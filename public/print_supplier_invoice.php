<?php
/**
 * Official Supplier Invoice / Receipt — Manager & Admin View
 * public/print_supplier_invoice.php
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['manager', 'admin', 'superadmin'], true)) {
    http_response_code(403);
    die('<p style="font-family:Arial;padding:40px;color:#721c24;">Access denied.</p>');
}

$station_id = (int)user_station_id();
$raw_param = trim($_GET['batch_id'] ?? $_GET['po_id'] ?? $_GET['po_number'] ?? $_GET['delivery_ref'] ?? $_GET['id'] ?? '');
if ($raw_param === '') {
    die('<p style="font-family:Arial;padding:40px;">Missing invoice reference parameters.</p>');
}

// Normalize spacing to hyphens (e.g. "PO 2026 0003 03" -> "PO-2026-0003-03")
$batch_id = preg_replace('/\s+/', '-', $raw_param);
$base_po  = preg_replace('/-\d{2,}$/', '', $batch_id); // e.g. "PO-2026-0003"

$raw_type = $_GET['type'] ?? '';
$type     = (strpos(strtolower($raw_type), 'fuel') !== false) ? 'fuel' : 'merch';

/* ── helpers ── */
function psr_due_date($d): string {
    $ts = strtotime((string)$d);
    return $ts ? date('Y-m-d', strtotime('+30 days', $ts)) : date('Y-m-d', strtotime('+30 days'));
}
function psr_date($d): string {
    $ts = strtotime((string)$d);
    return $ts ? date('F d, Y', $ts) : '—';
}
function psr_money($v): string {
    return '&#8369;' . number_format((float)$v, 2);
}
function si_extract_invoice(?string $remarks): string {
    $remarks = (string)$remarks;
    if (preg_match('/Invoice:\s*([^|]+)/i', $remarks, $m)) {
        return trim($m[1]);
    }
    return '';
}

/* ── station info ── */
$station_name  = 'Petron Station';
$station_addr  = 'Carmen, City of Cagayan de Oro, Misamis Oriental';
$vat_tin       = '—';
try {
    $st = $pdo->prepare("SELECT name, address, location, vat_tin FROM stations WHERE id=? LIMIT 1");
    $st->execute([$station_id]);
    $strow = $st->fetch(PDO::FETCH_ASSOC);
    if ($strow) {
        $station_name = $strow['name'] ?: $station_name;
        $raw_addr     = trim($strow['address'] ?? '');
        $raw_loc      = trim($strow['location'] ?? '');
        if (empty($raw_addr) && !empty($raw_loc) && $raw_loc !== 'CDO') $raw_addr = $raw_loc;
        if (!empty($raw_addr)) $station_addr = $raw_addr;
        $vat_tin = $strow['vat_tin'] ?: '—';
    }
} catch (Exception $e) {}

/* ── Collect all linked reference candidate keys ── */
$candidates = array_values(array_unique(array_filter([
    $batch_id,
    $raw_param,
    $base_po
])));

// 1. Discover linked references from fuel_purchase_orders and purchase_orders
try {
    $c_ph = implode(',', array_fill(0, count($candidates), '?'));
    $stmt_fpo = $pdo->prepare("SELECT po_number, batch_id FROM fuel_purchase_orders WHERE (station_id = ? OR ? = 0) AND (po_number IN ($c_ph) OR batch_id IN ($c_ph) OR po_number LIKE ? OR batch_id LIKE ?)");
    $stmt_fpo->execute(array_merge([$station_id, $station_id], $candidates, $candidates, [$base_po . '%', $base_po . '%']));
    while ($r = $stmt_fpo->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($r['po_number'])) $candidates[] = $r['po_number'];
        if (!empty($r['batch_id']))  $candidates[] = $r['batch_id'];
    }
} catch (Exception $e) {}

try {
    $c_ph = implode(',', array_fill(0, count($candidates), '?'));
    $stmt_po = $pdo->prepare("SELECT po_number, batch_id FROM purchase_orders WHERE (station_id = ? OR ? = 0) AND (po_number IN ($c_ph) OR batch_id IN ($c_ph) OR po_number LIKE ? OR batch_id LIKE ?)");
    $stmt_po->execute(array_merge([$station_id, $station_id], $candidates, $candidates, [$base_po . '%', $base_po . '%']));
    while ($r = $stmt_po->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($r['po_number'])) $candidates[] = $r['po_number'];
        if (!empty($r['batch_id']))  $candidates[] = $r['batch_id'];
    }
} catch (Exception $e) {}

