<?php
/**
 * get_transaction_items.php
 * AJAX endpoint — returns item breakdown for a transaction (JSON).
 * Supports: merchandise_transactions, job_orders
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$id         = (int)($_GET['id']     ?? 0);
$source     = trim($_GET['source']  ?? 'merchandise_transactions');
$station_id = (int) user_station_id();

if ($id <= 0) {
    echo json_encode(['items' => [], 'error' => 'Invalid ID']);
    exit;
}

try {
    // ── JOB ORDERS ────────────────────────────────────────────────────────────
    if ($source === 'job_orders') {
        $stmt = $pdo->prepare("
            SELECT id, service_type, service_description, total_cost, estimated_cost,
                   required_parts, vehicle_plate, vehicle_type
            FROM job_orders
            WHERE id = ? AND station_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $station_id]);
        $jo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$jo) {
            echo json_encode(['items' => [], 'error' => 'Job order not found', 'id' => $id, 'source' => $source]);
            exit;
        }

        $total = (float)($jo['total_cost'] ?? $jo['estimated_cost'] ?? 0);
        $items = [];

        // Service fee line
        $items[] = [
            'id'           => null,
            'product_name' => $jo['service_type'] ?? 'Service Fee',
            'item_type'    => 'service',
            'quantity'     => 1,
            'unit_price'   => $total,
            'subtotal'     => $total,
            'product_id'   => null,
        ];

        // Parts from required_parts (JSON or plain text)
        if (!empty($jo['required_parts'])) {
            $parts_raw = $jo['required_parts'];
            $decoded   = json_decode($parts_raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $part) {
                    $pname = is_array($part) ? ($part['name'] ?? $part['part_name'] ?? 'Part') : (string)$part;
                    $pqty  = is_array($part) ? (float)($part['quantity'] ?? 1) : 1;
                    $pprice = is_array($part) ? (float)($part['price'] ?? $part['unit_price'] ?? 0) : 0;
                    if (empty($pname)) continue;
                    $items[] = [
                        'id'           => null,
                        'product_name' => $pname,
                        'item_type'    => 'merchandise',
                        'quantity'     => $pqty,
                        'unit_price'   => $pprice,
                        'subtotal'     => round($pqty * $pprice, 2),
                        'product_id'   => null,
                    ];
                }
            }
        }

        echo json_encode([
            'items'        => $items,
            'service_type' => $jo['service_type'] ?? 'Service',
            'total_cost'   => $total,
            'id'           => $id,
            'source'       => $source,
        ]);
        exit;
    }

    // ── MERCHANDISE TRANSACTIONS ───────────────────────────────────────────────
    // Query transaction row first to obtain transaction_id string, station check, and fallback fields
    $mt = $pdo->prepare("
        SELECT id, transaction_id, item_sku, quantity, unit_price, total_amount,
               job_order_service, job_order_vehicle_plate, job_order_vehicle_type, transaction_type
        FROM merchandise_transactions
        WHERE id = ? AND station_id = ?
        LIMIT 1
    ");
    $mt->execute([$id, $station_id]);
    $tx = $mt->fetch(PDO::FETCH_ASSOC);

    $items = [];
    $txn_code = $tx['transaction_id'] ?? '';

    // First try merchandise_transaction_items detail table matching either id or transaction_id code
    $stmt = $pdo->prepare("
        SELECT
            mti.id,
            COALESCE(NULLIF(TRIM(mti.product_name), ''), 'Merchandise Item') AS product_name,
            COALESCE(mti.item_type, 'merchandise')               AS item_type,
            mti.quantity,
            mti.unit_price,
            COALESCE(mti.subtotal, mti.quantity * mti.unit_price) AS subtotal,
            mti.product_id
        FROM merchandise_transaction_items mti
        WHERE (mti.transaction_id = ? OR mti.transaction_id = ?)
        ORDER BY
            CASE COALESCE(mti.item_type,'merchandise')
                WHEN 'service'     THEN 0
                WHEN 'merchandise' THEN 1
                ELSE 2
            END ASC,
            mti.id ASC
    ");
    $stmt->execute([$id, $txn_code]);
    $raw_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter out invalid items where name is just placeholder dash
    foreach ($raw_items as $it) {
        $pname = trim((string)($it['product_name'] ?? ''));
        if (in_array($pname, ['', '—', '-', 'N/A', 'n/a', 'none', 'None'], true)) {
            $it['product_name'] = 'Merchandise Item';
        }
        $items[] = $it;
    }

    // Fallback: use main transaction row if no valid items in detail table
    if (empty($items) && $tx) {
        $total = (float)($tx['total_amount'] ?? 0);
        $qty   = (float)($tx['quantity'] ?? 1);
        if ($qty <= 0) $qty = 1;
        $unit_price = (float)($tx['unit_price'] ?? 0);
        if ($unit_price <= 0 && $qty > 0 && $total > 0) {
            $unit_price = round($total / $qty, 2);
        }

        $jo_svc = trim((string)($tx['job_order_service'] ?? ''));
        $is_placeholder = in_array($jo_svc, ['', '—', '-', 'N/A', 'n/a', 'none', 'None', 'null', 'NULL', '— (x1)'], true);
        $tx_type = strtolower(trim((string)($tx['transaction_type'] ?? '')));

        if (!$is_placeholder && in_array($tx_type, ['job_order', 'combined'], true)) {
            $items[] = [
                'id'           => null,
                'product_name' => $jo_svc,
                'item_type'    => 'service',
                'quantity'     => 1,
                'unit_price'   => $total,
                'subtotal'     => $total,
                'product_id'   => null,
            ];
        } else {
            // Merchandise transaction
            $item_sku = trim((string)($tx['item_sku'] ?? ''));
            $prod_name = '';

            if ($item_sku !== '' && !in_array($item_sku, ['—', '-', 'N/A', 'n/a'], true)) {
                try {
                    $pst = $pdo->prepare("SELECT product_name FROM products WHERE sku = ? OR name = ? OR id = ? LIMIT 1");
                    $pst->execute([$item_sku, $item_sku, $item_sku]);
                    $pn = $pst->fetchColumn();
                    if ($pn) $prod_name = $pn;
                } catch (Throwable $e) {}

                if (!$prod_name) {
                    try {
                        $ip_st = $pdo->prepare("SELECT product_name FROM inventory_products WHERE product_name = ? OR sku = ? OR id = ? LIMIT 1");
                        $ip_st->execute([$item_sku, $item_sku, $item_sku]);
                        $pn2 = $ip_st->fetchColumn();
                        if ($pn2) $prod_name = $pn2;
                    } catch (Throwable $e) {}
                }

                if (!$prod_name) {
                    $prod_name = $item_sku;
                }
            }

            if (!$prod_name || in_array($prod_name, ['—', '-', 'N/A', 'n/a'], true)) {
                $prod_name = 'Merchandise Item';
            }

            $items[] = [
                'id'           => null,
                'product_name' => $prod_name,
                'item_type'    => 'merchandise',
                'quantity'     => $qty,
                'unit_price'   => $unit_price,
                'subtotal'     => $total > 0 ? $total : round($qty * $unit_price, 2),
                'product_id'   => null,
            ];
        }
    }

    // Compute total from items
    $total_from_items = array_sum(array_column($items, 'subtotal'));
    if ($total_from_items <= 0 && $tx && !empty($tx['total_amount'])) {
        $total_from_items = (float)$tx['total_amount'];
    }

    echo json_encode([
        'items'        => $items,
        'total_amount' => $total_from_items,
        'item_label'   => count($items) . ' item(s)',
        'id'           => $id,
        'source'       => $source,
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode(['items' => [], 'error' => $e->getMessage(), 'id' => $id, 'source' => $source]);
}
