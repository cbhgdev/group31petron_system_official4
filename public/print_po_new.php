<?php
/**
 * Official Purchase Order — Final Admin View
 * print_po_new.php
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? 'staff');

$staff_roles   = ['staff', 'cashier', 'pump_attendant'];
$is_staff_view = in_array($role, $staff_roles);
if (!in_array($role, ['admin', 'superadmin', 'manager', 'staff', 'cashier', 'pump_attendant'])) {
    http_response_code(403);
    die('<p style="font-family:Arial;padding:40px;color:#721c24;">Access denied.</p>');
}

$po_id     = (int)($_GET['id'] ?? 0);
$po_date   = $_GET['date']     ?? null;
$raw_param = trim($_GET['batch_id'] ?? $_GET['po_id'] ?? $_GET['po_number'] ?? $_GET['po'] ?? (isset($_GET['id']) && !is_numeric($_GET['id']) ? $_GET['id'] : ''));
$batch_id  = preg_replace('/\s+/', '-', $raw_param);
$base_po   = preg_replace('/-\d{2,}$/', '', $batch_id);
$raw_type  = $_GET['type']     ?? '';
$po_type   = (strpos(strtolower($raw_type), 'fuel') !== false) ? 'fuel' : ((strpos(strtolower($raw_type), 'merch') !== false) ? 'merch' : '');

if ($batch_id === '' && $po_id > 0) {
    try {
        $stmt_find_f = $pdo->prepare("SELECT batch_id, po_number FROM fuel_purchase_orders WHERE id = ? LIMIT 1");
        $stmt_find_f->execute([$po_id]);
        $rf = $stmt_find_f->fetch(PDO::FETCH_ASSOC);
        if ($rf) {
            $batch_id = $rf['batch_id'] ?: $rf['po_number'];
            $base_po  = preg_replace('/-\d{2,}$/', '', $batch_id);
            $po_type  = 'fuel';
        } else {
            $stmt_find_m = $pdo->prepare("SELECT batch_id, po_number FROM purchase_orders WHERE id = ? LIMIT 1");
            $stmt_find_m->execute([$po_id]);
            $rm = $stmt_find_m->fetch(PDO::FETCH_ASSOC);
            if ($rm) {
                $batch_id = $rm['batch_id'] ?: $rm['po_number'];
                $base_po  = preg_replace('/-\d{2,}$/', '', $batch_id);
                $po_type  = 'merch';
            }
        }
    } catch (Exception $e) {}
}

if (!$po_id && !$po_date && $batch_id === '') {
    die('<p style="font-family:Arial;padding:40px;">No Purchase Order ID, Date, or Batch ID provided.</p>');
}

$station_id = (int)user_station_id();
$po_items   = [];

// ── Check if inventory_products table exists ──────────────────────────────
$inv_products_exists = false;
try {
    $pdo->query("SELECT 1 FROM inventory_products LIMIT 1");
    $inv_products_exists = true;
} catch (Exception $e) {
    $inv_products_exists = false;
}

// Helper: build the inventory_products join fragment based on table availability
$ip_join_on_sku_or_name = $inv_products_exists
    ? "LEFT JOIN inventory_products ip ON (sr.item_sku = ip.sku OR po.product_name = ip.product_name)"
    : "-- inventory_products not available";
$ip_join_on_id = $inv_products_exists
    ? "LEFT JOIN inventory_products ip ON poi.product_id = ip.id"
    : "-- inventory_products not available";
$ip_join_sr_sku = $inv_products_exists
    ? "LEFT JOIN inventory_products ip ON sr.item_sku = ip.sku"
    : "-- inventory_products not available";
$ip_category_coalesce = $inv_products_exists
    ? "COALESCE(ip.category, pc.name, 'Lubricant')"
    : "COALESCE(pc.name, 'Lubricant')";
$ip_unit_cost_coalesce = $inv_products_exists
    ? "COALESCE(ip.unit_cost, p.cost, p.price, 0)"
    : "COALESCE(p.cost, p.price, 0)";
$ip_line_sku_coalesce = $inv_products_exists
    ? "COALESCE(ip.sku, '')"
    : "''";
$ip_line_category_coalesce = $inv_products_exists
    ? "COALESCE(ip.category, pc.name, 'Merchandise')"
    : "COALESCE(pc.name, 'Merchandise')";

// ── Fetch PO with all related data ────────────────────────────────────────
try {
    // Helper select columns for sup and st
    // NOTE: stations table has no 'contact' column; fall back to NULL
    $select_fields = "
        st.name AS station_name,
        st.location AS station_location,
        st.address AS station_address,
        st.vat_tin AS station_vat_tin,
        NULL AS station_contact,
        sup.name AS supplier_name,
        sup.contact_person AS supplier_contact_person,
        sup.phone AS supplier_phone,
        sup.email AS supplier_email,
        sup.address AS supplier_address
    ";

    if ($batch_id !== '') {
        $candidates = array_values(array_unique(array_filter([
            $batch_id,
            $raw_param,
            $base_po
        ])));
        $cand_ph = !empty($candidates) ? implode(',', array_fill(0, count($candidates), '?')) : "''";

        $matched_fuel_parent = '';
        try {
            $stmt_f_chk = $pdo->prepare("
                SELECT DISTINCT batch_id, po_number 
                FROM fuel_purchase_orders 
                WHERE (station_id = ? OR ? = 0)
                  AND (batch_id IN ($cand_ph) OR po_number IN ($cand_ph) OR batch_id LIKE ? OR po_number LIKE ?)
            ");
            $stmt_f_chk->execute(array_merge([$station_id, $station_id], $candidates, $candidates, [$base_po . '%', $base_po . '%']));
            $f_matches = $stmt_f_chk->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($f_matches)) {
                foreach ($f_matches as $fm) {
                    if (!empty($fm['batch_id'])) {
                        $matched_fuel_parent = $fm['batch_id'];
                        break;
                    }
                }
                if (!$matched_fuel_parent) {
                    $matched_fuel_parent = $base_po ?: $batch_id;
                }
            }
        } catch (Exception $e) {}

        if ($matched_fuel_parent !== '' || $po_type === 'fuel') {
            $fuel_key = $matched_fuel_parent ?: ($base_po ?: $batch_id);
            $stmt = $pdo->prepare("
                SELECT fpo.*,
                       fpo.volume AS quantity,
                       fpo.notes AS sr_manager_notes,
                       ft.name AS product_name,
                       'Fuel' AS product_category,
                       u.name AS created_by_name,
                       ab.name AS approved_by_name,
                       NULL AS staff_id,
                       NULL AS item_sku,
                       NULL AS sr_requested_qty,
                       NULL AS sr_approved_qty,
                       u.name AS staff_name,
                       NULL AS manager_name,
                       NULL AS request_id,
                       fpo.approved_at AS approved_at,
                       $select_fields
                FROM fuel_purchase_orders fpo
                LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                LEFT JOIN stations st ON fpo.station_id = st.id
                LEFT JOIN suppliers sup ON fpo.supplier_id = sup.id
                LEFT JOIN users u ON fpo.created_by = u.id
                LEFT JOIN users ab ON fpo.approved_by = ab.id
                WHERE (fpo.station_id = ? OR ? = 0)
                  AND (fpo.batch_id = ? OR fpo.po_number = ? OR fpo.batch_id = ? OR fpo.po_number LIKE ? OR fpo.batch_id LIKE ?)
                ORDER BY fpo.id ASC
            ");
            $stmt->execute([$station_id, $station_id, $fuel_key, $batch_id, $batch_id, $fuel_key . '%', $fuel_key . '%']);
            $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($po_items)) {
                $po_type = 'fuel';
            }
        }

        if (empty($po_items) && $po_type !== 'fuel') {
            $stmt = $pdo->prepare("
                SELECT po.*,
                       u.name AS created_by_name,
                       ab.name AS approved_by_name,
                       sr.staff_id,
                       sr.item_sku,
                       sr.requested_quantity AS sr_requested_qty,
                       sr.approved_quantity  AS sr_approved_qty,
                       sr.manager_notes      AS sr_manager_notes,
                       staff_u.name          AS staff_name,
                       mgr_u.name            AS manager_name,
                       {$ip_category_coalesce} AS product_category,
                       $select_fields
                FROM purchase_orders po
                LEFT JOIN stations st ON po.station_id = st.id
                LEFT JOIN suppliers sup ON po.supplier_id = sup.id
                LEFT JOIN users u ON po.created_by = u.id
                LEFT JOIN users ab ON po.approved_by = ab.id
                LEFT JOIN stock_requests sr ON po.request_id = sr.id
                LEFT JOIN users staff_u ON sr.staff_id = staff_u.id
                LEFT JOIN users mgr_u ON sr.manager_id = mgr_u.id
                {$ip_join_on_sku_or_name}
                LEFT JOIN products p ON (sr.item_id = p.id OR po.product_name = p.name)
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                WHERE (po.station_id = ? OR ? = 0)
                  AND (po.batch_id IN ($cand_ph) OR po.po_number IN ($cand_ph) OR po.batch_id LIKE ? OR po.po_number LIKE ?)
                ORDER BY po.id ASC
            ");
            $stmt->execute(array_merge([$station_id, $station_id], $candidates, $candidates, [$base_po . '%', $base_po . '%']));
            $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($po_items)) {
                $po_type = 'merch';
            }
        }

        if ($po_type !== 'fuel' && !empty($po_items)) {
            $parents_by_id = [];
            $po_ids = [];
            foreach ($po_items as $parent_row) {
                $parent_id = (int)($parent_row['id'] ?? 0);
                if ($parent_id > 0) {
                    $parents_by_id[$parent_id] = $parent_row;
                    $po_ids[] = $parent_id;
                }
            }

            if (!empty($po_ids)) {
                $ph = implode(',', array_fill(0, count($po_ids), '?'));
                $line_stmt = $pdo->prepare("
                    SELECT poi.po_id,
                           poi.item_name AS line_product_name,
                           COALESCE(poi.quantity_ordered, poi.quantity, 0) AS line_quantity,
                           poi.unit_price AS line_unit_price,
                           poi.total_price AS line_total_amount,
                           {$ip_line_sku_coalesce} AS line_sku,
                           {$ip_line_category_coalesce} AS line_category
                    FROM purchase_order_items poi
                    {$ip_join_on_id}
                    LEFT JOIN products p ON poi.product_id = p.id
                    LEFT JOIN product_categories pc ON p.category_id = pc.id
                    WHERE poi.po_id IN ($ph)
                    ORDER BY poi.id ASC
                ");
                $line_stmt->execute($po_ids);
                $line_rows = $line_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($line_rows)) {
                    $expanded_items = [];
                    foreach ($line_rows as $line) {
                        $base = $parents_by_id[(int)$line['po_id']] ?? $po_items[0];
                        $base['product_name'] = $line['line_product_name'];
                        $base['quantity'] = $line['line_quantity'];
                        $base['unit_price'] = $line['line_unit_price'];
                        $base['total_amount'] = $line['line_total_amount'];
                        $base['item_sku'] = $line['line_sku'] ?: ($base['item_sku'] ?? '');
                        $base['product_category'] = $line['line_category'] ?: ($base['product_category'] ?? 'Merchandise');
                        $expanded_items[] = $base;
                    }
                    $po_items = $expanded_items;
                }
            }
        }

        // Fallback to stock_requests / fuel_stock_requests if no purchase_orders exist yet
        if (empty($po_items)) {
            if ($po_type === 'fuel') {
                $stmt = $pdo->prepare("
                    SELECT fsr.id,
                           fsr.request_no AS po_number,
                           fsr.fuel_type AS product_name,
                           'Fuel' AS product_category,
                           fsr.approved_liters AS quantity,
                           0 AS unit_price,
                           0 AS total_amount,
                           fsr.created_at AS created_at,
                           fsr.processed_at AS approved_at,
                           fsr.remarks AS remarks,
                           fsr.status AS status,
                           u_staff.name AS staff_name,
                           u_mgr.name AS approved_by_name,
                           st.name AS station_name,
                           st.location AS station_location,
                           st.address AS station_address,
                           st.vat_tin AS station_vat_tin,
                           NULL AS station_contact,
                           'Petron Corporation' AS supplier_name
                    FROM fuel_stock_requests fsr
                    LEFT JOIN stations st ON fsr.station_id = st.id
                    LEFT JOIN users u_staff ON fsr.staff_id = u_staff.id
                    LEFT JOIN users u_mgr ON fsr.manager_id = u_mgr.id
                    WHERE fsr.request_no = ? AND fsr.station_id = ?
                ");
                $stmt->execute([$batch_id, $station_id]);
                $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->prepare("
                    SELECT sr.id,
                           sr.request_no AS po_number,
                           sr.item_name AS product_name,
                           {$ip_category_coalesce} AS product_category,
                           sr.approved_quantity AS quantity,
                           {$ip_unit_cost_coalesce} AS unit_price,
                           (sr.approved_quantity * {$ip_unit_cost_coalesce}) AS total_amount,
                           sr.created_at AS created_at,
                           sr.processed_at AS approved_at,
                           sr.manager_notes AS remarks,
                           sr.status AS status,
                           sr.item_sku,
                           u_staff.name AS staff_name,
                           u_mgr.name AS approved_by_name,
                           st.name AS station_name,
                           st.location AS station_location,
                           st.address AS station_address,
                           st.vat_tin AS station_vat_tin,
                           NULL AS station_contact,
                           'Petron Corporation' AS supplier_name
                    FROM stock_requests sr
                    LEFT JOIN stations st ON sr.station_id = st.id
                    LEFT JOIN users u_staff ON sr.staff_id = u_staff.id
                    LEFT JOIN users u_mgr ON sr.manager_id = u_mgr.id
                    {$ip_join_sr_sku}
                    LEFT JOIN products p ON sr.item_id = p.id
                    LEFT JOIN product_categories pc ON p.category_id = pc.id
                    WHERE sr.request_no = ? AND sr.station_id = ?
                ");
                $stmt->execute([$batch_id, $station_id]);
                $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $po = $po_items[0] ?? false;
        if ($po) {
            if ($po_type === 'fuel' && !empty($matched_fuel_parent)) {
                $po['po_number'] = $matched_fuel_parent;
            } elseif (!empty($po['batch_id'])) {
                $po['po_number'] = $po['batch_id'];
            } elseif (!empty($base_po)) {
                $po['po_number'] = $base_po;
            } else {
                $po['po_number'] = $batch_id;
            }
        }
    }
    elseif ($po_date) {
        if ($po_type === 'fuel') {
            $stmt = $pdo->prepare("
                SELECT fpo.*,
                       fpo.volume AS quantity,
                       fpo.notes AS sr_manager_notes,
                       ft.name AS product_name,
                       'Fuel' AS product_category,
                       u.name AS created_by_name,
                       ab.name AS approved_by_name,
                       NULL AS staff_id,
                       NULL AS item_sku,
                       NULL AS sr_requested_qty,
                       NULL AS sr_approved_qty,
                       u.name AS staff_name,
                       NULL AS manager_name,
                       NULL AS request_id,
                       fpo.approved_at AS approved_at,
                       $select_fields
                FROM fuel_purchase_orders fpo
                LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                LEFT JOIN stations st ON fpo.station_id = st.id
                LEFT JOIN suppliers sup ON fpo.supplier_id = sup.id
                LEFT JOIN users u ON fpo.created_by = u.id
                LEFT JOIN users ab ON fpo.approved_by = ab.id
                WHERE fpo.station_id = ? AND DATE(fpo.created_at) = ?
                ORDER BY fpo.id ASC
            ");
            $stmt->execute([$station_id, $po_date]);
        } else {
            $stmt = $pdo->prepare("
                SELECT po.*,
                       u.name AS created_by_name,
                       ab.name AS approved_by_name,
                       sr.staff_id,
                       sr.item_sku,
                       sr.requested_quantity AS sr_requested_qty,
                       sr.approved_quantity  AS sr_approved_qty,
                       sr.manager_notes      AS sr_manager_notes,
                       staff_u.name          AS staff_name,
                       mgr_u.name            AS manager_name,
                       {$ip_category_coalesce} AS product_category,
                       $select_fields
                FROM purchase_orders po
                LEFT JOIN stations st ON po.station_id = st.id
                LEFT JOIN suppliers sup ON po.supplier_id = sup.id
                LEFT JOIN users u ON po.created_by = u.id
                LEFT JOIN users ab ON po.approved_by = ab.id
                LEFT JOIN stock_requests sr ON po.request_id = sr.id
                LEFT JOIN users staff_u ON sr.staff_id = staff_u.id
                LEFT JOIN users mgr_u ON sr.manager_id = mgr_u.id
                {$ip_join_on_sku_or_name}
                LEFT JOIN products p ON (sr.item_id = p.id OR po.product_name = p.name)
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                WHERE po.station_id = ? AND DATE(po.created_at) = ? AND po.type = 'merch'
                ORDER BY po.id ASC
            ");
            $stmt->execute([$station_id, $po_date]);
        }
        $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($po_items) {
            $po = $po_items[0];
            $found_batch = $po_items[0]['batch_id'] ?? '';
            if (empty($found_batch)) {
                $date_tag = date('Ymd', strtotime($po_date));
                $prefix   = $po_type === 'fuel' ? 'POF-' : 'POM-';
                $found_batch = $prefix . $date_tag . '-BATCH';
            }
            $po['po_number'] = $found_batch;
        } else {
            $po = false;
        }
    }
    else {
        if ($po_type === 'fuel') {
            $stmt = $pdo->prepare("
                SELECT fpo.*,
                       fpo.volume AS quantity,
                       fpo.notes AS sr_manager_notes,
                       ft.name AS product_name,
                       'Fuel' AS product_category,
                       u.name AS created_by_name,
                       ab.name AS approved_by_name,
                       NULL AS staff_id,
                       NULL AS item_sku,
                       NULL AS sr_requested_qty,
                       NULL AS sr_approved_qty,
                       u.name AS staff_name,
                       NULL AS manager_name,
                       NULL AS request_id,
                       fpo.approved_at AS approved_at,
                       $select_fields
                FROM fuel_purchase_orders fpo
                LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
                LEFT JOIN stations st ON fpo.station_id = st.id
                LEFT JOIN suppliers sup ON fpo.supplier_id = sup.id
                LEFT JOIN users u ON fpo.created_by = u.id
                LEFT JOIN users ab ON fpo.approved_by = ab.id
                WHERE fpo.id = ?
                LIMIT 1
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT po.*,
                       u.name AS created_by_name,
                       ab.name AS approved_by_name,
                       sr.staff_id,
                       sr.item_sku,
                       sr.requested_quantity AS sr_requested_qty,
                       sr.approved_quantity  AS sr_approved_qty,
                       sr.manager_notes      AS sr_manager_notes,
                       staff_u.name          AS staff_name,
                       mgr_u.name            AS manager_name,
                       {$ip_category_coalesce} AS product_category,
                       $select_fields
                FROM purchase_orders po
                LEFT JOIN stations st ON po.station_id = st.id
                LEFT JOIN suppliers sup ON po.supplier_id = sup.id
                LEFT JOIN users u ON po.created_by = u.id
                LEFT JOIN users ab ON po.approved_by = ab.id
                LEFT JOIN stock_requests sr ON po.request_id = sr.id
                LEFT JOIN users staff_u ON sr.staff_id = staff_u.id
                LEFT JOIN users mgr_u ON sr.manager_id = mgr_u.id
                {$ip_join_on_sku_or_name}
                LEFT JOIN products p ON (sr.item_id = p.id OR po.product_name = p.name)
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                WHERE po.id = ?
                LIMIT 1
            ");
        }
        $stmt->execute([$po_id]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($po) {
            $po_items = [$po];
        } else {
            $po_items = [];
        }
    }
} catch (Exception $e) {
    die('<p style="font-family:Arial;padding:40px;">Database error: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

if (!$po) {
    die('<p style="font-family:Arial;padding:40px;">Purchase Order not found.</p>');
}

// Block printing only when record is explicitly rejected/cancelled.
$blocked_statuses = ['Rejected', 'rejected', 'Rejected by Admin', 'Cancelled', 'cancelled', 'Draft', 'draft'];
if ($po_id && !$batch_id && !$po_date && in_array($po['status'] ?? '', $blocked_statuses)) {
    die('<p style="font-family:Arial;padding:40px;color:#856404;">This PO has been rejected or cancelled and cannot be printed.</p>');
}

// Log PO view only. Printing happens from the browser button.
try {
    $po_label = $po_type === 'fuel' ? 'Fuel PO' : 'Purchase Order';
    log_activity(
        $pdo,
        $me['id'],
        'View Purchase Order',
        "{$po_label} {$po['po_number']} viewed by {$me['name']}."
    );
} catch (Exception $e) {}

// Gather values
$is_fuel        = ($po_type === 'fuel');
$qty_unit       = $is_fuel ? 'L' : 'pcs';
$finalized_dt   = (!empty($po['approved_at']) ? $po['approved_at'] : null) ?? $po['admin_finalized_at'] ?? $po['created_at'];
$finalized_date = date('F d, Y', strtotime($finalized_dt));
$finalized_time = date('g:i A', strtotime($finalized_dt));
$purchase_date  = date('F d, Y', strtotime($po['created_at']));
$printed_date   = date('F d, Y g:i A');
$po_number      = htmlspecialchars($po['po_number']);
$admin_name     = htmlspecialchars($po['approved_by_name'] ?? $me['name'] ?? '—');

// Expected Delivery Date parsing
$expected_delivery_date = 'N/A';
foreach ($po_items as $item) {
    $d = $item['expected_delivery_date'] ?? $item['expected_delivery'] ?? '';
    if (!empty($d) && $d !== '0000-00-00') {
        $expected_delivery_date = date('F d, Y', strtotime($d));
        break;
    }
}

// Parse all structured notes fields
$raw_notes = $po['admin_notes'] ?? $po['notes'] ?? $po['remarks'] ?? '';

$expected_delivery_time = '09:00 AM';
if (preg_match('/Expected Time:\s*(.+)/im', $raw_notes, $m))  { $expected_delivery_time = trim($m[1]); }
elseif (preg_match('/Expected Delivery Time:\s*([0-9:]+\s*[A-Z]{2})/i', $raw_notes, $m)) { $expected_delivery_time = trim($m[1]); }

$parsed_receiving = 'Any Assigned Staff';
if (preg_match('/Receiving Personnel:\s*(.+)/im', $raw_notes, $m)) { $parsed_receiving = trim($m[1]); }

$parsed_payment = '30 Days';
if (preg_match('/Payment Terms:\s*(.+)/im', $raw_notes, $m)) { $parsed_payment = trim($m[1]); }

$parsed_instructions = 'Deliver all items in one shipment.';
if (preg_match('/Instructions:\s*(.+)/im', $raw_notes, $m)) { $parsed_instructions = trim($m[1]); }

$parsed_remarks = 'None';
if (preg_match('/Remarks:\s*(.+)/im', $raw_notes, $m) && trim($m[1]) !== '') { $parsed_remarks = trim($m[1]); }

// Supplier Info
$supplier_name   = htmlspecialchars($po['supplier_name'] ?? 'Petron Corporation');
$sup_contact     = htmlspecialchars($po['supplier_contact_person'] ?? 'Account Manager');
$sup_phone       = htmlspecialchars($po['supplier_phone'] ?? '(02) 8884-9200');
$sup_email       = htmlspecialchars($po['supplier_email'] ?? 'sales@petron.com');
$sup_addr        = htmlspecialchars($po['supplier_address'] ?? 'San Miguel Corp. Head Office Complex, 40 San Miguel Ave, Mandaluyong City');

// Station Info — prefer address column, then location, then CDO default
$station_name  = htmlspecialchars($po['station_name'] ?? 'Petron Carmen');
$raw_addr      = trim($po['station_address'] ?? '');
$raw_loc       = trim($po['station_location'] ?? '');
if (empty($raw_addr) && !empty($raw_loc) && $raw_loc !== 'CDO') {
    $raw_addr = $raw_loc;
} elseif (empty($raw_addr)) {
    $raw_addr = 'Vamenta Blvd., Carmen, City of Cagayan de Oro, Misamis Oriental';
}
$station_addr  = htmlspecialchars($raw_addr);
$station_phone = htmlspecialchars($po['station_contact'] ?? 'N/A');
$vat_tin       = htmlspecialchars($po['station_vat_tin'] ?? '—');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Purchase Order — <?php echo $po_number; ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;font-size:11px;color:#333;background:#fff;padding:15px;padding-top:55px;line-height:1.3}
.po-document{width:100%;max-width:850px;margin:0 auto;border:1px solid #ddd;padding:20px;position:relative;background:#fff;box-shadow:0 0 10px rgba(0,0,0,0.05);}
.header-box{display:flex;justify-content:space-between;align-items:center;position:relative;padding-bottom:8px;}
.divider-double{border-top:3px double #333;margin:10px 0;}
.divider-single{border-top:1px dashed #ccc;margin:10px 0;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;}
.info-block{border:1px solid #e2e8f0;border-radius:4px;padding:8px;background:#f8fafc;}
.info-block h3{font-size:11px;font-weight:bold;color:#0f172a;border-bottom:1px solid #cbd5e1;padding-bottom:3px;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.3px;}
.info-row{margin-bottom:3px;display:flex;font-size:10px;}
.info-row strong{width:140px;color:#475569;display:inline-block;}
.info-row span{color:#0f172a;flex:1;}
.items-table{width:100%;border-collapse:collapse;margin:8px 0;}
.items-table th{background:#002F6C;color:#fff;padding:6px 8px;font-weight:600;text-align:left;font-size:10px;text-transform:uppercase;}
.items-table td{padding:5px 8px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:10px;}
.items-table th.r, .items-table td.r{text-align:right;}
.signatures-box{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:25px;}
.sig-col{text-align:center;}
.sig-line{border-top:1px solid #475569;width:80%;margin:30px auto 3px auto;}
.btn-print-bar{position:fixed;top:0;right:0;padding:8px 16px;display:flex;justify-content:flex-end;gap:10px;background:rgba(255,255,255,0.95);z-index:99999;box-shadow:0 2px 6px rgba(0,0,0,0.08);}
.btn-print{font-family:sans-serif;font-size:11px;padding:6px 14px;background:#002F6C;color:#fff;border:none;border-radius:4px;cursor:pointer;text-decoration:none;font-weight:600;pointer-events:auto !important;position:relative;z-index:99999;}
.btn-print:hover{background:#0b448a;}
.btn-back{background:#fff;color:#333;border:1px solid #ccc;}
.btn-back:hover{background:#f5f5f5;}

/* Rotated official stamp */
.official-stamp {
    position: absolute;
    top: 5px;
    right: 230px;
    border: 3px solid #2e7d32;
    color: #2e7d32;
    padding: 2px 10px;
    font-size: 13px;
    font-weight: bold;
    text-transform: uppercase;
    transform: rotate(-10deg);
    border-radius: 4px;
    background: #fff;
    font-family: 'Segoe UI', Arial, sans-serif;
    letter-spacing: 1px;
    z-index: 10;
    pointer-events: none;
    text-align: center;
    line-height: 1.1;
}
.official-stamp small {
    display: block;
    font-size: 7px;
    font-weight: bold;
    letter-spacing: 0.5px;
}