// 2. Discover linked references from deliveries_oversight
try {
    $c_ph = implode(',', array_fill(0, count($candidates), '?'));
    $stmt_do = $pdo->prepare("SELECT id, source_ref, delivery_ref, batch_id, dr_number, sales_invoice_no FROM deliveries_oversight WHERE (station_id = ? OR ? = 0) AND (source_ref IN ($c_ph) OR delivery_ref IN ($c_ph) OR batch_id IN ($c_ph) OR source_ref LIKE ? OR delivery_ref LIKE ? OR batch_id LIKE ?)");
    $stmt_do->execute(array_merge([$station_id, $station_id], $candidates, $candidates, $candidates, [$base_po . '%', $base_po . '%', $base_po . '%']));
    while ($r = $stmt_do->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($r['source_ref']))       $candidates[] = $r['source_ref'];
        if (!empty($r['delivery_ref']))     $candidates[] = $r['delivery_ref'];
        if (!empty($r['batch_id']))         $candidates[] = $r['batch_id'];
        if (!empty($r['dr_number']))        $candidates[] = $r['dr_number'];
        if (!empty($r['sales_invoice_no'])) $candidates[] = $r['sales_invoice_no'];
        $candidates[] = 'DO-' . $r['id'];
    }
} catch (Exception $e) {}

$candidates = array_values(array_unique(array_filter($candidates)));
$cand_ph = implode(',', array_fill(0, count($candidates), '?'));

/* ── fetch items ── */
$items = [];
$is_fuel = ($type === 'fuel');
$po_number = $base_po ?: $batch_id;
$supplier = 'Petron Corporation';
$delivery_date = '';
$delivery_time = '';
$dr_number = '';
$sales_invoice_no = '';
$manager_name = '';
$approved_at = '';
$actual_batch_id = $batch_id;

// 1. Try Fuel Stock-In if fuel type requested or general
if ($type === 'fuel' || empty($items)) {
    try {
        $stmt_fsi = $pdo->prepare("
            SELECT fsi.*,
                   do.dr_number, do.sales_invoice_no, do.supplier AS do_supplier, do.delivery_date AS do_date,
                   do.delivery_time AS do_time, do.remarks AS do_remarks, u.name AS mgr_name
            FROM fuel_stock_in fsi
            LEFT JOIN deliveries_oversight do ON fsi.delivery_id = do.id
            LEFT JOIN users u ON fsi.encoded_by = u.id
            WHERE fsi.station_id = ? 
              AND (fsi.batch_ref IN ($cand_ph) OR fsi.delivery_ref IN ($cand_ph) OR fsi.invoice_no IN ($cand_ph)
                   OR do.source_ref IN ($cand_ph) OR do.delivery_ref IN ($cand_ph) OR do.batch_id IN ($cand_ph))
            ORDER BY fsi.id ASC
        ");
        $stmt_fsi->execute(array_merge([$station_id], $candidates, $candidates, $candidates, $candidates, $candidates, $candidates));
        $fsi_rows = $stmt_fsi->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($fsi_rows)) {
            $is_fuel = true;
            $po_number = $fsi_rows[0]['delivery_ref'] ?: $base_po;
            $actual_batch_id = $fsi_rows[0]['batch_ref'] ?: $batch_id;
            $supplier = $fsi_rows[0]['do_supplier'] ?: 'Petron Corporation';
            $delivery_date = $fsi_rows[0]['do_date'] ?: date('Y-m-d', strtotime($fsi_rows[0]['encoded_at']));
            $delivery_time = $fsi_rows[0]['do_time'] ?? '';
            $dr_number = $fsi_rows[0]['dr_number'] ?: ($fsi_rows[0]['invoice_no'] ?: '');
            $sales_invoice_no = $fsi_rows[0]['sales_invoice_no'] ?: ($fsi_rows[0]['invoice_no'] ?: '');
            if (empty($sales_invoice_no)) {
                $sales_invoice_no = si_extract_invoice($fsi_rows[0]['do_remarks']);
            }
            $manager_name = $fsi_rows[0]['mgr_name'] ?: '';
            $approved_at = $fsi_rows[0]['encoded_at'];

            foreach ($fsi_rows as $r) {
                $qty_ordered = (float)$r['qty_expected'];
                $qty_received = (float)$r['qty_received'];
                
                $cost = 0;
                try {
                    $cstmt = $pdo->prepare("SELECT COALESCE(unit_cost, unit_price, 0) FROM deliveries_oversight WHERE id = ?");
                    $cstmt->execute([$r['delivery_id']]);
                    $cost = (float)$cstmt->fetchColumn();
                } catch (Exception $e) {}

                if ($cost <= 0) {
                    try {
                        $cost_stmt = $pdo->prepare("SELECT unit_price FROM fuel_purchase_orders WHERE station_id = ? AND (po_number = ? OR batch_id = ? OR po_number LIKE ?) LIMIT 1");
                        $cost_stmt->execute([$station_id, $r['delivery_ref'], $r['delivery_ref'], $base_po . '%']);
                        $cost = (float)$cost_stmt->fetchColumn();
                    } catch (Exception $e) {}
                }
                
                $total = $qty_received * $cost;
                $items[] = [
                    'sku' => 'FUEL',
                    'name' => $r['fuel_type'],
                    'qty_ordered' => $qty_ordered,
                    'qty_received' => $qty_received,
                    'unit' => 'L',
                    'cost' => $cost,
                    'total' => $total,
                    'condition' => $r['condition_flag'] ?: 'Good',
                    'remarks' => $r['remarks']
                ];
            }
        }
    } catch (Exception $e) {
        error_log('[print_supplier_invoice] fuel stock in error: ' . $e->getMessage());
    }
}

