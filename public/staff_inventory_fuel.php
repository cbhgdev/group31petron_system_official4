<?php
$page_id = 'inv_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// ── Module gate ──────────────────────────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'admin', 'manager', 'superadmin'])) {
    header('Location: login.php');
    exit;
}

// ── AJAX Handler for Movement History ─────────────────────────────────────────
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_fuel_details') {
    header('Content-Type: application/json');
    $fuel_type = $_GET['fuel_type'] ?? '';
    
    // Clean fuel type string (e.g. "Diesel (UGT #1)" -> "Diesel")
    $clean_fuel = trim(preg_replace('/\s*\(.*?\)/', '', $fuel_type));
    
    // Aliases mapping for fuel delivery & transactions
    $aliases = [$fuel_type, $clean_fuel];
    $fl = strtolower($clean_fuel);
    if (strpos($fl, 'diesel') !== false && strpos($fl, 'turbo') === false) {
        $aliases[] = 'Diesel';
    } elseif (strpos($fl, 'turbo') !== false) {
        $aliases[] = 'Turbo Diesel';
    } elseif (strpos($fl, 'xcs') !== false) {
        $aliases[] = 'XCS Plus';
        $aliases[] = 'XCS';
    } elseif (strpos($fl, 'xtra') !== false || strpos($fl, 'regular') !== false || strpos($fl, 'unl') !== false) {
        $aliases[] = 'Xtra UNL';
        $aliases[] = 'XTRA UNL';
        $aliases[] = 'XTRA';
    } elseif (strpos($fl, 'kerosene') !== false) {
        $aliases[] = 'Kerosene';
    }
    $aliases = array_values(array_unique(array_filter($aliases)));

    $deliveries = [];
    try {
        $where_parts = [];
        $params = [$station_id, $station_id, $station_id];
        foreach ($aliases as $alias) {
            $where_parts[] = "LOWER(TRIM(fuel_type)) = LOWER(?)";
            $params[] = $alias;
        }
        $where_sql = implode(' OR ', $where_parts);

        $stmt = $pdo->prepare("SELECT delivery_date, delivery_liters, invoice_no, supplier, status, fuel_type, tank_assigned FROM fuel_deliveries WHERE station_id = ? AND ($where_sql) ORDER BY delivery_date DESC, id DESC LIMIT 10");
        $stmt->execute($params);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $transactions = [];
    try {
        $where_parts = [];
        $params = [$station_id, $station_id, $station_id];
        foreach ($aliases as $alias) {
            $where_parts[] = "LOWER(TRIM(fuel_type)) = LOWER(?)";
            $params[] = $alias;
        }
        $where_sql = implode(' OR ', $where_parts);

        $stmt = $pdo->prepare("SELECT transaction_date, liters_sold, total_amount, shift_period, status, fuel_type FROM fuel_transactions WHERE station_id = ? AND ($where_sql) ORDER BY transaction_date DESC, id DESC LIMIT 10");
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'deliveries' => $deliveries,
        'transactions' => $transactions
    ]);
    exit;
}

$TANK_CONFIG_17 = get_tank_config((int)$station_id);

// ── Fetch fuel_inventory (one row per fuel_type for this station) ─────
$fi_raw = [];
$fi_lookup = [];
try {
    $s = $pdo->prepare("SELECT id, fuel_type_id, fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, COALESCE(ugt_no,'') AS ugt_no FROM fuel_inventory WHERE station_id = ? AND LOWER(COALESCE(status,'active')) NOT IN ('archived', 'deleted') ORDER BY CAST(REGEXP_REPLACE(COALESCE(ugt_no,'0'), '[^0-9]', '') AS UNSIGNED) ASC, id ASC");
    $s->execute([$station_id]);
    $fi_raw = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fi_raw as $row) {
        $fi_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {}

// â”€â”€ Fetch today's deliveries per (fuel_type, tank_assigned) â”€â”€â”€â”€â”€â”€â”€â”€â”€
$del_lookup = [];
try {
    $s = $pdo->prepare("SELECT tank_assigned, fuel_type, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id=? AND DATE(delivery_date)=CURDATE() AND status='Verified' GROUP BY tank_assigned, fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];
    }
} catch (Exception $e) {}

// â”€â”€ Fetch today's sales per fuel_type â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$sales_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE() AND status='Verified' GROUP BY fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];
    }
} catch (Exception $e) {}

// â”€â”€ Fetch today's calibration/adjustments per fuel_type â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$adj_lookup = [];
try {
    // NOTE: Stock-In / tank-dip / approved adjustments already update fuel_inventory.current_level; do not subtract them again (AND 1=0).
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id=fi.fuel_type_id AND fi.station_id=fa.station_id WHERE fa.station_id=? AND 1=0 AND DATE(fa.adjustment_date)=CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];
    }
} catch (Exception $e) {}

// â”€â”€ Fetch latest price per fuel_type from fuel_pricing â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$price_lookup = [];
try {
    $s = $pdo->prepare("SELECT ft.name AS fuel_type, fp.price_per_liter FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id=ft.id WHERE fp.station_id=? AND fp.is_active=1 ORDER BY fp.effective_date DESC");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim($row['fuel_type']));
        if (!isset($price_lookup[$key])) $price_lookup[$key] = (float)$row['price_per_liter'];
    }
} catch (Exception $e) {}

// â”€â”€ Fetch latest validated readings per fuel type â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$latest_readings_lookup = [];
try {
    $s = $pdo->prepare("
        SELECT ft.fuel_type, ft.previous_reading, ft.present_reading, ft.calibration, ft.liters_sold
        FROM fuel_transactions ft
        INNER JOIN (
            SELECT fuel_type, MAX(id) AS max_id
            FROM fuel_transactions
            WHERE station_id = ? AND status IN ('Approved', 'Completed', 'Verified')
            GROUP BY fuel_type
        ) latest ON ft.id = latest.max_id
    ");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $latest_readings_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) {}

// Fallback lookup if no validated transactions exist yet
foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key = strtolower(trim($tc['fuel_type']));
    if (!isset($latest_readings_lookup[$ft_key])) {
        try {
            $s = $pdo->prepare("
                SELECT fuel_type, previous_reading, present_reading, calibration, liters_sold
                FROM fuel_transactions
                WHERE station_id = ? AND LOWER(trim(fuel_type)) = ?
                ORDER BY transaction_date DESC, id DESC
                LIMIT 1
            ");
            $s->execute([$station_id, $ft_key]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $latest_readings_lookup[$ft_key] = $row;
            } else {
                $latest_readings_lookup[$ft_key] = [
                    'fuel_type' => $tc['fuel_type'],
                    'previous_reading' => 0.00,
                    'present_reading' => 0.00,
                    'calibration' => 0.00,
                    'liters_sold' => 0.00
                ];
            }
        } catch (Exception $e) {
            $latest_readings_lookup[$ft_key] = [
                'fuel_type' => $tc['fuel_type'],
                'previous_reading' => 0.00,
                'present_reading' => 0.00,
                'calibration' => 0.00,
                'liters_sold' => 0.00
            ];
        }
    }
}

// â”€â”€ Build 17-row dataset â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$rows = [];
$msg = '';
try {
    foreach ($TANK_CONFIG_17 as $tc) {
        $tank_num = $tc['tanker_num'];
        $ugt_str  = 'UGT-' . str_pad($tank_num, 2, '0', STR_PAD_LEFT);
        $fuel_type_base = $tc['fuel_type'];
        $ft_key = strtolower(trim($fuel_type_base));
        
        // Smart match: match by exact id, UGT number, tanker_num, or clean fuel_type
        $inv = null;
        foreach ($fi_raw as $r) {
            if (!empty($tc['id']) && (int)$r['id'] === (int)$tc['id']) {
                $inv = $r;
                break;
            }
            $r_ugt = strtolower(trim($r['ugt_no']));
            $r_ft  = strtolower(trim($r['fuel_type']));
            if (($r_ugt !== '' && $r_ugt === strtolower($ugt_str)) || 
                strpos($r_ft, '#' . $tank_num) !== false || 
                strpos($r_ft, 'ugt-' . str_pad($tank_num, 2, '0', STR_PAD_LEFT)) !== false) {
                $inv = $r;
                break;
            }
        }
        if (!$inv) {
            if ($ft_key === 'xtra unl' || $ft_key === 'xtr advance') {
                $cand = '';
                if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'xtra unl 1'; }
                elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'xtra unl 2'; }
                if ($cand && isset($fi_lookup[$cand])) { $ft_key = $cand; }
                else { $ft_key = 'xtra unl'; }
            } elseif ($ft_key === 'diesel') {
                $cand = '';
                if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'diesel 1'; }
                elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'diesel 2'; }
                if ($cand && isset($fi_lookup[$cand])) { $ft_key = $cand; }
                else { $ft_key = 'diesel'; }
            }
            $inv = $fi_lookup[$ft_key] ?? null;
        }

        $tank_key = strtolower(trim($tc['tank']));

        $capacity  = (float)$tc['capacity'];
        $cur_level = min(
            $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0,
            $capacity
        );

        // Number of tanks for this fuel sub-group
        $same_type_count = count(array_filter($TANK_CONFIG_17, function($t) use ($ft_key, $fi_lookup) {
            $k = strtolower(trim($t['fuel_type']));
            if ($k === 'xtra unl' || $k === 'xtr advance') {
                $cand = '';
                if (strpos(strtolower($t['label']), '1') !== false) { $cand = 'xtra unl 1'; }
                elseif (strpos(strtolower($t['label']), '2') !== false) { $cand = 'xtra unl 2'; }
                if ($cand && isset($fi_lookup[$cand])) { $k = $cand; }
                else { $k = 'xtra unl'; }
            } elseif ($k === 'diesel') {
                $cand = '';
                if (strpos(strtolower($t['label']), '1') !== false) { $cand = 'diesel 1'; }
                elseif (strpos(strtolower($t['label']), '2') !== false) { $cand = 'diesel 2'; }
                if ($cand && isset($fi_lookup[$cand])) { $k = $cand; }
                else { $k = 'diesel'; }
            }
            return $k === $ft_key;
        }));

        // Deliveries: per tank_assigned
        $purchases = $del_lookup[$tank_key] ?? 0;

        // Sales & Calibration: split equally
        $sales_total = $sales_lookup[$ft_key] ?? 0;
        $adj_total   = $adj_lookup[$ft_key] ?? 0;
        $sales       = $same_type_count > 0 ? round($sales_total / $same_type_count, 2) : 0;
        $calibration_adj = $same_type_count > 0 ? round($adj_total / $same_type_count, 2) : 0;

        // Beginning Balance
        $beginning = $cur_level;

        $total_available = $beginning + $purchases;
        $ending_system   = min(max(0, $total_available - $sales - $calibration_adj), $capacity);

        // Actual Dip = use ending_system as proxy
        $actual_dip = $ending_system;
        $variance   = $ending_system - $actual_dip;

        $current_level_tank = $ending_system;

        // Thresholds — from DB tank config (reorder_level / critical_level)
        $critical_lvl = (float)($tc['critical_level'] ?? 0);
        $low_lvl      = (float)($tc['reorder_level']  ?? 0);
        if ($critical_lvl <= 0) $critical_lvl = $capacity > 0 ? $capacity * 0.15 : 0;
        if ($low_lvl <= 0)      $low_lvl      = $capacity > 0 ? $capacity * 0.30 : 0;
        $fill_pct = $capacity > 0 ? round(($current_level_tank / $capacity) * 100, 2) : 0;

        // Check if tank is deactivated in fuel_inventory
        $sf_inv_status = strtolower(trim($inv['status'] ?? 'active'));
        $sf_deactivated = in_array($sf_inv_status, ['inactive', 'deactivated', 'disabled'], true);

        if ($sf_deactivated)                              { $status = 'Deactivated';  $sc = '#dc3545'; }
        elseif ($current_level_tank <= 0)                 { $status = 'Out of Stock'; $sc = '#dc3545'; }
        elseif ($current_level_tank <= $critical_lvl)     { $status = 'Critical';     $sc = '#dc3545'; }
        elseif ($current_level_tank <= $low_lvl)          { $status = 'Low';          $sc = '#fd7e14'; }
        else                                              { $status = 'Normal';       $sc = '#28a745'; }

        // Price
        $price = $price_lookup[$ft_key] ?? ($inv ? (float)($inv['price_per_liter'] ?? 0) : 0);

        // Revenue
        $revenue = round($sales * $price, 2);

        // Timestamp
        $timestamp = $inv['last_updated'] ?? null;

        // Fetch Beginning / Ending Readings for this Tank's Fuel Type
        $tx = $latest_readings_lookup[$ft_key] ?? null;
        $beg_reading = $tx ? (float)$tx['previous_reading'] : 0.00;
        $end_reading = $tx ? (float)$tx['present_reading'] : 0.00;
        $calibration_val = $tx ? (float)$tx['calibration'] : 0.00;
        $total_dispensed = max(0, $end_reading - $beg_reading - $calibration_val);

        $ugt_no = !empty($inv['ugt_no']) ? $inv['ugt_no'] : (!empty($inv['tank_name']) ? $inv['tank_name'] : ('UGT-' . str_pad($tc['tanker_num'], 2, '0', STR_PAD_LEFT)));

        $rows[] = [
            'ugt_no'          => $ugt_no,
            'fuel_type'       => $tc['fuel_type'],
            'label'           => $tc['label'],
            'tank'            => $tc['tank'],
            'tanker_num'      => $tc['tanker_num'],
            'capacity'        => $capacity,
            'reorder_level'   => $tc['reorder_level'],
            'beginning'       => $beginning,
            'purchases'       => $purchases,
            'total_available' => $total_available,
            'sales'           => $sales,
            'calibration_adj' => $calibration_adj,
            'ending_system'   => $ending_system,
            'actual_dip'      => $actual_dip,
            'variance'        => $variance,
            'current_level'   => $current_level_tank,
            'status'          => $status,
            'status_color'    => $sc,
            'fill_pct'        => $fill_pct,
            'price'           => $price,
            'revenue'         => $revenue,
            'timestamp'       => $timestamp,
            'beginning_reading'=> $beg_reading,
            'ending_reading'   => $end_reading,
            'calibration'     => $calibration_val,
            'total_dispensed' => $total_dispensed,
            'inv_id'          => $inv['id'] ?? null
        ];
    }
} catch (Exception $e) {
    $msg = 'Error loading fuel inventory: ' . $e->getMessage();
}

