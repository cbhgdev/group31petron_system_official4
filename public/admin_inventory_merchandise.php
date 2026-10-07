<?php
// ============================================================
// Admin Merchandise Inventory Oversight - admin_inventory_merchandise.php
// Rebuilt to support summary cards, filters, flat paginated table,
// action buttons (View Details, View History, Print Inventory), and modal popups.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_inventory_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

// â”€â”€ Module gate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['admin','superadmin'])) { 
    header('Location: dashboard.php'); 
    exit; 
}
if ($station_id <= 0 && $role === 'admin') { 
    render_no_station_page('admin_dashboard.php'); 
}

// ── Handle Admin Direct Stock Adjustment (No Approval Required) ───────────
if (isset($_GET['action']) && $_GET['action'] === 'admin_direct_adjust') {
    header('Content-Type: application/json');

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;

    $prod_id   = (int)($data['product_id'] ?? 0);
    $adj_type  = trim($data['adjustment_type'] ?? '');
    $adj_act   = trim($data['adjustment_action'] ?? 'Decrease');
    $qty       = (float)($data['quantity'] ?? 0);
    $reason    = trim($data['reason'] ?? $adj_type);
    $remarks   = trim($data['remarks'] ?? '');

    if ($prod_id <= 0 || empty($adj_type) || $qty <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid adjustment parameters.']);
        exit;
    }

    // Force direction for fixed types
    if (in_array($adj_type, ['Damaged Product', 'Expired Product', 'Missing Item'], true)) {
        $adj_act = 'Decrease';
    } elseif ($adj_type === 'Returned Item') {
        $adj_act = 'Increase';
    }

    try {
        // Fetch product info
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(ip.id, p.id, si.product_id) AS id,
                COALESCE(ip.product_name, p.name, 'Unknown') AS product_name,
                COALESCE(ip.sku, p.sku, CONCAT('P', LPAD(COALESCE(ip.id, p.id), 4, '0'))) AS sku,
                COALESCE(ip.category, pc.name, 'Merchandise') AS category,
                COALESCE(si.stock_level, ip.stock, p.current_stock, 0) AS current_stock
            FROM station_inventory si
            LEFT JOIN inventory_products ip ON ip.id = si.product_id
            LEFT JOIN products p ON p.id = si.product_id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE si.product_id = ? AND si.station_id = ?
            LIMIT 1
        ");
        $stmt->execute([$prod_id, $station_id]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            echo json_encode(['success' => false, 'message' => 'Product not found in station inventory.']);
            exit;
        }

        $current_stock = (float)$prod['current_stock'];

        // Calculate adjusted stock
        if ($adj_type === 'Physical Count') {
            $quantity_change = (int)($qty - $current_stock);
            $adj_act         = $quantity_change >= 0 ? 'Increase' : 'Decrease';
            $new_stock       = max(0, (int)$qty);
        } else {
            $strict_types = ['Damaged Product', 'Expired Product', 'Missing Item'];
            if ($adj_act === 'Decrease' && in_array($adj_type, $strict_types, true) && $qty > $current_stock) {
                echo json_encode([
                    'success' => false,
                    'message' => "Deduction quantity ({$qty}) cannot exceed current stock ({$current_stock}) for {$adj_type}."
                ]);
                exit;
            }
            $quantity_change = ($adj_act === 'Decrease') ? -(int)$qty : +(int)$qty;
            $new_stock       = max(0, (int)($current_stock + $quantity_change));
        }

        $full_reason = $reason . ($remarks !== '' ? ' — ' . $remarks : '');

        $pdo->beginTransaction();

        // 1. Record in merchandise_adjustments as auto-approved
        $ins = $pdo->prepare("
            INSERT INTO merchandise_adjustments
            (station_id, product_id, product_name, sku, category, current_stock, adjusted_stock, quantity_change, adjustment_type, reason, status, requested_by, approved_by, requested_at, approved_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', ?, ?, NOW(), NOW(), NOW(), NOW())
        ");
        $ins->execute([
            $station_id,
            $prod_id,
            $prod['product_name'],
            $prod['sku'],
            $prod['category'],
            (int)$current_stock,
            (int)$new_stock,
            (int)$quantity_change,
            $adj_type,
            $full_reason,
            $me['id'],
            $me['id']
        ]);
        $adj_id = $pdo->lastInsertId();

        // 2. Update station_inventory
        $upd = $pdo->prepare("UPDATE station_inventory SET stock_level = ?, last_updated = NOW() WHERE product_id = ? AND station_id = ?");
        $upd->execute([$new_stock, $prod_id, $station_id]);

        // 3. Update inventory_products
        try {
            $pdo->prepare("UPDATE inventory_products SET stock = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$new_stock, $prod_id]);
        } catch (Exception $e2) {}

        // 4. Update products table
        try {
            $pdo->prepare("UPDATE products SET current_stock = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$new_stock, $prod_id]);
        } catch (Exception $e3) {}

        // 5. Log to inventory_logs
        $pdo->prepare("
            INSERT INTO inventory_logs (station_id, product_id, action, quantity_change, notes, user_id, created_at)
            VALUES (?, ?, 'adjustment', ?, ?, ?, NOW())
        ")->execute([
            $station_id,
            $prod_id,
            $quantity_change,
            "Admin Direct Adjustment — {$adj_type}: Stock updated from {$current_stock} to {$new_stock}. {$full_reason}",
            $me['id']
        ]);

        // 6. Log to inventory_movements if table exists
        try {
            $pdo->prepare("
                INSERT INTO inventory_movements (station_id, product_id, reference_no, movement_type, quantity_change, resulting_stock, remarks, created_by, created_at)
                VALUES (?, ?, ?, 'adjustment', ?, ?, ?, ?, NOW())
            ")->execute([
                $station_id,
                $prod_id,
                'ADJ-' . str_pad($adj_id, 4, '0', STR_PAD_LEFT),
                $quantity_change,
                $new_stock,
                "Admin Direct: {$adj_type} — {$full_reason}",
                $me['id']
            ]);
        } catch (Exception $e4) {}

        log_activity($pdo, $me['id'], 'Admin Direct Adjustment', "Adjusted #{$adj_id} for {$prod['product_name']} ({$adj_type}: {$quantity_change}). New stock: {$new_stock}");

        $pdo->commit();

        echo json_encode([
            'success'     => true,
            'message'     => "Stock for '{$prod['product_name']}' adjusted successfully. New stock: {$new_stock}.",
            'new_stock'   => $new_stock,
            'adj_id'      => $adj_id,
            'qty_change'  => $quantity_change
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ── Handle Product Information Update (POST) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_product') {
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

            // 4. Log the action
            try {
                $log_stmt = $pdo->prepare("
                    INSERT INTO inventory_logs (product_id, station_id, user_id, action, quantity_change, previous_stock, new_stock, notes, created_at)
                    VALUES (?, ?, ?, 'PRODUCT_UPDATE', 0, 0, 0, ?, NOW())
                ");
                $log_stmt->execute([$prod_id, $station_id, $me['id'], "Admin updated product settings: {$name}"]);
            } catch (Exception $e_log) {}

            $pdo->commit();
            $_SESSION['success'] = 'Product updated successfully.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = 'Error updating product: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = 'Invalid product data.';
    }
    header('Location: admin_inventory_merchandise.php?tab=' . urlencode($_POST['tab'] ?? 'overview'));
    exit;
}

// â”€â”€ Status Badge Styles / Helper Functions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!function_exists('getStatusBadgeClass')) {
    function getStatusBadgeClass($status) {
        $s = strtolower(trim($status ?? ''));
        if ($s === 'expired') return 'bg-red';
        if ($s === 'available' || $s === 'normal' || $s === 'active' || $s === 'ok') return 'bg-green';
        if (in_array($s, ['low', 'low stock', 'critical', 'critical stock', 'out', 'out of stock'], true)) return 'bg-red';
        return 'bg-green';
    }
}

if (!function_exists('getStatusLabel')) {
    function getStatusLabel($status) {
        $s = strtolower(trim($status ?? ''));
        if ($s === 'expired') return 'EXPIRED';
        if (in_array($s, ['available', 'normal', 'active', 'ok'], true)) return 'AVAILABLE';
        if (in_array($s, ['low', 'low stock', 'critical', 'critical stock'], true)) return 'LOW STOCK';
        if (in_array($s, ['out', 'out of stock'], true)) return 'OUT OF STOCK';
        if ($s === 'inactive') return 'INACTIVE';
        return 'AVAILABLE';
    }
}

// Brand parser helper
if (!function_exists('get_product_brand')) {
    function get_product_brand($product_name) {
        $name = strtolower($product_name);
        if (strpos($name, 'petron') !== false) return 'Petron';
        if (strpos($name, 'sprint') !== false) return 'Sprint';
        if (strpos($name, 'rev-x') !== false || strpos($name, 'revx') !== false) return 'Rev-X';
        if (strpos($name, 'ultron') !== false) return 'Ultron';
        if (strpos($name, 'blaze') !== false) return 'Blaze';
        return 'Petron'; // Fallback brand
    }
}

// Barcode helper stub (empty)
if (!function_exists('get_product_barcode')) {
    function get_product_barcode($sku) {
        return '';
    }
}

// â”€â”€ PRINT FRIENDLY VIEW â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['print_id'])) {
    $print_id = (int)$_GET['print_id'];
    
    // Fetch product details
    $item = null;
    try {
        $stmt = $pdo->prepare("
            SELECT ip.*,
                   ip.product_name AS name,
                   ip.category AS category_name,
                   ip.unit_price AS price,
                   ip.unit_cost AS cost,
                   COALESCE(si.unit, ip.size, 'pcs')       AS unit,
                   COALESCE(si.status, 'active')          AS status,
                   COALESCE(si.stock_level, ip.stock, 0)  AS stock_level,
                   COALESCE(si.capacity, ip.max_stock, 480) AS capacity,
                   COALESCE(si.reorder_level, ip.min_stock, 24) AS reorder_level,
                   COALESCE(si.critical_level, 10)              AS critical_level,
                   COALESCE(si.variance, 0.00)            AS variance,
                   COALESCE(si.last_updated, ip.updated_at, ip.created_at) AS last_updated,
                   COALESCE(ip.brand, 'Petron Corporation') AS supplier
            FROM inventory_products ip
            LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
            WHERE ip.id = ? AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
        ");
        $stmt->execute([$station_id, $print_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $primary_error) {}

    if (!$item) {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   p.name AS product_name,
                   p.name AS name,
                   COALESCE(pc.name, 'General') AS category,
                   COALESCE(pc.name, 'General') AS category_name,
                   COALESCE(si.price, p.price, si.cost, p.cost, 0) AS unit_price,
                   COALESCE(si.price, p.price, si.cost, p.cost, 0) AS price,
                   COALESCE(p.cost, si.cost, 0) AS unit_cost,
                   COALESCE(p.cost, si.cost, 0) AS cost,
                   COALESCE(NULLIF(p.sku, ''), CONCAT('P', LPAD(p.id, 4, '0'))) AS sku,
                   COALESCE(NULLIF(p.unit, ''), NULLIF(si.unit, ''), 'pcs') AS unit,
                   COALESCE(NULLIF(si.status, ''), NULLIF(p.status, ''), 'active') AS status,
                   COALESCE(si.stock_level, p.current_stock, 0) AS stock_level,
                   COALESCE(NULLIF(si.capacity, 0), NULLIF(p.capacity, 0), NULLIF(p.max_stock_level, 0), 480) AS capacity,
                   COALESCE(NULLIF(si.reorder_level, 0), NULLIF(p.min_stock_level, 0), 24) AS reorder_level,
                   COALESCE(NULLIF(si.critical_level, 0), 10) AS critical_level,
                   COALESCE(si.variance, 0.00) AS variance,
                   COALESCE(si.last_updated, p.updated_at, p.created_at) AS last_updated,
                   COALESCE(latest_supplier.supplier, '') AS supplier
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            LEFT JOIN (
                SELECT LOWER(TRIM(poi.item_name)) AS product_key,
                       SUBSTRING_INDEX(GROUP_CONCAT(s.name ORDER BY po.created_at DESC SEPARATOR '||'), '||', 1) AS supplier
                FROM purchase_order_items poi
                JOIN purchase_orders po ON po.id = poi.po_id
                LEFT JOIN suppliers s ON s.id = po.supplier_id
                WHERE po.station_id = ?
                  AND po.type = 'merch'
                  AND s.name IS NOT NULL
                  AND s.name != ''
                GROUP BY LOWER(TRIM(poi.item_name))
            ) latest_supplier ON latest_supplier.product_key = LOWER(TRIM(p.name))
            LEFT JOIN station_inventory si ON si.product_id = p.id AND si.station_id = ?
            WHERE p.id = ?
              AND LOWER(COALESCE(pc.name, '')) NOT IN ('fuel', 'fuel products', 'services')
        ");
        $stmt->execute([$station_id, $station_id, $print_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$item) {
        die('Product not found.');
    }
    
    $item['category_name'] = format_product_category_display(
        $item['category_name'] ?? $item['category'] ?? '',
        $item['product_name'] ?? $item['name'] ?? '',
        $item['description'] ?? ''
    );
    $item['category'] = $item['category_name'];
    $item['brand'] = get_product_brand(
        $item['product_name'] ?? $item['name'] ?? '',
        $item['category_name'] ?? '',
        $item['description'] ?? ''
    );
    $item['supplier'] = 'Petron Corporation';
    $item['unit'] = format_product_unit_display(
        $item['unit'] ?? 'pcs',
        $item['product_name'] ?? $item['name'] ?? '',
        $item['category_name'] ?? '',
        $item['description'] ?? ''
    );
    
    $station_name = 'Petron Carmen';
    $station_address = 'Vamenta Blvd., Carmen, Cagayan de Oro';
    $station_contact = '';
    try {
        $st_stmt = $pdo->prepare("SELECT * FROM stations WHERE id = ? LIMIT 1");
        $st_stmt->execute([$station_id]);
        $station = $st_stmt->fetch(PDO::FETCH_ASSOC);
        if ($station) {
            if (!empty($station['name'])) $station_name = $station['name'];
            if (!empty($station['address'])) $station_address = $station['address'];
            if (!empty($station['contact_number'])) $station_contact = $station['contact_number'];
        }
    } catch (Exception $e) {}
    
    // Fetch logs
    $log_stmt = $pdo->prepare("
        SELECT il.*, 
               COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') as user_fullname
        FROM inventory_logs il
        LEFT JOIN users u ON il.user_id = u.id
        WHERE il.product_id = ? AND il.station_id = ?
        ORDER BY il.created_at DESC 
        LIMIT 10
    ");
    $log_stmt->execute([$print_id, $station_id]);
    $logs = $log_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stock = (float)$item['stock_level'];
    $price = (float)$item['unit_price'];
    $value = $stock * $price;
    $capacity = max(480.0, (float)$item['capacity']);
    $fill_pct = $capacity > 0 ? min(100, ($stock / $capacity) * 100) : 0;
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Inventory Sheet - <?= htmlspecialchars($item['product_name']) ?></title>
        <style>
            body { font-family: 'Courier New', Courier, monospace; font-size: 15.5px; color: #000; padding: 20px; line-height: 1.5; }
            .print-wrap { max-width: 600px; margin: 0 auto; border: 1px dashed #aaa; padding: 20px; }
            .center { text-align: center; }
            .logo { font-weight: bold; font-size: 20px; color: #002F70; margin-bottom: 2px; }
            .title { font-weight: bold; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 15px; font-size: 15px; }
            .row { display: flex; justify-content: space-between; margin-bottom: 5px; }
            .label { font-weight: bold; }
            .value { text-align: right; }
            .section { border-top: 1px dashed #000; margin-top: 15px; padding-top: 15px; }
            .log-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
            .log-table th, .log-table td { border-bottom: 1px dotted #ccc; padding: 5px; text-align: left; }
            .log-table th { font-weight: bold; }
            .signatures { display: flex; justify-content: space-between; margin-top: 40px; }
            .sig-line { border-top: 1px solid #000; width: 45%; text-align: center; padding-top: 5px; font-size: 14px; }
            .var-pos { color: #16a34a; font-weight: bold; }
            .var-neg { color: #dc2626; font-weight: bold; }
            @media print {
                body { padding: 0; }
                .print-wrap { border: none; }
            }
        
/* == ACTION BUTTON STYLES (VERTICAL STACKING) == */
.act-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 5px !important;
    padding: 4px 8px !important;
    border-radius: 6px !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    width: 100% !important;
    max-width: 90px !important;
    margin-bottom: 3px !important;
    transition: all .18s ease-in-out !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
}
.act-btn:last-child { margin-bottom: 0 !important; }
.act-btn-view { color: #002F70 !important; border-color: #002F70 !important; background: #ffffff !important; }
.act-btn-view:hover { background: #002F70 !important; color: #ffffff !important; }
.act-btn-edit { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.act-btn-edit:hover { background: #16a34a !important; color: #ffffff !important; }

</style>
    </head>
    <body onload="window.print();">
        <div class="print-wrap">
            <div class="center" style="margin-bottom: 20px;">
                <div class="logo" style="letter-spacing: 1px;">PETRON CORPORATION</div>
                <div style="font-weight: bold; font-size: 14px; margin-top: 4px;"><?= htmlspecialchars($station_name) ?></div>
                <div style="font-size: 14px; color: #555;"><?= htmlspecialchars($station_address) ?></div>
                <?php if ($station_contact): ?>
                    <div style="font-size: 14px; color: #555;">Contact: <?= htmlspecialchars($station_contact) ?></div>
                <?php endif; ?>
                <div class="title" style="margin-top: 10px; font-size: 16px;">PRODUCT INVENTORY SHEET</div>
            </div>
            
            <div class="row"><span class="label">Product Name:</span><span class="value"><?= htmlspecialchars($item['product_name']) ?></span></div>
            <div class="row"><span class="label">Brand:</span><span class="value"><?= htmlspecialchars($item['brand']) ?></span></div>
            <div class="row"><span class="label">SKU / Code:</span><span class="value"><code><?= htmlspecialchars($item['sku']) ?></code></span></div>
            <div class="row"><span class="label">Category:</span><span class="value"><?= htmlspecialchars($item['category']) ?></span></div>
            <div class="row"><span class="label">Unit:</span><span class="value"><?= htmlspecialchars($item['unit']) ?></span></div>
            <div class="row"><span class="label">Supplier:</span><span class="value"><?= htmlspecialchars($item['supplier'] ?: '—') ?></span></div>
            
            <div class="section">
                <div class="row"><span class="label">Current Stock:</span><span class="value"><?= number_format($stock, 2) ?> <?= htmlspecialchars($item['unit']) ?></span></div>
                <div class="row"><span class="label">Reorder Level:</span><span class="value"><?= number_format($item['reorder_level'], 2) ?> <?= htmlspecialchars($item['unit']) ?></span></div>
                <div class="row"><span class="label">Maximum Stock:</span><span class="value"><?= number_format($capacity, 2) ?> <?= htmlspecialchars($item['unit']) ?></span></div>
                <div class="row"><span class="label">Capacity Fill:</span><span class="value"><?= number_format($fill_pct, 1) ?>%</span></div>
                <div class="row"><span class="label">Variance:</span><span class="value <?= $item['variance'] < 0 ? 'var-neg' : ($item['variance'] > 0 ? 'var-pos' : '') ?>"><?= ($item['variance'] > 0 ? '+' : '') . number_format($item['variance'], 2) ?></span></div>
            </div>

            <div class="section">
                <div class="row"><span class="label">Unit Price:</span><span class="value">&#8369;<?= number_format($price, 2) ?></span></div>
                <div class="row" style="font-size: 15px; font-weight: bold;"><span class="label">Inventory Value:</span><span class="value">&#8369;<?= number_format($value, 2) ?></span></div>
                <div class="row"><span class="label">Last Updated:</span><span class="value"><?= date('M d, Y', strtotime($item['last_updated'])) ?></span></div>
            </div>

            <?php if (!empty($logs)): ?>
            <div class="section">
                <div class="label" style="font-weight: bold; margin-bottom: 5px;">Recent Stock Movements</div>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Action</th>
                            <th>Change</th>
                            <th>Performed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= date('m/d h:i A', strtotime($log['created_at'])) ?></td>
                            <td><?= htmlspecialchars($log['action']) ?></td>
                            <td><?= ($log['quantity_change'] > 0 ? '+' : '') . number_format($log['quantity_change'], 0) ?></td>
                            <td><?= htmlspecialchars($log['user_fullname']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="signatures">
                <div class="sig-line">
                    Prepared By: <?= htmlspecialchars($me['first_name'] . ' ' . $me['last_name']) ?><br>
                    Admin / Staff
                </div>
                <div class="sig-line">
                    Verified By:<br>
                    Station Manager
                </div>
            </div>

            <div class="center section" style="font-size: 15.5px; color: #666; margin-top: 30px;">
                Petron Station Management System —¢ Official Inventory Slip
            </div>
        </div>
    
<script id="merchAutoRefreshScript">
// ── Silent Background Auto-Refresh for Merchandise Inventory (15 seconds) ──
(function() {
    'use strict';
    setInterval(function() {
        if (!document.hidden) {
            // If any modal is currently open, skip page reload to avoid interrupting user input
            var openModal = document.querySelector('.modal-overlay.open, .sr-modal-overlay.open, .modal.show, div[style*="display: block"][id*="Modal"]');
            if (!openModal) {
                if (typeof loadMerchandiseInventory === 'function') {
                    loadMerchandiseInventory();
                } else if (typeof fetchInventoryData === 'function') {
                    fetchInventoryData();
                } else {
                    location.reload();
                }
            }
        }
    },  15000);
})();
</script>
</body>
    </html>
    <?php
    exit;
}

// â”€â”€ GET MOVEMENT HISTORY (AJAX ENDPOINT) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');
    $pid = (int)($_GET['product_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT il.*, 
                   COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.username, 'System') as user_fullname
            FROM inventory_logs il
            LEFT JOIN users u ON il.user_id = u.id
            WHERE il.product_id = ? AND il.station_id = ?
            ORDER BY il.created_at DESC 
            LIMIT 30
        ");
        $stmt->execute([$pid, $station_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// â”€â”€ GET PRODUCT DETAILS & FIFO BATCHES (AJAX ENDPOINT) â”€â”€â”€â”€â”€â”€â”€â”€
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_product_details') {
    header('Content-Type: application/json');
    $prod_id = (int)($_GET['product_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT msi.*, COALESCE(u.name, 'Staff') AS user_name
            FROM merchandise_stock_in msi
            LEFT JOIN users u ON msi.encoded_by = u.id
            WHERE msi.product_id = ? AND msi.station_id = ?
            ORDER BY msi.encoded_at DESC
            LIMIT 50
        ");
        $stmt->execute([$prod_id, $station_id]);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'deliveries' => $deliveries
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// â”€â”€ GET Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$search_query       = trim($_GET['search_query'] ?? $_GET['search'] ?? '');
$target_product_id  = (int)($_GET['product_id'] ?? $_GET['pid'] ?? 0);
$auto_open_param    = !empty($_GET['auto_open']) || $target_product_id > 0;
$auto_open_item     = null;
$category_filter = trim($_GET['category'] ?? 'all');
$brand_filter    = trim($_GET['brand'] ?? 'all');
$supplier_filter = trim($_GET['supplier'] ?? 'all');
$unit_filter     = trim($_GET['unit'] ?? 'all');
$status_filter   = strtolower(trim($_GET['status_filter'] ?? 'all'));
$date_from      = trim($_GET['date_from'] ?? '');
$date_to        = trim($_GET['date_to'] ?? '');

// â”€â”€ Active Tab â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$active_tab = trim($_GET['tab'] ?? 'overview');
if (!in_array($active_tab, ['overview', 'movement', 'alerts', 'stockin', 'stockout', 'transfer', 'damaged', 'expired'])) $active_tab = 'overview';

// â”€â”€ Fetch dynamic categories for dropdown â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$all_categories = [];
try {
    $cat_stmt = $pdo->query("SELECT DISTINCT category FROM inventory_products WHERE category NOT IN ('fuel', 'fuel products') AND category IS NOT NULL AND category != '' ORDER BY category");
    $all_categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    try {
        $cat_stmt = $pdo->query("
            SELECT DISTINCT pc.name
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE pc.name IS NOT NULL
              AND pc.name != ''
              AND LOWER(pc.name) NOT IN ('fuel', 'fuel products', 'services')
            ORDER BY pc.name
        ");
        $all_categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $fallback_error) {}
}

// â”€â”€ Fetch all merchandise items â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$all_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(ip.id, p.id, si.product_id) AS id,
               COALESCE(ip.product_name, p.name, 'Unknown Product') AS name,
               COALESCE(ip.category, pc.name, 'Merchandise') AS category_name,
               COALESCE(si.price, ip.unit_price, p.price, 0) AS price,
               COALESCE(si.cost, ip.unit_cost, p.cost, 0) AS cost,
               COALESCE(ip.sku, p.sku, CONCAT('P', LPAD(si.product_id, 4, '0'))) AS sku,
               COALESCE(si.unit, ip.size, p.unit, 'pcs') AS unit,
               COALESCE(si.status, ip.status, p.status, 'active') AS status,
               COALESCE(si.stock_level, 0) AS stock_level,
               COALESCE(si.capacity, ip.max_stock, p.capacity, 480) AS capacity,
               COALESCE(si.reorder_level, ip.min_stock, p.min_stock_level, 24) AS reorder_level,
               COALESCE(si.critical_level, 10) AS critical_level,
               si.physical_count,
               si.variance,
               COALESCE(si.last_updated, NOW()) AS last_updated,
               COALESCE(ip.brand, 'Petron Corporation') AS supplier,
               COALESCE(si.expiration_date, ip.expiration_date, p.expiration_date) AS expiration_date
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
    $all_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error loading merchandise inventory: " . $e->getMessage());
}

// ── Stock In map: total received per product (by ID & Name) ─────────────────
$prod_added_map_id   = [];
$prod_added_map_name = [];
try {
    $siStmt = $pdo->prepare("
        SELECT 
            product_id, 
            LOWER(TRIM(product_name)) AS pname, 
            COALESCE(SUM(qty_received), 0) AS total_added
        FROM merchandise_stock_in
        WHERE station_id = ?
        GROUP BY product_id, LOWER(TRIM(product_name))
    ");
    $siStmt->execute([$station_id]);
    foreach ($siStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['total_added'];
        if (!empty($r['product_id']) && (int)$r['product_id'] > 0) {
            $prod_added_map_id[(int)$r['product_id']] = ($prod_added_map_id[(int)$r['product_id']] ?? 0) + $qty;
        }
        if (!empty($r['pname'])) {
            $prod_added_map_name[$r['pname']] = ($prod_added_map_name[$r['pname']] ?? 0) + $qty;
        }
    }
} catch (Exception $e) {}

// ── Stock Out map: total sold/deducted per product (by ID & Name) ────────────
$prod_deducted_map_id   = [];
$prod_deducted_map_name = [];
try {
    $soStmt = $pdo->prepare("
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
    $soStmt->execute([$station_id]);
    foreach ($soStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['total_deducted'];
        if (!empty($r['product_id']) && (int)$r['product_id'] > 0) {
            $prod_deducted_map_id[(int)$r['product_id']] = ($prod_deducted_map_id[(int)$r['product_id']] ?? 0) + $qty;
        }
        if (!empty($r['pname'])) {
            $prod_deducted_map_name[$r['pname']] = ($prod_deducted_map_name[$r['pname']] ?? 0) + $qty;
        }
    }
} catch (Exception $e) {}

$all_brands = [];
$all_suppliers = ['Petron Corporation'];
$all_units = [];
$all_categories = [];
foreach ($all_items as &$item) {
    $pid = (int)$item['id'];
    $pname_norm = strtolower(trim((string)($item['name'] ?? '')));
    $item['stock_in']  = (int)($prod_added_map_id[$pid] ?? $prod_added_map_name[$pname_norm] ?? 0);
    $item['stock_out'] = (int)($prod_deducted_map_id[$pid] ?? $prod_deducted_map_name[$pname_norm] ?? 0);

    $item['category_name'] = format_product_category_display(
        $item['category_name'] ?? '',
        $item['name'] ?? '',
        $item['description'] ?? ''
    );
    if (!empty($item['category_name']) && !in_array($item['category_name'], $all_categories)) {
        $all_categories[] = $item['category_name'];
    }
    $item['brand'] = get_product_brand($item['name'] ?? '', $item['category_name'] ?? '', $item['description'] ?? '');
    $item['barcode'] = get_product_barcode($item['sku']);
    $item['supplier'] = 'Petron Corporation';
    
    if (!in_array($item['brand'], $all_brands)) $all_brands[] = $item['brand'];
    
    $u = format_product_unit_display($item['unit'], $item['name'] ?? '', $item['category_name'] ?? '', $item['description'] ?? '');
    $item['unit'] = $u;
    if (!empty($u) && !in_array($u, $all_units)) $all_units[] = $u;
}
unset($item);
sort($all_brands);
sort($all_suppliers);
sort($all_units);
sort($all_categories);

// â”€â”€ Compute KPI summary metrics & filter list in PHP â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kpi_total_products = 0;
$kpi_total_stock    = 0;
$kpi_low_stock      = 0;
$kpi_critical_stock = 0;
$kpi_out_of_stock   = 0;
$kpi_expired_stock  = 0;
$kpi_total_value    = 0;

$stock_movements_today = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_logs WHERE station_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$station_id]);
    $stock_movements_today = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$pending_adjustments_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status = 'Pending'");
    $stmt->execute([$station_id]);
    $pending_adjustments_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$filtered_items = [];

foreach ($all_items as &$item) {
    $stock     = (float)$item['stock_level'];
    $capacity  = (float)$item['capacity'];
    $reorder   = (float)$item['reorder_level'];
    $critical  = (float)$item['critical_level'];
    $price     = (float)$item['price'];
    $variance  = (float)$item['variance'];
    $has_variance = ($item['variance'] !== null && (float)$item['variance'] != 0);
    $item_status = strtolower(trim($item['status'] ?? 'active'));

    // Expiration computation (prefer real expiration_date from DB)
    $exp_raw = null;
    if (!empty($item['expiration_date']) && $item['expiration_date'] !== '0000-00-00') {
        $exp_raw = $item['expiration_date'];
        $exp_date = date('M d, Y', strtotime($exp_raw));
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
        } catch (Exception $e) { $exp_date = 'Jul 20, 2029'; }
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
    
    // Status computation — driven entirely by DB thresholds & expiration
    if ($exp_status === 'expired') {
        $computed_status = 'expired';
    } elseif ($stock <= 0) {
        $computed_status = 'out';
    } elseif ($stock <= $critical) {
        $computed_status = 'critical';
    } elseif ($stock <= $reorder) {
        $computed_status = 'low';
    } else {
        $computed_status = 'available';
    }
    
    if ($item_status === 'inactive') {
        $computed_status = 'inactive';
    }

    $item['computed_status'] = $computed_status;

    // Global KPIs (unfiltered)
    $kpi_total_products++;
    $kpi_total_stock += $stock;
    if ($computed_status === 'expired') $kpi_expired_stock++;
    elseif ($computed_status === 'low') $kpi_low_stock++;
    elseif ($computed_status === 'critical') $kpi_critical_stock++;
    elseif ($computed_status === 'out') $kpi_out_of_stock++;
    $kpi_total_value += ($stock * $price);

    // Apply Filters
    $is_target_prod = ($target_product_id > 0 && (int)$item['id'] === $target_product_id);
    if (!$is_target_prod) {
        // 1. Search Query
        if ($search_query !== '') {
            $s_lower = trim(strtolower($search_query));
            $status_match = false;
            if ($s_lower === 'expired') {
                $status_match = ($computed_status === 'expired' || $exp_status === 'expired');
            } elseif (in_array($s_lower, ['low', 'low stock'], true)) {
                $status_match = ($computed_status === 'low');
            } elseif (in_array($s_lower, ['critical', 'critical stock'], true)) {
                $status_match = ($computed_status === 'critical');
            } elseif (in_array($s_lower, ['out', 'out of stock'], true)) {
                $status_match = ($computed_status === 'out');
            } elseif (in_array($s_lower, ['variance', 'variance detected'], true)) {
                $status_match = $has_variance;
            } elseif ($s_lower === 'available') {
                $status_match = ($computed_status === 'available' && !$has_variance && $exp_status !== 'expired');
            }
            $name_match = (strpos(strtolower($item['name'] ?? ''), $s_lower) !== false);
            $sku_match  = (strpos(strtolower($item['sku'] ?? ''), $s_lower) !== false);
            $cat_match  = (strpos(strtolower($item['category_name'] ?? ''), $s_lower) !== false);
            $sup_match  = (strpos(strtolower($item['supplier'] ?? ''), $s_lower) !== false);
            $brand_match = (strpos(strtolower($item['brand'] ?? ''), $s_lower) !== false);
            if (!$name_match && !$sku_match && !$cat_match && !$sup_match && !$brand_match && !$status_match) {
                continue;
            }
        }

    // 2. Category Filter
    if ($category_filter !== 'all' && $category_filter !== '') {
        if ($item['category_name'] !== $category_filter) {
            continue;
        }
    }

    // 3. Brand Filter
    if ($brand_filter !== 'all' && $brand_filter !== '') {
        if ($item['brand'] !== $brand_filter) {
            continue;
        }
    }

    // 4. Supplier Filter
    if ($supplier_filter !== 'all' && $supplier_filter !== '') {
        if ($item['supplier'] !== $supplier_filter) {
            continue;
        }
    }

    // 5. Unit Filter
    if ($unit_filter !== 'all' && $unit_filter !== '') {
        if ($item['unit'] !== $unit_filter) {
            continue;
        }
    }

    // 6. Status Filter — Low Stock, Critical Stock, Out of Stock all show the same combined stock alert view
    if ($status_filter !== 'all' && $status_filter !== '') {
        $sf_lower = strtolower($status_filter);
        if (in_array($sf_lower, ['warning', 'low', 'low stock', 'critical', 'critical stock', 'out', 'out of stock'], true)) {
            // Any stock-alert filter shows ALL low + critical + out of stock items together
            if (!in_array($computed_status, ['low', 'critical', 'out'], true)) {
                continue;
            }
        } elseif ($sf_lower === 'expired') {
            if ($computed_status !== 'expired' && $exp_status !== 'expired') {
                continue;
            }
        } elseif (in_array($sf_lower, ['variance', 'variance detected'], true)) {
            if (!$has_variance) {
                continue;
            }
        } elseif ($sf_lower === 'available') {
            if ($computed_status !== 'available' || $has_variance || $exp_status === 'expired') {
                continue;
            }
        } elseif ($sf_lower === 'inactive') {
            if ($computed_status !== 'inactive') {
                continue;
            }
        } else {
            if ($computed_status !== $sf_lower) {
                continue;
            }
        }
    }

    // 7. Date range filter
    $updated_date = date('Y-m-d', strtotime($item['last_updated'] ?? 'now'));
    if ($date_from !== '' && $updated_date < $date_from) {
        continue;
    }
    if ($date_to !== '' && $updated_date > $date_to) {
        continue;
    }
    }

    $item['computed_status'] = $computed_status;
    $filtered_items[] = $item;

    if ($target_product_id > 0 && (int)$item['id'] === $target_product_id) {
        $auto_open_item = $item;
    } elseif (!$auto_open_item && $search_query !== '' && (stripos($item['name'] ?? '', $search_query) !== false || stripos($item['sku'] ?? '', $search_query) !== false)) {
        $auto_open_item = $item;
    }
}
unset($item);

if (!$auto_open_item && $target_product_id > 0) {
    foreach ($all_items as $ai) {
        if ((int)$ai['id'] === $target_product_id) {
            $auto_open_item = $ai;
            break;
        }
    }
}

// Group filtered items by category for grouped rendering
$sorted_filtered = [];
if (!empty($filtered_items)) {
    $grouped_filtered_items = [];
    foreach ($filtered_items as $item) {
        $cat = $item['category_name'] ?: 'Uncategorized';
        $grouped_filtered_items[$cat][] = $item;
    }
    $cat_order = ['Oils/Lubes/Grease', 'Filters', 'VIC Filters', 'Drinks/Food', 'Snacks', 'Car Accessories', 'Merchandise', 'Others'];
    foreach ($cat_order as $k) { 
        if (isset($grouped_filtered_items[$k])) {
            $sorted_filtered[$k] = $grouped_filtered_items[$k]; 
        } 
    }
    foreach ($grouped_filtered_items as $k => $v) { 
        if (!in_array($k, $cat_order)) {
            $sorted_filtered[$k] = $v; 
        }
    }
}

// â”€â”€ KPI: Available Stock count â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$kpi_available_stock = 0;
foreach ($all_items as $item) {
    $s = (float)$item['stock_level'];
    $r = (float)($item['reorder_level'] ?? 24);
    $c = (float)($item['critical_level'] ?? 10);
    if ($s > $r) $kpi_available_stock++;
}

// ── Fetch Stock Movement History (Unified across logs, deliveries & sales) ──
$movement_history = [];
if ($active_tab === 'movement') {
    try {
        // Ensure columns exist on inventory_logs to prevent missing column errors
        foreach ([
            "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS station_id INT NULL",
            "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS product_id INT NULL",
            "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS user_id INT NULL",
            "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS action VARCHAR(100) NULL",
            "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS quantity_change DECIMAL(12,2) NOT NULL DEFAULT 0",
            "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS notes TEXT NULL",
            "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100) NULL",
            "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS reference_type VARCHAR(100) NULL",
            "ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS reference_id INT NULL"
        ] as $sql_alter) {
            try { $pdo->exec($sql_alter); } catch (Exception $ignored) {}
        }

        $query = "
            SELECT il.id AS log_id,
                   il.created_at,
                   COALESCE(NULLIF(il.action,''), 'Adjustment') AS movement_type,
                   il.quantity_change AS quantity,
                   COALESCE(NULLIF(il.notes,''), '—') AS notes,
                   COALESCE(
                       NULLIF(NULLIF(il.reference_no, ''), 'merchandise_transaction'),
                       NULLIF(CONCAT_WS('-', NULLIF(il.reference_type, 'merchandise_transaction'), il.reference_id), ''),
                       CONCAT('LOG-', LPAD(il.id, 5, '0'))
                   ) AS reference_no,
                   COALESCE(NULLIF(ip.product_name,''), NULLIF(p.name,''), 'Merchandise Item') AS product_name,
                   COALESCE(NULLIF(ip.sku,''), NULLIF(p.sku,''), CONCAT('P', LPAD(COALESCE(p.id, il.product_id, 0),4,'0')), '') AS sku,
                   COALESCE(NULLIF(si.unit,''), NULLIF(ip.size,''), 'pcs') AS unit,
                   COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(u.first_name, ' ', u.last_name),' '), u.username, 'System') AS user_name
            FROM inventory_logs il
            LEFT JOIN inventory_products ip ON ip.id = il.product_id
            LEFT JOIN products p ON p.id = il.product_id AND (ip.id IS NULL)
            LEFT JOIN station_inventory si ON si.product_id = il.product_id AND si.station_id = il.station_id
            LEFT JOIN users u ON u.id = il.user_id
            WHERE (il.station_id = ? OR il.station_id IS NULL OR il.station_id = 0)
              AND (ip.id IS NOT NULL OR p.id IS NOT NULL OR il.product_id IS NOT NULL)

            UNION ALL

            SELECT (1000000 + msi.id) AS log_id,
                   msi.encoded_at AS created_at,
                   'Stock In' AS movement_type,
                   msi.qty_received AS quantity,
                   COALESCE(NULLIF(msi.remarks,''), CONCAT('PO: ', COALESCE(msi.po_number,'—'), ' | Batch: ', COALESCE(msi.batch_ref,'—'))) AS notes,
                   COALESCE(NULLIF(msi.po_number, ''), NULLIF(msi.batch_ref, ''), CONCAT('SI-', LPAD(msi.id, 5, '0'))) AS reference_no,
                   COALESCE(NULLIF(msi.product_name,''), NULLIF(ip.product_name,''), NULLIF(p.name,''), 'Merchandise Item') AS product_name,
                   COALESCE(NULLIF(msi.sku,''), NULLIF(ip.sku,''), NULLIF(p.sku,''), CONCAT('P', LPAD(COALESCE(p.id, msi.product_id, 0),4,'0')), '') AS sku,
                   COALESCE(NULLIF(si.unit,''), NULLIF(ip.size,''), 'pcs') AS unit,
                   COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(u.first_name, ' ', u.last_name),' '), 'Staff') AS user_name
            FROM merchandise_stock_in msi
            LEFT JOIN inventory_products ip ON ip.id = msi.product_id
            LEFT JOIN products p ON p.id = msi.product_id AND (ip.id IS NULL)
            LEFT JOIN station_inventory si ON si.product_id = msi.product_id AND si.station_id = msi.station_id
            LEFT JOIN users u ON u.id = msi.encoded_by
            WHERE msi.station_id = ?
              AND msi.id NOT IN (
                  SELECT COALESCE(reference_id, 0) FROM inventory_logs WHERE (station_id = ? OR station_id IS NULL) AND (reference_type LIKE '%delivery%' OR reference_type LIKE '%stock_in%')
              )

            UNION ALL

            SELECT (2000000 + mti.id) AS log_id,
                   mt.created_at,
                   COALESCE(NULLIF(mt.transaction_type, ''), 'Stock Out') AS movement_type,
                   -ABS(mti.quantity) AS quantity,
                   COALESCE(NULLIF(mt.manager_notes,''), NULLIF(mt.staff_remarks,''), 'Sale Transaction') AS notes,
                   COALESCE(NULLIF(mt.transaction_id, ''), CONCAT('SO-', LPAD(mt.id, 5, '0'))) AS reference_no,
                   COALESCE(NULLIF(mti.product_name,''), NULLIF(ip.product_name,''), NULLIF(p.name,''), 'Merchandise Item') AS product_name,
                   COALESCE(NULLIF(mti.item_sku,''), NULLIF(ip.sku,''), NULLIF(p.sku,''), CONCAT('P', LPAD(COALESCE(p.id, mti.product_id, 0),4,'0')), '') AS sku,
                   COALESCE(NULLIF(si.unit,''), NULLIF(ip.size,''), 'pcs') AS unit,
                   COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(u.first_name, ' ', u.last_name),' '), 'Staff') AS user_name
            FROM merchandise_transactions mt
            JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
            LEFT JOIN inventory_products ip ON ip.id = mti.product_id
            LEFT JOIN products p ON p.id = mti.product_id AND (ip.id IS NULL)
            LEFT JOIN station_inventory si ON si.product_id = mti.product_id AND si.station_id = mt.station_id
            LEFT JOIN users u ON u.id = mt.staff_id
            WHERE mt.station_id = ?
              AND mt.id NOT IN (
                  SELECT COALESCE(reference_id, 0) FROM inventory_logs WHERE (station_id = ? OR station_id IS NULL) AND (reference_type LIKE '%transaction%' OR reference_type LIKE '%sale%')
              )
              AND mt.transaction_id NOT IN (
                  SELECT COALESCE(reference_no, '') FROM inventory_logs WHERE (station_id = ? OR station_id IS NULL) AND reference_no IS NOT NULL AND reference_no != ''
              )

            ORDER BY created_at DESC
            LIMIT 250
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $station_id,
            $station_id, $station_id,
            $station_id, $station_id, $station_id
        ]);
        $movement_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching movement history: " . $e->getMessage());
        try {
            $fallbackStmt = $pdo->prepare("
                SELECT (1000000 + msi.id) AS log_id,
                       msi.encoded_at AS created_at,
                       'Stock In' AS movement_type,
                       msi.qty_received AS quantity,
                       COALESCE(NULLIF(msi.remarks,''), 'Stock In Delivery') AS notes,
                       COALESCE(NULLIF(msi.po_number, ''), CONCAT('SI-', LPAD(msi.id, 5, '0'))) AS reference_no,
                       COALESCE(NULLIF(msi.product_name,''), 'Merchandise Item') AS product_name,
                       COALESCE(NULLIF(msi.sku,''), '') AS sku,
                       'pcs' AS unit,
                       'Staff' AS user_name
                FROM merchandise_stock_in msi
                WHERE msi.station_id = ?
                ORDER BY msi.encoded_at DESC LIMIT 100
            ");
            $fallbackStmt->execute([$station_id]);
            $movement_history = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $fallbackErr) {
            $movement_history = [];
        }
    }
}

// ── Fetch Stock-In History ──
$stockin_history = [];
if ($active_tab === 'stockin') {
    try {
        $stmt = $pdo->prepare("
            SELECT msi.id, msi.encoded_at AS date, msi.qty_received, msi.unit_cost, msi.batch_no,
                   msi.po_number, msi.status, msi.notes,
                   COALESCE(ip.product_name, p.name, 'Unknown') AS product_name,
                   COALESCE(ip.sku, CONCAT('P', LPAD(p.id,4,'0')), '') AS sku,
                   COALESCE(ip.category, pc.name, 'General') AS category_name,
                   COALESCE(u.name, 'Staff') AS received_by,
                   COALESCE(ip.brand, 'Petron Corporation') AS supplier
            FROM merchandise_stock_in msi
            LEFT JOIN inventory_products ip ON ip.id = msi.product_id
            LEFT JOIN products p ON p.id = msi.product_id AND (ip.id IS NULL)
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            LEFT JOIN users u ON u.id = msi.encoded_by
            WHERE msi.station_id = ?
            ORDER BY msi.encoded_at DESC
            LIMIT 200
        ");
        $stmt->execute([$station_id]);
        $stockin_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── Fetch Stock-Out History ──
$stockout_history = [];
if ($active_tab === 'stockout') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                CONCAT('SO-', LPAD(mt.id, 5, '0')) AS ref_no,
                mt.created_at AS date,
                mt.transaction_type,
                COALESCE(ip.product_name, p.name, 'Unknown') AS product_name,
                COALESCE(ip.sku, CONCAT('P', LPAD(p.id,4,'0')), '') AS sku,
                COALESCE(mti.quantity, 0) AS qty
            FROM merchandise_transactions mt
            JOIN merchandise_transaction_items mti ON mti.transaction_id = mt.id
            LEFT JOIN inventory_products ip ON ip.id = mti.product_id
            LEFT JOIN products p ON p.id = mti.product_id AND (ip.id IS NULL)
            WHERE mt.station_id = ?
              AND mt.transaction_type IN ('sale','stock_out','return','wastage')
            ORDER BY mt.created_at DESC
            LIMIT 200
        ");
        $stmt->execute([$station_id]);
        $stockout_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── Fetch Transfer Records ──
$transfer_records = [];
if ($active_tab === 'transfer') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                CONCAT('TR-', LPAD(il.id, 5, '0')) AS transfer_no,
                COALESCE(ip.product_name, 'Unknown') AS product_name,
                COALESCE(ip.sku, '') AS sku,
                COALESCE(il.notes, '—') AS from_location,
                COALESCE(NULLIF(CONCAT_WS(' ', il.reference_type, il.reference_id), ''), '—') AS to_location,
                ABS(il.quantity_change) AS qty,
                il.created_at AS date,
                COALESCE(u.name, 'Staff') AS performed_by
            FROM inventory_logs il
            LEFT JOIN inventory_products ip ON ip.id = il.product_id
            LEFT JOIN users u ON u.id = il.user_id
            WHERE il.station_id = ?
              AND LOWER(il.action) IN ('transfer','transfer_out','transfer_in')
            ORDER BY il.created_at DESC
            LIMIT 200
        ");
        $stmt->execute([$station_id]);
        $transfer_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── Fetch Damaged Items ──
$damaged_items = [];
if ($active_tab === 'damaged') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                CONCAT('DMG-', LPAD(il.id, 5, '0')) AS damage_no,
                COALESCE(ip.product_name, 'Unknown') AS product_name,
                COALESCE(ip.sku, '') AS sku,
                ABS(il.quantity_change) AS qty,
                COALESCE(il.notes, '—') AS reason,
                il.created_at AS date,
                COALESCE(u.name, 'Staff') AS performed_by
            FROM inventory_logs il
            LEFT JOIN inventory_products ip ON ip.id = il.product_id
            LEFT JOIN users u ON u.id = il.user_id
            WHERE il.station_id = ?
              AND LOWER(il.action) IN ('damage','damaged','defective','disposal')
            ORDER BY il.created_at DESC
            LIMIT 200
        ");
        $stmt->execute([$station_id]);
        $damaged_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── Fetch Expired Products ──
$expired_products = [];
if ($active_tab === 'expired') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(ip.product_name, 'Unknown') AS product_name,
                COALESCE(ip.sku, '') AS sku,
                COALESCE(mb.batch_number, msi.batch_ref, '—') AS batch,
                mb.created_at AS expiry_date,
                COALESCE(mb.remaining_qty, msi.qty_received, 0) AS qty
            FROM merchandise_batches mb
            LEFT JOIN inventory_products ip ON ip.id = mb.product_id
            LEFT JOIN merchandise_stock_in msi ON msi.product_id = mb.product_id AND msi.station_id = mb.station_id
            WHERE mb.station_id = ?
              AND mb.status = 'active'
            ORDER BY mb.id DESC
            LIMIT 200
        ");
        $stmt->execute([$station_id]);
        $expired_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

require_once __DIR__ . '/../partials/header.php';
?>

<!-- ══ GLOBAL SUCCESS TOAST (TOP-RIGHT) ══ -->
<div id="adminGlobalSuccessBanner">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
        <div style="display:flex; align-items:center; gap:10px;">
            <div style="width:32px; height:32px; border-radius:50%; background:#dcfce7; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <i class="fas fa-check-circle" style="font-size:17px; color:#16a34a;"></i>
            </div>
            <div style="font-size:13px; font-weight:800; color:#15803d; letter-spacing:0.2px; line-height:1.3;" id="adminBannerTitle">SUCCESSFUL!</div>
        </div>
        <button type="button" onclick="closeAdminSuccessBanner()" style="background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; line-height:1; padding:0 4px;" title="Close">&times;</button>
    </div>
    <div style="font-size:12px; color:#475569; padding-left:42px; line-height:1.5;" id="adminBannerText">Action completed successfully.</div>
</div>


<style>
/* Clean Merchandise Inventory Table Layout - Senior / Elderly Friendly Large Fonts & High Legibility */
html, body, .mim-wrap, .main-content, .card, .tbl-card, .table-wrap, .table-responsive {
    overflow-x: hidden !important;
    max-width: 100% !important;
    width: 100% !important;
}

.afto-tbl, #mgrMerchTable, #adminMerchTable, #adminMovTable, #adminAlertTable {
    table-layout: fixed !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    border-collapse: collapse !important;
}

.afto-tbl th, #mgrMerchTable th, #adminMerchTable th, #adminMovTable th, #adminAlertTable th {
    background: #002F70 !important;
    color: #ffffff !important;
    padding: 7px 3px !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
    letter-spacing: 0.2px !important;
    text-transform: uppercase !important;
    white-space: normal !important;
    word-break: normal !important;
    overflow-wrap: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
    border-bottom: 2px solid #001a3d !important;
    vertical-align: middle !important;
    line-height: 1.2 !important;
    text-align: center !important;
}

.afto-tbl td, #mgrMerchTable td, #adminMerchTable td {
    padding: 7px 4px !important;
    font-size: 13px !important;
    line-height: 1.3 !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #f1f5f9 !important;
    color: #334155 !important;
    overflow: visible !important;
    text-overflow: clip !important;
    white-space: normal !important;
}

/* ── Admin Merchandise Table (adminMerchTable) - Aligned with Manager Table ── */
#adminMerchTable,
table.merch-tbl {
    table-layout: fixed !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    border-collapse: collapse !important;
}

#adminMerchTable th,
#adminMerchTable td,
table.merch-tbl th,
table.merch-tbl td {
    overflow: hidden !important;
    max-width: 0 !important;
    box-sizing: border-box !important;
    vertical-align: middle !important;
}

#adminMerchTable thead th,
table.merch-tbl thead th {
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

#adminMerchTable tbody td,
table.merch-tbl tbody td {
    padding: 9px 8px !important;
    font-size: 13px !important;
    line-height: 1.35 !important;
    color: #0f172a !important;
    border-bottom: 1px solid #f1f5f9 !important;
}

/* Col 2 (Product & Category) & Col 4 (Stock Levels): clean multi-line wrapping */
#adminMerchTable td:nth-child(2),
table.merch-tbl td:nth-child(2),
#adminMerchTable td:nth-child(4),
table.merch-tbl td:nth-child(4) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    max-width: 0 !important;
}

/* Category header: Clean soft background, navy bold text */
#adminMerchTable tr.cat-header td,
.cat-header td {
    background: #f1f5f9 !important;
    font-weight: 800 !important;
    font-size: 13px !important;
    padding: 8px 12px !important;
    color: #002F70 !important;
    text-align: left !important;
    border-bottom: 1px solid #e2e8f0 !important;
}

/* Inv Stock Badge */
.inv-stock-badge {
    display: inline-block !important;
    padding: 4px 9px !important;
    border-radius: 6px !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

/* Fill bar styles */
.fill-bar-wrap {
    background: #e2e8f0 !important;
    border-radius: 3px !important;
    height: 6px !important;
    overflow: hidden !important;
    margin-bottom: 3px !important;
    width: 100% !important;
}
.fill-bar-inner {
    height: 100% !important;
    border-radius: 3px !important;
}

/* Movement Table Specific Styling - Explicit alignment matching each column */
#adminMovTable th {
    background: #002F70 !important;
    color: #ffffff !important;
    padding: 10px 10px !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
    letter-spacing: 0.3px !important;
    text-transform: uppercase !important;
    border-bottom: 2px solid #001a3d !important;
    vertical-align: middle !important;
    white-space: nowrap !important;
    box-sizing: border-box !important;
}

#adminMovTable td {
    padding: 10px 10px !important;
    font-size: 13px !important;
    line-height: 1.35 !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #f1f5f9 !important;
    color: #334155 !important;
    box-sizing: border-box !important;
}

/* Explicit per-column alignment matching headers and data perfectly */
#adminMovTable th:nth-child(1), #adminMovTable td:nth-child(1) { text-align: center !important; }
#adminMovTable th:nth-child(2), #adminMovTable td:nth-child(2) { text-align: center !important; }
#adminMovTable th:nth-child(3), #adminMovTable td:nth-child(3) { text-align: center !important; }
#adminMovTable th:nth-child(4), #adminMovTable td:nth-child(4) { text-align: left !important; }
#adminMovTable th:nth-child(5), #adminMovTable td:nth-child(5) { text-align: right !important; }
#adminMovTable th:nth-child(6), #adminMovTable td:nth-child(6) { text-align: left !important; }
#adminMovTable th:nth-child(7), #adminMovTable td:nth-child(7) { text-align: left !important; }
#adminMovTable th:nth-child(8), #adminMovTable td:nth-child(8) { text-align: left !important; }

/* Movement Type: No colored backgrounds, clean text */
.mov-type-txt {
    font-size: 13px !important;
    font-weight: 800 !important;
    color: #002F70 !important;
    text-transform: uppercase !important;
    background: transparent !important;
    background-color: transparent !important;
    border: none !important;
    letter-spacing: 0.3px !important;
    white-space: nowrap !important;
    padding: 0 !important;
}

/* Stock Alerts Table Specific Styling - Large & High Legibility for Senior Users */
#adminAlertTable th {
    background: #002F70 !important;
    color: #ffffff !important;
    padding: 10px 8px !important;
    font-size: 13px !important;
    font-weight: 800 !important;
    letter-spacing: 0.25px !important;
    text-transform: uppercase !important;
    border-bottom: 2px solid #001a3d !important;
    vertical-align: middle !important;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
}

#adminAlertTable td {
    padding: 10px 8px !important;
    font-size: 14px !important;
    line-height: 1.35 !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #f1f5f9 !important;
    color: #334155 !important;
    overflow: visible !important;
    text-overflow: clip !important;
    white-space: normal !important;
    word-break: break-word !important;
}
</style>

<style>
/* == GLOBAL OVERFLOW FIX (ELIMINATE DOUBLE VERTICAL SCROLLBAR) == */
html, body {
    overflow: hidden !important;
    height: 100% !important;
    max-width: 100vw !important;
}
.content-wrapper, .main-content {
    overflow-x: visible !important;
    overflow-y: visible !important;
    max-width: 100% !important;
    padding: 0 !important;
    box-sizing: border-box;
    width: 100%;
}
.table-wrap {
    overflow-x: hidden !important;
    overflow-y: visible !important;
    max-width: 100% !important;
    -webkit-overflow-scrolling: touch;
}

/* == PAGE HEADER - Uniform standard across all modules == */
.int-head {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin-top: 0 !important;
    margin-bottom: 25px !important;
    padding: 0 !important;
    border: none !important;
    width: 100%;
}
.int-head h1 {
    margin: 0 !important;
    color: #002f70 !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    line-height: 1.2 !important;
}
.int-head .sub {
    font-size: 15.5px;
    color: #666;
    margin-top: 4px;
    text-transform: none !important;
}

/* == SUMMARY CARDS == */
.afto-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.afto-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    position: relative;
    overflow: hidden;
    text-decoration: none !important;
}
.afto-card-info {
    display: flex;
    flex-direction: column;
}
.afto-card-lbl {
    font-size: 15.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
    text-decoration: none !important;
}
.afto-card-val {
    font-size: 18px;
    font-weight: 800;
    color: #1e293b;
    text-decoration: none !important;
}
.afto-card-icon {
    font-size: 22px;
    opacity: 0.85;
}

.afto-card.blue .afto-card-icon { color: #2563eb; }
.afto-card.green .afto-card-icon { color: #16a34a; }
.afto-card.yellow .afto-card-icon { color: #d97706; }
.afto-card.red .afto-card-icon { color: #dc2626; }
.afto-card.purple .afto-card-icon { color: #7c3aed; }
.afto-card.orange .afto-card-icon { color: #ea580c; }

/* == FILTER BAR == */
.afto-filter {
    display: grid;
    grid-template-columns: 1.8fr 1.1fr 1.1fr 0.9fr 1.1fr 0.9fr 0.9fr auto;
    align-items: flex-end;
    gap: 6px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.afto-fg {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.afto-fg label {
    font-size: 15.5px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    white-space: nowrap;
}
.afto-fg input, .afto-fg select {
    height: 36px;
    padding: 6px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 15.5px;
    color: #1e293b;
    background: #ffffff;
    outline: none;
    box-sizing: border-box;
    width: 100%;
}
.afto-fg input:focus, .afto-fg select:focus {
    border-color: var(--petron-blue, #00264D);
    box-shadow: 0 0 0 3px rgba(0, 38, 77, 0.1);
}
.afto-actions {
    display: flex;
    align-items: flex-end;
    gap: 4px;
    white-space: nowrap;
}
.afto-actions .flt-btn {
    height: 30px;
    padding: 0 10px;
    font-size: 14px;
}

.flt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 15.5px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .15s;
    background: white !important;
    border: 1px solid transparent;
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

/* ── Filter Controls Styled Exactly Like Fuel Inventory ── */
.filter-select,
.afto-fg select,
select.filter-select {
    height: 38px !important;
    padding: 6px 12px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    font-size: 14.5px !important;
    font-weight: 500 !important;
    color: #334155 !important;
    background: #ffffff !important;
    outline: none !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    width: 100% !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}
.filter-select:focus,
.afto-fg select:focus,
select.filter-select:focus {
    border-color: #002F70 !important;
    box-shadow: 0 0 0 2px rgba(0, 47, 112, 0.15) !important;
}

/* ── Guaranteed Downward Filter Dropdowns (Opens downward - Exact Fuel Inventory Style) ── */
.petron-dropdown-source {
    display: none !important;
}
.petron-dropdown-wrap {
    position: relative !important;
    display: inline-block !important;
    vertical-align: middle !important;
    box-sizing: border-box !important;
}
.afto-fg .petron-dropdown-wrap {
    display: block !important;
    width: 100% !important;
}
.petron-dropdown-wrap.is-open {
    z-index: 10050 !important;
}
.petron-dropdown-trigger {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    width: 100% !important;
    height: 38px !important;
    padding: 6px 12px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    background: #ffffff !important;
    color: #1e293b !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    font-family: inherit !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    outline: none !important;
    user-select: none !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}
.petron-dropdown-trigger:hover {
    border-color: #94a3b8 !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-trigger {
    border-color: #1967d2 !important;
    box-shadow: 0 0 0 2px rgba(25, 103, 210, 0.2) !important;
}
.petron-dropdown-label {
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    flex: 1 !important;
    text-align: left !important;
    color: #1e293b !important;
    font-size: 15px !important;
    font-weight: 500 !important;
}
.petron-dropdown-arrow {
    font-size: 11px !important;
    color: #475569 !important;
    margin-left: 8px !important;
    flex-shrink: 0 !important;
}
.petron-dropdown-menu {
    display: none !important;
    position: absolute !important;
    top: calc(100% + 2px) !important;
    bottom: auto !important;
    left: 0 !important;
    min-width: 100% !important;
    width: max-content !important;
    max-width: 340px !important;
    max-height: 260px !important;
    overflow-y: auto !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12) !important;
    z-index: 10050 !important;
    padding: 4px 0 !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-menu {
    display: block !important;
}
.petron-dropdown-menu::-webkit-scrollbar {
    width: 6px !important;
}
.petron-dropdown-menu::-webkit-scrollbar-track {
    background: #f8fafc !important;
}
.petron-dropdown-menu::-webkit-scrollbar-thumb {
    background: #cbd5e1 !important;
    border-radius: 4px !important;
}
.petron-dropdown-menu::-webkit-scrollbar-thumb:hover {
    background: #94a3b8 !important;
}
.petron-dropdown-item {
    padding: 7px 14px !important;
    font-size: 15px !important;
    color: #1e293b !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    line-height: 1.4 !important;
    font-weight: 400 !important;
    background: #ffffff !important;
    transition: background 0.05s, color 0.05s !important;
}
.petron-dropdown-item:hover,
.petron-dropdown-item.is-selected {
    background: #1967d2 !important;
    color: #ffffff !important;
    font-weight: 400 !important;
}

.filter-input,
.afto-fg input,
input.filter-input {
    height: 38px !important;
    padding: 6px 12px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    font-size: 15px !important;
    font-weight: 500 !important;
    color: #1e293b !important;
    background: #ffffff !important;
    outline: none !important;
    box-sizing: border-box !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}
.afto-fg input,
.afto-fg .filter-input {
    width: 100% !important;
}
.filter-input:focus,
.afto-fg input:focus,
input.filter-input:focus {
    border-color: #002F70 !important;
    box-shadow: 0 0 0 2px rgba(0, 47, 112, 0.15) !important;
}

.txn-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 6px 12px;
    border-radius: 5px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    line-height: 1;
    transition: all .18s;
    background: white !important;
    border: 1px solid transparent;
    text-decoration: none;
    box-sizing: border-box;
}
.txn-btn-info { color: #0284c7 !important; border-color: #0284c7 !important; }
.txn-btn-info:hover { background: #0284c7 !important; color: #fff !important; }
.btn-cancel {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 0 16px; border-radius: 6px; font-size: 15.5px; font-weight: 600;
    cursor: pointer; border: 1px solid #6b7280; background: white !important;
    color: #475569 !important; transition: all .15s; height: 36px;
}
.btn-cancel:hover { background: #6b7280 !important; color: #fff !important; }

/* == TABLE CARD == */
.tbl-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
    margin-bottom: 24px;
    max-width: 100%;
    min-height: 480px;
}
.tbl-hd {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid #e9ecef;
    flex-wrap: wrap;
    gap: 8px;
    background: #f8fafc;
}
.tbl-title {
    font-size: 14px;
    font-weight: 700;
    color: #00264D;
    display: flex;
    align-items: center;
    gap: 8px;
}
.afto-tbl {
    width: 100% !important;
    max-width: 100% !important;
    border-collapse: collapse !important;
    font-size: 10.5px !important;
    text-align: left;
    table-layout: fixed !important; min-width: 0 !important;
}
.afto-tbl th {
    background: #002F70 !important;
    color: #fff !important;
    padding: 7px 4px !important;
    font-size: 9.5px !important;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
    white-space: nowrap !important;
    overflow: hidden;
    text-overflow: ellipsis;
}
.afto-tbl td {
    padding: 5px 4px !important;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    font-size: 10.5px !important;
    overflow: hidden;
}
/* ── Tab Navigation - Reports-style boxed design ── */
.tab-nav {
    display: flex !important; flex-wrap: wrap !important;
    margin-bottom: 22px !important;
    border: 1px solid #d1d9e6 !important; border-radius: 0 !important;
    overflow: hidden !important; border-bottom: 3px solid #00264D !important;
    gap: 0 !important; background: transparent !important;
    padding: 0 !important; width: 100% !important;
}
.tab-btn {
    flex: 1 !important; min-width: 140px !important;
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
    border-bottom-color: transparent !important;
}
.afto-tbl thead tr {
    background: #002F70;
}
.afto-tbl thead th {
    padding: 7px 4px;
    font-weight: 700;
    color: #ffffff;
    text-transform: uppercase;
    letter-spacing: 0.2px;
    font-size: 14.5px;
    border-bottom: 2px solid #001a3d;
    white-space: nowrap;
}
.afto-tbl tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.1s ease;
}
.afto-tbl tbody tr:hover td {
    background: #f8fafc;
}
.afto-tbl tbody td {
    padding: 6px 4px;
    color: #334155;
    vertical-align: middle;
    white-space: normal;
    font-size: 13px;
    overflow: visible;
    text-overflow: clip;
}
.afto-tbl tbody td:last-child, .afto-tbl thead th:last-child {
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
    word-wrap: break-word !important;
}

.align-right { text-align: right; font-family: monospace; }
.align-center { text-align: center; }

.fill-bar-wrap { background:#e9ecef; border-radius:3px; height:5px; overflow:hidden; margin-bottom:2px; width:100%; }
.fill-bar-inner { height:100%; border-radius:3px; }
.mv-pos { color:#16a34a; font-weight:700; }
.mv-neg { color:#dc2626; font-weight:700; }
.mv-none { color:#94a3b8; }

.var-pos { color: #16a34a !important; font-weight: 700; }
.var-neg { color: #dc2626 !important; font-weight: 700; }
.var-zero { color: #64748b !important; font-weight: 600; }

.badge-lbl {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 15.5px;
    font-weight: 700;
    text-transform: uppercase;
    text-align: center;
    white-space: nowrap;
}
.bg-amber { background-color: #fef3c7; color: #b45309; }
.bg-green { background-color: #dcfce7; color: #15803d; }
.bg-red   { background-color: #fee2e2; color: #b91c1c; }
.bg-gray  { background-color: #f1f5f9; color: #475569; }

.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9000;
}
.modal-overlay.show,
.modal-overlay.open {
    display: flex;
}

/* ── FIFO Batch Table & View Product Modal Layout (Zero Horizontal Scroll) ── */
#adminViewProdModal,
#adminViewProdModal * {
    box-sizing: border-box !important;
}
#adminViewProdModal > div {
    max-width: 960px !important;
    width: 96% !important;
}
#adminViewProdModal #vpmBody {
    overflow-x: hidden !important;
    overflow-y: auto !important;
}
#adminViewProdModal #vpmPane1,
#adminViewProdModal #vpmPane2,
#adminViewProdModal #vpmPane3 {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
}
#adminViewProdModal #vpmFifoTable {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
}
#adminViewProdModal #vpmFifoTable table {
    width: 100% !important;
    max-width: 100% !important;
    table-layout: fixed !important;
    min-width: 0 !important;
    border-collapse: collapse !important;
}
#adminViewProdModal #vpmFifoTable th,
#adminViewProdModal #vpmFifoTable td {
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.modal-box {
    background: #ffffff;
    border-radius: 12px;
    padding: 24px;
    width: 650px;
    max-width: 95vw;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}
.po-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14.5px;
}
.po-table th {
    background: #f1f5f9;
    color: #475569;
    text-transform: uppercase;
    font-size: 15.5px;
    font-weight: 700;
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
}
.po-table td {
    padding: 8px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
}

/* â”€â”€ Tab Navigation â”€â”€ */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:22px; }
.tab-btn { padding:10px 24px; background:none; border:none; border-bottom:3px solid transparent; font-size:15.5px; font-weight:600; color:#64748b; cursor:pointer; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.tab-btn.active { color:#002F70; border-bottom-color:#002F70; }
.tab-btn:hover { color:#002F70 !important; background:#f8fafc !important; }

/* â”€â”€ Modal tab button overrides to prevent global button background â”€â”€ */
.modal-tab-btn {
    background: none !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 10px 16px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    border-bottom: 2px solid transparent !important;
    transition: color 0.15s, border-color 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.modal-tab-btn.active {
    border-bottom: 2px solid #002F70 !important;
    color: #002F70 !important;
    font-weight: 700 !important;
    background: transparent !important;
    background-color: transparent !important;
}
.modal-tab-btn:not(.active) {
    color: #64748b !important;
    background: transparent !important;
    background-color: transparent !important;
}
.modal-tab-btn:hover:not(.active) {
    color: #002F70 !important;
    border-bottom: 2px solid #c7d4ea !important;
    background: #f1f5f9 !important;
}

/* â”€â”€ Outline buttons (View, Print, Close) plain styles â”€â”€ */
.int-btn-outline {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 14.5px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid #002F70 !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    color: #002F70 !important;
    transition: all 0.2s;
    white-space: nowrap;
    text-decoration: none;
    box-shadow: none !important;
}
.int-btn-outline:hover {
    background: #002F70 !important;
    color: #ffffff !important;
}

/* == ACTION BUTTON STYLES (VERTICAL STACKING) == */
.act-btn-wrap {
    display: flex !important;
    flex-direction: column !important;
    gap: 3px !important;
    width: 100% !important;
    align-items: center !important;
    justify-content: center !important;
    box-sizing: border-box !important;
}
.act-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 5px !important;
    padding: 2px 8px !important;
    border-radius: 5px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    line-height: 1 !important;
    width: 100% !important;
    max-width: 80px !important;
    height: 25px !important;
    margin-bottom: 0 !important;
    transition: background .1s ease, color .1s ease, border-color .1s ease !important;
    background: #ffffff !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
    pointer-events: auto !important;
    user-select: none !important;
    touch-action: manipulation !important;
}
.act-btn * {
    pointer-events: none !important;
}
.act-btn:last-child { margin-bottom: 0 !important; }
.act-btn-view { color: #002F70 !important; border: 1.5px solid #002F70 !important; background: #ffffff !important; }
.act-btn-view:hover { background: #002F70 !important; color: #ffffff !important; }
.act-btn-edit { color: #16a34a !important; border: 1.5px solid #16a34a !important; background: #ffffff !important; }
.act-btn-edit:hover { background: #16a34a !important; color: #ffffff !important; }
.act-btn-adjust { color: #64748b !important; border: 1.5px solid #64748b !important; background: #ffffff !important; }
.act-btn-adjust:hover { background: #64748b !important; color: #ffffff !important; }

/* ── Global Success Toast Banner (Top-Right) ── */
@keyframes slideInRight {
    from { opacity: 0; transform: translateX(60px); }
    to { opacity: 1; transform: translateX(0); }
}
#adminGlobalSuccessBanner {
    position: fixed;
    top: 76px;
    right: 24px;
    z-index: 99999;
    max-width: 380px;
    min-width: 290px;
    background: #ffffff;
    color: #1e293b;
    padding: 14px 18px;
    border-radius: 10px;
    border-left: 4px solid #16a34a;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15), 0 3px 8px rgba(0,0,0,0.08);
    display: none;
    flex-direction: column;
    gap: 5px;
    animation: slideInRight 0.3s cubic-bezier(.22,.68,0,1.2);
    transition: opacity 0.25s ease, transform 0.25s ease;
}


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
</style>

<div class="main-content">
<!-- Page Header -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-boxes"></i> Merchandise Inventory Management</h1>
    </div>
</div>

<!-- Tab Navigation -->
<div class="tab-nav">
    <a href="admin_inventory_merchandise.php?<?= http_build_query(array_merge($_GET, ['tab' => 'overview'])) ?>"
       class="tab-btn <?= $active_tab === 'overview' ? 'active' : '' ?>">
        <i class="fas fa-boxes"></i> Inventory Overview
    </a>
    <a href="admin_inventory_merchandise.php?tab=movement"
       class="tab-btn <?= $active_tab === 'movement' ? 'active' : '' ?>">
        <i class="fas fa-exchange-alt"></i> Stock Movement Monitoring
    </a>
    <a href="admin_inventory_merchandise.php?tab=alerts"
       class="tab-btn <?= $active_tab === 'alerts' ? 'active' : '' ?>">
        <i class="fas fa-exclamation-triangle"></i> Stock Alerts
        <?php if (($kpi_low_stock + $kpi_out_of_stock + $kpi_expired_stock) > 0): ?>
            <span style="background:#dc2626 !important;color:#fff !important;border-radius:10px;padding:1px 8px;font-size:12px;font-weight:700;line-height:1;"><?= ($kpi_low_stock + $kpi_out_of_stock + $kpi_expired_stock) ?></span>
        <?php endif; ?>
    </a>
</div>

<?php if ($active_tab === 'overview'): ?>

<!-- Summary Cards (Matches Master Data Requests Design & Size) -->
<div class="txn-kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
    <!-- Card 1: Total Products -->
    <div onclick="filterAdminByCard('all')" class="txn-kpi-card blue" style="cursor:pointer;" title="Click to show All Products">
        <div class="txn-kpi-lbl"><i class="fas fa-boxes" style="color:#0284c7;margin-right:4px;"></i> Total Products</div>
        <div class="txn-kpi-val"><?= number_format($kpi_total_products) ?></div>
    </div>
    <!-- Card 2: Total Inventory -->
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-cubes" style="color:#002F70;margin-right:4px;"></i> Total Inventory</div>
        <div class="txn-kpi-val"><?= number_format($kpi_total_stock) ?></div>
    </div>
    <!-- Card 3: Total Inventory Value -->
    <div class="txn-kpi-card teal">
        <div class="txn-kpi-lbl"><i class="fas fa-peso-sign" style="color:#0d9488;margin-right:4px;"></i> Total Inventory Value</div>
        <div class="txn-kpi-val" style="font-size:22px !important;">₱<?= number_format($kpi_total_value, 2) ?></div>
    </div>
    <!-- Card 4: Low Stock Items -->
    <div onclick="filterAdminByCard('low')" class="txn-kpi-card orange" style="cursor:pointer;" title="Click to filter low stock items">
        <div class="txn-kpi-lbl"><i class="fas fa-exclamation-triangle" style="color:#d97706;margin-right:4px;"></i> Low Stock Items</div>
        <div class="txn-kpi-val"><?= number_format($kpi_low_stock) ?></div>
    </div>
    <!-- Card 5: Out of Stock -->
    <div onclick="filterAdminByCard('out')" class="txn-kpi-card dark-danger" style="cursor:pointer;" title="Click to filter out of stock items">
        <div class="txn-kpi-lbl"><i class="fas fa-times-circle" style="color:#991b1b;margin-right:4px;"></i> Out of Stock</div>
        <div class="txn-kpi-val"><?= number_format($kpi_out_of_stock) ?></div>
    </div>
    <!-- Card 6: Total Stock Movements Today -->
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-chart-line" style="color:#16a34a;margin-right:4px;"></i> Movements Today</div>
        <div class="txn-kpi-val"><?= number_format($stock_movements_today) ?></div>
    </div>
    <!-- Card 7: Pending Stock Adjustments -->
    <div class="txn-kpi-card yellow">
        <div class="txn-kpi-lbl"><i class="fas fa-clock" style="color:#d97706;margin-right:4px;"></i> Pending Adjustments</div>
        <div class="txn-kpi-val"><?= number_format($pending_adjustments_count) ?></div>
    </div>
</div>
<!-- Filter Bar -->
<form method="GET" action="admin_inventory_merchandise.php" class="afto-filter">
    <div class="afto-fg">
        <label for="search_query">Search Product</label>
        <input type="text" name="search_query" id="search_query" placeholder="Search SKU, name, brand..." value="<?= htmlspecialchars($search_query) ?>">
    </div>
    
    <div class="afto-fg">
        <label for="category">Category</label>
        <select name="category" id="category" class="filter-select">
            <option value="all">All Categories</option>
            <?php foreach ($all_categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>" <?= ($category_filter === $cat) ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="afto-fg">
        <label for="brand">Brand</label>
        <select name="brand" id="brand" class="filter-select">
            <option value="all">All Brands</option>
            <?php foreach ($all_brands as $b): ?>
            <option value="<?= htmlspecialchars($b) ?>" <?= ($brand_filter === $b) ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
            <?php endforeach; ?>
        </select>
    </div>


    <div class="afto-fg">
        <label for="unit">UOM</label>
        <select name="unit" id="unit" class="filter-select">
            <option value="all">All UOMs</option>
            <?php foreach ($all_units as $u): ?>
            <option value="<?= htmlspecialchars($u) ?>" <?= ($unit_filter === $u) ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="afto-fg">
        <label for="status_filter">Status</label>
        <select name="status_filter" id="status_filter" class="filter-select">
            <option value="all" <?= ($status_filter === 'all' || $status_filter === '') ? 'selected' : '' ?>>All Statuses</option>
            <option value="available" <?= $status_filter === 'available' ? 'selected' : '' ?>>Available</option>
            <option value="low" <?= $status_filter === 'low' ? 'selected' : '' ?>>Low Stock</option>
            <option value="out" <?= in_array($status_filter, ['out','out of stock'], true) ? 'selected' : '' ?>>Out of Stock</option>
            <option value="expired" <?= $status_filter === 'expired' ? 'selected' : '' ?>>Expired</option>
            <option value="variance detected" <?= in_array($status_filter, ['variance','variance detected'], true) ? 'selected' : '' ?>>Variance Detected</option>
            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>

    <div class="afto-fg">
        <label for="date_from">Updated From</label>
        <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>" style="font-size: 14px;">
    </div>

    <div class="afto-fg">
        <label for="date_to">Updated To</label>
        <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>" style="font-size: 14px;">
    </div>
    
    <div class="afto-actions">
        <button type="submit" class="flt-btn flt-btn-search"><i class="fas fa-filter"></i> Filter</button>
        <a href="admin_inventory_merchandise.php" class="flt-btn flt-btn-reset"><i class="fas fa-sync-alt"></i> Reset</a>
    </div>
</form>

<script>
// Fast immediate availability for Adjust modal (prevents any click delay)
window._adminPendingAdj = null;
window.openAdminAdjustModal = function(item) {
    window._adminPendingAdj = item;
    if (typeof window._execOpenAdminAdjustModal === 'function') {
        window._execOpenAdminAdjustModal(item);
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof window._execOpenAdminAdjustModal === 'function') {
                window._execOpenAdminAdjustModal(window._adminPendingAdj);
            }
        });
    }
};
</script>

<!-- Table Card -->
<div class="tbl-card">
    <div class="tbl-hd">
        <div class="tbl-title"><i class="fas fa-clipboard-list"></i> Merchandise Stock Records</div>
    </div>
    <div class="table-wrap" style="width:100% !important;max-width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;">
        <table class="table report-table no-min-width print-table merch-tbl afto-tbl" id="adminMerchTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
            <colgroup>
                <col style="width:11%;"><!-- ITEM IDENTIFIERS -->
                <col style="width:24%;"><!-- PRODUCT & CATEGORY -->
                <col style="width:11%;"><!-- EXPIRATION -->
                <col style="width:16%;"><!-- STOCK LEVELS -->
                <col style="width:12%;"><!-- STATUS -->
                <col style="width:14%;"><!-- LAST UPDATED -->
                <col style="width:12%;"><!-- ACTIONS -->
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
            <tbody id="adminMerchTableBody">
            <?php if (empty($sorted_filtered)): ?>
                <tr class="no-paginate">
                    <td colspan="7" class="align-center" style="padding: 24px; color: #64748b; text-align:center;">
                        <i class="fas fa-box-open" style="font-size: 24px; margin-bottom: 8px; display:block;"></i>
                        No merchandise inventory records matched your filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($sorted_filtered as $cat_label => $items): ?>
                    <tr class="cat-header no-paginate">
                        <td colspan="7" style="background:#f1f5f9;font-weight:800;font-size:13px;padding:8px 12px;color:#002F70;text-align:left;">
                            <i class="fas fa-folder" style="color:#2563eb;margin-right:6px;"></i><?= htmlspecialchars($cat_label) ?>
                        </td>
                    </tr>
                    <?php foreach ($items as $item): 
                        $stock    = (float)$item['stock_level'];
                        $reorder  = (float)($item['reorder_level'] ?? 24);
                        if ($reorder  <= 0) $reorder  = 24;
                        $critical = (float)($item['critical_level'] ?? 10);
                        if ($critical <= 0) $critical = 10;
                        $capacity = max(480.0, (float)($item['capacity'] ?? 480));
                        $unit     = htmlspecialchars(format_product_unit_display($item['unit'] ?? 'pcs', $item['name'] ?? '', $item['category_name'] ?? ''));
                        $variance = $item['variance'] ?? null;
                        $has_variance = ($variance !== null && (float)$variance != 0);

                        $pid_item = (int)$item['id'];
                        $pname_norm = strtolower(trim((string)($item['name'] ?? '')));
                        $stock_in_qty = (float)($prod_added_map_id[$pid_item] ?? $prod_added_map_name[$pname_norm] ?? 0);

                        $fill_pct = $capacity > 0 ? min(100, ($stock / $capacity) * 100) : 0;
                        $batch_id = !empty($item['batch_ref']) ? $item['batch_ref'] : (!empty($item['batch_number']) ? $item['batch_number'] : ('B' . str_pad((string)$pid_item, 3, '0', STR_PAD_LEFT)));
                        $exp_date = 'N/A';
                        $exp_raw = null;
                        if (!empty($item['expiration_date']) && $item['expiration_date'] !== '0000-00-00') {
                            $exp_raw = $item['expiration_date'];
                            $exp_date = date('M d, Y', strtotime($exp_raw));
                        } elseif (!empty($item['date_received'])) {
                            $exp_raw = $item['date_received'];
                            $exp_date = date('M d, Y', strtotime($exp_raw));
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
                            } catch (Exception $e) { $exp_date = 'Jul 20, 2029'; }
                        }
                        // Determine expiration alert status
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
                        $initial_qty = $stock_in_qty > 0 ? (int)$stock_in_qty : (int)$capacity;

                        if ($stock <= 0) {
                            $st = 'OUT OF STOCK'; $sc = '#dc3545'; $si_cls = 'out of stock';
                        } elseif ($stock <= $critical) {
                            $st = 'CRITICAL STOCK'; $sc = '#dc3545'; $si_cls = 'critical';
                        } elseif ($stock <= $reorder) {
                            $st = 'LOW STOCK'; $sc = '#fd7e14'; $si_cls = 'low';
                        } else {
                            $st = 'AVAILABLE'; $sc = '#28a745'; $si_cls = 'available';
                        }

                        if ($has_variance) {
                            $st = 'VARIANCE DETECTED'; $sc = '#fd7e14';
                            $si_cls = 'variance detected';
                        }

                        $timestamp_date = '—';
                        $timestamp_time = '';
                        if (!empty($item['last_updated'])) {
                            try {
                                $dt_up = new DateTime($item['last_updated']);
                                $timestamp_date = $dt_up->format('M d, Y');
                                $timestamp_time = $dt_up->format('h:i A');
                            } catch (Exception $e) {}
                        }
                    ?>
                    <tr class="merch-row"
                        data-id="<?= (int)$item['id'] ?>"
                        data-name="<?= htmlspecialchars(strtolower($item['name'])) ?>"
                        data-sku="<?= htmlspecialchars(strtolower($item['sku'] ?? '')) ?>"
                        data-category="<?= htmlspecialchars(strtolower($item['category_name'] ?? '')) ?>"
                        data-brand="<?= htmlspecialchars(strtolower($item['brand'] ?? '')) ?>"
                        data-supplier="<?= htmlspecialchars(strtolower($item['supplier'] ?? 'petron corporation')) ?>"
                        data-unit="<?= htmlspecialchars(strtolower($unit)) ?>"
                        data-status="<?= $exp_status === 'expired' ? 'expired' : htmlspecialchars(strtolower($st)) ?>"
                        data-status-key="<?= $exp_status === 'expired' ? 'expired' : htmlspecialchars(strtolower($item['computed_status'] ?? '')) ?>"
                        data-has-variance="<?= $has_variance ? 'true' : 'false' ?>"
                        data-date="<?= !empty($item['last_updated']) ? date('Y-m-d', strtotime($item['last_updated'])) : '' ?>">

                        <!-- 1. ITEM IDENTIFIERS -->
                        <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
                            <div style="font-family:monospace;font-size:12.5px;font-weight:700;color:#002F70;white-space:nowrap;" title="Batch ID">
                                <i class="fas fa-layer-group" style="font-size:10.5px;color:#2563eb;margin-right:2px;"></i> <?= htmlspecialchars($batch_id) ?>
                            </div>
                            <div style="font-family:monospace;font-size:11.5px;font-weight:700;color:#4f46e5;margin-top:2px;white-space:nowrap;" title="SKU">
                                <?= htmlspecialchars($item['sku'] ?: '—') ?>
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

                        <!-- 3. EXPIRATION -->
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
                                <div class="fill-bar-inner" style="width:<?= min(100, round($fill_pct)) ?>%;background:<?= $sc ?>;height:100%;"></div>
                            </div>
                            <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:4px;">
                                <span style="font-size:13px;font-weight:800;color:#0f172a;white-space:nowrap;"><?= number_format($stock, 0) ?> <small style="font-size:11px;color:#64748b;font-weight:600;"><?= $unit ?></small></span>
                                <span style="font-size:11px;color:#64748b;white-space:nowrap;font-weight:600;">Reorder: <strong style="color:#ea580c;"><?= number_format($reorder, 0) ?></strong></span>
                            </div>
                            <div style="font-size:10.5px;color:#64748b;margin-top:2px;white-space:nowrap;">Init: <?= number_format($initial_qty) ?></div>
                        </td>

                        <!-- 5. STATUS -->
                        <td style="padding:9px 6px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;text-align:center;">
                            <span class="inv-stock-badge" style="background:<?= $sc ?>20;color:<?= $sc ?>;border:1.5px solid <?= $sc ?>50;padding:4px 9px;border-radius:6px;font-size:11px;font-weight:800;text-transform:uppercase;white-space:nowrap;display:inline-block;">
                                <?= htmlspecialchars($st) ?>
                            </span>
                        </td>

                        <!-- 6. LAST UPDATED -->
                        <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
                            <?php if ($timestamp_date !== '—'): ?>
                                <div style="font-size:12px;font-weight:700;color:#1e293b;white-space:nowrap;"><?= $timestamp_date ?></div>
                                <div style="font-size:11px;color:#64748b;font-weight:600;margin-top:2px;white-space:nowrap;"><?= $timestamp_time ?></div>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- 7. ACTIONS -->
                        <td style="padding:6px 6px;overflow:visible !important;box-sizing:border-box;text-align:center;vertical-align:middle;">
                            <div class="act-btn-wrap">
                                <button type="button" class="act-btn act-btn-view"
                                    onclick='adminViewProduct(<?= htmlspecialchars(json_encode([
                                        "id" => $item["id"],
                                        "sku" => $item["sku"],
                                        "name" => $item["name"],
                                        "category_name" => $item["category_name"],
                                        "brand" => $item["brand"] ?? "Petron",
                                        "supplier" => "Petron Corporation",
                                        "unit" => $item["unit"],
                                        "barcode" => $item["barcode"] ?? "",
                                        "stock_level" => $item["stock_level"],
                                        "reorder_level" => $item["reorder_level"],
                                        "critical_level" => $item["critical_level"],
                                        "price" => $item["price"],
                                        "cost" => $item["cost"],
                                        "capacity" => $item["capacity"],
                                        "computed_status" => $item["computed_status"],
                                        "expiration_date" => $exp_date,
                                        "exp_status" => $exp_status
                                    ]), ENT_QUOTES) ?>)'>
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button type="button" class="act-btn act-btn-edit"
                                    onclick='openAdminEditModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES) ?>)'>
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button type="button" class="act-btn act-btn-adjust"
                                    onclick='openAdminAdjustModal(<?= htmlspecialchars(json_encode([
                                        "id"              => $item["id"],
                                        "name"            => $item["name"],
                                        "sku"             => $item["sku"],
                                        "category_name"   => $item["category_name"],
                                        "unit"            => $item["unit"],
                                        "stock_level"     => $item["stock_level"],
                                        "expiration_date" => $exp_date,
                                        "exp_status"      => $exp_status
                                    ]), ENT_QUOTES) ?>)'
                                    title="Direct Stock Adjustment">
                                    <i class="fas fa-sliders-h"></i> Adjust
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Overview Pagination Footer -->
    <div id="adminMerchPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 10px 10px; font-size:13.5px; color:#475569; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center;">
            <span id="adminMerchShowingText" style="font-size:13.5px; color:#475569; font-weight:700;">Showing entries…</span>
        </div>
        <div style="display:flex; align-items:center; gap:16px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="margin:0; font-weight:700; color:#475569; font-size:13.5px;">Rows per page:</label>
                <select id="adminMerchPerPage" onchange="adminMerchChangePerPage()" style="padding:5px 9px; border:1px solid #cbd5e1; border-radius:6px; font-size:13.5px; font-weight:700; background:transparent !important; color:#1e293b; outline:none; cursor:pointer;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <button id="adminMerchPrevBtn" onclick="adminMerchGoPage(adminMerchState.page - 1)"
                        style="width:34px; height:34px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <span id="adminMerchPageLabel" style="color:#1e293b; font-size:13.5px; font-weight:700; padding:0 4px;">Page 1 of 1</span>
                <button id="adminMerchNextBtn" onclick="adminMerchGoPage(adminMerchState.page + 1)"
                        style="width:34px; height:34px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; color:#475569; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
    <div id="adminMerchPagination" style="display:none;"></div>
</div>
<?php endif; ?>

<!-- â•â• TAB: STOCK MOVEMENT MONITORING â•â• -->
<?php if ($active_tab === 'movement'): ?>
<div class="tbl-card">
    <div class="tbl-hd">
        <div class="tbl-title"><i class="fas fa-exchange-alt"></i> Stock Movement Monitoring</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:nowrap;">
            <input type="text" id="adminMovSearchInput" placeholder="Search product, ref, user..." oninput="filterAdminMovTable()" class="filter-input" style="width:240px;height:38px;">
            <select id="adminMovTypeFilter" onchange="filterAdminMovTable()" class="filter-select" style="width:200px;min-width:180px;">
                <option value="">All Movement Types</option>
                <option value="stock in">Stock In</option>
                <option value="stock out">Stock Out</option>
                <option value="adjustment">Adjustment</option>
                <option value="transfer">Transfer</option>
                <option value="damaged">Damaged</option>
                <option value="expired">Expired</option>
            </select>
            <button type="button" class="flt-btn flt-btn-reset" onclick="resetAdminMovFilters()" style="height:38px;padding:0 12px;font-size:14px;"><i class="fas fa-rotate-left"></i> Reset</button>
        </div>
    </div>
    <div class="table-wrap" style="overflow-x:hidden; width:100%;">
        <table class="afto-tbl" id="adminMovTable" style="width:100%; max-width:100%; table-layout:fixed; min-width: 0; border-collapse:collapse;">
            <colgroup>
                <col style="width:13%;"> <!-- Date -->
                <col style="width:14%;"> <!-- Reference No. -->
                <col style="width:9%;">  <!-- Type -->
                <col style="width:21%;"> <!-- Product -->
                <col style="width:8%;">  <!-- Quantity -->
                <col style="width:11%;"> <!-- Performed By -->
                <col style="width:12%;"> <!-- Branch -->
                <col style="width:12%;"> <!-- Remarks -->
            </colgroup>
            <thead>
                <tr>
                    <th style="text-align:center;">Date</th>
                    <th style="text-align:center;">Reference No.</th>
                    <th style="text-align:center;">Type</th>
                    <th style="text-align:left;">Product</th>
                    <th style="text-align:right;">Quantity</th>
                    <th style="text-align:left;">Performed By</th>
                    <th style="text-align:left;">Branch</th>
                    <th style="text-align:left;">Remarks</th>
                </tr>
            </thead>
            <tbody id="adminMovBody">
            <?php if (empty($movement_history)): ?>
                <tr class="no-paginate"><td colspan="8" class="align-center" style="padding:24px;color:#64748b;text-align:center;"><i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No movement records found.</td></tr>
            <?php else: ?>
                <tr id="adminMovNoMatchRow" class="no-paginate" style="display:none;">
                    <td colspan="8" style="text-align:center;padding:36px 20px;color:#64748b;font-size:14px;font-weight:600;">
                        <i class="fas fa-search" style="font-size:28px;display:block;margin-bottom:10px;color:#94a3b8;"></i>
                        <span id="adminMovNoMatchMsg">No movement records found matching your search.</span>
                    </td>
                </tr>
                <?php foreach ($movement_history as $log):
                    $m_date = !empty($log['created_at']) ? date('M d, Y h:i A', strtotime($log['created_at'])) : '—';
                    $m_raw  = strtolower($log['movement_type'] ?? '');
                    $ref_no = !empty($log['reference_no']) ? trim($log['reference_no']) : ('LOG-' . str_pad($log['log_id'] ?? 0, 5, '0', STR_PAD_LEFT));
                    if ($ref_no === 'merchandise_transaction' || strpos($ref_no, 'merchandise_transaction') !== false) {
                        if (!empty($log['notes']) && preg_match('/(?:Ref:\s*|Reference:\s*)([A-Za-z0-9\-_]+)/i', $log['notes'], $m)) {
                            $ref_no = $m[1];
                        } elseif (!empty($log['notes']) && preg_match('/(MERCH[0-9]+)/i', $log['notes'], $m)) {
                            $ref_no = $m[1];
                        } elseif (!empty($log['reference_id'])) {
                            $ref_no = 'MT-' . str_pad($log['reference_id'], 5, '0', STR_PAD_LEFT);
                        } else {
                            $ref_no = 'LOG-' . str_pad($log['log_id'] ?? 0, 5, '0', STR_PAD_LEFT);
                        }
                    }
                    $qty    = (float)($log['quantity'] ?? 0);
                    $unit   = htmlspecialchars($log['unit'] ?? 'pcs');
                    $user_name = htmlspecialchars($log['user_name'] ?? 'System');
                    $branch = htmlspecialchars($station_name ?: 'Main Station');

                    if (strpos($m_raw, 'in') !== false || strpos($m_raw, 'delivery') !== false || strpos($m_raw, 'receive') !== false) {
                        $type_label = 'Stock In';
                        $qty_text = '+' . number_format(abs($qty), 0);
                        $qty_color = '#15803d';
                    } elseif (strpos($m_raw, 'out') !== false || strpos($m_raw, 'sale') !== false || strpos($m_raw, 'release') !== false) {
                        $type_label = 'Stock Out';
                        $qty_text = '-' . number_format(abs($qty), 0);
                        $qty_color = '#dc2626';
                    } elseif (strpos($m_raw, 'transfer') !== false) {
                        $type_label = 'Transfer';
                        $qty_text = number_format($qty, 0);
                        $qty_color = '#0284c7';
                    } elseif (strpos($m_raw, 'damage') !== false || strpos($m_raw, 'defective') !== false) {
                        $type_label = 'Damaged';
                        $qty_text = '-' . number_format(abs($qty), 0);
                        $qty_color = '#dc2626';
                    } elseif (strpos($m_raw, 'expire') !== false) {
                        $type_label = 'Expired';
                        $qty_text = '-' . number_format(abs($qty), 0);
                        $qty_color = '#d97706';
                    } else {
                        $type_label = 'Adjustment';
                        $qty_text = ($qty >= 0 ? '+' : '') . number_format($qty, 0);
                        $qty_color = $qty >= 0 ? '#15803d' : '#dc2626';
                    }
                ?>
                <tr class="mov-row" data-search="<?= strtolower(htmlspecialchars($log['product_name'] . ' ' . $ref_no . ' ' . ($log['user_name'] ?? '') . ' ' . $type_label)) ?>" data-type="<?= strtolower($type_label) ?>" data-raw-type="<?= strtolower(htmlspecialchars($log['movement_type'] ?? '')) ?>">
                    <td style="font-size:13.5px;color:#475569;font-weight:600;text-align:center;white-space:nowrap;"><?= $m_date ?></td>
                    <td style="text-align:center;"><code style="font-size:13px;font-weight:700;color:#002F70;word-break:break-all;line-height:1.25;display:inline-block;"><?= htmlspecialchars($ref_no) ?></code></td>
                    <td style="text-align:center;">
                        <span class="mov-type-txt" style="font-size:13px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:0.3px;white-space:nowrap;background:transparent;border:none;">
                            <?= $type_label ?>
                        </span>
                    </td>
                    <td style="word-break:break-word;overflow-wrap:break-word;line-height:1.35;">
                        <strong style="font-size:14px;color:#002F6C;display:block;"><?= htmlspecialchars($log['product_name']) ?></strong>
                        <code style="font-size:12.5px;color:#64748b;font-weight:600;"><?= htmlspecialchars($log['sku']) ?></code>
                    </td>
                    <td style="text-align:right;font-weight:800;font-size:14px;color:<?= $qty_color ?>;white-space:nowrap;"><?= $qty_text ?> <?= $unit ?></td>
                    <td style="font-size:13.5px;font-weight:600;color:#1e293b;word-break:break-word;"><?= htmlspecialchars($log['user_name'] ?? 'System') ?></td>
                    <td style="font-size:13px;color:#334155;word-break:break-word;line-height:1.35;"><?= $branch ?></td>
                    <td style="font-size:13px;color:#475569;word-break:break-word;line-height:1.35;"><?= htmlspecialchars($log['notes'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Stock Movement Pagination Footer -->
    <div id="adminMovPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 10px 10px; font-size:13.5px; color:#475569; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center;">
            <span id="adminMovShowingText" style="font-size:13.5px; color:#475569; font-weight:700;">Showing entries…</span>
        </div>
        <div style="display:flex; align-items:center; gap:16px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="margin:0; font-weight:700; color:#475569; font-size:13.5px;">Rows per page:</label>
                <select id="adminMovPerPage" onchange="adminMovChangePerPage()" style="padding:5px 9px; border:1px solid #cbd5e1; border-radius:6px; font-size:13.5px; font-weight:700; background:transparent !important; color:#1e293b; outline:none; cursor:pointer;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <button id="adminMovPrevBtn" onclick="adminMovGoPage(adminMovState.page - 1)"
                        style="width:34px; height:34px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <span id="adminMovPageLabel" style="color:#1e293b; font-size:13.5px; font-weight:700; padding:0 4px;">Page 1 of 1</span>
                <button id="adminMovNextBtn" onclick="adminMovGoPage(adminMovState.page + 1)"
                        style="width:34px; height:34px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; color:#475569; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
    <div id="adminMovPagination" style="display:none;"></div>
</div>
<?php endif; ?>

<!-- ════ TAB: STOCK ALERTS ════ -->
<?php if ($active_tab === 'alerts'): ?>
<?php
$alert_rows = array_filter($all_items, function($i) {
    $status = strtolower($i['computed_status'] ?? '');
    return in_array($status, ['low', 'critical', 'out', 'expired'], true) || ($i['exp_status'] ?? '') === 'expired';
});
$total_alerts_count = count($alert_rows);
?>

<div class="txn-kpi-grid">
    <div class="txn-kpi-card orange">
        <div class="txn-kpi-lbl"><i class="fas fa-exclamation-triangle" style="color:#d97706;margin-right:4px;"></i> Low Stock Items</div>
        <div class="txn-kpi-val"><?= number_format($kpi_low_stock) ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-fire" style="color:#dc2626;margin-right:4px;"></i> Critical Stock Items</div>
        <div class="txn-kpi-val"><?= number_format($kpi_critical_stock) ?></div>
    </div>
    <div class="txn-kpi-card dark-danger">
        <div class="txn-kpi-lbl"><i class="fas fa-times-circle" style="color:#991b1b;margin-right:4px;"></i> Out of Stock</div>
        <div class="txn-kpi-val"><?= number_format($kpi_out_of_stock) ?></div>
    </div>
    <div class="txn-kpi-card danger" style="background:#fee2e2;border-color:#ef4444;">
        <div class="txn-kpi-lbl" style="color:#991b1b;"><i class="fas fa-ban" style="color:#dc2626;margin-right:4px;"></i> Expired Stock</div>
        <div class="txn-kpi-val" style="color:#dc2626;"><?= number_format($kpi_expired_stock) ?></div>
    </div>
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-bell" style="color:#0284c7;margin-right:4px;"></i> Total Active Alerts</div>
        <div class="txn-kpi-val"><?= number_format($total_alerts_count) ?></div>
    </div>
</div>

<div class="tbl-card">
    <div class="tbl-hd">
        <div class="tbl-title" style="font-size:16px;font-weight:800;"><i class="fas fa-exclamation-triangle" style="color:#dc2626;"></i> Active Stock Alerts Catalog</div>
        <div style="display:flex;align-items:center;gap:10px;">
            <input type="text" id="adminAlertSearchInput" placeholder="Search alert products..." oninput="filterAdminAlertTable()" class="filter-input" style="width:240px;">
        </div>
    </div>
    <div class="table-wrap" style="overflow-x:hidden; width:100%;">
        <table class="afto-tbl" id="adminAlertTable" style="width:100%; max-width:100%; table-layout:fixed; min-width: 0; border-collapse:collapse;">
            <colgroup>
                <col style="width:12%;"> <!-- SKU -->
                <col style="width:27%;"> <!-- Product Name -->
                <col style="width:14%;"> <!-- Category -->
                <col style="width:13%;"> <!-- Current Stock -->
                <col style="width:12%;"> <!-- Reorder Level -->
                <col style="width:11%;"> <!-- Status -->
                <col style="width:11%;"> <!-- Actions -->
            </colgroup>
            <thead>
                <tr>
                    <th style="text-align:center;">SKU</th>
                    <th style="text-align:left;">Product Name</th>
                    <th style="text-align:center;">Category</th>
                    <th style="text-align:right;">Current Stock</th>
                    <th style="text-align:right;">Reorder Level</th>
                    <th style="text-align:center;">Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="adminAlertBody">
            <?php if (empty($alert_rows)): ?>
                <tr class="no-paginate"><td colspan="7" class="align-center" style="padding:32px;color:#64748b;text-align:center;"><i class="fas fa-check-circle" style="font-size:28px;display:block;margin-bottom:8px;color:#16a34a;"></i>All stock levels are healthy! No active alerts found.</td></tr>
            <?php else: ?>
                <tr id="adminAlertNoMatchRow" class="no-paginate" style="display:none;">
                    <td colspan="7" style="text-align:center;padding:36px 20px;color:#64748b;font-size:14px;font-weight:600;">
                        <i class="fas fa-search" style="font-size:28px;display:block;margin-bottom:10px;color:#94a3b8;"></i>
                        <span id="adminAlertNoMatchMsg">No alert products found matching your search.</span>
                    </td>
                </tr>
                <?php foreach ($alert_rows as $item):
                    $stock   = (float)$item['stock_level'];
                    $reorder = (float)($item['reorder_level'] ?? 24);
                    $unit    = htmlspecialchars($item['unit'] ?? 'pcs');
                    $st_lbl  = getStatusLabel($item['computed_status'] ?? 'available');
                    $st_cls  = getStatusBadgeClass($item['computed_status'] ?? 'available');
                    $item_json = htmlspecialchars(json_encode($item), ENT_QUOTES);
                ?>
                <tr class="alert-row" data-search="<?= strtolower(htmlspecialchars($item['name'] . ' ' . $item['sku'] . ' ' . ($item['category_name'] ?? ''))) ?>">
                    <td style="text-align:center;padding:10px 8px;"><code style="font-size:14px;font-weight:700;color:#002F70;"><?= htmlspecialchars($item['sku']) ?></code></td>
                    <td style="padding:10px 8px;word-break:break-word;line-height:1.35;"><strong style="font-size:15px;color:#002F6C;display:block;"><?= htmlspecialchars($item['name']) ?></strong></td>
                    <td style="text-align:center;padding:10px 8px;color:#334155;font-weight:600;font-size:14px;word-break:break-word;"><?= htmlspecialchars($item['category_name'] ?? 'General') ?></td>
                    <td style="text-align:right;padding:10px 8px;font-weight:800;font-size:15.5px;color:<?= $stock <= 0 ? '#dc2626' : '#002F70' ?>;white-space:nowrap;"><?= number_format($stock, 0) ?> <?= $unit ?></td>
                    <td style="text-align:right;padding:10px 8px;font-weight:700;font-size:14.5px;color:#ea580c;white-space:nowrap;"><?= number_format($reorder, 0) ?> <?= $unit ?></td>
                    <td style="text-align:center;padding:10px 8px;"><span class="badge-lbl <?= $st_cls ?>" style="font-size:12.5px !important;padding:4px 10px !important;font-weight:800 !important;"><?= htmlspecialchars($st_lbl) ?></span></td>
                    <td style="text-align:center;padding:10px 8px;">
                        <button type="button" class="int-btn-outline" onclick='adminViewProduct(<?= $item_json ?>)' style="padding:6px 14px;font-size:13.5px;font-weight:700;border-radius:6px;gap:6px;"><i class="fas fa-eye"></i> View</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Stock Alerts Pagination Footer -->
    <div id="adminAlertPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 10px 10px; font-size:13.5px; color:#475569; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center;">
            <span id="adminAlertShowingText" style="font-size:13.5px; color:#475569; font-weight:700;">Showing entries…</span>
        </div>
        <div style="display:flex; align-items:center; gap:16px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="margin:0; font-weight:700; color:#475569; font-size:13.5px;">Rows per page:</label>
                <select id="adminAlertPerPage" onchange="adminAlertChangePerPage()" style="padding:5px 9px; border:1px solid #cbd5e1; border-radius:6px; font-size:13.5px; font-weight:700; background:transparent !important; color:#1e293b; outline:none; cursor:pointer;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <button id="adminAlertPrevBtn" onclick="adminAlertGoPage(adminAlertState.page - 1)"
                        style="width:34px; height:34px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <span id="adminAlertPageLabel" style="color:#1e293b; font-size:13.5px; font-weight:700; padding:0 4px;">Page 1 of 1</span>
                <button id="adminAlertNextBtn" onclick="adminAlertGoPage(adminAlertState.page + 1)"
                        style="width:34px; height:34px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:pointer; color:#475569; display:flex; align-items:center; justify-content:center; transition:all 0.2s;"
                        onmouseover="if(!this.disabled)this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
    <div id="adminAlertPagination" style="display:none;"></div>
</div>
<?php endif; ?>

<!-- ════ VIEW PRODUCT MODAL (WITH SUB-TABS) ════ -->
<div class="modal-overlay" id="adminViewProdModal" style="z-index:10000;">
    <div style="background:#fff;border-radius:14px;width:96%;max-width:980px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 40px rgba(0,0,0,.18);overflow:hidden;position:relative;z-index:10001;box-sizing:border-box;">
        <!-- Header -->
        <div style="padding:16px 22px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;flex-shrink:0;">
            <div style="font-size:15px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-box-open"></i> <span id="vpmTitle">View Product</span>
            </div>
        </div>
        <!-- Sub-tabs inside modal -->
        <div style="display:flex;border-bottom:2px solid #e2e8f0;background:#f8fafc;flex-shrink:0;padding:0 16px;overflow-x:hidden;white-space:nowrap;gap:4px;">
            <button type="button" class="modal-tab-btn active" id="vpmTab1" onclick="vpmSwitchTab(1)"><i class="fas fa-info-circle"></i> Product Information</button>
            <button type="button" class="modal-tab-btn" id="vpmTab2" onclick="vpmSwitchTab(2)"><i class="fas fa-chart-pie"></i> Inventory Summary</button>
            <button type="button" class="modal-tab-btn" id="vpmTab3" onclick="vpmSwitchTab(3)"><i class="fas fa-layer-group"></i> Batch Inventory</button>
        </div>
        <!-- Body -->
        <div style="overflow-y:auto;overflow-x:hidden !important;flex:1;padding:22px;box-sizing:border-box;" id="vpmBody">
            <!-- SUB-TAB 1: Product Information -->
            <div id="vpmPane1">
                <div id="vpmAlertNotice"></div>
                <div style="font-size:14px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-info-circle"></i> Product Details</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;margin-bottom:20px;">
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">SKU</div><div id="vpmSku" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Product Name</div><div id="vpmName" style="font-weight:700;color:#1e293b;font-size:15px;word-break:break-word;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Category</div><div id="vpmCategory" style="font-weight:500;color:#1e293b;font-size:15px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Brand</div><div id="vpmBrand" style="font-weight:500;color:#1e293b;font-size:15px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Supplier</div><div id="vpmSupplier" style="font-weight:500;color:#1e293b;font-size:15px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Unit of Measure</div><div id="vpmUnit" style="font-weight:500;color:#1e293b;font-size:15px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Barcode</div><div id="vpmBarcode" style="font-family:monospace;font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Expiration Date</div><div id="vpmExpiry" style="font-weight:700;font-size:15px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Status</div><div id="vpmStatus"></div></div>
                </div>
            </div>
            <!-- SUB-TAB 2: Inventory Summary -->
            <div id="vpmPane2" style="display:none;">
                <div style="font-size:14px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-boxes"></i> Stock &amp; Valuation Summary</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;margin-bottom:20px;">
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Current Stock</div><div id="vpmCurrentStock" style="font-weight:700;color:#1e293b;font-size:17px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Available Stock</div><div id="vpmAvailableStock" style="font-weight:700;color:#1e293b;font-size:17px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Reorder Level</div><div id="vpmReorderLevel" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Critical Level</div><div id="vpmCriticalLevel" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Unit Cost</div><div id="vpmCost" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                    <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Selling Price</div><div id="vpmPrice" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                    <div style="grid-column:span 2;"><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Total Inventory Value</div><div id="vpmInventoryValue" style="font-weight:700;color:#1e293b;font-size:18px;"></div></div>
                </div>
            </div>
            <!-- SUB-TAB 3: Batch Inventory -->
            <div id="vpmPane3" style="display:none;width:100%;box-sizing:border-box;">
                <div style="font-size:14px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-layer-group"></i> Batch Inventory Breakdown</div>
                <div id="vpmFifoTable" style="width:100%;overflow-x:hidden !important;box-sizing:border-box;"><div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
            </div>
        </div>
        <!-- Footer -->
        <div style="padding:12px 22px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;background:#ffffff;flex-shrink:0;">
            <button type="button" onclick="closeAdminViewProdModal()" style="background:transparent !important;background-color:transparent !important;background-image:none !important;color:#475569 !important;-webkit-text-fill-color:#475569 !important;border:1.5px solid #cbd5e1 !important;padding:8px 22px;border-radius:6px;font-size:14.5px;font-weight:700 !important;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:none !important;transition:all 0.15s ease;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#475569'">
                <i class="fas fa-times" style="color:inherit !important;-webkit-text-fill-color:inherit !important;"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- ════ VIEW MOVEMENT MODAL ════ -->
<div class="modal-overlay" id="adminViewMovModal" style="z-index:10005;">
    <div style="background:#fff;border-radius:14px;width:96%;max-width:520px;box-shadow:0 24px 40px rgba(0,0,0,.22);overflow:hidden;position:relative;z-index:10006;">
        <div style="padding:16px 22px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;">
            <div style="font-size:15px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-exchange-alt"></i> Stock Movement Details
            </div>
        </div>
        <div style="padding:22px;font-size:15px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Date / Time</div><div id="vmmDate" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Movement Type</div><div id="vmmType"></div></div>
            </div>
            <div style="margin-bottom:14px;"><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Product Name</div><div id="vmmProduct" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Batch / Ref No.</div><div id="vmmRef" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Quantity</div><div id="vmmQty" style="font-weight:700;font-size:16px;"></div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Remaining Stock</div><div id="vmmRemaining" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Performed By</div><div id="vmmBy" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
            </div>
            <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Notes / Remarks</div><div id="vmmNotes" style="color:#475569;font-size:14.5px;font-style:italic;"></div></div>
        </div>
        <div style="padding:12px 22px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;background:#f8fafc;">
            <button type="button" onclick="closeAdminViewMovModal()" class="int-btn-outline" style="border-color:#6b7280;color:#6b7280;">Close</button>
        </div>
    </div>
</div>

<!-- ════ VIEW STOCK-IN MODAL ════ -->
<div class="modal-overlay" id="adminViewSiModal" style="z-index:10005;">
    <div style="background:#fff;border-radius:14px;width:96%;max-width:540px;box-shadow:0 24px 40px rgba(0,0,0,.22);overflow:hidden;position:relative;z-index:10006;">
        <div style="padding:16px 22px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;">
            <div style="font-size:15px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-truck-loading"></i> Stock-In Record Details
            </div>
        </div>
        <div style="padding:22px;font-size:15px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Stock-In No.</div><div id="vsimSiNo" style="font-weight:700;color:#1e293b;font-size:15px;"></div></div>
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">PO Number</div><div id="vsimPo" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
            </div>
            <div style="margin-bottom:14px;"><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Product Name</div><div id="vsimProduct" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Supplier</div><div id="vsimSupplier" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Delivery Date</div><div id="vsimDate" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #e2e8f0;">
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Received Qty</div><div id="vsimQty" style="font-weight:700;color:#1e293b;font-size:15px;"></div></div>
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Unit Cost</div><div id="vsimCost" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Batch No.</div><div id="vsimBatch" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Received By</div><div id="vsimBy" style="font-weight:600;color:#1e293b;font-size:15px;"></div></div>
                <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Status</div><div id="vsimStatus"></div></div>
            </div>
            <div><div style="font-size:12.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Notes</div><div id="vsimNotes" style="color:#475569;font-size:14.5px;font-style:italic;"></div></div>
        </div>
        <div style="padding:12px 22px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;background:#f8fafc;">
            <button type="button" onclick="closeAdminViewSiModal()" class="int-btn-outline" style="border-color:#6b7280;color:#6b7280;">Close</button>
        </div>
    </div>
</div>

<!-- ════ EDIT PRODUCT MODAL (ADMIN) ════ -->
<div class="modal-overlay" id="adminEditProdModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;">
    <div class="modal-box" style="max-width:620px; width:95%; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.25);">
        <div class="modal-head" style="background:#002F70; padding:16px 20px; color:#fff; display:flex; align-items:center; justify-content:space-between;">
            <div class="modal-title" style="font-size:16px; font-weight:700; color:#fff !important; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-edit"></i> Edit Product Information
            </div>
        </div>
        <form method="post" action="admin_inventory_merchandise.php" style="padding:20px;">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            <input type="hidden" name="product_id" id="adminEditProdId">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label style="display:block; font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Product Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="product_name" id="adminEditProdName" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15px; font-weight:600; color:#1e293b;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Category</label>
                    <input type="text" id="adminEditProdCategory" readonly style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px; font-size:15px; background:#f8fafc; color:#64748b;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Unit of Measure <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="unit" id="adminEditProdUnit" required placeholder="e.g. pcs, Liter, Bottle" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15px; font-weight:500; color:#1e293b;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Reorder Level <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="reorder_level" id="adminEditProdReorder" min="0" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15px; font-weight:500; color:#1e293b;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Critical Level <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="critical_level" id="adminEditProdCritical" min="0" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15px; font-weight:500; color:#1e293b;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Max Capacity <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="capacity" id="adminEditProdCapacity" min="1" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15px; font-weight:500; color:#1e293b;">
                </div>
                <div class="form-group">
                    <label style="display:block; font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Selling Price (₱) <span style="color:#dc2626;">*</span></label>
                    <input type="number" step="0.01" name="price" id="adminEditProdPrice" min="0" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15px; font-weight:500; color:#1e293b;">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label style="display:block; font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Unit Cost (₱)</label>
                    <input type="number" step="0.01" name="cost" id="adminEditProdCost" min="0" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15px; font-weight:500; color:#1e293b;">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #e2e8f0; padding-top:16px;">
                <button type="button" onclick="closeAdminEditModal()" style="padding:8px 20px; border:1.5px solid #00264D !important; background:#ffffff !important; color:#00264D !important; border-radius:6px; font-size:15.5px; font-weight:700; cursor:pointer;">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#002F70 !important; color:#fff !important; padding:8px 20px; border:none; border-radius:6px; font-size:15.5px; font-weight:700; cursor:pointer;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ════ ADMIN DIRECT ADJUSTMENT MODAL (matches staff design) ════ -->
<div class="modal-overlay" id="adminAdjModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:15000; align-items:center; justify-content:center; box-sizing:border-box; overflow-y:auto;">
    <div class="modal-box" style="background:#fff; border-radius:14px; width:92% !important; max-width:520px !important; max-height:calc(100vh - 80px) !important; margin:auto; box-shadow:0 20px 50px rgba(0,0,0,0.35); overflow:hidden; display:flex; flex-direction:column; position:relative;">

        <!-- Header — gradient same as staff -->
        <div style="background:linear-gradient(135deg,#002F70,#001838); padding:16px 22px; color:#fff; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
            <div style="font-size:16px; font-weight:700; display:flex; align-items:center; gap:10px;">
                <i class="fas fa-sliders-h" style="color:#fd7e14;"></i>
                Direct Stock Adjustment
            </div>
        </div>

        <form id="adminAdjForm" onsubmit="submitAdminAdjustForm(event)" style="display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; margin:0;">
            <input type="hidden" id="adminAdjProductId">

            <div style="padding:16px 22px; overflow-y:auto; overflow-x:hidden !important; flex:1; min-height:0; box-sizing:border-box;">

                <!-- Product Information Card -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-bottom:18px;">
                    <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">
                        <i class="fas fa-info-circle" style="color:#002F70;"></i> Product Information
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:12px;">
                        <div><span style="color:#64748b;">SKU:</span> <code id="adminAdjDispSku" style="font-weight:700; color:#002F70;">—</code></div>
                        <div><span style="color:#64748b;">Category:</span> <span id="adminAdjDispCategory" style="color:#334155;">—</span></div>
                        <div style="grid-column:1/-1;"><span style="color:#64748b;">Product Name:</span> <strong id="adminAdjDispName" style="color:#0f172a;">—</strong></div>
                        <div><span style="color:#64748b;">Current Stock:</span> <strong id="adminAdjDispStock" style="color:#16a34a; font-size:13px;">0</strong> <span id="adminAdjDispUom" style="color:#64748b;">pcs</span></div>
                        <div><span style="color:#64748b;">Expiration:</span> <span id="adminAdjDispExp" style="color:#334155;">N/A</span></div>
                    </div>
                </div>

                <!-- Adjustment Details heading -->
                <div style="font-size:12px; font-weight:700; color:#0f172a; margin-bottom:12px;">
                    <i class="fas fa-sliders" style="color:#fd7e14;"></i> Adjustment Details
                </div>

                <!-- Adjustment Type -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">
                        Adjustment Type <span style="color:#dc2626;">*</span>
                    </label>
                    <select id="adminAdjType" name="adjustment_type" onchange="handleAdminAdjTypeChange()" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#0f172a;">
                        <option value="">-- Select Adjustment Type --</option>
                        <option value="Damaged Product">Damaged Product</option>
                        <option value="Expired Product">Expired Product</option>
                        <option value="Physical Count">Physical Count</option>
                        <option value="Missing Item">Missing Item</option>
                        <option value="Returned Item">Returned Item</option>
                        <option value="Encoding Error">Encoding Error</option>
                        <option value="Stock Correction">Stock Correction</option>
                        <option value="Others">Others</option>
                    </select>
                </div>

                <!-- Adjustment Action Display -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">
                        Adjustment Action
                    </label>
                    <input type="hidden" id="adminAdjAction" name="adjustment_action" value="Decrease">
                    <div id="adminAdjActionDisplay" style="min-height:36px; display:flex; align-items:center;">
                        <div id="adminAdjActionBadge" style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#dc2626; font-weight:800; font-size:13px;">
                            <i class="fas fa-minus-circle" id="adminAdjActionIcon"></i>
                            <span id="adminAdjActionLabel">Inventory Adjustment (Decrease)</span>
                        </div>
                    </div>

                    <!-- Manual direction for flexible types -->
                    <div id="adminAdjManualDir" style="display:none; margin-top:8px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                        <label style="display:block; font-size:11px; font-weight:700; color:#002F70; margin-bottom:4px;">
                            <i class="fas fa-arrows-alt-v"></i> Select Stock Action Direction:
                        </label>
                        <select id="adminAdjManualDirection" onchange="updateAdminAdjDetection()" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; font-weight:700; color:#0f172a;">
                            <option value="Decrease">Inventory Adjustment (Decrease)</option>
                            <option value="Increase">Inventory Adjustment (Increase)</option>
                        </select>
                    </div>
                </div>

                <!-- Quantity -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label id="adminAdjQtyLabel" style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">
                        Quantity <span style="color:#dc2626;">*</span>
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="number" id="adminAdjQuantity" name="quantity" min="0" step="1" required oninput="updateAdminAdjPreview()" placeholder="Enter quantity..." style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:14px; font-weight:700; color:#0f172a;">
                        <span id="adminAdjQtyUnit" style="font-size:12px; font-weight:600; color:#475569;">pcs</span>
                    </div>
                    <small id="adminAdjQtyError" style="color:#dc2626; font-size:11px; margin-top:3px; display:none; font-weight:600;"></small>
                </div>

                <!-- Hidden reason -->
                <input type="hidden" id="adminAdjReason" name="reason">

                <!-- Remarks -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">
                        Remarks <span style="color:#64748b; font-weight:normal; font-size:11px;">(Optional)</span>
                    </label>
                    <textarea id="adminAdjRemarks" name="remarks" rows="3" placeholder="Add any optional notes or details here..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-family:inherit; box-sizing:border-box;"></textarea>
                </div>

                <!-- Result/Error message -->
                <div id="adminAdjBanner" style="display:none; padding:8px 12px; border-radius:6px; font-size:13px; font-weight:600; margin-bottom:4px;"></div>
            </div>

            <!-- Footer -->
            <div style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:16px 24px; display:flex; justify-content:flex-end; gap:12px; flex-shrink:0;">
                <button type="button" onclick="closeAdminAdjustModal()" class="txn-btn muted">Cancel</button>
                <button type="submit" id="adminAdjSubmitBtn" class="txn-btn" style="background:#002F70!important; color:#fff!important; border-color:#002F70!important;">
                    <i class="fas fa-check-circle"></i> Apply Adjustment Now
                </button>
            </div>
        </form>
    </div>
</div>

<script>
var _adminCurrentProd = null;

function openAdminEditModal(item) {
    if (!item) return;
    var modal = document.getElementById('adminEditProdModal');
    if (!modal) return;
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }

    document.getElementById('adminEditProdId').value = item.id || '';
    document.getElementById('adminEditProdName').value = item.name || '';
    document.getElementById('adminEditProdCategory').value = item.category_name || item.category || 'General';
    document.getElementById('adminEditProdUnit').value = item.unit || 'pcs';
    document.getElementById('adminEditProdReorder').value = parseFloat(item.reorder_level) || 24;
    document.getElementById('adminEditProdCritical').value = parseFloat(item.critical_level) || 10;
    document.getElementById('adminEditProdCapacity').value = parseFloat(item.capacity) || 480;
    document.getElementById('adminEditProdPrice').value = parseFloat(item.price) || 0;
    document.getElementById('adminEditProdCost').value = parseFloat(item.cost) || 0;

    modal.classList.add('open');
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.right = '0';
    modal.style.bottom = '0';
    modal.style.zIndex = '10000';
}

function closeAdminEditModal() {
    var modal = document.getElementById('adminEditProdModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

// ── Admin Direct Adjustment Modal (Immediate, High-Performance) ──
var _adminAdjItem = null;

window._execOpenAdminAdjustModal = function(item) {
    if (!item) return;
    _adminAdjItem = item;

    var modal = document.getElementById('adminAdjModal');
    if (!modal) return;

    // Reset fields
    var typeEl = document.getElementById('adminAdjType');
    if (typeEl) typeEl.value = '';
    var qtyEl = document.getElementById('adminAdjQuantity');
    if (qtyEl) qtyEl.value = '';
    var remEl = document.getElementById('adminAdjRemarks');
    if (remEl) remEl.value = '';
    var rsnEl = document.getElementById('adminAdjReason');
    if (rsnEl) rsnEl.value = '';
    var actEl = document.getElementById('adminAdjAction');
    if (actEl) actEl.value = 'Decrease';
    var banEl = document.getElementById('adminAdjBanner');
    if (banEl) banEl.style.display = 'none';
    var sbtn = document.getElementById('adminAdjSubmitBtn');
    if (sbtn) {
        sbtn.disabled = false;
        sbtn.innerHTML = '<i class="fas fa-check-circle"></i> Apply Adjustment Now';
    }
    var mdir = document.getElementById('adminAdjManualDir');
    if (mdir) mdir.style.display = 'none';
    var qerr = document.getElementById('adminAdjQtyError');
    if (qerr) qerr.style.display = 'none';
    var qlbl = document.getElementById('adminAdjQtyLabel');
    if (qlbl) qlbl.innerHTML = 'Quantity <span style="color:#dc2626">*</span>';

    // Default action display — decrease
    var badge = document.getElementById('adminAdjActionBadge');
    var icon  = document.getElementById('adminAdjActionIcon');
    var label = document.getElementById('adminAdjActionLabel');
    if (badge) {
        badge.style.cssText = 'display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#dc2626; font-weight:800; font-size:13px;';
    }
    if (icon) icon.className = 'fas fa-minus-circle';
    if (label) label.textContent = 'Inventory Adjustment (Decrease)';

    // Populate product info
    var pidEl = document.getElementById('adminAdjProductId');
    if (pidEl) pidEl.value = item.id || '';
    var nameEl = document.getElementById('adminAdjDispName');
    if (nameEl) nameEl.textContent = item.name || '—';
    var skuEl = document.getElementById('adminAdjDispSku');
    if (skuEl) skuEl.textContent = item.sku || '—';
    var catEl = document.getElementById('adminAdjDispCategory');
    if (catEl) catEl.textContent = item.category_name || '—';
    var stkEl = document.getElementById('adminAdjDispStock');
    if (stkEl) stkEl.textContent = parseInt(item.stock_level) || 0;
    var uomEl = document.getElementById('adminAdjDispUom');
    if (uomEl) uomEl.textContent = item.unit || 'pcs';
    var quomEl = document.getElementById('adminAdjQtyUnit');
    if (quomEl) quomEl.textContent = item.unit || 'pcs';
    var expEl = document.getElementById('adminAdjDispExp');
    if (expEl) expEl.textContent = item.expiration_date || 'N/A';

    // Show modal immediately
    modal.classList.add('open');
    modal.classList.add('show');
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.right = '0';
    modal.style.bottom = '0';
    modal.style.zIndex = '15000';
    document.body.style.overflow = 'hidden';
};
window.openAdminAdjustModal = window._execOpenAdminAdjustModal;

function closeAdminAdjustModal() {
    var modal = document.getElementById('adminAdjModal');
    if (modal) {
        modal.classList.remove('open');
        modal.classList.remove('show');
        modal.style.display = 'none';
    }
    document.body.style.overflow = '';
    _adminAdjItem = null;
}

function handleAdminAdjTypeChange() {
    var typeEl = document.getElementById('adminAdjType');
    var type = typeEl ? typeEl.value : '';
    var manualDir = document.getElementById('adminAdjManualDir');
    var badge = document.getElementById('adminAdjActionBadge');
    var icon = document.getElementById('adminAdjActionIcon');
    var label = document.getElementById('adminAdjActionLabel');
    var qtyLabel = document.getElementById('adminAdjQtyLabel');
    var reasonEl = document.getElementById('adminAdjReason');
    var actEl = document.getElementById('adminAdjAction');

    if (reasonEl) reasonEl.value = type;
    if (qtyLabel) qtyLabel.innerHTML = 'Quantity <span style="color:#dc2626">*</span>';
    if (manualDir) manualDir.style.display = 'none';

    var decreaseTypes = ['Damaged Product', 'Expired Product', 'Missing Item'];
    var increaseTypes = ['Returned Item'];
    var manualTypes = ['Encoding Error', 'Stock Correction', 'Others'];

    if (decreaseTypes.includes(type)) {
        if (actEl) actEl.value = 'Decrease';
        if (badge) badge.style.cssText = 'display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#dc2626; font-weight:800; font-size:13px;';
        if (icon) icon.className = 'fas fa-minus-circle';
        if (label) label.textContent = 'Inventory Adjustment (Decrease)';
    } else if (increaseTypes.includes(type)) {
        if (actEl) actEl.value = 'Increase';
        if (badge) badge.style.cssText = 'display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; color:#16a34a; font-weight:800; font-size:13px;';
        if (icon) icon.className = 'fas fa-plus-circle';
        if (label) label.textContent = 'Inventory Adjustment (Increase)';
    } else if (type === 'Physical Count') {
        if (actEl) actEl.value = 'Set';
        if (badge) badge.style.cssText = 'display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; color:#1d4ed8; font-weight:800; font-size:13px;';
        if (icon) icon.className = 'fas fa-clipboard-check';
        if (label) label.textContent = 'Set to Exact Count';
        if (qtyLabel) qtyLabel.innerHTML = 'Exact Stock Count <span style="color:#dc2626">*</span>';
    } else if (manualTypes.includes(type)) {
        if (manualDir) manualDir.style.display = 'block';
        updateAdminAdjDetection();
    } else {
        if (actEl) actEl.value = 'Decrease';
        if (badge) badge.style.cssText = 'display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#dc2626; font-weight:800; font-size:13px;';
        if (icon) icon.className = 'fas fa-minus-circle';
        if (label) label.textContent = 'Inventory Adjustment (Decrease)';
    }
}

function updateAdminAdjDetection() {
    var dirEl = document.getElementById('adminAdjManualDirection');
    var dir = dirEl ? dirEl.value : 'Decrease';
    var badge = document.getElementById('adminAdjActionBadge');
    var icon = document.getElementById('adminAdjActionIcon');
    var label = document.getElementById('adminAdjActionLabel');
    var actEl = document.getElementById('adminAdjAction');

    if (actEl) actEl.value = dir;

    if (dir === 'Decrease') {
        if (badge) badge.style.cssText = 'display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#dc2626; font-weight:800; font-size:13px;';
        if (icon) icon.className = 'fas fa-minus-circle';
        if (label) label.textContent = 'Inventory Adjustment (Decrease)';
    } else {
        if (badge) badge.style.cssText = 'display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; color:#16a34a; font-weight:800; font-size:13px;';
        if (icon) icon.className = 'fas fa-plus-circle';
        if (label) label.textContent = 'Inventory Adjustment (Increase)';
    }
}

function updateAdminAdjPreview() {}

function submitAdminAdjustForm(e) {
    if (e) e.preventDefault();

    var typeEl = document.getElementById('adminAdjType');
    var type = typeEl ? typeEl.value : '';
    var actEl = document.getElementById('adminAdjAction');
    var adjAct = actEl ? actEl.value : 'Decrease';
    var qtyEl = document.getElementById('adminAdjQuantity');
    var qty = qtyEl ? parseFloat(qtyEl.value) : 0;
    var remEl = document.getElementById('adminAdjRemarks');
    var remarks = remEl ? remEl.value.trim() : '';
    var pidEl = document.getElementById('adminAdjProductId');
    var prodId = pidEl ? pidEl.value : '';
    var banner = document.getElementById('adminAdjBanner');
    var errEl = document.getElementById('adminAdjQtyError');

    if (banner) banner.style.display = 'none';
    if (errEl) errEl.style.display = 'none';

    if (!type) {
        if (banner) {
            banner.style.cssText = 'display:block; padding:8px 12px; border-radius:6px; font-size:13px; font-weight:600; background:#fef2f2; color:#dc2626; border:1px solid #fecaca;';
            banner.textContent = 'Please select an adjustment type.';
        }
        return;
    }
    if (isNaN(qty) || qty <= 0) {
        if (errEl) {
            errEl.textContent = 'Please enter a valid quantity greater than zero.';
            errEl.style.display = 'block';
        }
        return;
    }

    var submitBtn = document.getElementById('adminAdjSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Applying…';
    }

    fetch(window.location.pathname + '?action=admin_direct_adjust', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            product_id: parseInt(prodId),
            adjustment_type: type,
            adjustment_action: adjAct,
            quantity: qty,
            reason: type,
            remarks: remarks
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Apply Adjustment Now';
        }

        if (res.success) {
            // Immediately close modal — do not show success message inside the modal
            closeAdminAdjustModal();

            // Save message to sessionStorage so it persists across page reload
            try {
                sessionStorage.setItem('adminSuccessBanner', res.message);
            } catch(e) {}

            // Show top-right success banner immediately
            showAdminSuccessBanner("STOCK ADJUSTED SUCCESSFULLY!", res.message);

            if (_adminAdjItem && res.new_stock !== undefined) {
                document.querySelectorAll('tr.merch-row[data-id="' + _adminAdjItem.id + '"]').forEach(function(row) {
                    row.dataset.stock = res.new_stock;
                });
            }

            setTimeout(function() {
                location.reload();
            }, 1000);
        } else {
            if (banner) {
                banner.style.cssText = 'display:block; padding:8px 12px; border-radius:6px; font-size:13px; font-weight:600; background:#fef2f2; color:#dc2626; border:1px solid #fecaca;';
                banner.textContent = '✖ ' + (res.message || 'Adjustment failed. Please try again.');
            }
        }
    })
    .catch(function() {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Apply Adjustment Now';
        }
        if (banner) {
            banner.style.cssText = 'display:block; padding:8px 12px; border-radius:6px; font-size:13px; font-weight:600; background:#fef2f2; color:#dc2626; border:1px solid #fecaca;';
            banner.textContent = '✖ Network error. Please try again.';
        }
    });
}

// ── Global Success Toast Banner (Top-Right) Handlers ──
function closeAdminSuccessBanner() {
    var b = document.getElementById('adminGlobalSuccessBanner');
    if (b) {
        b.style.opacity = '0';
        b.style.transform = 'translateX(50px)';
        setTimeout(function() { b.style.display = 'none'; }, 260);
    }
    try { sessionStorage.removeItem('adminSuccessBanner'); } catch(e) {}
}

function showAdminSuccessBanner(title, msg) {
    var banner = document.getElementById('adminGlobalSuccessBanner');
    var bannerTitle = document.getElementById('adminBannerTitle');
    var bannerText = document.getElementById('adminBannerText');
    if (banner) {
        if (bannerTitle && title) bannerTitle.innerText = title;
        if (bannerText && msg) bannerText.innerHTML = msg;
        banner.style.display = 'flex';
        banner.style.opacity = '1';
        banner.style.transform = 'translateX(0)';
        setTimeout(closeAdminSuccessBanner, 6000);
    }
}

// Close on backdrop click & initialize DOM elements
document.addEventListener('DOMContentLoaded', function() {
    var m = document.getElementById('adminAdjModal');
    if (m) {
        m.addEventListener('click', function(e) {
            if (e.target === this) closeAdminAdjustModal();
        });
        // Move to document.body on DOM ready once so future clicks don't cause layout reflow
        if (m.parentNode !== document.body) {
            document.body.appendChild(m);
        }
    }

    // Show top-right success banner if adjustment was just performed
    try {
        var sMsg = sessionStorage.getItem('adminSuccessBanner');
        if (sMsg) {
            sessionStorage.removeItem('adminSuccessBanner');
            showAdminSuccessBanner("STOCK ADJUSTED SUCCESSFULLY!", sMsg);
        }
    } catch(e) {}
});



function adminViewProduct(item) {
    _adminCurrentProd = item;
    var overlay = document.getElementById('adminViewProdModal');
    if (!overlay) return;
    if (overlay.parentNode !== document.body) {
        document.body.appendChild(overlay);
    }

    if (document.getElementById('vpmTitle')) document.getElementById('vpmTitle').textContent = 'View Product — ' + (item.name || '');
    document.getElementById('vpmSku').textContent = item.sku || '—';
    document.getElementById('vpmName').textContent = item.name || '—';
    document.getElementById('vpmCategory').textContent = item.category_name || '—';
    document.getElementById('vpmBrand').textContent = item.brand || 'Petron';
    document.getElementById('vpmSupplier').textContent = item.supplier || 'Petron Corporation';
    document.getElementById('vpmUnit').textContent = item.unit || 'pcs';
    document.getElementById('vpmBarcode').textContent = item.barcode || '4800012345678';
    
    var expDate = item.expiration_date && item.expiration_date !== 'N/A' && item.expiration_date !== '' ? item.expiration_date : (function() {
        var base = item.last_updated ? new Date(item.last_updated) : new Date('2026-08-31');
        var cat = (item.category || item.category_name || '').toLowerCase();
        var nm  = (item.name || '').toLowerCase();
        if (nm.indexOf('chippy') !== -1 || nm.indexOf('coca') !== -1 || nm.indexOf('choco') !== -1 || cat.indexOf('snack') !== -1 || cat.indexOf('beverage') !== -1) {
            base.setFullYear(base.getFullYear() + 1);
        } else if (cat.indexOf('accessory') !== -1 || cat.indexOf('tool') !== -1 || nm.indexOf('wiper') !== -1 || nm.indexOf('mat') !== -1) {
            base.setFullYear(base.getFullYear() + 5);
        } else {
            base.setFullYear(base.getFullYear() + 3);
        }
        return base.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    })();
    var isExpired = item.exp_status === 'expired' || item.computed_status === 'expired' || (expDate !== 'N/A' && new Date(expDate) < new Date(new Date().toDateString()));
    var isExpiringSoon = !isExpired && (item.exp_status === 'expiring_soon' || (expDate !== 'N/A' && (new Date(expDate) - new Date(new Date().toDateString())) / (1000*60*60*24) <= 30));

    var noticeEl = document.getElementById('vpmAlertNotice');
    if (noticeEl) {
        if (isExpired) {
            noticeEl.innerHTML = '<div style="background:#fee2e2; border:1.5px solid #ef4444; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; gap:12px; color:#991b1b; font-size:13.5px; font-weight:700;">' +
                '<i class="fas fa-exclamation-triangle" style="font-size:22px; color:#dc2626; flex-shrink:0;"></i>' +
                '<div><strong style="text-transform:uppercase; letter-spacing:0.5px;">Product Has Expired</strong><div style="font-size:12px; font-weight:500; color:#7f1d1d; margin-top:2px;">This product reached its expiration date on ' + esc(expDate) + '. Do not dispense or sell to customers.</div></div>' +
                '</div>';
        } else if (isExpiringSoon) {
            noticeEl.innerHTML = '<div style="background:#fffbeb; border:1.5px solid #f59e0b; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; gap:12px; color:#92400e; font-size:13.5px; font-weight:700;">' +
                '<i class="fas fa-clock" style="font-size:20px; color:#d97706; flex-shrink:0;"></i>' +
                '<div><strong style="text-transform:uppercase; letter-spacing:0.5px;">Expiring Soon</strong><div style="font-size:12px; font-weight:500; color:#b45309; margin-top:2px;">This product will expire on ' + esc(expDate) + '. Prioritize sales using FIFO.</div></div>' +
                '</div>';
        } else {
            noticeEl.innerHTML = '';
        }
    }

    var expiryEl = document.getElementById('vpmExpiry');
    if (expiryEl) {
        var expBadge = '';
        if (isExpired) {
            expBadge = ' <span style="background:#dc354520;color:#dc3545;border:1.5px solid #dc354560;border-radius:4px;font-size:10px;font-weight:800;padding:2px 6px;text-transform:uppercase;margin-left:6px;display:inline-block;"><i class="fas fa-exclamation-circle"></i> EXPIRED</span>';
        } else if (isExpiringSoon) {
            expBadge = ' <span style="background:#fd7e1420;color:#c05c00;border:1.5px solid #fd7e1460;border-radius:4px;font-size:10px;font-weight:800;padding:2px 6px;text-transform:uppercase;margin-left:6px;display:inline-block;"><i class="fas fa-clock"></i> EXPIRING SOON</span>';
        }
        expiryEl.innerHTML = '<span style="color:' + (isExpired ? '#dc3545' : (expDate !== 'N/A' ? '#1e293b' : '#94a3b8')) + ';">' + esc(expDate) + '</span>' + expBadge;
    }

    var statusMap = {
        'expired': '<span class="badge-lbl bg-red" style="font-weight:800;"><i class="fas fa-ban" style="margin-right:3px;"></i> EXPIRED</span>',
        'available': '<span class="badge-lbl bg-green">Available</span>',
        'low': '<span class="badge-lbl bg-amber">Low Stock</span>',
        'critical': '<span class="badge-lbl bg-red">Critical Stock</span>',
        'out': '<span class="badge-lbl bg-red">Out of Stock</span>'
    };
    if (isExpired) {
        document.getElementById('vpmStatus').innerHTML = statusMap['expired'];
    } else {
        document.getElementById('vpmStatus').innerHTML = statusMap[item.computed_status] || '<span class="badge-lbl bg-green">Available</span>';
    }

    // Sub-tab 2 Data
    var stock = parseFloat(item.stock_level) || 0;
    var price = parseFloat(item.price) || 0;
    var cost  = parseFloat(item.cost) || 0;
    var reorder = parseFloat(item.reorder_level) || 24;
    var critical = parseFloat(item.critical_level) || 10;

    document.getElementById('vpmCurrentStock').textContent = stock.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ' + (item.unit || 'pcs');
    document.getElementById('vpmAvailableStock').textContent = stock.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' ' + (item.unit || 'pcs');
    document.getElementById('vpmReorderLevel').textContent = reorder.toLocaleString('en-US') + ' ' + (item.unit || 'pcs');
    document.getElementById('vpmCriticalLevel').textContent = critical.toLocaleString('en-US') + ' ' + (item.unit || 'pcs');
    document.getElementById('vpmCost').textContent = '₱' + cost.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('vpmPrice').textContent = '₱' + price.toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('vpmInventoryValue').textContent = '₱' + (stock * price).toLocaleString('en-US', {minimumFractionDigits: 2});

    // Reset sub-tabs
    vpmSwitchTab(1);

    // FIFO table loading placeholder
    document.getElementById('vpmFifoTable').innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading batches...</div>';

    // Show modal
    overlay.classList.add('open');
    overlay.style.display = 'flex';
    overlay.style.position = 'fixed';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.right = '0';
    overlay.style.bottom = '0';
    overlay.style.zIndex = '10000';

    // Fetch FIFO details via AJAX
    fetch('admin_inventory_merchandise.php?ajax=1&action=get_product_details&product_id=' + item.id)
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.success || !data.deliveries || data.deliveries.length === 0) {
            document.getElementById('vpmFifoTable').innerHTML = 
                '<table style="width:100%;table-layout:fixed;border-collapse:collapse;font-size:13.5px;box-sizing:border-box;min-width:0;">' +
                '<colgroup>' +
                '<col style="width:16%;">' +
                '<col style="width:14%;">' +
                '<col style="width:14%;">' +
                '<col style="width:14%;">' +
                '<col style="width:14%;">' +
                '<col style="width:14%;">' +
                '<col style="width:14%;">' +
                '</colgroup>' +
                '<thead><tr style="background:#f8fafc;color:#002F70;">' +
                '<th style="padding:9px 6px;text-align:left;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Batch ID</th>' +
                '<th style="padding:9px 6px;text-align:left;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Delivery Date</th>' +
                '<th style="padding:9px 6px;text-align:left;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Expiration Date</th>' +
                '<th style="padding:9px 6px;text-align:right;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Received Qty</th>' +
                '<th style="padding:9px 6px;text-align:right;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Remaining Qty</th>' +
                '<th style="padding:9px 6px;text-align:right;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Unit Cost</th>' +
                '<th style="padding:9px 6px;text-align:right;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Selling Price</th>' +
                '</tr></thead>' +
                '<tbody><tr><td colspan="7" style="text-align:center;padding:24px 12px;color:#94a3b8;font-size:13.5px;word-break:break-word;">No batch records found. Defaulting to main inventory pool.</td></tr></tbody></table>';
            return;
        }
        var fHtml = '<table style="width:100%;table-layout:fixed;border-collapse:collapse;font-size:13.5px;box-sizing:border-box;min-width:0;">';
        fHtml += '<colgroup>' +
            '<col style="width:16%;">' +
            '<col style="width:14%;">' +
            '<col style="width:14%;">' +
            '<col style="width:14%;">' +
            '<col style="width:14%;">' +
            '<col style="width:14%;">' +
            '<col style="width:14%;">' +
            '</colgroup>';
        fHtml += '<thead><tr style="background:#f8fafc;color:#002F70;">' +
            '<th style="padding:9px 6px;text-align:left;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Batch ID</th>' +
            '<th style="padding:9px 6px;text-align:left;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Delivery Date</th>' +
            '<th style="padding:9px 6px;text-align:left;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Expiration Date</th>' +
            '<th style="padding:9px 6px;text-align:right;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Received Qty</th>' +
            '<th style="padding:9px 6px;text-align:right;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Remaining Qty</th>' +
            '<th style="padding:9px 6px;text-align:right;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Unit Cost</th>' +
            '<th style="padding:9px 6px;text-align:right;border-bottom:2px solid #e2e8f0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Selling Price</th>' +
            '</tr></thead><tbody>';
        data.deliveries.forEach(function(d) {
            var dateStr = d.encoded_at ? new Date(d.encoded_at).toLocaleDateString() : '—';
            var expBatchStr = d.expiration_date ? new Date(d.expiration_date).toLocaleDateString() : (item.expiration_date || '—');
            fHtml += '<tr><td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><code style="color:#002F70;font-weight:700;">' + esc(d.batch_no || 'BATCH-' + d.id) + '</code></td>';
            fHtml += '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + dateStr + '</td>';
            fHtml += '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;">' + esc(expBatchStr) + '</td>';
            fHtml += '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + Number(d.qty_received).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td>';
            fHtml += '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:#002F70;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + Number(d.qty_received).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td>';
            fHtml += '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">₱' + Number(d.unit_cost || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td>';
            fHtml += '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;text-align:right;color:#16a34a;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">₱' + Number(item.price || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td></tr>';
        });
        fHtml += '</tbody></table>';
        document.getElementById('vpmFifoTable').innerHTML = fHtml;
    })
    .catch(function() {
        document.getElementById('vpmFifoTable').innerHTML = '<div style="text-align:center;padding:16px;color:#dc3545;">Connection error loading batch data.</div>';
    });
}

function esc(str) {
    if (!str) return '';
    return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function closeAdminViewProdModal() {
    var overlay = document.getElementById('adminViewProdModal');
    if (overlay) {
        overlay.classList.remove('open');
        overlay.style.display = 'none';
    }
}

function vpmSwitchTab(tabNum) {
    for (var i = 1; i <= 3; i++) {
        var pane = document.getElementById('vpmPane' + i);
        var btn = document.getElementById('vpmTab' + i);
        if (pane) pane.style.display = (i === tabNum) ? 'block' : 'none';
        if (btn) {
            if (i === tabNum) {
                btn.classList.add('active');
                btn.style.borderBottom = '2px solid #002F70';
                btn.style.color = '#002F70';
                btn.style.fontWeight = '700';
            } else {
                btn.classList.remove('active');
                btn.style.borderBottom = '2px solid transparent';
                btn.style.color = '#64748b';
                btn.style.fontWeight = '600';
            }
        }
    }
}

// ── Movement Modal ──
function openMovModal(d) {
    var modal = document.getElementById('adminViewMovModal');
    if (!modal) return;
    document.getElementById('vmmDate').textContent = d.date;
    document.getElementById('vmmType').innerHTML = '<span style="font-weight:700;color:#002F70;">' + esc(d.type) + '</span>';
    document.getElementById('vmmProduct').textContent = d.product + ' (' + d.sku + ')';
    document.getElementById('vmmRef').textContent = d.ref;
    document.getElementById('vmmQty').textContent = d.qty;
    document.getElementById('vmmQty').style.color = d.qty.indexOf('+') !== -1 ? '#16a34a' : (d.qty.indexOf('-') !== -1 ? '#dc2626' : '#64748b');
    document.getElementById('vmmRemaining').textContent = d.remaining;
    document.getElementById('vmmBy').textContent = d.by;
    document.getElementById('vmmNotes').textContent = d.notes;

    modal.classList.add('open');
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.top = '0'; modal.style.left = '0'; modal.style.right = '0'; modal.style.bottom = '0';
    modal.style.zIndex = '10005';
}

function closeAdminViewMovModal() {
    var modal = document.getElementById('adminViewMovModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

// â”€â”€ Stock-In Modal â”€â”€
function openSiModal(d) {
    var modal = document.getElementById('adminViewSiModal');
    if (!modal) return;
    document.getElementById('vsimSiNo').textContent = d.si_no;
    document.getElementById('vsimPo').textContent = d.po_number;
    document.getElementById('vsimProduct').textContent = d.product + ' (' + d.sku + ')';
    document.getElementById('vsimSupplier').textContent = d.supplier;
    document.getElementById('vsimDate').textContent = d.date;
    document.getElementById('vsimQty').textContent = d.qty;
    document.getElementById('vsimCost').textContent = '₱' + d.unit_cost;
    document.getElementById('vsimBatch').textContent = d.batch;
    document.getElementById('vsimBy').textContent = d.received_by;
    document.getElementById('vsimStatus').innerHTML = '<span style="background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:4px;font-size:14px;font-weight:700;">' + esc(d.status) + '</span>';
    document.getElementById('vsimNotes').textContent = d.notes;

    modal.classList.add('open');
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.top = '0'; modal.style.left = '0'; modal.style.right = '0'; modal.style.bottom = '0';
    modal.style.zIndex = '10005';
}

function closeAdminViewSiModal() {
    var modal = document.getElementById('adminViewSiModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

// â”€â”€ Movement & Stock-In Table Filters â”€â”€
function filterMovTable() {
    var query = (document.getElementById('movSearchInput') || {}).value || '';
    var type = (document.getElementById('movTypeFilter') || {}).value || '';
    query = query.toLowerCase().trim();
    type = type.toLowerCase().trim();

    var rows = document.querySelectorAll('#adminMovBody tr.mov-row');
    rows.forEach(function(r) {
        var rProd = (r.dataset.product || '').toLowerCase();
        var rType = (r.dataset.type || '').toLowerCase();
        var match = true;
        if (query && rProd.indexOf(query) === -1 && rType.indexOf(query) === -1) match = false;
        if (type && rType.indexOf(type) === -1) match = false;
        r.style.display = match ? '' : 'none';
    });
}

function filterSiTable() {
    var query = (document.getElementById('siSearchInput') || {}).value || '';
    query = query.toLowerCase().trim();

    var rows = document.querySelectorAll('#adminSiBody tr.si-row');
    rows.forEach(function(r) {
        var rProd = (r.dataset.product || '').toLowerCase();
        var rPo = (r.dataset.po || '').toLowerCase();
        var rSup = (r.dataset.supplier || '').toLowerCase();
        var match = true;
        if (query && rProd.indexOf(query) === -1 && rPo.indexOf(query) === -1 && rSup.indexOf(query) === -1) match = false;
        r.style.display = match ? '' : 'none';
    });
}

function filterSoTable() {
    var q = ((document.getElementById('soSearchInput') || {}).value || '').toLowerCase().trim();
    document.querySelectorAll('#adminSoTable .so-row').forEach(function(r) {
        var s = (r.getAttribute('data-search') || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
}

function filterTrTable() {
    var q = ((document.getElementById('trSearchInput') || {}).value || '').toLowerCase().trim();
    document.querySelectorAll('#adminTrTable .tr-row').forEach(function(r) {
        var s = (r.getAttribute('data-search') || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
}

function filterDmgTable() {
    var q = ((document.getElementById('dmgSearchInput') || {}).value || '').toLowerCase().trim();
    document.querySelectorAll('#adminDmgTable .dmg-row').forEach(function(r) {
        var s = (r.getAttribute('data-search') || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
}

function filterAdminMovTable() {
    var search = (document.getElementById('adminMovSearchInput')?.value || '').toLowerCase().trim();
    var type = (document.getElementById('adminMovTypeFilter')?.value || '').toLowerCase().trim();
    var rows = document.querySelectorAll('#adminMovBody .mov-row');
    var matchedCount = 0;
    rows.forEach(function(row) {
        var rowSearch = (row.getAttribute('data-search') || '').toLowerCase();
        var rowType = (row.getAttribute('data-type') || '').toLowerCase();
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
            row.classList.remove('search-hidden');
            matchedCount++;
        } else {
            row.classList.add('search-hidden');
        }
    });

    var noMatch = document.getElementById('adminMovNoMatchRow');
    if (noMatch) {
        noMatch.style.display = (rows.length > 0 && matchedCount === 0) ? '' : 'none';
    }

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['adminMovTable']) {
        window.tablePaginationTriggers['adminMovTable']();
    }
}

function resetAdminMovFilters() {
    var search = document.getElementById('adminMovSearchInput');
    var filter = document.getElementById('adminMovTypeFilter');
    if (search) search.value = '';
    if (filter) {
        filter.value = '';
        filter.dispatchEvent(new Event('change'));
    }
    filterAdminMovTable();
}

function filterAdminAlertTable() {
    var search = (document.getElementById('adminAlertSearchInput')?.value || '').toLowerCase().trim();
    var rows = document.querySelectorAll('#adminAlertBody .alert-row');
    var matchedCount = 0;
    rows.forEach(function(row) {
        var rowSearch = (row.getAttribute('data-search') || '').toLowerCase();
        if (!search || rowSearch.indexOf(search) !== -1) {
            row.classList.remove('search-hidden');
            matchedCount++;
        } else {
            row.classList.add('search-hidden');
        }
    });

    var noMatch = document.getElementById('adminAlertNoMatchRow');
    if (noMatch) {
        noMatch.style.display = (rows.length > 0 && matchedCount === 0) ? '' : 'none';
    }

    if (window.tablePaginationTriggers && window.tablePaginationTriggers['adminAlertTable']) {
        window.tablePaginationTriggers['adminAlertTable']();
    }
}

function filterExpTable() {
    var q = ((document.getElementById('expSearchInput') || {}).value || '').toLowerCase().trim();
    document.querySelectorAll('#adminExpTable .exp-row').forEach(function(r) {
        var s = (r.getAttribute('data-search') || '').toLowerCase();
        r.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
    });
}

function filterAdminByCard(statusKey) {
    var sel = document.getElementById('status_filter');
    if (!sel) return;
    if (sel.value === statusKey) {
        sel.value = 'all';
    } else {
        sel.value = statusKey;
    }
    var form = sel.closest('form');
    if (form) form.submit();
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
        if (select.style.width && select.style.width !== '100%') {
            wrap.style.width = select.style.width;
        } else if (select.closest('.afto-fg')) {
            wrap.style.width = '100%';
        } else {
            wrap.style.width = '200px';
        }
        if (select.style.minWidth) {
            wrap.style.minWidth = select.style.minWidth;
        }

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
            document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(openWrap) {
                openWrap.classList.remove('is-open');
            });
            if (willOpen) {
                var rect = wrap.getBoundingClientRect();
                if (rect.right + 140 > window.innerWidth) {
                    menu.style.left = 'auto';
                    menu.style.right = '0';
                } else {
                    menu.style.left = '0';
                    menu.style.right = 'auto';
                }
                wrap.classList.add('is-open');
                var selectedItem = menu.querySelector('.petron-dropdown-item.is-selected');
                if (selectedItem) {
                    selectedItem.scrollIntoView({ block: 'nearest' });
                }
            }
        });

        select.addEventListener('change', syncLabel);
        select.classList.add('petron-dropdown-source');
        select.parentNode.insertBefore(wrap, select.nextSibling);
        wrap.appendChild(trigger);
        wrap.appendChild(menu);
        syncLabel();
    });

    if (!window.__petronDownCloseBound) {
        window.__petronDownCloseBound = true;
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.petron-dropdown-wrap')) {
                document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(wrap) {
                    wrap.classList.remove('is-open');
                });
            }
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(wrap) {
                    wrap.classList.remove('is-open');
                });
            }
        });
    }
}

// ═══════════════════════════════════════════════════════════════
// PAGINATION STATE MACHINES — Admin Overview, Movement, Alerts
// ═══════════════════════════════════════════════════════════════

function adminGetVisibleRows(tbodyId) {
    var tbody = document.getElementById(tbodyId);
    if (!tbody) return [];
    return Array.from(tbody.querySelectorAll('tr')).filter(function(r) {
        return !r.classList.contains('search-hidden') &&
               !r.classList.contains('no-paginate') &&
               r.id.indexOf('NoMatchRow') === -1 &&
               r.id.indexOf('NoResults') === -1;
    });
}

/* ── Tab 1: Overview ── */
var adminMerchState = { page: 1, perPage: 25 };

function adminMerchRender() {
    var rows = adminGetVisibleRows('adminMerchTableBody');
    var total = rows.length;
    var perPage = adminMerchState.perPage;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    if (adminMerchState.page > totalPages) adminMerchState.page = totalPages;
    if (adminMerchState.page < 1) adminMerchState.page = 1;

    var start = (adminMerchState.page - 1) * perPage;
    var end   = Math.min(start + perPage, total);

    var tbody = document.getElementById('adminMerchTableBody');
    if (tbody) {
        var allRows = Array.from(tbody.querySelectorAll('tr'));
        var visIdx = 0;
        allRows.forEach(function(r) {
            if (r.classList.contains('no-paginate') || r.id.indexOf('NoMatchRow') !== -1) return;
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

    var showingEl = document.getElementById('adminMerchShowingText');
    if (showingEl) {
        var s = total === 0 ? 0 : start + 1;
        showingEl.textContent = 'Showing ' + s + '–' + end + ' of ' + total + ' entries';
    }
    var labelEl = document.getElementById('adminMerchPageLabel');
    if (labelEl) labelEl.textContent = 'Page ' + adminMerchState.page + ' of ' + totalPages;

    var prevBtn = document.getElementById('adminMerchPrevBtn');
    var nextBtn = document.getElementById('adminMerchNextBtn');
    if (prevBtn) {
        prevBtn.disabled = adminMerchState.page <= 1;
        prevBtn.style.cursor = adminMerchState.page <= 1 ? 'not-allowed' : 'pointer';
        prevBtn.style.color  = adminMerchState.page <= 1 ? '#cbd5e1' : '#475569';
    }
    if (nextBtn) {
        nextBtn.disabled = adminMerchState.page >= totalPages;
        nextBtn.style.cursor = adminMerchState.page >= totalPages ? 'not-allowed' : 'pointer';
        nextBtn.style.color  = adminMerchState.page >= totalPages ? '#cbd5e1' : '#475569';
    }
}

function adminMerchGoPage(p) {
    adminMerchState.page = p;
    adminMerchRender();
}

function adminMerchChangePerPage() {
    var sel = document.getElementById('adminMerchPerPage');
    if (sel) adminMerchState.perPage = parseInt(sel.value, 10);
    adminMerchState.page = 1;
    adminMerchRender();
}

/* ── Tab 2: Movement ── */
var adminMovState = { page: 1, perPage: 25 };

function adminMovRender() {
    var rows = adminGetVisibleRows('adminMovBody');
    var total = rows.length;
    var perPage = adminMovState.perPage;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    if (adminMovState.page > totalPages) adminMovState.page = totalPages;
    if (adminMovState.page < 1) adminMovState.page = 1;

    var start = (adminMovState.page - 1) * perPage;
    var end   = Math.min(start + perPage, total);

    rows.forEach(function(r, i) {
        r.style.display = (i >= start && i < end) ? '' : 'none';
    });

    var showingEl = document.getElementById('adminMovShowingText');
    if (showingEl) {
        var s = total === 0 ? 0 : start + 1;
        showingEl.textContent = 'Showing ' + s + '–' + end + ' of ' + total + ' entries';
    }
    var labelEl = document.getElementById('adminMovPageLabel');
    if (labelEl) labelEl.textContent = 'Page ' + adminMovState.page + ' of ' + totalPages;

    var prevBtn = document.getElementById('adminMovPrevBtn');
    var nextBtn = document.getElementById('adminMovNextBtn');
    if (prevBtn) {
        prevBtn.disabled = adminMovState.page <= 1;
        prevBtn.style.cursor = adminMovState.page <= 1 ? 'not-allowed' : 'pointer';
        prevBtn.style.color  = adminMovState.page <= 1 ? '#cbd5e1' : '#475569';
    }
    if (nextBtn) {
        nextBtn.disabled = adminMovState.page >= totalPages;
        nextBtn.style.cursor = adminMovState.page >= totalPages ? 'not-allowed' : 'pointer';
        nextBtn.style.color  = adminMovState.page >= totalPages ? '#cbd5e1' : '#475569';
    }
}

function adminMovGoPage(p) {
    adminMovState.page = p;
    adminMovRender();
}

function adminMovChangePerPage() {
    var sel = document.getElementById('adminMovPerPage');
    if (sel) adminMovState.perPage = parseInt(sel.value, 10);
    adminMovState.page = 1;
    adminMovRender();
}

/* ── Tab 3: Alerts ── */
var adminAlertState = { page: 1, perPage: 25 };

function adminAlertRender() {
    var rows = adminGetVisibleRows('adminAlertBody');
    var total = rows.length;
    var perPage = adminAlertState.perPage;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    if (adminAlertState.page > totalPages) adminAlertState.page = totalPages;
    if (adminAlertState.page < 1) adminAlertState.page = 1;

    var start = (adminAlertState.page - 1) * perPage;
    var end   = Math.min(start + perPage, total);

    rows.forEach(function(r, i) {
        r.style.display = (i >= start && i < end) ? '' : 'none';
    });

    var showingEl = document.getElementById('adminAlertShowingText');
    if (showingEl) {
        var s = total === 0 ? 0 : start + 1;
        showingEl.textContent = 'Showing ' + s + '–' + end + ' of ' + total + ' entries';
    }
    var labelEl = document.getElementById('adminAlertPageLabel');
    if (labelEl) labelEl.textContent = 'Page ' + adminAlertState.page + ' of ' + totalPages;

    var prevBtn = document.getElementById('adminAlertPrevBtn');
    var nextBtn = document.getElementById('adminAlertNextBtn');
    if (prevBtn) {
        prevBtn.disabled = adminAlertState.page <= 1;
        prevBtn.style.cursor = adminAlertState.page <= 1 ? 'not-allowed' : 'pointer';
        prevBtn.style.color  = adminAlertState.page <= 1 ? '#cbd5e1' : '#475569';
    }
    if (nextBtn) {
        nextBtn.disabled = adminAlertState.page >= totalPages;
        nextBtn.style.cursor = adminAlertState.page >= totalPages ? 'not-allowed' : 'pointer';
        nextBtn.style.color  = adminAlertState.page >= totalPages ? '#cbd5e1' : '#475569';
    }
}

function adminAlertGoPage(p) {
    adminAlertState.page = p;
    adminAlertRender();
}

function adminAlertChangePerPage() {
    var sel = document.getElementById('adminAlertPerPage');
    if (sel) adminAlertState.perPage = parseInt(sel.value, 10);
    adminAlertState.page = 1;
    adminAlertRender();
}

// Expose on window
window.adminMerchState = adminMerchState;
window.adminMerchRender = adminMerchRender;
window.adminMerchGoPage = adminMerchGoPage;
window.adminMerchChangePerPage = adminMerchChangePerPage;

window.adminMovState = adminMovState;
window.adminMovRender = adminMovRender;
window.adminMovGoPage = adminMovGoPage;
window.adminMovChangePerPage = adminMovChangePerPage;

window.adminAlertState = adminAlertState;
window.adminAlertRender = adminAlertRender;
window.adminAlertGoPage = adminAlertGoPage;
window.adminAlertChangePerPage = adminAlertChangePerPage;

<?php if (!empty($auto_open_item)): ?>
window._autoOpenProdItem = <?= json_encode([
    "id" => $auto_open_item["id"],
    "name" => $auto_open_item["name"],
    "sku" => $auto_open_item["sku"],
    "category_name" => $auto_open_item["category_name"],
    "brand" => $auto_open_item["brand"] ?? "Petron",
    "supplier" => $auto_open_item["supplier"] ?? "Petron Corporation",
    "unit" => $auto_open_item["unit"] ?? "pcs",
    "barcode" => $auto_open_item["barcode"] ?? "",
    "stock_level" => $auto_open_item["stock_level"],
    "price" => $auto_open_item["price"],
    "cost" => $auto_open_item["cost"],
    "reorder_level" => $auto_open_item["reorder_level"] ?? 24,
    "critical_level" => $auto_open_item["critical_level"] ?? 10,
    "capacity" => $auto_open_item["capacity"] ?? 480,
    "last_updated" => $auto_open_item["last_updated"] ?? "",
    "computed_status" => $auto_open_item["computed_status"] ?? "available",
    "expiration_date" => $auto_open_item["expiration_date"] ?? "N/A",
    "exp_status" => $auto_open_item["exp_status"] ?? "ok"
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function() {
    setupPetronDownwardDropdowns([
        '#category',
        '#brand',
        '#unit',
        '#status_filter',
        '#adminMovTypeFilter'
    ]);

    var activeTab = '<?= htmlspecialchars($active_tab) ?>';
    if (activeTab === 'overview') {
        adminMerchRender();
    } else if (activeTab === 'movement') {
        adminMovRender();
    } else if (activeTab === 'alerts') {
        adminAlertRender();
    }

    window.tablePaginationTriggers = window.tablePaginationTriggers || {};
    window.tablePaginationTriggers['adminMerchTable'] = function(){ adminMerchState.page = 1; adminMerchRender(); };
    window.tablePaginationTriggers['adminMovTable']   = function(){ adminMovState.page = 1; adminMovRender(); };
    window.tablePaginationTriggers['adminAlertTable'] = function(){ adminAlertState.page = 1; adminAlertRender(); };

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
                // If on another page in pagination, jump to that page
                var visibleRows = Array.from(document.querySelectorAll('tbody#adminMerchTbody tr.merch-row:not(.search-hidden)'));
                var rowIdx = visibleRows.indexOf(targetRow);
                if (rowIdx !== -1 && typeof adminMerchGoPage === 'function' && window.adminMerchState) {
                    var targetPage = Math.floor(rowIdx / adminMerchState.perPage) + 1;
                    if (targetPage !== adminMerchState.page) {
                        adminMerchGoPage(targetPage);
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
</div> <!-- /.main-content -->

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
