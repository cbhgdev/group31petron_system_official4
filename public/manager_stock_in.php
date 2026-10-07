<?php
// Manager Stock-In
// Approves pending staff-recorded deliveries and updates inventory.
$page_id = 'mgr_stock_in';
$page_title = 'Stock-In';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'], true)) {
    header('Location: dashboard.php');
    exit;
}

$active_type = $_GET['type'] ?? 'merch';
if (!in_array($active_type, ['merch', 'fuel'], true)) {
    $active_type = 'merch';
}

$active_main_tab = $_GET['tab'] ?? 'stockin';
if (!in_array($active_main_tab, ['stockin', 'history'], true)) {
    if (in_array($_GET['tab'] ?? '', ['fuel', 'merch', 'merchandise'], true)) {
        $active_main_tab = 'stockin';
        $active_type = ($_GET['tab'] === 'fuel') ? 'fuel' : 'merch';
    } else {
        $active_main_tab = 'stockin';
    }
}

try {
    $pdo->exec("ALTER TABLE fuel_purchase_orders ADD COLUMN IF NOT EXISTS batch_id VARCHAR(100) NULL DEFAULT NULL");
} catch (Exception $ignored) {}

$session_success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);
if (!empty($_GET['success_msg'])) {
    $session_success = trim($_GET['success_msg']);
}

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'supplier' => trim($_GET['supplier'] ?? ''),
    'delivery_date' => trim($_GET['delivery_date'] ?? ''),
    'status' => trim($_GET['status'] ?? ''),
];

$pending_statuses = ['Pending Stock-In', 'Ready for Stock-In', 'Validated', 'Verified', 'Partial Delivery', 'Damaged Items', 'Adjusted', 'Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Validation', 'Pending Verification', 'Pending Delivery', 'Pending', 'Expected Delivery'];

function si_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function si_money($value): string
{
    return '&#8369;' . number_format((float)$value, 2);
}

function si_qty($value, int $decimals = 0): string
{
    return number_format((float)$value, $decimals);
}

function si_product_id($id): string
{
    $id = (int)$id;
    return $id > 0 ? 'P' . str_pad((string)$id, 4, '0', STR_PAD_LEFT) : '-';
}

function si_extract_invoice(?string $remarks): string
{
    $remarks = (string)$remarks;
    if (preg_match('/Invoice:\s*([^|]+)/i', $remarks, $m)) {
        return trim($m[1]);
    }
    return '';
}

function si_status_sql(array $statuses): string
{
    return implode(',', array_fill(0, count($statuses), '?'));
}

function si_fetch_pending_rows(PDO $pdo, int $station_id, string $type, array $filters, array $statuses): array
{
    $delivery_type = $type === 'fuel' ? 'fuel' : 'merchandise';
    $params = array_merge([$station_id, $delivery_type], $statuses);
    $where = "do2.station_id = ? AND do2.delivery_type = ? AND do2.status IN (" . si_status_sql($statuses) . ")";

    if ($filters['search'] !== '') {
        $where .= " AND (do2.source_ref LIKE ? OR do2.delivery_ref LIKE ? OR do2.dr_number LIKE ? OR do2.product LIKE ? OR do2.supplier LIKE ?)";
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($filters['supplier'] !== '' && strcasecmp($filters['supplier'], 'Petron Corporation') !== 0) {
        $where .= " AND do2.supplier LIKE ?";
        $params[] = '%' . $filters['supplier'] . '%';
    }
    if ($filters['delivery_date'] !== '') {
        $where .= " AND do2.delivery_date = ?";
        $params[] = $filters['delivery_date'];
    }
    if ($filters['status'] !== '' && in_array($filters['status'], $statuses, true)) {
        $where .= " AND do2.status = ?";
        $params[] = $filters['status'];
    }

    if ($type === 'fuel') {
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
                do2.unit,
                do2.delivery_date,
                do2.dr_number,
                do2.remarks,
                do2.status,
                do2.batch_id,
                do2.encoded_by,
                COALESCE(NULLIF(do2.received_by_name, ''), u.name, 'Unknown') AS received_by,
                COALESCE(do2.unit_cost, do2.unit_price,
                    (SELECT fpo.unit_price FROM fuel_purchase_orders fpo
                     LEFT JOIN fuel_types ft ON ft.id = fpo.fuel_type_id
                     WHERE fpo.station_id = do2.station_id
                       AND (fpo.po_number = do2.source_ref OR fpo.batch_id = do2.source_ref)
                       AND LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product))
                     ORDER BY fpo.id DESC LIMIT 1),
                    0
                ) AS cost_price,
                COALESCE(
                    (SELECT fi.price_per_liter FROM fuel_inventory fi
                     LEFT JOIN fuel_types ft ON ft.id = fi.fuel_type_id
                     WHERE fi.station_id = do2.station_id
                       AND (
                         LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(do2.product))
                         OR LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product))
                         OR LOWER(TRIM(fi.fuel_type)) LIKE LOWER(CONCAT(TRIM(do2.product), ' (%'))
                         OR LOWER(TRIM(fi.fuel_type)) LIKE LOWER(CONCAT(TRIM(do2.product), ' %'))
                         OR fi.fuel_type_id = (
                            SELECT fpo2.fuel_type_id FROM fuel_purchase_orders fpo2 
                            WHERE fpo2.station_id = do2.station_id 
                              AND (fpo2.po_number = do2.source_ref OR fpo2.batch_id = do2.source_ref) 
                            LIMIT 1
                         )
                       )
                     ORDER BY
                       CASE
                         WHEN LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(do2.product)) THEN 1
                         WHEN LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product)) THEN 2
                         ELSE 3
                       END
                     LIMIT 1),
                    0
                ) AS current_selling_price,
                COALESCE(
                    (SELECT fi.ugt_no FROM fuel_inventory fi
                     LEFT JOIN fuel_types ft ON ft.id = fi.fuel_type_id
                     WHERE fi.station_id = do2.station_id
                       AND (
                         LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(do2.product))
                         OR LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product))
                         OR LOWER(TRIM(fi.fuel_type)) LIKE LOWER(CONCAT(TRIM(do2.product), ' (%'))
                         OR LOWER(TRIM(fi.fuel_type)) LIKE LOWER(CONCAT(TRIM(do2.product), ' %'))
                         OR fi.fuel_type_id = (
                            SELECT fpo2.fuel_type_id FROM fuel_purchase_orders fpo2 
                            WHERE fpo2.station_id = do2.station_id 
                              AND (fpo2.po_number = do2.source_ref OR fpo2.batch_id = do2.source_ref) 
                            LIMIT 1
                         )
                       )
                     ORDER BY
                       CASE
                         WHEN LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(do2.product)) THEN 1
                         WHEN LOWER(TRIM(COALESCE(ft.name, ''))) = LOWER(TRIM(do2.product)) THEN 2
                         ELSE 3
                       END
                     LIMIT 1),
                    ''
                ) AS ugt_no
            FROM deliveries_oversight do2
            LEFT JOIN users u ON u.id = do2.encoded_by
            WHERE {$where}
            ORDER BY do2.delivery_date ASC, do2.created_at ASC, do2.id ASC
        ";
    } else {
        // Check if inventory_products is accessible (InnoDB engine file may be missing)
        $inv_accessible = false;
        try { $pdo->query("SELECT 1 FROM inventory_products LIMIT 1"); $inv_accessible = true; } catch (Throwable $e) {}

        if ($inv_accessible) {
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
                do2.delivery_date,
                do2.delivery_time,
                do2.dr_number,
                do2.remarks,
                do2.status,
                do2.batch_id,
                do2.encoded_by,
                COALESCE(NULLIF(do2.received_by_name, ''), u.name, 'Unknown') AS received_by,
                ip.id AS product_id,
                ip.sku,
                ip.category,
                COALESCE(si.unit, ip.size, do2.unit, 'Piece') AS unit_display,
                COALESCE(do2.unit_cost, do2.unit_price,
                    (SELECT po.unit_price FROM purchase_orders po
                     WHERE po.station_id = do2.station_id
                       AND (po.po_number = do2.source_ref OR po.batch_id = do2.source_ref)
                       AND LOWER(TRIM(po.product_name)) = LOWER(TRIM(do2.product))
                     ORDER BY po.id DESC LIMIT 1),
                    ip.unit_cost, 0
                ) AS cost_price,
                COALESCE(si.price, ip.unit_price, 0) AS current_selling_price,
                (SELECT po.id FROM purchase_orders po
                 WHERE po.station_id = do2.station_id
                   AND (po.po_number = do2.source_ref OR po.batch_id = do2.source_ref)
                   AND LOWER(TRIM(po.product_name)) = LOWER(TRIM(do2.product))
                 ORDER BY po.id DESC LIMIT 1) AS po_id,
                COALESCE(
                    (SELECT sr.request_no FROM stock_requests sr
                     JOIN purchase_orders po ON po.request_id = sr.id
                     WHERE po.station_id = do2.station_id
                       AND (po.po_number = do2.source_ref OR po.batch_id = do2.source_ref)
                       AND LOWER(TRIM(po.product_name)) = LOWER(TRIM(do2.product))
                     ORDER BY sr.id DESC LIMIT 1),
                    do2.source_ref
                ) AS purchase_request_no
            FROM deliveries_oversight do2
            LEFT JOIN users u ON u.id = do2.encoded_by
            LEFT JOIN inventory_products ip
                   ON ip.station_id = do2.station_id
                  AND LOWER(TRIM(ip.product_name)) = LOWER(TRIM(do2.product))
                  AND LOWER(COALESCE(ip.category, '')) NOT IN ('fuel','fuels')
            LEFT JOIN station_inventory si
                   ON si.product_id = ip.id AND si.station_id = do2.station_id
            WHERE {$where}
            ORDER BY do2.delivery_date ASC, do2.created_at ASC, do2.id ASC
        ";
        } else {
            // Fallback: no inventory_products JOIN (engine file missing/corrupt)
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
                do2.delivery_date,
                do2.delivery_time,
                do2.dr_number,
                do2.remarks,
                do2.status,
                do2.batch_id,
                do2.encoded_by,
                COALESCE(NULLIF(do2.received_by_name, ''), u.name, 'Unknown') AS received_by,
                NULL AS product_id,
                NULL AS sku,
                NULL AS category,
                COALESCE(do2.unit, 'Piece') AS unit_display,
                COALESCE(do2.unit_cost, do2.unit_price, 0) AS cost_price,
                0 AS current_selling_price,
                NULL AS po_id,
                do2.source_ref AS purchase_request_no
            FROM deliveries_oversight do2
            LEFT JOIN users u ON u.id = do2.encoded_by
            WHERE {$where}
            ORDER BY do2.delivery_date ASC, do2.created_at ASC, do2.id ASC
        ";
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function si_group_rows(array $rows, string $type): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = $row['po_key'] ?: $row['delivery_ref'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'id' => substr(md5($type . '-' . $key), 0, 12),
                'po_no' => $key,
                'purchase_request_no' => $row['purchase_request_no'] ?? ($row['source_ref'] ?? ''),
                'supplier' => !empty($row['supplier']) ? $row['supplier'] : 'Petron Corporation',
                'delivery_ref' => $row['delivery_ref'] ?? '',
                'delivery_date' => $row['delivery_date'] ?? '',
                'delivery_time' => $row['delivery_time'] ?? '',
                'received_by' => $row['received_by'] ?? 'Unknown',
                'dr_number' => $row['dr_number'] ?? '',
                'sales_invoice' => si_extract_invoice($row['remarks'] ?? ''),
                'status' => $row['status'] ?? 'Pending Stock-In',
                'batch_id' => $row['batch_id'] ?? '',
                'rows' => [],
            ];
        }
        $groups[$key]['rows'][] = $row;
    }
    return $groups;
}

// Auto-load approved POs into deliveries_oversight if any are missing
if (function_exists('auto_load_pending_deliveries_from_approved_pos')) {
    auto_load_pending_deliveries_from_approved_pos($pdo, $station_id);
}