// 2. Try Merchandise Stock-In if empty or type is merchandise
if (empty($items) && $type !== 'fuel') {
    try {
        $stmt_msi = $pdo->prepare("
            SELECT msi.*,
                   msi.sku AS sku,
                   msi.product_name AS product_name,
                   COALESCE(si.unit, 'pcs') AS unit_display,
                   do.dr_number, do.sales_invoice_no, do.supplier AS do_supplier, do.delivery_date AS do_date,
                   do.delivery_time AS do_time, do.remarks AS do_remarks, u.name AS mgr_name
            FROM merchandise_stock_in msi
            LEFT JOIN station_inventory si ON (si.product_id = msi.product_id AND si.station_id = msi.station_id)
            LEFT JOIN deliveries_oversight do ON msi.delivery_id = do.id
            LEFT JOIN users u ON msi.encoded_by = u.id
            WHERE msi.station_id = ? 
              AND (msi.batch_ref IN ($cand_ph) OR msi.po_number IN ($cand_ph) 
                   OR do.delivery_ref IN ($cand_ph) OR do.source_ref IN ($cand_ph) OR do.batch_id IN ($cand_ph))
            ORDER BY msi.id ASC
        ");
        $stmt_msi->execute(array_merge([$station_id], $candidates, $candidates, $candidates, $candidates, $candidates));
        $msi_rows = $stmt_msi->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($msi_rows)) {
            $is_fuel = false;
            $po_number = $msi_rows[0]['po_number'] ?: $base_po;
            $actual_batch_id = $msi_rows[0]['batch_ref'] ?: $batch_id;
            $supplier = $msi_rows[0]['do_supplier'] ?: 'Petron Corporation';
            $delivery_date = $msi_rows[0]['do_date'] ?: date('Y-m-d', strtotime($msi_rows[0]['encoded_at']));
            $delivery_time = $msi_rows[0]['do_time'] ?? '';
            $dr_number = $msi_rows[0]['dr_number'] ?: '';
            $sales_invoice_no = $msi_rows[0]['sales_invoice_no'] ?: '';
            if (empty($sales_invoice_no)) {
                $sales_invoice_no = si_extract_invoice($msi_rows[0]['do_remarks']);
            }
            $manager_name = $msi_rows[0]['mgr_name'] ?: '';
            $approved_at = $msi_rows[0]['encoded_at'];
            foreach ($msi_rows as $r) {
                $items[] = [
                    'sku'          => $r['sku'] ?: '—',
                    'name'         => $r['product_name'],
                    'qty_ordered'  => (float)$r['qty_ordered'],
                    'qty_received' => (float)$r['qty_received'],
                    'unit'         => $r['unit_display'] ?: 'pcs',
                    'cost'         => (float)$r['unit_cost'],
                    'total'        => (float)$r['total_cost'],
                    'condition'    => $r['condition_flag'] ?: 'Good',
                    'remarks'      => $r['remarks']
                ];
            }
        }
    } catch (Exception $e) {
        error_log('[print_supplier_invoice] merch stock in error: ' . $e->getMessage());
    }
}