@media print{
    @page {
        size: A4;
        margin: 0.5cm;
    }
    .btn-print-bar{display:none !important;}
    body{padding:0;background:#fff;margin:0;}
    .po-document{border:none;padding:15px;box-shadow:none;page-break-after:avoid;}
    a[href]:after{content:none !important;}
    .header-box{padding-bottom:5px;}
    .info-grid{gap:8px;margin-bottom:8px;}
    .info-block{padding:6px;}
    .items-table{margin:6px 0;}
    .signatures-box{margin-top:15px;gap:15px;}
    .sig-line{margin:20px auto 3px auto;}
}
</style>
</head>
<body>

<div class="btn-print-bar">
    <?php if (!$is_staff_view): ?>
    <button type="button" onclick="window.print()" class="btn-print">Print PDF</button>
    <?php endif; ?>
    <button type="button" onclick="window.history.length > 1 ? window.history.back() : window.close();" class="btn-print btn-back">&#x2190; Close</button>
</div>

<div class="po-document">
    
    <!-- ── Document Header ── -->
    <div class="header-box">
        <!-- Left Side: Logo & Station Address -->
        <div style="display:flex; align-items:center; gap:15px; max-width:65%; text-align:left;">
            <?php
                $logo_url = '../' . get_system_logo_url($station_id);
            ?>
            <img src="<?php echo $logo_url; ?>" alt="Petron Logo" style="width:65px; height:65px; object-fit:contain;" onerror="this.src='../assets/img/Petron Logo.png'">
            <div>
                <h1 style="font-family:'Segoe UI', Arial, sans-serif; font-size:15px; font-weight:800; color:#002F6C; margin:0; line-height:1.1;">
                    Petron Station Management System
                </h1>
                <p style="font-size:9px; color:#333; margin-top:3px; font-weight:600;">
                    <?php echo htmlspecialchars($station_name); ?>
                </p>
                <p style="font-size:8.5px; color:#666; margin-top:2px; text-transform:uppercase; line-height:1.2;">
                    <?php echo htmlspecialchars($station_addr); ?>
                </p>
                <p style="font-size:8px; color:#666; margin-top:1px;">
                    Contact: <?php echo htmlspecialchars($station_phone); ?>
                </p>
            </div>
        </div>

        <!-- Right Side: PO Number & Dates -->
        <div style="text-align:right; position:relative; min-width:30%;">
            <!-- Official stamp rotated -->
            <div class="official-stamp">
                <small>PURCHASE ORDER</small>
                OFFICIAL
                <small>PURCHASE ORDER</small>
            </div>
            
            <div style="font-size:18px; font-weight:900; color:#002F6C; letter-spacing:-0.5px; font-family:'Courier New', Courier, monospace;">
                <?php echo $po_number; ?>
            </div>
            <div style="font-size:9px; color:#555; margin-top:3px; line-height:1.3;">
                <strong>Finalized:</strong> <?php echo $finalized_date; ?> <?php echo $finalized_time; ?><br>
                <strong>Printed:</strong> <?php echo $printed_date; ?>
            </div>
        </div>
    </div>

    <!-- Solid thick blue divider line underneath header -->
    <div style="border-top: 3px solid #002F6C; margin-bottom: 12px; width: 100%;"></div>

    <!-- Info Sections in 2 Columns Grid -->
    <div class="info-grid">
        <!-- Purchase Order Information -->
        <div class="info-block">
            <h3>Purchase Order Information</h3>
            <div class="info-row"><strong>Purchase Order No.</strong> <span><?php echo $po_number; ?></span></div>
            <div class="info-row"><strong>Request Batch ID</strong> <span><?php echo $po_number; ?></span></div>
            <div class="info-row"><strong>Purchase Date</strong> <span><?php echo $purchase_date; ?></span></div>
            <div class="info-row"><strong>Finalized Date</strong> <span><?php echo $finalized_date; ?></span></div>
            <div class="info-row"><strong>Status</strong> <span style="font-weight:600; color:#16a34a;">Approved / Pending Delivery</span></div>
        </div>
        
        <!-- Station Information -->
        <div class="info-block">
            <h3>Station Information</h3>
            <div class="info-row"><strong>Station Name</strong> <span><?php echo $station_name; ?></span></div>
            <div class="info-row"><strong>Branch Address</strong> <span><?php echo $station_addr; ?></span></div>
            <div class="info-row"><strong>Prepared By</strong> <span><?php echo $admin_name; ?></span></div>
        </div>
    </div>

    <!-- Supplier & Delivery Information -->
    <div class="info-block" style="margin-bottom:10px;">
        <h3>Supplier &amp; Delivery Information</h3>
        <div class="info-grid" style="grid-template-columns:1fr 1fr; gap:8px 25px; margin-bottom:0; background:none; padding:0; border:none;">
            <div>
                <div class="info-row"><strong>Official Supplier</strong> <span>Petron Corporation</span></div>
                <div class="info-row"><strong>Business Address</strong> <span>Petron Regional Depot &amp; Sales Office, Zone 4, Carmen, Cagayan de Oro City, Misamis Oriental, 9000</span></div>
                <div class="info-row"><strong>Reg. Details</strong> <span>SEC Reg. No. 31171 | TIN: 000-168-801-000 | CDO Regional Branch</span></div>
                <div class="info-row"><strong>Contact Person</strong> <span>Petron CDO Sales &amp; Supply Manager</span></div>
                <div class="info-row"><strong>Phone / Email</strong> <span>(088) 856-4321 | cdo.orders@petron.com</span></div>
            </div>
            <div>
                <div class="info-row"><strong>Delivery Terms</strong> <span>FOB Destination / Net 30 Days / CDO Local Tanker &amp; Container Delivery</span></div>
                <div class="info-row"><strong>Delivery Location</strong> <span><?php echo $station_addr; ?></span></div>
                <div class="info-row"><strong>Expected Delivery</strong> <span><?php echo $expected_delivery_date; ?> (<?php echo htmlspecialchars($expected_delivery_time); ?>)</span></div>
                <div class="info-row"><strong>Receiving Personnel</strong> <span><?php echo htmlspecialchars($parsed_receiving); ?></span></div>
                <div class="info-row"><strong>Instructions &amp; Remarks</strong> <span><?php echo htmlspecialchars($parsed_instructions ?: ($parsed_remarks ?: 'N/A')); ?></span></div>
            </div>
        </div>
    </div>

    <!-- Order Details -->
    <div>
        <h3 style="font-size:11px; font-weight:bold; color:#0f172a; text-transform:uppercase; margin-bottom:4px;">Order Details</h3>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:12%;">SKU</th>
                    <th>Product Name</th>
                    <th style="width:15%;">Category</th>
                    <th class="r" style="width:10%;">Quantity</th>
                    <th style="width:8%;">UOM</th>
                    <th class="r" style="width:14%;">Unit Price</th>
                    <th class="r" style="width:16%;">Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $subtotal = 0;
                foreach ($po_items as $idx => $item):
                    $qty = (float)($item['quantity'] ?? 0);
                    $price = (float)($item['unit_price'] ?? 0);
                    $stored_total = (float)($item['total_amount'] ?? $item['total_price'] ?? 0);
                    if ($price <= 0 && $qty > 0 && $stored_total > 0) {
                        $price = $stored_total / $qty;
                    }
                    $total = $stored_total > 0 ? $stored_total : ($qty * $price);
                    $subtotal += $total;
                ?>
                <tr>
                    <td><?php echo $idx + 1; ?></td>
                    <td><code style="font-weight:bold; font-size:11px;"><?php echo htmlspecialchars($item['item_sku'] ?: ($is_fuel ? 'FUEL-PO' : 'N/A')); ?></code></td>
                    <td><?php echo htmlspecialchars($item['product_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($item['product_category'] ?: ($is_fuel ? 'Fuel' : 'Lubricant')); ?></td>
                    <td class="r"><?php echo number_format($qty, $is_fuel ? 2 : 0); ?></td>
                    <td><?php echo $qty_unit; ?></td>
                    <td class="r">₱<?php echo number_format($price, 2); ?></td>
                    <td class="r">₱<?php echo number_format($total, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Order Summary -->
    <div style="display:flex; justify-content:flex-end; margin-top:6px;">
        <div style="width:280px; line-height:1.5; border:1px solid #e2e8f0; border-radius:4px; padding:8px; background:#f8fafc; font-size:10px;">
            <div style="display:flex; justify-content:space-between;"><span>Subtotal</span> <span style="font-weight:600;">₱<?php echo number_format($subtotal, 2); ?></span></div>
            <div style="display:flex; justify-content:space-between;"><span>Discount</span> <span style="font-weight:600;">₱0.00</span></div>
            <div style="display:flex; justify-content:space-between;"><span>VAT (12% Included)</span> <span style="font-weight:600;">₱<?php echo number_format($subtotal * 0.12, 2); ?></span></div>
            <div style="display:flex; justify-content:space-between; font-weight:bold; border-top:1px solid #cbd5e1; margin-top:4px; padding-top:4px; font-size:11px; color:#002F6C;">
                <span>Grand Total</span> <span>₱<?php echo number_format($subtotal, 2); ?></span>
            </div>
        </div>
    </div>

    <div class="divider-single" style="margin-top:12px; margin-bottom:35px;"></div>

    <!-- Signature Section -->
    <div class="signatures-box">
        <div class="sig-col">
            <div class="sig-line"></div>
            <strong style="font-size:10px;">Prepared By (Admin)</strong>
            <div style="font-size:9px; color:#475569; margin-top:2px;"><?php echo $admin_name; ?></div>
        </div>
        <div class="sig-col">
            <div class="sig-line"></div>
            <strong style="font-size:10px;">Supplier Representative</strong>
            <div style="font-size:8px; color:#94a3b8; margin-top:2px;">Signature over Printed Name / Date</div>
        </div>
        <div class="sig-col">
            <div class="sig-line"></div>
            <strong style="font-size:10px;">Received By</strong>
            <div style="font-size:8px; color:#94a3b8; margin-top:2px;">Signature over Printed Name / Date</div>
        </div>
    </div>

    
</div>

</body>
</html>