$merch_rows = si_fetch_pending_rows($pdo, $station_id, 'merch', $filters, $pending_statuses);
$fuel_rows = si_fetch_pending_rows($pdo, $station_id, 'fuel', $filters, $pending_statuses);
$merch_groups = si_group_rows($merch_rows, 'merch');
$fuel_groups = si_group_rows($fuel_rows, 'fuel');

// If type wasn't explicitly provided in URL and merch has no pending items but fuel does, auto-switch to fuel
if (empty($_GET['type']) && empty($merch_groups) && !empty($fuel_groups)) {
    $active_type = 'fuel';
}
$active_groups = $active_type === 'fuel' ? $fuel_groups : $merch_groups;

$today = date('Y-m-d');
$today_deliveries = 0;
foreach (array_merge($merch_groups, $fuel_groups) as $group) {
    if (($group['delivery_date'] ?? '') === $today) {
        $today_deliveries++;
    }
}

function si_fetch_history_list(PDO $pdo, int $station_id): array
{
    $history_list = [];
    $tracked_keys = [];

    // 1. Fetch completed stock-in records from deliveries_oversight
    try {
        $stmt = $pdo->prepare("
            SELECT do2.*,
                   COALESCE(NULLIF(do2.batch_id, ''), msi.batch_ref, fsi.batch_ref) AS matched_batch_ref,
                   COALESCE(NULLIF(do2.unit_cost, 0), msi.unit_cost, fb.unit_cost, do2.unit_price, 0) AS matched_unit_cost,
                   COALESCE(msi.selling_price, fsi.selling_price_per_liter, 0) AS matched_selling_price,
                   COALESCE(msi.condition_flag, fsi.condition_flag, CASE WHEN do2.damaged_quantity > 0 THEN 'Damaged' ELSE 'Good' END) AS matched_condition,
                   COALESCE(NULLIF(do2.received_by_name, ''), u_enc.name, u_enc.username, 'Staff') AS received_by_display,
                   COALESCE(u_mgr.name, u_mgr.username, u_fin.name, u_fin.username, 'Manager') AS manager_display
            FROM deliveries_oversight do2
            LEFT JOIN merchandise_stock_in msi ON msi.delivery_id = do2.id
            LEFT JOIN fuel_stock_in fsi ON fsi.delivery_id = do2.id
            LEFT JOIN fuel_batches fb ON fb.delivery_id = do2.id
            LEFT JOIN users u_enc ON u_enc.id = do2.encoded_by
            LEFT JOIN users u_mgr ON u_mgr.id = do2.manager_id
            LEFT JOIN users u_fin ON u_fin.id = do2.finalized_by
            WHERE do2.station_id = ?
              AND do2.status NOT IN ('Pending Stock-In', 'Ready for Stock-In', 'Validated', 'Verified', 'Cancelled', 'Rejected')
              AND (
                  do2.status IN ('Stock-In Complete', 'Stocked-In', 'Completed', 'Approved')
                  OR do2.finalized_at IS NOT NULL
                  OR do2.manager_action_at IS NOT NULL
              )
            ORDER BY COALESCE(do2.finalized_at, do2.manager_action_at, do2.delivery_date, do2.created_at) DESC, do2.id DESC
        ");
        $stmt->execute([$station_id]);
        $comp_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($comp_rows as $row) {
            $b_key = trim((string)($row['matched_batch_ref'] ?: ($row['batch_id'] ?? '')));
            $d_ref = trim((string)($row['delivery_ref'] ?? ''));
            $s_ref = trim((string)($row['source_ref'] ?? ''));
            $dr    = trim((string)($row['dr_number'] ?? ''));

            $group_key = $b_key ?: ($d_ref ?: ($s_ref ? ($s_ref . '_' . ($row['delivery_date'] ?? '')) : ('DO-' . $row['id'])));

            if (!isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'key'              => $group_key,
                    'batch_id'         => $b_key ?: ($d_ref ?: ($s_ref ?: '—')),
                    'delivery_ref'     => $d_ref,
                    'po_number'        => $s_ref ?: ($d_ref ?: '—'),
                    'dr_number'        => $dr,
                    'delivery_type'    => ($row['delivery_type'] === 'fuel') ? 'fuel' : 'merchandise',
                    'supplier'         => $row['supplier'] ?: 'Petron Corporation',
                    'delivery_date'    => $row['delivery_date'] ?? '',
                    'delivery_time'    => $row['delivery_time'] ?? '',
                    'stock_in_date'    => $row['finalized_at'] ?: ($row['manager_action_at'] ?: ($row['delivery_date'] ?: ($row['created_at'] ?? ''))),
                    'stocked_by'       => $row['manager_display'] ?: 'Manager',
                    'received_by'      => $row['received_by_display'] ?: 'Staff',
                    'status'           => $row['status'] ?: 'Stock-In Complete',
                    'remarks'          => $row['manager_notes'] ?: ($row['remarks'] ?? ''),
                    'items'            => [],
                    'total_qty'        => 0,
                    'total_cost'       => 0,
                ];
            }

            $actual_qty = (float)($row['actual_quantity'] !== null ? $row['actual_quantity'] : ($row['quantity'] ?? 0));
            $unit_cost  = (float)($row['matched_unit_cost'] ?: ($row['unit_cost'] ?? ($row['unit_price'] ?? 0)));
            $sell_price = (float)($row['matched_selling_price'] ?: ($row['selling_price'] ?? 0));
            $item_total = $actual_qty * $unit_cost;
            $condition  = !empty($row['matched_condition']) ? $row['matched_condition'] : (($row['damaged_quantity'] > 0) ? 'Damaged' : 'Good');

            $groups[$group_key]['items'][] = [
                'name'         => $row['product'],
                'sku'          => '',
                'qty_ordered'  => (float)($row['expected_quantity'] ?? ($row['quantity'] ?? 0)),
                'qty_received' => $actual_qty,
                'unit'         => $row['unit'] ?: (($row['delivery_type'] === 'fuel') ? 'L' : 'pcs'),
                'unit_cost'    => $unit_cost,
                'selling_price'=> $sell_price,
                'total_cost'   => $item_total,
                'condition'    => $condition
            ];
            $groups[$group_key]['total_qty']  += $actual_qty;
            $groups[$group_key]['total_cost'] += $item_total;

            if ($b_key !== '') $tracked_keys[$b_key] = true;
            if ($d_ref !== '') $tracked_keys[$d_ref] = true;
            if ($dr !== '')    $tracked_keys[$dr]    = true;
            if (!empty($row['id'])) $tracked_keys['DO-' . $row['id']] = true;
        }

        foreach ($groups as $g) {
            $history_list[] = $g;
        }
    } catch (Exception $e) {
        error_log('si_fetch_history_list oversight error: ' . $e->getMessage());
    }

    // 2. Fetch from merchandise_stock_in for any batches not yet captured
    try {
        $stmt_msi = $pdo->prepare("
            SELECT msi.*, 
                   COALESCE(u.name, u.username, 'Manager') AS encoded_by_name
            FROM merchandise_stock_in msi
            LEFT JOIN users u ON u.id = msi.encoded_by
            WHERE msi.station_id = ?
            ORDER BY msi.encoded_at DESC, msi.id DESC
        ");
        $stmt_msi->execute([$station_id]);
        $msi_rows = $stmt_msi->fetchAll(PDO::FETCH_ASSOC);

        $msi_groups = [];
        foreach ($msi_rows as $row) {
            $b_ref = trim((string)($row['batch_ref'] ?? ''));
            $po_no = trim((string)($row['po_number'] ?? ''));
            $g_key = $b_ref ?: ($po_no ?: ('MSI-' . $row['id']));

            if (isset($tracked_keys[$g_key]) || ($b_ref && isset($tracked_keys[$b_ref])) || (!empty($row['delivery_id']) && isset($tracked_keys['DO-' . $row['delivery_id']]))) {
                continue;
            }

            if (!isset($msi_groups[$g_key])) {
                $msi_groups[$g_key] = [
                    'key'              => $g_key,
                    'batch_id'         => $b_ref ?: $g_key,
                    'delivery_ref'     => $b_ref,
                    'po_number'        => $po_no ?: '—',
                    'dr_number'        => '',
                    'delivery_type'    => 'merchandise',
                    'supplier'         => 'Petron Corporation',
                    'delivery_date'    => $row['encoded_at'] ? date('Y-m-d', strtotime($row['encoded_at'])) : '',
                    'delivery_time'    => $row['encoded_at'] ? date('H:i', strtotime($row['encoded_at'])) : '',
                    'stock_in_date'    => $row['encoded_at'] ?? '',
                    'stocked_by'       => $row['encoded_by_name'] ?: 'Manager',
                    'received_by'      => 'Staff',
                    'status'           => 'Stock-In Complete',
                    'remarks'          => $row['remarks'] ?? '',
                    'items'            => [],
                    'total_qty'        => 0,
                    'total_cost'       => 0,
                ];
            }

            $qty_rec   = (float)$row['qty_received'];
            $unit_cost = (float)$row['unit_cost'];
            $tot_cost  = (float)($row['total_cost'] ?: ($qty_rec * $unit_cost));

            $msi_groups[$g_key]['items'][] = [
                'name'         => $row['product_name'],
                'sku'          => $row['sku'] ?? '',
                'qty_ordered'  => (float)$row['qty_ordered'],
                'qty_received' => $qty_rec,
                'unit'         => 'pcs',
                'unit_cost'    => $unit_cost,
                'selling_price'=> (float)$row['selling_price'],
                'total_cost'   => $tot_cost,
                'condition'    => $row['condition_flag'] ?: 'Good'
            ];
            $msi_groups[$g_key]['total_qty']  += $qty_rec;
            $msi_groups[$g_key]['total_cost'] += $tot_cost;
            if ($b_ref) $tracked_keys[$b_ref] = true;
            if (!empty($row['delivery_id'])) $tracked_keys['DO-' . $row['delivery_id']] = true;
        }

        foreach ($msi_groups as $g) {
            $history_list[] = $g;
        }
    } catch (Exception $e) {}

    // 3. Fetch from fuel_stock_in for any batches not yet captured
    try {
        $stmt_fsi = $pdo->prepare("
            SELECT fsi.*, 
                   COALESCE(fb.unit_cost, (SELECT fpo.unit_price FROM fuel_purchase_orders fpo WHERE fpo.station_id = fsi.station_id AND (fpo.po_number = fsi.delivery_ref OR fpo.batch_id = fsi.batch_ref) LIMIT 1), 0) AS batch_unit_cost,
                   COALESCE(u.name, u.username, 'Manager') AS encoded_by_name
            FROM fuel_stock_in fsi
            LEFT JOIN fuel_batches fb ON (fb.delivery_id = fsi.delivery_id OR fb.batch_number = fsi.batch_ref)
            LEFT JOIN users u ON u.id = fsi.encoded_by
            WHERE fsi.station_id = ?
            ORDER BY fsi.encoded_at DESC, fsi.id DESC
        ");
        $stmt_fsi->execute([$station_id]);
        $fsi_rows = $stmt_fsi->fetchAll(PDO::FETCH_ASSOC);

        $fsi_groups = [];
        foreach ($fsi_rows as $row) {
            $b_ref = trim((string)($row['batch_ref'] ?? ''));
            $d_ref = trim((string)($row['delivery_ref'] ?? ''));
            $g_key = $b_ref ?: ($d_ref ?: ('FSI-' . $row['id']));

            if (isset($tracked_keys[$g_key]) || ($b_ref && isset($tracked_keys[$b_ref])) || (!empty($row['delivery_id']) && isset($tracked_keys['DO-' . $row['delivery_id']]))) {
                continue;
            }

            if (!isset($fsi_groups[$g_key])) {
                $fsi_groups[$g_key] = [
                    'key'              => $g_key,
                    'batch_id'         => $b_ref ?: $g_key,
                    'delivery_ref'     => $d_ref ?: $b_ref,
                    'po_number'        => $d_ref ?: '—',
                    'dr_number'        => $row['invoice_no'] ?? '',
                    'delivery_type'    => 'fuel',
                    'supplier'         => 'Petron Corporation',
                    'delivery_date'    => $row['encoded_at'] ? date('Y-m-d', strtotime($row['encoded_at'])) : '',
                    'delivery_time'    => $row['encoded_at'] ? date('H:i', strtotime($row['encoded_at'])) : '',
                    'stock_in_date'    => $row['encoded_at'] ?? '',
                    'stocked_by'       => $row['encoded_by_name'] ?: 'Manager',
                    'received_by'      => 'Staff',
                    'status'           => 'Stock-In Complete',
                    'remarks'          => $row['remarks'] ?? '',
                    'items'            => [],
                    'total_qty'        => 0,
                    'total_cost'       => 0,
                ];
            }

            $qty_rec   = (float)$row['qty_received'];
            $unit_cost = (float)($row['batch_unit_cost'] ?? 0);
            $sell_pr   = (float)($row['selling_price_per_liter'] ?? 0);
            $tot_cost  = $qty_rec * $unit_cost;

            $fsi_groups[$g_key]['items'][] = [
                'name'         => $row['fuel_type'],
                'sku'          => '',
                'qty_ordered'  => (float)$row['qty_expected'],
                'qty_received' => $qty_rec,
                'unit'         => 'L',
                'unit_cost'    => $unit_cost,
                'selling_price'=> $sell_pr,
                'total_cost'   => $tot_cost,
                'condition'    => $row['condition_flag'] ?: 'Good'
            ];
            $fsi_groups[$g_key]['total_qty']  += $qty_rec;
            $fsi_groups[$g_key]['total_cost'] += $tot_cost;
            if ($b_ref) $tracked_keys[$b_ref] = true;
            if (!empty($row['delivery_id'])) $tracked_keys['DO-' . $row['delivery_id']] = true;
        }

        foreach ($fsi_groups as $g) {
            $history_list[] = $g;
        }
    } catch (Exception $e) {}

    // Sort by stock_in_date descending
    usort($history_list, function($a, $b) {
        $ta = strtotime($a['stock_in_date'] ?: ($a['delivery_date'] ?: 'now'));
        $tb = strtotime($b['stock_in_date'] ?: ($b['delivery_date'] ?: 'now'));
        return $tb - $ta;
    });

    return $history_list;
}

$stock_in_history_list = si_fetch_history_list($pdo, $station_id);
$cnt_si_hist_total  = count($stock_in_history_list);
$cnt_si_hist_fuel   = count(array_filter($stock_in_history_list, fn($r) => $r['delivery_type'] === 'fuel'));
$cnt_si_hist_merch  = count(array_filter($stock_in_history_list, fn($r) => $r['delivery_type'] === 'merchandise'));
$cnt_si_hist_volume = array_sum(array_map(fn($r) => $r['delivery_type'] === 'fuel' ? (float)$r['total_qty'] : 0, $stock_in_history_list));
$cnt_si_hist_units  = array_sum(array_map(fn($r) => $r['delivery_type'] === 'merchandise' ? (float)$r['total_qty'] : 0, $stock_in_history_list));

$si_hist_suppliers = ['Petron Corporation' => true];
foreach ($stock_in_history_list as $h_item) {
    $sup = trim($h_item['supplier'] ?? '');
    if ($sup !== '') {
        $si_hist_suppliers[$sup] = true;
    }
}
ksort($si_hist_suppliers);

$supplier_options_map = ['Petron Corporation' => true];
foreach (array_merge($merch_rows, $fuel_rows) as $pr_row) {
    $sup = trim($pr_row['supplier'] ?? '');
    if ($sup !== '') $supplier_options_map[$sup] = true;
}
$supplier_options = array_keys($supplier_options_map);
sort($supplier_options);

function si_tab_url(string $type, array $filters): string
{
    $params = array_filter(array_merge(['type' => $type], $filters), static fn($value) => $value !== '');
    return 'manager_stock_in.php?' . http_build_query($params);
}

// Compute absolute base URL to avoid Edge trailing-slash relative path issues
$_si_base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
if ($_si_base === '' || $_si_base === '.') $_si_base = '';

include __DIR__ . '/../partials/header.php';
?>

<style>
body .main,
.main,
.main-content {
    padding: 0 !important;
}

.stock-page {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    padding: 0 !important;
    margin: 0 !important;
    color: #1e293b;
    background: transparent;
}
.stock-head{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:15px!important;margin-top:0!important;margin-bottom:25px!important;}
.stock-title{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif!important;font-size:24px!important;font-weight:700!important;color:#002f70!important;margin:0!important;line-height:1.2!important;display:flex!important;align-items:center!important;gap:10px!important;text-transform:uppercase!important;letter-spacing:0.5px!important;}
.stock-sub{font-size:13px;color:#64748b;margin-top:4px;font-weight:600;text-transform:none;letter-spacing:.3px;}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-bottom:16px;width:100%;box-sizing:border-box;}
.summary-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(15,23,42,.04);}
.summary-label{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:800;}
.summary-value{font-size:28px;color:#002F70;font-weight:850;margin-top:4px;}
.summary-icon{width:38px;height:38px;border-radius:8px;background:#eff6ff;color:#002F70;display:flex;align-items:center;justify-content:center;font-size:17px;}
/* Main Page Tabs (Stock-In / Stock-In History - matches Manager Purchase Management style) */
.tab-nav {
    display: flex !important;
    gap: 0 !important;
    border: 1px solid #d1d9e6 !important;
    border-radius: 0 !important;
    overflow: hidden !important;
    background: #ffffff !important;
    margin-bottom: 20px !important;
    border-bottom: 3px solid #00264D !important;
    width: 100% !important;
}
.tab-btn {
    flex: 1 !important;
    min-width: 150px !important;
    padding: 12px 16px !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    color: #334155 !important;
    background: #ffffff !important;
    border: none !important;
    border-right: 1px solid #d1d9e6 !important;
    border-radius: 0 !important;
    text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    text-align: center !important;
    cursor: pointer !important;
    margin-bottom: 0 !important;
    box-shadow: none !important;
    white-space: nowrap;
}
.tab-btn:last-child { border-right: none !important; }
.tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.tab-btn.active {
    background: #00264D !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    box-shadow: none !important;
}
.tab-btn.active *, .tab-btn.active span, .tab-btn.active i { color: #ffffff !important; }

/* Sub-tab nav (Merchandise / Fuel inside Stock-In) */
.sub-tab-nav {
    display: flex !important;
    flex-wrap: wrap !important;
    margin: 8px 0 20px !important;
    border: 1px solid #d1d9e6 !important;
    border-radius: 0 !important;
    overflow: hidden !important;
    border-bottom: 3px solid #00264D !important;
    gap: 0 !important;
    width: 100% !important;
    background: #fff !important;
}
.sub-tab-nav-btn {
    flex: 1 !important;
    min-width: 120px !important;
    padding: 11px 16px !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    color: #334155 !important;
    background: #ffffff !important;
    border: none !important;
    border-right: 1px solid #d1d9e6 !important;
    border-bottom: none !important;
    text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    cursor: pointer !important;
    margin-bottom: 0 !important;
}
.sub-tab-nav-btn:last-child { border-right: none !important; }
.sub-tab-nav-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.sub-tab-nav-btn.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; }
.sub-tab-nav-btn.active * { color: #ffffff !important; }

/* Backward-compatible stock-tabs */
.stock-tabs { display: flex !important; flex-wrap: wrap !important; margin: 8px 0 20px !important; border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; border-bottom: 3px solid #00264D !important; gap: 0 !important; }
.stock-tab { flex: 1 !important; min-width: 120px !important; padding: 11px 16px !important; font-size: 11.5px !important; font-weight: 700 !important; color: #334155 !important; background: #ffffff !important; border: none !important; border-right: 1px solid #d1d9e6 !important; border-bottom: none !important; text-decoration: none !important; transition: all 0.15s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 7px !important; text-transform: uppercase !important; letter-spacing: 0.3px !important; cursor: pointer !important; }
.stock-tab:last-child { border-right: none !important; }
.stock-tab:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.stock-tab.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; }

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
.filter-panel{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:16px;box-shadow:0 2px 8px rgba(15,23,42,.04);width:100%;box-sizing:border-box;}
.filter-grid{display:grid;grid-template-columns:2fr 1.4fr 1fr 1fr auto;gap:10px;align-items:end;}
.field label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.45px;font-weight:800;color:#64748b;margin-bottom:5px;}
.field input,.field select{width:100%;height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px;font-size:12px;color:#1e293b;background:#fff;box-sizing:border-box;}
.field input:focus,.field select:focus{outline:none;border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,.12);}
.filter-actions{display:flex;gap:8px;}
.si-btn{height:38px;border-radius:6px;border:1px solid transparent;padding:0 13px;font-size:12px;font-weight:800;display:inline-flex;align-items:center;gap:7px;cursor:pointer;text-decoration:none;white-space:nowrap;}
.si-btn.primary{background:#002F70!important;color:#fff!important;border-color:#002F70!important;}
.si-btn.outline{background:#fff!important;color:#475569!important;border-color:#cbd5e1!important;}
.si-btn.success{background:#16a34a!important;color:#fff!important;border-color:#16a34a!important;}
.si-btn:disabled{opacity:.55;cursor:not-allowed;}
.table-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow-x:auto;overflow-y:visible;box-shadow:0 2px 8px rgba(15,23,42,.04);width:100%;box-sizing:border-box;}
.table-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;border-bottom:1px solid #e2e8f0;}
.table-title{font-size:15px;font-weight:850;color:#002F70;display:flex;align-items:center;gap:8px;}
.stock-table{width:100%;border-collapse:collapse;font-size:12px;}
.stock-table th{background:#002F70;color:#fff;text-align:left;padding:11px 12px;text-transform:uppercase;letter-spacing:.45px;font-size:10px;}
.stock-table td{padding:11px 12px;border-bottom:1px solid #eef2f7;vertical-align:middle;overflow-wrap:anywhere;}
.click-row{cursor:pointer;}
.click-row:hover td{background:#eff6ff;}
.po-link{font-family:Consolas,monospace;font-weight:850;color:#002F70;}
.status-pill{display:inline-flex;align-items:center;gap:5px;color:#b45309;font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:.35px;}
.detail-row{display:none;background:#f8fafc;}
.detail-row.open{display:table-row;}
.detail-cell{padding:0!important;width:100%;}
.detail-panel{padding:18px;padding-bottom:100px;border-top:1px solid #dbeafe;box-sizing:border-box;max-width:100%;overflow:visible;}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin-bottom:16px;}
.info-item{background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;}
.info-item label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.45px;font-weight:850;color:#64748b;margin-bottom:3px;}
.info-item span{font-size:13px;font-weight:750;color:#0f172a;}
.verify-wrap{max-width:100%;min-width:0;overflow-x:auto;border:1px solid #dbeafe;border-radius:8px;background:#fff;}
.verify-table{width:100%;border-collapse:collapse;font-size:12px;min-width:920px;}
.verify-table th{background:#eaf2ff;color:#002F70;text-align:left;padding:10px;font-size:10px;text-transform:uppercase;letter-spacing:.4px;}
.verify-table td{padding:9px 10px;border-top:1px solid #eef2f7;}
.verify-table input{height:34px;border:1px solid #cbd5e1;border-radius:6px;padding:0 8px;font-size:12px;box-sizing:border-box;}
.qty-input{width:96px;text-align:right;}
.price-input{width:120px;text-align:right;}
.readonly-money{font-weight:800;color:#475569;}
.detail-summary{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-top:14px;padding:14px 0 16px;border-top:2px solid #dbeafe;background:#f8fafc;z-index:5;}
.summary-inline{display:flex;gap:18px;flex-wrap:wrap;font-size:12px;color:#475569;}
.summary-inline strong{color:#002F70;font-size:14px;}
.approve-btn{flex:0 0 auto;justify-content:center;}
.empty-state{text-align:center;padding:70px 20px;color:#64748b;}
.empty-state i{font-size:42px;color:#16a34a;display:block;margin-bottom:12px;}
#stockToast.stock-toast{display:none;position:fixed!important;top:96px!important;right:22px!important;left:auto!important;bottom:auto!important;transform:translateX(30px)!important;z-index:2147483000!important;box-sizing:border-box!important;width:fit-content!important;min-width:280px!important;max-width:min(440px,calc(100vw - 32px))!important;height:auto!important;min-height:0!important;max-height:140px!important;overflow:auto!important;border-radius:10px!important;padding:14px 18px!important;color:#fff!important;font-size:13.5px!important;line-height:1.4!important;font-weight:700!important;text-align:left!important;white-space:normal!important;overflow-wrap:break-word!important;box-shadow:0 12px 28px rgba(15,23,42,.25)!important;opacity:0;pointer-events:none;transition:opacity .25s ease,transform .25s ease;}
#stockToast.stock-toast.is-visible{display:block!important;opacity:1;transform:translateX(0)!important;}
#stockToast.stock-toast.toast-ok{background:#16a34a!important;}
#stockToast.stock-toast.toast-err{background:#dc2626!important;}
/* Confirm Modal Overlay and Buttons (Override global button styling) */
#siConfirmOverlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65) !important;
    z-index: 99999 !important;
    align-items: center;
    justify-content: center;
}
#siConfirmOverlay .confirm-card {
    background: #ffffff !important;
    border-radius: 12px !important;
    padding: 26px 30px !important;
    max-width: 440px !important;
    width: 90% !important;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35) !important;
    position: relative !important;
}
#siConfirmOverlay #siConfirmCancelBtn {
    padding: 10px 22px !important;
    border: 1.5px solid #dc2626 !important;
    border-radius: 7px !important;
    background: #dc2626 !important;
    background-color: #dc2626 !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    font-weight: 700 !important;
    font-size: 13.5px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.25) !important;
    transition: all 0.15s ease !important;
}
#siConfirmOverlay #siConfirmCancelBtn:hover {
    background: #b91c1c !important;
    background-color: #b91c1c !important;
    border-color: #b91c1c !important;
}
#siConfirmOverlay #siConfirmOkBtn {
    padding: 10px 22px !important;
    border: 1.5px solid #16a34a !important;
    border-radius: 7px !important;
    background: #16a34a !important;
    background-color: #16a34a !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    font-weight: 800 !important;
    font-size: 13.5px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    box-shadow: 0 2px 6px rgba(22, 163, 74, 0.25) !important;
    transition: all 0.15s ease !important;
}
/* Stock-In Success Banner */
.stock-banner {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 22px;
    position: relative;
    box-shadow: 0 4px 14px rgba(22, 163, 74, 0.14);
    animation: stockBannerSlideDown 0.3s ease-out;
}
@keyframes stockBannerSlideDown {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}
.stock-banner-success {
    background: #f0fdf4 !important;
    border: 1.5px solid #86efac !important;
    color: #166534 !important;
}
.stock-banner-icon {
    font-size: 28px;
    color: #16a34a;
    flex-shrink: 0;
}
.stock-banner-content {
    flex: 1;
    min-width: 0;
}
.stock-banner-title {
    font-size: 15.5px;
    font-weight: 800;
    color: #14532d;
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.stock-banner-desc {
    font-size: 13.5px;
    color: #166534;
    line-height: 1.45;
    font-weight: 600;
}
.stock-banner-meta {
    margin-top: 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.stock-banner-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #dcfce7;
    border: 1px solid #bbf7d0;
    color: #15803d;
    padding: 3px 9px;
    border-radius: 5px;
    font-size: 12px;
    font-weight: 700;
    font-family: Consolas, monospace;
}
@media print{#stockToast.stock-toast,#siConfirmOverlay,#stockSuccessBanner{display:none!important;}}
@media(max-width:900px){
    .stock-page{padding:16px 12px 48px;}
    .filter-grid{grid-template-columns:1fr;}
    .filter-actions{justify-content:flex-start;}
    .detail-summary{align-items:stretch;justify-content:flex-start;}
    .approve-btn{width:100%;}
    .stock-banner{flex-direction:column;align-items:flex-start;gap:12px;}
}
</style>

<div class="stock-page">
    <div class="stock-head">
        <div>
            <h1 class="stock-title"><i class="fas fa-dolly"></i> Stock-In</h1>
        </div>
    </div>

    <!-- Main Page Tabs -->
    <div class="tab-nav">
        <button type="button" id="mainTabStockInBtn" onclick="switchStockInMainTab('stockin')" class="tab-btn <?= $active_main_tab !== 'history' ? 'active' : '' ?>">
            <i class="fas fa-dolly"></i> Stock-In
        </button>
        <button type="button" id="mainTabHistoryBtn" onclick="switchStockInMainTab('history')" class="tab-btn <?= $active_main_tab === 'history' ? 'active' : '' ?>">
            <i class="fas fa-history"></i> Stock-In History
        </button>
    </div>

    <!-- Stock-In Section (Pending Deliveries) -->
    <div id="stockInPendingSection" style="<?= $active_main_tab === 'history' ? 'display:none;' : 'display:block;' ?>;">



        <div class="summary-grid">
            <a href="<?= si_h(si_tab_url('merch', $filters)) ?>" class="summary-card" style="text-decoration:none;color:inherit;cursor:pointer;" title="Click to view Pending Merchandise">
                <div>
                    <div class="summary-label">Pending Merchandise Deliveries</div>
                    <div class="summary-value"><?= count($merch_groups) ?></div>
                </div>
                <div class="summary-icon"><i class="fas fa-boxes"></i></div>
            </a>
            <a href="<?= si_h(si_tab_url('fuel', $filters)) ?>" class="summary-card" style="text-decoration:none;color:inherit;cursor:pointer;" title="Click to view Pending Fuel">
                <div>
                    <div class="summary-label">Pending Fuel Deliveries</div>
                    <div class="summary-value"><?= count($fuel_groups) ?></div>
                </div>
                <div class="summary-icon"><i class="fas fa-gas-pump"></i></div>
            </a>
            <div class="summary-card">
                <div>
                    <div class="summary-label">Total Pending Stock-In</div>
                    <div class="summary-value"><?= count($merch_groups) + count($fuel_groups) ?></div>
                </div>
                <div class="summary-icon"><i class="fas fa-inbox"></i></div>
            </div>
            <div class="summary-card">
                <div>
                    <div class="summary-label">Today's Deliveries</div>
                    <div class="summary-value"><?= $today_deliveries ?></div>
                </div>
                <div class="summary-icon"><i class="fas fa-calendar-day"></i></div>
            </div>
        </div>

        <!-- Sub-Tabs for Pending Deliveries -->
        <div class="sub-tab-nav">
            <a class="sub-tab-nav-btn <?= $active_type === 'merch' ? 'active' : '' ?>" href="<?= si_h(si_tab_url('merch', $filters)) ?>">
                <i class="fas fa-boxes"></i> Merchandise (<?= count($merch_groups) ?>)
            </a>
            <a class="sub-tab-nav-btn <?= $active_type === 'fuel' ? 'active' : '' ?>" href="<?= si_h(si_tab_url('fuel', $filters)) ?>">
                <i class="fas fa-gas-pump"></i> Fuel (<?= count($fuel_groups) ?>)
            </a>
        </div>

    <form class="filter-panel" method="get" action="<?= htmlspecialchars($_si_base . '/public/manager_stock_in.php') ?>">
        <input type="hidden" name="type" value="<?= si_h($active_type) ?>">
        <div class="filter-grid">
            <div class="field">
                <label>Search PO Number</label>
                <input type="text" name="search" value="<?= si_h($filters['search']) ?>" placeholder="PO number, DR number, product">
            </div>
            <div class="field">
                <label>Supplier</label>
                <select name="supplier">
                    <option value="">All Suppliers</option>
                    <?php foreach ($supplier_options as $supplier): ?>
                        <option value="<?= si_h($supplier) ?>" <?= $filters['supplier'] === $supplier ? 'selected' : '' ?>><?= si_h($supplier) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Delivery Date</label>
                <input type="date" name="delivery_date" value="<?= si_h($filters['delivery_date']) ?>">
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ($pending_statuses as $status): ?>
                        <option value="<?= si_h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= si_h($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button class="si-btn primary" type="submit"><i class="fas fa-filter"></i> Filter</button>
                <a class="si-btn outline" href="<?= htmlspecialchars($_si_base . '/public/manager_stock_in.php') ?>?type=<?= si_h($active_type) ?>"><i class="fas fa-undo"></i> Reset</a>
            </div>
        </div>
    </form>

    <div class="table-card">
        <div class="table-head">
            <div class="table-title">
                <i class="<?= $active_type === 'fuel' ? 'fas fa-gas-pump' : 'fas fa-boxes' ?>"></i>
                Pending Stock-In Table
            </div>
        </div>

        <?php if (empty($active_groups)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <strong>No pending deliveries for stock-in.</strong><br>
                <span style="font-size:13px;">Staff-recorded deliveries with Pending Stock-In status will appear here.</span>
            </div>
        <?php else: ?>
            <table class="stock-table">
                <thead>
                <tr>
                    <th>PO No.</th>
                    <th>Supplier</th>
                    <th>Delivery Date</th>
                    <th>Received By</th>
                    <th><?= $active_type === 'fuel' ? 'Fuel Types' : 'Products' ?></th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($active_groups as $group): ?>
                    <?php
                    $gid = $group['id'];
                    $row_count = count($group['rows']);
                    $total_received_default = 0;
                    foreach ($group['rows'] as $r) {
                        $total_received_default += (float)($r['actual_quantity'] !== null ? $r['actual_quantity'] : $r['quantity']);
                    }
                    ?>
                    <tr class="click-row" onclick="toggleStockDetail('<?= si_h($gid) ?>')">
                        <td><span class="po-link"><?= si_h($group['po_no']) ?></span></td>
                        <td><?= si_h($group['supplier']) ?></td>
                        <td><?= $group['delivery_date'] ? date('M d, Y', strtotime($group['delivery_date'])) : '-' ?></td>
                        <td><?= si_h($group['received_by']) ?></td>
                        <td><?= $row_count ?> <?= $active_type === 'fuel' ? 'Fuel Type' . ($row_count === 1 ? '' : 's') : 'Product' . ($row_count === 1 ? '' : 's') ?></td>
                        <td><span class="status-pill"><i class="fas fa-hourglass-half"></i> <?= si_h($group['status']) ?></span></td>
                    </tr>
                    <tr class="detail-row" id="detail-<?= si_h($gid) ?>">
                        <td colspan="6" class="detail-cell">
                            <div class="detail-panel">
                                <div class="info-grid">
                                    <div class="info-item"><label>Purchase Order No.</label><span><?= si_h($group['po_no']) ?></span></div>
                                    <div class="info-item"><label>Purchase Request No.</label><span><?= si_h($group['purchase_request_no'] ?: '-') ?></span></div>
                                    <div class="info-item"><label>Supplier</label><span><?= si_h($group['supplier']) ?></span></div>
                                    <div class="info-item"><label>Delivery Receipt No.</label><span><?= si_h($group['dr_number'] ?: '-') ?></span></div>
                                    <div class="info-item"><label>Sales Invoice No.</label><span><?= si_h($group['sales_invoice'] ?: '-') ?></span></div>
                                    <div class="info-item"><label>Delivery Date &amp; Time</label><span><?= $group['delivery_date'] ? date('M d, Y', strtotime($group['delivery_date'])) : '-' ?><?= !empty($group['delivery_time']) ? ' at ' . date('g:i A', strtotime($group['delivery_time'])) : '' ?></span></div>
                                    <div class="info-item"><label>Received By</label><span><?= si_h($group['received_by']) ?></span></div>
                                </div>

                                <div class="verify-wrap">
                                    <?php if ($active_type === 'fuel'): ?>
                                        <table class="verify-table">
                                            <thead>
                                            <tr>
                                                <th>Fuel Type</th>
                                                <th>UGT No.</th>
                                                <th>Liters Ordered</th>
                                                <th>Liters Received</th>
                                                <th>Cost per Liter</th>
                                                <th>Selling Price/Liter</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($group['rows'] as $item): ?>
                                                <?php
                                                $ordered = (float)($item['expected_quantity'] ?: $item['quantity']);
                                                $received = (float)($item['actual_quantity'] !== null ? $item['actual_quantity'] : $item['quantity']);
                                                ?>
                                                <tr data-stock-row="<?= si_h($gid) ?>"
                                                        data-delivery-id="<?= (int)$item['delivery_id'] ?>">
                                                    <td><strong><?= si_h($item['fuel_type']) ?></strong></td>
                                                    <td>
                                                        <?php if (!empty($item['ugt_no'])): ?>
                                                            <span style="display:inline-block;padding:3px 9px;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;border-radius:6px;font-weight:800;font-size:12px;font-family:Consolas,monospace;letter-spacing:0.5px;"><?= si_h($item['ugt_no']) ?></span>
                                                        <?php else: ?>
                                                            <span style="color:#94a3b8;">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= si_qty($ordered, 2) ?> L</td>
                                                    <td>
                                                        <input class="qty-input qty-field" type="number" step="0.01" min="0"
                                                               value="<?= si_h($received) ?>" oninput="recalcStockGroup('<?= si_h($gid) ?>', true)">
                                                    </td>
                                                    <td><span class="readonly-money"><?= si_money($item['cost_price']) ?></span></td>
                                                    <td>
                                                         <input class="price-input price-field" type="number" step="0.01" min="0.01" required
                                                                value=""
                                                                placeholder="<?= (float)$item['current_selling_price'] > 0 ? 'Current ₱' . number_format($item['current_selling_price'], 2) . '/L' : 'Enter selling price/L (required)' ?>"
                                                                style="border-color: #f59e0b;">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <table class="verify-table">
                                            <thead>
                                            <tr>
                                                <th>Product ID</th>
                                                <th>Product Code</th>
                                                <th>Product Name</th>
                                                <th>Qty Ordered</th>
                                                <th>Qty Received</th>
                                                <th>Unit</th>
                                                <th>Unit Cost</th>
                                                <th>Selling Price</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($group['rows'] as $item): ?>
                                                <?php
                                                $ordered = (int)round((float)($item['expected_quantity'] ?: $item['quantity']));
                                                $received = (int)round((float)($item['actual_quantity'] !== null ? $item['actual_quantity'] : $item['quantity']));
                                                ?>
                                                <tr data-stock-row="<?= si_h($gid) ?>"
                                                    data-delivery-id="<?= (int)$item['delivery_id'] ?>">
                                                    <td><?= si_h(si_product_id($item['product_id'])) ?></td>
                                                    <td><code><?= si_h($item['sku'] ?: '-') ?></code></td>
                                                    <td><strong><?= si_h($item['product']) ?></strong></td>
                                                    <td><?= si_qty($ordered) ?></td>
                                                    <td>
                                                        <input class="qty-input qty-field" type="number" min="0" step="1"
                                                               value="<?= si_h($received) ?>" oninput="recalcStockGroup('<?= si_h($gid) ?>', false)">
                                                    </td>
                                                    <td><?= si_h($item['unit_display'] ?: $item['unit'] ?: 'Piece') ?></td>
                                                    <td><span class="readonly-money"><?= si_money($item['cost_price']) ?></span></td>
                                                    <td>
                                                         <input class="price-input price-field" type="number" min="0.01" step="0.01" required
                                                                value=""
                                                                placeholder="<?= (float)$item['current_selling_price'] > 0 ? 'Current ₱' . number_format($item['current_selling_price'], 2) : 'Enter selling price (required)' ?>"
                                                                style="border-color: #f59e0b;">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>

                                <div class="detail-summary" style="display:flex; justify-content:flex-end;">
                                    <button type="button" class="si-btn success approve-btn"
                                            onclick="approveStockIn('<?= si_h($active_type) ?>','<?= si_h($gid) ?>','<?= si_h($group['po_no']) ?>')">
                                        <i class="fas fa-check-circle"></i> Approve Stock-In
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <!-- Stock-In Pagination Footer -->
        <div id="siPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="siShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing <?= empty($active_groups) ? '0' : '1–'.min(10, count($active_groups)) ?> of <?= count($active_groups) ?> entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="siPerPage" onchange="siChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="siPrevBtn" onclick="siGoPage(siState.page - 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="siPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of <?= max(1, ceil(count($active_groups) / 10)) ?></span>
                    <button id="siNextBtn" onclick="siGoPage(siState.page + 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($active_groups) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($active_groups) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    </div> <!-- /#stockInPendingSection -->

    <!-- Stock-In History Section -->
    <div id="stockInHistorySection" style="<?= $active_main_tab === 'history' ? 'display:block;' : 'display:none;' ?>;">
        <!-- Stock-In History Summary Cards -->
        <div class="summary-grid-hist">
            <div class="summary-card-hist">
                <div class="summary-card-hist-label"><i class="fas fa-boxes" style="color:#002F70;"></i> Total Stock-Ins</div>
                <div class="summary-card-hist-val"><?= number_format($cnt_si_hist_total) ?></div>
            </div>
            <div class="summary-card-hist">
                <div class="summary-card-hist-label"><i class="fas fa-gas-pump" style="color:#1d4ed8;"></i> Fuel Stock-Ins</div>
                <div class="summary-card-hist-val" style="color:#1d4ed8;"><?= number_format($cnt_si_hist_fuel) ?></div>
            </div>
            <div class="summary-card-hist">
                <div class="summary-card-hist-label"><i class="fas fa-box" style="color:#9333ea;"></i> Merch Stock-Ins</div>
                <div class="summary-card-hist-val" style="color:#9333ea;"><?= number_format($cnt_si_hist_merch) ?></div>
            </div>
            <div class="summary-card-hist">
                <div class="summary-card-hist-label"><i class="fas fa-tint" style="color:#0284c7;"></i> Total Fuel Stocked</div>
                <div class="summary-card-hist-val" style="color:#0284c7; font-size:20px !important;"><?= number_format($cnt_si_hist_volume, 2) ?> <span style="font-size:13px; font-weight:700; color:#64748b;">L</span></div>
            </div>
            <div class="summary-card-hist">
                <div class="summary-card-hist-label"><i class="fas fa-layer-group" style="color:#16a34a;"></i> Total Merch Units</div>
                <div class="summary-card-hist-val" style="color:#16a34a; font-size:20px !important;"><?= number_format($cnt_si_hist_units) ?> <span style="font-size:13px; font-weight:700; color:#64748b;">pcs</span></div>
            </div>
        </div>

        <!-- History Filter Bar -->
        <div class="filter-panel" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-bottom:16px; box-shadow:0 2px 8px rgba(15,23,42,.04);">
            <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
                <div style="flex:1.5; min-width:200px;">
                    <label style="display:block; font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.45px; margin-bottom:5px;">Search Stock-In</label>
                    <div style="position:relative;">
                        <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:12px;"></i>
                        <input type="text" id="histSearchStockIn" placeholder="Batch, PO, DR, Item, Supplier..." oninput="filterStockInHistory()"
                               style="width:100%; box-sizing:border-box; height:38px; padding:0 12px 0 34px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; color:#1e293b; background:#fff; outline:none;">
                    </div>
                </div>
                <div style="flex:1; min-width:130px;">
                    <label style="display:block; font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.45px; margin-bottom:5px;">Category</label>
                    <select id="histCategoryFilter" onchange="filterStockInHistory()" style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:0 10px; font-size:12px; color:#1e293b; background:#fff;">
                        <option value="">All Categories</option>
                        <option value="fuel">Fuel</option>
                        <option value="merchandise">Merchandise</option>
                    </select>
                </div>
                <div style="flex:1; min-width:150px;">
                    <label style="display:block; font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.45px; margin-bottom:5px;">Supplier</label>
                    <select id="histSupplierFilter" onchange="filterStockInHistory()" style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:0 10px; font-size:12px; color:#1e293b; background:#fff;">
                        <option value="">All Suppliers</option>
                        <?php foreach (array_keys($si_hist_suppliers) as $sup): ?>
                        <option value="<?= htmlspecialchars(strtolower($sup)) ?>"><?= htmlspecialchars($sup) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1; min-width:130px;">
                    <label style="display:block; font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.45px; margin-bottom:5px;">Start Date</label>
                    <input type="date" id="histStartDate" onchange="filterStockInHistory()" style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:0 10px; font-size:12px; color:#1e293b; background:#fff; box-sizing:border-box;">
                </div>
                <div style="flex:1; min-width:130px;">
                    <label style="display:block; font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.45px; margin-bottom:5px;">End Date</label>
                    <input type="date" id="histEndDate" onchange="filterStockInHistory()" style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:0 10px; font-size:12px; color:#1e293b; background:#fff; box-sizing:border-box;">
                </div>
                <div class="filter-actions" style="display:flex; gap:8px;">
                    <button class="si-btn primary" type="button" onclick="filterStockInHistory()"><i class="fas fa-filter"></i> Filter</button>
                    <button class="si-btn outline" type="button" onclick="resetStockInHistoryFilter()"><i class="fas fa-undo"></i> Reset</button>
                </div>
            </div>
        </div>

        <!-- History Table Card -->
        <div class="table-card">
            <div class="table-head">
                <div class="table-title">
                    <i class="fas fa-history"></i> Stock-In History Records
                </div>
                <span style="font-size:12px; font-weight:700; color:#64748b;">
                    <?= count($stock_in_history_list) ?> Record<?= count($stock_in_history_list) === 1 ? '' : 's' ?>
                </span>
            </div>

            <div style="overflow-x:auto;">
                <table class="stock-table" id="stockInHistoryTable">
                    <thead>
                        <tr>
                            <th>Reference / Batch No.</th>
                            <th>PO / DR No.</th>
                            <th style="text-align:center;">Category</th>
                            <th>Supplier</th>
                            <th>Date Stocked-In</th>
                            <th>Stocked By</th>
                            <th style="text-align:center;">Delivered Items</th>
                            <th style="text-align:center;">Status</th>
                            <th style="text-align:center; width:170px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="stockInHistoryBody">
                        <?php if (empty($stock_in_history_list)): ?>
                        <tr id="siHistEmptyRow">
                            <td colspan="9" class="empty-state" style="padding:50px 20px;">
                                <i class="fas fa-history" style="font-size:38px; color:#cbd5e1; display:block; margin-bottom:10px;"></i>
                                <strong style="font-size:15px; color:#1e293b;">No Stock-In History Found</strong><br>
                                <span style="font-size:13px; color:#64748b;">Approved stock-in deliveries will automatically appear here.</span>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($stock_in_history_list as $si_row): 
                                $h_is_fuel = ($si_row['delivery_type'] === 'fuel');
                                $h_dt_raw = $si_row['stock_in_date'] ?: ($si_row['delivery_date'] ?? '');
                                $h_dt_fmt = $h_dt_raw ? date('M d, Y', strtotime($h_dt_raw)) : '—';
                                $h_tm_fmt = $h_dt_raw && strlen($h_dt_raw) > 10 ? date('h:i A', strtotime($h_dt_raw)) : '';
                                
                                $h_item_count = count($si_row['items'] ?? []);
                                $h_first_item = !empty($si_row['items'][0]['name']) ? $si_row['items'][0]['name'] : '—';
                                $h_more_count = max(0, $h_item_count - 1);
                                $h_unit       = $h_is_fuel ? 'L' : 'pcs';

                                // Search text helper
                                $h_search_parts = [
                                    $si_row['batch_id'] ?? '',
                                    $si_row['delivery_ref'] ?? '',
                                    $si_row['po_number'] ?? '',
                                    $si_row['dr_number'] ?? '',
                                    $si_row['supplier'] ?? '',
                                    $si_row['stocked_by'] ?? '',
                                    $si_row['received_by'] ?? '',
                                ];
                                foreach ($si_row['items'] as $it) {
                                    $h_search_parts[] = $it['name'] ?? '';
                                }
                                $h_search_str = strtolower(implode(' ', array_filter($h_search_parts)));
                                
                                // Modal payload
                                $h_modal_data = [
                                    'batch_id'      => $si_row['batch_id'] ?: ($si_row['delivery_ref'] ?: '—'),
                                    'po_number'     => $si_row['po_number'] ?: '—',
                                    'dr_number'     => $si_row['dr_number'] ?: '—',
                                    'delivery_type' => $h_is_fuel ? 'Fuel' : 'Merchandise',
                                    'supplier'      => $si_row['supplier'] ?: 'Petron Corporation',
                                    'stock_in_date' => $h_dt_fmt . ($h_tm_fmt ? ' ' . $h_tm_fmt : ''),
                                    'stocked_by'    => $si_row['stocked_by'] ?: 'Manager',
                                    'received_by'   => $si_row['received_by'] ?: 'Staff',
                                    'status'        => $si_row['status'] ?: 'Stock-In Complete',
                                    'remarks'       => $si_row['remarks'] ?? '',
                                    'total_qty'     => $si_row['total_qty'],
                                    'total_cost'    => $si_row['total_cost'],
                                    'items'         => $si_row['items']
                                ];
                                $h_json_attr = htmlspecialchars(json_encode($h_modal_data), ENT_QUOTES, 'UTF-8');
                                
                                $row_date_ymd = $h_dt_raw ? date('Y-m-d', strtotime($h_dt_raw)) : '';
                            ?>
                            <tr class="si-hist-row"
                                data-search="<?= htmlspecialchars($h_search_str) ?>"
                                data-category="<?= $h_is_fuel ? 'fuel' : 'merchandise' ?>"
                                data-supplier="<?= htmlspecialchars(strtolower($si_row['supplier'])) ?>"
                                data-date="<?= $row_date_ymd ?>"
                                data-details='<?= $h_json_attr ?>'>
                                <td style="font-weight:800; color:#002F70; font-family:Consolas, monospace; font-size:12.5px;">
                                    <?= htmlspecialchars($si_row['batch_id'] ?: ($si_row['delivery_ref'] ?: '—')) ?>
                                </td>
                                <td>
                                    <div style="font-weight:700; color:#0f172a; font-family:Consolas, monospace;"><?= htmlspecialchars($si_row['po_number'] ?: '—') ?></div>
                                    <?php if (!empty($si_row['dr_number'])): ?>
                                        <div style="font-size:11px; color:#64748b;">DR: <?= htmlspecialchars($si_row['dr_number']) ?></div>
                                    <?php endif; ?>
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
                                <td style="font-weight:600; color:#1e293b;"><?= htmlspecialchars($si_row['supplier']) ?></td>
                                <td style="color:#334155; white-space:nowrap;">
                                    <div style="font-weight:700;"><?= $h_dt_fmt ?></div>
                                    <?php if ($h_tm_fmt): ?>
                                        <div style="font-size:11px; color:#64748b;"><?= $h_tm_fmt ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight:600; color:#334155;">
                                    <div><?= htmlspecialchars($si_row['stocked_by']) ?></div>
                                    <div style="font-size:11px; color:#64748b;">Rec: <?= htmlspecialchars($si_row['received_by']) ?></div>
                                </td>
                                <td style="text-align:center;">
                                    <div style="font-weight:800; color:#002F70;"><?= $h_item_count ?> Item<?= $h_item_count === 1 ? '' : 's' ?></div>
                                    <div style="font-size:11px; color:#64748b; line-height:1.2; margin-top:2px;">
                                        <?= htmlspecialchars($h_first_item) ?>
                                        <?= $h_more_count ? '<br><span style="font-weight:700;color:#475569;">+' . $h_more_count . ' more</span>' : '' ?>
                                    </div>
                                    <div style="font-weight:800; font-family:monospace; color:#0f172a; font-size:11.5px; margin-top:2px;">
                                        <?= $h_is_fuel ? number_format($si_row['total_qty'], 2) : number_format($si_row['total_qty']) ?> <?= htmlspecialchars($h_unit) ?>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <span style="display:inline-flex; align-items:center; gap:4px; background:#dcfce7; color:#15803d; font-weight:800; font-size:11px; padding:4px 9px; border-radius:12px;">
                                        <i class="fas fa-check-circle" style="font-size:10px;"></i> <?= htmlspecialchars($si_row['status']) ?>
                                    </span>
                                </td>
                                <td style="text-align:center; white-space:nowrap;">
                                    <button type="button" class="si-btn outline" style="height:32px; padding:0 10px; font-size:11.5px; display:inline-flex; align-items:center; gap:5px;" onclick="openStockInViewModal(this)">
                                        <i class="fas fa-eye" style="color:#002F70;"></i> View Details
                                    </button>
                                    <a href="print_supplier_invoice.php?batch_id=<?= urlencode($si_row['batch_id'] ?: $si_row['po_number']) ?>&type=<?= urlencode($si_row['delivery_type']) ?>" target="_blank" class="si-btn outline" style="height:32px; padding:0 8px; font-size:11.5px; display:inline-flex; align-items:center;" title="Print Invoice">
                                        <i class="fas fa-print" style="color:#64748b;"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr id="siHistNoResultsRow" style="display:none;">
                                <td colspan="9" style="text-align:center; padding:32px 16px; color:#64748b; background:#fff;">
                                    <i class="fas fa-search" style="font-size:24px; color:#cbd5e1; display:block; margin-bottom:8px;"></i>
                                    No matching stock-in history records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Stock-In History Pagination Footer -->
            <div id="siHistPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 8px 8px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center;">
                    <span id="siHistShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing 0 of 0 entries</span>
                </div>
                <div style="display:flex; align-items:center; gap:16px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                        <select id="siHistPerPage" onchange="siHistChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <button id="siHistPrevBtn" onclick="siHistGoPage(siHistState.page - 1)" 
                                style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                                onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span id="siHistPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of 1</span>
                        <button id="siHistNextBtn" onclick="siHistGoPage(siHistState.page + 1)" 
                                style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                                onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- /#stockInHistorySection -->
</div>

<!-- Toast -->
<div class="stock-toast" id="stockToast" role="status" aria-live="polite"></div>

<!-- Custom Confirm Modal (replaces window.confirm which Edge may block) -->
<div id="siConfirmOverlay" onclick="if(event.target===this) siConfirmCancel();">
    <div class="confirm-card">
        <div style="font-size:16px;font-weight:800;color:#002F70;margin-bottom:10px;"><i class="fas fa-check-circle" style="color:#16a34a;margin-right:8px;"></i>Confirm Stock-In Approval</div>
        <div id="siConfirmMsg" style="font-size:13.5px;color:#374151;line-height:1.6;margin-bottom:22px;"></div>
        <div style="display:flex;justify-content:flex-end;gap:12px;">
            <button type="button" id="siConfirmCancelBtn" onclick="siConfirmCancel()" style="padding:10px 22px!important;border:1.5px solid #dc2626!important;border-radius:7px!important;background:#dc2626!important;background-color:#dc2626!important;color:#ffffff!important;-webkit-text-fill-color:#ffffff!important;font-weight:700!important;font-size:13.5px!important;cursor:pointer!important;display:inline-flex!important;align-items:center!important;gap:7px!important;">Cancel</button>
            <button type="button" id="siConfirmOkBtn" style="padding:10px 22px!important;border:1.5px solid #16a34a!important;border-radius:7px!important;background:#16a34a!important;background-color:#16a34a!important;color:#ffffff!important;-webkit-text-fill-color:#ffffff!important;font-weight:800!important;font-size:13.5px!important;cursor:pointer!important;display:inline-flex!important;align-items:center!important;gap:7px!important;"><i class="fas fa-check"></i> Yes, Approve</button>
        </div>
    </div>
</div>

<!-- Stock-In View Details Modal -->
<div id="stockInViewModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.65); backdrop-filter:blur(5px); -webkit-backdrop-filter:blur(5px); align-items:flex-start; justify-content:center; padding:70px 16px 30px 16px; box-sizing:border-box; overflow-y:auto;" onclick="if(event.target===this) closeStockInViewModal();">
    <div style="background:#fff; border-radius:14px; width:96%; max-width:1020px; max-height:calc(100vh - 100px); display:flex; flex-direction:column; box-shadow:0 25px 60px rgba(0,0,0,0.35); overflow:hidden; margin:0 auto;" onclick="event.stopPropagation();">
        <div style="background:#002F70; padding:18px 24px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
            <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-boxes" style="color:#fff; font-size:18px;"></i>
                <div>
                    <div style="color:#fff; font-weight:800; font-size:15px; letter-spacing:0.3px;" id="siv_title">Stock-In Details</div>
                    <div style="color:rgba(255,255,255,0.75); font-size:11.5px; margin-top:1px;" id="siv_subtitle">Completed Stock-In Verification Record</div>
                </div>
            </div>
            <button type="button" onclick="closeStockInViewModal()" style="background:transparent; border:none; color:#fff; font-size:18px; cursor:pointer; padding:4px 8px; border-radius:4px; opacity:0.85;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.85'">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div style="overflow-y:auto; flex:1; padding:20px 22px;">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(155px,1fr)); gap:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:16px;">
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Reference / Batch No.</div><div style="font-weight:800;color:#002F70;font-family:Consolas,monospace;font-size:13px;" id="siv_batch_id">—</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">PO Number</div><div style="font-weight:800;color:#0f172a;font-family:Consolas,monospace;font-size:13px;" id="siv_po_number">—</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">DR Number</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="siv_dr_number">—</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Category</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="siv_category">—</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Supplier</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="siv_supplier">—</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Date Stocked-In</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="siv_stock_date">—</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Stocked By</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="siv_stocked_by">—</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Received By</div><div style="font-weight:700;color:#1e293b;font-size:13px;" id="siv_received_by">—</div></div>
                <div><div style="font-size:9.5px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Status</div><div id="siv_status" style="font-weight:800;color:#15803d;font-size:13px;">—</div></div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:18px;">
                <div style="border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; background:#fff;">
                    <div style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; margin-bottom:4px;">Total Items</div>
                    <div style="font-size:22px; font-weight:900; color:#002F70;" id="siv_total_items">0</div>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; background:#fff;">
                    <div style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; margin-bottom:4px;">Total Quantity</div>
                    <div style="font-size:22px; font-weight:900; color:#0f172a;" id="siv_total_qty">0</div>
                </div>
                <div style="border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; background:#fff;">
                    <div style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase; margin-bottom:4px;">Total Value</div>
                    <div style="font-size:22px; font-weight:900; color:#16a34a;" id="siv_total_cost">₱ 0.00</div>
                </div>
            </div>

            <div style="font-size:11px; font-weight:800; color:#002F70; text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px;">
                <i class="fas fa-list" style="margin-right:5px;"></i> Stocked Items Breakdown
            </div>
            <div style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; margin-bottom:16px; background:#fff; width:100%; box-sizing:border-box;">
                <table style="width:100%; border-collapse:collapse; table-layout:fixed; font-size:11.5px; box-sizing:border-box; margin:0;">
                    <thead style="background:#002F70;">
                        <tr>
                            <th style="width:22%; padding:9px 8px; text-align:left; color:#fff; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; box-sizing:border-box;">Item / Product</th>
                            <th style="width:12.5%; padding:9px 6px; text-align:right; color:#fff; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; box-sizing:border-box;">Ordered</th>
                            <th style="width:13.5%; padding:9px 6px; text-align:right; color:#fff; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; box-sizing:border-box;">Received</th>
                            <th style="width:11%; padding:9px 6px; text-align:right; color:#fff; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; box-sizing:border-box;">Unit Cost</th>
                            <th style="width:11.5%; padding:9px 6px; text-align:right; color:#fff; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; box-sizing:border-box;">Selling Price</th>
                            <th style="width:18.5%; padding:9px 6px; text-align:right; color:#fff; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; box-sizing:border-box;">Total Cost</th>
                            <th style="width:11%; padding:9px 6px; text-align:center; color:#fff; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:0.3px; white-space:nowrap; box-sizing:border-box;">Condition</th>
                        </tr>
                    </thead>
                    <tbody id="siv_items_body"></tbody>
                </table>
            </div>

            <div id="siv_remarks_wrap" style="display:none; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:12px 16px;">
                <div style="font-size:10px; font-weight:800; color:#92400e; text-transform:uppercase; margin-bottom:3px;">Remarks / Notes</div>
                <div id="siv_remarks" style="font-size:12.5px; color:#78350f; line-height:1.45;"></div>
            </div>
        </div>
        <div style="padding:14px 24px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
            <a id="siv_print_btn" href="#" target="_blank" class="si-btn primary" style="font-size:12.5px; height:36px;">
                <i class="fas fa-print"></i> Print Invoice
            </a>
            <button type="button" onclick="closeStockInViewModal()" style="background:#64748b; color:#fff; border:none; padding:8px 22px; border-radius:7px; font-weight:700; font-size:13px; cursor:pointer;">
                Close
            </button>
        </div>
    </div>
</div>

<script>
const stockEndpoint = '<?= htmlspecialchars($_si_base) ?>/backend/api/manager_stock_in.php';

function toggleStockDetail(groupId) {
    const row = document.getElementById('detail-' + groupId);
    if (!row) return;
    row.classList.toggle('open');
}

function stockRows(groupId) {
    return Array.from(document.querySelectorAll('[data-stock-row="' + groupId + '"]'));
}

function recalcStockGroup(groupId, isFuel) {
    let total = 0;
    stockRows(groupId).forEach(function(row) {
        const qty = parseFloat(row.querySelector('.qty-field').value) || 0;
        total += qty;
    });
    const out = document.getElementById('sum-' + groupId);
    if (out) {
        out.textContent = isFuel ? total.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L' : Math.round(total).toLocaleString();
    }
}

var _siConfirmCallback = null;

function siConfirm(message, onYes) {
    document.getElementById('siConfirmMsg').textContent = message;
    var overlay = document.getElementById('siConfirmOverlay');
    overlay.style.display = 'flex';
    document.getElementById('siConfirmOkBtn').onclick = function() {
        overlay.style.display = 'none';
        onYes();
    };
}

function siConfirmCancel() {
    var overlay = document.getElementById('siConfirmOverlay');
    if (overlay) overlay.style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        siConfirmCancel();
        closeStockInViewModal();
    }
});

function approveStockIn(type, groupId, poKey) {
    var rows = stockRows(groupId);
    if (!rows.length) {
        showStockToast('No items found in this group. Please refresh the page.', 'err');
        return;
    }

    var items = [];
    var hasError = false;
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var qtyInput = row.querySelector('.qty-field');
        var priceInput = row.querySelector('.price-field');
        
        if (!qtyInput || !priceInput) {
            showStockToast('Invalid row structure. Please refresh the page.', 'err');
            hasError = true;
            break;
        }
        
        var qty = parseFloat(qtyInput.value);
        var price = parseFloat(priceInput.value);

        if (!Number.isFinite(qty) || qty < 0) {
            showStockToast('Received quantity cannot be negative.', 'err');
            qtyInput.style.borderColor = '#dc2626';
            qtyInput.focus();
            hasError = true;
            break;
        }
        if (!Number.isFinite(price) || price <= 0) {
            showStockToast('Enter the selling price for all items before approving.', 'err');
            priceInput.style.borderColor = '#dc2626';
            priceInput.focus();
            hasError = true;
            break;
        }
        qtyInput.style.borderColor = '';
        priceInput.style.borderColor = '';

        var deliveryId = parseInt(row.getAttribute('data-delivery-id'), 10);
        
        items.push({
            delivery_id: deliveryId,
            qty_received: qty,
            selling_price: price
        });
    }
    
    if (hasError) return;

    var label = type === 'fuel' ? 'fuel' : 'merchandise';
    siConfirm(
        'Approve stock-in for ' + poKey + '? This will update inventory, prices, history, and PO status.',
        function() {
            var button = document.querySelector('#detail-' + groupId + ' .approve-btn');
            
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving...';
            }

            var action = type === 'fuel' ? 'approve_fuel_stock_in' : 'approve_merchandise_stock_in';
            var url = stockEndpoint + '?action=' + action;
            var payload = {po_key: poKey, items: items};
            
            fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            })
            .then(function(response) {
                return response.text().then(function(text) {
                    var data = {};
                    try {
                        data = text ? JSON.parse(text) : {};
                    } catch (e) {
                        data = {};
                    }
                    if (!response.ok) {
                        throw new Error(data.message || ('Server error ' + response.status));
                    }
                    return data;
                });
            })
            .then(function(data) {
                if (data.success) {
                    var successMsg = data.message || ('Successfully approved ' + label + ' stock-in for ' + poKey + '! Official station inventory and prices have been updated.');
                    var successObj = {
                        title: 'Stock-In Approved Successfully!',
                        message: successMsg,
                        batch_id: data.batch_id || '',
                        po_key: poKey,
                        time: Date.now()
                    };
                    try {
                        sessionStorage.setItem('petron_stock_in_success', JSON.stringify(successObj));
                    } catch(e) {}

                    // showStockBanner(successObj);
                    showStockToast(successMsg, 'ok');
                    setTimeout(function() { window.location.reload(); }, 1200);
                } else {
                    showStockToast(data.message || 'Unable to approve stock-in.', 'err');
                    if (button) {
                        button.disabled = false;
                        button.innerHTML = '<i class="fas fa-check-circle"></i> Approve Stock-In';
                    }
                }
            })
            .catch(function(err) {
                showStockToast('Error: ' + (err.message || 'Connection error. Check server logs.'), 'err');
                if (button) {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-check-circle"></i> Approve Stock-In';
                }
            });
        }
    );
}

function showStockToast(message, type) {
    var toastType = (type === 'ok' || type === 'success') ? 'success' : 'error';
    if (typeof window.showGlobalToast === 'function') {
        window.showGlobalToast(message, toastType);
        return;
    }
    if (typeof window.showToast === 'function') {
        window.showToast(message, toastType);
        return;
    }
    if (typeof window.showPetronFlash === 'function') {
        window.showPetronFlash(message, toastType);
        return;
    }
    const toast = document.getElementById('stockToast');
    if (!toast) return;
    toast.innerHTML = '<div style="display:flex;align-items:center;gap:10px;"><i class="fas ' + (toastType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + '" style="font-size:16px;flex-shrink:0;"></i><span>' + message + '</span></div>';
    toast.className = 'stock-toast ' + (toastType === 'success' ? 'toast-ok' : 'toast-err') + ' is-visible';
    clearTimeout(window.stockToastTimer);
    clearTimeout(window.stockToastHideTimer);
    window.stockToastTimer = setTimeout(function() {
        toast.classList.remove('is-visible');
        window.stockToastHideTimer = setTimeout(function() {
            toast.className = 'stock-toast';
        }, 240);
    }, toastType === 'success' ? 4000 : 5500);
}

// ── Stock-In Pagination Engine ──
var siState = { page: 1, per_page: 10 };

function siRender() {
    const tableBody = document.querySelector('.stock-table tbody');
    if (!tableBody) return;

    const clickRows = Array.from(tableBody.querySelectorAll('tr.click-row'));
    const tot = clickRows.length;
    const pp = siState.per_page || 10;
    const tp = Math.max(1, Math.ceil(tot / pp));

    if (siState.page > tp) siState.page = tp;
    if (siState.page < 1) siState.page = 1;
    const p = siState.page;

    const start = (p - 1) * pp;
    const end   = p * pp;

    clickRows.forEach(function(r, i) {
        const isVis = (i >= start && i < end);
        r.style.display = isVis ? '' : 'none';
        const nextRow = r.nextElementSibling;
        if (nextRow && nextRow.classList.contains('detail-row') && !isVis) {
            nextRow.style.display = 'none';
            r.classList.remove('is-active');
        }
    });

    // Update counter
    const showingStart = tot === 0 ? 0 : start + 1;
    const showingEnd   = Math.min(end, tot);
    const entriesLbl   = document.getElementById('siShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
    }

    const lbl = document.getElementById('siPageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

    const prev = document.getElementById('siPrevBtn');
    const next = document.getElementById('siNextBtn');
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

window.siState = siState;
window.siGoPage = function(p) {
    const tableBody = document.querySelector('.stock-table tbody');
    if (!tableBody) return;
    const clickRows = Array.from(tableBody.querySelectorAll('tr.click-row'));
    const tp = Math.max(1, Math.ceil(clickRows.length / (siState.per_page || 10)));
    if (p < 1 || p > tp) return;
    siState.page = p;
    siRender();
};

window.siChangePerPage = function() {
    const s = document.getElementById('siPerPage');
    if (s && siState) {
        siState.per_page = parseInt(s.value, 10);
        siState.page = 1;
        siRender();
    }
};

function showStockBanner(data) {
    var banner = document.getElementById('stockSuccessBanner');
    if (!banner) return;
    
    var titleEl = document.getElementById('stockBannerTitle');
    var descEl = document.getElementById('stockBannerDesc');
    var metaEl = document.getElementById('stockBannerMeta');
    
    if (titleEl && data.title) titleEl.textContent = data.title;
    if (descEl && data.message) descEl.textContent = data.message;
    
    if (metaEl) {
        metaEl.innerHTML = '';
        if (data.po_key) {
            metaEl.innerHTML += '<span class="stock-banner-tag"><i class="fas fa-file-invoice"></i> PO: ' + siEscapeHtml(data.po_key) + '</span>';
        }
        if (data.batch_id) {
            metaEl.innerHTML += '<span class="stock-banner-tag"><i class="fas fa-layer-group"></i> Batch: ' + siEscapeHtml(data.batch_id) + '</span>';
        }
        metaEl.style.display = metaEl.innerHTML ? 'flex' : 'none';
    }
    
    banner.style.display = 'flex';
    try {
        banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch(e) {}
}

function dismissStockBanner() {
    var banner = document.getElementById('stockSuccessBanner');
    if (banner) {
        banner.style.display = 'none';
    }
    try {
        sessionStorage.removeItem('petron_stock_in_success');
    } catch(e) {}
}

function siEscapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Tab Switching between Pending Stock-In and Stock-In History
function switchStockInMainTab(tab) {
    const pendingSec = document.getElementById('stockInPendingSection');
    const histSec    = document.getElementById('stockInHistorySection');
    const btnStockIn = document.getElementById('mainTabStockInBtn');
    const btnHist    = document.getElementById('mainTabHistoryBtn');

    if (tab === 'history') {
        if (btnStockIn) btnStockIn.classList.remove('active');
        if (btnHist)    btnHist.classList.add('active');
        if (pendingSec) pendingSec.style.display = 'none';
        if (histSec)    histSec.style.display = 'block';
        if (typeof renderSiHistPagination === 'function') {
            renderSiHistPagination();
        }
    } else {
        if (btnHist)    btnHist.classList.remove('active');
        if (btnStockIn) btnStockIn.classList.add('active');
        if (histSec)    histSec.style.display = 'none';
        if (pendingSec) pendingSec.style.display = 'block';
        if (typeof siRender === 'function') {
            siRender();
        }
    }

    try {
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);
    } catch(e) {}
}

// ── Stock-In History Engine ──
var siHistState = { page: 1, per_page: 10 };

function getSiHistFilteredRows() {
    return Array.from(document.querySelectorAll('.si-hist-row')).filter(function(row) {
        return row.dataset.filteredOut !== 'true';
    });
}

function renderSiHistPagination() {
    const allRows = Array.from(document.querySelectorAll('.si-hist-row'));
    if (!allRows.length) {
        const entriesLbl = document.getElementById('siHistShowingEntriesText');
        if (entriesLbl) entriesLbl.textContent = 'Showing 0 of 0 entries';
        const lbl = document.getElementById('siHistPageLabel');
        if (lbl) lbl.textContent = 'Page 1 of 1';
        return;
    }

    const visibleRows = getSiHistFilteredRows();
    const tot = visibleRows.length;
    const pp = siHistState.per_page || 10;
    const tp = Math.max(1, Math.ceil(tot / pp));

    if (siHistState.page > tp) siHistState.page = tp;
    if (siHistState.page < 1) siHistState.page = 1;
    const p = siHistState.page;

    const start = (p - 1) * pp;
    const end   = p * pp;

    // Show or hide based on filter and page
    allRows.forEach(function(row) {
        if (row.dataset.filteredOut === 'true') {
            row.style.display = 'none';
        }
    });

    visibleRows.forEach(function(row, idx) {
        row.style.display = (idx >= start && idx < end) ? '' : 'none';
    });

    // Handle no results row
    const noResultsRow = document.getElementById('siHistNoResultsRow');
    if (noResultsRow) {
        noResultsRow.style.display = (tot === 0 && allRows.length > 0) ? '' : 'none';
    }

    // Update Showing Entries text
    const showingStart = tot === 0 ? 0 : start + 1;
    const showingEnd   = Math.min(end, tot);
    const entriesLbl   = document.getElementById('siHistShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
    }

    const lbl = document.getElementById('siHistPageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

    const prev = document.getElementById('siHistPrevBtn');
    const next = document.getElementById('siHistNextBtn');
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

function siHistGoPage(p) {
    const tot = getSiHistFilteredRows().length;
    const tp = Math.max(1, Math.ceil(tot / (siHistState.per_page || 10)));
    if (p < 1 || p > tp) return;
    siHistState.page = p;
    renderSiHistPagination();
}

function siHistChangePerPage() {
    const s = document.getElementById('siHistPerPage');
    if (s) {
        siHistState.per_page = parseInt(s.value, 10);
        siHistState.page = 1;
        renderSiHistPagination();
    }
}

function filterStockInHistory() {
    const q        = (document.getElementById('histSearchStockIn')?.value || '').trim().toLowerCase();
    const cat      = (document.getElementById('histCategoryFilter')?.value || '').trim().toLowerCase();
    const sup      = (document.getElementById('histSupplierFilter')?.value || '').trim().toLowerCase();
    const startDt  = document.getElementById('histStartDate')?.value || '';
    const endDt    = document.getElementById('histEndDate')?.value || '';

    const rows = document.querySelectorAll('.si-hist-row');
    rows.forEach(function(row) {
        const rowSearch = (row.dataset.search || '').toLowerCase();
        const rowCat    = (row.dataset.category || '').toLowerCase();
        const rowSup    = (row.dataset.supplier || '').toLowerCase();
        const rowDt     = row.dataset.date || '';

        let match = true;

        if (q && !rowSearch.includes(q)) match = false;
        if (cat && rowCat !== cat) match = false;
        if (sup && !rowSup.includes(sup)) match = false;
        if (startDt && rowDt && rowDt < startDt) match = false;
        if (endDt && rowDt && rowDt > endDt) match = false;

        row.dataset.filteredOut = match ? 'false' : 'true';
    });

    siHistState.page = 1;
    renderSiHistPagination();
}

function resetStockInHistoryFilter() {
    if (document.getElementById('histSearchStockIn')) document.getElementById('histSearchStockIn').value = '';
    if (document.getElementById('histCategoryFilter')) document.getElementById('histCategoryFilter').value = '';
    if (document.getElementById('histSupplierFilter')) document.getElementById('histSupplierFilter').value = '';
    if (document.getElementById('histStartDate')) document.getElementById('histStartDate').value = '';
    if (document.getElementById('histEndDate')) document.getElementById('histEndDate').value = '';

    const rows = document.querySelectorAll('.si-hist-row');
    rows.forEach(function(row) {
        row.dataset.filteredOut = 'false';
    });

    siHistState.page = 1;
    renderSiHistPagination();
}

// ── Stock-In View Modal Engine ──
function openStockInViewModal(btn) {
    const row = btn.closest('.si-hist-row');
    if (!row) return;

    let data = {};
    try {
        data = JSON.parse(row.getAttribute('data-details') || '{}');
    } catch(e) {
        console.error('Failed to parse stock-in details', e);
        return;
    }

    const modal = document.getElementById('stockInViewModal');
    if (!modal) return;

    const setText = function(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val || '—';
    };

    setText('siv_title', (data.delivery_type || 'Stock-In') + ' Details');
    setText('siv_subtitle', 'Reference: ' + (data.batch_id || data.po_number || '—'));
    setText('siv_batch_id', data.batch_id);
    setText('siv_po_number', data.po_number);
    setText('siv_dr_number', data.dr_number);
    setText('siv_category', data.delivery_type);
    setText('siv_supplier', data.supplier);
    setText('siv_stock_date', data.stock_in_date);
    setText('siv_stocked_by', data.stocked_by);
    setText('siv_received_by', data.received_by);
    setText('siv_status', data.status);

    const statusEl = document.getElementById('siv_status');
    if (statusEl) {
        const stLower = String(data.status || '').toLowerCase();
        if (stLower.includes('complete') || stLower.includes('approved') || stLower === 'ok') {
            statusEl.style.color = '#15803d';
        } else if (stLower.includes('pending')) {
            statusEl.style.color = '#b45309';
        } else if (stLower.includes('progress')) {
            statusEl.style.color = '#0369a1';
        } else if (stLower.includes('damage') || stLower.includes('reject') || stLower.includes('cancel')) {
            statusEl.style.color = '#b91c1c';
        } else {
            statusEl.style.color = '#15803d';
        }
    }

    const isFuel = (String(data.delivery_type || '').toLowerCase() === 'fuel');
    const unit = isFuel ? 'L' : 'pcs';

    setText('siv_total_items', String((data.items || []).length));
    setText('siv_total_qty', (isFuel ? parseFloat(data.total_qty || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) : Math.round(parseFloat(data.total_qty || 0)).toLocaleString('en-US')) + ' ' + unit);
    setText('siv_total_cost', '₱ ' + parseFloat(data.total_cost || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}));

    // Render item rows
    const tbody = document.getElementById('siv_items_body');
    if (tbody) {
        tbody.innerHTML = (data.items || []).map(function(it) {
            const itUnit = it.unit || unit;
            const ordQty = parseFloat(it.qty_ordered || 0);
            const recQty = parseFloat(it.qty_received || 0);
            const uCost  = parseFloat(it.unit_cost || 0);
            const sPrice = parseFloat(it.selling_price || 0);
            const tCost  = parseFloat(it.total_cost || (recQty * uCost));

            const ordText = isFuel 
                ? (ordQty.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L') 
                : (Math.round(ordQty).toLocaleString('en-US') + ' ' + siEscapeHtml(itUnit));
            const recText = isFuel 
                ? (recQty.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L') 
                : (Math.round(recQty).toLocaleString('en-US') + ' ' + siEscapeHtml(itUnit));
            const uCostText = '₱ ' + uCost.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
            const sPriceText = '₱ ' + sPrice.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
            const tCostText = '₱ ' + tCost.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

            const isDamaged = String(it.condition || '').toLowerCase().includes('damag');

            return '<tr>' +
                '<td style="width:22%; padding:8px 8px; border-bottom:1px solid #f1f5f9; font-weight:700; color:#1e293b; font-size:11.5px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; box-sizing:border-box;" title="' + siEscapeHtml(it.name) + '">' + siEscapeHtml(it.name) + '</td>' +
                '<td style="width:12.5%; padding:8px 6px; border-bottom:1px solid #f1f5f9; text-align:right; color:#64748b; font-weight:600; font-size:11.5px; white-space:nowrap; box-sizing:border-box;">' + ordText + '</td>' +
                '<td style="width:13.5%; padding:8px 6px; border-bottom:1px solid #f1f5f9; text-align:right; color:#002F70; font-weight:800; font-size:11.5px; white-space:nowrap; box-sizing:border-box;">' + recText + '</td>' +
                '<td style="width:11%; padding:8px 6px; border-bottom:1px solid #f1f5f9; text-align:right; color:#475569; font-weight:600; font-size:11.5px; white-space:nowrap; box-sizing:border-box;">' + uCostText + '</td>' +
                '<td style="width:11.5%; padding:8px 6px; border-bottom:1px solid #f1f5f9; text-align:right; color:#002F70; font-weight:700; font-size:11.5px; white-space:nowrap; box-sizing:border-box;">' + sPriceText + '</td>' +
                '<td style="width:18.5%; padding:8px 6px; border-bottom:1px solid #f1f5f9; text-align:right; color:#16a34a; font-weight:800; font-size:11.5px; white-space:nowrap; box-sizing:border-box;">' + tCostText + '</td>' +
                '<td style="width:11%; padding:8px 6px; border-bottom:1px solid #f1f5f9; text-align:center; white-space:nowrap; box-sizing:border-box;">' +
                    '<span style="display:inline-block; padding:2px 7px; border-radius:8px; font-size:10.5px; font-weight:700; background:' + (isDamaged ? '#fee2e2;color:#b91c1c;' : '#dcfce7;color:#15803d;') + '">' +
                        siEscapeHtml(it.condition || 'Good') +
                    '</span>' +
                '</td>' +
            '</tr>';
        }).join('') || '<tr><td colspan="7" style="padding:18px;text-align:center;color:#94a3b8;font-size:12px;">No items listed.</td></tr>';
    }

    // Remarks
    const remWrap = document.getElementById('siv_remarks_wrap');
    if (remWrap) {
        if (data.remarks && String(data.remarks).trim()) {
            document.getElementById('siv_remarks').textContent = data.remarks;
            remWrap.style.display = 'block';
        } else {
            remWrap.style.display = 'none';
        }
    }

    // Print Invoice Button
    const printBtn = document.getElementById('siv_print_btn');
    if (printBtn) {
        const batchRef = data.batch_id && data.batch_id !== '—' ? data.batch_id : data.po_number;
        const bType = isFuel ? 'fuel' : 'merch';
        printBtn.href = 'print_supplier_invoice.php?batch_id=' + encodeURIComponent(batchRef) + '&type=' + encodeURIComponent(bType);
    }

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeStockInViewModal() {
    const modal = document.getElementById('stockInViewModal');
    if (modal) modal.style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function() {
    siRender();
    renderSiHistPagination();

    // Check URL param tab
    try {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('tab') === 'history') {
            switchStockInMainTab('history');
        }
    } catch(e) {}

    // Check for stored success banner from previous stock-in
    try {
        var stored = sessionStorage.getItem('petron_stock_in_success');
        if (stored) {
            var data = JSON.parse(stored);
            if (data && (Date.now() - (data.time || 0) < 600000)) {
                // showStockBanner(data);
                showStockToast(data.message || 'Stock-In Approved Successfully!', 'ok');
            }
            sessionStorage.removeItem('petron_stock_in_success');
        }
    } catch (e) {}

    <?php if (!empty($session_success)): ?>
    try {
        showStockToast(<?= json_encode($session_success) ?>, 'ok');
    } catch (e) {}
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