// 3. Fallback: Check Deliveries Oversight
if (empty($items)) {
    try {
        $stmt_do_items = $pdo->prepare("
            SELECT do.*, u.name AS mgr_name
            FROM deliveries_oversight do
            LEFT JOIN users u ON (do.verified_by = u.id OR do.approved_by = u.id)
            WHERE do.station_id = ? 
              AND (do.source_ref IN ($cand_ph) OR do.delivery_ref IN ($cand_ph) OR do.batch_id IN ($cand_ph))
            ORDER BY do.id ASC
        ");
        $stmt_do_items->execute(array_merge([$station_id], $candidates, $candidates, $candidates));
        $do_rows = $stmt_do_items->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($do_rows)) {
            $is_fuel = (strtolower($do_rows[0]['delivery_type']) === 'fuel');
            $po_number = $do_rows[0]['source_ref'] ?: ($do_rows[0]['delivery_ref'] ?: $base_po);
            $actual_batch_id = $do_rows[0]['batch_id'] ?: $batch_id;
            $supplier = $do_rows[0]['supplier'] ?: 'Petron Corporation';
            $delivery_date = $do_rows[0]['delivery_date'] ?: date('Y-m-d', strtotime($do_rows[0]['created_at']));
            $delivery_time = $do_rows[0]['delivery_time'] ?? '';
            $dr_number = $do_rows[0]['dr_number'] ?: '';
            $sales_invoice_no = $do_rows[0]['sales_invoice_no'] ?: si_extract_invoice($do_rows[0]['remarks']);
            $manager_name = $do_rows[0]['mgr_name'] ?: '';
            $approved_at = $do_rows[0]['finalized_at'] ?: $do_rows[0]['created_at'];

            foreach ($do_rows as $r) {
                $qty_ordered = (float)($r['expected_quantity'] ?: $r['quantity']);
                $qty_received = (float)($r['actual_quantity'] !== null ? $r['actual_quantity'] : $r['quantity']);
                $cost = (float)($r['unit_cost'] ?: ($r['unit_price'] ?: 0));
                $total = (float)($r['total_cost'] ?: ($qty_received * $cost));

                $items[] = [
                    'sku'          => $is_fuel ? 'FUEL' : '—',
                    'name'         => $r['product'],
                    'qty_ordered'  => $qty_ordered,
                    'qty_received' => $qty_received,
                    'unit'         => $r['unit'] ?: ($is_fuel ? 'L' : 'pcs'),
                    'cost'         => $cost,
                    'total'        => $total,
                    'condition'    => ($r['damaged_quantity'] > 0) ? 'Damaged' : 'Good',
                    'remarks'      => $r['remarks']
                ];
            }
        }
    } catch (Exception $e) {}
}