// ── Append any additional fuel products from fuel_inventory not covered by TANK_CONFIG_17 ──
$seen_inv_ids = array_filter(array_column($rows, 'inv_id'));
foreach ($fi_raw as $row) {
    $r_id = (int)$row['id'];
    if (in_array($r_id, $seen_inv_ids, true)) continue;
    $sf_raw_st = strtolower(trim($row['status'] ?? 'active'));
    if (in_array($sf_raw_st, ['archived', 'deleted'], true)) continue;

    $cap   = (float)($row['capacity'] ?? 14000);
    $cur_s = (float)($row['current_level'] ?? $row['current_stock'] ?? 0);
    $crit  = (float)($row['critical_level'] ?? ($cap * 0.15));
    $reord = (float)($row['reorder_level'] ?? ($cap * 0.30));
    $sf_pr = (float)($row['price_per_liter'] ?? 0);

    $sf_deact = in_array($sf_raw_st, ['inactive', 'deactivated', 'disabled'], true);

    $st = 'Normal'; $sc_e = '#28a745';
    if ($sf_deact)           { $st = 'Deactivated';  $sc_e = '#dc3545'; }
    elseif ($cur_s <= 0)     { $st = 'Out of Stock'; $sc_e = '#dc3545'; }
    elseif ($cur_s <= $crit) { $st = 'Critical';     $sc_e = '#dc3545'; }
    elseif ($cur_s <= $reord){ $st = 'Low';           $sc_e = '#fd7e14'; }

    $sf_fill = $cap > 0 ? round(($cur_s / $cap) * 100, 2) : 0;
    $numOnly = (int)preg_replace('/[^0-9]/', '', $row['ugt_no'] ?? '') ?: (count($rows) + 1);
    $sf_ugt  = !empty($row['ugt_no']) ? $row['ugt_no'] : ('UGT #' . $numOnly);

    $rows[] = [
        'ugt_no'           => $sf_ugt,
        'fuel_type'        => $row['fuel_type'],
        'label'            => $sf_ugt,
        'tank'             => $sf_ugt,
        'tanker_num'       => $numOnly,
        'capacity'         => $cap,
        'reorder_level'    => $reord,
        'beginning'        => $cur_s,
        'purchases'        => 0,
        'total_available'  => $cur_s,
        'sales'            => 0,
        'calibration_adj'  => 0,
        'ending_system'    => $cur_s,
        'actual_dip'       => $cur_s,
        'variance'         => 0,
        'current_level'    => $cur_s,
        'status'           => $st,
        'status_color'     => $sc_e,
        'fill_pct'         => $sf_fill,
        'price'            => $sf_pr,
        'revenue'          => 0,
        'timestamp'        => $row['last_updated'] ?? null,
        'beginning_reading'=> 0,
        'ending_reading'   => 0,
        'calibration'      => 0,
        'total_dispensed'  => 0,
        'inv_id'           => $r_id
    ];
    $seen_inv_ids[] = $r_id;
}

$pending_fuel_sr = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE staff_id=? AND status IN ('Pending', 'Pending Manager Review')");
    $s->execute([$me['id']]);
    $pending_fuel_sr = (int)$s->fetchColumn();
} catch (Exception $e) {}

// Build JS data array for Stock Request modal
$js_fuel = [];
foreach ($rows as $r) {
    $fl  = (float)$r['current_level'];
    $cap = (float)$r['capacity'];
    $pct = $cap > 0 ? ($fl / $cap) * 100 : 0;
    
    $st = strtoupper($r['status']);
    $sc = $r['status_color'];
    if ($st === 'NORMAL' || $st === 'AVAILABLE') {
        $st_cls = 'status-ok';
    } elseif ($st === 'LOW') {
        $st_cls = 'status-low';
    } else {
        $st_cls = 'status-critical';
    }
    
    $js_fuel[] = [
        'name'         => $r['fuel_type'],
        'tanker_label' => $r['label'] ?? '',
        'tanker_num'   => $r['tanker_num'] ?? 0,
        'level'        => $fl,
        'capacity'     => $cap,
        'reorder_level'=> (float)($r['reorder_level'] ?? 0),
        'pct'          => round($pct, 1),
        'variance'     => $r['variance'],
        'status'       => $st,
        'statusCls'    => $st_cls,
        'color'        => $sc,
    ];
}

// Summary Metrics calculations
$total_tanks = count($rows);
$total_fuel_available = array_sum(array_filter(array_map(fn($r) => $r['status'] === 'Deactivated' ? 0 : (float)$r['current_level'], $rows)));
$total_low_fuel_tanks = count(array_filter($rows, fn($r) => $r['status'] === 'Low'));
$total_critical_fuel_tanks = count(array_filter($rows, fn($r) => in_array($r['status'], ['Critical','Out of Stock'])));

// Per-type summaries for new dashboard cards
$diesel_available   = 0; $premium_available  = 0; $regular_available  = 0;
foreach ($rows as $_r) {
    if ($_r['status'] === 'Deactivated') continue; // skip deactivated in metrics
    $ft = strtolower(trim($_r['fuel_type']));
    if (str_contains($ft, 'diesel') || $ft === 'kerosene') {
        $diesel_available += $_r['current_level'];
    } elseif (str_contains($ft, 'xcs') || str_contains($ft, 'turbo') || str_contains($ft, 'blaze')) {
        $premium_available += $_r['current_level'];
    } else {
        $regular_available += $_r['current_level'];
    }
}

// Fetch fuel deliveries for Fuel Deliveries tab
$fuel_deliveries_list = [];
try {
    $s = $pdo->prepare("
        SELECT id, COALESCE(invoice_no, CONCAT('DEL-', LPAD(id,4,'0'))) AS delivery_no,
               supplier, fuel_type, delivery_liters AS liters,
               delivery_date, status
        FROM fuel_deliveries
        WHERE station_id = ?
        ORDER BY delivery_date DESC, id DESC
        LIMIT 100
    ");
    $s->execute([$station_id]);
    $fuel_deliveries_list = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}




include __DIR__ . '/../partials/header.php'; ?>

<style>
/* Prevent Column Text Overlap CSS */
.table-wrap, .table-responsive {
    overflow-x: hidden !important;
    width: 100% !important;
}
#mgrMerchTable, table.pricing-table, table.tbl-requests, table.table {
    table-layout: auto !important;
    min-width: 0 !important;
    width: 100% !important;
}
#mgrMerchTable th, table.pricing-table th, table.tbl-requests th {
    padding: 10px 10px !important;
    white-space: nowrap !important;
}
#mgrMerchTable td, table.pricing-table td, table.tbl-requests td {
    padding: 10px 10px !important;
    word-break: normal !important;
    overflow-wrap: break-word !important;
}
.cat-cell, td:nth-child(4), th:nth-child(4) {
    white-space: nowrap !important;
}
td:nth-child(6), th:nth-child(6) {
    white-space: nowrap !important;
}
td:nth-child(11), th:nth-child(11), td:nth-child(12), th:nth-child(12) {
    white-space: nowrap !important;
}
.badge, .status-badge, .status-pill, .inv-stock-badge, .pstatus-badge {
    white-space: nowrap !important;
}
</style>
<?php  require_once __DIR__ . '/../partials/flash_toast.php';
?>
<div class="stock-page">
<style>
html, body {
    max-width: 100vw !important;
    overflow-x: hidden !important;
}
.stock-page {
    overflow-x: hidden !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    padding: 12px 24px 130px 16px !important;
    margin: 0 !important;
}
#toggleScrollBtn, .toggle-scroll-btn {
    right: 12px !important;
    bottom: 48px !important;
    z-index: 999 !important;
}
.page-head, .int-head {
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
.page-head h1, .page-head .h1, .int-head h1 {
    margin: 0;
    color: #002F70 !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    font-family: system-ui,-apple-system,"Segoe UI",Roboto,sans-serif !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    line-height: 1.2 !important;
}
.main, .main-content {
    padding-top: 0 !important;
}

.inv-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    border: 1.5px solid #cbd5e1;
    margin-bottom: 14px;
    overflow: hidden;
}
.inv-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 18px;
    border-bottom: 1.5px solid #cbd5e1;
    flex-wrap: wrap;
    gap: 8px;
    background: #fff;
}
.inv-card-title {
    font-size: 1rem !important;
    font-weight: 700 !important;
    color: #002F70 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    text-transform: none !important;
    letter-spacing: normal !important;
}
.inv-card-body {
    padding: 14px 18px;
}

