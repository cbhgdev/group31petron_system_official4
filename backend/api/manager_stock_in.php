<?php
/**
 * Manager Stock-In API
 * Finalizes pending merchandise and fuel deliveries recorded by staff.
 */
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json');

$me = current_user();
if (!$me) {
    stock_in_json(['success' => false, 'message' => 'Not authenticated'], 401);
}

$role = role_key($me['role'] ?? '');
if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'], true)) {
    stock_in_json(['success' => false, 'message' => 'Access denied: Manager or Administrator role required.'], 403);
}

$station_id = (int)user_station_id();
$action = $_GET['action'] ?? '';

try {
    ensure_manager_stock_in_schema($pdo);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        stock_in_json(['success' => false, 'message' => 'POST required'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        stock_in_json(['success' => false, 'message' => 'Invalid JSON input'], 400);
    }

    if ($action === 'approve_merchandise_stock_in') {
        approve_merchandise_stock_in($pdo, $me, $station_id, $input);
    } elseif ($action === 'approve_fuel_stock_in') {
        approve_fuel_stock_in($pdo, $me, $station_id, $input);
    } else {
        stock_in_json(['success' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Manager stock-in error: ' . $e->getMessage());
    stock_in_json(['success' => false, 'message' => stock_in_public_error_message($e)], 500);
}

function stock_in_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function stock_in_public_error_message(Exception $e): string
{
    $message = $e->getMessage();
    if (stripos($message, 'Duplicate entry') !== false && stripos($message, 'unique_product') !== false) {
        return 'This product already exists in inventory. Please refresh the page and try approving again.';
    }
    if (stripos($message, 'SQLSTATE[') !== false) {
        return 'A database error prevented stock-in approval. Please refresh and try again.';
    }
    return $message !== '' ? $message : 'Unable to approve stock-in.';
}

function ensure_manager_stock_in_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS merchandise_stock_in (
        id INT AUTO_INCREMENT PRIMARY KEY,
        po_id INT NULL,
        po_number VARCHAR(100) NULL,
        station_id INT NOT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(255) NOT NULL,
        sku VARCHAR(100) NULL,
        category VARCHAR(100) NULL,
        qty_ordered INT NOT NULL DEFAULT 0,
        qty_received INT NOT NULL DEFAULT 0,
        qty_variance INT NOT NULL DEFAULT 0,
        unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        condition_flag ENUM('Good','Damaged','Short','Excess') NOT NULL DEFAULT 'Good',
        remarks TEXT NULL,
        stock_before INT NOT NULL DEFAULT 0,
        stock_after INT NOT NULL DEFAULT 0,
        encoded_by INT NOT NULL,
        encoded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        batch_ref VARCHAR(100) NULL,
        INDEX idx_station (station_id),
        INDEX idx_encoded_at (encoded_at),
        INDEX idx_po_id (po_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_stock_in (
        id INT AUTO_INCREMENT PRIMARY KEY,
        delivery_id INT NOT NULL,
        invoice_no VARCHAR(100) NULL,
        station_id INT NOT NULL,
        fuel_type VARCHAR(255) NOT NULL,
        qty_expected DECIMAL(12,2) NOT NULL DEFAULT 0,
        qty_received DECIMAL(12,2) NOT NULL DEFAULT 0,
        qty_variance DECIMAL(12,2) NOT NULL DEFAULT 0,
        condition_flag ENUM('Good','Damaged','Short','Excess') NOT NULL DEFAULT 'Good',
        remarks TEXT NULL,
        level_before DECIMAL(12,2) NOT NULL DEFAULT 0,
        level_after DECIMAL(12,2) NOT NULL DEFAULT 0,
        encoded_by INT NOT NULL,
        encoded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        batch_ref VARCHAR(100) NULL,
        delivery_ref VARCHAR(100) NULL,
        INDEX idx_station (station_id),
        INDEX idx_encoded_at (encoded_at),
        INDEX idx_delivery_id (delivery_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS merchandise_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        station_id INT NOT NULL,
        batch_number VARCHAR(50) NOT NULL,
        delivery_id INT DEFAULT NULL,
        quantity_received INT NOT NULL DEFAULT 0,
        remaining_qty INT NOT NULL DEFAULT 0,
        unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
        selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        supplier VARCHAR(200) DEFAULT NULL,
        date_received DATE NOT NULL,
        encoded_by INT DEFAULT NULL,
        validated_by INT DEFAULT NULL,
        validated_at DATETIME DEFAULT NULL,
        status ENUM('active','depleted','cancelled') NOT NULL DEFAULT 'active',
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_product (product_id),
        INDEX idx_station (station_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fuel_type_id INT NOT NULL,
        station_id INT NOT NULL,
        batch_number VARCHAR(50) NOT NULL,
        delivery_id INT DEFAULT NULL,
        quantity_received DECIMAL(12,2) NOT NULL DEFAULT 0,
        remaining_qty DECIMAL(12,2) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
        selling_price_per_liter DECIMAL(12,4) NOT NULL DEFAULT 0,
        supplier VARCHAR(200) DEFAULT NULL,
        date_received DATE NOT NULL,
        encoded_by INT DEFAULT NULL,
        status ENUM('active','depleted','cancelled') NOT NULL DEFAULT 'active',
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_fuel_type (fuel_type_id),
        INDEX idx_station (station_id),
        INDEX idx_status (status),
        INDEX idx_date (date_received)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        "ALTER TABLE merchandise_stock_in ADD COLUMN IF NOT EXISTS delivery_id INT NULL",
        "ALTER TABLE merchandise_stock_in ADD COLUMN IF NOT EXISTS selling_price DECIMAL(12,2) NOT NULL DEFAULT 0",
        "ALTER TABLE fuel_stock_in ADD COLUMN IF NOT EXISTS selling_price_per_liter DECIMAL(12,2) NOT NULL DEFAULT 0",
        "ALTER TABLE fuel_stock_in MODIFY COLUMN delivery_id INT NULL",
        "ALTER TABLE merchandise_batches ADD COLUMN IF NOT EXISTS selling_price DECIMAL(12,2) NOT NULL DEFAULT 0",
        "ALTER TABLE fuel_batches ADD COLUMN IF NOT EXISTS selling_price_per_liter DECIMAL(12,2) NOT NULL DEFAULT 0",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_done TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_at DATETIME NULL",
        "ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS stock_in_by INT NULL",
        "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS manager_id INT NULL",
        "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS manager_action_at DATETIME NULL",
        "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS manager_notes TEXT NULL",
        "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS finalized_at DATETIME NULL",
        "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS finalized_by INT NULL"
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $ignored) {
        }
    }

    // Drop legacy/invalid foreign keys referencing fuel_deliveries or incompatible tables that block stock-in
    $invalid_fks = [
        ['table' => 'fuel_stock_in', 'fk' => 'fk_fsi_delivery'],
        ['table' => 'fuel_batches', 'fk' => 'fk_fuel_batches_delivery_id'],
        ['table' => 'merchandise_stock_in', 'fk' => 'fk_3nf_merchandise_stock_in_delivery_id_fuel_deliveries'],
        ['table' => 'merchandise_batches', 'fk' => 'fk_3nf_merchandise_batches_delivery_id_fuel_deliveries'],
        ['table' => 'merchandise_stock_in', 'fk' => 'fk_merchandise_stock_in_product_id'],
        ['table' => 'merchandise_batches', 'fk' => 'fk_merchandise_batches_product_id'],
    ];
    foreach ($invalid_fks as $item) {
        try {
            $pdo->exec("ALTER TABLE {$item['table']} DROP FOREIGN KEY {$item['fk']}");
        } catch (Exception $ignored) {}
    }
}

function approve_merchandise_stock_in(PDO $pdo, array $me, int $station_id, array $input): void
{
    $items = normalize_stock_in_items($input['items'] ?? [], 'delivery_id');
    if (empty($items)) {
        stock_in_json(['success' => false, 'message' => 'No merchandise items submitted'], 400);
    }

    $rows = fetch_pending_merchandise_rows($pdo, $station_id, array_keys($items));
    validate_single_group($rows, trim((string)($input['po_key'] ?? '')));

    $po_key = $rows[0]['po_key'];
    $batch_id = next_stock_in_batch_id($pdo, 'BT', 'merchandise_stock_in', 'batch_ref');
    $completed_status = purchase_order_completed_status($pdo);
    $ids = [];
    $staff_ids = [];
    $total_received = 0;
    $records = [];

    // Determine if inventory_products table is usable (once per call)
    $inv_products_exists = false;
    try {
        $pdo->query("SELECT COUNT(*) FROM inventory_products LIMIT 1");
        $inv_products_exists = true;
    } catch (Exception $ignored) {
        $inv_products_exists = false;
    }

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $delivery_id = (int)$row['delivery_id'];
            $posted = $items[$delivery_id];
            $product_id = (int)$row['product_id'];

            if ($product_id <= 0) {
                $product_id = resolve_or_create_merchandise_product($pdo, $station_id, $row, $posted, $inv_products_exists);
            }

            $qty_ordered = (int)round((float)($row['expected_quantity'] ?: $row['quantity']));
            $qty_received = (int)round((float)($posted['qty_received'] ?? 0));
            $selling_price = round((float)($posted['selling_price'] ?? 0), 2);
            if ($qty_received < 0) {
                throw new Exception('Quantity received cannot be negative.');
            }
            if ($selling_price <= 0) {
                throw new Exception("Selling price is required for {$row['product']}.");
            }

            $unit_cost = round((float)($row['cost_price'] ?? 0), 2);
            $stock_before = merchandise_stock_before($pdo, $station_id, $product_id, $inv_products_exists);
            $stock_after = $stock_before + $qty_received;
            $qty_variance = $qty_received - $qty_ordered;
            $condition = condition_from_variance($qty_received, $qty_ordered, (float)($row['damaged_quantity'] ?? 0));
            $remarks = trim((string)($posted['remarks'] ?? ''));
            $total_cost = round($qty_received * $unit_cost, 2);

            upsert_station_inventory($pdo, $station_id, $product_id, $qty_received, $unit_cost, $selling_price, $row['unit_display'] ?: $row['unit']);

            // Update inventory_products only if the table exists
            if ($inv_products_exists && $product_id > 0) {
                try {
                    $pdo->prepare("
                        UPDATE inventory_products
                        SET stock = COALESCE(stock, 0) + ?,
                            stock_quantity = COALESCE(stock_quantity, 0) + ?,
                            unit_cost = ?,
                            unit_price = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$qty_received, $qty_received, $unit_cost, $selling_price, $product_id]);
                } catch (Exception $ignored) {}

                // Also sync products.current_stock to stay consistent
                try {
                    $pdo->prepare("
                        UPDATE products
                        SET current_stock = COALESCE(current_stock, 0) + ?,
                            price = CASE WHEN ? > 0 THEN ? ELSE price END,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$qty_received, $selling_price, $selling_price, $product_id]);
                } catch (Exception $ignored) {}
            } else {
                // Update products table as fallback
                try {
                    $pdo->prepare("
                        UPDATE products
                        SET current_stock = COALESCE(current_stock, 0) + ?,
                            price = CASE WHEN ? > 0 THEN ? ELSE price END,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([$qty_received, $selling_price, $selling_price, $product_id]);
                } catch (Exception $ignored) {}
            }


            // Insert inventory log with Global Movement Engine standards
            try {
                $pdo->prepare("
                    INSERT INTO inventory_logs
                        (station_id, product_id, user_id, action, movement_type, reason, quantity_before, quantity_after,
                         quantity_change, reference_type, reference_id, reference_no, notes, created_at)
                    VALUES (?, ?, ?, 'stock_in', 'IN', 'Stock-In', ?, ?, ?, 'deliveries_oversight', NULL, ?, ?, NOW())
                ")->execute([
                    $station_id,
                    $product_id,
                    (int)$me['id'],
                    (int)$stock_before,
                    (int)$stock_after,
                    (int)$qty_received,
                    $po_key ?: $batch_id,
                    "Manager Stock-In | Batch: {$batch_id} | PO: {$po_key}"
                ]);
            } catch (Exception $log_error) {
                error_log("Inventory log insert failed for product {$product_id}: " . $log_error->getMessage());
            }


            $pdo->prepare("
                INSERT INTO merchandise_stock_in
                    (po_id, delivery_id, po_number, station_id, product_id, product_name, sku, category,
                     qty_ordered, qty_received, qty_variance, unit_cost, selling_price, total_cost,
                     condition_flag, remarks, stock_before, stock_after, encoded_by, encoded_at, batch_ref)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ")->execute([
                $row['po_id'] ? (int)$row['po_id'] : null,
                $delivery_id,
                $po_key,
                $station_id,
                $product_id,
                $row['product'],
                $row['sku'] ?? '',
                $row['category'] ?? '',
                $qty_ordered,
                $qty_received,
                $qty_variance,
                $unit_cost,
                $selling_price,
                $total_cost,
                $condition,
                $remarks ?: 'Manager approved stock-in',
                $stock_before,
                $stock_after,
                $me['id'],
                $batch_id
            ]);

            upsert_merchandise_batch(
                $pdo,
                $station_id,
                $product_id,
                $batch_id,
                $delivery_id,
                $qty_received,
                $unit_cost,
                $selling_price,
                $row['supplier'] ?? '',
                $me['id'],
                "Stock-In from {$po_key}"
            );

            $ids[] = $delivery_id;
            if (!empty($row['encoded_by'])) {
                $staff_ids[] = (int)$row['encoded_by'];
            }
            $total_received += $qty_received;
            $records[] = ['delivery_id' => $delivery_id, 'product' => $row['product'], 'qty_received' => $qty_received];
        }

        $role_name = (string)($me['role'] ?? 'manager');
        mark_deliveries_complete($pdo, $ids, $station_id, (int)$me['id'], $batch_id, $role_name);
        update_merchandise_po_status($pdo, $station_id, $po_key, $completed_status, (int)$me['id']);
        notify_stock_in_users($pdo, $station_id, $staff_ids, 'Merchandise Stock-In Completed', "PO {$po_key} has been stocked in. Batch {$batch_id}.", 'merchandise', $me, $po_key, $batch_id, $total_received);
        audit_stock_in($pdo, $me, 'Merchandise Stock-In', "Approved merchandise stock-in for {$po_key}; batch {$batch_id}; total qty {$total_received}.", 'deliveries_oversight', $ids[0] ?? null);

        if (function_exists('log_activity')) {
            $actor = ucfirst($role_name);
            log_activity($pdo, $me['id'], "{$actor} Merchandise Stock-In", "PO {$po_key} | Batch {$batch_id} | Items " . count($records));
        }

        $pdo->commit();
        stock_in_json([
            'success' => true,
            'message' => "Merchandise stock-in approved. Batch {$batch_id} generated.",
            'batch_id' => $batch_id,
            'records' => $records
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function approve_fuel_stock_in(PDO $pdo, array $me, int $station_id, array $input): void
{
    $items = normalize_stock_in_items($input['items'] ?? [], 'delivery_id');
    if (empty($items)) {
        stock_in_json(['success' => false, 'message' => 'No fuel items submitted'], 400);
    }

    $rows = fetch_pending_fuel_rows($pdo, $station_id, array_keys($items));
    validate_single_group($rows, trim((string)($input['po_key'] ?? '')));

    $po_key = $rows[0]['po_key'];
    $batch_id = next_stock_in_batch_id($pdo, 'FB', 'fuel_stock_in', 'batch_ref');
    $ids = [];
    $staff_ids = [];
    $total_received = 0.0;
    $records = [];

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $delivery_id = (int)$row['delivery_id'];
            $posted = $items[$delivery_id];
            $liters_ordered = round((float)($row['expected_quantity'] ?: $row['quantity']), 2);
            $liters_received = round((float)($posted['qty_received'] ?? 0), 2);
            $selling_price = round((float)($posted['selling_price'] ?? 0), 2);
            if ($liters_received < 0) {
                throw new Exception('Liters received cannot be negative.');
            }
            if ($selling_price <= 0) {
                throw new Exception("Selling price per liter is required for {$row['fuel_type']}.");
            }

            $fuel_type_id = resolve_fuel_type_id($pdo, $station_id, $row['fuel_type'], (int)($row['fuel_type_id'] ?? 0));
            $unit_cost = round((float)($row['cost_price'] ?? 0), 2);
            $inventory = fuel_inventory_before($pdo, $station_id, $fuel_type_id, $row['fuel_type']);
            $level_before = (float)$inventory['current_level'];
            $level_after = $level_before + $liters_received;
            $variance = $liters_received - $liters_ordered;
            $condition = condition_from_variance($liters_received, $liters_ordered, (float)($row['damaged_quantity'] ?? 0));
            $remarks = trim((string)($posted['remarks'] ?? ''));

            upsert_fuel_inventory($pdo, $station_id, $fuel_type_id, $row['fuel_type'], $liters_received, $selling_price, (int)$me['id']);

            $pdo->prepare("
                INSERT INTO fuel_stock_in
                    (delivery_id, invoice_no, station_id, fuel_type, qty_expected, qty_received, qty_variance,
                     condition_flag, remarks, level_before, level_after, encoded_by, encoded_at, batch_ref,
                     delivery_ref, selling_price_per_liter)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
            ")->execute([
                $delivery_id,
                $row['dr_number'] ?? '',
                $station_id,
                $row['fuel_type'],
                $liters_ordered,
                $liters_received,
                $variance,
                $condition,
                $remarks ?: 'Manager approved fuel stock-in',
                $level_before,
                $level_after,
                $me['id'],
                $batch_id,
                $row['delivery_ref'] ?? '',
                $selling_price
            ]);

            upsert_fuel_batch($pdo, $station_id, $fuel_type_id, $batch_id, $delivery_id, $liters_received, $unit_cost, $selling_price, $row['supplier'] ?? '', (int)$me['id'], "Fuel stock-in from {$po_key}");
            insert_fuel_movement($pdo, $station_id, $fuel_type_id, $row['fuel_type'], $level_before, $level_after, $liters_received, $delivery_id, (int)$me['id'], $batch_id);
            update_fuel_purchase_order_row($pdo, $station_id, $po_key, $fuel_type_id, $row['fuel_type'], $liters_received, $row['delivery_date'] ?? date('Y-m-d'));

            $ids[] = $delivery_id;
            if (!empty($row['encoded_by'])) {
                $staff_ids[] = (int)$row['encoded_by'];
            }
            $total_received += $liters_received;
            $records[] = ['delivery_id' => $delivery_id, 'fuel_type' => $row['fuel_type'], 'liters_received' => $liters_received];
        }

        $role_name = (string)($me['role'] ?? 'manager');
        mark_deliveries_complete($pdo, $ids, $station_id, (int)$me['id'], $batch_id, $role_name);
        notify_stock_in_users($pdo, $station_id, $staff_ids, 'Fuel Stock-In Completed', "Fuel PO {$po_key} has been stocked in. Batch {$batch_id}.", 'fuel', $me, $po_key, $batch_id, $total_received);
        audit_stock_in($pdo, $me, 'Fuel Stock-In', "Approved fuel stock-in for {$po_key}; batch {$batch_id}; total liters {$total_received}.", 'deliveries_oversight', $ids[0] ?? null);

        if (function_exists('log_activity')) {
            $actor = ucfirst($role_name);
            log_activity($pdo, $me['id'], "{$actor} Fuel Stock-In", "PO {$po_key} | Batch {$batch_id} | Fuel rows " . count($records));
        }

        $pdo->commit();
        stock_in_json([
            'success' => true,
            'message' => "Fuel stock-in approved. Batch {$batch_id} generated.",
            'batch_id' => $batch_id,
            'records' => $records
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function normalize_stock_in_items($items, string $id_key): array
{
    if (!is_array($items)) {
        return [];
    }
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (int)($item[$id_key] ?? 0);
        if ($id > 0) {
            $out[$id] = $item;
        }
    }
    return $out;
}

function pending_stock_in_status_sql(): string
{
    return "'Pending Stock-In','Ready for Stock-In','Validated','Verified','Partial Delivery','Damaged Items','Adjusted','Pending Manager Approval','Pending Manager Confirmation','Pending Validation','Pending Verification','Pending Delivery','Pending','Expected Delivery'";
}

function fetch_pending_merchandise_rows(PDO $pdo, int $station_id, array $delivery_ids): array
{
    // Check if inventory_products table is usable (not just exists but also accessible)
    $inv_products_exists = false;
    try {
        $pdo->query("SELECT COUNT(*) FROM inventory_products LIMIT 1");
        $inv_products_exists = true;
    } catch (Exception $ignored) {
        $inv_products_exists = false;
    }

    $placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));

    if ($inv_products_exists) {
        $sql = "
            SELECT
                do2.id AS delivery_id,
                do2.delivery_ref,
                COALESCE(NULLIF(do2.source_ref, ''), do2.delivery_ref) AS po_key,
                do2.source_ref,
                do2.supplier,
                do2.product,
                do2.quantity,
                do2.expected_quantity,
                do2.actual_quantity,
                do2.damaged_quantity,
                do2.unit,
                do2.unit_price,
                do2.unit_cost,
                do2.delivery_date,
                do2.dr_number,
                do2.encoded_by,
                ip.id AS product_id,
                ip.sku,
                ip.category,
                COALESCE(si.unit, ip.size, do2.unit, 'pcs') AS unit_display,
                COALESCE(do2.unit_cost, do2.unit_price,
                    (SELECT po.unit_price FROM purchase_orders po
                     WHERE po.station_id = do2.station_id
                       AND (po.po_number = do2.source_ref OR po.batch_id = do2.source_ref)
                       AND LOWER(TRIM(po.product_name)) = LOWER(TRIM(do2.product))
                     ORDER BY po.id DESC LIMIT 1),
                    ip.unit_cost, 0
                ) AS cost_price,
                (SELECT po.id FROM purchase_orders po
                 WHERE po.station_id = do2.station_id
                   AND (po.po_number = do2.source_ref OR po.batch_id = do2.source_ref)
                   AND LOWER(TRIM(po.product_name)) = LOWER(TRIM(do2.product))
                 ORDER BY po.id DESC LIMIT 1) AS po_id
            FROM deliveries_oversight do2
            LEFT JOIN inventory_products ip
                   ON ip.station_id = do2.station_id
                  AND LOWER(TRIM(ip.product_name)) = LOWER(TRIM(do2.product))
                  AND LOWER(COALESCE(ip.category, '')) NOT IN ('fuel','fuels')
            LEFT JOIN station_inventory si
                   ON si.product_id = ip.id AND si.station_id = do2.station_id
            WHERE do2.id IN ({$placeholders})
              AND do2.station_id = ?
              AND do2.delivery_type = 'merchandise'
              AND do2.status IN (" . pending_stock_in_status_sql() . ")
            ORDER BY do2.id ASC
        ";
    } else {
        $sql = "
            SELECT
                do2.id AS delivery_id,
                do2.delivery_ref,
                COALESCE(NULLIF(do2.source_ref, ''), do2.delivery_ref) AS po_key,
                do2.source_ref,
                do2.supplier,
                do2.product,
                do2.quantity,
                do2.expected_quantity,
                do2.actual_quantity,
                do2.damaged_quantity,
                do2.unit,
                do2.unit_price,
                do2.unit_cost,
                do2.delivery_date,
                do2.dr_number,
                do2.encoded_by,
                COALESCE(p.id, 0) AS product_id,
                '' AS sku,
                COALESCE(pc.name, 'Merchandise') AS category,
                COALESCE(p.unit, do2.unit, 'pcs') AS unit_display,
                COALESCE(do2.unit_cost, do2.unit_price, p.cost, 0) AS cost_price,
                (SELECT po.id FROM purchase_orders po
                 WHERE po.station_id = do2.station_id
                   AND (po.po_number = do2.source_ref OR po.batch_id = do2.source_ref)
                   AND LOWER(TRIM(po.product_name)) = LOWER(TRIM(do2.product))
                 ORDER BY po.id DESC LIMIT 1) AS po_id
            FROM deliveries_oversight do2
            LEFT JOIN products p
                   ON LOWER(TRIM(p.name)) = LOWER(TRIM(do2.product))
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE do2.id IN ({$placeholders})
              AND do2.station_id = ?
              AND do2.delivery_type = 'merchandise'
              AND do2.status IN (" . pending_stock_in_status_sql() . ")
            ORDER BY do2.id ASC
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($delivery_ids, [$station_id]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== count($delivery_ids)) {
        throw new Exception('Some merchandise deliveries are no longer pending stock-in.');
    }
    return $rows;
}

function fetch_pending_fuel_rows(PDO $pdo, int $station_id, array $delivery_ids): array
{
    $placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));
    $sql = "
        SELECT
            do2.id AS delivery_id,
            do2.delivery_ref,
            COALESCE(NULLIF(do2.source_ref, ''), do2.delivery_ref) AS po_key,
            do2.source_ref,
            do2.supplier,
            do2.product AS fuel_type,
            do2.quantity,
            do2.expected_quantity,
            do2.actual_quantity,
            do2.damaged_quantity,
            do2.unit_price,
            do2.unit_cost,
            do2.delivery_date,
            do2.dr_number,
            do2.encoded_by,
            COALESCE(do2.unit_cost, do2.unit_price,
                (SELECT fpo.unit_price FROM fuel_purchase_orders fpo
                 LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
                 WHERE fpo.station_id = do2.station_id
                   AND fpo.po_number = do2.source_ref
                   AND LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product))
                 ORDER BY fpo.id DESC LIMIT 1),
                0
            ) AS cost_price,
            (SELECT fpo.fuel_type_id FROM fuel_purchase_orders fpo
             LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
             WHERE fpo.station_id = do2.station_id
               AND fpo.po_number = do2.source_ref
               AND LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product))
             ORDER BY fpo.id DESC LIMIT 1) AS fuel_type_id
        FROM deliveries_oversight do2
        WHERE do2.id IN ({$placeholders})
          AND do2.station_id = ?
          AND do2.delivery_type = 'fuel'
          AND do2.status IN (" . pending_stock_in_status_sql() . ")
        ORDER BY do2.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($delivery_ids, [$station_id]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== count($delivery_ids)) {
        throw new Exception('Some fuel deliveries are no longer pending stock-in.');
    }
    return $rows;
}