// 4. Fallback: Check Fuel Purchase Orders
if (empty($items) && ($type === 'fuel' || empty($items))) {
    try {
        $stmt_fpo_items = $pdo->prepare("
            SELECT fpo.*, ft.name AS fuel_type_name, sup.name AS sup_name, u.name AS mgr_name
            FROM fuel_purchase_orders fpo
            LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id
            LEFT JOIN suppliers sup ON fpo.supplier_id = sup.id
            LEFT JOIN users u ON (fpo.approved_by = u.id OR fpo.created_by = u.id)
            WHERE (fpo.station_id = ? OR ? = 0)
              AND (fpo.po_number IN ($cand_ph) OR fpo.batch_id IN ($cand_ph) OR fpo.po_number LIKE ? OR fpo.batch_id LIKE ?)
            ORDER BY fpo.id ASC
        ");
        $stmt_fpo_items->execute(array_merge([$station_id, $station_id], $candidates, $candidates, [$base_po . '%', $base_po . '%']));
        $fpo_rows = $stmt_fpo_items->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($fpo_rows)) {
            $is_fuel = true;
            $po_number = !empty($fpo_rows[0]['batch_id']) ? $fpo_rows[0]['batch_id'] : $fpo_rows[0]['po_number'];
            $actual_batch_id = $po_number;
            $supplier = $fpo_rows[0]['sup_name'] ?: 'Petron Corporation';
            $delivery_date = $fpo_rows[0]['expected_delivery_date'] ?: date('Y-m-d', strtotime($fpo_rows[0]['created_at']));
            $delivery_time = '09:00 AM';
            $dr_number = 'PO-' . $fpo_rows[0]['id'];
            $sales_invoice_no = 'INV-' . date('Ymd', strtotime($fpo_rows[0]['created_at'])) . '-' . $fpo_rows[0]['id'];
            $manager_name = $fpo_rows[0]['mgr_name'] ?: '';
            $approved_at = $fpo_rows[0]['approved_at'] ?: $fpo_rows[0]['created_at'];

            foreach ($fpo_rows as $r) {
                $vol = (float)$r['volume'];
                $unit_p = (float)$r['unit_price'];
                $tot = (float)($r['total_amount'] ?: ($vol * $unit_p));
                if ($unit_p <= 0 && $vol > 0 && $tot > 0) {
                    $unit_p = $tot / $vol;
                }
                $items[] = [
                    'sku'          => 'FUEL',
                    'name'         => $r['fuel_type_name'] ?: 'Fuel',
                    'qty_ordered'  => $vol,
                    'qty_received' => $vol,
                    'unit'         => 'L',
                    'cost'         => $unit_p,
                    'total'        => $tot,
                    'condition'    => 'Good',
                    'remarks'      => $r['notes']
                ];
            }
        }
    } catch (Exception $e) {}
}

// 5. Fallback: Check Merchandise Purchase Orders
if (empty($items)) {
    try {
        $stmt_po_items = $pdo->prepare("
            SELECT po.*, poi.item_name, poi.quantity, poi.unit_price, poi.total_price, sup.name AS sup_name, u.name AS mgr_name
            FROM purchase_orders po
            LEFT JOIN purchase_order_items poi ON po.id = poi.po_id
            LEFT JOIN suppliers sup ON po.supplier_id = sup.id
            LEFT JOIN users u ON (po.approved_by = u.id OR po.created_by = u.id)
            WHERE (po.station_id = ? OR ? = 0)
              AND (po.po_number IN ($cand_ph) OR po.batch_id IN ($cand_ph) OR po.po_number LIKE ? OR po.batch_id LIKE ?)
            ORDER BY po.id ASC
        ");
        $stmt_po_items->execute(array_merge([$station_id, $station_id], $candidates, $candidates, [$base_po . '%', $base_po . '%']));
        $po_rows = $stmt_po_items->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($po_rows)) {
            $is_fuel = false;
            $po_number = !empty($po_rows[0]['batch_id']) ? $po_rows[0]['batch_id'] : $po_rows[0]['po_number'];
            $actual_batch_id = $po_number;
            $supplier = $po_rows[0]['sup_name'] ?: 'Petron Corporation';
            $delivery_date = $po_rows[0]['expected_delivery_date'] ?: date('Y-m-d', strtotime($po_rows[0]['created_at']));
            $delivery_time = '09:00 AM';
            $dr_number = 'PO-' . $po_rows[0]['id'];
            $sales_invoice_no = 'INV-' . date('Ymd', strtotime($po_rows[0]['created_at'])) . '-' . $po_rows[0]['id'];
            $manager_name = $po_rows[0]['mgr_name'] ?: '';
            $approved_at = $po_rows[0]['admin_finalized_at'] ?: ($po_rows[0]['approved_at'] ?: $po_rows[0]['created_at']);

            foreach ($po_rows as $r) {
                $qty = (float)$r['quantity'];
                $unit_p = (float)$r['unit_price'];
                $tot = (float)($r['total_price'] ?: ($qty * $unit_p));
                $items[] = [
                    'sku'          => '—',
                    'name'         => $r['item_name'] ?: $r['product_name'],
                    'qty_ordered'  => $qty,
                    'qty_received' => $qty,
                    'unit'         => 'pcs',
                    'cost'         => $unit_p,
                    'total'        => $tot,
                    'condition'    => 'Good',
                    'remarks'      => $r['remarks']
                ];
            }
        }
    } catch (Exception $e) {}
}

