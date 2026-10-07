<?php
$page_id = 'mgr_inv_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// AJAX Endpoints
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_product_details') {
    $prod_id = (int)($_GET['product_id'] ?? 0);
    header('Content-Type: application/json');
    try {
        // Product Info
        $prod = null;
        try {
            $stmt = $pdo->prepare("
                SELECT
                    ip.id,
                    ip.product_name                              AS name,
                    ip.category                                  AS category_name,
                    ip.unit_price                                AS price,
                    ip.unit_cost                                 AS cost,
                    ip.sku,
                    COALESCE(ip.brand, 'Petron Corporation')      AS supplier,
                    ip.status                                    AS product_status,
                    COALESCE(ip.min_stock, 0)                    AS min_stock,
                    COALESCE(ip.max_stock, 0)                    AS max_stock,
                    COALESCE(si.stock_level, ip.stock, 0)        AS stock_level,
                    COALESCE(si.capacity, ip.max_stock, 480)       AS capacity,
                    COALESCE(si.reorder_level, ip.min_stock, 24) AS reorder_level,
                    COALESCE(si.critical_level, 10)              AS critical_level,
                    COALESCE(si.unit, ip.size, 'pcs')            AS unit,
                    COALESCE(si.expiration_date, ip.expiration_date) AS expiration_date,
                    si.physical_count,
                    si.variance,
                    si.last_updated
                FROM inventory_products ip
                LEFT JOIN station_inventory si
                       ON si.product_id = ip.id AND si.station_id = ?
                WHERE ip.id = ?
            ");
            $stmt->execute([$station_id, $prod_id]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $primary_error) {}

        if (!$prod) {
            $stmt = $pdo->prepare("
                SELECT
                    p.id,
                    p.name AS name,
                    COALESCE(pc.name, 'General') AS category_name,
                    p.description,
                    COALESCE(si.price, p.price, si.cost, p.cost, 0) AS price,
                    COALESCE(p.cost, si.cost, 0) AS cost,
                    COALESCE(NULLIF(p.sku, ''), CONCAT('P', LPAD(p.id, 4, '0'))) AS sku,
                    'Petron Corporation' AS supplier,
                    COALESCE(NULLIF(si.status, ''), NULLIF(p.status, ''), 'active') AS product_status,
                    COALESCE(NULLIF(p.min_stock_level, 0), 0) AS min_stock,
                    COALESCE(NULLIF(p.max_stock_level, 0), 0) AS max_stock,
                    COALESCE(si.stock_level, p.current_stock, 0) AS stock_level,
                    COALESCE(NULLIF(si.capacity, 0), NULLIF(p.capacity, 0), NULLIF(p.max_stock_level, 0), 480) AS capacity,
                    COALESCE(NULLIF(si.reorder_level, 0), NULLIF(p.min_stock_level, 0), 24) AS reorder_level,
                    COALESCE(NULLIF(si.critical_level, 0), 10) AS critical_level,
                    COALESCE(NULLIF(p.unit, ''), NULLIF(si.unit, ''), 'pcs') AS unit,
                    COALESCE(si.expiration_date, p.expiration_date) AS expiration_date,
                    si.physical_count,
                    si.variance,
                    COALESCE(si.last_updated, p.updated_at, p.created_at) AS last_updated
                FROM products p
                LEFT JOIN product_categories pc ON pc.id = p.category_id
                LEFT JOIN station_inventory si ON si.product_id = p.id AND si.station_id = ?
                WHERE p.id = ?
                  AND LOWER(COALESCE(pc.name, '')) NOT IN ('fuel', 'fuel products', 'services')
            ");
            $stmt->execute([$station_id, $prod_id]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$prod) {
            echo json_encode(['success' => false, 'message' => 'Product not found']);
            exit;
        }
        $prod['category_name'] = format_product_category_display($prod['category_name'] ?? '', $prod['name'] ?? '', $prod['description'] ?? '');
        $prod['unit'] = format_product_unit_display($prod['unit'] ?? 'pcs', $prod['name'] ?? '', $prod['category_name'] ?? '', $prod['description'] ?? '');
        $prod['supplier'] = 'Petron Corporation';

        // Expiration computation (prefer real expiration_date from DB)
        $exp_raw = null;
        $exp_date = 'N/A';
        if (!empty($prod['expiration_date']) && $prod['expiration_date'] !== '0000-00-00') {
            $exp_raw = $prod['expiration_date'];
            $exp_date = (new DateTime($exp_raw))->format('M d, Y');
        } else {
            try {
                $dt = new DateTime(!empty($prod['last_updated']) ? $prod['last_updated'] : '2026-07-20');
                $cat_str = strtolower((string)($prod['category_name'] ?? ''));
                $name_str = strtolower((string)($prod['name'] ?? ''));
            if (strpos($cat_str, 'snack') !== false || strpos($cat_str, 'beverage') !== false || strpos($name_str, 'chippy') !== false || strpos($name_str, 'coca') !== false || strpos($name_str, 'choco') !== false) {
                $dt->modify('+1 year');
                $exp_raw = $dt->format('Y-m-d');
                $exp_date = $dt->format('M d, Y');
            } elseif (strpos($cat_str, 'accessory') !== false || strpos($cat_str, 'tool') !== false || strpos($name_str, 'wiper') !== false || strpos($name_str, 'mat') !== false) {
                $dt->modify('+5 years');
                $exp_raw = $dt->format('Y-m-d');
                $exp_date = $dt->format('M d, Y');
            } else {
                $dt->modify('+3 years');
                $exp_raw = $dt->format('Y-m-d');
                $exp_date = $dt->format('M d, Y');
            }
            } catch (Exception $e) {
                $exp_date = 'Jul 20, 2029';
                $exp_raw = '2029-07-20';
            }
        }
        $exp_status = '';
        if ($exp_raw) {
            try {
                $exp_dt   = new DateTime($exp_raw);
                $today_dt = new DateTime('today');
                $diff_days = (int)$today_dt->diff($exp_dt)->days * ($exp_dt >= $today_dt ? 1 : -1);
                if ($diff_days < 0) {
                    $exp_status = 'expired';
                } elseif ($diff_days <= 30) {
                    $exp_status = 'expiring_soon';
                }
            } catch (Exception $e) {}
        }
        $prod['exp_raw'] = $exp_raw;
        $prod['expiration_date'] = $exp_date;
        $prod['exp_status'] = $exp_status;

        // Apply capacity fallbacks
        $capacity = (float)($prod['capacity'] ?? 0);
        if ($capacity <= 0) {
            $capacity = 480;
        }
        $prod['capacity'] = $capacity;

        // Movement history for this product
        $stmt = $pdo->prepare("
            SELECT il.created_at, il.action AS movement_type, il.quantity_change AS quantity, il.notes, u.name AS user_name
            FROM inventory_logs il
            LEFT JOIN users u ON il.user_id = u.id
            WHERE il.product_id = ? AND il.station_id = ?
            ORDER BY il.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$prod_id, $station_id]);
        $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent deliveries (merchandise_stock_in)
        $stmt = $pdo->prepare("
            SELECT msi.*, u.name AS user_name
            FROM merchandise_stock_in msi
            JOIN users u ON msi.encoded_by = u.id
            WHERE msi.product_id = ? AND msi.station_id = ?
            ORDER BY msi.encoded_at DESC
            LIMIT 50
        ");
        $stmt->execute([$prod_id, $station_id]);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pending stock requests
        $stmt = $pdo->prepare("
            SELECT sr.*, u.name AS staff_name
            FROM stock_requests sr
            JOIN users u ON sr.staff_id = u.id
            WHERE sr.item_id = ? AND sr.station_id = ? AND sr.status = 'Pending'
            ORDER BY sr.created_at DESC
        ");
        $stmt->execute([$prod_id, $station_id]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'product' => $prod,
            'movements' => $movements,
            'deliveries' => $deliveries,
            'requests' => $requests
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// POST Actions
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. Validate Product (existing action)
    if ($action === 'validate_product') {
        $id = (int)($_POST['product_id'] ?? 0);
        if ($id) {
            try {
                $stmt = $pdo->prepare("SELECT product_name FROM inventory_products WHERE id=?");
                $stmt->execute([$id]);
                $pname = $stmt->fetchColumn();
                $pdo->prepare("UPDATE inventory_products SET status = 'active' WHERE id=?")->execute([$id]);
                log_activity($pdo, $me['id'], 'Product Validated', "Merchandise product '$pname' (ID:$id) validated by {$me['name']}");
                $_SESSION['success'] = "Product '$pname' validated and is now active.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_inventory_merchandise.php?tab=inventory'); exit;
    }

    // Approve Merchandise Adjustment
    if ($action === 'approve_merchandise_adjustment') {
        $adj_id = (int)($_POST['adjustment_id'] ?? 0);
        if ($adj_id > 0) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM merchandise_adjustments WHERE id = ? AND station_id = ? AND status = 'Pending'");
                $stmt->execute([$adj_id, $station_id]);
                $adj = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$adj) throw new Exception("Adjustment request not found or already processed.");

                $pid = (int)$adj['product_id'];
                $change = (int)$adj['quantity_change'];

                // Fetch real live current stock from station_inventory / inventory_products / products
                $live_stmt = $pdo->prepare("
                    SELECT stock_level FROM station_inventory 
                    WHERE product_id = ? AND station_id = ?
                    ORDER BY station_id DESC LIMIT 1
                ");
                $live_stmt->execute([$pid, $station_id]);
                $live_stock_val = $live_stmt->fetchColumn();

                if ($live_stock_val === false) {
                    $ip_stmt = $pdo->prepare("SELECT stock FROM inventory_products WHERE id = ?");
                    $ip_stmt->execute([$pid]);
                    $live_stock_val = $ip_stmt->fetchColumn();
                    if ($live_stock_val === false) {
                        $p_stmt = $pdo->prepare("SELECT current_stock FROM products WHERE id = ?");
                        $p_stmt->execute([$pid]);
                        $live_stock_val = $p_stmt->fetchColumn();
                    }
                }
                $curr_stock = max(0, (float)($live_stock_val !== false ? $live_stock_val : $adj['current_stock']));
                $new_stock = max(0, $curr_stock + $change);

                // 1. Update merchandise_adjustments
                $pdo->prepare("UPDATE merchandise_adjustments SET status = 'Approved', approved_by = ?, approved_at = NOW(), updated_at = NOW(), adjusted_stock = ? WHERE id = ?")
                    ->execute([$me['id'], $new_stock, $adj_id]);

                // 2. Update station_inventory across station records
                $upd_si = $pdo->prepare("UPDATE station_inventory SET stock_level = ?, last_updated = NOW() WHERE product_id = ? AND station_id = ?");
                $upd_si->execute([$new_stock, $pid, $station_id]);
                if ($upd_si->rowCount() === 0) {
                    $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, status, last_updated) VALUES (?, ?, ?, 'active', NOW())")
                        ->execute([$station_id, $pid, $new_stock]);
                }

                // 3. Update inventory_products
                $pdo->prepare("UPDATE inventory_products SET stock = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$new_stock, $pid]);

                // 4. Update products table
                try {
                    $pdo->prepare("UPDATE products SET current_stock = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$new_stock, $pid]);
                } catch (Exception $e_p) {}

                // 4. Insert into inventory_logs
                $pdo->prepare("
                    INSERT INTO inventory_logs (station_id, product_id, action, quantity_change, notes, user_id, created_at)
                    VALUES (?, ?, 'adjustment', ?, ?, ?, NOW())
                ")->execute([
                    $station_id,
                    $pid,
                    $change,
                    "{$adj['adjustment_type']} Approved: Current Stock Updated to {$new_stock} ({$adj['reason']})",
                    $me['id']
                ]);

                // 5. Insert into inventory_movements if table exists
                try {
                    $pdo->prepare("
                        INSERT INTO inventory_movements (station_id, product_id, reference_no, movement_type, quantity_change, resulting_stock, remarks, created_by, created_at)
                        VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?, NOW())
                    ")->execute([
                        $station_id,
                        $pid,
                        'ADJ-' . str_pad($adj_id, 4, '0', STR_PAD_LEFT),
                        $change,
                        $new_stock,
                        "Approved {$adj['adjustment_type']}: Current Stock Updated ({$adj['reason']})",
                        $me['id']
                    ]);
                } catch (Exception $e_mov) {}

                log_activity($pdo, $me['id'], 'Approve Adjustment', "Approved adjustment #{$adj_id} for {$adj['product_name']} ({$change})");

                $pdo->commit();
                $_SESSION['success'] = "Adjustment request for '{$adj['product_name']}' approved successfully! Current Stock Updated to {$new_stock}.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_inventory_merchandise.php?tab=requests'); exit;
    }

    // Reject Merchandise Adjustment
    if ($action === 'reject_merchandise_adjustment') {
        $adj_id = (int)($_POST['adjustment_id'] ?? 0);
        $rej_reason = trim($_POST['rejection_reason'] ?? 'Rejected by Manager');
        if ($adj_id > 0) {
            try {
                $pdo->prepare("UPDATE merchandise_adjustments SET status = 'Rejected', approved_by = ?, rejection_reason = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ? AND station_id = ?")
                    ->execute([$me['id'], $rej_reason, $adj_id, $station_id]);
                log_activity($pdo, $me['id'], 'Reject Adjustment', "Rejected adjustment #{$adj_id}");
                $_SESSION['success'] = "Adjustment request #{$adj_id} rejected.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_inventory_merchandise.php?tab=requests'); exit;
    }

    // 2. Approve Stock Request (Forward to Admin as PR)
    if ($action === 'approve_request') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $approved_qty = (int)($_POST['approved_quantity'] ?? 0);
        $notes = trim($_POST['manager_notes'] ?? '');
        
        if ($req_id > 0 && $approved_qty > 0) {
            try {
                $pdo->beginTransaction();
                
                // Fetch request
                $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ? AND status = 'Pending'");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$req) throw new Exception("Stock request not found or already processed.");
                
                // Generate sequential PR number
                $stmt_max = $pdo->query("SELECT MAX(CAST(REGEXP_SUBSTR(request_no, '[0-9]+$') AS UNSIGNED)) FROM stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != ''");
                $max_num = (int)($stmt_max->fetchColumn() ?: 0);
                $pr_number = 'PR-' . date('Y') . '-' . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);
                
                // Update Stock Request
                $pdo->prepare("
                    UPDATE stock_requests 
                    SET status = 'Waiting for Purchase Order', approved_quantity = ?, manager_id = ?, manager_notes = ?, request_no = ?, processed_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $approved_qty, $me['id'], $notes, $pr_number, $req_id
                ]);
                
                // Audit log & Activity log
                $audit_note = "Forwarded to Admin. PR: $pr_number";
                if ($notes) $audit_note .= " Notes: {$notes}";
                
                $pdo->prepare("
                    INSERT INTO stock_request_audit
                        (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'Forwarded to Admin', ?, ?, 'Pending', 'Waiting for Purchase Order', ?)
                ")->execute([$req_id, $me['id'], $role, $audit_note]);
                
                // Send notification to Admin
                $notify_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, event_type, severity, redirect_url, created_at)
                    VALUES (?, 'info', 'PR Waiting for PO', ?, 'stock_request', 'high', 'admin_purchase_orders.php?tab=pending', NOW())
                ");
                $admin_stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND station_id = ? AND status = 'Active'");
                $admin_stmt->execute([$station_id]);
                $admins = $admin_stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $admin_id) {
                    $notify_stmt->execute([$admin_id, "Purchase Request {$pr_number} has been approved by Manager and is waiting for PO generation."]);
                }
                
                log_activity($pdo, $me['id'], 'Approve Stock Request', "Request #{$req_id} | {$req['item_name']} | Qty: {$approved_qty} forwarded to Admin under PR {$pr_number}");
                
                $pdo->commit();
                $_SESSION['success'] = "Stock request #{$req_id} approved and forwarded to Admin (PR: {$pr_number}).";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid quantity or request ID.';
        }
        header('Location: manager_inventory_merchandise.php?tab=requests'); exit;
    }

    // 3. Reject Stock Request
    if ($action === 'reject_request') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $notes = trim($_POST['manager_notes'] ?? '');
        
        if ($req_id > 0 && !empty($notes)) {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id = ? AND station_id = ? AND status = 'Pending'");
                $stmt->execute([$req_id, $station_id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$req) throw new Exception("Stock request not found or already processed.");
                
                $pdo->prepare("
                    UPDATE stock_requests 
                    SET status = 'Rejected', manager_id = ?, manager_notes = ?, processed_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $me['id'], $notes, $req_id
                ]);
                
                $pdo->prepare("
                    INSERT INTO stock_request_audit
                        (stock_request_id, action_type, performed_by, performed_by_role, old_status, new_status, notes)
                    VALUES (?, 'Rejected', ?, ?, 'Pending', 'Rejected', ?)
                ")->execute([$req_id, $me['id'], $role, "Rejected by {$me['name']}. Reason: {$notes}"]);
                
                log_activity($pdo, $me['id'], 'Reject Stock Request', "Request #{$req_id} | {$req['item_name']} rejected by {$me['name']}");
                
                $pdo->commit();
                $_SESSION['success'] = "Stock request #{$req_id} rejected.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Rejection reason is required.';
        }
        header('Location: manager_inventory_merchandise.php?tab=requests'); exit;
    }

    // 4. Validate Delivery
    if ($action === 'validate_delivery') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $actual_qty = (int)($_POST['actual_qty'] ?? 0);
        $flag = $_POST['delivery_flag'] ?? 'OK';
        $notes = trim($_POST['delivery_notes'] ?? '');
        
        if (!in_array($flag, ["OK","Short","Damaged","Excess","Mixed"])) $flag = "OK";
        
        if ($po_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND station_id=? AND admin_finalized=1 AND delivery_validated=0");
                $stmt->execute([$po_id, $station_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$po) throw new Exception("PO not found or already validated.");
                
                $pdo->prepare("
                    UPDATE purchase_orders 
                    SET delivery_validated=1, delivery_validated_at=NOW(), delivery_validated_by=?, delivery_flag=?, delivery_notes=?, actual_qty_received=?, updated_at=NOW() 
                    WHERE id=?
                ")->execute([$me['id'], $flag, $notes, $actual_qty, $po_id]);
                
                log_activity($pdo, $me['id'], "Validate Delivery", "PO #{$po['po_number']} | {$po['product_name']} | Flag:{$flag} | Actual:{$actual_qty}");
                
                $_SESSION['success'] = "Delivery for PO #{$po['po_number']} validated. Staff can now encode Stock-In.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_inventory_merchandise.php?tab=deliveries'); exit;
    }

    // 5. Flag Issue
    if ($action === 'flag_delivery_issue') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        $flag = $_POST['delivery_flag'] ?? 'Short';
        $notes = trim($_POST['delivery_notes'] ?? '');
        
        if (empty($notes)) {
            $_SESSION['error'] = "Notes required when flagging.";
            header('Location: manager_inventory_merchandise.php?tab=deliveries'); exit;
        }
        
        if ($po_id > 0) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=? AND station_id=?");
                $stmt->execute([$po_id, $station_id]);
                $po = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$po) throw new Exception("PO not found.");
                
                $pdo->prepare("UPDATE purchase_orders SET delivery_flag=?, delivery_notes=?, updated_at=NOW() WHERE id=?")->execute([$flag, $notes, $po_id]);
                
                $_SESSION['success'] = "Issue flagged for PO #{$po['po_number']}.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        }
        header('Location: manager_inventory_merchandise.php?tab=deliveries'); exit;
    }

    // 6. Request Inventory Adjustment
    if ($action === 'request_adjustment') {
        $prod_id = (int)($_POST['product_id'] ?? 0);
        $physical_count = (float)($_POST['physical_count'] ?? 0);
        $notes = trim($_POST['manager_notes'] ?? $_POST['notes'] ?? '');

        if ($prod_id > 0) {
            try {
                $pdo->beginTransaction();

                // Fetch current stock
                $stmt = $pdo->prepare("
                    SELECT COALESCE(si.stock_level, ip.stock, 0) AS stock_level, ip.product_name, si.id AS si_exists
                    FROM inventory_products ip
                    LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                    WHERE ip.id = ?
                ");
                $stmt->execute([$station_id, $prod_id]);
                $curr = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$curr) throw new Exception("Product not found.");

                $current_stock = (float)$curr['stock_level'];
                $variance = $physical_count - $current_stock;

                // Make sure station_inventory row exists
                if (!$curr['si_exists']) {
                    $pdo->prepare("
                        INSERT INTO station_inventory (product_id, station_id, stock_level, status, last_updated)
                        VALUES (?, ?, ?, 'active', NOW())
                    ")->execute([$prod_id, $station_id, $current_stock]);
                }

                // Update physical_count, variance, stock_level, and last_updated
                $stmt = $pdo->prepare("
                    UPDATE station_inventory
                    SET physical_count = ?,
                        variance = ?,
                        stock_level = ?,
                        last_updated = NOW()
                    WHERE product_id = ? AND station_id = ?
                ");
                $stmt->execute([$physical_count, $variance, $physical_count, $prod_id, $station_id]);

                // Insert into inventory_logs
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_logs (station_id, product_id, action, quantity_change, notes, user_id, created_at)
                    VALUES (?, ?, 'adjustment', ?, ?, ?, NOW())
                ");
                $log_notes = "Physical count adjustment: counted {$physical_count} (was {$current_stock}, variance: " . ($variance > 0 ? '+' : '') . "{$variance}). Notes: {$notes}";
                $stmt->execute([$station_id, $prod_id, $variance, $log_notes, $me['id']]);

                // Also update the fallback stock in inventory_products
                $stmt = $pdo->prepare("UPDATE inventory_products SET stock = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$physical_count, $prod_id]);

                log_activity($pdo, $me['id'], 'Inventory Adjustment', "Product: {$curr['product_name']} | Count: {$physical_count} | Variance: {$variance} | User: {$me['name']}");

                $pdo->commit();
                $_SESSION['success'] = "Inventory adjusted successfully for '{$curr['product_name']}'. Physical count: {$physical_count}, Variance: " . ($variance > 0 ? '+' : '') . "{$variance} logged.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid product selected.';
        }
        header('Location: manager_inventory_merchandise.php?tab=inventory'); exit;
    }

    // 7. Create Stock Request (replenishment request submitted by manager)
    if ($action === 'create_stock_request') {
        $prod_id = (int)($_POST['product_id'] ?? 0);
        $qty = (int)($_POST['requested_quantity'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        // Use session-based batch PR number so multiple items submitted in one session share the same PR
        if (empty($_SESSION['current_batch_pr']) || (time() - ($_SESSION['batch_pr_time'] ?? 0)) > 3600) {
            // Generate a new sequential PR number based on the highest existing one
            $stmt_max = $pdo->query("SELECT MAX(CAST(REGEXP_SUBSTR(request_no, '[0-9]+\$') AS UNSIGNED)) FROM stock_requests WHERE station_id = $station_id AND request_no IS NOT NULL AND request_no != ''");
            $max_num = (int)($stmt_max->fetchColumn() ?: 0);
            $_SESSION['current_batch_pr'] = 'PR-' . date('Y') . '-' . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);
            $_SESSION['batch_pr_time'] = time();
        }
        $batch_pr = $_SESSION['current_batch_pr'];

        if ($prod_id > 0 && $qty > 0) {
            try {
                // Get product information
                $stmt = $pdo->prepare("
                    SELECT ip.product_name, ip.category, ip.sku, COALESCE(si.stock_level, ip.stock, 0) AS stock_level
                    FROM inventory_products ip
                    LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
                    WHERE ip.id = ?
                ");
                $stmt->execute([$station_id, $prod_id]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$prod) throw new Exception("Product not found.");

                // Insert into stock_requests WITH the shared batch request_no
                $stmt = $pdo->prepare("
                    INSERT INTO stock_requests (
                        request_no, staff_id, station_id, item_id, item_sku, item_name, 
                        item_category, current_stock, requested_quantity, 
                        remarks, status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())
                ");
                $stmt->execute([
                    $batch_pr,
                    $me['id'],
                    $station_id,
                    $prod_id,
                    $prod['sku'] ?: 'N/A',
                    $prod['product_name'],
                    $prod['category'] ?: 'Uncategorized',
                    (int)$prod['stock_level'],
                    $qty,
                    $remarks
                ]);

                $request_id = $pdo->lastInsertId();
                log_activity($pdo, $me['id'], 'Create Stock Request', "Stock Request #$request_id for {$prod['product_name']} | Qty: $qty | PR: $batch_pr");

                $_SESSION['success'] = "Stock request for '{$prod['product_name']}' added to <strong>{$batch_pr}</strong> (ID: #$request_id).";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid product or quantity.';
        }
        header('Location: manager_inventory_merchandise.php?tab=alerts'); exit;
    }

    // 8. Update Product Information
    if ($action === 'update_product') {
        $prod_id  = (int)($_POST['product_id'] ?? 0);
        $name     = trim($_POST['product_name'] ?? '');
        $reorder  = (float)($_POST['reorder_level'] ?? 24);
        $critical = (float)($_POST['critical_level'] ?? 10);
        $capacity = (float)($_POST['capacity'] ?? 480);
        $price    = (float)($_POST['price'] ?? 0);
        $cost     = (float)($_POST['cost'] ?? 0);
        $unit     = trim($_POST['unit'] ?? 'pcs');

        if ($prod_id > 0 && !empty($name)) {
            try {
                $pdo->beginTransaction();

                // 1. Update station_inventory
                $stmt_si = $pdo->prepare("
                    UPDATE station_inventory 
                    SET reorder_level = ?, critical_level = ?, capacity = ?, unit = ?, price = ?, cost = ?, last_updated = NOW() 
                    WHERE product_id = ? AND station_id = ?
                ");
                $stmt_si->execute([$reorder, $critical, $capacity, $unit, $price, $cost, $prod_id, $station_id]);

                // 2. Update inventory_products
                try {
                    $stmt_ip = $pdo->prepare("
                        UPDATE inventory_products 
                        SET product_name = ?, min_stock = ?, max_stock = ?, unit_price = ?, unit_cost = ?, size = ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt_ip->execute([$name, $reorder, $capacity, $price, $cost, $unit, $prod_id]);
                } catch (Exception $e_ip) {}

                // 3. Update products table
                try {
                    $stmt_p = $pdo->prepare("
                        UPDATE products 
                        SET name = ?, price = ?, cost = ?, min_stock_level = ?, max_stock_level = ?, capacity = ?, unit = ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt_p->execute([$name, $price, $cost, $reorder, $capacity, $capacity, $unit, $prod_id]);
                } catch (Exception $e_p) {}

                log_activity($pdo, $me['id'], 'Update Product', "Updated product '{$name}' (ID:#{$prod_id}) details: Price=₱{$price}, Reorder={$reorder}, Capacity={$capacity}");

                $pdo->commit();
                $_SESSION['success'] = "Product '{$name}' updated successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error updating product: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Invalid product data provided.';
        }
        header('Location: manager_inventory_merchandise.php?tab=inventory'); exit;
    }
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Data Fetching
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (function_exists('ensure_station_inventory_synced')) {
    ensure_station_inventory_synced($pdo, (int)$station_id);
}

$merch_inventory = [];
$msg = '';

// Main catalog query for this specific station
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(ip.id, p.id, si.product_id)         AS id,
            COALESCE(ip.product_name, p.name, 'Unknown Product') AS name,
            COALESCE(ip.category, pc.name, 'Merchandise') AS category_name,
            COALESCE(NULLIF(si.price, 0), ip.unit_price, p.price, 0) AS price,
            COALESCE(NULLIF(si.cost, 0), ip.unit_cost, p.cost, 0)   AS cost,
            COALESCE(ip.sku, p.sku, CONCAT('P', LPAD(si.product_id,4,'0'))) AS sku,
            COALESCE(ip.brand, 'Petron Corporation')     AS supplier,
            COALESCE(si.status, ip.status, p.status, 'active') AS product_status,
            COALESCE(ip.min_stock, p.min_stock_level, 0) AS min_stock,
            COALESCE(ip.max_stock, p.max_stock_level, 0) AS max_stock,
            COALESCE(si.stock_level, 0)                  AS stock_level,
            COALESCE(si.capacity, ip.max_stock, p.capacity, 480) AS capacity,
            COALESCE(si.reorder_level, ip.min_stock, p.min_stock_level, 24) AS reorder_level,
            COALESCE(si.critical_level, 10)              AS critical_level,
            COALESCE(si.unit, ip.size, p.unit, 'pcs')    AS unit,
            COALESCE(si.expiration_date, ip.expiration_date, p.expiration_date) AS expiration_date,
            COALESCE(si.last_updated, NOW())             AS last_updated,
            si.physical_count,
            si.variance
        FROM station_inventory si
        LEFT JOIN inventory_products ip ON ip.id = si.product_id
        LEFT JOIN products p ON p.id = si.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE si.station_id = ?
          AND (LOWER(COALESCE(ip.category, pc.name, '')) NOT IN ('fuel', 'fuel products', 'services', 'service') OR (ip.category IS NULL AND pc.name IS NULL))
          AND LOWER(COALESCE(ip.status, 'active')) NOT IN ('inactive', 'discontinued')
        ORDER BY category_name, name
    ");
    $stmt->execute([$station_id]);
    $merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = 'Error loading merchandise: ' . $e->getMessage();
}

foreach ($merch_inventory as &$item) {
    $item['category_name'] = format_product_category_display(
        $item['category_name'] ?? '',
        $item['name'] ?? '',
        $item['description'] ?? ''
    );
    $item['supplier'] = 'Petron Corporation';

    // Expiration computation (prefer real expiration_date from DB)
    $exp_raw = null;
    $exp_date = 'N/A';
    if (!empty($item['expiration_date']) && $item['expiration_date'] !== '0000-00-00') {
        $exp_raw = $item['expiration_date'];
        $exp_date = (new DateTime($exp_raw))->format('M d, Y');
    } else {
        try {
            $dt = new DateTime(!empty($item['last_updated']) ? $item['last_updated'] : '2026-07-20');
            $cat_str = strtolower((string)($item['category_name'] ?? $item['category'] ?? ''));
            $name_str = strtolower((string)($item['name'] ?? ''));
            if (strpos($cat_str, 'snack') !== false || strpos($cat_str, 'beverage') !== false || strpos($name_str, 'chippy') !== false || strpos($name_str, 'coca') !== false || strpos($name_str, 'choco') !== false) {
                $dt->modify('+1 year');
                $exp_raw = $dt->format('Y-m-d');
                $exp_date = $dt->format('M d, Y');
            } elseif (strpos($cat_str, 'accessory') !== false || strpos($cat_str, 'tool') !== false || strpos($name_str, 'wiper') !== false || strpos($name_str, 'mat') !== false) {
                $dt->modify('+5 years');
                $exp_raw = $dt->format('Y-m-d');
                $exp_date = $dt->format('M d, Y');
            } else {
                $dt->modify('+3 years');
                $exp_raw = $dt->format('Y-m-d');
                $exp_date = $dt->format('M d, Y');
            }
        } catch (Exception $e) {
            $exp_date = 'Jul 20, 2029';
            $exp_raw = '2029-07-20';
        }
    }
    $exp_status = '';
    if ($exp_raw) {
        try {
            $exp_dt   = new DateTime($exp_raw);
            $today_dt = new DateTime('today');
            $diff_days = (int)$today_dt->diff($exp_dt)->days * ($exp_dt >= $today_dt ? 1 : -1);
            if ($diff_days < 0) {
                $exp_status = 'expired';
            } elseif ($diff_days <= 30) {
                $exp_status = 'expiring_soon';
            }
        } catch (Exception $e) {}
    }
    $item['expiration_date'] = $exp_date;
    $item['exp_raw'] = $exp_raw;
    $item['exp_status'] = $exp_status;
}
unset($item);

$last_movements = [];
try {
    $mvStmt = $pdo->prepare("
        SELECT product_id, qty_received AS qty, 'Delivery' AS mtype, encoded_at AS mdate
        FROM merchandise_stock_in WHERE station_id = ? AND product_id IS NOT NULL
    ");
    $mvStmt->execute([$station_id]);
    foreach ($mvStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($last_movements[$pid]) || $r['mdate'] > $last_movements[$pid]['date'])
            $last_movements[$pid] = ['qty'=>(int)$r['qty'],'type'=>$r['mtype'],'sign'=>'+','date'=>$r['mdate']];
    }
} catch (Exception $e) {}
try {
    $slStmt = $pdo->prepare("
        SELECT ti.product_id, SUM(ti.quantity) AS qty, MAX(t.created_at) AS mdate
        FROM merchandise_transaction_items ti JOIN merchandise_transactions t ON t.id=ti.transaction_id
        WHERE t.station_id=? AND ti.product_id IS NOT NULL GROUP BY ti.product_id
    ");
    $slStmt->execute([$station_id]);
    foreach ($slStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['product_id'];
        if (!isset($last_movements[$pid]) || $r['mdate'] > $last_movements[$pid]['date'])
            $last_movements[$pid] = ['qty'=>(int)$r['qty'],'type'=>'Sales','sign'=>'-','date'=>$r['mdate']];
    }
} catch (Exception $e) {}

// ── prod_added_map: total stock added (Stock-In) per product (by ID & Name) ───────
$prod_added_map_id   = [];
$prod_added_map_name = [];
try {
    $add_stmt = $pdo->prepare("
        SELECT 
            product_id, 
            LOWER(TRIM(product_name)) AS pname, 
            COALESCE(SUM(qty_received), 0) AS total_added
        FROM merchandise_stock_in
        WHERE station_id = ?
        GROUP BY product_id, LOWER(TRIM(product_name))
    ");
    $add_stmt->execute([$station_id]);
    foreach ($add_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['total_added'];
        if (!empty($r['product_id']) && (int)$r['product_id'] > 0) {
            $prod_added_map_id[(int)$r['product_id']] = ($prod_added_map_id[(int)$r['product_id']] ?? 0) + $qty;
        }
        if (!empty($r['pname'])) {
            $prod_added_map_name[$r['pname']] = ($prod_added_map_name[$r['pname']] ?? 0) + $qty;
        }
    }
} catch (Exception $e) {}

// ── prod_deducted_map: total stock deducted (Sales + JO + Adjustments) per product ──
$prod_deducted_map_id   = [];
$prod_deducted_map_name = [];
try {
    $ded_stmt2 = $pdo->prepare("
        SELECT 
            ti.product_id, 
            LOWER(TRIM(ti.product_name)) AS pname, 
            COALESCE(SUM(ti.quantity), 0) AS total_deducted
        FROM merchandise_transaction_items ti
        JOIN merchandise_transactions t ON t.id = ti.transaction_id
        WHERE t.station_id = ?
          AND LOWER(t.workflow_status) NOT IN ('voided','void','cancelled')
        GROUP BY ti.product_id, LOWER(TRIM(ti.product_name))
    ");
    $ded_stmt2->execute([$station_id]);
    foreach ($ded_stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['total_deducted'];
        if (!empty($r['product_id']) && (int)$r['product_id'] > 0) {
            $prod_deducted_map_id[(int)$r['product_id']] = ($prod_deducted_map_id[(int)$r['product_id']] ?? 0) + $qty;
        }
        if (!empty($r['pname'])) {
            $prod_deducted_map_name[$r['pname']] = ($prod_deducted_map_name[$r['pname']] ?? 0) + $qty;
        }
    }
} catch (Exception $e) {}


// Category list for filters
$categories_list = [];
foreach ($merch_inventory as $item) {
    $cat = $item['category_name'] ?? '';
    if ($cat !== '') $categories_list[$cat] = true;
}
ksort($categories_list);


// Build sorted-by-category structure (used by both Inventory Overview and Stock Alerts tabs)
$by_cat = [];
foreach ($merch_inventory as $item) {
    $cat = $item['category_name'] ?? 'Uncategorized';
    $by_cat[$cat][] = $item;
}
$cat_order = ['Oils/Lubes/Grease','Filters','VIC Filters','Drinks/Food','Snacks','Car Accessories','Merchandise','Others'];
$sorted = [];
foreach ($cat_order as $k) { if (isset($by_cat[$k])) $sorted[$k] = $by_cat[$k]; }
foreach ($by_cat as $k => $v) { if (!in_array($k, $cat_order)) $sorted[$k] = $v; }

// Summary Stats
$summary_total = count($merch_inventory);
$summary_available = 0;
$summary_low = 0;       // all below reorder (includes critical)
$summary_out = 0;
$summary_variance = 0;
$summary_expired = 0;
// Granular alert counts for Stock Alerts tab cards
$summary_alert_low      = 0; // Low Stock only (stock > reorder/2 && <= reorder)
$summary_alert_critical = 0; // Critical Stock (stock > 0 && <= reorder/2)
foreach ($merch_inventory as $item) {
    $stock = (float)($item['stock_level'] ?? 0);
    $reorder = (float)($item['reorder_level'] ?? 24);
    $critical = (float)($item['critical_level'] ?? 10);
    $variance = $item['variance'];
    $is_expired = ($item['exp_status'] ?? '') === 'expired';
    
    if ($is_expired) {
        $summary_expired++;
    }
    
    if ($variance !== null && (float)$variance != 0) {
        $summary_variance++;
    }
    
    if ($stock <= 0) {
        $summary_out++;
    } elseif ($stock <= $critical) {
        $summary_alert_critical++;
        $summary_low++;
    } elseif ($stock <= $reorder) {
        $summary_alert_low++;
        $summary_low++;
    } else {
        if (!$is_expired) {
            $summary_available++;
        }
    }
}
// Additional summary stats for new dashboard cards
$total_inventory_value = 0;
foreach ($merch_inventory as $item) {
    $s = (float)($item['stock_level'] ?? 0);
    $p = (float)($item['price'] ?? $item['unit_price'] ?? 0);
    $total_inventory_value += $s * $p;
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status = 'Pending'");
$stmt->execute([$station_id]);
$summary_pending_requests = (int)$stmt->fetchColumn();

// Active tab definition
$active_tab = $_GET['tab'] ?? 'inventory';
if (!in_array($active_tab, ['inventory', 'alerts', 'movement', 'requests', 'adjustments', 'stockin', 'stockout', 'transfers', 'damaged', 'expired'])) {
    $active_tab = 'inventory';
}
// URL-driven filter/view params (from sidebar deep-links)
$url_filter = in_array($_GET['filter'] ?? '', ['low','critical']) ? ($_GET['filter']) : '';
$url_view   = ($_GET['view'] ?? '') === 'movement' ? 'movement' : '';

// Stock Added Today & Stock Deducted Today (for dashboard cards)
$stock_added_today    = 0;
$stock_deducted_today = 0;
if ($active_tab === 'inventory') {
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(qty_received),0) FROM merchandise_stock_in WHERE station_id=? AND DATE(encoded_at)=CURDATE()");
        $s->execute([$station_id]);
        $stock_added_today = (int)$s->fetchColumn();
    } catch (Exception $e) {}
    try {
        $s = $pdo->prepare("
            SELECT COALESCE(SUM(ti.quantity),0)
            FROM merchandise_transaction_items ti
            JOIN merchandise_transactions t ON t.id = ti.transaction_id
            WHERE t.station_id=? AND DATE(t.created_at)=CURDATE()
              AND t.workflow_status NOT IN ('voided','void','cancelled')
        ");
        $s->execute([$station_id]);
        $stock_deducted_today = (int)$s->fetchColumn();
    } catch (Exception $e) {}
}

// Stock requests data
$stock_requests = [];
$summary_req_total = 0;
$summary_req_pending = 0;
$summary_req_approved = 0;
$summary_req_rejected = 0;
$req_categories = [];
$req_staff_users = [];

// Merchandise Adjustments data
$merchandise_adjustments = [];
$summary_adj_pending = 0;

if ($active_tab === 'requests' || $active_tab === 'adjustments') {
    try {
        $stmt = $pdo->prepare("
            SELECT sr.*, u.name AS staff_name, 
                   COALESCE(si.reorder_level, ip.min_stock, 24) AS reorder_level,
                   COALESCE(si.critical_level, 10)              AS critical_level,
                   COALESCE(si.unit, ip.size, 'pcs') AS unit,
                   ip.sku AS prod_sku
            FROM stock_requests sr 
            JOIN users u ON sr.staff_id = u.id 
            LEFT JOIN inventory_products ip ON sr.item_id = ip.id
            LEFT JOIN station_inventory si ON sr.item_id = si.product_id AND si.station_id = sr.station_id
            WHERE sr.station_id = ? 
            ORDER BY CASE sr.status WHEN 'Pending' THEN 1 ELSE 2 END, sr.created_at DESC
        ");
        $stmt->execute([$station_id]);
        $stock_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($stock_requests as $req) {
            $summary_req_total++;
            $status_lc = strtolower($req['status'] ?? 'pending');
            if ($status_lc === 'pending') {
                $summary_req_pending++;
            } elseif ($status_lc === 'approved' || $status_lc === 'validated' || $status_lc === 'waiting for purchase order' || $status_lc === 'purchase order generated') {
                $summary_req_approved++;
            } elseif ($status_lc === 'rejected') {
                $summary_req_rejected++;
            }

            $cat = $req['item_category'] ?? '';
            if ($cat !== '') {
                $req_categories[$cat] = true;
            }

            $staff = $req['staff_name'] ?? '';
            if ($staff !== '') {
                $req_staff_users[$staff] = true;
            }
        }
        ksort($req_categories);
        ksort($req_staff_users);
    } catch (Exception $e) {}

    try {
        $stmt = $pdo->prepare("
            SELECT ma.*, u.name AS staff_name
            FROM merchandise_adjustments ma
            LEFT JOIN users u ON ma.requested_by = u.id
            WHERE ma.station_id = ?
            ORDER BY CASE ma.status WHEN 'Pending' THEN 1 ELSE 2 END, ma.requested_at DESC
        ");
        $stmt->execute([$station_id]);
        $merchandise_adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($merchandise_adjustments as $adj) {
            if (strtolower($adj['status'] ?? '') === 'pending') {
                $summary_adj_pending++;
            }
        }
    } catch (Exception $e) {}
} else {
    // Quick badge count query for tabs when not viewing adjustments
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_adjustments WHERE station_id = ? AND status = 'Pending'");
        $stmt->execute([$station_id]);
        $summary_adj_pending = (int)$stmt->fetchColumn();
    } catch (Exception $e) {}
}

// Awaiting Deliveries Verification
$pending_pos = [];
$validated_pos = [];
if ($active_tab === 'deliveries') {
    try {
        $stmt = $pdo->prepare("
            SELECT po.*, CONCAT(u_mgr.first_name, ' ', u_mgr.last_name) AS manager_name, CONCAT(u_adm.first_name, ' ', u_adm.last_name) AS admin_name, sr.item_sku, sr.item_category, sr.remarks AS sr_remarks, sr.current_stock 
            FROM purchase_orders po 
            LEFT JOIN users u_mgr ON po.created_by=u_mgr.id 
            LEFT JOIN users u_adm ON po.admin_id=u_adm.id 
            LEFT JOIN stock_requests sr ON po.request_id=sr.id 
            WHERE po.station_id=? AND po.type='merch' AND po.admin_finalized=1 AND po.delivery_validated=0 AND po.stock_in_done=0 
            ORDER BY po.admin_finalized_at ASC
        ");
        $stmt->execute([$station_id]);
        $pending_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    try {
        $stmt = $pdo->prepare("
            SELECT po.*, CONCAT(u_mgr.first_name, ' ', u_mgr.last_name) AS manager_name, CONCAT(u_adm.first_name, ' ', u_adm.last_name) AS admin_name, CONCAT(u_val.first_name, ' ', u_val.last_name) AS validated_by_name 
            FROM purchase_orders po 
            LEFT JOIN users u_mgr ON po.created_by=u_mgr.id 
            LEFT JOIN users u_adm ON po.admin_id=u_adm.id 
            LEFT JOIN users u_val ON po.delivery_validated_by=u_val.id 
            WHERE po.station_id=? AND po.type='merch' AND po.delivery_validated=1 
            ORDER BY po.delivery_validated_at DESC LIMIT 50
        ");
        $stmt->execute([$station_id]);
        $validated_pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Movement History
$movement_history = [];
$mov_total_count = 0;
$mov_delivery_count = 0;
$mov_sale_count = 0;
$mov_adjustment_count = 0;
$mov_variance_count = 0;

if ($active_tab === 'movement' || in_array($active_tab, ['stockin', 'stockout', 'transfers', 'damaged', 'expired'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT il.id AS log_id, il.created_at, il.action AS movement_type, il.quantity_change AS quantity, 
                   il.quantity_before, il.quantity_after, il.reference_type, il.reference_id, il.notes, 
                   COALESCE(il.product_name, ip.product_name, 'Merchandise Item') AS product_name,
                   COALESCE(ip.sku, il.reference_id, 'N/A') AS sku,
                   COALESCE(si.unit, ip.size, 'pcs') AS unit,
                   COALESCE(il.performed_by, u.name, 'System') AS user_name 
            FROM inventory_logs il 
            LEFT JOIN inventory_products ip ON il.product_id = ip.id 
            LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = il.station_id 
            LEFT JOIN users u ON il.user_id = u.id 
            WHERE il.station_id = ? 
            ORDER BY il.created_at DESC LIMIT 500
        ");
        $stmt->execute([$station_id]);
        $movement_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mov_total_count = count($movement_history);
        foreach ($movement_history as $log) {
            $m_type = strtolower($log['movement_type'] ?? '');
            if ($m_type === 'delivery' || $m_type === 'stock_in' || $m_type === 'stock-in') {
                $mov_delivery_count++;
            } elseif ($m_type === 'sale' || $m_type === 'release') {
                $mov_sale_count++;
            } elseif ($m_type === 'adjustment') {
                $mov_adjustment_count++;
                if (stripos($log['notes'] ?? '', 'variance') !== false || stripos($log['notes'] ?? '', 'physical') !== false) {
                    $mov_variance_count++;
                }
            }
        }
    } catch (Exception $e) {}
}

// Stock-In list (manager full view with PO No., Status)
$mgr_stock_in_list = [];
if ($active_tab === 'stockin') {
    try {
        $s = $pdo->prepare("
            SELECT
                msi.id,
                CONCAT('SI-', LPAD(msi.id, 5, '0')) AS stock_in_no,
                COALESCE(NULLIF(msi.po_number,''), '—') AS po_no,
                COALESCE(NULLIF(msi.batch_ref,''), CONCAT('BATCH-', LPAD(msi.id, 4, '0'))) AS batch_no,
                msi.qty_received,
                msi.unit_cost,
                msi.selling_price,
                msi.encoded_at AS date_received,
                COALESCE(msi.condition_flag, 'Good') AS status_flag,
                COALESCE(u.name, u.username, 'Staff') AS received_by
            FROM merchandise_stock_in msi
            LEFT JOIN users u ON msi.encoded_by = u.id
            WHERE msi.station_id = ?
            ORDER BY msi.encoded_at DESC, msi.id DESC
            LIMIT 300
        ");
        $s->execute([$station_id]);
        $mgr_stock_in_list = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Stock-Out list (from merchandise_transaction_items + transactions)
$mgr_stock_out_list = [];
if ($active_tab === 'stockout') {
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('SO-', LPAD(t.id, 5, '0')) AS ref_no,
                ti.product_name,
                COALESCE(mb.batch_number, CONCAT('BATCH-', LPAD(COALESCE(ti.batch_id,0), 4, '0'))) AS batch_no,
                ABS(ti.quantity) AS qty_out,
                COALESCE(NULLIF(t.transaction_type,''), 'Sales') AS transaction_type,
                COALESCE(t.transaction_date, t.created_at) AS date_out,
                COALESCE(u.name, u.username, 'Staff') AS released_by
            FROM merchandise_transaction_items ti
            JOIN merchandise_transactions t ON t.id = ti.transaction_id
            LEFT JOIN merchandise_batches mb ON mb.id = ti.batch_id
            LEFT JOIN users u ON t.staff_id = u.id
            WHERE t.station_id = ?
              AND (t.workflow_status IS NULL OR LOWER(t.workflow_status) NOT IN ('voided','void','cancelled'))
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT 300
        ");
        $s->execute([$station_id]);
        $mgr_stock_out_list = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Transfer Records (from inventory_logs where action='transfer', or merchandise_deliveries)
$mgr_transfers_list = [];
if ($active_tab === 'transfers') {
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('TR-', LPAD(il.id, 5, '0')) AS transfer_no,
                COALESCE(ip.product_name, p.name, il.notes, 'Merchandise Product') AS product_name,
                COALESCE(il.notes, '—') AS notes,
                ABS(il.quantity_change) AS qty,
                il.created_at AS date_transferred,
                COALESCE(u.name, u.username, 'Staff') AS processed_by
            FROM inventory_logs il
            LEFT JOIN inventory_products ip ON ip.id = il.product_id
            LEFT JOIN products p ON p.id = il.product_id
            LEFT JOIN users u ON il.user_id = u.id
            WHERE il.station_id = ? 
              AND (LOWER(il.action) LIKE '%transfer%' OR LOWER(il.notes) LIKE '%transfer%')
            ORDER BY il.created_at DESC
            LIMIT 200
        ");
        $s->execute([$station_id]);
        $mgr_transfers_list = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Damaged Items (from inventory_logs where action='damage' or condition_flag='Damaged' in stock_in)
$mgr_damaged_list = [];
if ($active_tab === 'damaged') {
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('DMG-', LPAD(il.id, 5, '0')) AS damage_no,
                COALESCE(ip.product_name, p.name, il.notes, '—') AS product_name,
                '—' AS batch_no,
                ABS(il.quantity_change) AS qty,
                COALESCE(il.notes, 'Damage recorded') AS reason,
                il.created_at AS date_recorded,
                COALESCE(u.name, u.username, 'Staff') AS recorded_by
            FROM inventory_logs il
            LEFT JOIN inventory_products ip ON ip.id = il.product_id
            LEFT JOIN products p ON p.id = il.product_id
            LEFT JOIN users u ON il.user_id = u.id
            WHERE il.station_id = ? 
              AND (LOWER(il.action) LIKE '%damage%' OR LOWER(il.action) LIKE '%write_off%')
            ORDER BY il.created_at DESC
            LIMIT 200
        ");
        $s->execute([$station_id]);
        $mgr_damaged_list = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Also pull damaged from merchandise_stock_in condition_flag='Damaged'
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('DMG-SI-', LPAD(msi.id, 4, '0')) AS damage_no,
                COALESCE(NULLIF(msi.batch_ref,''), CONCAT('BATCH-', LPAD(msi.id, 4, '0'))) AS batch_no,
                msi.qty_received AS qty,
                CONCAT('Damaged on delivery - ', COALESCE(msi.remarks, 'No remarks')) AS reason,
                msi.encoded_at AS date_recorded,
                COALESCE(u.name, u.username, 'Staff') AS recorded_by
            FROM merchandise_stock_in msi
            LEFT JOIN users u ON msi.encoded_by = u.id
            WHERE msi.station_id = ? 
              AND msi.condition_flag = 'Damaged'
            ORDER BY msi.encoded_at DESC
            LIMIT 100
        ");
        $s->execute([$station_id]);
        $damaged_from_si = $s->fetchAll(PDO::FETCH_ASSOC);
        $mgr_damaged_list = array_merge($mgr_damaged_list, $damaged_from_si);
    } catch (Exception $e) {}
}

// Expired Products (from merchandise_batches where status='expired' or expiry logic)
$mgr_expired_list = [];
if ($active_tab === 'expired') {
    try {
        $s = $pdo->prepare("
            SELECT
                COALESCE(ip.product_name, p.name, 'Product') AS product_name,
                COALESCE(mb.batch_number, CONCAT('BATCH-', LPAD(mb.id,4,'0'))) AS batch_no,
                COALESCE(mb.date_received, mb.created_at) AS expiry_date,
                mb.remaining_qty AS qty,
                COALESCE(mb.status, 'Expired') AS status
            FROM merchandise_batches mb
            LEFT JOIN inventory_products ip ON ip.id = mb.product_id
            LEFT JOIN products p ON p.id = mb.product_id
            WHERE mb.station_id = ? 
              AND LOWER(mb.status) IN ('expired', 'damage')
            ORDER BY mb.date_received ASC
            LIMIT 200
        ");
        $s->execute([$station_id]);
        $mgr_expired_list = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Full Inventory Movement History
$mgr_movement_history = [];
if ($active_tab === 'movement') {
    try {
        $s = $pdo->prepare("
            SELECT
                il.created_at AS date,
                COALESCE(ip.product_name, '—') AS product_name,
                il.action AS movement_type,
                COALESCE(il.reference_type, il.action, '—') AS reference,
                CASE WHEN il.quantity_change > 0 THEN il.quantity_change ELSE 0 END AS qty_in,
                CASE WHEN il.quantity_change < 0 THEN ABS(il.quantity_change) ELSE 0 END AS qty_out,
                COALESCE(il.quantity_after, 0) AS balance,
                COALESCE(u.name, u.username, 'System') AS user_name
            FROM inventory_logs il
            LEFT JOIN inventory_products ip ON ip.id = il.product_id
            LEFT JOIN users u ON il.user_id = u.id
            WHERE il.station_id = ?
            ORDER BY il.created_at DESC
            LIMIT 500
        ");
        $s->execute([$station_id]);
        $mgr_movement_history = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── No-overlap and Senior Typography Fix for Merchandise Inventory & Stock Alerts ── */
.mim-wrap, .main-content, .table-wrap, .table-responsive {
    overflow-x: hidden !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}

/* Force fixed layout so columns don't steal space from neighbors */
table#mgrMerchTable, 
table#mgrAlertTable, 
table#mgrMerchMovTable, 
table#mgrStaffAdjTable,
table.merch-tbl, 
table.alert-tbl,
table.staff-adj-tbl,
table.report-table.no-min-width.print-table {
    table-layout: fixed !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    border-collapse: collapse !important;
}

/* All header and data cells: clear boundaries, high legibility for users */
table#mgrMerchTable th,
table#mgrMerchTable td,
table#mgrAlertTable th,
table#mgrAlertTable td,
table#mgrMerchMovTable th,
table#mgrMerchMovTable td,
table#mgrStaffAdjTable th,
table#mgrStaffAdjTable td,
table.merch-tbl th,
table.merch-tbl td,
table.alert-tbl th,
table.alert-tbl td,
table.staff-adj-tbl th,
table.staff-adj-tbl td {
    overflow: hidden !important;
    max-width: 0 !important;
    box-sizing: border-box !important;
    vertical-align: middle !important;
}

/* Header Cells styling - Clear, prominent, bold */
table#mgrMerchTable thead th,
table#mgrAlertTable thead th,
table#mgrMerchMovTable thead th,
table#mgrStaffAdjTable thead th,
table.alert-tbl thead th,
table.staff-adj-tbl thead th {
    padding: 11px 8px !important;
    font-size: 12px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: .3px !important;
    color: #ffffff !important;
    background: #002F70 !important;
    white-space: normal !important;
    word-break: normal !important;
    overflow-wrap: normal !important;
}

/* Data Cells base typography */
table#mgrMerchTable tbody td,
table#mgrAlertTable tbody td,
table#mgrMerchMovTable tbody td,
table#mgrStaffAdjTable tbody td,
table.alert-tbl tbody td,
table.staff-adj-tbl tbody td {
    padding: 11px 8px !important;
    font-size: 13.5px !important;
    line-height: 1.4 !important;
    color: #0f172a;
    border-bottom: 1px solid #f1f5f9;
}

/* Product & details cells in mgrMerchTable: clean multi-line wrapping */
table#mgrMerchTable td:nth-child(2),
table.merch-tbl td:nth-child(2),
table#mgrMerchTable td:nth-child(4),
table.merch-tbl td:nth-child(4) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    max-width: 0 !important;
}

/* Stock Alerts Table (mgrAlertTable): specific clean wrapping without overflow */
table#mgrAlertTable td:nth-child(2),
table.alert-tbl td:nth-child(2) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    max-width: 0 !important;
    font-size: 14px !important;
    font-weight: 800 !important;
    line-height: 1.35 !important;
}

table#mgrAlertTable td:nth-child(3),
table.alert-tbl td:nth-child(3) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    font-size: 13px !important;
    font-weight: 600 !important;
}

table#mgrAlertTable td:nth-child(8),
table.alert-tbl td:nth-child(8) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    font-size: 13px !important;
    font-weight: 700 !important;
}

/* Staff Inventory Adjustments Table (mgrStaffAdjTable): specific clean wrapping */
table#mgrStaffAdjTable td:nth-child(3),
table.staff-adj-tbl td:nth-child(3) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    max-width: 0 !important;
    font-size: 14px !important;
    font-weight: 800 !important;
    line-height: 1.35 !important;
}

table#mgrStaffAdjTable td:nth-child(7),
table.staff-adj-tbl td:nth-child(7) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    font-size: 13px !important;
    font-weight: 600 !important;
}

/* Status Column: fully visible, never clipped or covered */
table#mgrStaffAdjTable th:nth-child(9),
table#mgrStaffAdjTable td:nth-child(9),
table.staff-adj-tbl th:nth-child(9),
table.staff-adj-tbl td:nth-child(9) {
    overflow: visible !important;
    white-space: nowrap !important;
    text-align: center !important;
    padding-left: 6px !important;
    padding-right: 6px !important;
}

/* Actions Column: fully visible, spacious */
table#mgrStaffAdjTable th:nth-child(10),
table#mgrStaffAdjTable td:nth-child(10),
table.staff-adj-tbl th:nth-child(10),
table.staff-adj-tbl td:nth-child(10) {
    overflow: visible !important;
    white-space: nowrap !important;
    text-align: center !important;
    padding-left: 6px !important;
    padding-right: 6px !important;
}
</style>

<style>
/* Header standardization */
body { overflow-x: hidden; }
.mim-wrap { width: 100%; max-width: 100%; box-sizing: border-box; overflow-x: hidden !important; padding: 0 !important; margin: 0 !important; }
.table-wrap { overflow-x: hidden !important; width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; }

.int-head { display: flex !important; align-items: center !important; justify-content: space-between !important; flex-wrap: wrap !important; gap: 15px !important; margin-top: 0 !important; margin-bottom: 25px !important; padding: 0 !important; border: none !important; width: 100% !important; }
.int-head > div:first-child { flex: 1; min-width: 280px; max-width: 65%; }
.int-head > div:last-child { flex-shrink: 0; display: flex; gap: 8px; flex-wrap: wrap; }
.int-head h1 { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important; font-size: 24px !important; font-weight: 700 !important; color: #002f70 !important; margin: 0 !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; display: flex !important; align-items: center !important; gap: 10px !important; line-height: 1.2 !important; }
.int-head .sub { font-size: 13px !important; color: #64748b !important; margin-top: 4px !important; line-height: 1.4 !important; }
.ato-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:0 18px; border-radius:7px; font-size:14px; font-weight:700; cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s; height:40px; white-space:nowrap; background:white !important; }
.ato-btn-back { color:#4b5563 !important; border-color:#6b7280 !important; }
.ato-btn-back:hover { background:#6b7280 !important; color:#fff !important; }

/* Tabs Layout - Prominent and readable for elderly */
.tab-nav { display: flex !important; flex-wrap: wrap !important; margin-bottom: 22px !important; border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; border-bottom: 3px solid #00264D !important; gap: 0 !important; }
.tab-btn { flex: 1 !important; min-width: 150px !important; padding: 13px 18px !important; font-size: 13.5px !important; font-weight: 700 !important; color: #334155 !important; background: #ffffff !important; border: none !important; border-right: 1px solid #d1d9e6 !important; border-bottom: none !important; text-decoration: none !important; transition: all 0.15s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 8px !important; text-transform: uppercase !important; letter-spacing: 0.3px !important; text-align: center !important; cursor: pointer !important; margin-bottom: 0 !important; }
.tab-btn:last-child { border-right: none !important; }
.tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.tab-btn.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; border-bottom: none !important; }

.cat-header td { font-weight:800 !important; background:#e2e8f0 !important; color:#002F70 !important; text-transform:uppercase; font-size:13.5px !important; letter-spacing:.5px; border-bottom:2px solid #cbd5e1; padding:10px 14px !important; text-align:center; }
.merch-row:hover { background:#f8f9fa; }
.inv-filter-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
.inv-filter-bar select, .inv-filter-bar input[type="text"] { padding:8px 11px; border:1px solid #ced4da; border-radius:5px; font-size:13px; font-family:inherit; color:#1e293b; }
.inv-filter-bar select { min-width:170px; }
.inv-filter-bar input[type="text"] { min-width:210px; }
.fd-select-source{display:none!important;}
.fd-select{position:relative;display:inline-block;min-width:130px;}
.fd-select-trigger{display:flex;align-items:center;gap:8px;width:100%;height:36px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#1e293b;font-size:13px;font-family:inherit;cursor:pointer;box-sizing:border-box;white-space:nowrap;}
.fd-select-trigger:hover{border-color:#94a3b8;}
.fd-select.fd-open .fd-select-trigger{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.fd-select-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;text-align:left;}
.fd-select-arrow{font-size:10px;color:#94a3b8;margin-left:auto;transition:transform .15s;flex-shrink:0;}
.fd-select.fd-open .fd-select-arrow{transform:rotate(180deg);}
.fd-select-menu{display:none;position:absolute;top:calc(100% + 4px);left:0;min-width:100%;max-height:280px;overflow-y:auto;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 8px 24px rgba(15,23,42,.16);z-index:10000;}
.fd-select.fd-open .fd-select-menu{display:block;}
.fd-select-option{padding:9px 14px;font-size:13px;color:#1e293b;cursor:pointer;white-space:nowrap;}
.fd-select-option:hover{background:#f1f5f9;}
.fd-select-option.fd-active{font-weight:700;color:#fff;background:#1a6fd4;}

/* Status Badges */
.inv-stock-badge { display:inline-block; padding:3px 9px; border-radius:4px; font-size:11px; font-weight:600; text-transform:uppercase; }
.pstatus-badge { display:inline-block; padding:3px 9px; border-radius:4px; font-size:11px; font-weight:600; }
.pstatus-active   { background:#d4edda; color:#155724; }
.pstatus-inactive { background:#e9ecef; color:#495057; }
.pstatus-pending  { background:#fff3cd; color:#856404; }

/* Fill bar style */
.fill-bar-wrap{background:#e9ecef;border-radius:3px;height:5px;overflow:hidden;margin-bottom:2px;width:100%;}
.fill-bar-inner{height:100%;border-radius:3px;}

/* Last Movement signs style */
.mv-pos{color:#16a34a;font-weight:700;}
.mv-neg{color:#dc2626;font-weight:700;}
.mv-none{color:#94a3b8;}

.po-table-wrap { width:100%; overflow-x:auto; }
.po-table { width:100%; border-collapse:collapse; font-size:11px; table-layout:auto; }
.po-table thead tr { background:#002F70; }
.po-table thead th { padding:9px 10px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; border-bottom:2px solid #001a3d; vertical-align:middle; white-space:nowrap; }
.po-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.po-table tbody tr:hover td { background:#eff6ff; }
.po-table tbody td { padding:9px 10px; color:#334155; vertical-align:middle; white-space:normal; word-break:break-word; background:#fff; font-size:11px; }

/* Modals */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:9999; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex !important; z-index:99999 !important; }
.modal-box { background:#fff; border-radius:12px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.15); padding:28px; }
.modal-box h3 { margin:0 0 16px; font-size:15px; color:#00264D; font-weight:700; text-transform:uppercase; display:flex; align-items:center; gap:8px; border-bottom:1px solid #f1f5f9; padding-bottom:10px; }
.modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #e9ecef; }
.modal-title { font-size:1.05rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; }
.modal-close { background:none; border:none; font-size:1.4rem; cursor:pointer; color:#888; line-height:1; padding:0 4px; }
.modal-close:hover { color:#333; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
.form-group input, .form-group select, .form-group textarea { width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; outline:none; }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:16px; }

/* Custom Outlined Buttons for Petron-clean Look */
.int-btn-outline {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid #002F6C;
    transition: all 0.2s;
    background: white !important;
    color: #002F6C !important;
    height: 30px;
    line-height: 1;
    white-space: nowrap;
    text-decoration: none;
}
.int-btn-outline:hover {
    background: #002F6C !important;
    color: white !important;
}

.int-btn-outline-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid #dc3545;
    transition: all 0.2s;
    background: white !important;
    color: #dc3545 !important;
    height: 30px;
    line-height: 1;
    white-space: nowrap;
    text-decoration: none;
}
.int-btn-outline-danger:hover {
    background: #dc3545 !important;
    color: white !important;
}

.int-btn-outline-success {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid #28a745;
    transition: all 0.2s;
    background: white !important;
    color: #28a745 !important;
    height: 30px;
    line-height: 1;
    white-space: nowrap;
    text-decoration: none;
}
.int-btn-outline-success:hover {
    background: #28a745 !important;
    color: white !important;
}

.status-badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; white-space:nowrap; }
.badge-pending          { background:#fff3cd; color:#856404; border:1px solid #ffeeba; }
.badge-approved         { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.badge-rejected         { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.badge-other            { background:#e2e8f0; color:#475569; border:1px solid #cbd5e1; }

.empty-state { text-align:center; padding:40px 20px; color:#94a3b8; }
.empty-state i { font-size:40px; display:block; margin-bottom:12px; opacity:.4; }

/* Hide filtered rows */
.search-hidden { display: none !important; }

.modal-tab-btn {
    border: none;
    background: none !important;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.modal-tab-btn:hover {
    color: #002F70;
    background: none !important;
}
.modal-tab-btn.active {
    color: #002F70;
    border-bottom-color: #002F70;
    background: none !important;
}

/* Export Buttons (Filter Button Style) */
.flt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.15s;
    height: 34px;
    line-height: 1;
    white-space: nowrap;
    text-decoration: none;
    background: white !important;
}
.flt-btn-search { color: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-search:hover { background: #002F70 !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-csv { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-csv:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }

/* == Petron Clean KPI Summary Cards (Matches Master Data Requests Exactly) == */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 18px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 1100px) {
    .txn-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .txn-kpi-grid {
        grid-template-columns: 1fr;
    }
}
.txn-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: none;
    transition: transform .15s, box-shadow .15s;
    box-sizing: border-box;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,.09);
}
.txn-kpi-lbl {
    font-size: 10px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
    color: #64748b !important;
    margin-bottom: 6px !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    line-height: 1.3 !important;
    white-space: nowrap !important;
}
.txn-kpi-val {
    font-size: 26px !important;
    font-weight: 800 !important;
    color: #002F70 !important;
    line-height: 1.1 !important;
}
.txn-kpi-card.blue   .txn-kpi-val { color: #0284c7 !important; }
.txn-kpi-card.green  .txn-kpi-val { color: #16a34a !important; }
.txn-kpi-card.danger .txn-kpi-val { color: #dc2626 !important; }
.txn-kpi-card.dark-danger .txn-kpi-val { color: #991b1b !important; }
.txn-kpi-card.orange .txn-kpi-val { color: #d97706 !important; }
.txn-kpi-card.yellow .txn-kpi-val { color: #d97706 !important; }
.txn-kpi-card.purple .txn-kpi-val { color: #7c3aed !important; }
.txn-kpi-card.teal   .txn-kpi-val { color: #0d9488 !important; }
/* ── Consistent Petron Filter Controls ── */
.filter-select,
.inv-filter-bar select {
    height: 36px !important;
    padding: 0 12px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 7px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    background: #ffffff !important;
    outline: none !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
}
.filter-select:focus,
.inv-filter-bar select:focus {
    border-color: #002F70 !important;
    box-shadow: 0 0 0 3px rgba(0,47,112,.1) !important;
}
.filter-input,
.inv-filter-bar input {
    height: 36px !important;
    padding: 0 12px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 7px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    background: #ffffff !important;
    outline: none !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
    box-sizing: border-box !important;
}
.filter-input:focus,
.inv-filter-bar input:focus {
    border-color: #002F70 !important;
    box-shadow: 0 0 0 3px rgba(0,47,112,.1) !important;
}
</style>

<div class="mim-wrap">
<div class="int-head">
    <div>
        <h1><i class="fas fa-boxes"></i> Merchandise Inventory</h1>
    </div>
</div>

<!-- Success/Error Messages -->
<?php if (!empty($_SESSION['success'])): ?>
<div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
</div>
<?php endif; ?>


<!-- Tabs Navigation -->
<div class="tab-nav" style="overflow-x:auto; flex-wrap:nowrap; white-space:nowrap; padding-bottom:4px;">
    <a href="manager_inventory_merchandise.php?tab=inventory" class="tab-btn <?= $active_tab === 'inventory' ? 'active' : '' ?>">
        <i class="fas fa-boxes"></i> Inventory Overview
    </a>
    <a href="manager_inventory_merchandise.php?tab=movement" class="tab-btn <?= in_array($active_tab, ['movement', 'stockin', 'stockout', 'transfers', 'damaged', 'expired']) ? 'active' : '' ?>">
        <i class="fas fa-exchange-alt"></i> Stock Movement Monitoring
    </a>
    <a href="manager_inventory_merchandise.php?tab=alerts" class="tab-btn <?= $active_tab === 'alerts' ? 'active' : '' ?>">
        <i class="fas fa-exclamation-triangle"></i> Stock Alerts
        <?php if (($summary_low + $summary_out + $summary_expired) > 0): ?>
            <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;"><?= ($summary_low + $summary_out + $summary_expired) ?></span>
        <?php endif; ?>
    </a>
    <a href="manager_inventory_merchandise.php?tab=adjustments" class="tab-btn <?= ($active_tab === 'requests' || $active_tab === 'adjustments') ? 'active' : '' ?>">
        <i class="fas fa-sliders"></i> Inventory Adjustments
        <?php if ($summary_adj_pending > 0): ?>
            <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:800;"><?= $summary_adj_pending ?></span>
        <?php endif; ?>
    </a>
</div>
<!-- TAB CONTENT 1: Inventory Stock Catalog -->
<?php if ($active_tab === 'inventory'): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px;width:100%;box-sizing:border-box;">
    <!-- Total Products -->
    <div onclick="filterMgrByCard('')" class="txn-kpi-card blue" style="cursor:pointer;" title="Click to show All Products">
        <div class="txn-kpi-lbl"><i class="fas fa-boxes" style="color:#0284c7;margin-right:4px;"></i> Total Products</div>
        <div class="txn-kpi-val"><?= number_format($summary_total) ?></div>
    </div>
    <!-- Current Inventory -->
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-cubes" style="color:#002F70;margin-right:4px;"></i> Current Inventory</div>
        <div class="txn-kpi-val"><?= number_format(array_sum(array_column($merch_inventory, 'stock_level'))) ?></div>
    </div>
    <!-- Stock Added Today -->
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-arrow-down" style="color:#16a34a;margin-right:4px;"></i> Stock Added Today</div>
        <div class="txn-kpi-val">+<?= number_format($stock_added_today) ?></div>
    </div>
    <!-- Stock Deducted Today -->
    <div class="txn-kpi-card orange">
        <div class="txn-kpi-lbl"><i class="fas fa-arrow-up" style="color:#c2410c;margin-right:4px;"></i> Stock Deducted Today</div>
        <div class="txn-kpi-val">-<?= number_format($stock_deducted_today) ?></div>
    </div>
    <!-- Low Stock -->
    <div onclick="filterMgrByCard('warning')" class="txn-kpi-card orange" style="cursor:pointer;" title="Click to filter low stock items">
        <div class="txn-kpi-lbl"><i class="fas fa-exclamation-triangle" style="color:#d97706;margin-right:4px;"></i> Low Stock</div>
        <div class="txn-kpi-val"><?= number_format($summary_alert_low) ?></div>
    </div>

    <!-- Out of Stock -->
    <div onclick="filterMgrByCard('warning')" class="txn-kpi-card dark-danger" style="cursor:pointer;" title="Click to filter out of stock items">
        <div class="txn-kpi-lbl"><i class="fas fa-times-circle" style="color:#991b1b;margin-right:4px;"></i> Out of Stock</div>
        <div class="txn-kpi-val"><?= number_format($summary_out) ?></div>
    </div>
    <!-- Expired Stock -->
    <div onclick="filterMgrByCard('expired')" class="txn-kpi-card danger" style="cursor:pointer;" title="Click to filter expired stock items">
        <div class="txn-kpi-lbl"><i class="fas fa-ban" style="color:#dc2626;margin-right:4px;"></i> Expired Stock</div>
        <div class="txn-kpi-val"><?= number_format($summary_expired) ?></div>
    </div>
    <!-- Total Inventory Value -->
    <div class="txn-kpi-card teal">
        <div class="txn-kpi-lbl"><i class="fas fa-peso-sign" style="color:#0d9488;margin-right:4px;"></i> Total Inventory Value</div>
        <div class="txn-kpi-val" style="font-size:22px !important;">₱<?= number_format($total_inventory_value, 2) ?></div>
    </div>
</div>

<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-box"></i> Merchandise Stock Catalog
        </div>
        <div class="inv-filter-bar" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <input type="text" id="invSearch" placeholder="Search Product or SKU..." oninput="filterInvTable()" class="filter-input" style="width:180px;">
            <select id="invCatFilter" onchange="filterInvTable()" class="filter-select">
                <option value="">All Categories</option>
                <?php foreach (array_keys($categories_list) as $cat): ?>
                <option value="<?php echo strtolower(htmlspecialchars($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="invStockFilter" onchange="filterInvTable()" class="filter-select">
                <option value="">All Statuses</option>
                <option value="available">Available</option>
                <option value="low">Low Stock</option>
                
                <option value="out of stock">Out of Stock</option>
                <option value="variance detected">Variance Detected</option>
                <option value="expired">Expired</option>
                <option value="warning" hidden>Stock Alerts</option>
            </select>
        </div>
    </div>
    <div class="table-wrap" style="width:100% !important;max-width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;">
        <table class="table report-table no-min-width print-table merch-tbl" id="mgrMerchTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
        <colgroup>
            <col style="width:11%;"><!-- ITEM IDENTIFIERS -->
            <col style="width:23%;"><!-- PRODUCT & CATEGORY -->
            <col style="width:10%;"><!-- EXPIRY DATE -->
            <col style="width:16%;"><!-- STOCK LEVELS -->
            <col style="width:12%;"><!-- STATUS -->
            <col style="width:14%;"><!-- LAST UPDATED -->
            <col style="width:14%;"><!-- ACTIONS -->
        </colgroup>
        <thead>
            <tr>
                <th style="white-space:nowrap;">ITEM IDENTIFIERS</th>
                <th style="white-space:nowrap;">PRODUCT & CATEGORY</th>
                <th style="white-space:nowrap;text-align:center;">EXPIRATION</th>
                <th style="white-space:nowrap;">STOCK LEVELS</th>
                <th style="white-space:nowrap;text-align:center;">STATUS</th>
                <th style="white-space:nowrap;">LAST UPDATED</th>
                <th style="white-space:nowrap;text-align:center;">ACTIONS</th>
            </tr>
        </thead>
        <tbody id="merchTableBody">
        <?php
        foreach ($sorted as $cat_label => $items):
        ?>
            <tr class="cat-header no-paginate"><td colspan="7" style="background:#f1f5f9;font-weight:800;font-size:13px;padding:8px 12px;color:#002F70;text-align:left;"><i class="fas fa-folder" style="color:#2563eb;margin-right:6px;"></i><?php echo htmlspecialchars($cat_label); ?></td></tr>
            <?php foreach ($items as $item):
                $stock    = (float)($item['stock_level'] ?? 0);
                $reorder  = (float)($item['reorder_level'] ?? 24);
                if ($reorder  <= 0) $reorder  = 24;  // safety: Low Stock threshold
                $critical = (float)($item['critical_level'] ?? 10);
                if ($critical <= 0) $critical = 10;  // safety: Critical threshold
                $capacity = max(480.0, (float)($item['capacity'] ?? 480));
                $unit     = htmlspecialchars(format_product_unit_display($item['unit'] ?? 'pcs', $item['name'] ?? '', $item['category_name'] ?? ''));
                $variance = $item['variance'];
                $has_variance = ($variance !== null && (float)$variance != 0);

                $pid = (int)$item['id'];
                $pname_norm = strtolower(trim((string)($item['name'] ?? '')));
                $added_qty = (float)($prod_added_map_id[$pid] ?? $prod_added_map_name[$pname_norm] ?? $prod_added_map[$pid] ?? 0);
                $deducted_qty = (float)($prod_deducted_map_id[$pid] ?? $prod_deducted_map_name[$pname_norm] ?? $prod_deducted_map[$pid] ?? 0);

                $fill_pct = $capacity > 0 ? min(100, ($stock / $capacity) * 100) : 0;
                $batch_id = !empty($item['batch_ref']) ? $item['batch_ref'] : (!empty($item['batch_number']) ? $item['batch_number'] : ('B' . str_pad((string)$pid, 3, '0', STR_PAD_LEFT)));
                $exp_date = $item['expiration_date'] ?? 'N/A';
                $exp_raw = $item['exp_raw'] ?? null;
                $exp_status = $item['exp_status'] ?? '';
                $initial_qty = $added_qty > 0 ? (int)$added_qty : (int)$capacity;

                if ($exp_status === 'expired') {
                    $st = 'EXPIRED'; $sc = '#dc3545'; $si_cls = 'expired';
                } elseif ($stock <= 0) {
                    $st = 'OUT OF STOCK'; $sc = '#dc3545'; $si_cls = 'out of stock';
                } elseif ($stock <= $critical) {
                    $st = 'CRITICAL STOCK'; $sc = '#dc3545'; $si_cls = 'critical';
                } elseif ($stock <= $reorder) {
                    $st = 'LOW STOCK'; $sc = '#fd7e14'; $si_cls = 'low';
                } else {
                    $st = 'AVAILABLE'; $sc = '#28a745'; $si_cls = 'available';
                }

                // If has variance and not expired, show it in status but keep underlying status in data-stock-status
                $stock_status_class = $si_cls; // Preserve original status for filtering
                if ($has_variance && $exp_status !== 'expired') {
                    $st = 'VARIANCE DETECTED'; $sc = '#fd7e14'; // Warning color (Orange)
                    $si_cls = 'variance detected';
                }

                $timestamp = '—';
                if (!empty($item['last_updated'])) {
                    try {
                        $timestamp = (new DateTime($item['last_updated']))->format('M d, Y g:i A');
                    } catch (Exception $e) {}
                }
            ?>
            <tr class="merch-row"
                data-id="<?php echo (int)$item['id']; ?>"
                data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>"
                data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                data-cat="<?php echo strtolower(htmlspecialchars($item['category_name'] ?? '')); ?>"
                data-has-variance="<?php echo $has_variance ? 'true' : 'false'; ?>"
                data-inv-status="<?php echo $exp_status === 'expired' ? 'expired' : $si_cls; ?>"
                data-stock-status="<?php echo $exp_status === 'expired' ? 'expired' : $stock_status_class; ?>">
                
                <!-- 1. ITEM IDENTIFIERS -->
                <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
                    <div style="font-family:monospace;font-size:12.5px;font-weight:700;color:#002F70;white-space:nowrap;" title="Batch ID">
                        <i class="fas fa-layer-group" style="font-size:10.5px;color:#2563eb;margin-right:2px;"></i> <?= htmlspecialchars($batch_id) ?>
                    </div>
                    <div style="font-family:monospace;font-size:11.5px;font-weight:700;color:#4f46e5;margin-top:2px;white-space:nowrap;" title="SKU">
                        <?= htmlspecialchars($item['sku'] ?? '—') ?>
                    </div>
                </td>

                <!-- 2. PRODUCT & CATEGORY -->
                <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
                    <div style="font-weight:800;font-size:13.5px;color:#0f172a;line-height:1.3;word-break:break-word;overflow-wrap:break-word;"><?= htmlspecialchars($item['name']) ?></div>
                    <div style="font-size:11.5px;color:#64748b;margin-top:3px;font-weight:600;display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
                        <span style="color:#0369a1;"><i class="fas fa-tag" style="font-size:10px;"></i> <?= htmlspecialchars($item['category_name'] ?? 'General') ?></span>
                        <span style="color:#cbd5e1;">•</span>
                        <span style="color:#475569;font-weight:700;"><?= $unit ?></span>
                    </div>
                </td>

                <!-- 3. EXPIRY DATE -->
                <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;text-align:center;">
                    <span style="font-size:12.5px;font-weight:700;color:<?= $exp_status === 'expired' ? '#dc3545' : ($exp_date !== 'N/A' ? '#0f172a' : '#94a3b8') ?>;white-space:nowrap;">
                        <i class="fas fa-calendar-alt" style="font-size:11px;color:<?= $exp_status === 'expired' ? '#dc3545' : ($exp_date !== 'N/A' ? '#2563eb' : '#cbd5e1') ?>;margin-right:3px;"></i> <?= htmlspecialchars($exp_date) ?>
                    </span>
                    <?php if ($exp_status === 'expired'): ?>
                    <div style="margin-top:4px;">
                        <span style="display:inline-block;background:#dc354520;color:#dc3545;border:1.5px solid #dc354560;border-radius:5px;font-size:10px;font-weight:800;padding:2px 7px;text-transform:uppercase;white-space:nowrap;letter-spacing:0.4px;">
                            <i class="fas fa-exclamation-circle" style="font-size:9px;margin-right:2px;"></i>EXPIRED
                        </span>
                    </div>
                    <?php elseif ($exp_status === 'expiring_soon'): ?>
                    <div style="margin-top:4px;">
                        <span style="display:inline-block;background:#fd7e1420;color:#c05c00;border:1.5px solid #fd7e1460;border-radius:5px;font-size:10px;font-weight:800;padding:2px 7px;text-transform:uppercase;white-space:nowrap;letter-spacing:0.4px;">
                            <i class="fas fa-clock" style="font-size:9px;margin-right:2px;"></i>EXPIRING SOON
                        </span>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- 4. STOCK LEVELS -->
                <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
                    <div class="fill-bar-wrap" style="height:6px;border-radius:3px;background:#e2e8f0;overflow:hidden;margin-bottom:3px;">
                        <div class="fill-bar-inner" style="width:<?= min(100,round($fill_pct)) ?>%;background:<?= $sc ?>;height:100%;"></div>
                    </div>
                    <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:4px;">
                        <span style="font-size:13px;font-weight:800;color:#0f172a;white-space:nowrap;"><?= number_format($stock, 0) ?> <small style="font-size:11px;color:#64748b;font-weight:600;"><?= $unit ?></small></span>
                        <span style="font-size:11px;color:#64748b;white-space:nowrap;font-weight:600;">Reorder: <strong style="color:#ea580c;"><?= number_format($reorder, 0) ?></strong></span>
                    </div>
                    <div style="font-size:10.5px;color:#64748b;margin-top:2px;white-space:nowrap;">Init: <?= number_format($initial_qty) ?></div>
                </td>

                <!-- 5. STATUS -->
                <td style="padding:9px 6px;max-width:0;overflow:visible;box-sizing:border-box;vertical-align:middle;text-align:center;">
                    <?php if ($exp_status === 'expired'): ?>
                        <span class="inv-stock-badge" style="background:#dc354520;color:#dc3545;border:1.5px solid #dc354560;padding:4px 9px;border-radius:6px;font-size:11px;font-weight:800;text-transform:uppercase;white-space:nowrap;display:inline-block;">
                            <i class="fas fa-ban" style="font-size:9.5px;margin-right:2px;"></i> EXPIRED
                        </span>
                    <?php else: ?>
                        <span class="inv-stock-badge" style="background:<?= $sc ?>20;color:<?= $sc ?>;border:1.5px solid <?= $sc ?>50;padding:4px 9px;border-radius:6px;font-size:11px;font-weight:800;text-transform:uppercase;white-space:nowrap;display:inline-block;">
                            <?= htmlspecialchars($st) ?>
                        </span>
                    <?php endif; ?>
                </td>

                <!-- 6. LAST UPDATED -->
                <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
                    <?php if (!empty($item['last_updated'])): ?>
                        <div style="font-size:12px;font-weight:700;color:#1e293b;white-space:nowrap;"><?= (new DateTime($item['last_updated']))->format('M d, Y') ?></div>
                        <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:2px;white-space:nowrap;"><?= (new DateTime($item['last_updated']))->format('h:i A') ?></div>
                    <?php else: ?>
                        <span style="color:#94a3b8;font-size:12px;">—</span>
                    <?php endif; ?>
                </td>

                <!-- 7. ACTIONS -->
                <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;text-align:center;vertical-align:middle;">
                    <button type="button" class="int-btn-outline" style="font-size:12px;height:30px;padding:0 10px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;width:100%;border-radius:6px;border:1.5px solid #002F70;color:#002F70;background:#fff;box-sizing:border-box;" onclick="event.stopPropagation(); openProductModal(<?= (int)$item['id'] ?>)">
                        <i class="fas fa-eye"></i> View Details
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
            <tr id="invNoMatchRow" style="display:none;" class="no-paginate">
                <td colspan="7" class="empty-state" style="text-align:center;padding:36px 20px;color:#64748b;font-size:14px;font-weight:600;">
                    <i class="fas fa-search" style="font-size:28px;display:block;margin-bottom:10px;color:#94a3b8;"></i>
                    <span id="invNoMatchMsg">No products found matching your search.</span>
                </td>
            </tr>
        </tbody>
        </table>
    </div>
    <!-- Inventory Pagination Footer -->
    <div id="mgrMerchPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px; border-top:1px solid #e2e8f0; background:#fff; border-radius:0 0 10px 10px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center;">
            <span id="mgrMerchShowingText" style="font-size:13px; color:#64748b; font-weight:600;">Showing entries…</span>
        </div>
        <div style="display:flex; align-items:center; gap:16px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                <select id="mgrMerchPerPage" onchange="mgrMerchChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <button id="mgrMerchPrevBtn" onclick="mgrMerchGoPage(mgrMerchState.page - 1)"
                        style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <span id="mgrMerchPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of 1</span>
                <button id="mgrMerchNextBtn" onclick="mgrMerchGoPage(mgrMerchState.page + 1)"
                        style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; color:#475569; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
    <div id="mgrMerchPagination" style="display:none;"></div>
</div>
<?php endif; ?>

<!-- ══ TAB: STOCK MOVEMENT MONITORING ══ -->
<?php if ($active_tab === 'movement' || in_array($active_tab, ['stockin', 'stockout', 'transfers', 'damaged', 'expired'])): ?>
<?php
    $tot_in = 0;
    $tot_out = 0;
    foreach ($movement_history as $m) {
        $q = (float)($m['quantity'] ?? 0);
        if ($q > 0) $tot_in += $q;
        elseif ($q < 0) $tot_out += abs($q);
    }
?>
<!-- Movement Summary KPI Cards -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:20px;">
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Movement Logs</div>
            <div style="font-size:24px; font-weight:800; color:#002F70; margin-top:4px;"><?= number_format(count($movement_history)) ?></div>
        </div>
        <div style="background:#f0f4ff; color:#002F70; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-history"></i></div>
    </div>
    <div style="background:#fff; border:1px solid #bbf7d0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:.3px;">Stock Added (Inflow)</div>
            <div style="font-size:24px; font-weight:800; color:#15803d; margin-top:4px;">+<?= number_format($tot_in) ?></div>
        </div>
        <div style="background:#dcfce7; color:#15803d; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-arrow-down"></i></div>
    </div>
    <div style="background:#fff; border:1px solid #fed7aa; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#c2410c; text-transform:uppercase; letter-spacing:.3px;">Stock Deducted (Outflow)</div>
            <div style="font-size:24px; font-weight:800; color:#c2410c; margin-top:4px;">-<?= number_format($tot_out) ?></div>
        </div>
        <div style="background:#ffedd5; color:#c2410c; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-arrow-up"></i></div>
    </div>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Adjustments &amp; Variances</div>
            <div style="font-size:24px; font-weight:800; color:#0f172a; margin-top:4px;"><?= number_format($mov_variance_count + $mov_adjustment_count) ?></div>
        </div>
        <div style="background:#f1f5f9; color:#475569; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-sliders"></i></div>
    </div>
</div>

<!-- Stock Movement Monitoring Table Card -->
<div style="background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:20px;">
    <div style="padding:14px 20px; border-bottom:1px solid #e9ecef; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <div style="font-size:1rem; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-exchange-alt"></i> Stock Movement Logs
        </div>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <input type="text" id="mgrMovSearchInput" placeholder="Search product, SKU, user..." oninput="filterMgrMovTable()" class="filter-input" style="width:220px;">
            <select id="mgrMovTypeFilter" onchange="filterMgrMovTable()" class="filter-select" style="width:auto;min-width:180px;">
                <option value="">All Movement Types</option>
                <option value="stock_in">Stock In (Delivery)</option>
                <option value="stock-in">Stock In</option>
                <option value="delivery">Delivery</option>
                <option value="sale">Sales / Job Order (OUT)</option>
                <option value="release">Release (OUT)</option>
                <option value="adjustment">Adjustment</option>
                <option value="transfer">Transfer</option>
                <option value="damage">Damaged / Write-off</option>
            </select>
        </div>
    </div>
    <div class="table-wrap" style="width:100% !important;max-width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;">
        <table class="table report-table no-min-width print-table merch-mov-tbl" id="mgrMerchMovTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
            <colgroup>
                <col style="width:10%;">
                <col style="width:14%;">
                <col style="width:18%;">
                <col style="width:9%;">
                <col style="width:9%;">
                <col style="width:11%;">
                <col style="width:10%;">
                <col style="width:9%;">
                <col style="width:10%;">
            </colgroup>
            <thead>
                <tr style="background:#002F70; color:#fff;">
                    <th style="white-space:nowrap;color:#fff;">Reference</th>
                    <th style="white-space:nowrap;color:#fff;">Date &amp; Time</th>
                    <th style="white-space:nowrap;color:#fff;">Product Name</th>
                    <th style="white-space:nowrap;color:#fff;">SKU</th>
                    <th style="white-space:nowrap;color:#fff;text-align:center;">Type</th>
                    <th style="white-space:nowrap;color:#fff;text-align:right;">Qty Change</th>
                    <th style="white-space:nowrap;color:#fff;text-align:right;">Stock Level</th>
                    <th style="white-space:nowrap;color:#fff;">Performed By</th>
                    <th style="white-space:nowrap;color:#fff;">Remarks</th>
                </tr>
            </thead>
            <tbody id="mgrMerchMovTbody">
            <?php if (empty($movement_history)): ?>
                <tr><td colspan="9" style="text-align:center; padding:32px; color:#64748b;"><i class="fas fa-info-circle" style="font-size:1.8em; display:block; margin-bottom:8px;"></i> No merchandise movement records found.</td></tr>
            <?php else: ?>
                <?php foreach ($movement_history as $log):
                    $mtype = strtolower($log['movement_type'] ?? 'log');
                    $qty = (float)($log['quantity'] ?? 0);
                    $qty_color = $qty > 0 ? '#16a34a' : ($qty < 0 ? '#dc2626' : '#64748b');
                    $qty_str = ($qty > 0 ? '+' : '') . number_format($qty);
                    $ref = !empty($log['reference_id']) ? $log['reference_id'] : ('LOG-' . str_pad($log['log_id'], 4, '0', STR_PAD_LEFT));
                    
                    $is_in = ($qty > 0) || in_array($mtype, ['stock_in', 'stock-in', 'delivery', 'transfer_in']);
                    $is_out = ($qty < 0) || in_array($mtype, ['sale', 'release', 'stock_out', 'stock-out', 'job_order']);
                    $badge_bg = $is_in ? '#dcfce7' : ($is_out ? '#fee2e2' : '#fef3c7');
                    $badge_color = $is_in ? '#15803d' : ($is_out ? '#b91c1c' : '#b45309');
                ?>
                <tr class="mgr-mmov-row"
                    data-search="<?= strtolower(htmlspecialchars($ref . ' ' . $log['product_name'] . ' ' . $log['sku'] . ' ' . $log['movement_type'] . ' ' . $log['user_name'] . ' ' . $log['notes'])) ?>"
                    data-type="<?= strtolower(htmlspecialchars($log['movement_type'])) ?>"
                    style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;"><code style="font-size:11.5px; font-weight:700; color:#002F70;"><?= htmlspecialchars($ref) ?></code></td>
                    <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;font-size:12px; color:#334155; font-weight:600; white-space:nowrap;"><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
                    <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;"><strong style="font-size:12.5px;color:#0f172a;word-break:break-word;line-height:1.25;"><?= htmlspecialchars($log['product_name']) ?></strong></td>
                    <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;"><code style="font-size:11px; color:#475569;"><?= htmlspecialchars($log['sku'] ?: '—') ?></code></td>
                    <td style="padding:9px 4px;max-width:0;overflow:hidden;box-sizing:border-box;text-align:center;"><span style="background:<?= $badge_bg ?>; color:<?= $badge_color ?>; padding:3px 7px; border-radius:12px; font-size:10px; font-weight:700; text-transform:uppercase;white-space:nowrap;"><?= htmlspecialchars($log['movement_type']) ?></span></td>
                    <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;text-align:right; font-weight:800; font-size:13px; color:<?= $qty_color ?>;"><?= $qty_str ?> <span style="font-size:11px; font-weight:600; color:#64748b;"><?= htmlspecialchars($log['unit']) ?></span></td>
                    <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;text-align:right; font-size:11.5px; color:#475569;"><span style="color:#94a3b8;"><?= number_format((float)$log['quantity_before']) ?></span> &rarr; <strong style="color:#002F70;"><?= number_format((float)$log['quantity_after']) ?></strong></td>
                    <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;font-size:12px; color:#334155;"><strong><?= htmlspecialchars($log['user_name']) ?></strong></td>
                    <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;font-size:11.5px; color:#64748b;" title="<?= htmlspecialchars($log['notes'] ?: '—') ?>"><?= htmlspecialchars(mb_strimwidth($log['notes'] ?: '—', 0, 18, '...')) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
                <tr id="mgrMovNoMatchRow" style="display:none;" class="no-paginate">
                    <td colspan="9" class="empty-state" style="text-align:center;padding:36px 20px;color:#64748b;font-size:14px;font-weight:600;">
                        <i class="fas fa-search" style="font-size:28px;display:block;margin-bottom:10px;color:#94a3b8;"></i>
                        <span id="mgrMovNoMatchMsg">No movements found matching your search.</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <!-- Movement Pagination Footer -->
    <div id="mgrMerchMovPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px; border-top:1px solid #e2e8f0; background:#fff; border-radius:0 0 10px 10px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center;">
            <span id="mgrMerchMovShowingText" style="font-size:13px; color:#64748b; font-weight:600;">Showing entries…</span>
        </div>
        <div style="display:flex; align-items:center; gap:16px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                <select id="mgrMerchMovPerPage" onchange="mgrMerchMovChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <button id="mgrMerchMovPrevBtn" onclick="mgrMerchMovGoPage(mgrMerchMovState.page - 1)"
                        style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <span id="mgrMerchMovPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of 1</span>
                <button id="mgrMerchMovNextBtn" onclick="mgrMerchMovGoPage(mgrMerchMovState.page + 1)"
                        style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; color:#475569; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
    <div id="mgrMerchMovPagination" style="display:none;"></div>
</div>
<?php endif; ?>

<!-- TAB CONTENT 2: Stock Alerts -->
<?php if ($active_tab === 'alerts'): ?>

<!-- Stock Alerts Summary Cards (Matches Master Data Requests Exactly) -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card orange">
        <div class="txn-kpi-lbl"><i class="fas fa-exclamation-triangle" style="color:#d97706;margin-right:4px;"></i> Low Stock Items</div>
        <div class="txn-kpi-val"><?= number_format($summary_alert_low) ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-exclamation-circle" style="color:#dc2626;margin-right:4px;"></i> Critical Stock Items</div>
        <div class="txn-kpi-val"><?= number_format($summary_alert_critical) ?></div>
    </div>
    <div class="txn-kpi-card dark-danger">
        <div class="txn-kpi-lbl"><i class="fas fa-times-circle" style="color:#991b1b;margin-right:4px;"></i> Out of Stock Items</div>
        <div class="txn-kpi-val"><?= number_format($summary_out) ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-ban" style="color:#dc2626;margin-right:4px;"></i> Expired Stock Items</div>
        <div class="txn-kpi-val"><?= number_format($summary_expired) ?></div>
    </div>
    <div class="txn-kpi-card purple">
        <div class="txn-kpi-lbl"><i class="fas fa-balance-scale" style="color:#7c3aed;margin-right:4px;"></i> Variance Alerts</div>
        <div class="txn-kpi-val"><?= number_format($summary_variance) ?></div>
    </div>
</div>
<!-- Stock Alerts Table Card -->
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1.5px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="font-size:16px;font-weight:800;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-exclamation-triangle" style="color:#fd7e14;"></i> Stock Alerts
            <?php $total_alerts = $summary_alert_low + $summary_alert_critical + $summary_out + $summary_variance + $summary_expired; ?>
            <?php if ($total_alerts > 0): ?>
            <span style="background:#dc3545;color:#fff;border-radius:12px;padding:4px 12px;font-size:13px;font-weight:800;"><?= $total_alerts ?> items</span>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <input type="text" id="alertSearch" placeholder="Search Product or SKU..." oninput="filterAlertTable()" class="filter-input" style="width:210px;">
            <select id="alertCatFilter" onchange="filterAlertTable()" class="filter-select">
                <option value="">All Categories</option>
                <?php foreach (array_keys($categories_list) as $cat): ?>
                <option value="<?php echo strtolower(htmlspecialchars($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="table-wrap" style="width:100% !important;max-width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;">
        <table class="table report-table no-min-width print-table alert-tbl" id="mgrAlertTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
            <colgroup>
                <col style="width:10%;">
                <col style="width:26%;">
                <col style="width:10%;">
                <col style="width:8%;">
                <col style="width:8%;">
                <col style="width:7%;">
                <col style="width:13%;">
                <col style="width:18%;">
            </colgroup>
            <thead>
                <tr>
                    <th style="color:#fff;">SKU</th>
                    <th style="color:#fff;">Product Name</th>
                    <th style="color:#fff;text-align:center;">Category</th>
                    <th style="color:#fff;text-align:right;">Current Stock</th>
                    <th style="color:#fff;text-align:right;">Reorder Level</th>
                    <th style="color:#fff;text-align:right;">Variance</th>
                    <th style="color:#fff;text-align:center;">Status</th>
                    <th style="color:#fff;">Recommended Action</th>
                </tr>
            </thead>
            <tbody id="alertTableBody">
            <?php
            $alert_count = 0;
            foreach ($sorted as $cat_label => $items):
                $cat_alerts = [];
                foreach ($items as $item) {
                    $stock   = (float)($item['stock_level'] ?? 0);
                    $reorder = (float)($item['reorder_level'] ?? 24);
                    $critical = (float)($item['critical_level'] ?? 10);
                    $var     = $item['variance'];
                    $has_var = ($var !== null && (float)$var != 0);
                    if ($stock <= $reorder || $has_var || ($item['exp_status'] ?? '') === 'expired') { $cat_alerts[] = $item; }
                }
                if (empty($cat_alerts)) continue;
            ?>
                <tr class="cat-header no-paginate"><td colspan="8" style="background:#e2e8f0 !important;color:#002F70 !important;font-size:13.5px !important;font-weight:800 !important;text-align:center;padding:10px 14px;letter-spacing:.5px;border-bottom:2px solid #cbd5e1;"><strong><?php echo htmlspecialchars($cat_label); ?></strong></td></tr>
                <?php foreach ($cat_alerts as $item):
                    $alert_count++;
                    $stock    = (float)($item['stock_level'] ?? 0);
                    $reorder  = (float)($item['reorder_level'] ?? 24);
                    $critical = (float)($item['critical_level'] ?? 10);
                    $unit     = htmlspecialchars($item['unit'] ?? 'pcs');
                    $variance = $item['variance'];
                    $has_variance = ($variance !== null && (float)$variance != 0);
                    if (($item['exp_status'] ?? '') === 'expired') {
                        $st='Expired'; $sc='#dc3545'; $icon='fa-ban';
                        $recommended='Do Not Sell / Dispose & Adjust Stock';
                        $rec_icon='fa-trash-alt'; $rec_color='#dc3545'; $alert_type_cls='expired';
                    } elseif ($has_variance && $stock > $reorder) {
                        $st='Variance Detected'; $sc='#28a745'; $icon='fa-balance-scale';
                        $recommended='Conduct Physical Count';
                        $rec_icon='fa-clipboard-check'; $rec_color='#28a745'; $alert_type_cls='variance detected';
                    } elseif ($stock <= 0) {
                        $st='Out of Stock'; $sc='#dc3545'; $icon='fa-times-circle';
                        $recommended='Immediate Restock Required';
                        $rec_icon='fa-exclamation-circle'; $rec_color='#dc3545'; $alert_type_cls='out of stock';
                    } elseif ($stock <= $critical) {
                        $st='Critical Stock'; $sc='#dc3545'; $icon='fa-fire';
                        $recommended='Urgent: Create Stock Request';
                        $rec_icon='fa-bolt'; $rec_color='#dc3545'; $alert_type_cls='critical stock';
                    } else {
                        $st='Low Stock'; $sc='#fd7e14'; $icon='fa-exclamation-triangle';
                        $recommended='Create Stock Request';
                        $rec_icon='fa-file-alt'; $rec_color='#fd7e14'; $alert_type_cls='low stock';
                    }
                    if ($has_variance && $stock <= $reorder && ($item['exp_status'] ?? '') !== 'expired') { $recommended .= ' + Physical Count'; }
                    $var_text='&mdash;'; $var_style='color:#64748b;';
                    if ($variance !== null) {
                        $v_val = (float)$variance;
                        if ($v_val > 0)     { $var_text='+'.number_format($v_val,0); $var_style='color:#28a745;font-weight:700;'; }
                        elseif ($v_val < 0) { $var_text=number_format($v_val,0);     $var_style='color:#dc3545;font-weight:700;'; }
                        else                 { $var_text='0';                           $var_style='color:#64748b;font-weight:600;'; }
                    }
                ?>
                <tr class="alert-row"
                    data-id="<?php echo (int)$item['id']; ?>"
                    data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>"
                    data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                    data-cat="<?php echo strtolower(htmlspecialchars($item['category_name'] ?? '')); ?>"
                    data-alert-type="<?php echo $alert_type_cls; ?>">
                    <td><code style="font-size:12.5px;font-weight:700;color:#334155;"><?php echo htmlspecialchars($item['sku'] ?? ''); ?></code></td>
                    <td style="white-space:normal !important;word-break:break-word !important;overflow-wrap:break-word !important;"><strong style="font-size:14px;font-weight:800;color:#0f172a;line-height:1.35;"><?php echo htmlspecialchars($item['name']); ?></strong></td>
                    <td style="text-align:center;font-size:13px;font-weight:600;color:#475569;white-space:normal !important;word-break:break-word !important;"><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></td>
                    <td style="text-align:right;font-weight:900;font-size:16px;color:<?php echo $sc; ?>;"><?php echo number_format($stock, 0); ?></td>
                    <td style="text-align:right;font-weight:700;font-size:14px;color:#475569;"><?php echo number_format($reorder, 0); ?></td>
                    <td style="text-align:right;font-size:14px;<?php echo $var_style; ?>"><?php echo $var_text; ?></td>
                    <td style="text-align:center;">
                        <span style="display:inline-flex;align-items:center;gap:5px;background:<?php echo $sc; ?>18;color:<?php echo $sc; ?>;border:1.5px solid <?php echo $sc; ?>50;padding:5px 12px;border-radius:20px;font-size:11.5px;font-weight:800;text-transform:uppercase;white-space:nowrap;">
                            <i class="fas <?php echo $icon; ?>"></i> <?php echo $st; ?>
                        </span>
                    </td>
                    <td style="white-space:normal !important;word-break:break-word !important;overflow-wrap:break-word !important;">
                        <span style="font-size:13px;color:<?php echo $rec_color; ?>;font-weight:700;display:flex;align-items:center;gap:6px;">
                            <i class="fas <?php echo $rec_icon; ?>"></i> <?php echo htmlspecialchars($recommended); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if ($alert_count === 0): ?>
                <tr><td colspan="8" class="empty-state">
                    <i class="fas fa-check-circle" style="color:#28a745;font-size:32px;display:block;margin-bottom:8px;"></i>
                    No stock alerts. All products are at healthy stock levels!
                </td></tr>
            <?php endif; ?>
                <tr id="alertNoMatchRow" style="display:none;" class="no-paginate">
                    <td colspan="8" class="empty-state" style="text-align:center;padding:36px 20px;color:#64748b;font-size:14px;font-weight:600;">
                        <i class="fas fa-search" style="font-size:28px;display:block;margin-bottom:10px;color:#94a3b8;"></i>
                        <span id="alertNoMatchMsg">No stock alerts found matching your search.</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <!-- Alerts Pagination Footer -->
    <div id="mgrAlertPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px; border-top:1px solid #e2e8f0; background:#fff; border-radius:0 0 10px 10px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center;">
            <span id="mgrAlertShowingText" style="font-size:13px; color:#64748b; font-weight:600;">Showing entries…</span>
        </div>
        <div style="display:flex; align-items:center; gap:16px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                <select id="mgrAlertPerPage" onchange="mgrAlertChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <button id="mgrAlertPrevBtn" onclick="mgrAlertGoPage(mgrAlertState.page - 1)"
                        style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <span id="mgrAlertPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of 1</span>
                <button id="mgrAlertNextBtn" onclick="mgrAlertGoPage(mgrAlertState.page + 1)"
                        style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; color:#475569; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
    <div id="mgrAlertPagination" style="display:none;"></div>
</div>
<?php endif; ?>



<!-- TAB CONTENT 4: Stock Requests & Adjustments Review -->
<?php if ($active_tab === 'requests' || $active_tab === 'adjustments'): ?>

<!-- ══ PENDING INVENTORY ADJUSTMENTS (STAFF ADJUSTMENTS FOR MANAGER APPROVAL) ══ -->
<div class="tbl-card" style="margin-bottom:24px; background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1.5px solid #e9ecef; overflow:hidden;">
    <div class="tbl-hd" style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; background:#fff; border-bottom:1px solid #e9ecef;">
        <div class="tbl-title" style="font-size:16px; font-weight:800; color:#00264D; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-sliders-h" style="color:#fd7e14;"></i> Staff Inventory Adjustments (Pending Approval)
        </div>
    </div>
    <div class="table-wrap" style="width:100% !important;max-width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;">
        <table class="table report-table no-min-width print-table staff-adj-tbl" id="mgrStaffAdjTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
            <colgroup>
                <col style="width:7%;">
                <col style="width:10%;">
                <col style="width:17%;">
                <col style="width:8.5%;">
                <col style="width:6%;">
                <col style="width:6%;">
                <col style="width:14.5%;">
                <col style="width:9%;">
                <col style="width:11%;">
                <col style="width:11%;">
            </colgroup>
            <thead>
                <tr>
                    <th style="color:#fff;">Adj ID</th>
                    <th style="color:#fff;">Date</th>
                    <th style="color:#fff;">Product</th>
                    <th style="text-align:center;color:#fff;">Type</th>
                    <th style="text-align:right;color:#fff;">Current Stock</th>
                    <th style="text-align:right;color:#fff;">Qty Change</th>
                    <th style="color:#fff;">Reason / Remarks</th>
                    <th style="color:#fff;">Requested By</th>
                    <th style="text-align:center;color:#fff;">Status</th>
                    <th style="text-align:center;color:#fff;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($merchandise_adjustments)): ?>
                    <tr><td colspan="10" class="empty-state" style="text-align:center; padding:36px; color:#64748b; font-size:14px; font-weight:600;"><i class="fas fa-sliders-h" style="font-size:24px;display:block;margin-bottom:8px;color:#94a3b8;"></i>No staff inventory adjustment requests found.</td></tr>
                <?php else: ?>
                    <?php foreach ($merchandise_adjustments as $adj):
                        $st = strtolower($adj['status'] ?? 'pending');
                        $badge_cls = $st === 'pending' ? 'badge-pending' : ($st === 'approved' ? 'badge-approved' : 'badge-rejected');
                        $change_fmt = (int)$adj['quantity_change'] > 0 ? ('+' . number_format($adj['quantity_change'])) : number_format($adj['quantity_change']);
                        $change_color = (int)$adj['quantity_change'] > 0 ? '#16a34a' : '#dc2626';
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9; <?= $st === 'pending' ? 'background:#fffdfa;' : '' ?>">
                        <td><code style="font-size:12px; font-weight:700; color:#002F70;">ADJ-<?= str_pad($adj['id'], 4, '0', STR_PAD_LEFT) ?></code></td>
                        <td>
                            <div style="font-size:12.5px; font-weight:700; color:#0f172a;"><?= date('M d, Y', strtotime($adj['requested_at'])) ?></div>
                            <div style="font-size:11.5px; font-weight:600; color:#64748b;"><?= date('h:i A', strtotime($adj['requested_at'])) ?></div>
                        </td>
                        <td style="white-space:normal !important;word-break:break-word !important;overflow-wrap:break-word !important;">
                            <strong style="font-size:13.5px; font-weight:800; color:#0f172a; line-height:1.35;"><?= htmlspecialchars($adj['product_name']) ?></strong>
                            <?php if (!empty($adj['sku'])): ?><br><code style="font-size:11.5px; font-weight:600; color:#64748b;"><?= htmlspecialchars($adj['sku']) ?></code><?php endif; ?>
                        </td>
                        <td style="text-align:center;"><span style="font-weight:700; color:#002F70; font-size:12.5px;"><?= htmlspecialchars($adj['adjustment_type']) ?></span></td>
                        <td style="text-align:right; font-weight:700; font-size:14px; color:#475569;"><?= number_format($adj['current_stock']) ?></td>
                        <td style="text-align:right; font-weight:900; color:<?= $change_color ?>; font-size:15px;"><?= $change_fmt ?></td>
                        <td style="white-space:normal !important;word-break:break-word !important;overflow-wrap:break-word !important; font-size:13px; color:#334155; line-height:1.35;"><?= htmlspecialchars($adj['reason'] ?: '—') ?></td>
                        <td><strong style="font-size:13px; font-weight:700; color:#0f172a;"><?= htmlspecialchars($adj['staff_name'] ?? 'Staff') ?></strong></td>
                        <td style="text-align:center; overflow:visible !important; white-space:nowrap !important;">
                            <span class="status-badge <?= $badge_cls ?>" style="display:inline-flex; align-items:center; justify-content:center; gap:5px; font-weight:800; font-size:11px; text-transform:uppercase; padding:5px 12px; border-radius:14px; white-space:nowrap !important; letter-spacing:0.3px; box-sizing:border-box;">
                                <?php if ($st === 'approved'): ?>
                                    <i class="fas fa-check-circle" style="font-size:10.5px;"></i>
                                <?php elseif ($st === 'rejected'): ?>
                                    <i class="fas fa-times-circle" style="font-size:10.5px;"></i>
                                <?php else: ?>
                                    <i class="fas fa-clock" style="font-size:10.5px;"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($adj['status']) ?>
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($st === 'pending'): ?>
                                <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                                    <button type="button" onclick="openApproveAdjModal(<?= $adj['id'] ?>, '<?= htmlspecialchars(addslashes($adj['product_name'])) ?>', <?= (int)$adj['current_stock'] ?>, <?= (int)$adj['quantity_change'] ?>)" style="background:#16a34a!important; color:#fff!important; border:none!important; font-size:11.5px; padding:5px 8px; border-radius:4px; cursor:pointer; font-weight:700; width:100%; display:inline-flex; align-items:center; justify-content:center; gap:4px;">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button type="button" onclick="openRejectAdjModal(<?= $adj['id'] ?>, '<?= htmlspecialchars(addslashes($adj['product_name'])) ?>')" style="background:#dc2626!important; color:#fff!important; border:none!important; font-size:11.5px; padding:5px 8px; border-radius:4px; cursor:pointer; font-weight:700; width:100%; display:inline-flex; align-items:center; justify-content:center; gap:4px;">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>
                            <?php else: ?>
                                <span style="font-size:12px; color:#94a3b8; font-weight:700;"><i class="fas fa-check-double" style="margin-right:2px;"></i> Processed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>


<!-- Removed tab contents for deliveries and history -->

<!-- â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Modals
     â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->

<!-- STOCK REQUEST DETAILS MODAL -->
<div class="modal-overlay" id="reqDetailsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div class="modal-box" style="max-width:650px; width:95%; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.3);">
        <div class="modal-head" style="padding:16px 20px; background:#002F70; color:#fff; display:flex; align-items:center; justify-content:space-between;">
            <div class="modal-title" style="font-size:1.1rem; font-weight:700; color:#fff !important;"><i class="fas fa-file-alt"></i> Stock Request Details (<span id="modalReqIdText"></span>)</div>
        </div>
        <div class="modal-body" style="padding:20px; max-height:80vh; overflow-y:auto;">
            <!-- Details Grid -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px; font-size:13px;">
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Request ID</div>
                    <div id="detReqId" style="font-weight:700; color:#0f172a; margin-top:2px; font-size:14px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Request Date</div>
                    <div id="detReqDate" style="font-weight:700; color:#0f172a; margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0; grid-column:span 2;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Product Name</div>
                    <div id="detProdName" style="font-weight:700; color:#0f172a; margin-top:2px; font-size:14px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">SKU</div>
                    <div id="detSKU" style="font-weight:700; color:#0f172a; margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Category</div>
                    <div id="detCategory" style="font-weight:700; color:#0f172a; margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Current Stock</div>
                    <div id="detCurrentStock" style="font-weight:700; color:#002F70; margin-top:2px; font-size:14px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Reorder Level</div>
                    <div id="detReorderLevel" style="font-weight:700; color:#475569; margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Requested Quantity</div>
                    <div id="detRequestedQty" style="font-weight:700; color:#0f172a; margin-top:2px; font-size:14px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Requested By</div>
                    <div id="detRequestedBy" style="font-weight:700; color:#0f172a; margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0; grid-column:span 2;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Reason (Remarks)</div>
                    <div id="detReason" style="color:#334155; margin-top:2px; font-style:italic;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Status</div>
                    <div id="detStatus" style="margin-top:4px;"></div>
                </div>
                <div style="background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="color:#64748b; font-weight:700; font-size:10px; text-transform:uppercase;">Manager Remarks</div>
                    <div id="detManagerRemarks" style="font-weight:600; color:#0f172a; margin-top:2px;">—</div>
                </div>
            </div>

            <!-- Approve/Reject Section (Shown only if Pending) -->
            <div id="modalActionSection" style="display:none; border-top:1px dashed #cbd5e1; padding-top:16px; margin-top:16px;">
                <h4 style="font-size:12px; font-weight:700; color:#002F70; text-transform:uppercase; margin-bottom:12px;"><i class="fas fa-gavel"></i> Process Request</h4>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <!-- Approve form -->
                    <form method="post" action="manager_inventory_merchandise.php" style="background:#f0fdf4; border:1px solid #bbf7d0; padding:12px; border-radius:8px;">
                        <input type="hidden" name="action" value="approve_request">
                        <input type="hidden" name="request_id" id="modalApproveId">
                        <div style="font-weight:700; color:#16a34a; font-size:12px; margin-bottom:8px;"><i class="fas fa-check-circle"></i> Approve Option</div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:11px; font-weight:600; display:block; margin-bottom:3px; color:#475569;">Approved Quantity</label>
                            <input type="number" name="approved_quantity" id="modalApproveQty" min="1" required style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px; color:#0f172a; background:#fff;">
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:11px; font-weight:600; display:block; margin-bottom:3px; color:#475569;">Add Remarks</label>
                            <input type="text" name="manager_notes" placeholder="e.g. Approved for next supplier order." style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; color:#0f172a; background:#fff;">
                        </div>
                        <button type="submit" class="txn-btn success sm" style="width:100%; margin-top:4px; background:#28a745 !important; color:#fff !important; border-color:#28a745 !important; padding:6px 12px; border-radius:4px; font-weight:700; cursor:pointer;"><i class="fas fa-check"></i> Approve Request</button>
                    </form>

                    <!-- Reject form -->
                    <form method="post" action="manager_inventory_merchandise.php" style="background:#fef2f2; border:1px solid #fecaca; padding:12px; border-radius:8px;">
                        <input type="hidden" name="action" value="reject_request">
                        <input type="hidden" name="request_id" id="modalRejectId">
                        <div style="font-weight:700; color:#dc2626; font-size:12px; margin-bottom:8px;"><i class="fas fa-times-circle"></i> Reject Option</div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label style="font-size:11px; font-weight:600; display:block; margin-bottom:3px; color:#475569;">Rejection Reason</label>
                            <select name="rejection_reason_select" onchange="updateRejectionNotes(this)" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; margin-bottom:4px; color:#0f172a; background:#fff;">
                                <option value="Sufficient stock available">Sufficient stock available</option>
                                <option value="Duplicate request">Duplicate request</option>
                                <option value="Invalid quantity">Invalid quantity</option>
                                <option value="custom">Custom Reason...</option>
                            </select>
                            <input type="text" name="manager_notes" id="modalRejectNotes" value="Sufficient stock available" required style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; color:#0f172a; background:#fff;">
                        </div>
                        <button type="submit" class="txn-btn danger sm" style="width:100%; margin-top:4px; background:#dc3545 !important; color:#fff !important; border-color:#dc3545 !important; padding:6px 12px; border-radius:4px; font-weight:700; cursor:pointer;"><i class="fas fa-times"></i> Reject Request</button>
                    </form>
                </div>
            </div>
            
            <div class="modal-actions" style="margin-top:16px; display:flex; justify-content:flex-end;">
                <button type="button" onclick="closeReqDetailsModal()" class="ato-btn ato-btn-back">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- VIEW DETAILS MODAL -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal-box" style="max-width:850px; width:95%;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-info-circle"></i> Merchandise Product Details</div>
        </div>
        <div id="detailsContent" style="padding:10px 0;">
            <!-- Loaded via AJAX -->
        </div>
        <div class="modal-footer" id="detailsModalFooter" style="margin-top:16px;border-top:1px solid #e9ecef;padding-top:14px;display:flex;justify-content:flex-end;gap:10px;">
            <button class="ato-btn ato-btn-back" onclick="closeDetailsModal()">Close</button>
        </div>
    </div>
</div>

<!-- EDIT PRODUCT MODAL -->
<div class="modal-overlay" id="editProductModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;">
    <div class="modal-box" style="max-width:620px; width:95%; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.25);">
        <div class="modal-head" style="background:#002F70; padding:16px 20px; color:#fff; display:flex; align-items:center; justify-content:space-between;">
            <div class="modal-title" style="font-size:16px; font-weight:700; color:#fff !important; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-edit"></i> Edit Product Information
            </div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php" style="padding:20px;">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="product_id" id="editProdId">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Product Name <span style="color:red;">*</span></label>
                    <input type="text" name="product_name" id="editProdName" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#0f172a;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Category</label>
                    <input type="text" id="editProdCategory" readonly style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px; background:#f8fafc; color:#64748b;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Unit of Measure <span style="color:red;">*</span></label>
                    <input type="text" name="unit" id="editProdUnit" required placeholder="e.g. pcs, Liter, Bottle" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#0f172a;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Reorder Level <span style="color:red;">*</span></label>
                    <input type="number" name="reorder_level" id="editProdReorder" min="0" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#ea580c;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Critical Level <span style="color:red;">*</span></label>
                    <input type="number" name="critical_level" id="editProdCritical" min="0" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#dc2626;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Max Capacity <span style="color:red;">*</span></label>
                    <input type="number" name="capacity" id="editProdCapacity" min="1" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#002F70;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Selling Price (₱) <span style="color:red;">*</span></label>
                    <input type="number" step="0.01" name="price" id="editProdPrice" min="0" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:700; color:#16a34a;">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Unit Cost (₱)</label>
                    <input type="number" step="0.01" name="cost" id="editProdCost" min="0" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#475569;">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:16px;">
                <button type="button" onclick="closeEditProductModal()" style="padding:8px 20px; border:1.5px solid #00264D !important; background:#ffffff !important; color:#00264D !important; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer;">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#002F70 !important; color:#fff !important; padding:8px 20px; border:none; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- REQUEST INVENTORY ADJUSTMENT MODAL -->
<div class="modal-overlay" id="adjustmentModal">
    <div class="modal-box" style="max-width:500px !important; width:92% !important;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-edit" style="color:#28a745;"></i> Request Inventory Adjustment</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="request_adjustment">
            <input type="hidden" name="product_id" id="adjProductId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;">PRODUCT</div>
                    <div id="adjProductName" style="font-size:14px;font-weight:700;color:#002F70;margin-top:2px;">—</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;">CURRENT STOCK</div>
                    <div style="font-size:14px;font-weight:700;color:#002F70;margin-top:2px;"><span id="adjCurrentStock">0</span> <span id="adjUnit" style="font-weight:600;font-size:12px;color:#64748b;">pcs</span></div>
                </div>
            </div>
            <div class="form-group">
                <label>Physical Count (Actual Stock) <span style="color:red;">*</span></label>
                <input type="number" name="physical_count" id="adjPhysicalCount" min="0" required oninput="calculateAdjVariance()" style="font-size:16px;font-weight:700;color:#002F70;" placeholder="Enter actual count...">
            </div>
            
            <div id="adjVarianceBox" style="display:none;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;font-size:14px;font-weight:700;text-align:center;">
                Variance: <span id="adjVarianceValue">0</span> <span id="adjVarianceType" style="font-size:12px;font-weight:600;">(No Variance)</span>
            </div>

            <div class="form-group">
                <label>Reason / Manager Notes <span style="color:#64748b; font-weight:normal; font-size:11px;">(Optional)</span></label>
                <textarea name="manager_notes" rows="3" placeholder="Provide reason for variance adjustment (optional)..."></textarea>
            </div>
            <div class="modal-actions" style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" onclick="closeAdjustmentModal()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#28a745 !important;color:#fff !important;"><i class="fas fa-save"></i> Submit Adjustment</button>
            </div>
        </form>
    </div>
</div>

<!-- APPROVE MERCHANDISE ADJUSTMENT MODAL -->
<div id="approveAdjModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10001; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;">
    <div style="background:#fff; border-radius:12px; width:95%; max-width:440px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,0.25);">
        <div style="background:linear-gradient(135deg,#16a34a,#15803d); padding:16px 20px; display:flex; align-items:center; justify-content:space-between;">
            <div style="font-size:15px; font-weight:700; color:#fff; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-check-circle"></i> Approve Inventory Adjustment
            </div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php" style="padding:20px;">
            <input type="hidden" name="action" value="approve_merchandise_adjustment">
            <input type="hidden" name="adjustment_id" id="approveAdjId">
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Product</div>
                <div id="approveAdjProductName" style="font-size:14px; font-weight:700; color:#002F70;">—</div>
                <div id="approveAdjDetail" style="font-size:12px; margin-top:6px; color:#334155;"></div>
            </div>
            <div style="font-size:13px; color:#334155; margin-bottom:16px; line-height:1.6;">
                Are you sure you want to <strong style="color:#16a34a;">approve</strong> this inventory adjustment?
                The stock level will be updated immediately after confirmation.
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="closeApproveAdjModal()" style="padding:8px 20px; border:1.5px solid #00264D !important; background:#ffffff !important; background-color:#ffffff !important; color:#00264D !important; -webkit-text-fill-color:#00264D !important; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer; opacity:1 !important; visibility:visible !important;">
                    Cancel
                </button>
                <button type="submit" style="padding:8px 20px; background:#002F70 !important; color:#fff !important; border:none; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <i class="fas fa-check"></i> Confirm Approve
                </button>
            </div>
        </form>
    </div>
</div>

<!-- REJECT MERCHANDISE ADJUSTMENT MODAL -->
<div class="modal-overlay" id="rejectAdjModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div class="modal-box" style="max-width:480px; width:95%; background:#fff; border-radius:8px; overflow:hidden;">
        <div class="modal-head" style="padding:16px 20px; background:#dc3545; color:#fff; display:flex; align-items:center; justify-content:space-between;">
            <div class="modal-title" style="font-size:1.1rem; font-weight:700; color:#fff!important;"><i class="fas fa-times-circle"></i> Reject Inventory Adjustment</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php" style="padding:20px;">
            <input type="hidden" name="action" value="reject_merchandise_adjustment">
            <input type="hidden" name="adjustment_id" id="rejectAdjId">
            <div style="background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #cbd5e1; margin-bottom:14px;">
                <div style="font-size:11px; color:#64748b; font-weight:700;">PRODUCT</div>
                <div id="rejectAdjProductName" style="font-size:14px; font-weight:700; color:#002F70; margin-top:2px;">—</div>
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Rejection Reason <span style="color:red;">*</span></label>
                <textarea name="rejection_reason" rows="3" required placeholder="Describe reason for rejecting..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeRejectAdjModal()" style="padding:8px 20px; border:1.5px solid #00264D !important; background:#ffffff !important; background-color:#ffffff !important; color:#00264D !important; -webkit-text-fill-color:#00264D !important; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer; opacity:1 !important; visibility:visible !important;">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#dc3545 !important; color:#fff !important;"><i class="fas fa-times"></i> Reject Request</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveAdjModal(id, name, currentStock, qtyChange) {
    document.getElementById('approveAdjId').value = id;
    document.getElementById('approveAdjProductName').innerText = name || '—';
    var newStock = Math.max(0, currentStock + qtyChange);
    var sign = qtyChange >= 0 ? '+' : '';
    document.getElementById('approveAdjDetail').innerHTML =
        '<span style="color:#64748b;">Qty Change:</span> <strong style="color:' + (qtyChange >= 0 ? '#16a34a' : '#dc2626') + ';">' + sign + qtyChange + '</strong>' +
        ' &nbsp;|&nbsp; <span style="color:#64748b;">New Stock:</span> <strong style="color:#002F70;">' + newStock + '</strong>';
    document.getElementById('approveAdjModal').style.display = 'flex';
}
function closeApproveAdjModal() {
    document.getElementById('approveAdjModal').style.display = 'none';
}
function openRejectAdjModal(id, name) {
    document.getElementById('rejectAdjId').value = id;
    document.getElementById('rejectAdjProductName').innerText = name || '—';
    document.getElementById('rejectAdjModal').style.display = 'flex';
}
function closeRejectAdjModal() {
    document.getElementById('rejectAdjModal').style.display = 'none';
}
</script>

<!-- APPROVE REQUEST MODAL -->
<div class="modal-overlay" id="approveRequestModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-check-circle" style="color:#28a745;"></i> Approve Stock Request</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="approve_request">
            <input type="hidden" name="request_id" id="approveReqId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;">
                <div style="font-size:11px;color:#64748b;font-weight:700;">PRODUCT</div>
                <div id="approveReqProduct" style="font-size:14px;font-weight:700;color:#002F70;margin-top:2px;">—</div>
            </div>
            <div class="form-group">
                <label>Approved Quantity <span style="color:red;">*</span></label>
                <input type="number" name="approved_quantity" id="approveReqQty" min="1" required style="font-size:16px;font-weight:700;color:#002F70;">
            </div>
            <div class="form-group">
                <label>Add Remarks / Manager Notes</label>
                <textarea name="manager_notes" rows="3" placeholder="Optional notes..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" onclick="closeApproveRequest()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#28a745 !important;color:#fff !important;"><i class="fas fa-check"></i> Confirm Approve</button>
            </div>
        </form>
    </div>
</div>

<!-- REJECT REQUEST MODAL -->
<div class="modal-overlay" id="rejectRequestModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-times-circle" style="color:#dc3545;"></i> Reject Stock Request</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="reject_request">
            <input type="hidden" name="request_id" id="rejectReqId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;">
                <div style="font-size:11px;color:#64748b;font-weight:700;">PRODUCT</div>
                <div id="rejectReqProduct" style="font-size:14px;font-weight:700;color:#002F70;margin-top:2px;">—</div>
            </div>
            <div class="form-group">
                <label>Rejection Reason / Manager Remarks <span style="color:red;">*</span></label>
                <textarea name="manager_notes" rows="4" required placeholder="Describe the reason for rejection..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" onclick="closeRejectRequest()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#dc3545 !important;color:#fff !important;"><i class="fas fa-times"></i> Reject Request</button>
            </div>
        </form>
    </div>
</div>

<!-- VALIDATE DELIVERY MODAL -->
<div class="modal-overlay" id="validateDeliveryModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-check-double" style="color:#28a745;"></i> Verify Merchandise Delivery</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="validate_delivery">
            <input type="hidden" name="po_id" id="validatePoId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;font-size:12px;">
                <strong>PO Number:</strong> <span id="validatePoNum">—</span><br>
                <strong>Product:</strong> <span id="validatePoProduct">—</span>
            </div>
            <div class="form-group">
                <label>Actual Qty Received <span style="color:red;">*</span></label>
                <input type="number" name="actual_qty" id="validateActualQty" min="0" required placeholder="Enter quantity received...">
            </div>
            <div class="form-group">
                <label>Delivery Status Flag <span style="color:red;">*</span></label>
                <select name="delivery_flag">
                    <option value="OK">OK — Matches PO</option>
                    <option value="Short">Short — Less than ordered</option>
                    <option value="Excess">Excess — More than ordered</option>
                    <option value="Damaged">Damaged — Items damaged</option>
                    <option value="Mixed">Mixed — Multiple issues</option>
                </select>
            </div>
            <div class="form-group">
                <label>Remarks / Notes</label>
                <textarea name="delivery_notes" rows="3" placeholder="Optional notes..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" onclick="closeValidateDelivery()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#28a745 !important;color:#fff !important;"><i class="fas fa-check"></i> Verify Delivery</button>
            </div>
        </form>
    </div>
</div>

<!-- FLAG ISSUE MODAL -->
<div class="modal-overlay" id="flagIssueModal">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-exclamation-triangle" style="color:#dc3545;"></i> Flag Delivery Issue</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="flag_delivery_issue">
            <input type="hidden" name="po_id" id="flagPoId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;font-size:12px;">
                <strong>PO Number:</strong> <span id="flagPoNum">—</span>
            </div>
            <div class="form-group">
                <label>Issue Type <span style="color:red;">*</span></label>
                <select name="delivery_flag">
                    <option value="Short">Short — Less than ordered</option>
                    <option value="Damaged">Damaged — Items damaged</option>
                    <option value="Excess">Excess — More than ordered</option>
                    <option value="Mixed">Mixed — Multiple issues</option>
                </select>
            </div>
            <div class="form-group">
                <label>Issue Details <span style="color:red;">*</span></label>
                <textarea name="delivery_notes" rows="4" required placeholder="Describe the issue in detail..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" onclick="closeFlagIssue()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#dc3545 !important;color:#fff !important;"><i class="fas fa-exclamation-triangle"></i> Flag Issue</button>
            </div>
        </form>
    </div>
<!-- CREATE STOCK REQUEST MODAL -->
<div class="modal-overlay" id="createStockRequestModal">
    <div class="modal-box" style="max-width:500px; width:95%;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-file-alt" style="color:#002F70;"></i> Create Stock replenishment Request</div>
        </div>
        <form method="post" action="manager_inventory_merchandise.php">
            <input type="hidden" name="action" value="create_stock_request">
            <input type="hidden" name="product_id" id="srProductId">
            <div style="background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #cbd5e1;margin-bottom:14px;display:grid;grid-template-columns:1fr;gap:8px;">
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Product Name</div>
                    <div id="srProductName" style="font-size:15px;font-weight:700;color:#002F70;margin-top:2px;">—</div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:6px;">
                    <div>
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Current Stock</div>
                        <div id="srCurrentStock" style="font-size:14px;font-weight:700;color:#dc3545;">0</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Reorder Level</div>
                        <div id="srReorderLevel" style="font-size:14px;font-weight:700;color:#475569;">0</div>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Requested Quantity <span style="color:red;">*</span></label>
                <input type="number" name="requested_quantity" id="srRequestedQty" min="1" required style="font-size:16px;font-weight:700;color:#002F70;" placeholder="Enter qty to request...">
            </div>
            <div class="form-group">
                <label>Remarks / Replenishment Reason</label>
                <textarea name="remarks" rows="3" placeholder="Optional notes (e.g. low stock, supplier order, etc.)..."></textarea>
            </div>
            <div class="modal-actions" style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" onclick="closeCreateStockRequest()" class="ato-btn ato-btn-back">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#002F70 !important;color:#fff !important;"><i class="fas fa-paper-plane"></i> Submit Request</button>
            </div>
        </form>
    </div>
<!-- VIEW MOVEMENT DETAILS MODAL -->
<div class="modal-overlay" id="viewMovementModal">
    <div class="modal-box" style="max-width:500px; width:95%;">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-history" style="color:#002F70;"></i> Stock Movement Details</div>
        </div>
        <div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #cbd5e1;margin-bottom:16px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Movement ID</div>
                    <div id="vmId" style="font-size:14px;font-weight:700;color:#0f172a;margin-top:2px;">—</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Date / Time</div>
                    <div id="vmDate" style="font-size:14px;font-weight:700;color:#0f172a;margin-top:2px;">—</div>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Product Name</div>
                <div id="vmProductName" style="font-size:15px;font-weight:700;color:#002F70;margin-top:2px;">—</div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Movement Type</div>
                    <div id="vmType" style="margin-top:2px;">—</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Reference No.</div>
                    <div id="vmRef" style="font-size:14px;font-weight:700;color:#0f172a;margin-top:2px;">—</div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px;border-top:1px solid #cbd5e1;padding-top:12px;">
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Previous Stock</div>
                    <div id="vmPrev" style="font-size:14px;font-weight:700;color:#475569;margin-top:2px;">0</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Qty Change</div>
                    <div id="vmChange" style="font-size:14px;font-weight:700;margin-top:2px;">0</div>
                </div>
                <div>
                    <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">New Stock</div>
                    <div id="vmNew" style="font-size:14px;font-weight:700;color:#002F70;margin-top:2px;">0</div>
                </div>
            </div>
            <div style="border-top:1px solid #cbd5e1;padding-top:12px;margin-bottom:12px;">
                <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Performed By</div>
                <div id="vmUser" style="font-size:13px;font-weight:600;color:#0f172a;margin-top:2px;">—</div>
            </div>
            <div style="border-top:1px solid #cbd5e1;padding-top:12px;">
                <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;">Notes / Remarks</div>
                <div id="vmNotes" style="font-size:13px;color:#475569;margin-top:2px;white-space:pre-wrap;">—</div>
            </div>
        </div>
        <div class="modal-actions" style="margin-top:16px;display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" onclick="closeViewMovement()" class="ato-btn ato-btn-back">Close</button>
            <button type="button" onclick="printMovLogFromModal()" class="ato-btn" style="background:#002F70 !important;color:#fff !important;"><i class="fas fa-print"></i> Print Slip</button>
        </div>
    </div>
</div>

<script>
// Escape HTML helper
function esc(str) {
    if (!str) return '';
    return str.toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Date formatter helper
function fmtDate(dStr) {
    if (!dStr) return '—';
    try {
        var d = new Date(dStr);
        if (isNaN(d.getTime())) return dStr;
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
               d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    } catch(e) { return dStr; }
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Modal Actions
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openApproveRequest(id, product, qty) {
    document.getElementById('approveReqId').value = id;
    document.getElementById('approveReqProduct').textContent = product;
    document.getElementById('approveReqQty').value = qty;
    document.getElementById('approveRequestModal').classList.add('open');
}
function closeApproveRequest() {
    document.getElementById('approveRequestModal').classList.remove('open');
}

function openRejectRequest(id, product) {
    document.getElementById('rejectReqId').value = id;
    document.getElementById('rejectReqProduct').textContent = product;
    document.getElementById('rejectRequestModal').classList.add('open');
}
function closeRejectRequest() {
    document.getElementById('rejectRequestModal').classList.remove('open');
}

function openValidateDelivery(id, poNum, product, qty) {
    document.getElementById('validatePoId').value = id;
    document.getElementById('validatePoNum').textContent = poNum;
    document.getElementById('validatePoProduct').textContent = product;
    document.getElementById('validateActualQty').value = qty;
    document.getElementById('validateDeliveryModal').classList.add('open');
}
function closeValidateDelivery() {
    document.getElementById('validateDeliveryModal').classList.remove('open');
}

function openFlagIssue(id, poNum) {
    document.getElementById('flagPoId').value = id;
    document.getElementById('flagPoNum').textContent = poNum;
    document.getElementById('flagIssueModal').classList.add('open');
}
function closeFlagIssue() {
    document.getElementById('flagIssueModal').classList.remove('open');
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// View Details Modal (AJAX)
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function viewDetails(productId, focusTab) {
    focusTab = focusTab || 'info';
    var content = document.getElementById('detailsContent');
    content.innerHTML = '<div style="text-align:center;padding:48px;color:#6c757d;"><i class="fas fa-spinner fa-spin" style="font-size:2rem;margin-bottom:12px;"></i><br>Loading details...</div>';
    document.getElementById('detailsModal').classList.add('open');
    
    fetch('manager_inventory_merchandise.php?ajax=1&action=get_product_details&product_id=' + productId)
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) {
            content.innerHTML = '<div class="alert alert-danger" style="margin:20px;">' + esc(res.message) + '</div>';
            return;
        }
        var p = res.product;
        
        // Format last updated timestamp
        var timestamp = '—';
        if (p.last_updated) {
            try {
                timestamp = fmtDate(p.last_updated);
            } catch(e) {}
        }
        
        // Format last movement
        var lastMovText = '—';
        if (res.movements && res.movements.length > 0) {
            var lm = res.movements[0];
            var lmSign = lm.quantity > 0 ? '+' : '';
            lastMovText = lmSign + lm.quantity + ' ' + esc(lm.movement_type.toUpperCase()) + ' (' + fmtDate(lm.created_at) + ')';
        }

        // Format variance & check variance flag
        var variance = p.variance;
        var vText = '—';
        var vColor = '#64748b';
        var hasVarInModal = false;
        if (variance !== null) {
            var vVal = parseFloat(variance);
            if (vVal > 0) {
                vText = '+' + vVal;
                vColor = '#28a745';
                hasVarInModal = true;
            } else if (vVal < 0) {
                vText = vVal;
                vColor = '#dc3545';
                hasVarInModal = true;
            } else {
                vText = '0 (No Variance)';
                vColor = '#64748b';
            }
        }

        var mHtml = '';
        if (res.movements.length === 0) {
            mHtml = '<tr><td colspan="5" style="text-align:center;padding:12px;color:#999;">No movement logs.</td></tr>';
        } else {
            res.movements.forEach(function(m) {
                var qtyPrefix = m.quantity > 0 ? '+' : '';
                mHtml += '<tr>' +
                    '<td>' + fmtDate(m.created_at) + '</td>' +
                    '<td><span style="font-weight:600;">' + esc(m.movement_type.toUpperCase()) + '</span></td>' +
                    '<td style="font-weight:700;color:' + (m.quantity > 0 ? '#28a745' : '#dc3545') + ';">' + qtyPrefix + m.quantity + '</td>' +
                    '<td>' + esc(m.user_name || 'System') + '</td>' +
                    '<td>' + esc(m.notes || '—') + '</td>' +
                    '</tr>';
            });
        }

        var dHtml = '';
        if (res.deliveries.length === 0) {
            dHtml = '<tr><td colspan="6" style="text-align:center;padding:12px;color:#999;">No verified deliveries.</td></tr>';
        } else {
            res.deliveries.forEach(function(d) {
                dHtml += '<tr>' +
                    '<td>' + fmtDate(d.encoded_at) + '</td>' +
                    '<td><code style="font-weight:700;">' + esc(d.po_number || '—') + '</code></td>' +
                    '<td>' + d.qty_ordered + '</td>' +
                    '<td style="font-weight:700;">' + d.qty_received + '</td>' +
                    '<td><span class="status-badge ' + (d.condition_flag === 'Good' ? 'badge-approved' : 'badge-rejected') + '">' + esc(d.condition_flag) + '</span></td>' +
                    '<td>' + esc(d.remarks || '—') + '</td>' +
                    '</tr>';
            });
        }

        content.innerHTML = 
            '<div class="modal-tabs-container" style="display:flex;border-bottom:2px solid #e2e8f0;margin-bottom:20px;gap:8px;">' +
                '<button class="modal-tab-btn active" id="btnModalInfo" onclick="switchModalTab(\'info\')"><i class="fas fa-info-circle"></i> Info</button>' +
                '<button class="modal-tab-btn" id="btnModalMovement" onclick="switchModalTab(\'movement\')"><i class="fas fa-history"></i> Stock Movement</button>' +
                '<button class="modal-tab-btn" id="btnModalDeliveries" onclick="switchModalTab(\'deliveries\')"><i class="fas fa-truck"></i> Delivery History</button>' +
            '</div>' +
            
            '<div id="tabContentInfo" class="modal-tab-content">' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">' +
                    '<div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0;">' +
                        '<h4 style="margin:0 0 12px;color:#002F70;text-transform:uppercase;font-size:12px;letter-spacing:.5px;"><i class="fas fa-box"></i> Product Information</h4>' +
                        '<table style="width:100%;font-size:13px;border-collapse:collapse;">' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;width:120px;border-bottom:1px solid #f1f5f9;">Product Name:</td><td style="font-weight:700;color:#0f172a;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + esc(p.name) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">SKU:</td><td style="border-bottom:1px solid #f1f5f9;padding:8px 0;"><code>' + esc(p.sku || '—') + '</code></td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Category:</td><td style="border-bottom:1px solid #f1f5f9;padding:8px 0;">' + esc(p.category_name) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Unit of Measure:</td><td style="border-bottom:1px solid #f1f5f9;padding:8px 0;">' + esc(p.unit) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Supplier:</td><td style="font-weight:600;color:#475569;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + esc(p.supplier || '—') + '</td></tr>' +
                        '</table>' +
                    '</div>' +
                    '<div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0;">' +
                        '<h4 style="margin:0 0 12px;color:#002F70;text-transform:uppercase;font-size:12px;letter-spacing:.5px;"><i class="fas fa-warehouse"></i> Inventory Information</h4>' +
                        '<table style="width:100%;font-size:13px;border-collapse:collapse;">' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;width:120px;border-bottom:1px solid #f1f5f9;">Current Stock:</td><td style="font-weight:700;font-size:14px;color:#002F70;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + p.stock_level + ' ' + esc(p.unit) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Capacity:</td><td style="font-weight:700;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + p.capacity + ' ' + esc(p.unit) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Reorder Level:</td><td style="font-weight:700;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + p.reorder_level + ' ' + esc(p.unit) + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Physical Count:</td><td style="font-weight:700;color:#0f172a;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + (p.physical_count !== null ? p.physical_count + ' ' + esc(p.unit) : '—') + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Variance:</td><td style="font-weight:700;color:' + vColor + ';border-bottom:1px solid #f1f5f9;padding:8px 0;">' + vText + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Last Movement:</td><td style="font-size:12px;color:#475569;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + lastMovText + '</td></tr>' +
                            '<tr><td style="padding:8px 0;color:#64748b;font-weight:600;border-bottom:1px solid #f1f5f9;">Last Updated:</td><td style="font-size:12px;color:#475569;border-bottom:1px solid #f1f5f9;padding:8px 0;">' + timestamp + '</td></tr>' +
                        '</table>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            
            '<div id="tabContentMovement" class="modal-tab-content" style="display:none;">' +
                '<h4 style="margin:0 0 12px;color:#002F70;text-transform:uppercase;font-size:12px;letter-spacing:.5px;"><i class="fas fa-history"></i> Stock Movement History</h4>' +
                '<div style="max-height:300px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;">' +
                    '<table class="po-table" style="width:100%;">' +
                        '<thead><tr><th>Date</th><th>Type</th><th>Qty</th><th>Performed By</th><th>Notes</th></tr></thead>' +
                        '<tbody>' + mHtml + '</tbody>' +
                    '</table>' +
                '</div>' +
            '</div>' +
            
            '<div id="tabContentDeliveries" class="modal-tab-content" style="display:none;">' +
                '<h4 style="margin:0 0 12px;color:#002F70;text-transform:uppercase;font-size:12px;letter-spacing:.5px;"><i class="fas fa-truck"></i> Recent Deliveries</h4>' +
                '<div style="max-height:300px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;">' +
                    '<table class="po-table" style="width:100%;">' +
                        '<thead><tr><th>Date</th><th>PO #</th><th>Ordered</th><th>Received</th><th>Condition</th><th>Remarks</th></tr></thead>' +
                        '<tbody>' + dHtml + '</tbody>' +
                    '</table>' +
                '</div>' +
            '</div>';
            
        switchModalTab(focusTab);

        // Update modal footer action button depending on variance
        var footerEl = document.getElementById('detailsModalFooter');
        if (footerEl) {
            var btnHtml = '<button type="button" class="ato-btn ato-btn-back" onclick="closeDetailsModal()">Close</button>';
            var safeName = esc(p.name).replace(/'/g, "\\'");
            var safeUnit = esc(p.unit).replace(/'/g, "\\'");
            if (hasVarInModal) {
                btnHtml += ' <button type="button" class="ato-btn" style="background:#28a745 !important;color:#fff !important;display:inline-flex;align-items:center;gap:6px;" onclick="closeDetailsModal(); openAdjustmentModal(' + p.id + ', \'' + safeName + '\', ' + p.stock_level + ', \'' + safeUnit + '\')"><i class="fas fa-balance-scale"></i> Create Stock Adjustment</button>';
            } else {
                btnHtml += ' <button type="button" disabled title="No variance detected" class="ato-btn" style="background:#cbd5e1 !important;color:#94a3b8 !important;cursor:not-allowed;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-balance-scale"></i> Create Stock Adjustment</button>';
            }
            footerEl.innerHTML = btnHtml;
        }
    })
    .catch(function(e) {
        content.innerHTML = '<div style="color:#dc3545;padding:20px;text-align:center;">Failed to load data. Network issue.</div>';
    });
}
function closeDetailsModal() {
    document.getElementById('detailsModal').classList.remove('open');
}

window.switchModalTab = function(tabName) {
    document.querySelectorAll('.modal-tab-btn').forEach(function(b) {
        b.classList.remove('active');
    });
    document.querySelectorAll('.modal-tab-content').forEach(function(c) {
        c.style.display = 'none';
    });
    
    if (tabName === 'info') {
        var btn = document.getElementById('btnModalInfo');
        if (btn) btn.classList.add('active');
        var cnt = document.getElementById('tabContentInfo');
        if (cnt) cnt.style.display = 'block';
    } else if (tabName === 'movement') {
        var btn = document.getElementById('btnModalMovement');
        if (btn) btn.classList.add('active');
        var cnt = document.getElementById('tabContentMovement');
        if (cnt) cnt.style.display = 'block';
    } else if (tabName === 'deliveries') {
        var btn = document.getElementById('btnModalDeliveries');
        if (btn) btn.classList.add('active');
        var cnt = document.getElementById('tabContentDeliveries');
        if (cnt) cnt.style.display = 'block';
    }
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Request Inventory Adjustment Modal
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openAdjustmentModal(id, name, currentStock, unit) {
    document.getElementById('adjProductId').value = id;
    document.getElementById('adjProductName').textContent = name;
    document.getElementById('adjCurrentStock').textContent = currentStock;
    document.getElementById('adjUnit').textContent = unit;
    document.getElementById('adjPhysicalCount').value = '';
    document.getElementById('adjVarianceBox').style.display = 'none';
    document.getElementById('adjustmentModal').classList.add('open');
}

function openStockAdjustmentModal(id, name, currentStock, unit) {
    openAdjustmentModal(id, name, currentStock, unit);
}

function filterMovTable() {
    var srchEl = document.getElementById('movSearchInput') || document.getElementById('movSearch');
    var typeEl = document.getElementById('movTypeFilter');
    
    var search = (srchEl ? srchEl.value : '').toLowerCase().trim();
    var type = (typeEl ? typeEl.value : '').toLowerCase().trim();
    
    var rows = document.querySelectorAll('#mgrMovBody .mov-row, #movTableBody .mov-row');
    rows.forEach(function(row) {
        var rowSearch = (row.getAttribute('data-search') || row.dataset.product || '').toLowerCase();
        var rowType = (row.getAttribute('data-type') || row.dataset.type || '').toLowerCase();
        var rowRawType = (row.getAttribute('data-raw-type') || '').toLowerCase();
        
        var matchesSearch = !search || rowSearch.indexOf(search) !== -1;
        var matchesType = false;
        
        if (!type) {
            matchesType = true;
        } else if (type === 'stock in' || type === 'stock_in') {
            matchesType = (rowType === 'stock in' || rowType === 'stock_in' || rowRawType === 'delivery' || rowRawType === 'stock_in' || rowRawType === 'stock-in');
        } else if (type === 'stock out' || type === 'stock_out') {
            matchesType = (rowType === 'stock out' || rowType === 'stock_out' || rowRawType === 'sale' || rowRawType === 'stock_out' || rowRawType === 'stock-out' || rowRawType === 'release');
        } else if (type === 'adjustment') {
            matchesType = (rowType === 'adjustment' || rowRawType === 'adjustment');
        } else if (type === 'transfer') {
            matchesType = (rowType === 'transfer' || rowRawType.indexOf('transfer') !== -1);
        } else if (type === 'damaged') {
            matchesType = (rowType === 'damaged' || rowRawType.indexOf('damage') !== -1 || rowRawType.indexOf('defective') !== -1);
        } else if (type === 'expired') {
            matchesType = (rowType === 'expired' || rowRawType.indexOf('expire') !== -1);
        } else {
            matchesType = (rowType === type || rowRawType === type);
        }

        if (matchesSearch && matchesType) {
            row.style.display = '';
            row.classList.remove('search-hidden');
        } else {
            row.style.display = 'none';
            row.classList.add('search-hidden');
        }
    });

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['mgrMovTable']) {
        window.tablePaginationTriggers['mgrMovTable']();
    }
}

function closeAdjustmentModal() {
    document.getElementById('adjustmentModal').classList.remove('open');
}

function calculateAdjVariance() {
    var current = parseFloat(document.getElementById('adjCurrentStock').textContent) || 0;
    var physical = parseFloat(document.getElementById('adjPhysicalCount').value);
    var box = document.getElementById('adjVarianceBox');
    
    if (isNaN(physical)) {
        box.style.display = 'none';
        return;
    }
    
    var variance = physical - current;
    var valSpan = document.getElementById('adjVarianceValue');
    var typeSpan = document.getElementById('adjVarianceType');
    
    valSpan.textContent = (variance > 0 ? '+' : '') + variance;
    
    if (variance > 0) {
        box.style.background = '#e6f4ea';
        box.style.color = '#137333';
        box.style.borderColor = '#c3e6cb';
        typeSpan.textContent = '(Surplus)';
    } else if (variance < 0) {
        box.style.background = '#fce8e6';
        box.style.color = '#c5221f';
        box.style.borderColor = '#f5c6cb';
        typeSpan.textContent = '(Deficit)';
    } else {
        box.style.background = '#e8f0fe';
        box.style.color = '#1a73e8';
        box.style.borderColor = '#d2e3fc';
        typeSpan.textContent = '(No Variance)';
    }
    
    box.style.display = 'block';
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Client-side Filters
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function filterInvTable() {
    var cat = document.getElementById('invCatFilter').value.toLowerCase();
    var srch = document.getElementById('invSearch').value.toLowerCase().trim();
    var stFlt = document.getElementById('invStockFilter').value.toLowerCase().trim();

    console.log('=== FILTER DEBUG ===');
    console.log('Category Filter:', cat || '(all)');
    console.log('Search Filter:', srch || '(none)');
    console.log('Status Filter:', stFlt || '(all)');
    console.log('');

    var visibleCount = 0;
    var statusCounts = {};

    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        var rCat = (r.dataset.cat || '').toLowerCase();
        var rName = (r.dataset.name || '').toLowerCase();
        var rSku = (r.dataset.sku || '').toLowerCase();
        var rInv = (r.dataset.invStatus || '').toLowerCase();
        var rStockStatus = (r.dataset.stockStatus || rInv).toLowerCase();

        // Count statuses for debugging
        if (!statusCounts[rInv]) statusCounts[rInv] = 0;
        statusCounts[rInv]++;

        var matchesCat = !cat || rCat === cat;

        // WARNING TIERS: low, critical, out-of-stock are all connected
        var WARNING_STATUSES = ['low', 'critical', 'out of stock', 'out'];
        var isWarning = (WARNING_STATUSES.indexOf(rInv) !== -1 || WARNING_STATUSES.indexOf(rStockStatus) !== -1);

        // Status filter matching — Low Stock, Critical Stock, Out of Stock all show the same combined stock alert view
        var matchesStock = false;
        if (!stFlt) {
            matchesStock = true;
        } else if (stFlt === 'warning' || stFlt === 'low' || stFlt === 'critical' || stFlt === 'out of stock' || stFlt === 'out') {
            // Any stock-alert filter shows ALL low + critical + out of stock items together
            matchesStock = isWarning;
        } else if (stFlt === 'variance detected') {
            matchesStock = (rInv === 'variance detected');
        } else if (stFlt === 'expired') {
            matchesStock = (rInv === 'expired' || rStockStatus === 'expired');
        } else if (stFlt === 'available') {
            matchesStock = (rInv === 'available' || rStockStatus === 'available') && rInv !== 'expired' && rStockStatus !== 'expired';
        } else {
            matchesStock = (rInv === stFlt || (rInv !== 'variance detected' && rStockStatus === stFlt));
        }

        // Search text matching (status keywords expand to all warnings)
        var matchesSrch = false;
        if (!srch) {
            matchesSrch = true;
        } else if (['low', 'low stock', 'out', 'out of stock', 'critical', 'critical stock', 'warning'].indexOf(srch) !== -1) {
            matchesSrch = isWarning;
        } else if (srch === 'expired') {
            matchesSrch = (rInv === 'expired' || rStockStatus === 'expired');
        } else if (srch === 'available') {
            matchesSrch = (rInv === 'available' || rStockStatus === 'available') && rInv !== 'expired' && rStockStatus !== 'expired';
        } else {
            matchesSrch = rName.includes(srch) || rSku.includes(srch) || rCat.includes(srch);
        }

        var ok = matchesCat && matchesSrch && matchesStock;
        if (ok) {
            r.classList.remove('search-hidden');
            r.style.display = '';
            visibleCount++;
        } else {
            r.classList.add('search-hidden');
            r.style.display = 'none';
        }
    });

    // Update category header visibility based on filtered items
    var tbody = document.getElementById('merchTableBody');
    if (tbody) {
        var rows = Array.from(tbody.querySelectorAll('tr'));
        var currentHeader = null;
        var hasVisibleItems = false;
        rows.forEach(function(r) {
            if (r.classList.contains('cat-header')) {
                if (currentHeader) {
                    if (hasVisibleItems) {
                        currentHeader.classList.remove('search-hidden');
                        currentHeader.style.display = '';
                    } else {
                        currentHeader.classList.add('search-hidden');
                        currentHeader.style.display = 'none';
                    }
                }
                currentHeader = r;
                hasVisibleItems = false;
            } else if (r.classList.contains('merch-row')) {
                if (!r.classList.contains('search-hidden') && r.style.display !== 'none') {
                    hasVisibleItems = true;
                }
            }
        });
        if (currentHeader) {
            if (hasVisibleItems) {
                currentHeader.classList.remove('search-hidden');
                currentHeader.style.display = '';
            } else {
                currentHeader.classList.add('search-hidden');
                currentHeader.style.display = 'none';
            }
        }
    }

    // Dynamic No-Match Empty State for Inventory Overview
    var noMatchRow = document.getElementById('invNoMatchRow');
    if (noMatchRow) {
        var allMerchRows = document.querySelectorAll('#merchTableBody .merch-row');
        if (visibleCount === 0 && allMerchRows.length > 0) {
            noMatchRow.style.display = '';
            var invMsgEl = document.getElementById('invNoMatchMsg');
            if (invMsgEl) {
                if (srch) {
                    invMsgEl.innerHTML = 'No products found matching "<strong>' + esc(srch) + '</strong>".';
                } else if (cat) {
                    invMsgEl.textContent = 'No products found in category "' + cat.toUpperCase() + '".';
                } else {
                    invMsgEl.textContent = 'No products match the selected criteria.';
                }
            }
        } else {
            noMatchRow.style.display = 'none';
        }
    }

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['mgrMerchTable']) {
        window.tablePaginationTriggers['mgrMerchTable']();
    }
}

function filterAlertTable() {
    var catEl = document.getElementById('alertCatFilter');
    var srchEl = document.getElementById('alertSearch');
    var typeEl = document.getElementById('alertTypeFilter');

    var cat = catEl ? catEl.value.toLowerCase().trim() : '';
    var srch = srchEl ? srchEl.value.toLowerCase().trim() : '';
    var type = typeEl ? typeEl.value.toLowerCase().trim() : '';
    
    var visibleCount = 0;
    var rows = document.querySelectorAll('#alertTableBody .alert-row');
    
    rows.forEach(function(r) {
        var rCat = (r.dataset.cat || '').toLowerCase().trim();
        var rName = (r.dataset.name || '').toLowerCase().trim();
        var rSku = (r.dataset.sku || '').toLowerCase().trim();
        var rType = (r.dataset.alertType || '').toLowerCase().trim();
        var rText = (r.textContent || r.innerText || '').toLowerCase();
        
        var matchesCat = !cat || rCat === cat;
        // Search matches SKU, Product Name, Category, Alert Type, or any text in the row
        var matchesSrch = !srch || 
                          rName.indexOf(srch) !== -1 || 
                          rSku.indexOf(srch) !== -1 || 
                          rCat.indexOf(srch) !== -1 || 
                          rType.indexOf(srch) !== -1 || 
                          rText.indexOf(srch) !== -1;
        var matchesType = !type || rType === type;
        
        var ok = matchesCat && matchesSrch && matchesType;
        if (ok) {
            r.classList.remove('search-hidden');
            r.style.display = '';
            visibleCount++;
        } else {
            r.classList.add('search-hidden');
            r.style.display = 'none';
        }
    });

    // Update category header visibility: Hide header if all products under it are filtered out
    var tbody = document.getElementById('alertTableBody');
    if (tbody) {
        var trs = Array.from(tbody.querySelectorAll('tr'));
        var currentHeader = null;
        var hasVisibleItems = false;
        
        trs.forEach(function(r) {
            if (r.classList.contains('cat-header')) {
                if (currentHeader) {
                    if (hasVisibleItems) {
                        currentHeader.classList.remove('search-hidden');
                        currentHeader.style.display = '';
                    } else {
                        currentHeader.classList.add('search-hidden');
                        currentHeader.style.display = 'none';
                    }
                }
                currentHeader = r;
                hasVisibleItems = false;
            } else if (r.classList.contains('alert-row')) {
                if (!r.classList.contains('search-hidden') && r.style.display !== 'none') {
                    hasVisibleItems = true;
                }
            }
        });
        if (currentHeader) {
            if (hasVisibleItems) {
                currentHeader.classList.remove('search-hidden');
                currentHeader.style.display = '';
            } else {
                currentHeader.classList.add('search-hidden');
                currentHeader.style.display = 'none';
            }
        }
    }

    // Dynamic No-Match Empty State for Stock Alerts
    var noMatchRow = document.getElementById('alertNoMatchRow');
    if (noMatchRow) {
        if (visibleCount === 0 && rows.length > 0) {
            noMatchRow.style.display = '';
            var msgEl = document.getElementById('alertNoMatchMsg');
            if (msgEl) {
                if (srch) {
                    msgEl.innerHTML = 'No stock alerts found matching "<strong>' + esc(srch) + '</strong>".';
                } else if (cat) {
                    msgEl.textContent = 'No stock alerts found in category "' + cat.toUpperCase() + '".';
                } else {
                    msgEl.textContent = 'No stock alerts match the selected criteria.';
                }
            }
        } else {
            noMatchRow.style.display = 'none';
        }
    }

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['mgrAlertTable']) {
        window.tablePaginationTriggers['mgrAlertTable']();
    }
}

function openCreateStockRequest(id, name, stock, reorder) {
    document.getElementById('srProductId').value = id;
    document.getElementById('srProductName').textContent = name;
    document.getElementById('srCurrentStock').textContent = stock;
    document.getElementById('srReorderLevel').textContent = reorder;
    document.getElementById('srRequestedQty').value = Math.max(1, reorder - stock);
    document.getElementById('createStockRequestModal').classList.add('open');
}

function closeCreateStockRequest() {
    document.getElementById('createStockRequestModal').classList.remove('open');
}

function printAlertRow(id, name, type, stock, reorder) {
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Stock Alert Slip</title>');
    printWindow.document.write('<style>body{font-family:Arial,sans-serif;padding:30px;color:#333;} h2{color:#002F70;border-bottom:2px solid #002F70;padding-bottom:8px;margin-bottom:20px;} table{width:100%;border-collapse:collapse;margin-top:20px;} th,td{padding:12px;text-align:left;border-bottom:1px solid #ddd;} th{background:#f8fafc;color:#475569;font-weight:bold;width:150px;} td{color:#0f172a;font-weight:600;} .footer{margin-top:40px;border-top:1px dashed #ccc;padding-top:20px;font-size:12px;text-align:center;color:#64748b;}</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<h2>PETRON Merchandise Stock Alert Slip</h2>');
    printWindow.document.write('<p><strong>Date Generated:</strong> ' + new Date().toLocaleString() + '</p>');
    printWindow.document.write('<table>');
    printWindow.document.write('<tr><th>Product ID</th><td>#' + id + '</td></tr>');
    printWindow.document.write('<tr><th>Product Name</th><td>' + name + '</td></tr>');
    printWindow.document.write('<tr><th>Current Stock</th><td>' + stock + '</td></tr>');
    printWindow.document.write('<tr><th>Reorder Level</th><td>' + reorder + '</td></tr>');
    printWindow.document.write('<tr><th>Alert Type</th><td>' + type + '</td></tr>');
    printWindow.document.write('</table>');
    printWindow.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Inventory Overview — Print Record
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function printProductRecord(productId) {
    fetch('manager_inventory_merchandise.php?ajax=1&action=get_product_details&product_id=' + productId)
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) { alert('Could not load product data.'); return; }
        var p = res.product;

        var vText = '—', vColor = '#64748b';
        if (p.variance !== null) {
            var vVal = parseFloat(p.variance);
            if (vVal > 0)      { vText = '+' + vVal; vColor = '#28a745'; }
            else if (vVal < 0) { vText = vVal;        vColor = '#dc3545'; }
            else               { vText = '0 (No Variance)'; }
        }

        var stock = parseFloat(p.stock_level) || 0;
        var reorder = parseFloat(p.reorder_level) || 0;

        var ts = p.last_updated ? (function(d) {
            var dt = new Date(d);
            return isNaN(dt) ? d : dt.toLocaleDateString('en-US', {year:'numeric',month:'short',day:'2-digit'}) + ' ' + dt.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
        })(p.last_updated) : '—';

        var pw = window.open('', '_blank');
        pw.document.write('<!DOCTYPE html><html><head><title>Product Record — ' + esc(p.name) + '</title>');
        pw.document.write('<style>');
        pw.document.write('body{font-family:Arial,sans-serif;font-size:13px;color:#222;margin:0;padding:24px;}');
        pw.document.write('.header{background:#002F6C;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;margin-bottom:0;}');
        pw.document.write('.header h2{margin:0;font-size:16px;letter-spacing:.5px;}');
        pw.document.write('.header p{margin:4px 0 0;font-size:11px;opacity:.8;}');
        pw.document.write('.section{border:1px solid #e2e8f0;border-top:none;padding:16px 20px;margin-bottom:12px;}');
        pw.document.write('.section h4{margin:0 0 10px;color:#002F6C;font-size:11px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}');
        pw.document.write('table.info{width:100%;border-collapse:collapse;font-size:12px;}');
        pw.document.write('table.info tr td:first-child{color:#64748b;font-weight:600;width:160px;padding:5px 0;}');
        pw.document.write('table.info tr td{padding:5px 0;border-bottom:1px solid #f1f5f9;}');
        pw.document.write('.badge{display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:700;}');
        pw.document.write('.footer{text-align:center;font-size:10px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}');
        pw.document.write('@media print{.no-print{display:none;}}');
        pw.document.write('</style></head><body>');
        pw.document.write('<div class="header"><h2><i></i> Product Inventory Record</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
        pw.document.write('<div class="section"><h4>Product Information</h4>');
        pw.document.write('<table class="info">');
        pw.document.write('<tr><td>Product Name:</td><td><strong>' + esc(p.name) + '</strong></td></tr>');
        pw.document.write('<tr><td>SKU:</td><td><code>' + esc(p.sku || '—') + '</code></td></tr>');
        pw.document.write('<tr><td>Category:</td><td>' + esc(p.category_name || '—') + '</td></tr>');
        pw.document.write('<tr><td>Unit:</td><td>' + esc(p.unit || '—') + '</td></tr>');
        pw.document.write('<tr><td>Supplier:</td><td>' + esc(p.supplier || '—') + '</td></tr>');
        pw.document.write('</table></div>');
        pw.document.write('<div class="section"><h4>Inventory Details</h4>');
        pw.document.write('<table class="info">');
        pw.document.write('<tr><td>Current Stock:</td><td><strong style="font-size:15px;color:#002F6C;">' + p.stock_level + ' ' + esc(p.unit) + '</strong></td></tr>');
        pw.document.write('<tr><td>Reorder Level:</td><td>' + p.reorder_level + ' ' + esc(p.unit) + '</td></tr>');
        pw.document.write('<tr><td>Physical Count:</td><td>' + (p.physical_count !== null ? p.physical_count + ' ' + esc(p.unit) : '—') + '</td></tr>');
        pw.document.write('<tr><td>Variance:</td><td><span style="color:' + vColor + ';font-weight:700;">' + vText + '</span></td></tr>');
        pw.document.write('<tr><td>Last Updated:</td><td>' + ts + '</td></tr>');
        pw.document.write('</table></div>');
        pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
        pw.document.write('</body></html>');
        pw.document.close();
        pw.print();
    })
    .catch(function() { alert('Network error. Could not load product record.'); });
}

// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Inventory Overview — Export PDF / Excel
// â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function exportInvTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrMerchTable', 'Merchandise Inventory Overview Report');
    } else {
        window.print();
    }
}

function exportInvTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrMerchTable', 'merchandise_inventory_overview.xls');
    } else {
        alert('Excel export not supported on this page.');
    }
}

function exportAlertTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrAlertTable', 'Merchandise Stock Alerts Report');
    } else {
        window.print();
    }
}

function exportAlertTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrAlertTable', 'merchandise_stock_alerts_report.xls');
    } else {
        alert('Excel export not supported on this page');
    }
}

var activeVmLogId = null;
function viewMovLogDetails(btn) {
    var row = btn.closest('tr');
    activeVmLogId = row.dataset.logId;
    document.getElementById('vmId').textContent = row.dataset.movId;
    document.getElementById('vmDate').textContent = row.dataset.date;
    document.getElementById('vmProductName').textContent = row.dataset.product + ' (' + row.dataset.sku + ')';
    
    var typeBadge = document.getElementById('vmType');
    typeBadge.textContent = row.dataset.typeFormatted;
    typeBadge.className = 'inv-stock-badge';
    // Style typeBadge accordingly
    var typeRaw = row.dataset.typeRaw.toLowerCase();
    if (['delivery', 'stock_in', 'stock-in'].indexOf(typeRaw) !== -1) {
        typeBadge.style.cssText = 'background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;display:inline-block;';
    } else if (['sale', 'release', 'transaction'].indexOf(typeRaw) !== -1) {
        typeBadge.style.cssText = 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;display:inline-block;';
    } else if (typeRaw === 'adjustment') {
        typeBadge.style.cssText = 'background:#f3e8ff;color:#5b21b6;border:1px solid #e9d5ff;padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;display:inline-block;';
    } else if (['stock_request', 'request'].indexOf(typeRaw) !== -1) {
        typeBadge.style.cssText = 'background:#e0f2fe;color:#075985;border:1px solid #bae6fd;padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;display:inline-block;';
    } else {
        typeBadge.style.cssText = 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:3px 7px;font-size:10px;font-weight:700;border-radius:4px;display:inline-block;';
    }
    
    document.getElementById('vmRef').textContent = row.dataset.ref;
    document.getElementById('vmPrev').textContent = row.dataset.prev;
    
    var changeEl = document.getElementById('vmChange');
    changeEl.textContent = row.dataset.qty;
    var qtyVal = parseFloat(row.dataset.qtyVal) || 0;
    changeEl.style.color = qtyVal > 0 ? '#16a34a' : (qtyVal < 0 ? '#dc2626' : '#64748b');
    
    document.getElementById('vmNew').textContent = row.dataset.new;
    document.getElementById('vmUser').textContent = row.dataset.user;
    document.getElementById('vmNotes').textContent = row.dataset.notes;
    
    document.getElementById('viewMovementModal').classList.add('open');
}

function closeViewMovement() {
    document.getElementById('viewMovementModal').classList.remove('open');
}

function printMovLogFromModal() {
    if (activeVmLogId) {
        var row = document.querySelector('tr[data-log-id="' + activeVmLogId + '"]');
        if (row) {
            printMovLogRecord(row);
        }
    }
}

function printMovLogRecord(el) {
    var row = (el instanceof HTMLElement && el.tagName === 'TR') ? el : el.closest('tr');
    if (!row) return;
    
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Stock Movement Record</title>');
    printWindow.document.write('<style>body{font-family:Arial,sans-serif;padding:30px;color:#333;} h2{color:#002F70;border-bottom:2px solid #002F70;padding-bottom:8px;margin-bottom:20px;} table{width:100%;border-collapse:collapse;margin-top:20px;} th,td{padding:12px;text-align:left;border-bottom:1px solid #ddd;} th{background:#f8fafc;color:#475569;font-weight:bold;width:180px;} td{color:#0f172a;font-weight:600;} .footer{margin-top:40px;border-top:1px dashed #ccc;padding-top:20px;font-size:12px;text-align:center;color:#64748b;}</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<h2>PETRON Merchandise Stock Movement Slip</h2>');
    printWindow.document.write('<p><strong>Date Generated:</strong> ' + new Date().toLocaleString() + '</p>');
    printWindow.document.write('<table>');
    printWindow.document.write('<tr><th>Movement ID</th><td>' + row.dataset.movId + '</td></tr>');
    printWindow.document.write('<tr><th>Date / Time</th><td>' + row.dataset.date + '</td></tr>');
    printWindow.document.write('<tr><th>Product SKU</th><td>' + row.dataset.sku + '</td></tr>');
    printWindow.document.write('<tr><th>Product Name</th><td>' + row.dataset.product + '</td></tr>');
    printWindow.document.write('<tr><th>Movement Type</th><td>' + row.dataset.typeFormatted + '</td></tr>');
    printWindow.document.write('<tr><th>Reference No.</th><td>' + row.dataset.ref + '</td></tr>');
    printWindow.document.write('<tr><th>Previous Stock</th><td>' + row.dataset.prev + '</td></tr>');
    printWindow.document.write('<tr><th>Quantity Change</th><td>' + row.dataset.qty + '</td></tr>');
    printWindow.document.write('<tr><th>New Stock</th><td>' + row.dataset.new + '</td></tr>');
    printWindow.document.write('<tr><th>Performed By</th><td>' + row.dataset.user + '</td></tr>');
    printWindow.document.write('<tr><th>Notes / Remarks</th><td>' + row.dataset.notes + '</td></tr>');
    printWindow.document.write('</table>');
    printWindow.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}



function filterRequestsTable() {
    var srch = document.getElementById('reqSearch').value.toLowerCase();
    var cat = document.getElementById('reqCatFilter').value.toLowerCase();
    var status = document.getElementById('reqStatusFilter').value.toLowerCase();
    var user = document.getElementById('reqUserFilter').value.toLowerCase();
    var dateFrom = document.getElementById('reqDateFrom').value;
    var dateTo = document.getElementById('reqDateTo').value;

    document.querySelectorAll('#mgrRequestsTable tbody .req-row').forEach(function(r) {
        var rId = (r.dataset.id || '').toLowerCase();
        var rItem = (r.dataset.itemName || '').toLowerCase();
        var rCat = (r.dataset.category || '').toLowerCase();
        var rStatus = (r.dataset.status || '').toLowerCase();
        var rUser = (r.dataset.staffName || '').toLowerCase();
        var rDate = r.dataset.createdAt;

        var matchesSrch = !srch || rItem.includes(srch) || rId.includes(srch) || ('sr-' + rId.padStart(4, '0')).includes(srch);
        var matchesCat = !cat || rCat === cat;
        var matchesStatus = false;
        if (!status) {
            matchesStatus = true;
        } else if (status === 'approved') {
            matchesStatus = (rStatus === 'approved' || rStatus === 'waiting for purchase order' || rStatus === 'purchase order generated' || rStatus === 'validated');
        } else {
            matchesStatus = rStatus === status;
        }
        var matchesUser = !user || rUser === user;
        
        var matchesDate = true;
        if (dateFrom && rDate < dateFrom) matchesDate = false;
        if (dateTo && rDate > dateTo) matchesDate = false;

        var ok = matchesSrch && matchesCat && matchesStatus && matchesUser && matchesDate;
        if (ok) {
            r.classList.remove('search-hidden');
        } else {
            r.classList.add('search-hidden');
        }
    });

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['mgrRequestsTable']) {
        window.tablePaginationTriggers['mgrRequestsTable']();
    }
}

function exportMovTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrMovTable', 'Merchandise Stock Movement History');
    } else {
        window.print();
    }
}

function exportMovTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrMovTable', 'merchandise_stock_movement_history.xls');
    } else {
        alert('Excel export not supported on this page');
    }
}

function setupDownwardFilterSelects(selectors) {
    var selects = [];
    selectors.forEach(function(selector) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) selects.push(el);
    });

    selects.forEach(function(select) {
        if (!select || select.dataset.forceDownReady === '1') return;
        select.dataset.forceDownReady = '1';

        var wrap = document.createElement('div');
        wrap.className = 'fd-select';
        var computed = window.getComputedStyle(select);
        if (computed.minWidth && computed.minWidth !== '0px') wrap.style.minWidth = computed.minWidth;
        if (select.style.width) wrap.style.width = select.style.width;
        if (select.style.marginLeft) {
            wrap.style.marginLeft = select.style.marginLeft;
            select.style.marginLeft = '';
        }

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'fd-select-trigger';
        var label = document.createElement('span');
        label.className = 'fd-select-label';
        var arrow = document.createElement('i');
        arrow.className = 'fas fa-chevron-down fd-select-arrow';
        trigger.appendChild(label);
        trigger.appendChild(arrow);

        var menu = document.createElement('div');
        menu.className = 'fd-select-menu';
        Array.from(select.options).forEach(function(option) {
            if (option.hidden) return;
            var item = document.createElement('div');
            item.className = 'fd-select-option';
            item.dataset.value = option.value;
            item.textContent = option.textContent;
            item.addEventListener('click', function() {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                syncLabel();
                wrap.classList.remove('fd-open');
            });
            menu.appendChild(item);
        });

        function syncLabel() {
            var selected = select.options[select.selectedIndex];
            label.textContent = selected ? selected.textContent.trim() : '';
            Array.from(menu.querySelectorAll('.fd-select-option')).forEach(function(item) {
                item.classList.toggle('fd-active', item.dataset.value === select.value);
            });
        }

        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            document.querySelectorAll('.fd-select.fd-open').forEach(function(openWrap) {
                if (openWrap !== wrap) openWrap.classList.remove('fd-open');
            });
            wrap.classList.toggle('fd-open');
        });

        select.addEventListener('change', syncLabel);
        select.classList.add('fd-select-source');
        select.parentNode.insertBefore(wrap, select.nextSibling);
        wrap.appendChild(trigger);
        wrap.appendChild(menu);
        syncLabel();
    });

    if (!window.__forceDownSelectCloseBound) {
        window.__forceDownSelectCloseBound = true;
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.fd-select')) {
                document.querySelectorAll('.fd-select.fd-open').forEach(function(wrap) {
                    wrap.classList.remove('fd-open');
                });
            }
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    setupDownwardFilterSelects([
        '#invCatFilter',
        '#invStockFilter',
        '#alertCatFilter',
        '#alertTypeFilter',
        '#movTypeFilter',
        '#reqCatFilter',
        '#reqStatusFilter',
        '#reqUserFilter'
    ]);



    // â”€â”€ Auto-apply URL-driven filter (from sidebar deep-links) â”€â”€
    <?php if ($url_filter === 'low'): ?>
    // Low Stock Only
    var lowCb = document.getElementById('invLowStockOnly');
    if (lowCb) { lowCb.checked = true; filterInvTable(); }
    <?php elseif ($url_filter === 'critical'): ?>
    // Critical Stock Only
    var critCb = document.getElementById('invCriticalOnly');
    if (critCb) { critCb.checked = true; filterInvTable(); }
    <?php endif; ?>

    // â”€â”€ Auto-scroll to movement history section if ?view=movement â”€â”€
    <?php if ($url_view === 'movement'): ?>
    var mvSect = document.getElementById('movementHistorySection');
    if (mvSect) { setTimeout(function(){ mvSect.scrollIntoView({behavior:'smooth',block:'start'}); }, 300); }
    <?php endif; ?>
});

function filterMgrByCard(val) {
    var select = document.getElementById('invStockFilter');
    if (!select) return;
    if (select.value === val) {
        select.value = '';
    } else {
        select.value = val;
    }
    select.dispatchEvent(new Event('change', { bubbles: true }));
}
</script>

<!-- ══ VIEW PRODUCT MODAL ══ -->
<div class="modal-overlay" id="productDetailModal" style="z-index:10000;">
    <div style="background:#fff;border-radius:14px;width:96%;max-width:820px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 40px rgba(0,0,0,.18);overflow:hidden;">
        <!-- Header -->
        <div style="padding:16px 22px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;flex-shrink:0;">
            <div style="font-size:15px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-box"></i> <span id="pdmTitle">View Product</span>
            </div>
        </div>
        <!-- Sub-tabs -->
        <div style="display:flex;border-bottom:2px solid #e2e8f0;background:#f8fafc;flex-shrink:0;padding:0 16px;">
            <button class="modal-tab-btn active" id="pdmTab1" onclick="pdmSwitchTab(1)"><i class="fas fa-info-circle"></i> Product Info</button>
            <button class="modal-tab-btn" id="pdmTab2" onclick="pdmSwitchTab(2)"><i class="fas fa-layer-group"></i> Batch Inventory</button>
            <button class="modal-tab-btn" id="pdmTab3" onclick="pdmSwitchTab(3)"><i class="fas fa-clipboard-list"></i> Physical Count</button>
        </div>
        <!-- Body -->
        <div style="overflow-y:auto;flex:1;padding:22px;" id="pdmBody">
            <!-- TAB 1: Product Info + Inventory Summary -->
            <div id="pdmPane1">
                <div id="pdmLoadingMsg" style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading...</div>
                <div id="pdmContent" style="display:none;">
                    <div id="pdmAlertNotice"></div>
                    <div style="font-size:11px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-tag"></i> Product Information</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 24px;margin-bottom:20px;">
                        <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">SKU</div><div id="pdmSKU" style="font-weight:700;color:#002F70;font-size:14px;"></div></div>
                        <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Product Name</div><div id="pdmName" style="font-weight:700;color:#0f172a;font-size:14px;"></div></div>
                        <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Category</div><div id="pdmCategory"></div></div>
                        <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Brand</div><div id="pdmBrand"></div></div>
                        <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Supplier</div><div id="pdmSupplier"></div></div>
                        <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Barcode</div><div id="pdmBarcode"></div></div>
                        <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Unit of Measure</div><div id="pdmUOM"></div></div>
                        <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Expiration Date</div><div id="pdmExpiry"></div></div>
                        <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Status</div><div id="pdmStatus"></div></div>
                    </div>
                    <div style="font-size:11px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-chart-bar"></i> Inventory Summary</div>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:8px;">
                        <div style="background:#f1f5f9;border-radius:8px;padding:12px 16px;"><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Current Stock</div><div id="pdmCurrentStock" style="font-size:22px;font-weight:800;color:#002F70;"></div></div>
                        <div style="background:#f1f5f9;border-radius:8px;padding:12px 16px;"><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Reserved Stock</div><div id="pdmReserved" style="font-size:22px;font-weight:800;color:#64748b;">0</div></div>
                        <div style="background:#f1f5f9;border-radius:8px;padding:12px 16px;"><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Available Stock</div><div id="pdmAvailable" style="font-size:22px;font-weight:800;color:#15803d;"></div></div>
                        <div style="background:#f1f5f9;border-radius:8px;padding:12px 16px;"><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Reorder Level</div><div id="pdmReorderLevel" style="font-size:22px;font-weight:800;color:#fd7e14;"></div></div>
                        <div style="background:#f1f5f9;border-radius:8px;padding:12px 16px;"><div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">Critical Level</div><div id="pdmCriticalLevel" style="font-size:22px;font-weight:800;color:#dc3545;"></div></div>
                        <div style="background:#eff6ff;border-radius:8px;padding:12px 16px;border:1px solid #bfdbfe;"><div style="font-size:10px;font-weight:700;color:#1d4ed8;text-transform:uppercase;">Total Inv. Value</div><div id="pdmInvValue" style="font-size:18px;font-weight:800;color:#1d4ed8;"></div></div>
                    </div>
                </div>
            </div>
            <!-- TAB 2: Batch Inventory -->
            <div id="pdmPane2" style="display:none;">
                <div style="font-size:11px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-layer-group"></i> Batch Inventory Breakdown</div>
                <div id="pdmBatchTable"><div style="text-align:center;padding:24px;color:#94a3b8;">No batch data.</div></div>
            </div>
            <!-- TAB 3: Physical Count History -->
            <div id="pdmPane3" style="display:none;">
                <div style="font-size:11px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-clipboard-list"></i> Physical Count History</div>
                <div id="pdmPhysicalTable"><div style="text-align:center;padding:24px;color:#94a3b8;">No physical count records.</div></div>
            </div>
        </div>
        <!-- Footer -->
        <div style="padding:12px 22px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;flex-shrink:0;">
            <button onclick="closeProductModal()" class="int-btn-outline" style="border-color:#6b7280;color:#6b7280;">Close</button>
        </div>
    </div>
</div>

<!-- ══ ADJUST STOCK MODAL ══ -->
<div class="modal-overlay" id="adjustStockModal" style="z-index:10001; padding-top:90px; padding-bottom:50px; box-sizing:border-box;">
    <div style="background:#fff;border-radius:14px;width:96%;max-width:560px;max-height:calc(100vh - 160px);overflow-y:auto;box-shadow:0 24px 40px rgba(0,0,0,.18);">
        <div style="padding:18px 24px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;">
            <div style="font-size:15px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-sliders-h" style="color:#fd7e14;"></i> Adjust Stock
            </div>
        </div>
        <div style="padding:22px;">
            <div class="form-group">
                <label>Adjustment No.</label>
                <input type="text" id="adjNo" readonly style="background:#f1f5f9;color:#64748b;" placeholder="Auto-generated">
            </div>
            <div class="form-group">
                <label>Product</label>
                <input type="text" id="adjProduct" readonly style="background:#f1f5f9;color:#64748b;">
            </div>
            <div class="form-group">
                <label>Batch <span style="color:#94a3b8;font-size:10px;">(Optional)</span></label>
                <select id="adjBatch" style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
                    <option value="">-- Select Batch --</option>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group">
                    <label>Current Quantity</label>
                    <input type="number" id="adjCurrentQty" readonly style="background:#f1f5f9;color:#64748b;">
                </div>
                <div class="form-group">
                    <label>Adjusted Quantity *</label>
                    <input type="number" id="adjNewQty" min="0" placeholder="Enter new quantity" oninput="calcVariance()">
                </div>
            </div>
            <div class="form-group">
                <label>Variance <span style="color:#94a3b8;font-size:10px;">(Auto)</span></label>
                <input type="text" id="adjVariance" readonly style="background:#f1f5f9;color:#64748b;font-weight:700;">
            </div>
            <div class="form-group">
                <label>Adjustment Type *</label>
                <select id="adjType" style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;" required>
                    <option value="">-- Select Type --</option>
                    <option value="Damaged">Damaged</option>
                    <option value="Expired">Expired</option>
                    <option value="Missing">Missing</option>
                    <option value="Physical Count Correction">Physical Count Correction</option>
                    <option value="Returned to Supplier">Returned to Supplier</option>
                    <option value="Others">Others</option>
                </select>
            </div>
            <div class="form-group">
                <label>Reason *</label>
                <textarea id="adjReason" rows="2" placeholder="State the reason for adjustment..." style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;box-sizing:border-box;resize:vertical;"></textarea>
            </div>
            <div class="form-group">
                <label>Remarks <span style="color:#94a3b8;font-size:10px;">(Optional)</span></label>
                <textarea id="adjRemarks" rows="2" placeholder="Additional remarks..." style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;box-sizing:border-box;resize:vertical;"></textarea>
            </div>
            <div class="form-group">
                <label>Supporting Photo <span style="color:#94a3b8;font-size:10px;">(Optional)</span></label>
                <input type="file" id="adjPhoto" accept="image/*" style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;">
            </div>
            <div id="adjError" style="color:#dc3545;font-size:12px;margin-bottom:10px;display:none;"></div>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button onclick="saveAdjustment()" style="background:#002F70;color:#fff;border:none;border-radius:6px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;"><i class="fas fa-paper-plane"></i> Submit Adjustment</button>
                <button onclick="closeAdjustModal()" style="background:#fff;color:#6b7280;border:1px solid #6b7280;border-radius:6px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
// â”€â”€ Product Detail Modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
var _pdmData = null;

function openProductModal(productId) {
    _pdmData = null;
    var modal = document.getElementById('productDetailModal');
    if (!modal) return;
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    modal.classList.add('open');
    modal.style.display = 'flex';
    document.getElementById('pdmLoadingMsg').style.display = 'block';
    document.getElementById('pdmContent').style.display = 'none';
    pdmSwitchTab(1);

    fetch('manager_inventory_merchandise.php?ajax=1&action=get_product_details&product_id=' + productId)
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) {
            document.getElementById('pdmLoadingMsg').innerHTML = '<span style="color:#dc3545;">Failed to load product details: ' + (res.message || '') + '</span>';
            return;
        }
        _pdmData = res;
        var p = res.product;
        document.getElementById('pdmLoadingMsg').style.display = 'none';
        document.getElementById('pdmContent').style.display = 'block';
        document.getElementById('pdmTitle').textContent = p.name || 'View Product';

        // Expiration Alert & Badge Handling
        var expDate = p.expiration_date && p.expiration_date !== 'N/A' && p.expiration_date !== '' ? p.expiration_date : (function() {
            var base = p.last_updated ? new Date(p.last_updated) : new Date('2026-08-31');
            var cat = (p.category || p.category_name || '').toLowerCase();
            var nm  = (p.name || '').toLowerCase();
            if (nm.indexOf('chippy') !== -1 || nm.indexOf('coca') !== -1 || nm.indexOf('choco') !== -1 || cat.indexOf('snack') !== -1 || cat.indexOf('beverage') !== -1) {
                base.setFullYear(base.getFullYear() + 1);
            } else if (cat.indexOf('accessory') !== -1 || cat.indexOf('tool') !== -1 || nm.indexOf('wiper') !== -1 || nm.indexOf('mat') !== -1) {
                base.setFullYear(base.getFullYear() + 5);
            } else {
                base.setFullYear(base.getFullYear() + 3);
            }
            return base.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        })();
        var isExpired = p.exp_status === 'expired' || (expDate !== 'N/A' && new Date(expDate) < new Date(new Date().toDateString()));
        var isExpiringSoon = !isExpired && (p.exp_status === 'expiring_soon' || (expDate !== 'N/A' && (new Date(expDate) - new Date(new Date().toDateString())) / (1000*60*60*24) <= 30));

        var noticeEl = document.getElementById('pdmAlertNotice');
        if (noticeEl) {
            if (isExpired) {
                noticeEl.innerHTML = '<div style="background:#fee2e2; border:1.5px solid #ef4444; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; gap:12px; color:#991b1b; font-size:13.5px; font-weight:700;">' +
                    '<i class="fas fa-exclamation-triangle" style="font-size:22px; color:#dc2626; flex-shrink:0;"></i>' +
                    '<div><strong style="text-transform:uppercase; letter-spacing:0.5px;">Product Has Expired</strong><div style="font-size:12px; font-weight:500; color:#7f1d1d; margin-top:2px;">This product reached its expiration date on ' + expDate + '. Do not dispense or sell to customers.</div></div>' +
                    '</div>';
            } else if (isExpiringSoon) {
                noticeEl.innerHTML = '<div style="background:#fffbeb; border:1.5px solid #f59e0b; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; gap:12px; color:#92400e; font-size:13.5px; font-weight:700;">' +
                    '<i class="fas fa-clock" style="font-size:20px; color:#d97706; flex-shrink:0;"></i>' +
                    '<div><strong style="text-transform:uppercase; letter-spacing:0.5px;">Expiring Soon</strong><div style="font-size:12px; font-weight:500; color:#b45309; margin-top:2px;">This product will expire on ' + expDate + '. Prioritize sales using FIFO.</div></div>' +
                    '</div>';
            } else {
                noticeEl.innerHTML = '';
            }
        }

        // Product Info
        document.getElementById('pdmSKU').textContent = p.sku || '—';
        document.getElementById('pdmName').textContent = p.name || '—';
        document.getElementById('pdmCategory').textContent = p.category_name || '—';
        document.getElementById('pdmBrand').textContent = p.supplier || 'Petron Corporation';
        document.getElementById('pdmSupplier').textContent = p.supplier || 'Petron Corporation';
        document.getElementById('pdmBarcode').textContent = p.barcode || p.sku || '—';
        document.getElementById('pdmUOM').textContent = p.unit || '—';

        var expiryEl = document.getElementById('pdmExpiry');
        if (expiryEl) {
            var expBadge = '';
            if (isExpired) {
                expBadge = ' <span style="background:#dc354520;color:#dc3545;border:1.5px solid #dc354560;border-radius:4px;font-size:10px;font-weight:800;padding:2px 6px;text-transform:uppercase;margin-left:6px;display:inline-block;"><i class="fas fa-exclamation-circle"></i> EXPIRED</span>';
            } else if (isExpiringSoon) {
                expBadge = ' <span style="background:#fd7e1420;color:#c05c00;border:1.5px solid #fd7e1460;border-radius:4px;font-size:10px;font-weight:800;padding:2px 6px;text-transform:uppercase;margin-left:6px;display:inline-block;"><i class="fas fa-clock"></i> EXPIRING SOON</span>';
            }
            expiryEl.innerHTML = '<span style="color:' + (isExpired ? '#dc3545' : (expDate !== 'N/A' ? '#1e293b' : '#94a3b8')) + ';font-weight:700;">' + expDate + '</span>' + expBadge;
        }

        if (isExpired) {
            document.getElementById('pdmStatus').innerHTML = '<span style="background:#fee2e2;color:#dc2626;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;"><i class="fas fa-ban" style="margin-right:3px;"></i> EXPIRED</span>';
        } else {
            var status = (p.product_status || 'active').toLowerCase();
            var sBg = status === 'active' ? '#d4edda' : '#e9ecef';
            var sColor = status === 'active' ? '#155724' : '#495057';
            document.getElementById('pdmStatus').innerHTML = '<span style="background:' + sBg + ';color:' + sColor + ';padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;">' + (p.product_status || 'Active') + '</span>';
        }

        // Inventory Summary
        var stock = parseFloat(p.stock_level || 0);
        var reorder = parseFloat(p.reorder_level || 24);
        var critical = parseFloat(p.critical_level || 10);
        var price = parseFloat(p.price || p.cost || 0);
        document.getElementById('pdmCurrentStock').textContent = stock.toLocaleString() + ' ' + (p.unit || 'pcs');
        document.getElementById('pdmReserved').textContent = '0';
        document.getElementById('pdmAvailable').textContent = isExpired ? ('0 ' + (p.unit || 'pcs')) : (stock.toLocaleString() + ' ' + (p.unit || 'pcs'));
        document.getElementById('pdmReorderLevel').textContent = reorder.toLocaleString();
        document.getElementById('pdmCriticalLevel').textContent = critical.toLocaleString();
        document.getElementById('pdmInvValue').textContent = '₱' + (stock * price).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

        // Batch Inventory
        renderBatchTable((res.batches && res.batches.length > 0) ? res.batches : (res.deliveries || []), p);
        // Physical Count
        renderPhysicalTable(res.physical_counts || res.movements || []);

        // Pre-fill adjust modal
        document.getElementById('adjProduct').value = p.name || '';
        document.getElementById('adjCurrentQty').value = stock;
        document.getElementById('adjNo').value = 'ADJ-' + Date.now().toString().slice(-6);
        var batchSel = document.getElementById('adjBatch');
        if (batchSel) {
            batchSel.innerHTML = '<option value="">-- Select Batch --</option>';
            (res.batches || []).forEach(function(b) {
                var opt = document.createElement('option');
                opt.value = b.id || b.batch_id || '';
                opt.textContent = 'Batch ' + (b.batch_id || b.id || '—') + ' — ' + (b.remaining_qty || 0) + ' remaining';
                batchSel.appendChild(opt);
            });
        }
    })
    .catch(function(err) {
        console.error('Error fetching product details:', err);
        document.getElementById('pdmLoadingMsg').innerHTML = '<span style="color:#dc3545;">Error loading product details. Please try again.</span>';
    });
}

function renderBatchTable(batches, p) {
    var el = document.getElementById('pdmBatchTable');
    if (!batches || batches.length === 0) {
        el.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;">No batch records found.</div>';
        return;
    }
    var pExpiry = (p && p.expiration_date && p.expiration_date !== 'N/A') ? p.expiration_date : '—';
    var pPrice = (p && (p.price || p.selling_price)) ? parseFloat(p.price || p.selling_price) : 0;
    var html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">' +
        '<thead><tr style="background:#002F70;color:#fff;">' +
        '<th style="padding:8px 10px;">Batch ID</th>' +
        '<th style="padding:8px 10px;">Delivery Date</th>' +
        '<th style="padding:8px 10px;">Expiration Date</th>' +
        '<th style="padding:8px 10px;text-align:right;">Received Qty</th>' +
        '<th style="padding:8px 10px;text-align:right;">Remaining Qty</th>' +
        '<th style="padding:8px 10px;text-align:right;">Unit Cost</th>' +
        '<th style="padding:8px 10px;text-align:right;">Selling Price</th>' +
        '<th style="padding:8px 10px;text-align:center;">Status</th>' +
        '</tr></thead><tbody>';
    batches.forEach(function(b) {
        var dDate = b.encoded_at ? new Date(b.encoded_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : (b.delivery_date ? new Date(b.delivery_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—');
        var expBatchStr = b.expiration_date ? new Date(b.expiration_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : pExpiry;
        var rcv = parseFloat(b.qty_received || b.received_qty || b.quantity || 0);
        var remaining = parseFloat(b.remaining_qty !== undefined ? b.remaining_qty : (b.qty_received || b.quantity || 0));
        var cost = parseFloat(b.unit_cost || b.cost || 0);
        var price = parseFloat(b.selling_price || b.price || pPrice);
        var status = remaining <= 0 ? 'Depleted' : 'Active';
        var sBg = remaining <= 0 ? '#f1f5f9' : '#d4edda';
        var sColor = remaining <= 0 ? '#64748b' : '#155724';
        html += '<tr style="border-bottom:1px solid #f1f5f9;">' +
            '<td style="padding:8px 10px;font-weight:700;color:#002F70;">' + (b.batch_no || b.batch_id || ('BATCH-' + (b.id || '1'))) + '</td>' +
            '<td style="padding:8px 10px;color:#475569;">' + dDate + '</td>' +
            '<td style="padding:8px 10px;color:#475569;font-weight:600;">' + expBatchStr + '</td>' +
            '<td style="padding:8px 10px;text-align:right;">' + rcv.toLocaleString() + '</td>' +
            '<td style="padding:8px 10px;text-align:right;font-weight:700;">' + remaining.toLocaleString() + '</td>' +
            '<td style="padding:8px 10px;text-align:right;">₱' + cost.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + '</td>' +
            '<td style="padding:8px 10px;text-align:right;">₱' + price.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + '</td>' +
            '<td style="padding:8px 10px;text-align:center;"><span style="background:' + sBg + ';color:' + sColor + ';padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">' + status + '</span></td>' +
            '</tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}

function renderMovementTable(movements) {
    var el = document.getElementById('pdmMovementTable');
    if (!el) return;
    if (!movements || movements.length === 0) {
        el.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;">No movement records found.</div>';
        return;
    }
    var html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">' +
        '<thead><tr style="background:#002F70;color:#fff;">' +
        '<th style="padding:8px 10px;">Date</th>' +
        '<th style="padding:8px 10px;">Movement Type</th>' +
        '<th style="padding:8px 10px;">Reference No.</th>' +
        '<th style="padding:8px 10px;">Batch</th>' +
        '<th style="padding:8px 10px;text-align:right;">Quantity</th>' +
        '<th style="padding:8px 10px;text-align:right;">Remaining Stock</th>' +
        '<th style="padding:8px 10px;">Performed By</th>' +
        '</tr></thead><tbody>';
    movements.forEach(function(m) {
        var mDate = m.created_at ? new Date(m.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—';
        var qty = parseFloat(m.quantity || m.quantity_change || 0);
        var qtyStr = (qty >= 0 ? '+' : '') + qty.toLocaleString();
        var qtyColor = qty >= 0 ? '#15803d' : '#dc3545';
        var remaining = m.quantity_after !== undefined ? parseFloat(m.quantity_after) : '—';
        html += '<tr style="border-bottom:1px solid #f1f5f9;">' +
            '<td style="padding:8px 10px;color:#475569;">' + mDate + '</td>' +
            '<td style="padding:8px 10px;font-weight:600;">' + (m.movement_type || m.action || '—') + '</td>' +
            '<td style="padding:8px 10px;font-size:11px;color:#64748b;">' + (m.reference_id || '—') + '</td>' +
            '<td style="padding:8px 10px;font-size:11px;color:#64748b;">—</td>' +
            '<td style="padding:8px 10px;text-align:right;font-weight:700;color:' + qtyColor + ';">' + qtyStr + '</td>' +
            '<td style="padding:8px 10px;text-align:right;">' + (remaining !== '—' ? remaining.toLocaleString() : '—') + '</td>' +
            '<td style="padding:8px 10px;">' + (m.user_name || '—') + '</td>' +
            '</tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}

function renderPhysicalTable(movements) {
    var el = document.getElementById('pdmPhysicalTable');
    var physical = movements.filter(function(m) {
        var t = (m.movement_type || m.action || '').toLowerCase();
        return t.indexOf('physical') !== -1 || t.indexOf('count') !== -1;
    });
    if (physical.length === 0) {
        el.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;">No physical count records found.</div>';
        return;
    }
    var html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">' +
        '<thead><tr style="background:#002F70;color:#fff;">' +
        '<th style="padding:8px 10px;">Date</th>' +
        '<th style="padding:8px 10px;text-align:right;">System Qty</th>' +
        '<th style="padding:8px 10px;text-align:right;">Physical Qty</th>' +
        '<th style="padding:8px 10px;text-align:right;">Variance</th>' +
        '<th style="padding:8px 10px;">Counted By</th>' +
        '</tr></thead><tbody>';
    physical.forEach(function(m) {
        var mDate = m.created_at ? new Date(m.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—';
        var sysQty = parseFloat(m.quantity_before || 0);
        var phyQty = parseFloat(m.quantity_after || 0);
        var variance = phyQty - sysQty;
        var vColor = variance > 0 ? '#15803d' : variance < 0 ? '#dc3545' : '#64748b';
        html += '<tr style="border-bottom:1px solid #f1f5f9;">' +
            '<td style="padding:8px 10px;color:#475569;">' + mDate + '</td>' +
            '<td style="padding:8px 10px;text-align:right;">' + sysQty.toLocaleString() + '</td>' +
            '<td style="padding:8px 10px;text-align:right;">' + phyQty.toLocaleString() + '</td>' +
            '<td style="padding:8px 10px;text-align:right;font-weight:700;color:' + vColor + ';">' + (variance >= 0 ? '+' : '') + variance.toLocaleString() + '</td>' +
            '<td style="padding:8px 10px;">' + (m.user_name || '—') + '</td>' +
            '</tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}

function pdmSwitchTab(n) {
    for (var i = 1; i <= 3; i++) {
        var btn = document.getElementById('pdmTab' + i);
        var pane = document.getElementById('pdmPane' + i);
        if (btn) btn.classList.toggle('active', i === n);
        if (pane) pane.style.display = i === n ? 'block' : 'none';
    }
}

function closeProductModal() {
    var modal = document.getElementById('productDetailModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
    _pdmData = null;
}

function printProductDetails() {
    if (!_pdmData) return;
    var p = _pdmData.product;
    var pw = window.open('', '_blank', 'width=700,height=800');
    pw.document.write('<!DOCTYPE html><html><head><title>Product Details</title><style>body{font-family:Arial,sans-serif;font-size:13px;padding:20px;color:#000;}h2{color:#002F70;}table{width:100%;border-collapse:collapse;margin-top:12px;}td,th{padding:8px 10px;border-bottom:1px solid #e0e0e0;text-align:left;}.label{font-weight:700;color:#475569;width:200px;}</style></head><body>');
    pw.document.write('<h2>Product Details &mdash; ' + (p.name || '') + '</h2>');
    pw.document.write('<p style="color:#64748b;font-size:11px;">Printed: ' + new Date().toLocaleString() + '</p>');
    pw.document.write('<table><tr><td class="label">SKU</td><td>' + (p.sku || '—') + '</td></tr>');
    pw.document.write('<tr><td class="label">Category</td><td>' + (p.category_name || '—') + '</td></tr>');
    pw.document.write('<tr><td class="label">Supplier</td><td>' + (p.supplier || 'Petron Corporation') + '</td></tr>');
    pw.document.write('<tr><td class="label">Unit of Measure</td><td>' + (p.unit || '—') + '</td></tr>');
    pw.document.write('<tr><td class="label">Current Stock</td><td>' + parseFloat(p.stock_level || 0).toLocaleString() + '</td></tr>');
    pw.document.write('<tr><td class="label">Reorder Level</td><td>' + parseFloat(p.reorder_level || 24).toLocaleString() + '</td></tr>');
    pw.document.write('<tr><td class="label">Critical Level</td><td>' + parseFloat(p.critical_level || 10).toLocaleString() + '</td></tr>');
    pw.document.write('<tr><td class="label">Status</td><td>' + (p.product_status || 'Active') + '</td></tr></table>');
    pw.document.write('</body></html>');
    pw.document.close();
    setTimeout(function(){ pw.print(); }, 400);
}

// â”€â”€ Adjust Stock Modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openAdjustStockModal() {
    var modal = document.getElementById('adjustStockModal');
    if (!modal) return;
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }
    document.getElementById('adjNewQty').value = '';
    document.getElementById('adjVariance').value = '';
    document.getElementById('adjType').value = '';
    document.getElementById('adjReason').value = '';
    document.getElementById('adjRemarks').value = '';
    document.getElementById('adjError').style.display = 'none';
    modal.classList.add('open');
    modal.style.display = 'flex';
}

function closeAdjustModal() {
    var modal = document.getElementById('adjustStockModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function calcVariance() {
    var cur = parseFloat(document.getElementById('adjCurrentQty').value || 0);
    var adj = parseFloat(document.getElementById('adjNewQty').value || 0);
    if (isNaN(adj)) { document.getElementById('adjVariance').value = ''; return; }
    var v = adj - cur;
    document.getElementById('adjVariance').value = (v >= 0 ? '+' : '') + v.toFixed(0);
    document.getElementById('adjVariance').style.color = v > 0 ? '#15803d' : v < 0 ? '#dc3545' : '#64748b';
}

function saveAdjustment() {
    var adjQty = document.getElementById('adjNewQty').value.trim();
    var adjType = document.getElementById('adjType').value;
    var adjReason = document.getElementById('adjReason').value.trim();
    var errEl = document.getElementById('adjError');
    errEl.style.display = 'none';

    if (!adjQty || isNaN(parseFloat(adjQty))) { errEl.textContent = 'Please enter the adjusted quantity.'; errEl.style.display = 'block'; return; }
    if (!adjType) { errEl.textContent = 'Please select an adjustment type.'; errEl.style.display = 'block'; return; }
    if (!adjReason) { errEl.textContent = 'Reason is required.'; errEl.style.display = 'block'; return; }

    // For now, show success message (backend integration can be added later)
    alert('Adjustment submitted successfully!\nType: ' + adjType + '\nVariance: ' + document.getElementById('adjVariance').value);
    closeAdjustModal();
}

function openEditProductModal(productId) {
    var modal = document.getElementById('editProductModal');
    if (!modal) return;
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }

    // Fetch product data via AJAX
    fetch('manager_inventory_merchandise.php?ajax=1&action=get_product_details&product_id=' + productId)
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success || !res.product) {
            alert(res.message || 'Could not load product details.');
            return;
        }
        var p = res.product;
        document.getElementById('editProdId').value = p.id;
        document.getElementById('editProdName').value = p.name || '';
        document.getElementById('editProdCategory').value = p.category_name || 'General';
        document.getElementById('editProdUnit').value = p.unit || 'pcs';
        document.getElementById('editProdReorder').value = p.reorder_level || 24;
        document.getElementById('editProdCritical').value = p.critical_level || 10;
        document.getElementById('editProdCapacity').value = p.capacity || 480;
        document.getElementById('editProdPrice').value = p.price || 0;
        document.getElementById('editProdCost').value = p.cost || 0;

        modal.classList.add('open');
        modal.style.display = 'flex';
    })
    .catch(function(err) {
        alert('Network error while loading product data.');
    });
}

function filterMgrMovTable() {
    var sq = (document.getElementById('mgrMovSearchInput') ? document.getElementById('mgrMovSearchInput').value : '').toLowerCase().trim();
    var tp = (document.getElementById('mgrMovTypeFilter') ? document.getElementById('mgrMovTypeFilter').value : '').toLowerCase().trim();

    var visibleCount = 0;
    var rows = document.querySelectorAll('#mgrMerchMovTbody tr.mgr-mmov-row');
    rows.forEach(function(row) {
        var sText = (row.getAttribute('data-search') || '').toLowerCase();
        var mType = (row.getAttribute('data-type') || '').toLowerCase();
        var rowText = (row.textContent || row.innerText || '').toLowerCase();

        var matchS = !sq || sText.indexOf(sq) !== -1 || rowText.indexOf(sq) !== -1;
        var matchT = !tp || mType.indexOf(tp) !== -1;

        if (matchS && matchT) {
            row.style.display = '';
            row.classList.remove('search-hidden');
            visibleCount++;
        } else {
            row.style.display = 'none';
            row.classList.add('search-hidden');
        }
    });

    // Dynamic No-Match Empty State for Movement Logs
    var noMatchRow = document.getElementById('mgrMovNoMatchRow');
    if (noMatchRow) {
        if (visibleCount === 0 && rows.length > 0) {
            noMatchRow.style.display = '';
            var msgEl = document.getElementById('mgrMovNoMatchMsg');
            if (msgEl) {
                msgEl.innerHTML = sq ? 'No movements found matching "<strong>' + esc(sq) + '</strong>".' : 'No movements found matching selected filter.';
            }
        } else {
            noMatchRow.style.display = 'none';
        }
    }

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['mgrMerchMovTable']) {
        window.tablePaginationTriggers['mgrMerchMovTable']();
    }
}

// ═══════════════════════════════════════════════════════════════
// PAGINATION STATE MACHINES — Inventory, Movement, Alerts tables
// ═══════════════════════════════════════════════════════════════

/* ── Shared helper: get visible (non-hidden, non-no-paginate) rows ── */
function mgrGetVisibleRows(tbodyId) {
    var tbody = document.getElementById(tbodyId);
    if (!tbody) return [];
    return Array.from(tbody.querySelectorAll('tr')).filter(function(r) {
        return !r.classList.contains('search-hidden') &&
               !r.classList.contains('no-paginate') &&
               r.id.indexOf('NoMatchRow') === -1 &&
               r.id.indexOf('NoResults') === -1;
    });
}

/* ══════════════ INVENTORY TAB ══════════════ */
var mgrMerchState = { page: 1, perPage: 25 };

function mgrMerchRender() {
    var rows = mgrGetVisibleRows('merchTableBody');
    var total = rows.length;
    var perPage = mgrMerchState.perPage;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    if (mgrMerchState.page > totalPages) mgrMerchState.page = totalPages;
    if (mgrMerchState.page < 1) mgrMerchState.page = 1;

    var start = (mgrMerchState.page - 1) * perPage;
    var end   = Math.min(start + perPage, total);

    /* Hide/show all rows in tbody including category headers */
    var tbody = document.getElementById('merchTableBody');
    if (tbody) {
        var allRows = Array.from(tbody.querySelectorAll('tr'));
        /* Track which paginated rows to show */
        var visIdx = 0;
        allRows.forEach(function(r) {
            if (r.classList.contains('no-paginate') ||
                r.id.indexOf('NoMatchRow') !== -1 ||
                r.id.indexOf('NoResults') !== -1) {
                return; /* keep as-is */
            }
            if (r.classList.contains('search-hidden')) {
                r.style.display = 'none';
                return;
            }
            if (r.classList.contains('cat-header') || r.classList.contains('category-header')) {
                return; /* handle below */
            }
            /* Regular data row */
            r.style.display = (visIdx >= start && visIdx < end) ? '' : 'none';
            visIdx++;
        });
        /* Show/hide category headers: visible if they have ≥1 visible sibling */
        tbody.querySelectorAll('.cat-header, .category-header').forEach(function(hdr) {
            var next = hdr.nextElementSibling;
            var hasVisible = false;
            while (next && !next.classList.contains('cat-header') && !next.classList.contains('category-header')) {
                if (next.style.display !== 'none' &&
                    !next.classList.contains('search-hidden') &&
                    !next.classList.contains('no-paginate') &&
                    next.id.indexOf('NoMatchRow') === -1) {
                    hasVisible = true;
                    break;
                }
                next = next.nextElementSibling;
            }
            hdr.style.display = hasVisible ? '' : 'none';
        });
    }

    /* Update footer */
    var showingEl = document.getElementById('mgrMerchShowingText');
    if (showingEl) {
        var s = total === 0 ? 0 : start + 1;
        showingEl.textContent = 'Showing ' + s + '–' + end + ' of ' + total + ' entries';
    }
    var labelEl = document.getElementById('mgrMerchPageLabel');
    if (labelEl) labelEl.textContent = 'Page ' + mgrMerchState.page + ' of ' + totalPages;

    var prevBtn = document.getElementById('mgrMerchPrevBtn');
    var nextBtn = document.getElementById('mgrMerchNextBtn');
    if (prevBtn) {
        prevBtn.disabled = mgrMerchState.page <= 1;
        prevBtn.style.cursor = mgrMerchState.page <= 1 ? 'not-allowed' : 'pointer';
        prevBtn.style.color  = mgrMerchState.page <= 1 ? '#cbd5e1' : '#475569';
    }
    if (nextBtn) {
        nextBtn.disabled = mgrMerchState.page >= totalPages;
        nextBtn.style.cursor = mgrMerchState.page >= totalPages ? 'not-allowed' : 'pointer';
        nextBtn.style.color  = mgrMerchState.page >= totalPages ? '#cbd5e1' : '#475569';
    }
}

function mgrMerchGoPage(p) {
    mgrMerchState.page = p;
    mgrMerchRender();
}

function mgrMerchChangePerPage() {
    var sel = document.getElementById('mgrMerchPerPage');
    if (sel) mgrMerchState.perPage = parseInt(sel.value, 10);
    mgrMerchState.page = 1;
    mgrMerchRender();
}

/* ══════════════ MOVEMENT TAB ══════════════ */
var mgrMerchMovState = { page: 1, perPage: 25 };

function mgrMerchMovRender() {
    var rows = mgrGetVisibleRows('mgrMerchMovTbody');
    var total = rows.length;
    var perPage = mgrMerchMovState.perPage;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    if (mgrMerchMovState.page > totalPages) mgrMerchMovState.page = totalPages;
    if (mgrMerchMovState.page < 1) mgrMerchMovState.page = 1;

    var start = (mgrMerchMovState.page - 1) * perPage;
    var end   = Math.min(start + perPage, total);

    rows.forEach(function(r, i) {
        r.style.display = (i >= start && i < end) ? '' : 'none';
    });

    var showingEl = document.getElementById('mgrMerchMovShowingText');
    if (showingEl) {
        var s = total === 0 ? 0 : start + 1;
        showingEl.textContent = 'Showing ' + s + '–' + end + ' of ' + total + ' entries';
    }
    var labelEl = document.getElementById('mgrMerchMovPageLabel');
    if (labelEl) labelEl.textContent = 'Page ' + mgrMerchMovState.page + ' of ' + totalPages;

    var prevBtn = document.getElementById('mgrMerchMovPrevBtn');
    var nextBtn = document.getElementById('mgrMerchMovNextBtn');
    if (prevBtn) {
        prevBtn.disabled = mgrMerchMovState.page <= 1;
        prevBtn.style.cursor = mgrMerchMovState.page <= 1 ? 'not-allowed' : 'pointer';
        prevBtn.style.color  = mgrMerchMovState.page <= 1 ? '#cbd5e1' : '#475569';
    }
    if (nextBtn) {
        nextBtn.disabled = mgrMerchMovState.page >= totalPages;
        nextBtn.style.cursor = mgrMerchMovState.page >= totalPages ? 'not-allowed' : 'pointer';
        nextBtn.style.color  = mgrMerchMovState.page >= totalPages ? '#cbd5e1' : '#475569';
    }
}

function mgrMerchMovGoPage(p) {
    mgrMerchMovState.page = p;
    mgrMerchMovRender();
}

function mgrMerchMovChangePerPage() {
    var sel = document.getElementById('mgrMerchMovPerPage');
    if (sel) mgrMerchMovState.perPage = parseInt(sel.value, 10);
    mgrMerchMovState.page = 1;
    mgrMerchMovRender();
}

/* ══════════════ ALERTS TAB ══════════════ */
var mgrAlertState = { page: 1, perPage: 25 };

function mgrAlertRender() {
    var rows = mgrGetVisibleRows('alertTableBody');
    var total = rows.length;
    var perPage = mgrAlertState.perPage;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    if (mgrAlertState.page > totalPages) mgrAlertState.page = totalPages;
    if (mgrAlertState.page < 1) mgrAlertState.page = 1;

    var start = (mgrAlertState.page - 1) * perPage;
    var end   = Math.min(start + perPage, total);

    var tbody = document.getElementById('alertTableBody');
    if (tbody) {
        var allRows = Array.from(tbody.querySelectorAll('tr'));
        var visIdx = 0;
        allRows.forEach(function(r) {
            if (r.classList.contains('no-paginate') ||
                r.id.indexOf('NoMatchRow') !== -1 ||
                r.id.indexOf('NoResults') !== -1) {
                return;
            }
            if (r.classList.contains('search-hidden')) {
                r.style.display = 'none';
                return;
            }
            if (r.classList.contains('cat-header') || r.classList.contains('category-header')) {
                return;
            }
            r.style.display = (visIdx >= start && visIdx < end) ? '' : 'none';
            visIdx++;
        });
        tbody.querySelectorAll('.cat-header, .category-header').forEach(function(hdr) {
            var next = hdr.nextElementSibling;
            var hasVisible = false;
            while (next && !next.classList.contains('cat-header') && !next.classList.contains('category-header')) {
                if (next.style.display !== 'none' &&
                    !next.classList.contains('search-hidden') &&
                    !next.classList.contains('no-paginate') &&
                    next.id.indexOf('NoMatchRow') === -1) {
                    hasVisible = true;
                    break;
                }
                next = next.nextElementSibling;
            }
            hdr.style.display = hasVisible ? '' : 'none';
        });
    }

    var showingEl = document.getElementById('mgrAlertShowingText');
    if (showingEl) {
        var s = total === 0 ? 0 : start + 1;
        showingEl.textContent = 'Showing ' + s + '–' + end + ' of ' + total + ' entries';
    }
    var labelEl = document.getElementById('mgrAlertPageLabel');
    if (labelEl) labelEl.textContent = 'Page ' + mgrAlertState.page + ' of ' + totalPages;

    var prevBtn = document.getElementById('mgrAlertPrevBtn');
    var nextBtn = document.getElementById('mgrAlertNextBtn');
    if (prevBtn) {
        prevBtn.disabled = mgrAlertState.page <= 1;
        prevBtn.style.cursor = mgrAlertState.page <= 1 ? 'not-allowed' : 'pointer';
        prevBtn.style.color  = mgrAlertState.page <= 1 ? '#cbd5e1' : '#475569';
    }
    if (nextBtn) {
        nextBtn.disabled = mgrAlertState.page >= totalPages;
        nextBtn.style.cursor = mgrAlertState.page >= totalPages ? 'not-allowed' : 'pointer';
        nextBtn.style.color  = mgrAlertState.page >= totalPages ? '#cbd5e1' : '#475569';
    }
}

function mgrAlertGoPage(p) {
    mgrAlertState.page = p;
    mgrAlertRender();
}

function mgrAlertChangePerPage() {
    var sel = document.getElementById('mgrAlertPerPage');
    if (sel) mgrAlertState.perPage = parseInt(sel.value, 10);
    mgrAlertState.page = 1;
    mgrAlertRender();
}

/* ══════ Init on load + wire search filters to re-render ══════ */
document.addEventListener('DOMContentLoaded', function() {
    /* Run the relevant paginator for the current tab */
    var activeTab = '<?= htmlspecialchars($active_tab) ?>';
    if (activeTab === 'overview' || activeTab === 'inventory') {
        mgrMerchRender();
        /* Wire inventory search inputs to reset page on input */
        ['invSearch','invCatFilter','invStockFilter'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input',  function(){ setTimeout(function(){ mgrMerchState.page=1; mgrMerchRender(); }, 30); });
                el.addEventListener('change', function(){ setTimeout(function(){ mgrMerchState.page=1; mgrMerchRender(); }, 30); });
            }
        });
    } else if (activeTab === 'movement' || activeTab === 'stockin' || activeTab === 'stockout' ||
               activeTab === 'transfers' || activeTab === 'damaged' || activeTab === 'expired') {
        mgrMerchMovRender();
        ['mgrMovSearchInput','mgrMovTypeFilter'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input',  function(){ setTimeout(function(){ mgrMerchMovState.page=1; mgrMerchMovRender(); }, 30); });
                el.addEventListener('change', function(){ setTimeout(function(){ mgrMerchMovState.page=1; mgrMerchMovRender(); }, 30); });
            }
        });
    } else if (activeTab === 'alerts') {
        mgrAlertRender();
        ['alertSearch','alertCatFilter'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener('input',  function(){ setTimeout(function(){ mgrAlertState.page=1; mgrAlertRender(); }, 30); });
                el.addEventListener('change', function(){ setTimeout(function(){ mgrAlertState.page=1; mgrAlertRender(); }, 30); });
            }
        });
    }
    /* Keep setupTablePagination for category-header visibility (harmless) */
    if (typeof setupTablePagination === 'function') {
        if (activeTab === 'overview' || activeTab === 'inventory') setupTablePagination('mgrMerchTable', null, 'mgrMerchPagination', 25);
        if (activeTab === 'movement' || activeTab === 'stockin' || activeTab === 'stockout' ||
            activeTab === 'transfers' || activeTab === 'damaged' || activeTab === 'expired')
            setupTablePagination('mgrMerchMovTable', null, 'mgrMerchMovPagination', 25);
        if (activeTab === 'alerts') setupTablePagination('mgrAlertTable', null, 'mgrAlertPagination', 25);
        if (activeTab === 'requests' || activeTab === 'adjustments')
            setupTablePagination('mgrAdjustmentsTable', null, 'mgrAdjPagination', 25);
    }
    /* Expose triggers so filterInvTable / filterAlertTable / filterMgrMovTable can refresh pages */
    window.tablePaginationTriggers = window.tablePaginationTriggers || {};
    window.tablePaginationTriggers['mgrMerchTable']    = function(){ mgrMerchState.page=1; mgrMerchRender(); };
    window.tablePaginationTriggers['mgrMerchMovTable'] = function(){ mgrMerchMovState.page=1; mgrMerchMovRender(); };
    window.tablePaginationTriggers['mgrAlertTable']    = function(){ mgrAlertState.page=1; mgrAlertRender(); };

    // Auto-open Product Modal & scroll into view when navigated from Global Search
    var urlParams = new URLSearchParams(window.location.search);
    var autoOpenPid = urlParams.get('product_id') || urlParams.get('pid');
    var autoOpenSearch = urlParams.get('search_query') || urlParams.get('search');
    var autoOpenFlag = urlParams.get('auto_open') === '1' || !!autoOpenPid;

    if (autoOpenFlag || autoOpenPid || autoOpenSearch) {
        // Strip auto-open params from URL immediately so refresh won't re-trigger
        (function() {
            var clean = new URLSearchParams(window.location.search);
            ['auto_open','product_id','pid','search_query','search'].forEach(function(k){ clean.delete(k); });
            var newUrl = window.location.pathname + (clean.toString() ? '?' + clean.toString() : '');
            history.replaceState(null, '', newUrl);
        })();

        setTimeout(function() {
            var searchInput = document.getElementById('invSearch');
            if (searchInput && autoOpenSearch && !searchInput.value) {
                searchInput.value = autoOpenSearch;
                if (typeof filterInvTable === 'function') filterInvTable();
                if (typeof mgrMerchRender === 'function') {
                    mgrMerchState.page = 1;
                    mgrMerchRender();
                }
            }

            var targetRow = null;
            if (autoOpenPid) {
                targetRow = document.querySelector('tr.merch-row[data-id="' + autoOpenPid + '"]');
            }
            if (!targetRow && autoOpenSearch) {
                var sLower = autoOpenSearch.toLowerCase().trim();
                var allRows = document.querySelectorAll('tr.merch-row');
                for (var i = 0; i < allRows.length; i++) {
                    var n = (allRows[i].dataset.name || '').toLowerCase();
                    var s = (allRows[i].dataset.sku || '').toLowerCase();
                    if (n === sLower || s === sLower || n.indexOf(sLower) !== -1 || sLower.indexOf(n) !== -1) {
                        targetRow = allRows[i];
                        break;
                    }
                }
            }

            if (targetRow) {
                // If on another page in pagination, switch page
                var visibleRows = Array.from(document.querySelectorAll('#merchTableBody tr.merch-row:not(.search-hidden)'));
                var rowIdx = visibleRows.indexOf(targetRow);
                if (rowIdx !== -1 && typeof mgrMerchGoPage === 'function' && window.mgrMerchState) {
                    var targetPage = Math.floor(rowIdx / mgrMerchState.perPage) + 1;
                    if (targetPage !== mgrMerchState.page) {
                        mgrMerchGoPage(targetPage);
                    }
                }

                // Scroll smoothly to row and highlight it (no modal auto-open)
                targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                targetRow.style.transition = 'all 0.5s ease';
                targetRow.style.outline = '3px solid #002F70';
                targetRow.style.backgroundColor = '#dbeafe';
                setTimeout(function() {
                    targetRow.style.outline = '';
                    targetRow.style.backgroundColor = '';
                }, 2500);
            }
        }, 350);
    }
});
</script>

</div> <!-- /.mim-wrap -->

<script id="merchAutoRefreshScript">
// ── Silent Background Auto-Refresh for Merchandise Inventory (Dynamic Interval) ──
(function() {
    'use strict';
    let _merchRefreshTimer = null;

    function runMerchRefresh() {
        if (!document.hidden) {
            // If any modal is currently open, skip refresh to avoid interrupting user input
            var openModal = document.querySelector('.modal-overlay.open, .sr-modal-overlay.open, .modal.show, div[style*="display: block"][id*="Modal"]');
            if (!openModal) {
                if (typeof loadMerchandiseInventory === 'function') {
                    loadMerchandiseInventory();
                } else if (typeof fetchInventoryData === 'function') {
                    fetchInventoryData();
                } else if (window.PetronRealtime && typeof window.PetronRealtime.refreshActiveView === 'function') {
                    window.PetronRealtime.refreshActiveView({ force: false });
                }
            }
        }
    }

    function scheduleMerchRefresh(ms) {
        if (_merchRefreshTimer) clearInterval(_merchRefreshTimer);
        const interval = (typeof ms === 'number' && ms >= 5000)
            ? ms
            : ((typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000) ? window.PETRON_AUTO_REFRESH_MS : 10000);
        _merchRefreshTimer = setInterval(runMerchRefresh, interval);
    }

    scheduleMerchRefresh();

    document.addEventListener('petron:auto-refresh-interval-changed', function(e) {
        if (e.detail && e.detail.intervalMs) {
            scheduleMerchRefresh(e.detail.intervalMs);
        }
    });
})();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>