function validate_single_group(array $rows, string $submitted_key): void
{
    if (empty($rows)) {
        throw new Exception('No pending deliveries found.');
    }
    $keys = array_values(array_unique(array_map(static fn($row) => (string)$row['po_key'], $rows)));
    if (count($keys) !== 1) {
        throw new Exception('Please approve one purchase order at a time.');
    }
    if ($submitted_key !== '' && $submitted_key !== $keys[0]) {
        throw new Exception('Submitted purchase order does not match the selected deliveries.');
    }
}

function next_stock_in_batch_id(PDO $pdo, string $prefix, string $table, string $column): string
{
    $date = date('Ymd');
    $like = "{$prefix}-{$date}-%";
    $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE {$column} LIKE ? ORDER BY {$column} DESC LIMIT 1");
    $stmt->execute([$like]);
    $last = (string)($stmt->fetchColumn() ?: '');
    $next = 1;
    if (preg_match('/-(\d+)$/', $last, $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return "{$prefix}-{$date}-" . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function purchase_order_completed_status(PDO $pdo): string
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        $type = (string)($col['Type'] ?? '');
        if (stripos($type, 'enum(') === 0) {
            if (strpos($type, "'Completed'") !== false) {
                return 'Completed';
            }
            if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $matches)) {
                $values = $matches[1];
                $values[] = 'Completed';
                $enum = implode(',', array_map([$pdo, 'quote'], $values));
                $pdo->exec("ALTER TABLE purchase_orders MODIFY status ENUM({$enum}) NULL DEFAULT 'Pending Admin Validation'");
                return 'Completed';
            }
        }
    } catch (Exception $e) {
        error_log('Unable to add Completed PO status: ' . $e->getMessage());
    }
    return 'Received';
}

