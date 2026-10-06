<?php
/**
 * Staff Merchandise Inventory — Enhanced
 * Filters: Category, Status, Sort | Summary Cards | Last Movement | View Details
 */
$page_id = 'inv_merch';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'admin', 'manager', 'superadmin'])) {
    header('Location: login.php');
    exit;
}

$merch_inventory = [];
$msg = '';

// ── Main catalog for this specific station ────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(ip.id, p.id, si.product_id)         AS id,
            COALESCE(ip.product_name, p.name, 'Unknown Product') AS name,
            COALESCE(ip.category, pc.name, 'Merchandise') AS category_name,
            COALESCE(ip.description, p.description, '')  AS description,
            COALESCE(si.price, ip.unit_price, p.price, 0) AS unit_price,
            COALESCE(si.cost, ip.unit_cost, p.cost, 0)   AS unit_cost,
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
            si.physical_count,
            si.variance,
            COALESCE(si.last_updated, NOW())             AS last_updated
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

// ── Batches per product (FIFO) ─────────────────────────────────
$product_batches = [];
try {
    $bStmt = $pdo->prepare("
        SELECT id, batch_ref, product_id, qty_received, selling_price, encoded_at
        FROM merchandise_stock_in
        WHERE station_id = ? AND product_id IS NOT NULL
        ORDER BY encoded_at DESC
    ");
    $bStmt->execute([$station_id]);
    foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $pid = (int)$b['product_id'];
        $product_batches[$pid][] = [
            'batch_id'      => $b['batch_ref'] ?: ('BATCH-' . str_pad((string)$b['id'], 4, '0', STR_PAD_LEFT)),
            'remaining_qty' => (int)$b['qty_received'],
            'selling_price' => (float)($b['selling_price'] ?: 0),
            'status'        => 'Active',
            'date'          => $b['encoded_at']
        ];
    }
} catch (Exception $e) {}

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

// ── Stock Out map: total sold/deducted per product (by ID & Name) ───────────
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
try {
    $imStmt = $pdo->prepare("
        SELECT product_id, COALESCE(SUM(ABS(quantity_change)), 0) AS total_deducted
        FROM inventory_movements
        WHERE station_id = ?
          AND quantity_change < 0 AND product_id IS NOT NULL
        GROUP BY product_id
    ");
    $imStmt->execute([$station_id]);
    foreach ($imStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['product_id'];
        $existing = $prod_deducted_map_id[$pid] ?? 0;
        $prod_deducted_map_id[$pid] = max($existing, (float)$r['total_deducted']);
    }
} catch (Exception $e) {}

// ── Build js_items + summary stats ────────────────────────────
$all_categories = [];
$all_brands = [];
$all_units = [];
$stats = ['total'=>0,'available'=>0,'low'=>0,'out'=>0,'expired'=>0];
$js_items = [];

foreach ($merch_inventory as $item) {
    $raw_status = strtolower(trim((string)($item['status'] ?? 'active')));
    if (in_array($raw_status, ['inactive', 'disabled', 'archived'], true)) continue;

    $stock    = (float)($item['stock_level'] ?? 0);
    // Capacity default = 480, reorder (Low Stock) threshold = 24
    $capacity = max(480.0, (float)($item['capacity'] ?? 480));
    $reorder  = (float)($item['reorder_level'] ?? 24);
    if ($reorder <= 0) $reorder = 24;   // safety: never zero
    $critical = (float)($item['critical_level'] ?? 10);
    if ($critical <= 0) $critical = 10; // safety: never zero

    $exp_status = $item['exp_status'] ?? '';
    $exp_date   = $item['expiration_date'] ?? 'N/A';
    $exp_raw    = $item['exp_raw'] ?? null;
    $is_expired = ($exp_status === 'expired');

    $fill_pct = $capacity > 0 ? min(100, ($stock / $capacity) * 100) : 0;
    // Status: expired takes priority, then stock thresholds
    if      ($is_expired)         { $st='EXPIRED';        $sc='#dc3545'; $st_cls='expired'; }
    elseif  ($stock <= 0)         { $st='OUT OF STOCK';  $sc='#dc3545'; $st_cls='out'; }
    elseif  ($stock <= $critical) { $st='CRITICAL STOCK'; $sc='#dc3545'; $st_cls='critical'; }
    elseif  ($stock <= $reorder)  { $st='LOW STOCK';     $sc='#fd7e14'; $st_cls='low'; }
    else                          { $st='AVAILABLE';     $sc='#28a745'; $st_cls='ok'; }

    $stats['total']++;
    if ($is_expired) {
        $stats['expired']++;
    } elseif ($st_cls === 'ok') {
        $stats['available']++;
    } elseif ($st_cls === 'low' || $st_cls === 'critical') {
        $stats['low']++;
    } else {
        $stats['out']++;
    }

    $pid = (int)$item['id'];
    $pname_norm = strtolower(trim((string)($item['name'] ?? '')));
    $mv  = $last_movements[$pid] ?? null;

    $cat_label = format_product_category_display($item['category_name'] ?? 'Uncategorized', $item['name'] ?? '', $item['description'] ?? '');
    if (!in_array($cat_label,$all_categories)) $all_categories[]=$cat_label;

    $brand = get_product_brand($item['name'], $cat_label, $item['description'] ?? '');
    if (!in_array($brand,$all_brands)) $all_brands[]=$brand;

    $uom = format_product_unit_display($item['unit'] ?? 'pcs', $item['name'] ?? '', $cat_label);
    if (!in_array($uom,$all_units)) $all_units[]=$uom;

    $batches = $product_batches[$pid] ?? [
        [
            'batch_id'        => 'BT-' . date('Ymd', strtotime($item['last_updated'] ?? 'now')) . '-' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT),
            'remaining_qty'   => (int)$stock,
            'selling_price'   => (float)($item['price'] ?? 0),
            'status'          => 'Active',
            'date'            => $item['last_updated'] ?? date('Y-m-d'),
            'expiration_date' => $exp_date
        ]
    ];

    $st_in_val  = $prod_added_map_id[$pid] ?? $prod_added_map_name[$pname_norm] ?? 0;
    $st_out_val = $prod_deducted_map_id[$pid] ?? $prod_deducted_map_name[$pname_norm] ?? 0;

    $js_items[] = [
        'id'              => $pid,
        'name'            => $item['name'],
        'sku'             => (!empty($item['sku']) && $item['sku'] !== '-') ? $item['sku'] : ('P' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT)),
        'barcode'         => !empty($item['barcode']) ? $item['barcode'] : ('480' . str_pad((string)$pid, 9, '0', STR_PAD_LEFT)),
        'supplier'        => 'Petron Main Depot / Authorized Supplier',
        'category'        => $cat_label,
        'brand'           => $brand,
        'unit'            => $uom,
        'stock'           => (int)$stock,
        'capacity'        => (int)$capacity,
        'reorder'         => (int)$reorder,
        'critical'        => (int)$critical,
        'fill_pct'        => round($fill_pct, 1),
        'physical_count'  => $item['physical_count'] !== null ? (float)$item['physical_count'] : null,
        'variance'        => $item['variance'] !== null ? (float)$item['variance'] : null,
        'status'          => $st,
        'status_key'      => $st_cls,
        'color'           => $sc,
        'expiration_date' => $exp_date,
        'exp_raw'         => $exp_raw,
        'exp_status'      => $exp_status,
        'mv_label'        => $mv ? ($mv['sign'].$mv['qty'].' '.$mv['type']) : '',
        'mv_sign'         => $mv ? $mv['sign'] : '',
        'price'           => (float)($item['price'] ?? 0),
        'last_updated'    => $item['last_updated'] ?? '',
        'batches'         => $batches,
        'stock_in'        => (int)$st_in_val,
        'stock_out'       => (int)$st_out_val,
    ];
}
sort($all_categories);
sort($all_brands);
sort($all_units);