if (empty($items)) {
    die('<p style="font-family:Arial;padding:40px;">No record found for reference: ' . htmlspecialchars($batch_id) . '.</p>');
}

$invoice_date  = $delivery_date ?: date('Y-m-d');
$due_date      = psr_due_date($invoice_date);
$printed_date  = date('F d, Y g:i A');
$delivery_date_fmt = psr_date($delivery_date);
$delivery_time_fmt = (!empty($delivery_time) && $delivery_time !== '00:00:00')
    ? date('g:i A', strtotime($delivery_time))
    : '';
$invoice_date_fmt  = psr_date($invoice_date);
$due_date_fmt      = psr_date($due_date);
$approved_at_fmt   = $approved_at ? psr_date($approved_at) . ' ' . date('g:i A', strtotime($approved_at)) : '—';

$receipt_no    = 'RCP-' . date('Y') . '-' . strtoupper(substr(md5($po_number . $actual_batch_id), 0, 6));
$logo_url      = '../' . get_system_logo_url($station_id);

/* subtotal from items */
$subtotal = array_sum(array_column($items, 'total'));
$total_amount = $subtotal;

/* back link depending on role */
$back_url = 'admin_inventory_merchandise.php?tab=overview';
if ($role === 'admin' || $role === 'superadmin') {
    $back_url = 'admin_inventory_merchandise.php?tab=overview';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sales Invoice / Supplier Receipt — <?= htmlspecialchars($po_number) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;font-size:11px;color:#333;background:#fff;padding:15px;line-height:1.3}
.po-document{width:100%;max-width:850px;margin:0 auto;border:1px solid #ddd;padding:20px;position:relative;background:#fff;box-shadow:0 0 10px rgba(0,0,0,0.05);}
.header-box{display:flex;justify-content:space-between;align-items:center;position:relative;padding-bottom:8px;}
.divider-double{border-top:3px double #333;margin:10px 0;}
.divider-single{border-top:1px dashed #ccc;margin:10px 0;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;}
.info-block{border:1px solid #e2e8f0;border-radius:4px;padding:8px;background:#f8fafc;}
.info-block h3{font-size:11px;font-weight:bold;color:#002F6C;border-bottom:1px solid #cbd5e1;padding-bottom:3px;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.3px;}
.info-row{margin-bottom:3px;display:flex;font-size:10px;}
.info-row strong{width:140px;color:#475569;display:inline-block;}
.info-row span{color:#0f172a;flex:1;}
.items-table{width:100%;border-collapse:collapse;margin:8px 0;}
.items-table th{background:#002F6C;color:#fff;padding:6px 8px;font-weight:600;text-align:left;font-size:10px;text-transform:uppercase;}
.items-table td{padding:5px 8px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:10px;}
.items-table th.r,.items-table td.r{text-align:right;}
.signatures-box{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:25px;}
.sig-col{text-align:center;}
.sig-line{border-top:1px solid #475569;width:80%;margin:30px auto 3px auto;}
.btn-print-bar{
    position:fixed;
    top:14px;
    right:18px;
    display:flex;
    align-items:center;
    gap:8px;
    z-index:999;
}
.btn-print{
    font-family:sans-serif;
    font-size:12px;
    padding:8px 18px;
    background:#002F6C;
    color:#fff;
    border:none;
    border-radius:6px;
    cursor:pointer;
    text-decoration:none;
    font-weight:700;
    display:inline-flex;
    align-items:center;
    gap:6px;
    transition:background 0.2s, box-shadow 0.2s;
    box-shadow:0 2px 8px rgba(0,47,108,0.30);
}
.btn-print:hover{background:#0b448a;box-shadow:0 4px 14px rgba(0,47,108,0.45);}
.btn-back{
    background:#fff;
    color:#333;
    border:1px solid #ccc;
    box-shadow:0 1px 4px rgba(0,0,0,0.10);
}
.btn-back:hover{background:#f5f5f5;}

/* Rotated official stamp */
.official-stamp {
    position: absolute;
    top: 5px;
    right: 230px;
    border: 3px solid #16a34a;
    color: #16a34a;
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
    @page { size: A4; margin: 0.5cm; }
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
    .official-stamp{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .items-table th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>
</head>
<body>

<div class="btn-print-bar">
    <button type="button" onclick="window.print()" class="btn-print">&#128438; Print Invoice</button>
    <button type="button" onclick="window.history.length > 1 ? window.history.back() : window.close();" class="btn-print btn-back">&#x2190; Close</button>
</div>

<div class="po-document">

    <!-- ── Document Header ── -->
    <div class="header-box">
        <!-- Left: Logo & Station -->
        <div style="display:flex; align-items:center; gap:15px; max-width:65%; text-align:left;">
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="Petron Logo"
                 style="width:65px; height:65px; object-fit:contain;"
                 onerror="this.src='../assets/img/Petron Logo.png'">
            <div>
                <h1 style="font-family:'Segoe UI', Arial, sans-serif; font-size:15px; font-weight:800; color:#002F6C; margin:0; line-height:1.1;">
                    Petron Station Management System
                </h1>
                <p style="font-size:9px; color:#333; margin-top:3px; font-weight:600;">
                    <?= htmlspecialchars($station_name) ?>
                </p>
                <p style="font-size:8.5px; color:#666; margin-top:2px; text-transform:uppercase; line-height:1.2;">
                    <?= htmlspecialchars($station_addr) ?>
                </p>
                <p style="font-size:8px; color:#666; margin-top:1px;">
                    TIN / VAT: <?= htmlspecialchars($vat_tin) ?>
                </p>
            </div>
        </div>

        <!-- Right: Receipt No & Dates -->
        <div style="text-align:right; position:relative; min-width:30%;">
            <!-- Official stamp rotated -->
            <div class="official-stamp">
                <small>SUPPLIER</small>
                INVOICE
                <small>RECEIPT</small>
            </div>

            <div style="font-size:18px; font-weight:900; color:#002F6C; letter-spacing:-0.5px; font-family:'Courier New', Courier, monospace;">
                <?= htmlspecialchars($receipt_no) ?>
            </div>
            <div style="font-size:9px; color:#555; margin-top:3px; line-height:1.3;">
                <strong>Approved:</strong> <?= $approved_at_fmt ?><br>
                <strong>Printed:</strong> <?= $printed_date ?>
            </div>
        </div>
    </div>

    <!-- Blue divider -->
    <div style="border-top: 3px solid #002F6C; margin-bottom: 12px; width: 100%;"></div>

    <!-- Info Grid Row 1: Invoice Info & Station Info -->
    <div class="info-grid">
        <div class="info-block">
            <h3>Invoice Information</h3>
            <div class="info-row"><strong>Receipt No.</strong>    <span><?= htmlspecialchars($receipt_no) ?></span></div>
            <div class="info-row"><strong>Batch Reference</strong><span><?= htmlspecialchars($actual_batch_id) ?></span></div>
            <div class="info-row"><strong>PO Reference</strong>   <span><?= htmlspecialchars($po_number) ?></span></div>
            <div class="info-row"><strong>Sales Invoice No.</strong><span><?= htmlspecialchars($sales_invoice_no ?: '—') ?></span></div>
            <div class="info-row"><strong>Delivery Receipt (DR)</strong><span><?= htmlspecialchars($dr_number ?: '—') ?></span></div>
        </div>

        <div class="info-block">
            <h3>Station Information</h3>
            <div class="info-row"><strong>Station Name</strong>  <span><?= htmlspecialchars($station_name) ?></span></div>
            <div class="info-row"><strong>Branch Address</strong><span><?= htmlspecialchars($station_addr) ?></span></div>
            <div class="info-row"><strong>TIN / VAT</strong>    <span><?= htmlspecialchars($vat_tin) ?></span></div>
            <div class="info-row"><strong>Approved By</strong>  <span><?= htmlspecialchars($manager_name ?: 'Manager') ?></span></div>
        </div>
    </div>

    <!-- Info Block: Supplier & Delivery Details -->
    <div class="info-block" style="margin-bottom:10px;">
        <h3>Supplier &amp; Delivery Information</h3>
        <div class="info-grid" style="grid-template-columns:1fr 1fr; gap:8px 25px; margin-bottom:0; background:none; padding:0; border:none;">
            <div>
                <div class="info-row"><strong>Supplier</strong>       <span><?= htmlspecialchars($supplier) ?></span></div>
                <div class="info-row"><strong>Invoice Date</strong>   <span><?= $invoice_date_fmt ?></span></div>
                <div class="info-row"><strong>Delivery Date</strong>  <span><?= $delivery_date_fmt ?><?= $delivery_time_fmt ? ' &nbsp;<em style="color:#475569;font-style:normal;">at ' . htmlspecialchars($delivery_time_fmt) . '</em>' : '' ?></span></div>
                <div class="info-row"><strong>Payment Terms</strong>  <span>30 Days</span></div>
            </div>
            <div>
                <div class="info-row"><strong>Delivery Location</strong>  <span><?= htmlspecialchars($station_addr) ?></span></div>
                <div class="info-row"><strong>Due Date</strong>           <span><?= $due_date_fmt ?></span></div>
                <div class="info-row"><strong>Date Approved</strong>      <span><?= $approved_at_fmt ?></span></div>
                <div class="info-row"><strong>Remarks</strong>            <span>Based on actual delivery received.</span></div>
            </div>
        </div>
    </div>

    <!-- Delivered Items -->
    <div>
        <h3 style="font-size:11px; font-weight:bold; color:#0f172a; text-transform:uppercase; margin-bottom:4px;">
            <?= $is_fuel ? 'Fuel Delivery Details' : 'Merchandise Delivery Details' ?>
        </h3>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <?php if (!$is_fuel): ?>
                    <th style="width:13%;">SKU / Code</th>
                    <th>Product Name</th>
                    <th class="r" style="width:12%;">Qty Ordered</th>
                    <th class="r" style="width:12%;">Qty Received</th>
                    <th style="width:6%;">UOM</th>
                    <th class="r" style="width:14%;">Unit Cost</th>
                    <?php else: ?>
                    <th>Fuel Type</th>
                    <th class="r" style="width:16%;">Liters Ordered</th>
                    <th class="r" style="width:16%;">Liters Received</th>
                    <th class="r" style="width:16%;">Cost / Liter</th>
                    <?php endif; ?>
                    <th class="r" style="width:16%;">Total Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $idx => $item): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <?php if (!$is_fuel): ?>
                    <td><code style="font-weight:bold;font-size:11px;"><?= htmlspecialchars($item['sku']) ?></code></td>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td class="r"><?= number_format($item['qty_ordered'], 0) ?></td>
                    <td class="r"><?= number_format($item['qty_received'], 0) ?></td>
                    <td><?= htmlspecialchars($item['unit']) ?></td>
                    <td class="r">&#8369;<?= number_format($item['cost'], 2) ?></td>
                    <?php else: ?>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td class="r"><?= number_format($item['qty_ordered'], 2) ?> L</td>
                    <td class="r"><?= number_format($item['qty_received'], 2) ?> L</td>
                    <td class="r">&#8369;<?= number_format($item['cost'], 2) ?></td>
                    <?php endif; ?>
                    <td class="r">&#8369;<?= number_format($item['total'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Summary Box (right-aligned, same as PO print) -->
    <div style="display:flex; justify-content:flex-end; margin-top:6px;">
        <div style="width:280px; line-height:1.5; border:1px solid #e2e8f0; border-radius:4px; padding:8px; background:#f8fafc; font-size:10px;">
            <div style="display:flex; justify-content:space-between;">
                <span>Subtotal</span>
                <span style="font-weight:600;">&#8369;<?= number_format($subtotal, 2) ?></span>
            </div>
            <div style="display:flex; justify-content:space-between;">
                <span>VAT (12% Included)</span>
                <span style="font-weight:600;">&#8369;<?= number_format($subtotal * 0.12, 2) ?></span>
            </div>
            <div style="display:flex; justify-content:space-between; font-weight:bold; border-top:1px solid #cbd5e1; margin-top:4px; padding-top:4px; font-size:11px; color:#002F6C;">
                <span>Grand Total</span>
                <span>&#8369;<?= number_format($total_amount, 2) ?></span>
            </div>
        </div>
    </div>

    <div class="divider-single" style="margin-top:12px; margin-bottom:35px;"></div>

    <!-- Signature Section -->
    <div class="signatures-box">
        <div class="sig-col">
            <div class="sig-line"></div>
            <strong style="font-size:10px;">Approved By (Manager)</strong>
            <div style="font-size:9px; color:#475569; margin-top:2px;"><?= htmlspecialchars($manager_name ?: 'Manager') ?></div>
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

<script>
// No auto-print — user clicks "Print Invoice" button to print manually
</script>
</body>
</html>