function condition_from_variance(float $received, float $ordered, float $damaged): string
{
    if ($damaged > 0 && $received <= 0) {
        return 'Damaged';
    }
    if ($received < $ordered) {
        return 'Short';
    }
    if ($received > $ordered) {
        return 'Excess';
    }
    return 'Good';
}

function resolve_or_create_merchandise_product(PDO $pdo, int $station_id, array $row, array $posted, bool $inv_products_exists = true): int
{
    $name = trim((string)($row['product'] ?? ''));
    if ($name === '') {
        throw new Exception('Delivery item is missing a product name.');
    }

    $category = trim((string)($row['category'] ?? ''));
    if ($category === '' || in_array(strtolower($category), ['fuel', 'fuels'], true)) {
        $category = 'Merchandise';
    }

    $unit = trim((string)($row['unit_display'] ?? $row['unit'] ?? ''));
    if ($unit === '') {
        $unit = 'pcs';
    }

    $sku = trim((string)($row['sku'] ?? ''));
    $cost = round((float)($row['cost_price'] ?? 0), 2);
    $price = round((float)($posted['selling_price'] ?? 0), 2);

    // Try inventory_products first (if available)
    if ($inv_products_exists) {
        try {
            $find = $pdo->prepare("
                SELECT id
                FROM inventory_products
                WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))
                  AND LOWER(COALESCE(category, '')) NOT IN ('fuel', 'fuels')
                ORDER BY
                  CASE WHEN station_id = ? THEN 0 ELSE 1 END,
                  CASE WHEN LOWER(TRIM(COALESCE(category, ''))) = LOWER(TRIM(?)) THEN 0 ELSE 1 END,
                  id ASC
                LIMIT 1
            ");
            $find->execute([$name, $station_id, $category]);
            $existing_id = (int)($find->fetchColumn() ?: 0);

            if ($existing_id > 0) {
                $pdo->prepare("
                    UPDATE inventory_products
                    SET sku = COALESCE(NULLIF(?, ''), sku),
                        unit_cost = CASE WHEN ? > 0 THEN ? ELSE unit_cost END,
                        unit_price = CASE WHEN ? > 0 THEN ? ELSE unit_price END,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$sku, $cost, $cost, $price, $price, $existing_id]);
                return $existing_id;
            }

            $pdo->prepare("
                INSERT INTO inventory_products
                    (station_id, product_name, sku, category, stock, stock_quantity, unit_cost, unit_price, size, updated_at)
                VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    sku = COALESCE(NULLIF(VALUES(sku), ''), sku),
                    unit_cost = CASE WHEN VALUES(unit_cost) > 0 THEN VALUES(unit_cost) ELSE unit_cost END,
                    unit_price = CASE WHEN VALUES(unit_price) > 0 THEN VALUES(unit_price) ELSE unit_price END,
                    updated_at = NOW()
            ")->execute([$station_id, $name, $sku, $category, $cost, $price, $unit]);

            $product_id = (int)$pdo->lastInsertId();
            if ($product_id > 0) {
                return $product_id;
            }
        } catch (Exception $ignored) {}
    }

    // Fallback: use products table
    try {
        $find = $pdo->prepare("
            SELECT p.id FROM products p
            LEFT JOIN product_categories pc ON p.category_id = pc.id
            WHERE LOWER(TRIM(p.name)) = LOWER(TRIM(?))
              AND LOWER(COALESCE(pc.name, '')) NOT IN ('fuel', 'fuel products', 'services')
            LIMIT 1
        ");
        $find->execute([$name]);
        $existing_id = (int)($find->fetchColumn() ?: 0);
        if ($existing_id > 0) {
            return $existing_id;
        }

        // Create new product in products table
        $pdo->prepare("
            INSERT INTO products (name, price, cost, unit, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'active', NOW(), NOW())
        ")->execute([$name, $price, $cost, $unit]);
        $product_id = (int)$pdo->lastInsertId();
        if ($product_id > 0) {
            return $product_id;
        }
    } catch (Exception $e) {
        error_log("resolve_or_create_merchandise_product fallback failed: " . $e->getMessage());
    }

    throw new Exception("Failed to create or locate product '{$name}' in inventory.");
}

function merchandise_stock_before(PDO $pdo, int $station_id, int $product_id, bool $inv_products_exists = true): int
{
    $stmt = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE station_id = ? AND product_id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$station_id, $product_id]);
    $stock = $stmt->fetchColumn();
    if ($stock !== false) {
        return (int)round((float)$stock);
    }

    if ($inv_products_exists) {
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(stock, 0) FROM inventory_products WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$product_id]);
            return (int)round((float)($stmt->fetchColumn() ?: 0));
        } catch (Exception $ignored) {}
    }

    try {
        $stmt = $pdo->prepare("SELECT COALESCE(current_stock, 0) FROM products WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$product_id]);
        return (int)round((float)($stmt->fetchColumn() ?: 0));
    } catch (Exception $ignored) {}

    return 0;
}