/* ── Sub Tabs Styling ── */
.fuel-sub-tabs {
    display: flex !important;
    flex-wrap: wrap !important;
    margin-bottom: 12px !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 0 !important;
    overflow: hidden !important;
    border-bottom: 3px solid #002F6C !important;
    gap: 0 !important;
    background: transparent !important;
    padding: 0 !important;
    width: 100% !important;
}
.fuel-sub-tab-btn {
    flex: 1 !important;
    min-width: 140px !important;
    padding: 10px 16px !important;
    font-size: 13px !important;
    font-weight: 800 !important;
    color: #334155 !important;
    background: #ffffff !important;
    border: none !important;
    border-right: 1.5px solid #cbd5e1 !important;
    border-radius: 0 !important;
    text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    text-align: center !important;
    cursor: pointer !important;
    margin-bottom: 0 !important;
    box-shadow: none !important;
}
.fuel-sub-tab-btn:last-child {
    border-right: none !important;
}
.fuel-sub-tab-btn:hover {
    background: #f1f5f9 !important;
    color: #002F6C !important;
    text-decoration: none !important;
}
.fuel-sub-tab-btn.active {
    background: #002F6C !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    box-shadow: none !important;
}

/* ── Filter Bar ── */
.inv-filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 12px;
    position: relative;
    z-index: 25;
    isolation: isolate;
    pointer-events: auto;
}
.inv-filter-bar select, .inv-filter-bar input[type=text], .inv-filter-bar input[type=date] {
    padding: 8px 12px;
    border: 1.5px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #0f172a;
    background: #fff;
    height: 36px;
    outline: none;
    pointer-events: auto;
    position: relative;
    z-index: 2;
}
.inv-filter-bar select {
    cursor: pointer;
}
.inv-filter-bar input[type=text] { cursor:text; }
.fuel-filter-actions { display:flex; align-items:center; gap:8px; }

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
.petron-dropdown-wrap.is-open {
    z-index: 10050 !important;
}
.petron-dropdown-trigger {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    width: 100% !important;
    height: 36px !important;
    padding: 6px 12px !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 6px !important;
    background: #ffffff !important;
    color: #1e293b !important;
    font-size: 13px !important;
    font-weight: 600 !important;
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
    font-size: 13px !important;
    font-weight: 600 !important;
}
.petron-dropdown-arrow {
    font-size: 10px !important;
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
    max-width: 260px !important;
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
    font-size: 13px !important;
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

/* Keep search control enabled */
#sq, input#sq {
    pointer-events: auto !important;
    cursor: text !important;
    opacity: 1 !important;
    visibility: visible !important;
    display: inline-block !important;
    position: relative !important;
    z-index: 3 !important;
}
select#cf, select#sf, select#df,
.petron-dropdown-source,
select[data-petron-down-ready="1"] {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
    width: 0 !important;
    height: 0 !important;
    position: absolute !important;
}
.inv-filter-bar button, .inv-filter-bar a { pointer-events:auto !important; position:relative; z-index:3; }