// ── Fetch approved Stock-In records for Stock In tab (Read Only) ──
$stock_in_list = [];
try {
    $stIn = $pdo->prepare("
        SELECT 
            msi.id,
            CONCAT('SI-', LPAD(msi.id, 5, '0')) AS stock_in_no,
            msi.product_id,
            msi.product_name,
            msi.qty_received,
            COALESCE(NULLIF(msi.batch_ref, ''), CONCAT('BATCH-', LPAD(msi.id, 4, '0'))) AS batch_no,
            msi.encoded_at AS date_received,
            COALESCE(u.name, u.username, 'Staff') AS received_by,
            COALESCE(si.expiration_date, ip.expiration_date, p.expiration_date) AS expiration_date
        FROM merchandise_stock_in msi
        LEFT JOIN users u ON msi.encoded_by = u.id
        LEFT JOIN station_inventory si ON msi.product_id = si.product_id AND si.station_id = msi.station_id
        LEFT JOIN inventory_products ip ON msi.product_id = ip.id
        LEFT JOIN products p ON msi.product_id = p.id
        WHERE msi.station_id = ?
        ORDER BY msi.encoded_at DESC, msi.id DESC
        LIMIT 200
    ");
    $stIn->execute([$station_id]);
    $stock_in_list = $stIn->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';

// Display redirected notice from staff_stock_in.php access attempt
$inv_notice = $_SESSION['inv_notice'] ?? null;
unset($_SESSION['inv_notice']);
?>
<div class="stock-page">
<?php if ($inv_notice): ?>
<div style="background:#e8f4fd; border-left:4px solid #002F70; border-radius:8px; padding:13px 18px; margin-bottom:18px; display:flex; align-items:flex-start; gap:12px; font-size:13px; color:#002F70; line-height:1.5;">
    <i class="fas fa-info-circle" style="font-size:18px; margin-top:1px; flex-shrink:0;"></i>
    <div><strong>Note:</strong> <?php echo htmlspecialchars($inv_notice); ?></div>
</div>
<?php endif; ?>
<style>
body,html{overflow-x:hidden;max-width:100%;}
.stock-page{overflow-x:hidden;max-width:100%;padding:0 !important;margin:0 !important;}
.page-head { display:flex; justify-content:space-between; gap:16px; align-items:center; margin-top:0 !important; margin-bottom:25px !important; padding:0 !important; border:none !important; width:100%; }
.page-head h1, .page-head .h1 { margin:0; color:#002f70 !important; font-size:24px !important; font-weight:700 !important; text-transform:uppercase !important; letter-spacing:0.5px !important; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif !important; display:flex !important; align-items:center !important; gap:10px !important; line-height:1.2 !important; }
.main, .main-content { padding-top: 0 !important; }

/* ── Summary Cards ── */
.inv-stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;}
.inv-stat-card{background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;padding:16px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 3px rgba(0,0,0,.05);position:relative;overflow:hidden;transition:all .18s ease;}
.inv-stat-card[data-filter]{cursor:pointer;user-select:none;}
.inv-stat-card[data-filter]:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.12);border-color:#94a3b8;}
.inv-stat-card.card-active{border-width:2px!important;box-shadow:0 4px 14px rgba(0,0,0,.15)!important;}
.inv-stat-card.card-active .inv-stat-label::after{content:' <i class="fas fa-times"></i> (click to reset)';font-size:9px;opacity:.75;}
.inv-stat-info{display:flex;flex-direction:column;}
.inv-stat-label{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;}
.inv-stat-val{font-size:20px;font-weight:700;color:#1e293b;}
.inv-stat-icon{font-size:24px;opacity:0.8;}
@media(max-width:768px){.inv-stats-row{grid-template-columns:repeat(2,1fr);}}
/* ── Navigation Tabs - Matches Reports sub-tab design ── */
.inv-tab-nav { display: flex !important; flex-wrap: wrap !important; margin-bottom: 22px !important; border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; border-bottom: 3px solid #00264D !important; gap: 0 !important; background: transparent !important; padding: 0 !important; width: 100% !important; }
.inv-tab-btn { flex: 1 !important; min-width: 140px !important; padding: 13px 18px !important; font-size: 13px !important; font-weight: 800 !important; color: #334155 !important; background: #ffffff !important; border: none !important; border-right: 1px solid #d1d9e6 !important; border-radius: 0 !important; text-decoration: none !important; transition: all 0.15s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 8px !important; text-transform: uppercase !important; letter-spacing: 0.4px !important; text-align: center !important; cursor: pointer !important; margin-bottom: 0 !important; box-shadow: none !important; }
.inv-tab-btn:last-child { border-right: none !important; }
.inv-tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.inv-tab-btn.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; box-shadow: none !important; }
.inv-filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:16px;}
.inv-filter-bar input[type=text]{padding:8px 10px;border:1px solid #ced4da;border-radius:6px;font-size:13px;color:#374151;background:#fff;height:36px;outline:none;}
.inv-filter-bar input[type=text]:focus{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.inv-filter-bar input[type=text]{min-width:220px;}
.inv-filter-bar select{height:36px;min-width:130px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:inherit;color:#1e293b;background:#fff;outline:none;}
.inv-filter-bar select:focus{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.inv-filter-bar select:hover{border-color:#94a3b8;}
#cdd-sort{display:none!important;}

.fd-select-source{display:none!important;}
.fd-select{position:relative;display:inline-block;min-width:130px;}
.fd-select-trigger{display:flex;align-items:center;gap:8px;width:100%;height:36px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#1e293b;font-size:13px;font-family:inherit;cursor:pointer;box-sizing:border-box;white-space:nowrap;}
.fd-select-trigger:hover{border-color:#94a3b8;}
.fd-select.fd-open .fd-select-trigger{border-color:#1967d2;box-shadow:0 0 0 2px rgba(25,103,210,.2);}
.fd-select-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;text-align:left;}
.fd-select-arrow{font-size:10px;color:#475569;margin-left:auto;transition:transform .15s;flex-shrink:0;}
.fd-select.fd-open .fd-select-arrow{transform:rotate(180deg);}
.fd-select-menu{display:none;position:absolute;top:calc(100% + 2px);left:0;min-width:100%;max-height:260px;overflow-y:auto;background:#fff;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:10000;padding:4px 0;}
.fd-select.fd-open .fd-select-menu{display:block;}
.fd-select-option{padding:7px 14px;font-size:14px;color:#1e293b;cursor:pointer;white-space:nowrap;background:#fff;font-weight:400;line-height:1.4;}
.fd-select-option:hover,.fd-select-option.fd-active{background:#1967d2;color:#fff;font-weight:400;}

/* ── Custom Dropdown (always opens downward) ── */
.cdd-wrap{position:relative;display:inline-block;}
.cdd-trigger{display:flex;align-items:center;gap:8px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;color:#1e293b;background:#fff;height:38px;cursor:pointer;user-select:none;min-width:130px;white-space:nowrap;}
.cdd-trigger:hover{border-color:#94a3b8;}
.cdd-wrap.cdd-open .cdd-trigger{border-color:#1967d2;box-shadow:0 0 0 2px rgba(25,103,210,.2);}
.cdd-arrow{font-size:10px;color:#475569;margin-left:auto;}
.cdd-menu{display:none;position:absolute;top:calc(100% + 2px);left:0;min-width:100%;background:#fff;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:9999;max-height:260px;overflow-y:auto;overflow-x:hidden;padding:4px 0;}
.cdd-menu-right{left:auto;right:0;}
.cdd-wrap.cdd-open .cdd-menu{display:block;}
.cdd-item{padding:7px 14px;font-size:14px;color:#1e293b;cursor:pointer;white-space:nowrap;background:#fff;font-weight:400;line-height:1.4;}
.cdd-item:hover,.cdd-item.cdd-active{background:#1967d2;color:#fff;font-weight:400;}
.cdd-menu::-webkit-scrollbar,.fd-select-menu::-webkit-scrollbar{width:6px;}
.cdd-menu::-webkit-scrollbar-track,.fd-select-menu::-webkit-scrollbar-track{background:#f8fafc;}
.cdd-menu::-webkit-scrollbar-thumb,.fd-select-menu::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px;}
.cdd-menu::-webkit-scrollbar-thumb:hover,.fd-select-menu::-webkit-scrollbar-thumb:hover{background:#94a3b8;}

/* ── Main card ── */
.inv-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;overflow:visible;}
.inv-card-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e9ecef;flex-wrap:wrap;gap:8px;}
.inv-card-title{font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;}
.inv-card-body{padding:12px 14px;}
/* Ensure dropdowns open downward */
.inv-filter-bar select { position: relative; }
.stock-page { min-height: 100vh; }
.page { min-height: 200vh; }
.cat-header td{font-weight:700;background:#e9ecef!important;color:#495057!important;text-transform:uppercase;font-size:.8em;letter-spacing:.5px;border-bottom:2px solid #dee2e6;padding:8px 12px;text-align:center;}

/* ── Fill bar ── */
.fill-bar-wrap{background:#e9ecef;border-radius:3px;height:5px;overflow:hidden;margin-bottom:2px;width:100%;}
.fill-bar-inner{height:100%;border-radius:3px;}

/* ── Status badge ── */
.status-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;white-space:nowrap;}

/* ── Last Movement ── */
.mv-pos{color:#16a34a;font-weight:700;}
.mv-neg{color:#dc2626;font-weight:700;}
.mv-none{color:#94a3b8;}

/* ── Table Styling - Aligned with Manager & Admin ── */
.table-wrap {
    overflow-x: hidden !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
#merchTable,
table.merch-tbl,
.cust-table {
    table-layout: fixed !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    border-collapse: collapse !important;
    box-sizing: border-box !important;
}
#merchTable th,
#merchTable td,
table.merch-tbl th,
table.merch-tbl td {
    overflow: hidden !important;
    max-width: 0 !important;
    box-sizing: border-box !important;
    vertical-align: middle !important;
}
#merchTable thead th,
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
#merchTable tbody td,
table.merch-tbl tbody td {
    padding: 9px 8px !important;
    font-size: 13px !important;
    line-height: 1.35 !important;
    color: #0f172a !important;
    border-bottom: 1px solid #f1f5f9 !important;
}
#merchTable tbody tr:hover td,
table.merch-tbl tbody tr:hover td {
    background: #f8faff !important;
}
#merchTable td code {
    font-family: inherit !important;
}
#merchTable td:nth-child(2),
table.merch-tbl td:nth-child(2),
#merchTable td:nth-child(4),
table.merch-tbl td:nth-child(4) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    max-width: 0 !important;
}
#merchTable tr.cat-header td,
.cat-header td {
    background: #f1f5f9 !important;
    font-weight: 800 !important;
    font-size: 13px !important;
    padding: 8px 12px !important;
    color: #002F70 !important;
    text-align: left !important;
    border-bottom: 1px solid #e2e8f0 !important;
}
.inv-stock-badge {
    display: inline-block !important;
    padding: 4px 9px !important;
    border-radius: 6px !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
}
.act-btn-wrap {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
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
    padding: 3px 8px !important;
    border-radius: 6px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    width: 100% !important;
    max-width: 80px !important;
    height: 27px !important;
    margin-bottom: 0 !important;
    transition: all .18s ease-in-out !important;
    background: #ffffff !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
}
.act-btn:last-child { margin-bottom: 0 !important; }
.act-btn-view {
    color: #002F70 !important;
    border: 1.5px solid #002F70 !important;
    background: #ffffff !important;
}
.act-btn-view:hover {
    background: #002F70 !important;
    color: #ffffff !important;
}
.act-btn-adjust,
.act-btn-edit {
    color: #16a34a !important;
    border: 1.5px solid #16a34a !important;
    background: #ffffff !important;
}
.act-btn-adjust:hover,
.act-btn-edit:hover {
    background: #16a34a !important;
    color: #ffffff !important;
}
.cust-table thead th{background:#002F70!important;color:#fff!important;padding:12px 14px!important;font-size:13.5px!important;font-weight:800!important;text-transform:uppercase!important;letter-spacing:.3px!important;box-sizing:border-box!important;}
.cust-table tbody td{padding:12px 14px!important;font-size:14px!important;box-sizing:border-box!important;vertical-align:middle!important;}
@media(max-width:768px){
  .table-wrap{overflow-x:auto!important;-webkit-overflow-scrolling:touch;}
  .inv-card-body{padding:8px;}
  #merchTable thead th, #merchTable tbody td{font-size:11px!important;padding:6px 3px!important;}
}
.mi-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:13000;align-items:center;justify-content:center;padding:24px 16px;overflow-y:auto;-webkit-overflow-scrolling:touch;}
.mi-overlay.open{display:flex !important;}
.mi-box{background:#fff;border-radius:14px;padding:0;width:720px;max-width:calc(100vw - 32px);display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.3);animation:miIn .2s ease;overflow:hidden;position:relative;max-height:calc(100vh - 150px) !important;}
.mi-box.wide{width:880px;max-width:calc(100vw - 32px);}
@keyframes miIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
.mi-head{display:flex;justify-content:space-between;align-items:center;padding:16px 24px;border-bottom:1.5px solid #e9ecef;flex-shrink:0;background:#fff;position:relative;z-index:1;}
.mi-title{font-size:1.15rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;}
.mi-close{background:none;border:none;font-size:24px;cursor:pointer;color:#94a3b8;padding:0 4px;line-height:1;transition:color .15s;}
.mi-close:hover{color:#0f172a;}
.mi-body{padding:20px 24px;overflow-y:auto;overflow-x:hidden !important;flex:1;position:relative;min-height:0;box-sizing:border-box;}
.mi-foot{display:flex;gap:10px;justify-content:flex-end;align-items:center;padding:14px 24px;border-top:1.5px solid #e9ecef;flex-shrink:0;background:#fff;position:relative;z-index:2;pointer-events:auto;}
.mi-foot button{pointer-events:auto;cursor:pointer;}
.mi-info{background:#e8f4fd;border-left:4px solid #002F70;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#002F70;line-height:1.6;}

/* ── Stock Request Header Button ── */
.sr-header-btn {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 8px 18px !important;
    border-radius: 6px !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    cursor: pointer !important;
    background: #ffffff !important;
    color: #002F70 !important;
    -webkit-text-fill-color: #002F70 !important;
    border: 1.5px solid #002F70 !important;
    transition: all 0.2s ease-in-out !important;
    white-space: nowrap !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
}
.sr-header-btn i {
    color: #002F70 !important;
    font-size: 13px !important;
    transition: color 0.2s ease-in-out !important;
}
.sr-header-btn:hover {
    background: #002F70 !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border-color: #002F70 !important;
    box-shadow: 0 3px 8px rgba(0, 47, 112, 0.25) !important;
}
.sr-header-btn:hover i {
    color: #ffffff !important;
}

/* ── Stock Request Product List Wrapper ── */
.sr-products-wrapper{
  overflow-y:auto;
  overflow-x:hidden;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  max-height:260px;
  border:1px solid #cbd5e1;
  border-radius:8px;
  background:#f8fafc;
}
/* tbody itself should NOT have overflow/height */
#srProductsList{display:table-row-group;}

/* ── Modal mobile adjustments ── */
@media(max-height:600px){
  .mi-overlay{padding:20px 10px;}
  .mi-box{margin-top:10px;margin-bottom:20px;}
  .mi-head{padding:14px 20px;}
  .mi-body{padding:20px;}
  .mi-foot{padding:12px 20px;}
  .sr-products-wrapper{max-height:150px;}
}
@media(max-width:500px){
  .mi-box,.mi-box.wide{width:100%;max-width:calc(100vw - 20px);}
  .mi-head{padding:14px 16px;}
  .mi-body{padding:16px;}
  .mi-foot{padding:12px 16px;flex-wrap:wrap;}
  .mi-foot .txn-btn{flex:1;min-width:120px;}
  .sr-products-wrapper{max-height:180px;}
}

/* ── View Details modal ── */
.vd-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 20px;margin-bottom:14px;}
.vd-row{display:flex;flex-direction:column;gap:2px;}
.vd-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;color:#64748b;}
.vd-val{font-size:14px;font-weight:600;color:#1e293b;}

/* ── Stock Request modal inputs ── */
.sr-field{margin-bottom:14px;}
.sr-field label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;}
.sr-field select,.sr-field input,.sr-field textarea{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;color:#374151;box-sizing:border-box;outline:none;}
.sr-field select:focus,.sr-field input:focus,.sr-field textarea:focus{border-color:#002F70;box-shadow:0 0 0 2px rgba(0,47,112,.1);}
.sr-field textarea{resize:vertical;min-height:70px;}

/* ── Stock Request checkbox custom styling ── */
.sr-cb{width:17px;height:17px;accent-color:#002F70;cursor:pointer;flex-shrink:0;}


/* ── Success popup ── */
.sr-success-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:14000;}
.sr-success-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:14001;background:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);text-align:center;min-width:300px;}

/* ── Success toast (top-right) ── */
@keyframes slideInRight{from{opacity:0;transform:translateX(60px);}to{opacity:1;transform:translateX(0);}}
#srGlobalSuccessBanner{
    position:fixed;
    top:72px;
    right:24px;
    z-index:19000;
    max-width:360px;
    min-width:280px;
    background:#ffffff;
    color:#1e293b;
    padding:14px 16px;
    border-radius:10px;
    box-shadow:0 8px 28px rgba(0,0,0,0.14),0 2px 8px rgba(0,0,0,0.08);
    display:none;
    flex-direction:column;
    gap:5px;
    animation:slideInRight 0.3s cubic-bezier(.22,.68,0,1.2);
    transition:opacity 0.25s ease,transform 0.25s ease;
}

/* ── txn-btn override ── */
.txn-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:6px!important;padding:7px 14px!important;border-radius:4px!important;font-size:11px!important;font-weight:600!important;cursor:pointer!important;border:1px solid transparent!important;transition:all .2s!important;text-decoration:none!important;white-space:nowrap!important;background:#fff!important;}
.txn-btn.primary{color:#00264D!important;border-color:#00264D!important;}
.txn-btn.primary:hover{background:#00264D!important;color:#fff!important;}
.txn-btn.secondary{color:#475569!important;border-color:#475569!important;}
.txn-btn.secondary:hover{background:#475569!important;color:#fff!important;}
.txn-btn.success{color:#16a34a!important;border-color:#16a34a!important;}
.txn-btn.success:hover{background:#16a34a!important;color:#fff!important;}
.txn-btn.info{color:#002F70!important;border-color:#002F70!important;}
.txn-btn.info:hover{background:#002F70!important;color:#fff!important;}
.txn-btn.muted{color:#475569!important;border-color:#cbd5e1!important;background:#fff!important;}
.txn-btn.muted:hover{background:#f1f5f9!important;border-color:#94a3b8!important;color:#1e293b!important;}
.txn-btn.sm{padding:4px 9px!important;font-size:10px!important;}
body.modal-open {
  overflow: hidden !important;
}
body.modal-open .main {
  overflow-y: hidden !important;
}

/* ── Filter reset button ── */
.flt-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:36px;padding:0 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .2s;background:#fff !important;}
.flt-btn-reset{color:#475569 !important;border-color:#cbd5e1 !important;background:#fff !important;}
.flt-btn-reset:hover{background:#f1f5f9 !important;border-color:#94a3b8 !important;}

/* ── Outline button (for Select All / Clear) ── */
.int-btn-outline{display:inline-flex !important;align-items:center !important;justify-content:center !important;border:1px solid #00264D !important;background:#fff !important;color:#00264D !important;border-radius:5px !important;cursor:pointer !important;font-size:11px !important;font-weight:600 !important;padding:0 8px !important;height:28px !important;transition:all .15s !important;}
.int-btn-outline:hover{background:#00264D !important;color:#fff !important;border-color:#00264D !important;}

/* ── SR Modal 2-col grid and wide box ── */
.mi-box.wide{width:900px;max-width:calc(100vw - 32px);}
.sr-grid{display:grid;grid-template-columns:240px 1fr;gap:20px;}
.sr-grid > div{min-width:0;}
@media(max-width:680px){.sr-grid{grid-template-columns:1fr;}.mi-box.wide{width:100%;}}

/* ── Stock Request Table Styles ── */
.sr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    table-layout: fixed;
}
.sr-table th, .sr-table td {
    padding: 7px 5px;
    vertical-align: middle;
    box-sizing: border-box;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
/* Status cell — let badge display fully */
.sr-table td.sr-td-status {
    overflow: visible;
    text-align: center;
}
.sr-tbl-row {
    border-bottom: 1px solid #e2e8f0;
    cursor: pointer;
    transition: background 0.15s ease;
}
.sr-tbl-row:hover {
    background: #f1f5f9;
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-boxes"></i> Merchandise Inventory</h1>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>


<!-- ══ STOCK REQUEST SUCCESS TOAST (top-right) ══ -->
<div id="srGlobalSuccessBanner">
    <div style="display:flex; align-items:center; gap:10px;">
        <div style="width:34px; height:34px; border-radius:50%; background:#dcfce7; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
            <i class="fas fa-check-circle" style="font-size:17px; color:#16a34a;"></i>
        </div>
        <div style="font-size:13px; font-weight:800; color:#15803d; letter-spacing:0.2px; line-height:1.3;" id="srBannerTitle">STOCK REQUEST SUBMITTED!</div>
    </div>
    <div style="font-size:12px; color:#475569; padding-left:44px; line-height:1.5;" id="srBannerText">Your stock request is now pending manager approval.</div>
</div>

<!-- ══ SUMMARY CARDS (5 CARDS) ══ -->
<div class="inv-stats-row" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
    <div class="inv-stat-card" id="card-total" onclick="filterByCard('', this)">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Total Products</span>
            <span class="inv-stat-val"><?php echo $stats['total']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#2563eb;"><i class="fas fa-box"></i></div>
    </div>
    <div class="inv-stat-card" id="card-available" data-filter="ok" onclick="filterByCard('available', this)" title="Click to filter available products">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Available Products</span>
            <span class="inv-stat-val"><?php echo $stats['available']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#28a745;"><i class="fas fa-check-circle"></i></div>
    </div>
    <div class="inv-stat-card" id="card-low" data-filter="low" onclick="filterByCard('low', this)" title="Click to filter low stock items">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Low Stock</span>
            <span class="inv-stat-val"><?php echo $stats['low']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#fd7e14;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <div class="inv-stat-card" id="card-out" data-filter="out" onclick="filterByCard('out', this)" title="Click to filter out of stock items">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Out of Stock</span>
            <span class="inv-stat-val"><?php echo $stats['out']; ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#dc2626;"><i class="fas fa-times-circle"></i></div>
    </div>
    <div class="inv-stat-card" id="card-expired" data-filter="expired" onclick="filterByCard('expired', this)" title="Click to filter expired items">
        <div class="inv-stat-info">
            <span class="inv-stat-label">Expired Stock</span>
            <span class="inv-stat-val"><?php echo ($stats['expired'] ?? 0); ?></span>
        </div>
        <div class="inv-stat-icon" style="color:#dc2626;"><i class="fas fa-ban"></i></div>
    </div>
</div>

<!-- ══ TABS NAVIGATION ══ -->
<div class="inv-tab-nav">
    <button type="button" class="inv-tab-btn active" id="tab-overview" onclick="switchInvTab('overview')">
        <i class="fas fa-list"></i> Inventory Overview
    </button>
    <button type="button" class="inv-tab-btn" id="tab-stockin" onclick="switchInvTab('stockin')">
        <i class="fas fa-arrow-down"></i> Stock In (Read Only)
    </button>
    <button type="button" class="inv-tab-btn" id="tab-alerts" onclick="switchInvTab('alerts')">
        <i class="fas fa-bell"></i> Stock Alerts
        <span style="background:#dc2626 !important; color:#ffffff !important; font-size:11px; padding:2px 7px; border-radius:12px; margin-left:6px; font-weight:700; line-height:1;"><?php echo ($stats['low'] + $stats['out'] + ($stats['expired'] ?? 0)); ?></span>
    </button>
</div>

<!-- ══ TAB 1: INVENTORY OVERVIEW ══ -->
<div class="inv-card" id="section-overview">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-box"></i> Merchandise Stock Overview</div>
        <button type="button" onclick="openSrModal()" class="sr-header-btn">
            <i class="fas fa-paper-plane"></i> Stock Request
        </button>
    </div>


    <div class="inv-card-body">

        <!-- Filter Bar -->
        <div class="inv-filter-bar" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
            <div style="position:relative;">
                <i class="fas fa-search" style="position:absolute; left:10px; top:11px; color:#94a3b8; font-size:12px;"></i>
                <input type="text" id="merchSearch" placeholder="Search Product / SKU..." autocomplete="off" oninput="applyFilters()" onkeydown="if(event.key==='Enter'){ applyFilters(); }" style="padding-left:28px; width:240px;">
            </div>
            <select id="filterCategory" onchange="applyFilters()">
                <option value="">All Categories</option>
                <?php foreach ($all_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars(strtolower($cat)); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterBrand" onchange="applyFilters()" style="display:none;">
                <option value="">All Brands</option>
                <?php foreach ($all_brands as $b): ?>
                <option value="<?php echo htmlspecialchars(strtolower($b)); ?>"><?php echo htmlspecialchars($b); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterUnit" onchange="applyFilters()" style="display:none;">
                <option value="">All Units</option>
                <?php foreach ($all_units as $u): ?>
                <option value="<?php echo htmlspecialchars(strtolower($u)); ?>"><?php echo htmlspecialchars($u); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filterStatus" onchange="applyFilters()">
                <option value="">All Statuses</option>
                <option value="available">Available</option>
                <option value="low">Low Stock</option>
                <option value="out">Out of Stock</option>
                <option value="expired">Expired</option>
            </select>
            <select id="sortBy" onchange="applyFilters()">
                <option value="default">Default Sort</option>
                <option value="newest">Newest Updated</option>
                <option value="name_asc">Name A–Z</option>
                <option value="name_desc">Name Z–A</option>
                <option value="stock_asc">Stock Low–High</option>
                <option value="stock_desc">Stock High–Low</option>
            </select>
            <button type="button" class="flt-btn" onclick="applyFilters()" style="border:1px solid #002F70; color:#002F70; background:#fff; font-weight:600; padding:6px 14px; border-radius:6px; cursor:pointer;">
                <i class="fas fa-filter"></i> Filter
            </button>
            <button type="button" class="flt-btn flt-btn-reset" onclick="resetFilters()" title="Reset All Filters" style="border:1px solid #64748b; color:#64748b; background:#fff; font-weight:600; padding:6px 14px; border-radius:6px; cursor:pointer;">
                <i class="fas fa-undo"></i> Reset
            </button>
        </div>


        <!-- Table -->
        <div class="table-wrap" style="width:100% !important;max-width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;">
            <table class="table report-table no-min-width print-table merch-tbl afto-tbl" id="merchTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
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
                <tbody id="merchTableBody">
                <?php if (empty($js_items)): ?>
                    <tr>
                        <td colspan="7" class="align-center" style="padding:24px; color:#64748b; text-align:center;">
                            <i class="fas fa-box-open" style="font-size:24px; margin-bottom:8px; display:block;"></i>
                            No merchandise inventory records matched your filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    // Group by category from $js_items (already filtered to active only)
                    $grouped = [];
                    foreach ($js_items as $it) { $grouped[$it['category']][] = $it; }
                    ksort($grouped);
                    foreach ($grouped as $cat_label => $items):
                    ?>
                    <tr class="cat-header no-paginate">
                        <td colspan="7" style="background:#f1f5f9;font-weight:800;font-size:13px;padding:8px 12px;color:#002F70;text-align:left;">
                            <i class="fas fa-folder" style="color:#2563eb;margin-right:6px;"></i><?= htmlspecialchars($cat_label) ?>
                        </td>
                    </tr>
                    <?php foreach ($items as $it):
                        $timestamp_date = '—';
                        $timestamp_time = '';
                        if (!empty($it['last_updated'])) {
                            try {
                                $dt_up = new DateTime($it['last_updated']);
                                $timestamp_date = $dt_up->format('M d, Y');
                                $timestamp_time = $dt_up->format('h:i A');
                            } catch (Exception $e) {}
                        }
                        $exp_date   = $it['expiration_date'] ?? 'N/A';
                        $exp_raw    = $it['exp_raw'] ?? null;
                        $exp_status = $it['exp_status'] ?? '';
                        $has_variance = ($it['variance'] !== null && (float)$it['variance'] != 0);

                        if ($exp_status === 'expired') {
                            $display_status = 'EXPIRED';
                            $display_color = '#dc3545';
                        } elseif ($has_variance) {
                            $display_status = 'VARIANCE DETECTED';
                            $display_color = '#fd7e14';
                        } else {
                            $display_status = $it['status'];
                            $display_color = $it['color'];
                        }
                        $batch_id = !empty($it['batches'][0]['batch_id']) ? $it['batches'][0]['batch_id'] : ('B' . str_pad((string)$it['id'], 3, '0', STR_PAD_LEFT));
                        $initial_qty = (int)($it['stock_in'] ?? 0) > 0 ? (int)$it['stock_in'] : (int)($it['capacity'] ?? 480);
                        $stock = (float)$it['stock'];
                        $reorder = (float)$it['reorder'];
                        $unit = htmlspecialchars($it['unit'] ?? 'pcs');
                        $fill_pct = min(100, round($it['fill_pct'] ?? 0));
                    ?>
                    <tr class="merch-row"
                        data-id="<?php echo (int)$it['id']; ?>"
                        data-name="<?php echo strtolower(htmlspecialchars($it['name'])); ?>"
                        data-sku="<?php echo strtolower(htmlspecialchars($it['sku'] ?: '')); ?>"
                        data-category="<?php echo strtolower(htmlspecialchars($it['category'])); ?>"
                        data-brand="<?php echo strtolower(htmlspecialchars($it['brand'])); ?>"
                        data-unit="<?php echo strtolower(htmlspecialchars($it['unit'])); ?>"
                        data-status="<?php echo $exp_status === 'expired' ? 'expired' : htmlspecialchars($it['status_key']); ?>"
                        data-filter-status="<?php echo $exp_status === 'expired' ? 'expired' : ($has_variance ? 'variance detected' : htmlspecialchars($it['status_key'])); ?>"
                        data-stock="<?php echo $it['stock']; ?>"
                        data-updated="<?php echo htmlspecialchars($it['last_updated']); ?>"
                        data-idx="<?php echo htmlspecialchars(json_encode(array_merge($it, ['exp_status' => $exp_status, 'exp_date' => $exp_date]))); ?>">

                        <!-- 1. ITEM IDENTIFIERS -->
                        <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
                            <div style="font-family:monospace;font-size:12.5px;font-weight:700;color:#002F70;white-space:nowrap;" title="Batch ID">
                                <i class="fas fa-layer-group" style="font-size:10.5px;color:#2563eb;margin-right:2px;"></i> <?= htmlspecialchars($batch_id) ?>
                            </div>
                            <div style="font-family:monospace;font-size:11.5px;font-weight:700;color:#4f46e5;margin-top:2px;white-space:nowrap;" title="SKU">
                                <?= htmlspecialchars($it['sku'] ?: '—') ?>
                            </div>
                        </td>

                        <!-- 2. PRODUCT & CATEGORY -->
                        <td style="padding:9px 8px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;">
                            <div style="font-weight:800;font-size:13.5px;color:#0f172a;line-height:1.3;word-break:break-word;overflow-wrap:break-word;"><?= htmlspecialchars($it['name']) ?></div>
                            <div style="font-size:11.5px;color:#64748b;margin-top:3px;font-weight:600;display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
                                <span style="color:#0369a1;"><i class="fas fa-tag" style="font-size:10px;"></i> <?= htmlspecialchars($it['category'] ?? 'General') ?></span>
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
                                <div class="fill-bar-inner" style="width:<?= $fill_pct ?>%;background:<?= $display_color ?>;height:100%;"></div>
                            </div>
                            <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:4px;">
                                <span style="font-size:13px;font-weight:800;color:#0f172a;white-space:nowrap;"><?= number_format($stock, 0) ?> <small style="font-size:11px;color:#64748b;font-weight:600;"><?= $unit ?></small></span>
                                <span style="font-size:11px;color:#64748b;white-space:nowrap;font-weight:600;">Reorder: <strong style="color:#ea580c;"><?= number_format($reorder, 0) ?></strong></span>
                            </div>
                            <div style="font-size:10.5px;color:#64748b;margin-top:2px;white-space:nowrap;">Init: <?= number_format($initial_qty) ?></div>
                        </td>

                        <!-- 5. STATUS -->
                        <td style="padding:9px 6px;max-width:0;overflow:hidden;box-sizing:border-box;vertical-align:middle;text-align:center;">
                            <?php if ($exp_status === 'expired'): ?>
                                <span class="inv-stock-badge" style="background:#dc354520;color:#dc3545;border:1.5px solid #dc354560;padding:4px 9px;border-radius:6px;font-size:11px;font-weight:800;text-transform:uppercase;white-space:nowrap;display:inline-block;">
                                    <i class="fas fa-ban" style="font-size:9.5px;margin-right:2px;"></i> EXPIRED
                                </span>
                            <?php else: ?>
                                <span class="inv-stock-badge" style="background:<?= $display_color ?>20;color:<?= $display_color ?>;border:1.5px solid <?= $display_color ?>50;padding:4px 9px;border-radius:6px;font-size:11px;font-weight:800;text-transform:uppercase;white-space:nowrap;display:inline-block;">
                                    <?= htmlspecialchars($display_status) ?>
                                </span>
                            <?php endif; ?>
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
                        <td style="padding:8px 6px;max-width:0;overflow:hidden;box-sizing:border-box;text-align:center;vertical-align:middle;">
                            <div class="act-btn-wrap">
                                <button type="button" class="act-btn act-btn-view" onclick='viewDetails(<?= htmlspecialchars(json_encode($it), ENT_QUOTES) ?>)' title="View Details">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button type="button" class="act-btn act-btn-adjust" onclick='openAdjustmentModal(<?= htmlspecialchars(json_encode($it), ENT_QUOTES) ?>)' title="Adjust Stock">
                                    <i class="fas fa-edit"></i> Adjust
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
        <div id="merchPagination"></div>
    </div>
</div>

<!-- ══ TAB 2: STOCK ALERTS ══ -->
<div class="inv-card" id="section-alerts" style="display:none;">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-bell"></i> Stock Alerts</div>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="cust-table" style="width:100%;table-layout:fixed;">
                <colgroup>
                    <col style="width: 32%;">
                    <col style="width: 14%;">
                    <col style="width: 14%;">
                    <col style="width: 16%;">
                    <col style="width: 24%;">
                </colgroup>
                <thead>
                    <tr style="background:#002F70; color:#fff;">
                        <th style="padding:12px 14px;">Product</th>
                        <th style="padding:12px 14px; text-align:center;">Current Stock</th>
                        <th style="padding:12px 14px; text-align:center;">Reorder Level</th>
                        <th style="padding:12px 14px; text-align:center;">Status</th>
                        <th style="padding:12px 14px;">Recommended Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $alert_items = array_filter($js_items, function($it) {
                    return in_array($it['status_key'], ['low', 'critical', 'out', 'expired'], true) || ($it['exp_status'] ?? '') === 'expired';
                });
                if (empty($alert_items)):
                ?>
                    <tr><td colspan="5" style="text-align:center; padding:36px; font-size:15px; color:#64748b;"><i class="fas fa-check-circle" style="color:#16a34a; font-size:2.2em; display:block; margin-bottom:10px;"></i> <strong>No stock alerts found.</strong> All items are currently at optimal stock levels.</td></tr>
                <?php else: ?>
                    <?php foreach ($alert_items as $ait):
                        $is_exp = (($ait['exp_status'] ?? '') === 'expired' || $ait['status_key'] === 'expired');
                        if ($is_exp) {
                            $ait_status = 'Expired';
                            $ait_color  = '#dc3545';
                            $ait_icon   = 'fa-ban';
                            $ait_rec    = 'Do Not Sell / Dispose & Adjust Stock';
                            $ait_rec_ic = 'fa-trash-alt';
                            $ait_rec_col = '#dc3545';
                        } elseif ($ait['status_key'] === 'out') {
                            $ait_status = 'Out of Stock';
                            $ait_color  = '#dc2626';
                            $ait_icon   = 'fa-times-circle';
                            $ait_rec    = 'Immediate Restock / Stock Request';
                            $ait_rec_ic = 'fa-paper-plane';
                            $ait_rec_col = '#dc2626';
                        } elseif ($ait['status_key'] === 'critical') {
                            $ait_status = 'Critical Low';
                            $ait_color  = '#dc2626';
                            $ait_icon   = 'fa-exclamation-circle';
                            $ait_rec    = 'Urgent Restock Needed';
                            $ait_rec_ic = 'fa-exclamation-triangle';
                            $ait_rec_col = '#dc2626';
                        } else {
                            $ait_status = 'Low Stock';
                            $ait_color  = '#fd7e14';
                            $ait_icon   = 'fa-exclamation-triangle';
                            $ait_rec    = 'Create Stock Request';
                            $ait_rec_ic = 'fa-file-alt';
                            $ait_rec_col = '#ea580c';
                        }
                    ?>
                    <tr style="border-bottom:1px solid #e2e8f0;<?= $is_exp ? ' background:#fff5f5;' : '' ?>">
                        <td style="padding:12px 14px;">
                            <div style="font-size:15px; font-weight:800; color:#0f172a; line-height:1.35;"><?php echo htmlspecialchars($ait['name']); ?></div>
                            <div style="font-size:13px; font-weight:600; color:#475569; margin-top:4px;">
                                <span style="color:#002F70; font-weight:700;">SKU:</span> <?php echo htmlspecialchars($ait['sku']); ?> &middot; 
                                <span style="color:#002F70; font-weight:700;">Category:</span> <?php echo htmlspecialchars($ait['category']); ?>
                                <?php if (!empty($ait['expiration_date']) && $ait['expiration_date'] !== 'N/A'): ?>
                                    &middot; <span style="color:<?= $is_exp ? '#dc3545' : '#475569' ?>; font-weight:700;"><i class="fas fa-calendar-alt"></i> Exp: <?= htmlspecialchars($ait['expiration_date']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="padding:12px 14px; text-align:center;">
                            <div style="font-size:16px; font-weight:800; color:<?= $is_exp ? '#dc3545' : '#0f172a' ?>;"><?php echo number_format($ait['stock']); ?> <span style="font-size:13px; font-weight:600; color:#475569;"><?php echo htmlspecialchars($ait['unit']); ?></span></div>
                        </td>
                        <td style="padding:12px 14px; text-align:center;">
                            <div style="font-size:16px; font-weight:800; color:#dc2626;"><?php echo number_format($ait['reorder']); ?> <span style="font-size:13px; font-weight:600; color:#475569;"><?php echo htmlspecialchars($ait['unit']); ?></span></div>
                        </td>
                        <td style="padding:12px 14px; text-align:center;">
                            <span class="status-badge" style="background:<?php echo $ait_color; ?>25; color:<?php echo $ait_color; ?>; border:1.5px solid <?php echo $ait_color; ?>60; font-size:13px; font-weight:800; padding:6px 14px; border-radius:8px; text-transform:uppercase; letter-spacing:0.3px; display:inline-flex; align-items:center; gap:6px;">
                                <i class="fas <?php echo $ait_icon; ?>"></i> <?php echo htmlspecialchars($ait_status); ?>
                            </span>
                        </td>
                        <td style="padding:12px 14px; font-size:13px; font-weight:700; color:<?php echo $ait_rec_col; ?>;">
                            <i class="fas <?php echo $ait_rec_ic; ?>" style="margin-right:6px;"></i><?php echo htmlspecialchars($ait_rec); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══ TAB: STOCK IN (READ ONLY) ══ -->
<div class="inv-card" id="section-stockin" style="display:none;">
    <div class="inv-card-head">
        <div class="inv-card-title" style="font-size:1.15rem;font-weight:800;color:#002F70;"><i class="fas fa-arrow-down" style="color:#16a34a;"></i> Approved Stock-In Records (Read Only)</div>
    </div>
    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="cust-table" style="width:100%;table-layout:fixed;">
                <colgroup>
                    <col style="width: 14%;">
                    <col style="width: 28%;">
                    <col style="width: 11%;">
                    <col style="width: 12%;">
                    <col style="width: 13%;">
                    <col style="width: 11%;">
                    <col style="width: 11%;">
                </colgroup>
                <thead>
                    <tr style="background:#002F70; color:#fff;">
                        <th style="padding:12px 14px;">Stock-In No.</th>
                        <th style="padding:12px 14px;">Product</th>
                        <th style="padding:12px 14px; text-align:center;">Qty Received</th>
                        <th style="padding:12px 14px; text-align:center;">Batch</th>
                        <th style="padding:12px 14px; text-align:center;">Expiration Date</th>
                        <th style="padding:12px 14px; text-align:center;">Date</th>
                        <th style="padding:12px 14px; text-align:center;">Received By</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($stock_in_list)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:36px; font-size:15px; color:#64748b;"><i class="fas fa-info-circle" style="font-size:2.2em; display:block; margin-bottom:10px;"></i> <strong>No approved stock-in records found.</strong></td></tr>
                <?php else: ?>
                    <?php foreach ($stock_in_list as $sin):
                        $sdate = !empty($sin['date_received']) ? (new DateTime($sin['date_received']))->format('M d, Y h:i A') : '—';
                        $exp_str = !empty($sin['expiration_date']) && $sin['expiration_date'] !== '0000-00-00' ? (new DateTime($sin['expiration_date']))->format('M d, Y') : 'N/A';
                    ?>
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <td style="padding:12px 14px;"><code style="font-size:13px; font-weight:800; color:#002F70;"><?php echo htmlspecialchars($sin['stock_in_no']); ?></code></td>
                        <td style="padding:12px 14px;"><strong style="font-size:14.5px; font-weight:800; color:#0f172a; line-height:1.35; display:block;"><?php echo htmlspecialchars($sin['product_name']); ?></strong></td>
                        <td style="padding:12px 14px; text-align:center; font-weight:800; color:#16a34a; font-size:15px;">+<?php echo number_format($sin['qty_received']); ?></td>
                        <td style="padding:12px 14px; text-align:center;"><span style="background:#f1f5f9; color:#334155; padding:4px 10px; border-radius:6px; font-size:12.5px; font-weight:700; border:1px solid #cbd5e1;"><?php echo htmlspecialchars($sin['batch_no']); ?></span></td>
                        <td style="padding:12px 14px; text-align:center; font-size:12.5px; font-weight:700; color:<?= $exp_str !== 'N/A' ? '#0f172a' : '#94a3b8' ?>;"><?php echo $exp_str; ?></td>
                        <td style="padding:12px 14px; text-align:center; font-size:12.5px; font-weight:600; color:#475569;"><?php echo $sdate; ?></td>
                        <td style="padding:12px 14px; text-align:center; font-size:13px; font-weight:700; color:#1e293b;"><?php echo htmlspecialchars($sin['received_by']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══ VIEW DETAILS MODAL ══ -->
<div class="mi-overlay" id="vdModal">
    <div class="mi-box">
        <div class="mi-head">
            <div class="mi-title"><i class="fas fa-eye"></i> Product Details</div>
        </div>
        <div class="mi-body">
            <div id="vdContent"></div>
        </div>
        <div class="mi-foot">
            <button type="button" class="txn-btn muted" onclick="closeVd()">Close</button>
        </div>
    </div>
</div>

<!-- ══ STOCK REQUEST MODAL ══ -->
<div class="mi-overlay" id="srModal">
    <div class="mi-box wide">
        <div class="mi-head">
            <div class="mi-title"><i class="fas fa-box"></i> Stock Request</div>
        </div>
        <div class="mi-body">

            <div class="sr-grid">
                <!-- Left Column: Request Info -->
                <div>
                    <div style="background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:12px;">
                        <h4 style="margin:0 0 10px; font-size:12px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:5px;"><i class="fas fa-file-alt"></i> Request Info</h4>
                        <div style="margin-bottom:7px; font-size:11.5px;">
                            <div style="color:#64748b; font-weight:600; margin-bottom:2px;">Request No:</div>
                            <div style="font-weight:700; color:#1e293b;">Auto-Assigned</div>
                        </div>
                        <div style="margin-bottom:7px; font-size:11.5px;">
                            <div style="color:#64748b; font-weight:600; margin-bottom:2px;">Request Date:</div>
                            <div style="font-weight:700; color:#1e293b;"><?= date('M d, Y') ?></div>
                        </div>
                        <div style="font-size:11.5px;">
                            <div style="color:#64748b; font-weight:600; margin-bottom:2px;">Requested By:</div>
                            <div style="font-weight:700; color:#1e293b;"><?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?></div>
                        </div>
                    </div>
                    <div class="sr-field">
                        <label for="srReason" style="font-size:11.5px;"><i class="fas fa-comment-alt"></i> Remarks / Reason</label>
                        <textarea id="srReason" style="min-height:90px; font-size:12px;" placeholder="e.g. Running low, expected high demand this weekend..."></textarea>
                    </div>
                </div>
                
                <!-- Right Column: Products Checklist Table -->
                <div style="display:flex; flex-direction:column; max-height:360px;">
                    <label style="display:block;font-size:12.5px;font-weight:700;color:#374151;margin-bottom:8px;">
                        <i class="fas fa-exclamation-triangle" style="color:#eab308;margin-right:4px;"></i> Products Needing Replenishment <span style="color:#dc2626;">*</span>
                    </label>
                    <div style="display:flex; gap:8px; margin-bottom:8px;">
                        <button type="button" class="int-btn-outline" onclick="srSelectAll()">Select All</button>
                        <button type="button" class="int-btn-outline" onclick="srClearSelection()">Clear Selection</button>
                    </div>
                    <div class="sr-products-wrapper">
                        <table class="sr-table">
                            <thead>
                                <tr style="background:#002F70; color:#fff; position:sticky; top:0; z-index:10;">
                                    <th style="width:6%; text-align:center;">Select</th>
                                    <th style="width:12%; text-align:left;">Product ID</th>
                                    <th style="width:15%; text-align:left;">Product Code</th>
                                    <th style="width:25%; text-align:left;">Product Name</th>
                                    <th style="width:13%; text-align:center;">Current Stock</th>
                                    <th style="width:13%; text-align:center;">Reorder Level</th>
                                    <th style="width:16%; text-align:center;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="srProductsList">
                                <!-- Populated via JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div id="srError" style="display:none;background:#fee2e2;color:#dc3545;padding:10px 14px;border-radius:6px;font-size:13px;margin-top:10px;margin-bottom:10px;"></div>
        </div>
        <div class="mi-foot">
            <button class="txn-btn secondary" id="srCancelBtn" onclick="closeSrModal()" type="button">Cancel</button>
            <button class="txn-btn primary" id="srSubmitBtn" onclick="srHandleSubmit(this)" type="button"><i class="fas fa-paper-plane"></i> Submit Stock Request</button>
        </div>
    </div>
</div>

<!-- ── Success popup ── -->
<div class="sr-success-overlay" id="srSuccessOverlay"></div>
<div class="sr-success-popup" id="srSuccessPopup">
    <div id="srPopupIcon" style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-check" id="srPopupIconI" style="color:#fff;font-size:22px;"></i>
    </div>
    <h3 id="srPopupTitle" style="margin:0 0 6px;color:#28a745;">REQUEST SUBMITTED!</h3>
    <p style="margin:0 0 6px;color:#333;font-size:13px;" id="srSuccessMsg">Your stock request is now <strong>Pending</strong> Manager review.</p>
    <p id="srPopupStatusRow" style="margin:0 0 18px;font-size:12px;color:#6c757d;">Status: <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-weight:700;">PENDING</span></p>
    <button onclick="closeSrSuccess()" class="txn-btn primary">OK</button>
</div>

<script>
var allMerchData = <?php echo json_encode(array_values($js_items), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var stockInListData = <?php echo json_encode(array_values($stock_in_list), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
var _srPreselect = null;

// ── Filter / Sort ─────────────────────────────────────────────
function applyFilters() {
    var q      = (document.getElementById('merchSearch')?.value || '').toLowerCase().trim();
    var cat    = (document.getElementById('filterCategory')?.value || '').toLowerCase().trim();
    var brand  = (document.getElementById('filterBrand')?.value || '').toLowerCase().trim();
    var unit   = (document.getElementById('filterUnit')?.value || '').toLowerCase().trim();
    var stat   = (document.getElementById('filterStatus')?.value || '').toLowerCase().trim();
    var sortBy = document.getElementById('sortBy')?.value || 'default';

    document.querySelectorAll('#merchTableBody .merch-row').forEach(function(r) {
        var name    = (r.dataset.name || '').toLowerCase();
        var sku     = (r.dataset.sku || '').toLowerCase();
        var rcat    = (r.dataset.category || '').toLowerCase();
        var rbrand  = (r.dataset.brand || '').toLowerCase();
        var runit   = (r.dataset.unit || '').toLowerCase();
        var rstat   = (r.dataset.status || '').toLowerCase();
        var rfilter = (r.dataset.filterStatus || rstat).toLowerCase();

        var matchC = !cat || rcat === cat;
        var matchB = !brand || rbrand === brand;
        var matchU = !unit || runit === unit;

        var matchS = true;
        if (stat) {
            if (stat === 'available' || stat === 'ok') {
                matchS = (rstat === 'ok' || rstat === 'available' || rfilter === 'available') && rfilter !== 'expired' && rstat !== 'expired';
            } else if (stat === 'low') {
                matchS = (rstat === 'low' || rstat === 'critical');
            } else if (stat === 'out' || stat === 'out of stock') {
                matchS = (rstat === 'out');
            } else if (stat === 'expired') {
                matchS = (rstat === 'expired' || rfilter === 'expired');
            } else if (stat === 'warning') {
                matchS = (rstat === 'low' || rstat === 'critical' || rstat === 'out' || rstat === 'expired' || rfilter === 'expired');
            } else {
                matchS = (rstat === stat || rfilter === stat);
            }
        }

        var matchQ = true;
        if (q) {
            matchQ = (name.indexOf(q) !== -1 || sku.indexOf(q) !== -1 || rcat.indexOf(q) !== -1 || rbrand.indexOf(q) !== -1 || runit.indexOf(q) !== -1 || rstat.indexOf(q) !== -1 || rfilter.indexOf(q) !== -1);
        }

        var visible = matchQ && matchC && matchB && matchU && matchS;
        if (visible) {
            r.classList.remove('search-hidden');
            r.style.display = '';
        } else {
            r.classList.add('search-hidden');
            r.style.display = 'none';
        }
    });

    // Handle Category headers & sorting
    var tbody = document.getElementById('merchTableBody');
    if (!tbody) return;

    if (sortBy === 'default') {
        var rows = Array.from(tbody.querySelectorAll('tr'));
        var currentHeader = null;
        var hasVisibleItems = false;

        rows.forEach(function(r) {
            if (r.classList.contains('cat-header')) {
                if (currentHeader) {
                    currentHeader.style.display = hasVisibleItems ? '' : 'none';
                    if (!hasVisibleItems) currentHeader.classList.add('search-hidden');
                    else currentHeader.classList.remove('search-hidden');
                }
                currentHeader = r;
                hasVisibleItems = false;
            } else if (r.classList.contains('merch-row')) {
                if (!r.classList.contains('search-hidden')) {
                    hasVisibleItems = true;
                }
            }
        });
        if (currentHeader) {
            currentHeader.style.display = hasVisibleItems ? '' : 'none';
            if (!hasVisibleItems) currentHeader.classList.add('search-hidden');
            else currentHeader.classList.remove('search-hidden');
        }
    } else {
        // Global sort mode — hide category headers
        Array.from(tbody.querySelectorAll('.cat-header')).forEach(function(h) {
            h.style.display = 'none';
            h.classList.add('search-hidden');
        });

        // Perform in-place sorting of merch-rows
        var merchRows = Array.from(tbody.querySelectorAll('.merch-row'));
        merchRows.sort(function(a, b) {
            if (sortBy === 'name_asc') {
                return (a.dataset.name || '').localeCompare(b.dataset.name || '');
            } else if (sortBy === 'name_desc') {
                return (b.dataset.name || '').localeCompare(a.dataset.name || '');
            } else if (sortBy === 'stock_asc') {
                return parseFloat(a.dataset.stock || 0) - parseFloat(b.dataset.stock || 0);
            } else if (sortBy === 'stock_desc') {
                return parseFloat(b.dataset.stock || 0) - parseFloat(a.dataset.stock || 0);
            } else if (sortBy === 'newest') {
                return (b.dataset.updated || '').localeCompare(a.dataset.updated || '');
            }
            return 0;
        });
        merchRows.forEach(function(r) { tbody.appendChild(r); });
    }

    // Trigger pagination refresh
    if (window.tablePaginationTriggers && window.tablePaginationTriggers['merchTable']) {
        window.tablePaginationTriggers['merchTable']();
    } else if (window.setTablePage) {
        window.setTablePage('merchTable', 1);
    }
}


function resetFilters() {
    document.getElementById('merchSearch').value = '';
    // Reset hidden selects
    document.getElementById('filterCategory').value = '';
    document.getElementById('filterBrand').value = '';
    document.getElementById('filterUnit').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('sortBy').value = 'default';
    // Reset custom dropdowns
    cddSet('cdd-category', '', 'All Categories');
    cddSet('cdd-brand', '', 'All Brands');
    cddSet('cdd-unit', '', 'All Units');
    cddSet('cdd-status', '', 'All Statuses');
    cddSet('cdd-sort', 'default', 'Default Sort');
    ['filterCategory','filterBrand','filterUnit','filterStatus','sortBy'].forEach(function(id) {
        var select = document.getElementById(id);
        if (select) select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    // Clear card highlights too
    document.querySelectorAll('.inv-stat-card').forEach(function(c){ c.classList.remove('card-active'); });
    // Trigger sort reset
    applyFilters();
}

// ── Card filter shortcut ───────────────────────────────────────
function filterByCard(statusKey, cardEl) {
    var select = document.getElementById('filterStatus');
    var isActive = cardEl.classList.contains('card-active');

    // Remove active state from all cards
    document.querySelectorAll('.inv-stat-card').forEach(function(c){
        c.classList.remove('card-active');
        c.style.borderColor = '';
    });

    if (isActive || statusKey === '') {
        select.value = '';
        cddSet('cdd-status', '', 'All Statuses');
    } else {
        cardEl.classList.add('card-active');
        if (statusKey === 'warning') {
            ['card-low','card-critical','card-out'].forEach(function(id) {
                var c = document.getElementById(id);
                if (c) c.classList.add('card-active');
            });
        }
        select.value = statusKey;
        cddSet('cdd-status', statusKey, statusKey === 'warning' ? 'Stock Alerts' : statusKey);
    }

    select.dispatchEvent(new Event('change', { bubbles: true }));
    applyFilters();

    var card = document.querySelector('.inv-card');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

['input','change'].forEach(function(ev) {
    document.getElementById('merchSearch').addEventListener(ev, applyFilters);
    document.getElementById('filterCategory').addEventListener(ev, applyFilters);
    document.getElementById('filterBrand').addEventListener(ev, applyFilters);
    document.getElementById('filterUnit').addEventListener(ev, applyFilters);
    document.getElementById('filterStatus').addEventListener(ev, applyFilters);
});

document.getElementById('sortBy').addEventListener('change', function() {
    var val = this.value;
    var tbody = document.getElementById('merchTableBody');
    var rows  = Array.from(tbody.querySelectorAll('.merch-row'));
    var headers = Array.from(tbody.querySelectorAll('.cat-header'));

    if (val === 'default') {
        // Show all category headers initially
        headers.forEach(function(h) { h.style.display = ''; });

        // Sort rows by category first, then by name
        rows.sort(function(a, b) {
            var catA = (a.dataset.category || '').toLowerCase();
            var catB = (b.dataset.category || '').toLowerCase();
            if (catA !== catB) {
                return catA.localeCompare(catB);
            }
            var nameA = (a.dataset.name || '').toLowerCase();
            var nameB = (b.dataset.name || '').toLowerCase();
            return nameA.localeCompare(nameB);
        });

        // Re-append in grouped category order
        headers.forEach(function(h) {
            tbody.appendChild(h);
            var hCat = h.textContent.trim().toLowerCase();
            rows.forEach(function(r) {
                var rCat = (r.dataset.category || '').toLowerCase();
                if (rCat === hCat) {
                    tbody.appendChild(r);
                }
            });
        });
    } else {
        // Hide all category headers because it's a global sort
        headers.forEach(function(h) { h.style.display = 'none'; });

        // Sort rows globally
        if (val === 'name_asc')    rows.sort(function(a,b){ return (a.dataset.name||'').localeCompare(b.dataset.name||''); });
        if (val === 'name_desc')   rows.sort(function(a,b){ return (b.dataset.name||'').localeCompare(a.dataset.name||''); });
        if (val === 'stock_asc')   rows.sort(function(a,b){ return parseInt(a.dataset.stock||0)-parseInt(b.dataset.stock||0); });
        if (val === 'stock_desc')  rows.sort(function(a,b){ return parseInt(b.dataset.stock||0)-parseInt(a.dataset.stock||0); });
        if (val === 'newest')      rows.sort(function(a,b){ return (b.dataset.updated||'').localeCompare(a.dataset.updated||''); });

        rows.forEach(function(r){ tbody.appendChild(r); });
    }

    // Re-apply filters and update pagination
    applyFilters();
});

// ── Tabs Navigation Switcher ─────────────────────────────────
window.switchInvTab = function switchInvTab(tab) {
    var btnOv  = document.getElementById('tab-overview');
    var btnStk = document.getElementById('tab-stockin');
    var btnAlt = document.getElementById('tab-alerts');

    var secOv  = document.getElementById('section-overview');
    var secStk = document.getElementById('section-stockin');
    var secAlt = document.getElementById('section-alerts');

    [btnOv, btnStk, btnAlt].forEach(function(b) {
        if (!b) return;
        b.classList.remove('active');
    });
    [secOv, secStk, secAlt].forEach(function(s) {
        if (!s) return;
        s.style.display = 'none';
    });

    if (tab === 'overview') {
        if (btnOv) btnOv.classList.add('active');
        if (secOv) secOv.style.display = 'block';
    } else if (tab === 'stockin') {
        if (btnStk) btnStk.classList.add('active');
        if (secStk) secStk.style.display = 'block';
    } else {
        if (btnAlt) btnAlt.classList.add('active');
        if (secAlt) secAlt.style.display = 'block';
    }
};
var switchInvTab = window.switchInvTab;


// ── View Details ──────────────────────────────────────────────
function viewDetails(it) {
    var lastUpdatedStr = it.last_updated ? (new Date(it.last_updated)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
    var batchId = (it.batches && it.batches[0] && it.batches[0].batch_id) ? it.batches[0].batch_id : ('B' + String(it.id).padStart(3, '0'));
    var expDate = it.expiration_date && it.expiration_date !== 'N/A' && it.expiration_date !== '' ? it.expiration_date : (function() {
        var base = it.last_updated ? new Date(it.last_updated) : new Date('2026-08-31');
        var cat = (it.category || '').toLowerCase();
        var nm  = (it.name || '').toLowerCase();
        if (nm.indexOf('chippy') !== -1 || nm.indexOf('coca') !== -1 || nm.indexOf('choco') !== -1 || cat.indexOf('snack') !== -1 || cat.indexOf('beverage') !== -1) {
            base.setFullYear(base.getFullYear() + 1);
        } else if (cat.indexOf('accessory') !== -1 || cat.indexOf('tool') !== -1 || nm.indexOf('wiper') !== -1 || nm.indexOf('mat') !== -1) {
            base.setFullYear(base.getFullYear() + 5);
        } else {
            base.setFullYear(base.getFullYear() + 3);
        }
        return base.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    })();
    var initialQty = (parseInt(it.stock_in, 10) > 0) ? parseInt(it.stock_in, 10) : (parseInt(it.capacity, 10) || 480);
    var fillPct = Math.min(100, Math.round(it.fill_pct || 0));

    var matchingStockIns = (window.stockInListData || []).filter(function(sin) {
        return parseInt(sin.product_id || 0) === parseInt(it.id) || (sin.product_name && sin.product_name.toLowerCase() === (it.name || '').toLowerCase());
    });
    var stockInRows = '';
    if (matchingStockIns.length > 0) {
        stockInRows = matchingStockIns.slice(0, 10).map(function(sin) {
            var dStr = sin.date_received ? (new Date(sin.date_received)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
            var expBatchStr = sin.expiration_date ? (new Date(sin.expiration_date)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : (expDate !== 'N/A' ? expDate : '—');
            return '<div style="display:grid; grid-template-columns: 1.2fr 1fr 1fr 1fr; border-bottom:1px solid #e2e8f0; padding:10px 14px; font-size:12px; align-items:center; box-sizing:border-box; width:100%;">' +
                '<div style="font-weight:700; color:#002F70; word-break:break-all;"><code style="color:#002F70; font-weight:700;">' + escHtml(sin.stock_in_no || ('SI-' + String(sin.id).padStart(5, '0'))) + '</code></div>' +
                '<div style="text-align:center; font-size:12px; color:#475569; font-weight:600;">' + escHtml(dStr) + '</div>' +
                '<div style="text-align:center; font-size:12px; color:#475569; font-weight:600;">' + escHtml(expBatchStr) + '</div>' +
                '<div style="text-align:right; font-weight:800; color:#16a34a; font-size:12.5px;">+' + Number(sin.qty_received || 0).toLocaleString() + ' ' + escHtml(it.unit) + '</div>' +
                '</div>';
        }).join('');
    } else {
        stockInRows = '<div style="text-align:center; color:#64748b; padding:18px 14px; font-size:12px; font-weight:600; background:#f8fafc; line-height:1.5; box-sizing:border-box; width:100%;"><i class="fas fa-info-circle" style="color:#002F70; margin-right:6px;"></i> No recent stock-in history recorded for this product.</div>';
    }

    var expStatusHtml = '';
    var isExpired = false;
    var isExpiringSoon = false;
    if (it.exp_status === 'expired' || (expDate !== 'N/A' && new Date(expDate) < new Date(new Date().toDateString()))) {
        isExpired = true;
        expStatusHtml = ' <span style="background:#dc354520;color:#dc3545;border:1.5px solid #dc354560;border-radius:4px;font-size:10px;font-weight:800;padding:2px 6px;text-transform:uppercase;margin-left:6px;display:inline-block;"><i class="fas fa-exclamation-circle"></i> EXPIRED</span>';
    } else if (it.exp_status === 'expiring_soon' || (expDate !== 'N/A' && (new Date(expDate) - new Date(new Date().toDateString())) / (1000*60*60*24) <= 30)) {
        isExpiringSoon = true;
        expStatusHtml = ' <span style="background:#fd7e1420;color:#c05c00;border:1.5px solid #fd7e1460;border-radius:4px;font-size:10px;font-weight:800;padding:2px 6px;text-transform:uppercase;margin-left:6px;display:inline-block;"><i class="fas fa-clock"></i> EXPIRING SOON</span>';
    }

    var expiredNoticeHtml = isExpired ? 
        '<div style="background:#fee2e2; border:1.5px solid #ef4444; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; gap:12px; color:#991b1b; font-size:13px; font-weight:700;">' +
        '  <i class="fas fa-exclamation-triangle" style="font-size:22px; color:#dc2626; flex-shrink:0;"></i>' +
        '  <div><strong style="text-transform:uppercase; letter-spacing:0.5px;">Product Has Expired</strong><div style="font-size:11.5px; font-weight:500; color:#7f1d1d; margin-top:2px;">This product reached its expiration date on ' + escHtml(expDate) + '. Do not dispense or sell to customers.</div></div>' +
        '</div>' : (isExpiringSoon ?
        '<div style="background:#fffbeb; border:1.5px solid #f59e0b; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; gap:12px; color:#92400e; font-size:13px; font-weight:700;">' +
        '  <i class="fas fa-clock" style="font-size:20px; color:#d97706; flex-shrink:0;"></i>' +
        '  <div><strong style="text-transform:uppercase; letter-spacing:0.5px;">Expiring Soon</strong><div style="font-size:11.5px; font-weight:500; color:#b45309; margin-top:2px;">This product will expire on ' + escHtml(expDate) + '. Prioritize sales using FIFO.</div></div>' +
        '</div>' : '');

    document.getElementById('vdContent').innerHTML =
        expiredNoticeHtml +
        '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; margin-bottom:16px; box-sizing:border-box; width:100%;">' +
        '  <h4 style="margin:0 0 12px; color:#002F70; font-size:12.5px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #cbd5e1; padding-bottom:6px;"><i class="fas fa-info-circle"></i> Product Information</h4>' +
        '  <div class="vd-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:12px 20px; margin-bottom:0; width:100%; box-sizing:border-box;">' +
        vdRow('Batch ID', '<code style="font-size:12px; font-weight:700; color:#002F70;">' + escHtml(batchId) + '</code>') +
        vdRow('SKU', '<code style="font-size:12px; font-weight:700; color:#1e293b;">' + escHtml(it.sku || '—') + '</code>') +
        vdRow('Product Name', '<strong style="font-size:13px; color:#0f172a;">' + escHtml(it.name) + '</strong>') +
        vdRow('Barcode', '<code style="font-size:12px; font-weight:600; color:#475569;">' + escHtml(it.barcode || '—') + '</code>') +
        vdRow('Category', '<span style="font-size:12.5px; font-weight:600; color:#334155;">' + escHtml(it.category) + '</span>') +
        vdRow('Brand', '<span style="font-size:12.5px; font-weight:600; color:#334155;">' + escHtml(it.brand || 'Petron') + '</span>') +
        vdRow('Unit of Measure (UOM)', '<span style="font-size:12.5px; font-weight:600; color:#334155;">' + escHtml(it.unit) + '</span>') +
        vdRow('Expiration Date', '<span style="font-size:12.5px; font-weight:700; color:' + (isExpired ? '#dc3545' : (expDate !== 'N/A' ? '#0f172a' : '#94a3b8')) + ';">' + escHtml(expDate) + '</span>' + expStatusHtml) +
        '  </div>' +
        '</div>' +

        '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; margin-bottom:16px; box-sizing:border-box; width:100%;">' +
        '  <h4 style="margin:0 0 12px; color:#002F70; font-size:12.5px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #cbd5e1; padding-bottom:6px;"><i class="fas fa-cubes"></i> Stock & Inventory Details</h4>' +
        '  <div class="vd-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:12px 20px; margin-bottom:0; width:100%; box-sizing:border-box;">' +
        vdRow('Initial Stock', '<strong style="font-size:13.5px; color:#0f172a;">' + Number(initialQty).toLocaleString() + '</strong> ' + escHtml(it.unit)) +
        vdRow('Current Stock', '<strong style="font-size:15px; color:' + (isExpired ? '#dc3545' : '#16a34a') + ';">' + Number(it.stock).toLocaleString() + '</strong> ' + escHtml(it.unit)) +
        vdRow('Reorder Level', '<strong style="font-size:13.5px; color:#ea580c;">' + Number(it.reorder).toLocaleString() + '</strong> ' + escHtml(it.unit)) +
        vdRow('Stock Status', isExpired ? '<span class="status-badge" style="background:#dc354520; color:#dc3545; border:1.5px solid #dc354560; font-size:11px; padding:3px 10px; font-weight:800; text-transform:uppercase;"><i class="fas fa-ban" style="font-size:9.5px;margin-right:2px;"></i> EXPIRED</span>' : '<span class="status-badge" style="background:' + it.color + '20; color:' + it.color + '; border:1.5px solid ' + it.color + '40; font-size:11px; padding:3px 10px; font-weight:800; text-transform:uppercase;">' + escHtml(it.status) + '</span>') +
        vdRow('Fill Level', '<div style="margin-top:4px;"><div style="background:#e2e8f0; border-radius:4px; height:7px; overflow:hidden; width:100%; max-width:140px; margin-bottom:3px;"><div style="width:' + fillPct + '%; background:' + (isExpired ? '#dc3545' : it.color) + '; height:100%; border-radius:4px;"></div></div><span style="font-size:11px; font-weight:700; color:#475569;">' + fillPct + '% capacity</span></div>') +
        vdRow('Last Updated', '<span style="font-size:12px; font-weight:600; color:#64748b;">' + escHtml(lastUpdatedStr) + '</span>') +
        '  </div>' +
        '</div>' +

        '<div style="background:#ffffff; border:1.5px solid #cbd5e1; border-radius:10px; overflow:hidden; width:100%; box-sizing:border-box;">' +
        '  <div style="background:#002F70; color:#fff; padding:10px 16px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:8px;"><i class="fas fa-arrow-down"></i> Recent Stock-In (Read-Only Reference)</div>' +
        '  <div style="display:grid; grid-template-columns: 1.2fr 1fr 1fr 1fr; background:#f1f5f9; color:#002F70; border-bottom:1.5px solid #cbd5e1; padding:10px 14px; font-size:11.5px; font-weight:800; text-transform:uppercase; letter-spacing:0.3px; width:100%; box-sizing:border-box;">' +
        '    <div style="text-align:left;">Stock-In No.</div>' +
        '    <div style="text-align:center;">Date Received</div>' +
        '    <div style="text-align:center;">Expiration Date</div>' +
        '    <div style="text-align:right;">Qty Received</div>' +
        '  </div>' +
        '  <div style="width:100%; box-sizing:border-box;">' + stockInRows + '</div>' +
        '</div>';

    document.getElementById('vdModal').classList.add('open');
    document.body.classList.add('modal-open');
}

function printProductDetails() {
    var content = document.getElementById('vdContent').innerHTML;
    var printWin = window.open('', '_blank', 'width=750,height=600');
    printWin.document.write(
        '<html><head><title>Product Details Printout</title>' +
        '<style>' +
        'body { font-family: Arial, sans-serif; padding: 24px; color: #1e293b; }' +
        'h2 { color: #002F70; border-bottom: 2px solid #002F70; padding-bottom: 8px; margin-bottom: 16px; font-size: 18px; }' +
        '.vd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }' +
        '.vd-row { display: flex; flex-direction: column; }' +
        '.vd-label { font-size: 10px; font-weight: bold; color: #64748b; text-transform: uppercase; }' +
        '.vd-val { font-size: 13px; font-weight: 600; margin-top: 2px; }' +
        'table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 11px; }' +
        'th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }' +
        'th { background: #f1f5f9; color: #002F70; font-weight: bold; }' +
        '.status-badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }' +
        '</style></head><body>' +
        '<h2>PETRON STATION MANAGEMENT SYSTEM — PRODUCT DETAILS</h2>' +
        content +
        '<div style="margin-top:30px; font-size:11px; color:#64748b; text-align:right;">Printed on: ' + (new Date()).toLocaleString() + '</div>' +
        '</body></html>'
    );
    printWin.document.close();
    printWin.focus();
    setTimeout(function() { printWin.print(); printWin.close(); }, 300);
}

function vdRow(label, val) {
    return '<div class="vd-row"><div class="vd-label">'+label+'</div><div class="vd-val">'+val+'</div></div>';
}
function closeVd() {
    document.getElementById('vdModal').classList.remove('open');
    document.body.classList.remove('modal-open');
}
function closeVdOpenSr() {
    var it = JSON.parse(document.getElementById('vdSrBtn').dataset.item || '{}');
    closeVd();
    openSrModal(it.id);
}
document.getElementById('vdModal').addEventListener('click', function(e){ if(e.target===this) closeVd(); });

// ── Stock Request Modal ───────────────────────────────────────
function toggleSrRowClass(cb) {
    var tr = cb.closest('tr');
    if (cb.checked) {
        tr.style.background = '#f0fdf4';
    } else {
        tr.style.background = '';
    }
}
function srSelectAll() {
    document.querySelectorAll('#srProductsList .sr-cb').forEach(function(cb) {
        cb.checked = true;
        toggleSrRowClass(cb);
    });
}
function srClearSelection() {
    document.querySelectorAll('#srProductsList .sr-cb').forEach(function(cb) {
        cb.checked = false;
        toggleSrRowClass(cb);
    });
}

function openSrModal(preselect) {
    var listEl = document.getElementById('srProductsList');
    listEl.innerHTML = '';
    document.getElementById('srReason').value = '';
    document.getElementById('srError').style.display = 'none';
    document.getElementById('srSubmitBtn').disabled = false;
    document.getElementById('srSubmitBtn').innerHTML = '<i class="fas fa-paper-plane"></i> Submit Stock Request';

    // Filter items that are low/critical/out of stock, or if preselected
    var needy = allMerchData.filter(function(it) {
        return it.status_key === 'low' || it.status_key === 'critical' || it.status_key === 'out' || (preselect && parseInt(it.id) === parseInt(preselect));
    });

    if (needy.length === 0) {
        listEl.innerHTML = '<tr><td colspan="7" style="padding:16px;text-align:center;color:#64748b;font-size:13px;">' +
            '<i class="fas fa-check-circle" style="color:#16a34a;font-size:1.5em;display:block;margin-bottom:8px;"></i>' +
            'All products are currently at optimal stock levels. No low or critical items found.</td></tr>';
    } else {
        needy.forEach(function(it) {
            var isChecked = (preselect && parseInt(it.id) === parseInt(preselect)) ? 'checked' : '';
            var tr = document.createElement('tr');
            if (isChecked) tr.style.background = '#f0fdf4';
            tr.className = 'sr-tbl-row';
            tr.innerHTML = 
                '<td style="text-align:center;"><input type="checkbox" class="sr-cb" value="' + it.id + '" ' + isChecked + ' onclick="event.stopPropagation(); toggleSrRowClass(this);"></td>' +
                '<td style="font-family:monospace; font-weight:700;">P' + String(it.id).padStart(4, '0') + '</td>' +
                '<td style="font-family:monospace; font-weight:600;" title="' + escHtml(it.sku || '') + '">' + escHtml(it.sku || '—') + '</td>' +
                '<td style="font-weight:600;" title="' + escHtml(it.name) + '">' + escHtml(it.name) + '</td>' +
                '<td style="text-align:center; font-weight:700;">' + it.stock + '</td>' +
                '<td style="text-align:center; color:#dc2626; font-weight:700;">' + it.reorder + '</td>' +
                '<td class="sr-td-status"><span style="display:inline-block; padding:1px 7px; border-radius:10px; font-size:9.5px; font-weight:700; white-space:nowrap; background:' + it.color + '20; color:' + it.color + '; border:1px solid ' + it.color + '40;">' + escHtml(it.status) + '</span></td>';
            
            tr.addEventListener('click', function() {
                var cb = this.querySelector('.sr-cb');
                cb.checked = !cb.checked;
                toggleSrRowClass(cb);
            });
            listEl.appendChild(tr);
        });
    }
    
    // Force display and scrolling
    var modal = document.getElementById('srModal');
    modal.classList.add('open');
    document.body.classList.add('modal-open');
    
    setTimeout(function() {
        var modalBody = modal.querySelector('.mi-body');
        if (modalBody) {
            modalBody.style.overflowY = 'auto';
        }
    }, 50);
}
function closeSrModal() {
    var m = document.getElementById('srModal');
    if (m) m.classList.remove('open');
    document.body.classList.remove('modal-open');
}

// Safe null-checked listeners (buttons also have onclick attributes as primary handler)
(function() {
    var closeBtn = document.getElementById('srModalClose');
    if (closeBtn) closeBtn.addEventListener('click', closeSrModal);
    var cancelBtn = document.getElementById('srCancelBtn');
    if (cancelBtn) cancelBtn.addEventListener('click', closeSrModal);
    var srModalEl = document.getElementById('srModal');
    if (srModalEl) srModalEl.addEventListener('click', function(e){ if(e.target===this) closeSrModal(); });
})();

function srHandleSubmit(btn) {
    var checked = Array.from(document.querySelectorAll('#srProductsList .sr-cb:checked'));
    var reason = document.getElementById('srReason').value.trim();
    var errEl = document.getElementById('srError');

    if (checked.length === 0) {
        errEl.textContent = 'Please check at least one product to submit a stock request.';
        errEl.style.display = 'block';
        return;
    }
    errEl.style.display = 'none';

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    var items = checked.map(function(cb) {
        var id = parseInt(cb.value);
        var it = allMerchData.find(function(x) { return x.id === id; });
        if (!it) return null;
        return {
            item_id:            it.id,
            sku:                it.sku,
            item_name:          it.name,
            item_category:      it.category,
            current_stock:      it.stock,
            requested_quantity: 0
        };
    }).filter(Boolean);

    fetch('../backend/api/stock_request.php?action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            items:   items,
            remarks: reason || 'Bulk stock request — low/critical stock'
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Stock Request';
        closeSrModal();

        var popupIcon = document.getElementById('srPopupIcon');
        var popupTitle = document.getElementById('srPopupTitle');
        var popupStatus = document.getElementById('srPopupStatusRow');

        if (res.success) {
            if (popupIcon) {
                popupIcon.style.background = 'linear-gradient(135deg,#28a745,#20c997)';
                popupIcon.innerHTML = '<i class="fas fa-check" style="color:#fff;font-size:22px;"></i>';
            }
            if (popupTitle) {
                popupTitle.style.color = '#28a745';
                popupTitle.innerText = 'REQUEST SUBMITTED!';
            }
            if (popupStatus) popupStatus.style.display = 'block';

            var srNo = res.request_no || '';
            var cnt  = res.inserted_count || items.length;
            var msg  = 'Successfully submitted stock requests for <strong>' + cnt + '</strong> item' + (cnt !== 1 ? 's' : '') + '.';
            if (srNo) msg += '<br><span style="font-size:12px;color:#64748b;">Request No: <strong>' + escHtml(srNo) + '</strong></span>';
            if (res.message && res.message.indexOf('skipped') !== -1) {
                msg += '<br><small style="color:#d97706;">' + escHtml(res.message.split('Note:')[1] || '') + '</small>';
            }
            document.getElementById('srSuccessMsg').innerHTML = msg;
        } else {
            var isPendingNotice = res.pending_exists || (res.message && (res.message.indexOf('pending') !== -1 || res.message.indexOf('already') !== -1));
            
            if (isPendingNotice) {
                if (popupIcon) {
                    popupIcon.style.background = 'linear-gradient(135deg,#f59e0b,#d97706)';
                    popupIcon.innerHTML = '<i class="fas fa-clock" style="color:#fff;font-size:22px;"></i>';
                }
                if (popupTitle) {
                    popupTitle.style.color = '#d97706';
                    popupTitle.innerText = 'REQUEST ALREADY PENDING';
                }
                if (popupStatus) popupStatus.style.display = 'none';

                var cleanMsg = res.message || 'A stock request for the selected item(s) is already pending Manager review.';
                document.getElementById('srSuccessMsg').innerHTML =
                    '<span style="color:#d97706;font-weight:600;">' + escHtml(cleanMsg) + '</span>';
            } else {
                if (popupIcon) {
                    popupIcon.style.background = 'linear-gradient(135deg,#dc2626,#ef4444)';
                    popupIcon.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#fff;font-size:22px;"></i>';
                }
                if (popupTitle) {
                    popupTitle.style.color = '#dc2626';
                    popupTitle.innerText = 'SUBMISSION ERROR';
                }
                if (popupStatus) popupStatus.style.display = 'none';

                document.getElementById('srSuccessMsg').innerHTML =
                    '<span style="color:#dc2626;">' + escHtml(res.message || 'Submission failed. Please try again.') + '</span>';
            }
        }

        document.getElementById('srSuccessPopup').style.display = 'block';
        document.getElementById('srSuccessOverlay').style.display = 'block';
        setTimeout(closeSrSuccess, 7000);
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        errEl.textContent = 'Network error. Please check your connection and try again.';
        errEl.style.display = 'block';
    });
}

// Legacy addEventListener for Submit (safety net — onclick on button is the primary handler)
(function() {
    var sb = document.getElementById('srSubmitBtn');
    if (sb && !sb.dataset.listenerBound) {
        sb.dataset.listenerBound = '1';
        // noop — handled by onclick attribute
    }
})();




function closeSrSuccess() {
    document.getElementById('srSuccessPopup').style.display = 'none';
    document.getElementById('srSuccessOverlay').style.display = 'none';
    // Persist banner info across reload
    var bannerText = (document.getElementById('srSuccessMsg') || {}).innerHTML || '';
    if (bannerText && bannerText.indexOf('Successfully') !== -1) {
        try { sessionStorage.setItem('srBannerMsg', bannerText); } catch(e) {}
    }
    window.location.reload();
}
function closeSrBanner() {
    var b = document.getElementById('srGlobalSuccessBanner');
    if (b) {
        b.style.opacity = '0';
        b.style.transform = 'translateX(60px)';
        b.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        setTimeout(function() { b.style.display = 'none'; }, 260);
    }
    try { sessionStorage.removeItem('srBannerMsg'); } catch(e) {}
}
function showSrBanner(title, msg) {
    var banner = document.getElementById('srGlobalSuccessBanner');
    var bannerTitle = document.getElementById('srBannerTitle');
    var bannerText = document.getElementById('srBannerText');
    if (banner) {
        if (bannerTitle && title) bannerTitle.innerText = title;
        if (bannerText && msg) bannerText.innerHTML = msg;
        banner.style.display = 'flex';
        banner.style.opacity = '1';
        banner.style.transform = 'translateX(0)';
        setTimeout(closeSrBanner, 8000);
    }
}
function escHtml(str) { var d=document.createElement('div'); d.appendChild(document.createTextNode(str||'')); return d.innerHTML; }

// ── On page load: show success banner if stock request was just submitted ──
document.addEventListener('DOMContentLoaded', function() {
    try {
        var msg = sessionStorage.getItem('srBannerMsg');
        if (msg) {
            sessionStorage.removeItem('srBannerMsg');
            var banner = document.getElementById('srGlobalSuccessBanner');
            var bannerText = document.getElementById('srBannerText');
            if (banner && bannerText) {
                bannerText.innerHTML = msg;
                banner.style.display = 'flex';
                // Auto-dismiss after 8 seconds
                setTimeout(closeSrBanner, 8000);
            }
        }
    } catch(e) {}
});
document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closeVd(); closeSrModal(); } });

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
    ['srModal','vdModal','srSuccessOverlay','srSuccessPopup'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    setupDownwardFilterSelects([
        '#filterCategory',
        '#filterBrand',
        '#filterUnit',
        '#filterStatus',
        '#sortBy'
    ]);
    setupTablePagination('merchTable', 'merchRowsLimit', 'merchPagination', 50);
    applyFilters();

    // Auto-open stock request modal if triggered from Quick Actions / URL
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('stock_request') === '1' || urlParams.get('open_sr') === '1' || urlParams.get('action') === 'request' || window.location.hash === '#stock_request') {
        setTimeout(function() {
            if (typeof openSrModal === 'function') openSrModal();
        }, 200);
    }

    // Auto-open Product Details Modal & scroll into view when navigated from Global Search
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
            var searchInput = document.getElementById('searchQuery') || document.getElementById('searchInput') || document.querySelector('.filter-input');
            if (searchInput && autoOpenSearch && !searchInput.value) {
                searchInput.value = autoOpenSearch;
                applyFilters();
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

// ── Custom Dropdown (CDD) Logic ────────────────────────────────
// Maps cdd id → hidden select id + change handler
var cddMap = {
    'cdd-category': { selectId: 'filterCategory', onChange: applyFilters },
    'cdd-brand':    { selectId: 'filterBrand',    onChange: applyFilters },
    'cdd-unit':     { selectId: 'filterUnit',     onChange: applyFilters },
    'cdd-status':   { selectId: 'filterStatus',   onChange: applyFilters },
    'cdd-sort':     { selectId: 'sortBy',         onChange: function(){ document.getElementById('sortBy').dispatchEvent(new Event('change')); } }
};

function cddToggle(id) {
    var wrap = document.getElementById(id);
    var isOpen = wrap.classList.contains('cdd-open');
    // Close all
    document.querySelectorAll('.cdd-wrap.cdd-open').forEach(function(w){ w.classList.remove('cdd-open'); });
    if (!isOpen) wrap.classList.add('cdd-open');
}

function cddSet(cddId, val, label) {
    var wrap = document.getElementById(cddId);
    if (!wrap) return;
    wrap.querySelector('.cdd-label').textContent = label;
    wrap.querySelectorAll('.cdd-item').forEach(function(item){
        item.classList.toggle('cdd-active', item.dataset.val === val);
    });
    var cfg = cddMap[cddId];
    if (cfg) {
        var sel = document.getElementById(cfg.selectId);
        if (sel) sel.value = val;
    }
}

// Wire up cdd item clicks
document.querySelectorAll('.cdd-wrap').forEach(function(wrap) {
    var id = wrap.id;
    wrap.querySelectorAll('.cdd-item').forEach(function(item) {
        item.addEventListener('click', function() {
            var val = item.dataset.val;
            var label = item.textContent.trim();
            cddSet(id, val, label || (id === 'cdd-sort' ? 'Default Sort' :
                id === 'cdd-category' ? 'All Categories' :
                id === 'cdd-brand' ? 'All Brands' :
                id === 'cdd-unit' ? 'All Units' :
                id === 'cdd-status' ? 'All Statuses' : label));
            wrap.classList.remove('cdd-open');
            var cfg = cddMap[id];
            if (cfg && cfg.onChange) cfg.onChange();
        });
    });
});

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.cdd-wrap')) {
        document.querySelectorAll('.cdd-wrap.cdd-open').forEach(function(w){ w.classList.remove('cdd-open'); });
    }
});

// ══ INVENTORY ADJUSTMENT MODAL HANDLERS ══
var currentAdjProduct = null;

function openAdjustmentModal(it) {
    currentAdjProduct = it;
    document.getElementById('adj_product_id').value = it.id;
    
    var today = new Date(); var yy = today.getFullYear(); var mm = String(today.getMonth()+1).padStart(2,'0'); var dd = String(today.getDate()).padStart(2,'0');
    var batchId = (it.batches && it.batches.length > 0) ? it.batches[0].batch_id : ('BT-' + yy + mm + dd + '-' + String(it.id).padStart(4, '0'));
    var batchExp = it.expiration_date ? it.expiration_date : ((it.batches && it.batches.length > 0 && it.batches[0].date) ? (new Date(it.batches[0].date)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—');
    if (batchExp && batchExp.indexOf(':') !== -1) {
        try { batchExp = (new Date(batchExp)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); } catch(e){}
    }

    document.getElementById('adj_disp_batch').innerText = batchId;
    document.getElementById('adj_disp_sku').innerText = it.sku || '-';
    document.getElementById('adj_disp_name').innerText = it.name || '-';
    document.getElementById('adj_disp_brand').innerText = it.brand || '-';
    document.getElementById('adj_disp_category').innerText = it.category || '-';
    document.getElementById('adj_disp_stock').innerText = Number(it.stock).toLocaleString();
    document.getElementById('adj_disp_uom').innerText = it.unit || 'pcs';
    document.getElementById('adj_disp_uom2').innerText = it.unit || 'pcs';
    document.getElementById('adj_disp_exp').innerText = batchExp;
    document.getElementById('adj_qty_unit').innerText = it.unit || 'pcs';

    // Reset inputs
    document.getElementById('adj_type').value = '';
    document.getElementById('adj_action').value = 'Decrease';
    var manualSelect = document.getElementById('adj_manual_direction');
    if (manualSelect) manualSelect.value = 'Decrease';
    document.getElementById('adj_quantity').value = '';
    document.getElementById('adj_reason').value = '';
    document.getElementById('adj_remarks').value = '';
    document.getElementById('adj_qty_error').style.display = 'none';

    handleAdjTypeChange();
    document.getElementById('staffAdjustmentModal').style.display = 'flex';
}

function closeAdjustmentModal() {
    document.getElementById('staffAdjustmentModal').style.display = 'none';
}

function handleAdjTypeChange() {
    var type = document.getElementById('adj_type').value;
    var reasonInput = document.getElementById('adj_reason');
    var qtyLabel = document.getElementById('adj_qty_label');
    var qtyInput = document.getElementById('adj_quantity');

    reasonInput.value = type ? type : '';

    if (type === 'Physical Count') {
        if (qtyLabel) qtyLabel.innerHTML = 'Actual Physical Count <span style="color:#dc2626;">*</span>';
        if (qtyInput) qtyInput.placeholder = 'Enter actual physical count...';
    } else {
        if (qtyLabel) qtyLabel.innerHTML = 'Quantity <span style="color:#dc2626;">*</span>';
        if (qtyInput) qtyInput.placeholder = 'Enter quantity...';
    }

    updateAdjActionDetection();
}

function updateAdjActionDetection() {
    if (!currentAdjProduct) return;
    var type = document.getElementById('adj_type').value;
    var qtyVal = parseFloat(document.getElementById('adj_quantity').value);
    var stock = parseFloat(currentAdjProduct.stock) || 0;
    
    var actionHidden = document.getElementById('adj_action');
    var actionDisplay = document.getElementById('adj_action_display');
    var manualWrap = document.getElementById('adj_manual_direction_wrap');
    var errorEl = document.getElementById('adj_qty_error');
    var submitBtn = document.getElementById('adjSubmitBtn');

    var detectedAction = 'Decrease';
    var badgeHtml = '';
    var calcQtyChange = 0;

    if (manualWrap) manualWrap.style.display = 'none';

    if (type === 'Damaged Product' || type === 'Expired Product' || type === 'Missing Item') {
        detectedAction = 'Decrease';
        badgeHtml = '<div style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#dc2626; font-weight:800; font-size:13px;"><i class="fas fa-minus-circle"></i> Inventory Adjustment (Decrease)</div>';
    } else if (type === 'Returned Item' || type === 'Return to Inventory') {
        detectedAction = 'Increase';
        badgeHtml = '<div style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; color:#16a34a; font-weight:800; font-size:13px;"><i class="fas fa-plus-circle"></i> Inventory Adjustment (Increase)</div>';
    } else if (type === 'Physical Count') {
        if (!isNaN(qtyVal)) {
            calcQtyChange = qtyVal - stock;
            if (calcQtyChange > 0) {
                detectedAction = 'Increase';
                badgeHtml = '<div style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; color:#16a34a; font-weight:800; font-size:13px;"><i class="fas fa-plus-circle"></i> Inventory Adjustment (Increase)</div>';
            } else if (calcQtyChange < 0) {
                detectedAction = 'Decrease';
                badgeHtml = '<div style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#dc2626; font-weight:800; font-size:13px;"><i class="fas fa-minus-circle"></i> Inventory Adjustment (Decrease)</div>';
            } else {
                detectedAction = 'None';
                badgeHtml = '<div style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; color:#475569; font-weight:700; font-size:13px;"><i class="fas fa-check-circle"></i> No Stock Change (Matched)</div>';
            }
        } else {
            detectedAction = 'Pending Input';
            badgeHtml = '<div style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; color:#64748b; font-weight:600; font-size:13px;">Enter actual physical count to calculate direction</div>';
        }
    } else if (type === 'Encoding Error' || type === 'Others') {
        if (manualWrap) manualWrap.style.display = 'block';
        var optSelect = document.getElementById('adj_manual_direction');
        var manualDir = optSelect ? optSelect.value : 'Decrease';
        detectedAction = manualDir;
        if (manualDir === 'Increase') {
            badgeHtml = '<div style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; color:#16a34a; font-weight:800; font-size:13px;"><i class="fas fa-plus-circle"></i> Inventory Adjustment (Increase)</div>';
        } else {
            badgeHtml = '<div style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#dc2626; font-weight:800; font-size:13px;"><i class="fas fa-minus-circle"></i> Inventory Adjustment (Decrease)</div>';
        }
    } else {
        detectedAction = 'Decrease';
        badgeHtml = '<div style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; color:#64748b; font-weight:600; font-size:13px;">Select Adjustment Type</div>';
    }

    actionHidden.value = detectedAction;
    actionDisplay.innerHTML = badgeHtml;

    // Validation: Only cap deduction for strict physical loss types (Damaged Product, Expired Product, Missing Item)
    var isStrictDeductionType = (type === 'Damaged Product' || type === 'Expired Product' || type === 'Missing Item');
    if (detectedAction === 'Decrease' && isStrictDeductionType && !isNaN(qtyVal) && qtyVal > stock) {
        errorEl.innerText = 'Validation Error: Deduction quantity (' + qtyVal + ') cannot exceed current stock (' + stock + ') for ' + type + '.';
        errorEl.style.display = 'block';
        if (submitBtn) submitBtn.disabled = true;
        return false;
    } else {
        errorEl.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;
        return true;
    }
}

function validateAdjQuantity() {
    return updateAdjActionDetection();
}

function submitAdjustmentForm(e) {
    e.preventDefault();
    if (!validateAdjQuantity()) return;

    var submitBtn = document.getElementById('adjSubmitBtn');
    var type = document.getElementById('adj_type').value;
    var action = document.getElementById('adj_action').value;
    var qty = parseFloat(document.getElementById('adj_quantity').value) || 0;
    var reason = document.getElementById('adj_reason').value;
    var remarks = document.getElementById('adj_remarks').value;

    if (!type) {
        alert('Please select an adjustment type.');
        return;
    }
    if (qty <= 0) {
        alert('Please enter a valid quantity greater than zero.');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    fetch('../backend/api/inventory_adjustment.php?action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            product_id: parseInt(currentAdjProduct.id),
            adjustment_type: type,
            adjustment_action: action,
            quantity: qty,
            reason: reason,
            remarks: remarks
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Adjustment';

        if (res.success) {
            closeAdjustmentModal();
            showSrBanner("ADJUSTMENT SUBMITTED!", "Your inventory adjustment request is now Pending Manager Approval.");
        } else {
            alert('Error: ' + (res.message || 'Failed to submit adjustment.'));
        }
    })
    .catch(function(err) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Adjustment';
        alert('Network or server error: ' + err.message);
    });
}
</script>

<!-- ══ INVENTORY ADJUSTMENT MODAL ══ -->
<style>
#staffAdjustmentModal .modal-box {
    width: 92% !important;
    max-width: 520px !important;
}
</style>
<div class="modal-overlay" id="staffAdjustmentModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:15000; align-items:center; justify-content:center; box-sizing:border-box; overflow-y:auto;">
    <div class="modal-box" style="background:#fff; border-radius:14px; width:92% !important; max-width:520px !important; max-height:calc(100vh - 120px) !important; margin:auto; box-shadow:0 20px 50px rgba(0,0,0,0.35); overflow:hidden; display:flex; flex-direction:column; position:relative;">
        <div style="background:linear-gradient(135deg,#002F70,#001838); padding:14px 20px; color:#fff; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
            <div style="font-size:15px; font-weight:700; display:flex; align-items:center; gap:10px;"><i class="fas fa-edit" style="color:#fd7e14;"></i> Request Inventory Adjustment</div>
            <button type="button" onclick="closeAdjustmentModal()" style="background:transparent; border:none; color:rgba(255,255,255,0.7); font-size:18px; cursor:pointer; padding:0; display:flex; align-items:center; justify-content:center; transition:color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.7)'" title="Close"><i class="fas fa-times"></i></button>
        </div>
        
        <form id="adjustmentForm" onsubmit="submitAdjustmentForm(event)" style="display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; margin:0;">
            <input type="hidden" id="adj_product_id" name="product_id">
            
            <div style="padding:16px 22px; overflow-y:auto; overflow-x:hidden !important; flex:1; min-height:0; box-sizing:border-box;">
                <!-- Product Information (Auto Fetch / Readonly) -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-bottom:18px;">
                    <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">
                        <i class="fas fa-info-circle" style="color:#002F70;"></i> Product Information
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:12px;">
                        <div><span style="color:#64748b;">Batch ID:</span> <strong id="adj_disp_batch" style="color:#0f172a;">—</strong></div>
                        <div><span style="color:#64748b;">SKU:</span> <code id="adj_disp_sku" style="font-weight:700; color:#002F70;">—</code></div>
                        <div><span style="color:#64748b;">Product Name:</span> <strong id="adj_disp_name" style="color:#0f172a;">—</strong></div>
                        <div><span style="color:#64748b;">Brand:</span> <span id="adj_disp_brand" style="color:#334155;">—</span></div>
                        <div><span style="color:#64748b;">Category:</span> <span id="adj_disp_category" style="color:#334155;">—</span></div>
                        <div><span style="color:#64748b;">Current Stock:</span> <strong id="adj_disp_stock" style="color:#16a34a; font-size:13px;">0</strong> <span id="adj_disp_uom" style="color:#64748b;">pcs</span></div>
                        <div><span style="color:#64748b;">Expiration Date:</span> <span id="adj_disp_exp" style="color:#334155;">N/A</span></div>
                        <div><span style="color:#64748b;">UOM:</span> <span id="adj_disp_uom2" style="color:#334155;">pcs</span></div>
                    </div>
                </div>

                <!-- Adjustment Details -->
                <div style="font-size:12px; font-weight:700; color:#0f172a; margin-bottom:12px;">
                    <i class="fas fa-sliders" style="color:#fd7e14;"></i> Adjustment Details
                </div>

                <!-- Adjustment Type -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">
                        Adjustment Type <span style="color:#dc2626;">*</span>
                    </label>
                    <select id="adj_type" name="adjustment_type" onchange="handleAdjTypeChange()" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; color:#0f172a;">
                        <option value="">-- Select Adjustment Type --</option>
                        <option value="Damaged Product">Damaged Product</option>
                        <option value="Expired Product">Expired Product</option>
                        <option value="Physical Count">Physical Count</option>
                        <option value="Missing Item">Missing Item</option>
                        <option value="Returned Item">Returned Item</option>
                        <option value="Encoding Error">Encoding Error</option>
                        <option value="Others">Others</option>
                    </select>
                </div>

                <!-- Adjustment Action Display -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">
                        Adjustment Action
                    </label>
                    <input type="hidden" id="adj_action" name="adjustment_action" value="Decrease">
                    <div id="adj_action_display" style="min-height:36px; display:flex; align-items:center;">
                        <div style="display:inline-flex; align-items:center; gap:8px; padding:6px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#dc2626; font-weight:800; font-size:13px;">
                            <i class="fas fa-minus-circle"></i> Inventory Adjustment (Decrease)
                        </div>
                    </div>
                    
                    <!-- Dynamic Direction Toggle for Custom Types -->
                    <div id="adj_manual_direction_wrap" style="display:none; margin-top:8px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                        <label style="display:block; font-size:11px; font-weight:700; color:#002F70; margin-bottom:4px;">
                            <i class="fas fa-arrows-alt-v"></i> Select Stock Action Direction:
                        </label>
                        <select id="adj_manual_direction" onchange="updateAdjActionDetection()" style="width:100%; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; font-weight:700; color:#0f172a;">
                            <option value="Decrease">Inventory Adjustment (Decrease)</option>
                            <option value="Increase">Inventory Adjustment (Increase)</option>
                        </select>
                    </div>
                </div>

                <!-- Quantity / Physical Count -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label id="adj_qty_label" style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">
                        Quantity <span style="color:#dc2626;">*</span>
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="number" id="adj_quantity" name="quantity" min="0" step="1" required oninput="validateAdjQuantity()" placeholder="Enter quantity..." style="flex:1; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:14px; font-weight:700; color:#0f172a;">
                        <span id="adj_qty_unit" style="font-size:12px; font-weight:600; color:#475569;">pcs</span>
                    </div>
                    <small id="adj_qty_error" style="color:#dc2626; font-size:11px; margin-top:3px; display:none; font-weight:600;"></small>
                </div>

                <!-- Reason (Auto-filled hidden input) -->
                <input type="hidden" id="adj_reason" name="reason">

                <!-- Remarks -->
                <div class="form-group" style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">
                        Remarks <span style="color:#64748b; font-weight:normal; font-size:11px;">(Optional)</span>
                    </label>
                    <textarea id="adj_remarks" name="remarks" rows="3" placeholder="Add any optional notes or details here..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-family:inherit;"></textarea>
                </div>
            </div>

            <div style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:16px 24px; display:flex; justify-content:flex-end; gap:12px; flex-shrink:0;">
                <button type="button" onclick="closeAdjustmentModal()" class="txn-btn muted">Cancel</button>
                <button type="submit" id="adjSubmitBtn" class="txn-btn primary" style="background:#002F70!important; color:#fff!important;">
                    <i class="fas fa-paper-plane"></i> Submit Adjustment
                </button>
            </div>
        </form>
    </div>
</div>

</div> <!-- /stock-page -->

<script id="merchAutoRefreshScript">
// ── Seamless Silent Background Auto-Fetch for Merchandise Inventory (15 seconds) ──
(function() {
    'use strict';
    function autoRefreshMerchandiseInventory() {
        if (document.hidden) return;
        var openModal = document.querySelector('.modal-overlay.open, .sr-modal-overlay.open, .modal.show, div[style*="display: block"][id*="Modal"], div[style*="display: flex"][id*="Modal"]');
        if (openModal) return;
        var searchInput = document.getElementById('searchMerchandise') || document.querySelector('input[type="search"]');
        if (searchInput && searchInput === document.activeElement && searchInput.value.trim() !== '') return;

        fetch(window.location.href, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .then(function(res) { return res.text(); })
        .then(function(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            
            // Update table body
            var newTbody = doc.querySelector('#merchTableBody') || doc.querySelector('#merchandiseTable tbody') || doc.querySelector('table tbody');
            var curTbody = document.querySelector('#merchTableBody') || document.querySelector('#merchandiseTable tbody') || document.querySelector('table tbody');
            if (newTbody && curTbody) {
                curTbody.innerHTML = newTbody.innerHTML;
                if (typeof applyFilters === 'function') applyFilters();
            }
            
            // Update top KPI cards if present
            var newCards = doc.querySelectorAll('.kpi-card, .metric-card, [class*="summary-card"]');
            var curCards = document.querySelectorAll('.kpi-card, .metric-card, [class*="summary-card"]');
            if (newCards.length > 0 && curCards.length === newCards.length) {
                for (var j = 0; j < newCards.length; j++) {
                    curCards[j].innerHTML = newCards[j].innerHTML;
                }
            }
        })
        .catch(function(e) {
            console.warn('Merchandise inventory auto-fetch notice:', e);
        });
    }
    setInterval(autoRefreshMerchandiseInventory, 10000);
})();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>