function upsert_station_inventory(PDO $pdo, int $station_id, int $product_id, int $qty, float $cost, float $price, string $unit): void
{
    try {
        $check = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id = ? AND product_id = ? LIMIT 1");
        $check->execute([$station_id, $product_id]);
        if ($check->fetchColumn()) {
            $pdo->prepare("
                UPDATE station_inventory
                SET stock_level = COALESCE(stock_level, 0) + ?,
                    cost = ?,
                    price = ?,
                    unit = COALESCE(NULLIF(?, ''), unit),
                    status = 'active',
                    last_updated = NOW()
                WHERE station_id = ? AND product_id = ?
            ")->execute([$qty, $cost, $price, $unit, $station_id, $product_id]);
        } else {
            $pdo->prepare("
                INSERT INTO station_inventory
                    (station_id, product_id, stock_level, cost, price, unit, status, last_updated)
                VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
            ")->execute([$station_id, $product_id, $qty, $cost, $price, $unit]);
        }
    } catch (Exception $e) {
        // Log error but don't fail - station_inventory might have foreign key constraints
        error_log("Station inventory upsert failed for product {$product_id}: " . $e->getMessage());
    }
}

function upsert_merchandise_batch(PDO $pdo, int $station_id, int $product_id, string $batch_id, int $delivery_id, int $qty, float $cost, float $price, string $supplier, int $user_id, string $notes): void
{
    $stmt = $pdo->prepare("SELECT id FROM merchandise_batches WHERE station_id = ? AND product_id = ? AND batch_number = ? LIMIT 1");
    $stmt->execute([$station_id, $product_id, $batch_id]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        $pdo->prepare("
            UPDATE merchandise_batches
            SET quantity_received = quantity_received + ?,
                remaining_qty = remaining_qty + ?,
                unit_cost = ?,
                selling_price = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$qty, $qty, $cost, $price, $existing]);
        return;
    }

    $pdo->prepare("
        INSERT INTO merchandise_batches
            (product_id, station_id, batch_number, delivery_id, quantity_received, remaining_qty,
             unit_cost, selling_price, supplier, date_received, encoded_by, status, notes,
             created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'active', ?, NOW(), NOW())
    ")->execute([$product_id, $station_id, $batch_id, $delivery_id, $qty, $qty, $cost, $price, $supplier, $user_id, $notes]);
}

function resolve_fuel_type_id(PDO $pdo, int $station_id, string $fuel_type, int $preferred_id): int
{
    if ($preferred_id > 0) {
        return $preferred_id;
    }

    $stmt = $pdo->prepare("SELECT fuel_type_id FROM fuel_inventory WHERE station_id = ? AND (LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) OR LOWER(TRIM(fuel_type)) LIKE LOWER(CONCAT(TRIM(?), '%'))) LIMIT 1");
    $stmt->execute([$station_id, $fuel_type, $fuel_type]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) OR LOWER(TRIM(name)) LIKE LOWER(CONCAT(TRIM(?), '%')) LIMIT 1");
    $stmt->execute([$fuel_type, $fuel_type]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    throw new Exception("Fuel type '{$fuel_type}' was not found.");
}

function fuel_inventory_before(PDO $pdo, int $station_id, int $fuel_type_id, string $fuel_type): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM fuel_inventory
        WHERE station_id = ?
          AND (fuel_type_id = ? OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) OR LOWER(TRIM(fuel_type)) LIKE LOWER(CONCAT(TRIM(?), '%')))
        ORDER BY CASE WHEN fuel_type_id = ? THEN 0 ELSE 1 END
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$station_id, $fuel_type_id, $fuel_type, $fuel_type, $fuel_type_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    return ['current_level' => 0, 'current_stock' => 0, 'capacity' => 0, 'reorder_level' => 500, 'critical_level' => 200];
}

function upsert_fuel_inventory(PDO $pdo, int $station_id, int $fuel_type_id, string $fuel_type, float $liters, float $selling_price, int $user_id): void
{
    $find = $pdo->prepare("
        SELECT id
        FROM fuel_inventory
        WHERE station_id = ?
          AND (fuel_type_id = ? OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) OR LOWER(TRIM(fuel_type)) LIKE LOWER(CONCAT(TRIM(?), '%')))
        ORDER BY CASE WHEN fuel_type_id = ? THEN 0 ELSE 1 END
        LIMIT 1
    ");
    $find->execute([$station_id, $fuel_type_id, $fuel_type, $fuel_type, $fuel_type_id]);
    $inventory_id = (int)($find->fetchColumn() ?: 0);

    if ($inventory_id > 0) {
        $pdo->prepare("
        UPDATE fuel_inventory
        SET current_level = COALESCE(current_level, 0) + ?,
            current_stock = COALESCE(current_stock, 0) + ?,
            price_per_liter = ?,
            updated_by = ?,
            status = CASE
                WHEN COALESCE(current_level, 0) + ? <= COALESCE(critical_level, reorder_level, 0) THEN 'Low Stock'
                ELSE 'Normal'
            END,
            last_updated = NOW()
        WHERE id = ?
        ")->execute([$liters, $liters, $selling_price, $user_id, $liters, $inventory_id]);
        return;
    }

    $pdo->prepare("
        INSERT INTO fuel_inventory
            (station_id, fuel_type_id, fuel_type, current_level, current_stock, capacity,
             reorder_level, critical_level, price_per_liter, latest_calibration, status,
             last_updated, updated_by)
        VALUES (?, ?, ?, ?, ?, 0, 500, 200, ?, 0, 'Normal', NOW(), ?)
    ")->execute([$station_id, $fuel_type_id, $fuel_type, $liters, $liters, $selling_price, $user_id]);
}

function upsert_fuel_batch(PDO $pdo, int $station_id, int $fuel_type_id, string $batch_id, int $delivery_id, float $liters, float $cost, float $price, string $supplier, int $user_id, string $notes): void
{
    $stmt = $pdo->prepare("SELECT id FROM fuel_batches WHERE station_id = ? AND fuel_type_id = ? AND batch_number = ? LIMIT 1");
    $stmt->execute([$station_id, $fuel_type_id, $batch_id]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        $pdo->prepare("
            UPDATE fuel_batches
            SET quantity_received = quantity_received + ?,
                remaining_qty = remaining_qty + ?,
                unit_cost = ?,
                selling_price_per_liter = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$liters, $liters, $cost, $price, $existing]);
        return;
    }

    $pdo->prepare("
        INSERT INTO fuel_batches
            (fuel_type_id, station_id, batch_number, delivery_id, quantity_received, remaining_qty,
             unit_cost, selling_price_per_liter, supplier, date_received, encoded_by, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)
    ")->execute([$fuel_type_id, $station_id, $batch_id, $delivery_id, $liters, $liters, $cost, $price, $supplier, $user_id, $notes]);
}

function insert_fuel_movement(PDO $pdo, int $station_id, int $fuel_type_id, string $fuel_type, float $before, float $after, float $liters, int $delivery_id, int $user_id, string $batch_id): void
{
    try {
        $pdo->prepare("
            INSERT INTO fuel_adjustments
                (station_id, fuel_type_id, fuel_type, adjustment_type, liters, previous_value,
                 new_value, reason, user_id, status, approved_by, approved_at, adjustment_date, created_at)
            VALUES (?, ?, ?, 'stock_in', ?, ?, ?, ?, ?, 'Approved', ?, NOW(), CURDATE(), NOW())
        ")->execute([
            $station_id,
            $fuel_type_id,
            $fuel_type,
            $liters,
            $before,
            $after,
            "Manager Fuel Stock-In | Batch: {$batch_id} | Delivery ID: {$delivery_id}",
            $user_id,
            $user_id
        ]);
    } catch (Exception $e) {
        error_log('Fuel movement insert failed: ' . $e->getMessage());
    }
}

function update_fuel_purchase_order_row(PDO $pdo, int $station_id, string $po_key, int $fuel_type_id, string $fuel_type, float $liters, string $delivery_date): void
{
    $stmt = $pdo->prepare("
        SELECT fpo.id
        FROM fuel_purchase_orders fpo
        LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
        WHERE fpo.station_id = ?
          AND (fpo.po_number = ? OR fpo.batch_id = ?)
          AND (fpo.fuel_type_id = ? OR LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(?)))
        ORDER BY fpo.id DESC
        LIMIT 1
    ");
    $stmt->execute([$station_id, $po_key, $po_key, $fuel_type_id, $fuel_type]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        $pdo->prepare("
            UPDATE fuel_purchase_orders
            SET actual_volume = COALESCE(actual_volume, 0) + ?,
                delivery_date = ?,
                status = 'Completed',
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$liters, $delivery_date, $id]);
    }
}

function mark_deliveries_complete(PDO $pdo, array $ids, int $station_id, int $user_id, string $batch_id, string $role = 'manager'): void
{
    if (empty($ids)) {
        return;
    }
    $role_label = ucfirst($role);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([
        'Stock-In Complete',
        $batch_id,
        $user_id,
        "Stock-In approved by {$role_label}. Batch: {$batch_id}",
        $user_id,
        $user_id,
        "Stock-In approved by {$role_label}. Batch: {$batch_id}"
    ], $ids, [$station_id]);
    $pdo->prepare("
        UPDATE deliveries_oversight
        SET status = ?,
            batch_id = ?,
            manager_id = ?,
            manager_action_at = NOW(),
            manager_notes = ?,
            finalized_at = NOW(),
            finalized_by = ?,
            admin_id = COALESCE(admin_id, ?),
            admin_action_at = COALESCE(admin_action_at, NOW()),
            admin_notes = COALESCE(admin_notes, ?),
            updated_at = NOW()
        WHERE id IN ({$placeholders}) AND station_id = ?
    ")->execute($params);
}

function update_merchandise_po_status(PDO $pdo, int $station_id, string $po_key, string $status, int $user_id): void
{
    $pdo->prepare("
        UPDATE purchase_orders
        SET stock_in_done = 1,
            stock_in_at = NOW(),
            stock_in_by = ?,
            status = ?,
            updated_at = NOW()
        WHERE station_id = ?
          AND (po_number = ? OR batch_id = ?)
    ")->execute([$user_id, $status, $station_id, $po_key, $po_key]);

    try {
        $pdo->prepare("
            UPDATE stock_requests sr
            JOIN purchase_orders po ON po.request_id = sr.id
            SET sr.status = 'Received',
                sr.updated_at = NOW()
            WHERE po.station_id = ?
              AND (po.po_number = ? OR po.batch_id = ?)
        ")->execute([$station_id, $po_key, $po_key]);
    } catch (Exception $e) {
        error_log('Stock request received update failed: ' . $e->getMessage());
    }
}

function notify_stock_in_users(
    PDO $pdo,
    int $station_id,
    array $staff_ids,
    string $title,
    string $message,
    string $stock_type = 'fuel',
    ?array $actor = null,
    string $po_key = '',
    string $batch_id = '',
    $total_received = 0
): void
{
    $actor_name = $actor ? (trim(($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? '')) ?: ($actor['username'] ?? 'Station Manager')) : 'Station Manager';
    $actor_role = normalize_role($actor['role'] ?? 'Manager');
    $is_fuel = (stripos($stock_type, 'fuel') !== false || stripos($title, 'fuel') !== false);
    $action_type = $is_fuel ? 'Fuel Delivery Stock-In' : 'Merchandise Delivery Stock-In';
    $ref_label = !empty($po_key) ? "PO {$po_key} (Batch {$batch_id})" : (!empty($batch_id) ? "Batch {$batch_id}" : $title);
    $qty_label = $is_fuel ? number_format((float)$total_received, 2) . ' Liters' : number_format((float)$total_received) . ' units';
    $details = "Approved and stocked in for {$qty_label}.";
    $staff_redirect = $is_fuel ? 'staff_inventory_fuel.php' : 'staff_inventory_merchandise.php';

    // 1. Informative targeted notification directly to encoding staff
    $clean_staff_ids = array_unique(array_filter(array_map('intval', $staff_ids)));
    foreach ($clean_staff_ids as $st_id) {
        if (function_exists('notify_staff_action_result')) {
            notify_staff_action_result(
                $pdo,
                (int)$st_id,
                $action_type,
                'Approved',
                $ref_label,
                $actor_name,
                $actor_role,
                $details,
                'stock_in',
                0,
                $staff_redirect
            );
        }
    }

    // 2. Notify Station Admin(s)
    $stmt = $pdo->prepare("
        SELECT id FROM users
        WHERE role IN ('admin')
          AND station_id = ?
          AND status = 'Active'
    ");
    $stmt->execute([$station_id]);
    $admin_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (!empty($admin_ids)) {
        $admin_url = $is_fuel ? 'admin_inventory_fuel.php' : 'admin_inventory_merchandise.php';
        $adm_stmt = $pdo->prepare("
            INSERT INTO notifications
                (user_id, type, title, message, event_type, severity, redirect_url, status, created_at)
            VALUES (?, 'success', ?, ?, 'stock_in', 'high', ?, 'unread', NOW())
        ");
        foreach ($admin_ids as $adm_id) {
            $adm_stmt->execute([$adm_id, $title, $message, $admin_url]);
        }
    }
}

function audit_stock_in(PDO $pdo, array $me, string $action_type, string $details, string $entity_type, ?int $entity_id): void
{
    try {
        $pdo->prepare("
            INSERT INTO audit_logs
                (user_id, log_type, action_type, action_details, entity_type, entity_id,
                 status, ip_address, user_agent, created_at)
            VALUES (?, 'inventory', ?, ?, ?, ?, 'Success', ?, ?, NOW())
        ")->execute([
            $me['id'],
            $action_type,
            $details,
            $entity_type,
            $entity_id,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log('Stock-in audit log failed: ' . $e->getMessage());
    }
}