/* ── Zero-Scroll Fixed-Layout Tables ── */
.table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
    padding: 0 !important;
    margin: 0 !important;
    border-radius: 8px !important;
    border: 1.5px solid #cbd5e1 !important;
    box-sizing: border-box !important;
}
table.fuel-table, table.stock-alerts-table {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    table-layout: fixed !important;
    border-collapse: collapse !important;
    border-spacing: 0 !important;
    margin: 0 !important;
}
table.fuel-table thead tr, table.stock-alerts-table thead tr {
    background: #002F70 !important;
}
table.fuel-table thead th, table.stock-alerts-table thead th {
    padding: 10px 8px !important;
    text-align: center;
    font-size: 11.5px !important;
    font-weight: 800 !important;
    color: #ffffff !important;
    text-transform: uppercase !important;
    letter-spacing: .2px !important;
    border: 1px solid #00264D !important;
    white-space: nowrap !important;
    vertical-align: middle !important;
    line-height: 1.25 !important;
    box-sizing: border-box !important;
}
table.fuel-table tbody tr, table.stock-alerts-table tbody tr {
    border-bottom: 1px solid #e2e8f0;
    transition: background .1s;
}
table.fuel-table tbody tr:hover, table.stock-alerts-table tbody tr:hover {
    background: #f8fafc !important;
}
table.fuel-table tbody td, table.stock-alerts-table tbody td {
    padding: 8px 8px !important;
    color: #0f172a !important;
    vertical-align: middle !important;
    text-align: center;
    font-size: 12.5px !important;
    font-weight: 600 !important;
    border: 1px solid #e2e8f0 !important;
    line-height: 1.35 !important;
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
}
table.fuel-table tbody td.bold, table.stock-alerts-table tbody td.bold {
    font-weight: 800 !important;
    color: #002F70 !important;
}
.status-pill {
    display: inline-block !important;
    padding: 3px 8px !important;
    border-radius: 20px !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    white-space: nowrap !important;
    text-align: center !important;
    line-height: 1.2 !important;
}
.var-zero { color: #6c757d; }
.var-pos  { color: #28a745; font-weight: 700; }
.var-neg  { color: #dc3545; font-weight: 700; }

/* ── Actions Column & View Button Spacing (Never covered by edge) ── */
table.fuel-table th:last-child,
table.fuel-table td:last-child {
    text-align: center !important;
    padding-left: 8px !important;
    padding-right: 16px !important;
    white-space: nowrap !important;
    vertical-align: middle !important;
}

/* ── Modal Elements & Sidebar-Aware Workspace Centering ── */
.sr-modal-overlay {
    position: fixed !important;
    top: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    left: 0 !important;
    background: rgba(0,0,0,.55) !important;
    z-index: 10000 !important;
    display: none !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 80px 20px 20px 270px !important; /* Offset by sidebar width (250px + 20px padding) */
    box-sizing: border-box !important;
    opacity: 0;
    pointer-events: none !important;
    transition: opacity .2s ease-in-out, padding-left .3s ease;
}
body.sidebar-collapsed .sr-modal-overlay,
.sidebar.collapsed ~ .sr-modal-overlay {
    padding-left: 90px !important; /* Offset when sidebar collapsed (70px + 20px) */
}
@media (max-width: 991px) {
    .sr-modal-overlay {
        padding: 80px 20px 20px 20px !important;
    }
}
.sr-modal-overlay.open {
    display: flex !important;
    opacity: 1;
    pointer-events: auto !important;
}
.sr-modal-box {
    background: #fff !important;
    border-radius: 12px !important;
    width: 100% !important;
    max-width: min(1100px, calc(100vw - 310px)) !important;
    box-shadow: 0 10px 40px rgba(0,0,0,.3) !important;
    display: flex !important;
    flex-direction: column !important;
    max-height: calc(100vh - 110px) !important;
    overflow: hidden !important;
    pointer-events: auto !important;
    position: relative !important;
    z-index: 10001 !important;
    margin: 0 auto !important;
}
body.sidebar-collapsed .sr-modal-box {
    max-width: min(1100px, calc(100vw - 110px)) !important;
}
.sr-modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e2e8f0; flex-shrink:0; background:#fff; z-index:1; }
.sr-modal-title { font-size:16px; font-weight:700; color:#002F70; }
.sr-modal-body { overflow-y:auto; flex:1; min-height:0; padding:16px; }
.sr-modal-close { background:none !important; background-color:transparent !important; border:none !important; font-size:24px; color:#64748b !important; cursor:pointer !important; box-shadow:none !important; pointer-events:auto !important; }
.sr-modal-close:hover { color:#1e293b !important; }

/* Modal close and tab button overrides to prevent global button overrides */
.modal-header button, 
.sr-modal-head button,
.modal-tab-btn {
    background: none !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
    pointer-events: auto !important;
    cursor: pointer !important;
}
.modal-tab-btn.active {
    border-bottom: 2px solid #002F70 !important;
    color: #002F70 !important;
}
.modal-tab-btn:not(.active) {
    color: #64748b !important;
}
.sr-info-box { background:#eff6ff; border-left:4px solid #002F70; padding:12px 16px; margin:16px; border-radius:0 8px 8px 0; font-size:15.5px; color:#1e293b; line-height:1.5; }
.fsr-select-bar { display:flex; align-items:center; padding:10px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:15.5px; font-weight:600; pointer-events:auto !important; }
.fsr-select-bar input[type="checkbox"] { pointer-events:auto !important; cursor:pointer !important; }
.fsr-select-bar label { pointer-events:auto !important; cursor:pointer !important; }
#fsrCheckList { overflow-y:auto; flex:1; padding:8px 16px; pointer-events:auto !important; }
.fsr-cb-row { display:table-row; padding:0; border-radius:0; border:none; margin-bottom:0; cursor:pointer !important; transition:background .15s ease; pointer-events:auto !important; }
.fsr-cb-row td { padding:10px 12px; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
.fsr-cb-row:hover td { background:#f8fafc; }
.fsr-cb-row.checked td { background:#f0fdf4; }
.fsr-cb-row input[type="checkbox"] { margin-top:3px; transform:scale(1.1); cursor:pointer !important; pointer-events:auto !important; }
.fsr-item-info { flex:1; pointer-events:none; }
.fsr-item-name { font-weight:700; font-size:14px; color:#1e293b; }
.fsr-item-meta { font-size:14.5px; color:#64748b; margin-top:3px; display:flex; align-items:center; flex-wrap:wrap; gap:4px; }
.sr-modal-footer {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 12px !important;
    padding: 16px 24px !important;
    border-top: 1px solid #e2e8f0 !important;
    background: #f8fafc !important;
    flex-shrink: 0 !important;
    z-index: 2 !important;
    width: 100% !important;
    box-sizing: border-box !important;
    pointer-events: auto !important;
}
.sr-modal-footer button {
    pointer-events: auto !important;
    cursor: pointer !important;
}
/* ── Success Toast Banner (top-right) ── */
@keyframes fsrSlideInRight { from { opacity:0; transform:translateX(60px); } to { opacity:1; transform:translateX(0); } }
#fsrGlobalSuccessBanner {
    position: fixed;
    top: 72px;
    right: 24px;
    z-index: 19000;
    max-width: 380px;
    min-width: 280px;
    background: #ffffff;
    color: #1e293b;
    padding: 14px 16px;
    border-radius: 10px;
    box-shadow: 0 8px 28px rgba(0,0,0,0.14), 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
    display: none;
    flex-direction: column;
    gap: 5px;
    animation: fsrSlideInRight 0.3s cubic-bezier(.22,.68,0,1.2);
    transition: opacity 0.25s ease, transform 0.25s ease;
}

/* Custom Outlined Buttons for Petron-clean Look */
.flt-btn { display:inline-flex; align-items:center; gap:6px; padding:0 12px; height:36px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; border:1px solid transparent; background:#fff !important; transition:all .15s; }
.flt-btn-search { color:#0891b2 !important; -webkit-text-fill-color:#0891b2 !important; border-color:#0891b2 !important; background:#fff !important; }
.flt-btn-search:hover { background:#0891b2 !important; color:#fff !important; -webkit-text-fill-color:#fff !important; }
.flt-btn-reset { color:#475569 !important; -webkit-text-fill-color:#475569 !important; border-color:#cbd5e1 !important; background:#fff !important; }
.flt-btn-reset:hover { background:#64748b !important; color:#fff !important; -webkit-text-fill-color:#fff !important; }
.flt-btn-excel { color: #00264D !important; -webkit-text-fill-color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; -webkit-text-fill-color: #00264D !important; }
.flt-btn-csv { color: #00264D !important; -webkit-text-fill-color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-csv:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; -webkit-text-fill-color: #00264D !important; }
.flt-btn-pdf { color: #00264D !important; -webkit-text-fill-color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; -webkit-text-fill-color: #00264D !important; }

/* Custom Outlined Buttons for Petron-clean Look */
.int-btn-outline {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
    padding: 3px 10px !important;
    border-radius: 5px !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    border: 1px solid #002F70 !important;
    transition: all 0.15s !important;
    background: #ffffff !important;
    color: #002F70 !important;
    -webkit-text-fill-color: #002F70 !important;
    height: 26px !important;
    min-height: 26px !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
    margin: 0 auto !important;
}
.int-btn-outline:hover {
    background: #002F70 !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

.btn-cancel {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 0 16px; border-radius: 6px; font-size: 15.5px; font-weight: 600;
    cursor: pointer; border: 1px solid #6b7280; background: white !important;
    color: #475569 !important; transition: all .15s; height: 36px;
}
.btn-cancel:hover { background: #6b7280 !important; color: #fff !important; }

/* Modals */
.modal-overlay {
    display: none;
    position: fixed !important;
    top: 0 !important; right: 0 !important; bottom: 0 !important; left: 0 !important;
    background: rgba(0, 0, 0, .55) !important;
    z-index: 9999 !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 80px 20px 20px 270px !important;
    box-sizing: border-box !important;
    transition: padding-left .3s ease;
}
body.sidebar-collapsed .modal-overlay,
.sidebar.collapsed ~ .modal-overlay {
    padding-left: 90px !important;
}
@media (max-width: 991px) {
    .modal-overlay {
        padding: 80px 20px 20px 20px !important;
    }
}
.modal-overlay.open {
    display: flex !important;
}
.modal-box {
    background: #fff !important;
    border-radius: 12px !important;
    width: 600px !important;
    max-width: min(600px, calc(100vw - 310px)) !important;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04) !important;
    border: 1px solid #e2e8f0 !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
    max-height: calc(100vh - 110px) !important;
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
}
.modal-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    color: #002F70;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}
.modal-footer {
    padding: 12px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    background: #f8fafc;
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
    padding: 10px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
}

.modal-tab-btn {
    border: none;
    background: none;
    font-weight: 700;
    font-size: 14.5px;
    text-transform: uppercase;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.2s;
}
.modal-tab-btn.active {
    color: #002F70;
    border-bottom-color: #002F70;
}

.txn-btn { display:inline-flex !important; align-items:center !important; justify-content:center !important; gap:6px !important; padding:7px 14px !important; border-radius:4px !important; font-size:11px !important; font-weight:600 !important; cursor:pointer !important; border:1px solid transparent !important; transition:all .2s ease-in-out !important; text-decoration:none !important; white-space:nowrap !important; box-sizing:border-box !important; background-color:#ffffff !important; background:#ffffff !important; }
.txn-btn.primary   { color:#00264D !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #00264D !important; }
.txn-btn.primary:hover   { background-color:#00264D !important; background:#00264D !important; color:#ffffff !important; }
.txn-btn.secondary { color:#475569 !important; background-color:#ffffff !important; background:#ffffff !important; border:1px solid #475569 !important; }
.txn-btn.secondary:hover { background-color:#475569 !important; background:#475569 !important; color:#ffffff !important; }
</style>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-gas-pump"></i> Fuel Inventory</h1>
    </div>
</div>



<?php if ($msg): ?>
<div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<!-- ══ FUEL STOCK REQUEST SUCCESS TOAST (top-right) ══ -->
<div id="fsrGlobalSuccessBanner">
    <div style="display:flex; align-items:center; gap:10px;">
        <div style="width:34px; height:34px; border-radius:50%; background:#dcfce7; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
            <i class="fas fa-check-circle" style="font-size:17px; color:#16a34a;"></i>
        </div>
        <div style="font-size:15.5px; font-weight:800; color:#15803d; letter-spacing:0.2px; line-height:1.3;" id="fsrBannerTitle">FUEL STOCK REQUEST SUBMITTED!</div>
    </div>
    <div style="font-size:14.5px; color:#475569; padding-left:44px; line-height:1.5;" id="fsrBannerText">Your fuel stock request is now pending manager approval.</div>
</div>

<!-- ══ Dashboard Cards (4 Cards ONLY) ══ -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:14px;">
    <!-- Card 1: Total Fuel Available -->
    <div style="background:#fff; border-radius:8px; padding:12px 16px; box-shadow:0 1px 3px rgba(0,0,0,0.06); display:flex; align-items:center; justify-content:space-between; border:1.5px solid #cbd5e1;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Total Fuel Available (L)</div>
            <div style="font-size:20px; font-weight:700; color:#0284c7;"><?= number_format($total_fuel_available, 2) ?> L</div>
        </div>
        <div style="background:#e0f2fe; color:#0284c7; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0;"><i class="fas fa-gas-pump"></i></div>
    </div>
    <!-- Card 2: Diesel Available -->
    <div style="background:#fff; border-radius:8px; padding:12px 16px; box-shadow:0 1px 3px rgba(0,0,0,0.06); display:flex; align-items:center; justify-content:space-between; border:1.5px solid #cbd5e1;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Diesel Available (L)</div>
            <div style="font-size:20px; font-weight:700; color:#002F6C;"><?= number_format($diesel_available, 2) ?> L</div>
        </div>
        <div style="background:#e8f4fd; color:#002F6C; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0;"><i class="fas fa-tint"></i></div>
    </div>
    <!-- Card 3: Premium Available -->
    <div style="background:#fff; border-radius:8px; padding:12px 16px; box-shadow:0 1px 3px rgba(0,0,0,0.06); display:flex; align-items:center; justify-content:space-between; border:1.5px solid #cbd5e1;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Premium Available (L)</div>
            <div style="font-size:20px; font-weight:700; color:#7c3aed;"><?= number_format($premium_available, 2) ?> L</div>
        </div>
        <div style="background:#ede9fe; color:#7c3aed; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0;"><i class="fas fa-star"></i></div>
    </div>
    <!-- Card 4: Regular Available -->
    <div style="background:#fff; border-radius:8px; padding:12px 16px; box-shadow:0 1px 3px rgba(0,0,0,0.06); display:flex; align-items:center; justify-content:space-between; border:1.5px solid #cbd5e1;">
        <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">Regular Available (L)</div>
            <div style="font-size:20px; font-weight:700; color:#059669;"><?= number_format($regular_available, 2) ?> L</div>
        </div>
        <div style="background:#d1fae5; color:#059669; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0;"><i class="fas fa-leaf"></i></div>
    </div>
</div>

<!-- ══ Search & Filter Bar ══ -->
<form id="fuelFilterForm" class="inv-filter-bar" onsubmit="applyFuelInventoryFilters(event)" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
    <div style="position:relative;">
        <i class="fas fa-search" style="position:absolute; left:12px; top:11px; color:#94a3b8; font-size:13px;"></i>
        <input type="text" id="sq" placeholder="Search Fuel Type / UGT No..." oninput="filterFuelTable()" autocomplete="off" style="padding-left:32px; width:260px; height:36px; font-size:13px; font-weight:600; border:1.5px solid #cbd5e1; border-radius:6px;">
    </div>
    <select id="cf" onchange="filterFuelTable()" style="height:36px; font-size:13px; font-weight:600; border:1.5px solid #cbd5e1; border-radius:6px;">
        <option value="">All Fuel Types</option>
        <option value="diesel">Diesel</option>
        <option value="kerosene">Kerosene</option>
        <option value="turbo diesel">Turbo Diesel</option>
        <option value="xcs">XCS Plus</option>
        <option value="xtra">XTRA UNL</option>
    </select>
    <select id="sf" onchange="filterFuelTable()" style="height:36px; font-size:13px; font-weight:600; border:1.5px solid #cbd5e1; border-radius:6px;">
        <option value="">All Statuses</option>
        <option value="normal">Normal</option>
        <option value="low">Low Fuel</option>
        <option value="out of stock">Out of Stock</option>
    </select>
    <div class="fuel-filter-actions">
        <button type="submit" class="flt-btn flt-btn-search" style="height:36px; font-size:12px; font-weight:600; padding:0 14px;"><i class="fas fa-search"></i> Filter</button>
        <button type="button" class="flt-btn flt-btn-reset" onclick="resetFuelInventoryFilters()" style="height:36px; font-size:12px; font-weight:600; padding:0 14px;"><i class="fas fa-rotate-left"></i> Reset</button>
    </div>
</form>

<!-- ══ Sub Tabs ══ -->
<div class="fuel-sub-tabs">
    <button id="tabOverview" class="fuel-sub-tab-btn active" onclick="switchFuelTab('overview')">
        <i class="fas fa-gas-pump"></i> Fuel Inventory Overview
    </button>
    <button id="tabDeliveries" class="fuel-sub-tab-btn" onclick="switchFuelTab('deliveries')">
        <i class="fas fa-exclamation-triangle"></i> Stock Alerts
        <?php
        $alert_count = count(array_filter($rows, fn($r) => in_array($r['status'] ?? '', ['Critical','Low','Out of Stock'])));
        if ($alert_count > 0): ?>
            <span style="background:#dc2626 !important;color:#ffffff !important;border-radius:20px;padding:2px 7px;font-size:11px;font-weight:800;margin-left:4px;line-height:1;"><?= $alert_count ?></span>
        <?php endif; ?>
    </button>
</div>

<script>
(function() {
    function forceEnableNow() {
        var el = document.getElementById('sq');
        if (el) {
            el.style.pointerEvents = 'auto';
            el.style.cursor = 'text';
            el.disabled = false;
            el.style.opacity = '1';
            el.style.zIndex = '3';
        }
    }
    forceEnableNow();
    setTimeout(forceEnableNow, 100);
})();
</script>

<!-- ══ TAB: FUEL INVENTORY OVERVIEW ══ -->
<div id="section-tank-overview">
<div class="inv-card">
    <div class="inv-card-head" style="display:flex; align-items:center; justify-content:space-between;">
        <div class="inv-card-title"><i class="fas fa-gas-pump"></i> Fuel Stock Overview</div>
        <button type="button" onclick="openFuelSrModal()" class="sr-btn-outline" style="background:#ffffff !important; background-color:#ffffff !important; color:#002F70 !important; -webkit-text-fill-color:#002F70 !important; border:1.5px solid #cbd5e1 !important; border-radius:6px; padding:6px 14px; font-size:12.5px; font-weight:700 !important; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 1px 3px rgba(0,0,0,0.05) !important; text-decoration:none;">
            <i class="fas fa-paper-plane" style="color:#002F70 !important; -webkit-text-fill-color:#002F70 !important;"></i> Stock Request
        </button>
    </div>

    <div class="inv-card-body">
        <div class="table-wrap">
            <table class="fuel-table report-table no-min-width print-table" id="fuelTable" style="width:100% !important; max-width:100% !important; min-width:0 !important; table-layout:fixed !important; border-collapse:collapse;">
                <colgroup>
                    <col style="width: 9%;">  <!-- UGT No. -->
                    <col style="width: 15%;"> <!-- Fuel Type -->
                    <col style="width: 12%;"> <!-- Capacity -->
                    <col style="width: 13%;"> <!-- Current Volume -->
                    <col style="width: 13%;"> <!-- Available Space -->
                    <col style="width: 12%;"> <!-- Status -->
                    <col style="width: 14%;"> <!-- Updated -->
                    <col style="width: 12%;"> <!-- Actions -->
                </colgroup>
                <thead>
                    <tr>
                        <th style="text-align:left;">UGT No.</th>
                        <th style="text-align:left;">Fuel Type</th>
                        <th style="text-align:right;">Capacity</th>
                        <th style="text-align:right;">Current Volume</th>
                        <th style="text-align:right;">Available Space</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:center;">Updated</th>
                        <th style="text-align:center; padding-left:8px !important; padding-right:16px !important;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:32px;color:#6c757d;font-size:14px;">
                            No fuel inventory data available.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                         $ts_str   = $r['timestamp'] ? date('M d, Y h:i A', strtotime($r['timestamp'])) : '&mdash;';
                         $ugt_str  = 'UGT-' . str_pad($r['tanker_num'], 2, '0', STR_PAD_LEFT);
                         $avail_space = max(0, $r['capacity'] - $r['current_level']);
                         $row_date = $r['timestamp'] ? date('Y-m-d', strtotime($r['timestamp'])) : '';
                         $r_json   = htmlspecialchars(json_encode(array_merge($r, ['ugt_no' => $ugt_str, 'available_space' => $avail_space])), ENT_QUOTES);
                         $st_label = ($r['status'] === 'Low') ? 'Low Fuel' : (($r['status'] === 'Critical') ? 'Critical Fuel' : $r['status']);
                    ?>
                    <tr class="fuel-row"
                        data-tank-num="<?= htmlspecialchars(strtolower((string)$r['tanker_num'])) ?>"
                        data-ugt-no="<?= htmlspecialchars(strtolower($ugt_str)) ?>"
                        data-fuel-type="<?= htmlspecialchars(strtolower($r['fuel_type'])) ?>"
                        data-status="<?= htmlspecialchars(strtolower($r['status'])) ?>"
                        data-date="<?= $row_date ?>">
                        <td><code style="font-weight:700;color:#002F70;font-size:12px;"><?= htmlspecialchars($ugt_str) ?></code></td>
                        <td style="font-weight:700;color:#0f172a;font-size:12.5px;"><?= htmlspecialchars($r['fuel_type']) ?></td>
                        <td style="text-align:right;font-weight:600;color:#475569;font-size:12.5px;"><?= number_format($r['capacity'], 0) ?> L</td>
                        <td style="text-align:right;font-weight:800;color:#002F70;font-size:13.5px;"><?= number_format($r['current_level'], 2) ?> L</td>
                        <td style="text-align:right;font-weight:700;color:#16a34a;font-size:12.5px;"><?= number_format($avail_space, 2) ?> L</td>
                        <td style="text-align:center;white-space:normal !important;overflow:visible !important;text-overflow:clip !important;">
                            <span class="status-pill" style="background:<?= $r['status_color'] ?>18;color:<?= $r['status_color'] ?>;border:1px solid <?= $r['status_color'] ?>40;padding:3px 7px;border-radius:4px;font-size:11.5px;font-weight:700;text-transform:uppercase;white-space:nowrap;display:inline-block;line-height:1.2;">
                                <?= htmlspecialchars($st_label) ?>
                            </span>
                        </td>
                        <td style="font-size:12px;color:#1e293b;line-height:1.3;white-space:normal !important;overflow:visible !important;text-overflow:clip !important;">
                            <?php if ($r['timestamp'] && strtotime($r['timestamp']) > 0): ?>
                                <div style="font-weight:600;white-space:nowrap;"><?= date('M d, Y', strtotime($r['timestamp'])) ?></div>
                                <div style="font-size:11px;color:#64748b;white-space:nowrap;"><?= date('h:i A', strtotime($r['timestamp'])) ?></div>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center; padding-left:8px !important; padding-right:16px !important; white-space:nowrap !important;">
                            <button type="button" class="int-btn-outline" onclick='openTankModal(<?= $r_json ?>)'>
                                <i class="fas fa-eye" style="font-size:10px;"></i> View
                            </button>
                        </td>

                    </tr>

                    <?php endforeach; ?>
                <?php endif; ?>
                    <tr id="fuelNoResultsRow" class="no-paginate no-results search-hidden" style="display:none !important;">
                        <td colspan="8" style="text-align:center;padding:32px;color:#64748b;font-size:14px;">
                            No fuel tanks match the selected filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div id="fuelPagination" style="padding:8px 16px;"></div>
    </div>
</div>
</div>

<!-- ══ TAB: STOCK ALERTS ══ -->
<div id="section-fuel-deliveries" style="display:none;">
<div class="inv-card">
    <div class="inv-card-head">
        <div class="inv-card-title"><i class="fas fa-bell"></i> Stock Alerts</div>
    </div>
    <div class="inv-card-body">
        <?php
        $alert_rows = array_filter($rows, fn($r) => in_array($r['status'] ?? '', ['Critical','Low','Out of Stock']));
        if (empty($alert_rows)): ?>
            <div style="text-align:center;padding:40px;color:#64748b;">
                <i class="fas fa-check-circle" style="font-size:32px;color:#28a745;margin-bottom:12px;display:block;"></i>
                <strong>All fuel tanks are at normal levels.</strong><br>
                <span style="font-size:14.5px;">No low stock or critical alerts at this time.</span>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="stock-alerts-table report-table no-min-width print-table" style="width:100% !important; max-width:100% !important; min-width:0 !important; table-layout:fixed !important; border-collapse:collapse;">
                <colgroup>
                    <col style="width: 16%;"> <!-- UGT No. -->
                    <col style="width: 24%;"> <!-- Fuel Type -->
                    <col style="width: 22%;"> <!-- Current Stock -->
                    <col style="width: 20%;"> <!-- Reorder Level -->
                    <col style="width: 18%;"> <!-- Status -->
                </colgroup>
                <thead>
                    <tr style="background:#002F70; color:#fff;">
                        <th style="padding:10px 8px; text-align:left;">UGT No.</th>
                        <th style="padding:10px 8px; text-align:left;">Fuel Type</th>
                        <th style="padding:10px 8px; text-align:right;">Current Stock</th>
                        <th style="padding:10px 8px; text-align:right;">Reorder Level</th>
                        <th style="padding:10px 8px; text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($alert_rows as $r):
                    $status = $r['status'] ?? 'Low';
                    $st_label = ($status === 'Low') ? 'Low Fuel' : (($status === 'Critical') ? 'Critical Fuel' : $status);
                    $sc = match($status) {
                        'Critical', 'Out of Stock' => '#dc3545',
                        'Low' => '#ea580c',
                        default => '#64748b'
                    };
                    $ugt_str = 'UGT-' . str_pad($r['tanker_num'], 2, '0', STR_PAD_LEFT);
                ?>
                <tr style="border-bottom:1px solid #e2e8f0; background:<?= $sc ?>08;">
                    <td style="padding:8px 8px; font-weight:800; color:#002F6C; font-size:12px; text-align:left;"><?= htmlspecialchars($ugt_str) ?></td>
                    <td style="padding:8px 8px; font-weight:700; color:#0f172a; font-size:12.5px; text-align:left;"><?= htmlspecialchars($r['fuel_type']) ?></td>
                    <td style="padding:8px 8px; text-align:right; font-weight:800; color:#002F6C; font-size:13.5px;"><?= number_format($r['current_level'], 2) ?> L</td>
                    <td style="padding:8px 8px; text-align:right; color:#b45309; font-weight:800; font-size:13.5px;"><?= number_format($r['reorder_level'] ?? 0, 0) ?> L</td>
                    <td style="padding:8px 8px; text-align:center;">
                        <span class="status-pill" style="background:<?= $sc ?>18; color:<?= $sc ?>; border:1.5px solid <?= $sc ?>50; border-radius:20px; padding:3px 8px; font-size:11px; font-weight:800;">
                            <?= htmlspecialchars($st_label) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>



<!-- â•â• View Fuel Details Modal â•â• -->
<div class="modal-overlay" id="tankModal">
    <div class="modal-box" style="width:640px; max-height:90vh;">
        <div class="modal-header" style="background:#00264D; justify-content:center;">
            <h3 id="tankModalTitle" style="color:#ffffff !important;">View Fuel Details</h3>
        </div>
        <div class="modal-body" style="padding:20px;">
            <!-- UGT Information -->
            <div style="font-size:12px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-info-circle"></i> UGT Information</div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px 24px; margin-bottom:20px;">
                <div><div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">UGT No.</div><div id="detUgtNo" style="font-weight:800; color:#002F70; font-size:13.5px;"></div></div>
                <div><div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Fuel Type</div><div id="detFuelType" style="font-weight:700; color:#0f172a; font-size:13.5px;"></div></div>
                <div><div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Tank Capacity</div><div id="detCapacity" style="font-weight:600; color:#475569; font-size:13px;"></div></div>
                <div><div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Current Volume</div><div id="detVolume" style="font-weight:800; color:#002F70; font-size:14px;"></div></div>
                <div><div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Available Space</div><div id="detAvailableSpace" style="font-weight:700; color:#16a34a; font-size:13.5px;"></div></div>
                <div><div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Status</div><div id="detStatus"></div></div>
                <div style="grid-column:span 2;"><div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Last Updated</div><div id="detUpdated" style="font-weight:600; color:#64748b; font-size:12.5px;"></div></div>
            </div>
            <!-- Fuel Delivery History (Read Only) -->
            <div style="font-size:12px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-truck"></i> Fuel Delivery History (Read Only)</div>
            <div id="detDeliverySummary">
                <div style="text-align:center;padding:16px;color:#94a3b8;font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
            </div>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px; background:#f8fafc; padding:12px 20px;">
            <button type="button" onclick="closeTankModal()" class="btn-cancel" style="height:32px; font-size:12.5px; font-weight:600; padding:0 14px;"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- ══ Delivery Detail Modal ══ -->
<div class="modal-overlay" id="deliveryDetailModal">
    <div class="modal-box" style="width:480px;">
        <div class="modal-header" style="justify-content:center;">
            <h3>Delivery Details</h3>
        </div>
        <div class="modal-body" id="deliveryDetailBody"></div>
        <div class="modal-footer">
            <button onclick="closeDeliveryDetail()" class="btn-cancel" style="height:32px;font-size:12.5px;padding:0 14px;">Close</button>
        </div>
    </div>
</div>

<!-- ══ Fuel Movement Modal ══ -->
<div class="modal-overlay" id="movementModal">
    <div class="modal-box" style="width:750px;">
        <div class="modal-header" style="justify-content:center;">
            <h3 id="movementModalTitle">Fuel Movement History</h3>
        </div>
        <div class="modal-body" style="padding:0;">
            <div style="display:flex; border-bottom:2px solid #e2e8f0; background:#f8fafc; padding:0 10px;">
                <button class="modal-tab-btn active" id="tabDelBtn" onclick="switchMovTab('deliveries')" style="padding:10px 14px; border:none; background:none; font-weight:700; font-size:13px; text-transform:uppercase; color:#002F70; border-bottom:2px solid #002F70; cursor:pointer; display:flex; align-items:center; gap:6px;"><i class="fas fa-truck"></i> Deliveries</button>
                <button class="modal-tab-btn" id="tabSalesBtn" onclick="switchMovTab('sales')" style="padding:10px 14px; border:none; background:none; font-weight:700; font-size:13px; text-transform:uppercase; color:#64748b; border-bottom:2px solid transparent; cursor:pointer; display:flex; align-items:center; gap:6px;"><i class="fas fa-receipt"></i> Sales Transactions</button>
            </div>
            <div style="padding:20px;">
                <!-- Tab: Deliveries -->
                <div id="tabContentDeliveries" style="max-height:300px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px;">
                    <table class="po-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Invoice No.</th>
                                <th>Supplier</th>
                                <th style="text-align:right;">Liters (L)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="movDeliveriesBody"></tbody>
                    </table>
                </div>
                <!-- Tab: Sales -->
                <div id="tabContentSales" style="display:none; max-height:300px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px;">
                    <table class="po-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Shift Period</th>
                                <th style="text-align:right;">Liters Sold (L)</th>
                                <th style="text-align:right;">Total Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="movSalesBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="closeMovementModal()" class="btn-cancel" style="height:32px; font-size:14.5px; padding:0 12px;">Close</button>
        </div>
    </div>
</div>

<!-- ══ FUEL STOCK REQUEST MODAL ══ -->
<div class="sr-modal-overlay" id="fuelSrModal">
    <div class="sr-modal-box" style="max-width:1100px;">
        <div class="sr-modal-head" style="display:flex; justify-content:center; align-items:center;">
            <div class="sr-modal-title">
                <i class="fas fa-gas-pump"></i> Fuel Stock Request
            </div>
        </div>

        <div class="sr-modal-body">
            <style>
                .sr-table {
                    width: 100% !important;
                    min-width: 0 !important;
                    table-layout: fixed !important;
                }
                .sr-table td, .sr-table th {
                    white-space: normal !important;
                    word-wrap: break-word !important;
                    overflow-wrap: break-word !important;
                }
                #fsrCheckList > div {
                    overflow-x: hidden !important;
                }
            </style>
            <div style="display:grid; grid-template-columns:240px minmax(0,1fr); gap:20px;">
                <!-- LEFT COLUMN: Request Information -->
                <div>
                    <div style="background:#f8fafc; padding:14px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:14px;">
                        <h4 style="margin:0 0 10px; font-size:12px; font-weight:700; color:#002F70; text-transform:uppercase; border-bottom:1px solid #cbd5e1; padding-bottom:6px;">
                            <i class="fas fa-file-alt"></i> Request Information
                        </h4>
                        <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size:12.5px;">
                            <span style="color:#64748b; font-weight:600;">Request No:</span>
                            <span style="font-weight:700; color:#1e293b;">Auto-Assigned</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size:12.5px;">
                            <span style="color:#64748b; font-weight:600;">Request Date:</span>
                            <span style="font-weight:700; color:#1e293b;"><?= date('M d, Y') ?></span>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size:12.5px;">
                            <span style="color:#64748b; font-weight:600;">Requested By:</span>
                            <span style="font-weight:700; color:#1e293b;"><?= htmlspecialchars($me['name'] ?? $me['username'] ?? 'Staff') ?></span>
                        </div>
                    </div>

                    <div style="margin-top:12px;">
                        <label style="display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:.3px;">Remarks / Notes</label>
                        <textarea id="fsrRemarks" rows="4" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#334155;resize:vertical;box-sizing:border-box;outline:none;" placeholder="Optional remarks..."></textarea>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Fuel Selection -->
                <div style="display:flex; flex-direction:column; min-width: 0;">
                    <label style="display:block;font-size:12.5px;font-weight:700;color:#374151;margin-bottom:6px;">
                        <i class="fas fa-gas-pump" style="color:#eab308;margin-right:4px;"></i> Fuel Types <span style="color:#dc2626;">*</span>
                    </label>
                    
                    <!-- Select-all bar -->
                    <div class="fsr-select-bar" style="margin-bottom:8px;font-size:12px;">
                        <input type="checkbox" id="fsrSelectAll">
                        <label for="fsrSelectAll" style="cursor:pointer;margin:0;margin-left:8px;font-size:12px;font-weight:600;">Select All</label>
                        <span id="fsrSelectedCount" style="margin-left:auto;color:#002F70;font-size:12px;font-weight:700;"></span>
                    </div>

                    <!-- Fuel list with checkboxes -->
                    <div id="fsrCheckList" style="min-width: 0;"></div>
                </div>
            </div>

            <div id="fsrError" style="display:none;background:#fee2e2;color:#dc3545;padding:8px 12px;border-radius:6px;margin-top:10px;font-size:12px;"></div>
        </div>

        <div class="sr-modal-footer" style="display:flex !important; justify-content:flex-end !important; align-items:center !important; gap:10px !important; padding:12px 20px !important; background:#f8fafc !important; border-top:1px solid #cbd5e1 !important; box-sizing:border-box !important;">
            <button type="button" id="fsrCancelBtn" onclick="closeFuelSrModal()" style="padding:7px 16px !important; border:1.5px solid #00264D !important; background:#ffffff !important; background-color:#ffffff !important; color:#00264D !important; -webkit-text-fill-color:#00264D !important; border-radius:6px !important; font-size:12.5px !important; font-weight:700 !important; cursor:pointer !important; display:inline-flex !important; align-items:center !important; gap:6px !important; opacity:1 !important; visibility:visible !important; height:34px !important;">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" id="fsrSubmitBtn" onclick="fsrHandleSubmit(this)" style="padding:7px 18px !important; background:#002F70 !important; background-color:#002F70 !important; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; border:none !important; border-radius:6px !important; font-size:12.5px !important; font-weight:700 !important; cursor:pointer !important; display:inline-flex !important; align-items:center !important; gap:6px !important; opacity:1 !important; visibility:visible !important; height:34px !important;">
                <i class="fas fa-paper-plane"></i> Submit Stock Request
            </button>
        </div>
    </div>
</div>

<!-- â”€â”€ Success popup â”€â”€ -->
<div id="fsrSuccessOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10998;"></div>
<div id="fsrSuccessPopup" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10999;background:#fff;padding:28px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);text-align:center;">
    <div style="width:56px;height:56px;background:linear-gradient(135deg,#28a745,#20c997);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="fas fa-check" style="color:#fff;font-size:28px;"></i>
    </div>
    <h3 style="margin:0 0 8px;color:#28a745;">Request Submitted!</h3>
    <p style="margin:0 0 18px;color:#333;font-size:14px;line-height:1.5;" id="fsrSuccessMsg">
        Your fuel stock request is now <strong>Pending</strong> Manager review.
    </p>
    <button onclick="closeFsrSuccess()" class="txn-btn primary">OK</button>
</div>

<script>
var allFuelData = <?php echo json_encode($js_fuel); ?>;

// ── Open stock request modal ────────────────────────────────────────────────────────────────────────────────
function openFuelSrModal() {
    renderFsrCheckList();
    syncFsrSelectAll();
    document.getElementById('fsrError').style.display = 'none';
    var rem = document.getElementById('fsrRemarks');
    if (rem) rem.value = '';
    var sb = document.getElementById('fsrSubmitBtn');
    if (sb) { sb.disabled = false; sb.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Stock Request'; }
    document.getElementById('fuelSrModal').classList.add('open');
}

function renderFsrCheckList() {
    var needsRestock = allFuelData.filter(function(it) {
        var s = (it.status || '').toUpperCase();
        return s === 'CRITICAL' || s === 'LOW' || s === 'LOW STOCK' || s === 'OUT OF STOCK';
    });

    if (needsRestock.length === 0) {
        document.getElementById('fsrCheckList').innerHTML =
            '<div style="text-align:center;padding:28px 16px;color:#6c757d;">' +
            '<strong>All fuel tanks are at sufficient levels.</strong><br>' +
            '<small>Stock requests are only needed for Critical, Low, or Out-of-Stock fuels.</small></div>';
        document.getElementById('fsrSubmitBtn').disabled = true;
        return;
    }

    document.getElementById('fsrSubmitBtn').disabled = false;

    var rows = needsRestock.map(function(it) {
        var idx = allFuelData.indexOf(it);
        var badge = '<span style="background:' + it.color + '20;color:' + it.color + ';border:1px solid ' + it.color + '40;border-radius:20px;padding:2px 8px;font-size:11px;font-weight:800;">' + esc(it.status) + '</span>';
        var ugtNo = it.tanker_num ? it.tanker_num : (it.tanker_label || '');
        return '<tr class="fsr-cb-row ' + it.statusCls + '" data-idx="' + idx + '" style="cursor:pointer;font-size:12px;">' +
            '<td style="text-align:center;"><input type="checkbox" class="fsr-cb fsr-item-cb" data-idx="' + idx + '"></td>' +
            '<td style="font-weight:700;color:#002F70;">' + esc(it.name) + '</td>' +
            '<td style="font-family:monospace;font-weight:700;">' + esc(ugtNo) + '</td>' +
            '<td style="text-align:right;font-weight:700;">' + it.level.toLocaleString('en-PH',{minimumFractionDigits:2}) + ' L</td>' +
            '<td style="text-align:right;color:#dc2626;font-weight:700;">' + Number(it.reorder_level || 0).toLocaleString('en-PH',{minimumFractionDigits:0}) + ' L</td>' +
            '<td style="text-align:center;">' + badge + '</td>' +
        '</tr>';
    }).join('');
    var html = '<div style="max-height:360px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;">' +
        '<table class="sr-table" style="width:100%;border-collapse:collapse;font-size:12px;table-layout:fixed;">' +
            '<thead><tr style="background:#002F70;color:#fff;position:sticky;top:0;z-index:5;">' +
                '<th style="width:7%;text-align:center;padding:8px 6px;font-size:11.5px;font-weight:800;text-transform:uppercase;">Select</th>' +
                '<th style="width:24%;text-align:left;padding:8px 6px;font-size:11.5px;font-weight:800;text-transform:uppercase;">Fuel Type</th>' +
                '<th style="width:16%;text-align:left;padding:8px 6px;font-size:11.5px;font-weight:800;text-transform:uppercase;">UGT No.</th>' +
                '<th style="width:19%;text-align:right;padding:8px 6px;font-size:11.5px;font-weight:800;text-transform:uppercase;">Current Liters</th>' +
                '<th style="width:18%;text-align:right;padding:8px 6px;font-size:11.5px;font-weight:800;text-transform:uppercase;">Reorder Level</th>' +
                '<th style="width:16%;text-align:center;padding:8px 6px;font-size:11.5px;font-weight:800;text-transform:uppercase;">Status</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>';
    document.getElementById('fsrCheckList').innerHTML = html;

    document.querySelectorAll('.fsr-item-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var row = this.closest('.fsr-cb-row');
            if (row) {
                row.classList.toggle('checked', this.checked);
            }
            syncFsrSelectAll();
        });
    });

    document.querySelectorAll('.fsr-cb-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            if (e.target && e.target.matches('input')) return;
            var cb = this.querySelector('.fsr-item-cb');
            if (!cb) return;
            cb.checked = !cb.checked;
            this.classList.toggle('checked', cb.checked);
            syncFsrSelectAll();
        });
    });
}

function syncFsrSelectAll() {
    var all     = document.querySelectorAll('.fsr-item-cb');
    var checked = document.querySelectorAll('.fsr-item-cb:checked');
    var sa = document.getElementById('fsrSelectAll');
    if (sa) {
        sa.indeterminate = checked.length > 0 && checked.length < all.length;
        sa.checked       = all.length > 0 && checked.length === all.length;
    }
    var countLabel = document.getElementById('fsrSelectedCount');
    if (countLabel) {
        countLabel.textContent = checked.length > 0 ? checked.length + ' selected' : '';
    }
}

var selectAllEl = document.getElementById('fsrSelectAll');
if (selectAllEl) {
    selectAllEl.addEventListener('change', function() {
        var c = this.checked;
        document.querySelectorAll('.fsr-item-cb').forEach(function(cb) { cb.checked = c; });
        document.querySelectorAll('.fsr-cb-row').forEach(function(row) { row.classList.toggle('checked', c); });
        syncFsrSelectAll();
    });
}

// ── Close stock request modal ──────────────────────────────────────────────────────────────────────────────
function closeFuelSrModal() {
    var m = document.getElementById('fuelSrModal');
    if (m) m.classList.remove('open');
}

// ── Submit stock request ───────────────────────────────────────────────────────────────────────────────────
function submitFuelStockRequest() {
    var checked = document.querySelectorAll('.fsr-item-cb:checked');
    var errEl = document.getElementById('fsrError');
    if (checked.length === 0) {
        errEl.textContent = 'Please select at least one fuel type.';
        errEl.style.display = 'block';
        return;
    }

    var btn = document.getElementById('fsrSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    errEl.style.display = 'none';

    var remarks = ((document.getElementById('fsrRemarks') || {}).value || '').trim() || 'Bulk fuel stock request';

    var items = [];
    checked.forEach(function(cb) {
        var it = allFuelData[parseInt(cb.dataset.idx)];
        if (it) {
            items.push({
                fuel_type:        it.name,
                current_level:    it.level,
                capacity:         it.capacity,
                stock_status:     it.status,
                requested_liters: 0
            });
        }
    });

    fetch('../backend/api/fuel_stock_request.php?action=create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            items: items,
            remarks: remarks
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Stock Request';
        closeFuelSrModal();

        if (res.success) {
            var srNo = res.request_no || '';
            var cnt  = res.inserted_count || items.length;
            var msg  = 'Successfully submitted stock requests for <strong>' + cnt + '</strong> fuel type' + (cnt !== 1 ? 's' : '') + '. Pending Manager Approval.';
            if (srNo) msg += '<br><span style="font-size:14.5px;color:#64748b;">Request No: <strong>' + esc(srNo) + '</strong></span>';
            if (res.message && res.message.indexOf('skipped') !== -1) {
                msg += '<br><small style="color:#d97706;">' + esc(res.message.split('Note:')[1] || '') + '</small>';
            }
            document.getElementById('fsrSuccessMsg').innerHTML = msg;
            try { sessionStorage.setItem('fsrBannerMsg', msg); } catch(e) {}
            showFsrBanner("FUEL STOCK REQUEST SUBMITTED!", msg);
        } else {
            document.getElementById('fsrSuccessMsg').innerHTML =
                '<span style="color:#dc2626;">' + esc(res.message || 'Submission failed. Please try again.') + '</span>';
        }

        document.getElementById('fsrSuccessPopup').style.display = 'block';
        document.getElementById('fsrSuccessOverlay').style.display = 'block';
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        errEl.textContent = 'Network error. Please check your connection and try again.';
        errEl.style.display = 'block';
    });
}

function closeFsrBanner() {
    var b = document.getElementById('fsrGlobalSuccessBanner');
    if (b) {
        b.style.opacity = '0';
        b.style.transform = 'translateX(60px)';
        b.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        setTimeout(function() { b.style.display = 'none'; }, 260);
    }
    try { sessionStorage.removeItem('fsrBannerMsg'); } catch(e) {}
}

function showFsrBanner(title, msg) {
    var banner = document.getElementById('fsrGlobalSuccessBanner');
    var bannerTitle = document.getElementById('fsrBannerTitle');
    var bannerText = document.getElementById('fsrBannerText');
    if (banner) {
        if (bannerTitle && title) bannerTitle.innerText = title;
        if (bannerText && msg) bannerText.innerHTML = msg;
        banner.style.display = 'flex';
        banner.style.opacity = '1';
        banner.style.transform = 'translateX(0)';
        setTimeout(closeFsrBanner, 8000);
    }
}

function closeFsrSuccess() {
    document.getElementById('fsrSuccessPopup').style.display  = 'none';
    document.getElementById('fsrSuccessOverlay').style.display = 'none';
    location.reload();
}

document.addEventListener('DOMContentLoaded', function() {
    try {
        var msg = sessionStorage.getItem('fsrBannerMsg');
        if (msg) {
            sessionStorage.removeItem('fsrBannerMsg');
            showFsrBanner("FUEL STOCK REQUEST SUBMITTED!", msg);
        }
    } catch(e) {}
});

function esc(str) { var d = document.createElement('div'); d.appendChild(document.createTextNode(str)); return d.innerHTML; }

// ── Filter Table Logic ──────────────────────────────────────────────────────────
function fuelFilterValue(id) {
    var el = document.getElementById(id);
    return el ? String(el.value || '').toLowerCase().trim() : '';
}

function refreshFuelPagination() {
    if (window.tablePaginationTriggers && typeof window.tablePaginationTriggers.fuelTable === 'function') {
        window.tablePaginationTriggers.fuelTable();
    }
}

function filterFuelTable() {
    var search   = fuelFilterValue('sq');
    var fuelType = fuelFilterValue('cf');
    var status   = fuelFilterValue('sf');
    var rows = document.querySelectorAll('#fuelTable tbody tr.fuel-row');
    var visibleCount = 0;

    rows.forEach(function(row) {
        var rTankNum  = (row.dataset.tankNum  || '').toLowerCase();
        var rUgtNo    = (row.dataset.ugtNo    || '').toLowerCase();
        var rFuelType = (row.dataset.fuelType || '').toLowerCase();
        var rStatus   = (row.dataset.status   || '').toLowerCase();
        var match = true;

        if (search && rFuelType.indexOf(search) === -1 && rUgtNo.indexOf(search) === -1 && rTankNum.indexOf(search) === -1) match = false;
        if (fuelType && rFuelType.indexOf(fuelType) === -1) match = false;
        if (status && rStatus.trim() !== status.trim()) match = false;

        row.classList.toggle('search-hidden', !match);
        row.style.display = match ? '' : 'none';
        if (match) visibleCount++;
    });

    var noResultsRow = document.getElementById('fuelNoResultsRow');
    if (noResultsRow) {
        noResultsRow.style.display = rows.length > 0 && visibleCount === 0 ? '' : 'none';
    }

    refreshFuelPagination();
}

function applyFuelInventoryFilters(e) {
    if (e) e.preventDefault();
    filterFuelTable();
    return false;
}

function resetFuelInventoryFilters() {
    ['sq', 'cf', 'sf'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.value = '';
            el.dispatchEvent(new Event('change'));
        }
    });
    filterFuelTable();
}


// ── Sub Tab Switcher ─────────────────────────────────────────────────────────────────────────────────────────
function switchFuelTab(tab) {
    var overview = document.getElementById('section-tank-overview');
    var alerts   = document.getElementById('section-fuel-deliveries');

    var btnOv  = document.getElementById('tabOverview');
    var btnAlt = document.getElementById('tabDeliveries');

    [btnOv, btnAlt].forEach(function(b) {
        if (!b) return;
        b.classList.remove('active');
    });
    [overview, alerts].forEach(function(s) {
        if (!s) return;
        s.style.display = 'none';
    });

    var activeBtn = (tab === 'overview') ? btnOv : btnAlt;
    var activeSec = (tab === 'overview') ? overview : alerts;

    if (activeSec) activeSec.style.display = '';
    if (activeBtn) activeBtn.classList.add('active');
}


// ── Tank Details Modal ───────────────────────────────────────────────────────────────────────────────────────
var _currentTankData = null;
function openTankModal(r) {
    _currentTankData = r;
    var ugtName = r.ugt_no || ('UGT #' + r.tanker_num);
    var ugtTitle = r.fuel_type ? (ugtName + ' — ' + r.fuel_type) : ugtName;
    if (document.getElementById('tankModalTitle')) document.getElementById('tankModalTitle').textContent = 'View Fuel Details — ' + ugtTitle;
    if (document.getElementById('detUgtNo')) document.getElementById('detUgtNo').textContent = ugtName;
    if (document.getElementById('detFuelType')) document.getElementById('detFuelType').textContent = r.fuel_type || '—';
    if (document.getElementById('detCapacity')) document.getElementById('detCapacity').textContent = Number(r.capacity || 0).toLocaleString() + ' L';
    if (document.getElementById('detVolume')) document.getElementById('detVolume').textContent = Number(r.current_level || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
    var availSpace = Math.max(0, (r.capacity || 0) - (r.current_level || 0));
    if (document.getElementById('detAvailableSpace')) document.getElementById('detAvailableSpace').textContent = Number(availSpace).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
    
    var stLabel = (r.status === 'Low') ? 'Low Fuel' : ((r.status === 'Critical') ? 'Critical Fuel' : (r.status || 'Normal'));
    var statusSpan = '<span class="status-pill" style="background:' + (r.status_color || '#002F70') + '18; color:' + (r.status_color || '#002F70') + '; border:1px solid ' + (r.status_color || '#002F70') + '40;">' + esc(stLabel) + '</span>';
    if (document.getElementById('detStatus')) document.getElementById('detStatus').innerHTML = statusSpan;
    
    var ts = r.timestamp ? new Date(r.timestamp).toLocaleString() : '—';
    if (document.getElementById('detUpdated')) document.getElementById('detUpdated').textContent = ts;

    // Delivery History fetch
    var dSummary = document.getElementById('detDeliverySummary');
    if (dSummary) {
        dSummary.innerHTML = '<div style="text-align:center;padding:12px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading delivery history...</div>';
        fetch('staff_inventory_fuel.php?ajax=1&action=get_fuel_details&fuel_type=' + encodeURIComponent(r.fuel_type || ''))
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.deliveries || data.deliveries.length === 0) {
                dSummary.innerHTML = '<div style="text-align:center;padding:12px;color:#94a3b8;font-size:12.5px;">No delivery records found for this fuel type.</div>';
                return;
            }
            var html = '<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:#f1f5f9;">' +
                '<th style="padding:6px 8px;text-align:left;color:#475569;font-size:11px;font-weight:800;text-transform:uppercase;">Delivery No.</th>' +
                '<th style="padding:6px 8px;text-align:left;color:#475569;font-size:11px;font-weight:800;text-transform:uppercase;">Supplier</th>' +
                '<th style="padding:6px 8px;text-align:right;color:#475569;font-size:11px;font-weight:800;text-transform:uppercase;">Liters Received</th>' +
                '<th style="padding:6px 8px;text-align:center;color:#475569;font-size:11px;font-weight:800;text-transform:uppercase;">Delivery Date</th>' +
                '</tr></thead><tbody>';
            data.deliveries.slice(0, 10).forEach(function(d) {
                var dNo = d.invoice_no || ('DEL-' + String(d.id || 0).padStart(5, '0'));
                var dateStr = d.delivery_date ? new Date(d.delivery_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—';
                html += '<tr style="border-bottom:1px solid #f1f5f9;">' +
                    '<td style="padding:6px 8px;font-weight:700;color:#002F70;"><code style="font-size:11.5px;">' + esc(dNo) + '</code></td>' +
                    '<td style="padding:6px 8px;font-weight:600;">' + esc(d.supplier || 'Petron Corporation') + '</td>' +
                    '<td style="padding:6px 8px;text-align:right;font-weight:700;color:#16a34a;font-size:12.5px;">' + Number(d.delivery_liters || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</td>' +
                    '<td style="padding:6px 8px;text-align:center;color:#64748b;">' + dateStr + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
            dSummary.innerHTML = html;
        })
        .catch(function() {
            dSummary.innerHTML = '<div style="text-align:center;padding:12px;color:#dc3545;font-size:12.5px;">Failed to load delivery history.</div>';
        });
    }
    
    document.getElementById('tankModal').classList.add('open');
}

function closeTankModal() {
    document.getElementById('tankModal').classList.remove('open');
}

// ── Fuel Movement Modal ──────────────────────────────────────────────────────────────────────────────────────
var currentMovTab = 'deliveries';
function viewFuelMovement(fuelType, tankName) {
    document.getElementById('movementModalTitle').textContent = 'Movement History — ' + tankName + ' (' + fuelType + ')';
    
    var delBody = document.getElementById('movDeliveriesBody');
    var salesBody = document.getElementById('movSalesBody');
    
    delBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:24px; color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading deliveries...</td></tr>';
    salesBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:24px; color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading sales...</td></tr>';
    
    document.getElementById('movementModal').classList.add('open');
    switchMovTab('deliveries');

    fetch('staff_inventory_fuel.php?ajax=1&action=get_fuel_details&fuel_type=' + encodeURIComponent(fuelType))
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (!res.success) {
            delBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#dc3545;">Failed to load data.</td></tr>';
            salesBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#dc3545;">Failed to load data.</td></tr>';
            return;
        }

        // Render Deliveries
        if (res.deliveries.length === 0) {
            delBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:12px; color:#94a3b8;">No recent deliveries recorded.</td></tr>';
        } else {
            var delHtml = '';
            res.deliveries.forEach(function(d) {
                var dateStr = d.delivery_date ? new Date(d.delivery_date).toLocaleDateString() : '—';
                var statusCls = d.status === 'Verified' ? 'background:#e6f4ea; color:#28a745; border:1px solid #c3e6cb;' : 'background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;';
                delHtml += '<tr>' +
                    '<td>' + dateStr + '</td>' +
                    '<td><code>' + esc(d.invoice_no || '—') + '</code></td>' +
                    '<td>' + esc(d.supplier || '—') + '</td>' +
                    '<td style="text-align:right; font-weight:700; color:#002F70;">' + Number(d.delivery_liters).toLocaleString() + ' Liters (L)</td>' +
                    '<td><span style="font-size:15.5px; font-weight:700; padding:2px 6px; border-radius:4px;' + statusCls + '">' + esc(d.status) + '</span></td>' +
                    '</tr>';
            });
            delBody.innerHTML = delHtml;
        }

        // Render Sales
        if (res.transactions.length === 0) {
            salesBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:12px; color:#94a3b8;">No recent sales transactions.</td></tr>';
        } else {
            var salesHtml = '';
            res.transactions.forEach(function(t) {
                var dateStr = t.transaction_date ? new Date(t.transaction_date).toLocaleDateString() + ' ' + new Date(t.transaction_date).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', hour12: true}) : '—';
                var statusCls = t.status === 'Verified' ? 'background:#e6f4ea; color:#28a745; border:1px solid #c3e6cb;' : 'background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;';
                salesHtml += '<tr>' +
                    '<td>' + dateStr + '</td>' +
                    '<td>' + esc(t.shift_period || '—') + '</td>' +
                    '<td style="text-align:right; font-weight:700; color:#002F70;">' + Number(t.liters_sold).toLocaleString() + ' Liters (L)</td>' +
                    '<td style="text-align:right; font-weight:600;">₱' + Number(t.total_amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</td>' +
                    '<td><span style="font-size:15.5px; font-weight:700; padding:2px 6px; border-radius:4px;' + statusCls + '">' + esc(t.status) + '</span></td>' +
                    '</tr>';
            });
            salesBody.innerHTML = salesHtml;
        }
    })
    .catch(function() {
        delBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#dc3545;">Connection error.</td></tr>';
        salesBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#dc3545;">Connection error.</td></tr>';
    });
}

function closeMovementModal() {
    document.getElementById('movementModal').classList.remove('open');
}

function switchMovTab(tab) {
    currentMovTab = tab;
    var delBtn = document.getElementById('tabDelBtn');
    var salesBtn = document.getElementById('tabSalesBtn');
    var delContent = document.getElementById('tabContentDeliveries');
    var salesContent = document.getElementById('tabContentSales');

    if (tab === 'deliveries') {
        delBtn.classList.add('active');
        salesBtn.classList.remove('active');
        delContent.style.display = 'block';
        salesContent.style.display = 'none';
    } else {
        salesBtn.classList.add('active');
        delBtn.classList.remove('active');
        salesContent.style.display = 'block';
        delContent.style.display = 'none';
    }
}

// ── Print Tank Record ────────────────────────────────────────────────────────────────────────────────────────
function printTankRecord(r) {
    var win = window.open('', '_blank');
    var ts = r.timestamp ? new Date(r.timestamp).toLocaleString() : '—';
    var fill = Math.min(100, Math.max(0, Math.round(r.fill_pct, 1)));
    
    var html = '<html><head><title>Tank Report - ' + r.label + '</title>';
    html += '<style>';
    html += 'body { font-family: Arial, sans-serif; margin: 40px; color: #333; }';
    html += '.header { border-bottom: 2px solid #002F6C; padding-bottom: 10px; margin-bottom: 20px; }';
    html += 'h2 { color: #002F6C; margin: 0; }';
    html += '.meta { font-size: 14.5px; color: #666; margin-top: 5px; }';
    html += 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    html += 'th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }';
    html += 'th { background-color: #f8f9fa; color: #002F6C; font-weight: bold; }';
    html += '.status { font-weight: bold; }';
    html += '</style></head><body>';
    
    html += '<div class="header">';
    html += '<h2>PETRON TANK REPORT</h2>';
    html += '<div class="meta">Generated on: ' + new Date().toLocaleString() + '</div>';
    html += '</div>';
    
    html += '<table>';
    html += '<tr><td><strong>Tank Number</strong></td><td>' + r.tanker_num + '</td></tr>';
    html += '<tr><td><strong>Tank Reference</strong></td><td>' + r.label + '</td></tr>';
    html += '<tr><td><strong>Fuel Type</strong></td><td>' + r.fuel_type + '</td></tr>';
    html += '<tr><td><strong>Capacity</strong></td><td>' + Number(r.capacity).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Latest Reading</strong></td><td>' + Number(r.ending_reading).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Beginning Reading</strong></td><td>' + Number(r.beginning_reading).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Ending Reading</strong></td><td>' + Number(r.ending_reading).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Calibration</strong></td><td>' + Number(r.calibration).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Total Dispensed</strong></td><td>' + Number(r.total_dispensed).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Remaining Fuel</strong></td><td>' + Number(r.current_level).toLocaleString() + ' L</td></tr>';
    html += '<tr><td><strong>Available %</strong></td><td>' + fill + '%</td></tr>';
    html += '<tr><td><strong>Status</strong></td><td class="status">' + r.status + '</td></tr>';
    html += '<tr><td><strong>Last Updated</strong></td><td>' + ts + '</td></tr>';
    html += '</table>';
    
    html += '</body></html>';
    
    win.document.write(html);
    win.document.close();
    win.print();
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

        if (select.id === 'cf') {
            wrap.style.minWidth = '160px';
        } else if (select.id === 'sf') {
            wrap.style.minWidth = '145px';
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
        select.style.display = 'none';
        select.hidden = true;
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

document.addEventListener('DOMContentLoaded', function() {
    // Move modals to body to avoid z-index and stacking context issues
    ['tankModal', 'movementModal', 'fuelSrModal', 'fsrSuccessOverlay', 'fsrSuccessPopup'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });

    // Wire modal buttons AFTER elements are moved to body
    var closeBtn   = document.getElementById('fuelSrClose');
    var cancelBtn  = document.getElementById('fsrCancelBtn');
    var submitBtn  = document.getElementById('fsrSubmitBtn');
    var overlay    = document.getElementById('fuelSrModal');
    var selectAll  = document.getElementById('fsrSelectAll');

    if (closeBtn)  closeBtn.addEventListener('click',  closeFuelSrModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeFuelSrModal);
    if (overlay)   overlay.addEventListener('click', function(e) {
        if (e.target === this) closeFuelSrModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeFuelSrModal();
    });

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var c = this.checked;
            document.querySelectorAll('.fsr-item-cb').forEach(function(cb) { cb.checked = c; });
            document.querySelectorAll('.fsr-cb-row').forEach(function(row) { row.classList.toggle('checked', c); });
            syncFsrSelectAll();
        });
    }

    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            submitFuelStockRequest();
        });
    }

    var filterForm = document.getElementById('fuelFilterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', applyFuelInventoryFilters);
    }

    [['sq', 'input'], ['cf', 'change'], ['sf', 'change'], ['df', 'change']].forEach(function(item) {
        var el = document.getElementById(item[0]);
        if (el) {
            el.disabled = false;
            el.addEventListener(item[1], filterFuelTable);
        }
    });

    if (typeof setupPetronDownwardDropdowns === 'function') {
        setupPetronDownwardDropdowns(['#cf', '#sf']);
    }

    if (typeof setupTablePagination === 'function') {
        setupTablePagination('fuelTable', 'fuelRowsLimit', 'fuelPagination', 20);
    }
    filterFuelTable();

    // ── 15-SECOND SILENT BACKGROUND AUTO-FETCH FOR FUEL INVENTORY ──────────
    function autoRefreshFuelInventory() {
        // Do not refresh if user has any modal open or is typing
        var modals = ['tankModal', 'movementModal', 'fuelSrModal', 'fsrSuccessOverlay', 'fsrSuccessPopup'];
        for (var i = 0; i < modals.length; i++) {
            var el = document.getElementById(modals[i]);
            if (el && (el.classList.contains('open') || el.style.display === 'flex' || el.style.display === 'block')) return;
        }
        var searchInput = document.getElementById('sq');
        if (searchInput && searchInput === document.activeElement && searchInput.value.trim() !== '') return;

        // Fetch fresh HTML in background and update table & KPI cards seamlessly
        fetch(window.location.href, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        })
        .then(function(res) { return res.text(); })
        .then(function(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            
            // Update table body
            var newTbody = doc.querySelector('#fuelTable tbody') || doc.querySelector('table tbody');
            var curTbody = document.querySelector('#fuelTable tbody') || document.querySelector('table tbody');
            if (newTbody && curTbody) {
                curTbody.innerHTML = newTbody.innerHTML;
                if (typeof filterFuelTable === 'function') filterFuelTable();
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
            console.warn('Fuel inventory auto-fetch notice:', e);
        });
    }
    setInterval(autoRefreshFuelInventory, 10000);

    // Auto-switch tab if passed in URL (e.g. ?tab=overview or ?tab=deliveries)
    var urlParams = new URLSearchParams(window.location.search);
    var targetTab = urlParams.get('tab');
    if (targetTab && typeof window.switchFuelTab === 'function') {
        window.switchFuelTab(targetTab);
    }

    // Auto-open fuel stock request modal if triggered from URL
    if (urlParams.get('stock_request') === '1' || urlParams.get('open_sr') === '1' || urlParams.get('action') === 'request' || window.location.hash === '#stock_request') {
        setTimeout(function() {
            if (typeof openFuelSrModal === 'function') openFuelSrModal();
        }, 200);
    }
});

// â”€â”€ Initialize page â”€â”€
</script>
</div> <!-- /stock-page -->
<?php include __DIR__ . '/../partials/footer.php'; ?>

