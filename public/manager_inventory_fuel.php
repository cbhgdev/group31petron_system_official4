<?php
$page_id = 'mgr_inv_fuel';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// â”€â”€ Module gate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// ── Module gate ────────────────────────────────────────────────────────
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('inventory')) {
    render_module_disabled_page('Inventory');
}

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

$TANK_CONFIG_17 = get_tank_config((int)$station_id);

// ── AJAX Handler ────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && ($_GET['action'] ?? '') === 'get_fuel_details') {
    header('Content-Type: application/json');
    $fuel_type = trim($_GET['fuel_type'] ?? '');
    
    $aliases = [$fuel_type];
    $ft_lower = strtolower($fuel_type);
    if (strpos($ft_lower, 'diesel') !== false && strpos($ft_lower, 'turbo') === false) {
        $aliases[] = 'diesel 1'; $aliases[] = 'diesel 2'; $aliases[] = 'diesel';
    }
    if (strpos($ft_lower, 'xtra') !== false || strpos($ft_lower, 'advance') !== false || strpos($ft_lower, 'unl') !== false) {
        $aliases[] = 'xtra advance'; $aliases[] = 'xtra unl'; $aliases[] = 'xtra unl 1'; $aliases[] = 'xtra unl 2';
    }
    $aliases = array_values(array_unique($aliases));
    $placeholders = implode(',', array_fill(0, count($aliases), '?'));

    $deliveries = [];
    try {
        $del_params = array_merge([$station_id], $aliases);
        $stmt = $pdo->prepare("
            SELECT fd.id,
                   fd.delivery_date,
                   fd.delivery_liters,
                   fd.invoice_no,
                   fd.supplier,
                   COALESCE(u.name, fd.received_by, 'Edgar Eslit') AS received_by,
                   65.50 AS cost_per_liter,
                   fd.status
            FROM fuel_deliveries fd
            LEFT JOIN users u ON (fd.received_by = CAST(u.id AS CHAR) OR fd.received_by = u.username OR fd.received_by = u.name)
            WHERE fd.station_id = ?
              AND (LOWER(fd.fuel_type) IN ($placeholders) OR LOWER(fd.fuel_type) LIKE ?)
            ORDER BY fd.delivery_date DESC, fd.id DESC LIMIT 20
        ");
        $del_params[] = '%' . strtolower(explode(' ', $fuel_type)[0]) . '%';
        $stmt->execute($del_params);
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $transactions = [];
    try {
        $txn_params = array_merge([$station_id], $aliases);
        $stmt = $pdo->prepare("
            SELECT ft.id,
                   ft.transaction_date,
                   ft.liters_sold,
                   COALESCE(ft.calibration, 0) AS calibration,
                   ft.status,
                   COALESCE(u1.name, u2.name, 'Yyeng C.') AS staff_name,
                   DATE(ft.transaction_date) AS tx_date
            FROM fuel_transactions ft
            LEFT JOIN users u1 ON ft.staff_id = u1.id
            LEFT JOIN users u2 ON ft.validated_by = u2.id
            WHERE ft.station_id = ?
              AND (LOWER(ft.fuel_type) IN ($placeholders) OR LOWER(ft.fuel_type) LIKE ?)
            ORDER BY ft.transaction_date DESC, ft.id DESC LIMIT 20
        ");
        $txn_params[] = '%' . strtolower(explode(' ', $fuel_type)[0]) . '%';
        $stmt->execute($txn_params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch deliveries grouped by date for this fuel type
        $del_by_date = [];
        $stmt = $pdo->prepare("
            SELECT DATE(delivery_date) AS d_date, SUM(delivery_liters) AS tot_del
            FROM fuel_deliveries
            WHERE station_id = ?
              AND (LOWER(fuel_type) IN ($placeholders) OR LOWER(fuel_type) LIKE ?)
            GROUP BY DATE(delivery_date)
        ");
        $stmt->execute(array_merge([$station_id], $aliases, ['%' . strtolower(explode(' ', $fuel_type)[0]) . '%']));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $del_by_date[$r['d_date']] = (float)$r['tot_del'];
        }

        // Get current level of this tank for audit calculation
        $stmt = $pdo->prepare("SELECT current_level, capacity FROM fuel_inventory WHERE station_id = ? AND (LOWER(fuel_type) IN ($placeholders) OR LOWER(fuel_type) LIKE ?) LIMIT 1");
        $stmt->execute(array_merge([$station_id], $aliases, ['%' . strtolower(explode(' ', $fuel_type)[0]) . '%']));
        $fi_rec = $stmt->fetch(PDO::FETCH_ASSOC);
        $cur_vol = $fi_rec ? (float)$fi_rec['current_level'] : 500.0;

        foreach ($transactions as &$t) {
            $d_date = $t['tx_date'];
            $del = $del_by_date[$d_date] ?? 0.0;
            $t['delivered'] = $del;
            
            $dispensed = (float)$t['liters_sold'];
            $calib = (float)$t['calibration'];
            
            $t['ending_volume'] = $cur_vol;
            $t['beginning_volume'] = max(0, $cur_vol + $dispensed + $calib - $del);
            $cur_vol = $t['beginning_volume'];
        }
        unset($t);
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'deliveries' => $deliveries,
        'transactions' => $transactions
    ]);
    exit;
}

// ———————————————————————————————————————————————— Fetch DB data for current calculations ——————————————————————————————
$fi_raw = [];
$fi_lookup = [];
try {
    if (function_exists('ensure_fuel_inventory_synced')) {
        ensure_fuel_inventory_synced($pdo, (int)$station_id);
    }
    $s = $pdo->prepare("SELECT id, fuel_type_id, fuel_type, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, reorder_level, COALESCE(ugt_no,'') AS ugt_no FROM fuel_inventory WHERE station_id = ? AND LOWER(COALESCE(status,'active')) NOT IN ('archived', 'deleted') ORDER BY CAST(REGEXP_REPLACE(COALESCE(ugt_no,'0'), '[^0-9]', '') AS UNSIGNED) ASC, id ASC");
    $s->execute([$station_id]);
    $fi_raw = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fi_raw as $row) {
        $key = strtolower(trim($row['fuel_type']));
        $val = (float)($row['current_level'] ?? $row['current_stock'] ?? 0);
        if (!isset($fi_lookup[$key]) || $val > 0) {
            $fi_lookup[$key] = $row;
        }
    }
} catch (Exception $e) {}

$del_lookup = [];
try {
    $s = $pdo->prepare("SELECT tank_assigned, fuel_type, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id = ? AND DATE(delivery_date) = CURDATE() AND status = 'Verified' GROUP BY tank_assigned, fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];
    }
} catch (Exception $e) {}

$sales_lookup = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'Verified' GROUP BY fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];
    }
} catch (Exception $e) {}

$adj_lookup = [];
try {
    // NOTE: Stock-In approvals, tank-dip calibrations and approved adjustments ALREADY write to fuel_inventory.current_level.
    // Summing fuel_adjustments again (incl. Pending + 'stock_in' log rows) double-counted them => 0.00 L after approved Stock-In. Hence AND 1=0.
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id = fi.fuel_type_id AND fi.station_id = fa.station_id WHERE fa.station_id = ? AND 1 = 0 AND DATE(fa.adjustment_date) = CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];
    }
} catch (Exception $e) {}

// ———————————————————————————————————————————————— Price source: fuel_inventory.price_per_liter (authoritative — same as Meter Reading form) ──
$price_lookup = [];
try {
    $s = $pdo->prepare("SELECT LOWER(TRIM(fuel_type)) AS ft_key, price_per_liter FROM fuel_inventory WHERE station_id = ?");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['ft_key'];
        if (!isset($price_lookup[$key])) $price_lookup[$key] = (float)$row['price_per_liter'];
    }
} catch (Exception $e) {}

// ———————————————————————————————————————————————— Process tanks dataset ———————————————————————————————————————————————
$rows = [];
$total_fuel_volume = 0;

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
            if ($cand && isset($fi_lookup[$cand]) && (float)($fi_lookup[$cand]['current_level'] ?? 0) > 0) { $ft_key = $cand; }
            else { $ft_key = 'xtra unl'; }
        } elseif ($ft_key === 'diesel') {
            $cand = '';
            if (strpos(strtolower($tc['label']), '1') !== false) { $cand = 'diesel 1'; }
            elseif (strpos(strtolower($tc['label']), '2') !== false) { $cand = 'diesel 2'; }
            if ($cand && isset($fi_lookup[$cand]) && (float)($fi_lookup[$cand]['current_level'] ?? 0) > 0) { $ft_key = $cand; }
            else { $ft_key = 'diesel'; }
        }
        $inv = $fi_lookup[$ft_key] ?? null;
    }

    if (!$inv || ((float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) <= 0)) {
        $base_key = strtolower(explode(' ', $tc['fuel_type'])[0]);
        if (isset($fi_lookup[$base_key]) && (float)($fi_lookup[$base_key]['current_level'] ?? $fi_lookup[$base_key]['current_stock'] ?? 0) > 0) {
            $inv = $fi_lookup[$base_key];
        }
    }

    $capacity  = (float)$tc['capacity'];
    $raw_level = $inv ? (float)($inv['current_level'] ?? $inv['current_stock'] ?? 0) : 0;
    $cur_level = $raw_level > 0 ? min($raw_level, $capacity) : 0;


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
    $tank_key  = strtolower($ugt_str);
    $purchases = $del_lookup[$tank_key] ?? 0;

    $sales_total = $sales_lookup[$ft_key] ?? 0;
    $adj_total   = $adj_lookup[$ft_key] ?? 0;
    $sales       = $same_type_count > 0 ? round($sales_total / $same_type_count, 2) : 0;
    $calibration = $same_type_count > 0 ? round($adj_total / $same_type_count, 2) : 0;

    $beginning = $cur_level;
    $total_available = $beginning + $purchases;
    $ending_system   = min(max(0, $total_available - $sales - $calibration), $capacity);

    $remaining_capacity = max(0, $capacity - $ending_system);
    $total_fuel_volume += $ending_system;

    // Thresholds — from DB tank config (reorder_level / critical_level)
    $critical_lvl = (float)($tc['critical_level'] ?? 0);
    $low_lvl      = (float)($tc['reorder_level']  ?? 0);
    if ($critical_lvl <= 0) $critical_lvl = $capacity > 0 ? $capacity * 0.15 : 0;
    if ($low_lvl <= 0)      $low_lvl      = $capacity > 0 ? $capacity * 0.30 : 0;

    // fill_pct = actual proportion (not capped at 100)
    $fill_pct = $capacity > 0 ? round(($ending_system / $capacity) * 100, 2) : 0;

    // Check if tank is deactivated in fuel_inventory
    $inv_status_lower = strtolower(trim($inv['status'] ?? 'active'));
    $is_deactivated = in_array($inv_status_lower, ['inactive', 'deactivated', 'disabled'], true);

    if ($is_deactivated) {
        $status = 'Deactivated'; $sc = '#dc3545';
    } elseif ($ending_system <= 0) {
        $status = 'Out of Stock'; $sc = '#dc3545';
    } elseif ($ending_system <= $critical_lvl) {
        $status = 'Critical';    $sc = '#dc3545';
    } elseif ($ending_system <= $low_lvl) {
        $status = 'Low';         $sc = '#fd7e14';
    } else {
        $status = 'Normal';      $sc = '#28a745';
    }

    $price = $price_lookup[$ft_key] ?? ($inv ? (float)($inv['price_per_liter'] ?? 0) : 0);
    $timestamp = $inv['last_updated'] ?? null;

    $ugt_formatted = 'UGT-' . str_pad($tc['tanker_num'], 2, '0', STR_PAD_LEFT);

    $rows[] = [
        'tank_id'            => $tc['tanker_num'],
        'ugt_no'             => $ugt_formatted,
        'tank_name'          => $ugt_formatted,
        'tank_label'         => $tc['label'],
        'tank_description'   => $tc['tank'],
        'fuel_type'          => $tc['fuel_type'],
        'capacity'           => $capacity,
        'current_volume'     => $ending_system,
        'fill_pct'           => $fill_pct,
        'remaining_capacity' => $remaining_capacity,
        'status'             => $status,
        'status_color'       => $sc,
        'last_updated'       => $timestamp,
        'price'              => $price,
        'reorder_level'      => $low_lvl,
        'inv_id'             => $inv['id'] ?? null
    ];
}

// ── Append any additional fuel products from fuel_inventory not covered by TANK_CONFIG_17 ──
$seen_inv_ids = array_filter(array_column($rows, 'inv_id'));
foreach ($fi_raw as $row) {
    $r_id = (int)$row['id'];
    if (in_array($r_id, $seen_inv_ids, true)) continue;

    $cap   = (float)($row['capacity'] ?? 14000);
    $cur_s = (float)($row['current_level'] ?? $row['current_stock'] ?? 0);
    $crit  = (float)($row['critical_level'] ?? ($cap * 0.15));
    $reord = (float)($row['reorder_level'] ?? ($cap * 0.30));

    $fp_inv_status  = strtolower(trim($row['status'] ?? 'active'));
    $fp_deactivated = in_array($fp_inv_status, ['inactive', 'deactivated', 'disabled'], true);

    $st = 'Normal'; $sc_e = '#28a745';
    if ($fp_deactivated) {
        $st = 'Deactivated'; $sc_e = '#dc3545';
    } elseif ($cur_s <= 0) {
        $st = 'Out of Stock'; $sc_e = '#dc3545';
    } elseif ($cur_s <= $crit) {
        $st = 'Critical'; $sc_e = '#dc3545';
    } elseif ($cur_s <= $reord) {
        $st = 'Low'; $sc_e = '#fd7e14';
    }

    $fp_fill_pct = $cap > 0 ? round(($cur_s / $cap) * 100, 2) : 0;
    $fp_rem_cap  = max(0, $cap - $cur_s);
    $numOnly     = (int)preg_replace('/[^0-9]/', '', $row['ugt_no'] ?? '') ?: (count($rows) + 1);
    $fp_ugt      = !empty($row['ugt_no']) ? $row['ugt_no'] : ('UGT #' . $numOnly);
    $fp_price    = (float)($row['price_per_liter'] ?? 0);

    if (!$fp_deactivated) {
        $total_fuel_volume += $cur_s;
    }

    $rows[] = [
        'tank_id'            => $numOnly,
        'ugt_no'             => $fp_ugt,
        'tank_name'          => $fp_ugt,
        'tank_label'         => $fp_ugt,
        'tank_description'   => $fp_ugt,
        'fuel_type'          => $row['fuel_type'],
        'capacity'           => $cap,
        'current_volume'     => $cur_s,
        'fill_pct'           => $fp_fill_pct,
        'remaining_capacity' => $fp_rem_cap,
        'status'             => $st,
        'status_color'       => $sc_e,
        'last_updated'       => $row['last_updated'] ?? null,
        'price'              => $fp_price,
        'reorder_level'      => $reord,
        'inv_id'             => $r_id
    ];
    $seen_inv_ids[] = $r_id;
}

// â”€â”€ Summary Metrics â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$diesel_available    = 0;
$premium_available   = 0;
$regular_available   = 0;
$low_fuel_types      = 0;
$critical_fuel_types = 0;

foreach ($rows as $r) {
    if ($r['status'] === 'Deactivated') continue; // skip deactivated in metrics
    $ft = strtolower(trim($r['fuel_type']));
    $vol = (float)$r['current_volume'];
    if (strpos($ft, 'diesel') !== false || strpos($ft, 'kerosene') !== false) {
        $diesel_available += $vol;
    } elseif (strpos($ft, 'xcs') !== false || strpos($ft, 'premium') !== false) {
        $premium_available += $vol;
    } else {
        $regular_available += $vol;
    }
    if ($r['status'] === 'Low') {
        $low_fuel_types++;
    } elseif (in_array($r['status'], ['Critical', 'Out of Stock'])) {
        $critical_fuel_types++;
    }
}

$active_tab = $_GET['tab'] ?? 'overview';
if (!in_array($active_tab, ['overview', 'movement', 'movements', 'deliveries', 'readings', 'alerts'])) {
    $active_tab = 'overview';
}

$alert_rows = [];
foreach ($rows as $r) {
    if (in_array($r['status'], ['Low', 'Critical', 'Out of Stock'])) {
        $ft_key = strtolower(trim($r['fuel_type']));
        if ($ft_key === 'xtra unl' || $ft_key === 'xtr advance') {
            $cand = '';
            if (strpos(strtolower($r['tank_name'] ?? ''), '1') !== false) { $cand = 'xtra unl 1'; }
            elseif (strpos(strtolower($r['tank_name'] ?? ''), '2') !== false) { $cand = 'xtra unl 2'; }
            if ($cand && isset($fi_lookup[$cand])) { $ft_key = $cand; }
            else { $ft_key = 'xtra unl'; }
        } elseif ($ft_key === 'diesel') {
            $cand = '';
            if (strpos(strtolower($r['label'] ?? ''), '1') !== false) { $cand = 'diesel 1'; }
            elseif (strpos(strtolower($r['label'] ?? ''), '2') !== false) { $cand = 'diesel 2'; }
            if ($cand && isset($fi_lookup[$cand])) { $ft_key = $cand; }
            else { $ft_key = 'diesel'; }
        }
        $inv = $fi_lookup[$ft_key] ?? null;
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
        
        $reorder_level_per_tank = $inv ? (float)$inv['reorder_level'] / max(1, $same_type_count) : 0.25 * $r['capacity'];
        
        if ($r['status'] === 'Out of Stock' || $r['current_volume'] <= 0) {
            $alert_type = 'Empty Tank';
            $recommended_action = 'Urgent Refill Required - Tank Empty';
        } elseif ($r['status'] === 'Critical') {
            $alert_type = 'Critical Fuel';
            $recommended_action = 'Critical Depletion Warning - Refill Immediately';
        } else {
            $alert_type = 'Low Fuel';
            $recommended_action = 'Initiate Delivery Request';
        }
        
        $alert_rows[] = array_merge($r, [
            'reorder_level' => $reorder_level_per_tank,
            'alert_type' => $alert_type,
            'recommended_action' => $recommended_action
        ]);
    }
}
$total_alert_tanks = count($alert_rows);

$alert_low_tanks = count(array_filter($rows, fn($r) => $r['status'] === 'Low'));
$alert_critical_tanks = count(array_filter($rows, fn($r) => $r['status'] === 'Critical'));
$alert_empty_tanks = count(array_filter($rows, fn($r) => $r['status'] === 'Out of Stock' || $r['current_volume'] <= 0));
$alert_needing_delivery = $alert_low_tanks + $alert_critical_tanks + $alert_empty_tanks;

// â”€â”€ Fuel Movement History Data (only fetched when on movement tab) â”€â”€â”€â”€â”€
$mov_rows          = [];
$mov_total         = 0;
$mov_deliveries    = 0;
$mov_sales         = 0;
$mov_adjustments   = 0;

if ($active_tab === 'movement') {
    // Deliveries
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('DEL-', fd.id)         AS movement_id,
                fd.delivery_date              AS movement_date,
                fd.fuel_type,
                COALESCE(fd.tank_assigned,'—') AS tank,
                'Delivery'                    AS movement_type,
                fd.delivery_liters            AS liters,
                NULL                          AS previous_volume,
                NULL                          AS new_volume,
                COALESCE(u.name,'—')          AS performed_by,
                fd.invoice_no                 AS ref_no,
                fd.status,
                fd.notes
            FROM fuel_deliveries fd
            LEFT JOIN users u ON fd.received_by = u.id
            WHERE fd.station_id = ?
            ORDER BY fd.delivery_date DESC, fd.id DESC
            LIMIT 200
        ");
        $s->execute([$station_id]);
        $del_rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $mov_deliveries = count($del_rows);
        $mov_rows = array_merge($mov_rows, $del_rows);
    } catch (Exception $e) {}

    // Sales
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('SAL-', ft.id)         AS movement_id,
                DATE(ft.transaction_date)     AS movement_date,
                ft.fuel_type,
                COALESCE(CONCAT('Pump #',ft.pump_id),'—') AS tank,
                'Sale'                        AS movement_type,
                ft.liters_sold                AS liters,
                NULL                          AS previous_volume,
                NULL                          AS new_volume,
                COALESCE(u.name,'—')          AS performed_by,
                ft.transaction_id             AS ref_no,
                ft.status,
                ft.notes
            FROM fuel_transactions ft
            LEFT JOIN users u ON ft.staff_id = u.id
            WHERE ft.station_id = ?
            ORDER BY ft.transaction_date DESC, ft.id DESC
            LIMIT 200
        ");
        $s->execute([$station_id]);
        $sale_rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $mov_sales = count($sale_rows);
        $mov_rows = array_merge($mov_rows, $sale_rows);
    } catch (Exception $e) {}

    // Adjustments
    try {
        $s = $pdo->prepare("
            SELECT
                CONCAT('ADJ-', fa.id)         AS movement_id,
                fa.adjustment_date            AS movement_date,
                fa.fuel_type,
                '—'                           AS tank,
                CONCAT('Adjustment (',fa.adjustment_type,')') AS movement_type,
                fa.liters,
                fa.previous_value             AS previous_volume,
                fa.new_value                  AS new_volume,
                COALESCE(u.name,'—')          AS performed_by,
                fa.reason                     AS ref_no,
                fa.status,
                fa.notes
            FROM fuel_adjustments fa
            LEFT JOIN users u ON fa.user_id = u.id
            WHERE fa.station_id = ?
            ORDER BY fa.adjustment_date DESC, fa.id DESC
            LIMIT 200
        ");
        $s->execute([$station_id]);
        $adj_rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $mov_adjustments = count($adj_rows);
        $mov_rows = array_merge($mov_rows, $adj_rows);
    } catch (Exception $e) {}

    // Sort combined rows by date desc
    usort($mov_rows, function($a, $b) {
        return strcmp($b['movement_date'], $a['movement_date']);
    });
    $mov_total = count($mov_rows);
}

// ── Fetch Deliveries Tab Data ──
$deliveries_tab_list = [];
if ($active_tab === 'deliveries') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                CONCAT('DEL-', LPAD(fd.id, 5, '0')) AS delivery_no,
                COALESCE(NULLIF(fd.invoice_no,''), '—') AS po_no,
                COALESCE(NULLIF(fd.supplier,''), 'Petron Corporation') AS supplier,
                fd.delivery_liters AS liters,
                COALESCE(fp.price_per_liter, 0) AS cost_per_liter,
                fd.delivery_date AS date,
                fd.fuel_type
            FROM fuel_deliveries fd
            LEFT JOIN fuel_pricing fp ON LOWER(fp.fuel_type_id) = LOWER(fd.fuel_type) AND fp.station_id = fd.station_id
            WHERE fd.station_id = ?
            ORDER BY fd.delivery_date DESC, fd.id DESC
            LIMIT 200
        ");
        $stmt->execute([$station_id]);
        $deliveries_tab_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// ── Fetch Meter Readings Tab Data ──
$meter_readings_list = [];
if ($active_tab === 'readings') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                fa.id,
                fa.adjustment_date AS date,
                COALESCE(fa.fuel_type, '—') AS fuel_type,
                'UGT-01' AS ugt_no,
                fa.previous_value AS dip_reading,
                fa.new_value AS meter_reading,
                (fa.new_value - fa.previous_value) AS variance,
                COALESCE(u.name, 'Manager') AS adjusted_by,
                COALESCE(NULLIF(fa.reason,''), NULLIF(fa.notes,''), 'Routine Meter Calibration') AS remarks
            FROM fuel_adjustments fa
            LEFT JOIN users u ON fa.user_id = u.id
            WHERE fa.station_id = ?
            ORDER BY fa.adjustment_date DESC, fa.id DESC
            LIMIT 200
        ");
        $stmt->execute([$station_id]);
        $meter_readings_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// â”€â”€ Fetch Remaining Fuel Volume Tab Data â”€â”€
$fuel_types_grouped = [];
foreach ($rows as $r) {
    $ft = $r['fuel_type'];
    if (!isset($fuel_types_grouped[$ft])) {
        $fuel_types_grouped[$ft] = [
            'fuel_type' => $ft,
            'beginning' => 0,
            'delivered' => 0,
            'dispensed' => 0,
            'calibration' => 0,
            'remaining' => 0
        ];
    }
    $ft_key = strtolower(trim($ft));
    $del = $del_lookup[$ft_key] ?? 0;
    $sal = $sales_lookup[$ft_key] ?? 0;
    $adj = $adj_lookup[$ft_key] ?? 0;
    $rem = (float)$r['current_volume'];
    $beg = max(0, $rem - $del + $sal + $adj);

    $fuel_types_grouped[$ft]['beginning'] += $beg;
    $fuel_types_grouped[$ft]['delivered'] += $del;
    $fuel_types_grouped[$ft]['dispensed'] += $sal;
    $fuel_types_grouped[$ft]['calibration'] += $adj;
    $fuel_types_grouped[$ft]['remaining'] += $rem;
}
$remaining_volume_list = array_values($fuel_types_grouped);

// ── Fetch Fuel Movement History Tab Data ──
$fuel_movement_history = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            fd.delivery_date AS date,
            fd.fuel_type,
            COALESCE(NULLIF(fd.invoice_no,''), CONCAT('FDEL-', LPAD(fd.id, 5, '0'))) AS ref_no,
            'Delivery (IN)' AS movement_type,
            fd.delivery_liters AS inflow,
            0 AS outflow,
            fd.delivery_liters AS net_change,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Staff') AS user_name,
            COALESCE(NULLIF(fd.supplier,''), 'Petron Corporation') AS remarks
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        WHERE fd.station_id = ?
        ORDER BY fd.delivery_date DESC LIMIT 150
    ");
    $stmt->execute([$station_id]);
    $fuel_movement_history = array_merge($fuel_movement_history, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

// Approved Fuel Stock-Ins (manager_stock_in.php writes to fuel_stock_in, not fuel_deliveries)
try {
    $stmt = $pdo->prepare("
        SELECT
            fsi.encoded_at AS date,
            fsi.fuel_type,
            COALESCE(NULLIF(fsi.batch_ref,''), NULLIF(fsi.invoice_no,''), CONCAT('FSI-', LPAD(fsi.id, 5, '0'))) AS ref_no,
            'Delivery (IN)' AS movement_type,
            fsi.qty_received AS inflow,
            0 AS outflow,
            fsi.qty_received AS net_change,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Manager') AS user_name,
            CONCAT('Stock-In', CASE WHEN COALESCE(fsi.invoice_no,'') <> '' THEN CONCAT(' | PO: ', fsi.invoice_no) ELSE '' END,
                   CASE WHEN COALESCE(fsi.condition_flag,'') NOT IN ('', 'Good') THEN CONCAT(' | ', fsi.condition_flag) ELSE '' END) AS remarks
        FROM fuel_stock_in fsi
        LEFT JOIN users u ON fsi.encoded_by = u.id
        WHERE fsi.station_id = ?
        ORDER BY fsi.encoded_at DESC LIMIT 150
    ");
    $stmt->execute([$station_id]);
    $fuel_movement_history = array_merge($fuel_movement_history, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT
            ft.transaction_date AS date,
            ft.fuel_type,
            COALESCE(NULLIF(ft.transaction_id,''), CONCAT('FTRX-', LPAD(ft.id, 5, '0'))) AS ref_no,
            'Dispensed / Sales (OUT)' AS movement_type,
            0 AS inflow,
            ft.liters_sold AS outflow,
            -1 * ft.liters_sold AS net_change,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Pump Attendant') AS user_name,
            CONCAT('Pump ', COALESCE(ft.pump_id, '1'), ' Shift Sales') AS remarks
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id
        WHERE ft.station_id = ?
          AND LOWER(COALESCE(ft.status,'')) NOT IN ('voided','cancelled','rejected')
        ORDER BY ft.transaction_date DESC LIMIT 150
    ");
    $stmt->execute([$station_id]);
    $fuel_movement_history = array_merge($fuel_movement_history, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(fa.created_at, fa.adjustment_date) AS date,
            fa.fuel_type,
            CONCAT('FADJ-', LPAD(fa.id, 5, '0')) AS ref_no,
            'Calibration / Dip' AS movement_type,
            CASE WHEN LOWER(COALESCE(fa.status,'')) <> 'approved' THEN 0
                 WHEN (CASE WHEN LOWER(COALESCE(fa.adjustment_type,'')) LIKE 'meter%' THEN fa.liters WHEN COALESCE(fa.variance,0) <> 0 THEN fa.variance WHEN LOWER(COALESCE(fa.adjustment_direction,'')) = 'increase' THEN fa.liters ELSE -fa.liters END) > 0
                 THEN (CASE WHEN LOWER(COALESCE(fa.adjustment_type,'')) LIKE 'meter%' THEN fa.liters WHEN COALESCE(fa.variance,0) <> 0 THEN fa.variance WHEN LOWER(COALESCE(fa.adjustment_direction,'')) = 'increase' THEN fa.liters ELSE -fa.liters END)
                 ELSE 0 END AS inflow,
            CASE WHEN LOWER(COALESCE(fa.status,'')) <> 'approved' THEN 0
                 WHEN (CASE WHEN LOWER(COALESCE(fa.adjustment_type,'')) LIKE 'meter%' THEN fa.liters WHEN COALESCE(fa.variance,0) <> 0 THEN fa.variance WHEN LOWER(COALESCE(fa.adjustment_direction,'')) = 'increase' THEN fa.liters ELSE -fa.liters END) < 0
                 THEN ABS(CASE WHEN LOWER(COALESCE(fa.adjustment_type,'')) LIKE 'meter%' THEN fa.liters WHEN COALESCE(fa.variance,0) <> 0 THEN fa.variance WHEN LOWER(COALESCE(fa.adjustment_direction,'')) = 'increase' THEN fa.liters ELSE -fa.liters END)
                 ELSE 0 END AS outflow,
            CASE WHEN LOWER(COALESCE(fa.status,'')) <> 'approved' THEN 0
                 ELSE (CASE WHEN LOWER(COALESCE(fa.adjustment_type,'')) LIKE 'meter%' THEN fa.liters WHEN COALESCE(fa.variance,0) <> 0 THEN fa.variance WHEN LOWER(COALESCE(fa.adjustment_direction,'')) = 'increase' THEN fa.liters ELSE -fa.liters END) END AS net_change,
            COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, 'Manager') AS user_name,
            CONCAT(COALESCE(NULLIF(fa.reason,''), NULLIF(fa.notes,''), 'Routine Calibration'),
                   CASE WHEN LOWER(COALESCE(fa.status,'')) = 'approved' THEN '' ELSE CONCAT(' [', COALESCE(NULLIF(fa.status,''),'Pending'), ']') END) AS remarks
        FROM fuel_adjustments fa
        LEFT JOIN users u ON fa.user_id = u.id
        WHERE fa.station_id = ?
          AND LOWER(COALESCE(fa.adjustment_type,'')) <> 'stock_in'
        ORDER BY fa.created_at DESC LIMIT 100
    ");
    $stmt->execute([$station_id]);
    $fuel_movement_history = array_merge($fuel_movement_history, $stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {}

usort($fuel_movement_history, function($a, $b) {
    return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
});




include __DIR__ . '/../partials/header.php'; ?>

<style>
/* Zero-Horizontal-Scroll Fixed Layout Tables */
.table-wrap, .table-responsive {
    overflow-x: hidden !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
#mgrFuelTable, #mgrFuelMovementTable, #mgrDelTable, #mgrReadingsTable, #mgrAlertTable, table.pricing-table, table.tbl-requests, table.table {
    table-layout: fixed !important;
    min-width: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    border-collapse: collapse !important;
}
#mgrFuelMovementTable th,
#mgrFuelMovementTable td,
#mgrFuelTable th,
#mgrFuelTable td,
#mgrDelTable th,
#mgrDelTable td,
#mgrReadingsTable th,
#mgrReadingsTable td,
#mgrAlertTable th,
#mgrAlertTable td {
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    vertical-align: middle !important;
    box-sizing: border-box !important;
}
#mgrFuelTable th {
    padding: 9px 5px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: .3px !important;
}
#mgrFuelTable td {
    padding: 8px 5px !important;
    font-size: 13px !important;
}
/* Allow full text in Status (col 8) and Last Updated (col 9) in #mgrFuelTable — no ellipsis */
#mgrFuelTable td:nth-child(8),
#mgrFuelTable td:nth-child(9),
#mgrFuelTable th:nth-child(8),
#mgrFuelTable th:nth-child(9) {
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
    word-break: normal !important;
}
#mgrFuelTable .inv-stock-badge {
    white-space: nowrap !important;
    font-size: 11.5px !important;
    padding: 3px 7px !important;
    display: inline-block !important;
    line-height: 1.2 !important;
}
#mgrFuelMovementTable th {
    padding: 10px 8px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: .3px !important;
}
#mgrFuelMovementTable td {
    padding: 9px 8px !important;
    font-size: 13px !important;
}
/* Allow full text in Handled By (col 8) and Remarks (col 9) — no ellipsis */
#mgrFuelMovementTable td:nth-child(8),
#mgrFuelMovementTable td:nth-child(9) {
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
    word-break: break-word !important;
    vertical-align: top !important;
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
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 6px !important;
    background: #ffffff !important;
    color: #1e293b !important;
    font-size: 13.5px !important;
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
    font-size: 13.5px !important;
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
    font-size: 13.5px !important;
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
<style>
/* == PAGE HEADER - matches standard Petron dashboard layout == */
.int-head {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    flex-wrap: wrap !important;
    gap: 15px !important;
    margin-top: 0 !important;
    margin-bottom: 25px !important;
    padding: 0 !important;
    border: none !important;
    width: 100% !important;
}
.int-head h1 {
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #002f70 !important;
    margin: 0 !important;
    line-height: 1.2 !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}
.int-head .sub {
    font-size: 15.5px;
    color: #666;
    margin-top: 4px;
    text-transform: none !important;
}

/* Tabs Layout - Matches Reports sub-tab design */
.tab-nav { display: flex !important; flex-wrap: wrap !important; margin-bottom: 22px !important; border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; border-bottom: 3px solid #00264D !important; gap: 0 !important; }
.tab-btn { flex: 1 !important; min-width: 140px !important; padding: 12px 16px !important; font-size: 11.5px !important; font-weight: 700 !important; color: #334155 !important; background: #ffffff !important; border: none !important; border-right: 1px solid #d1d9e6 !important; border-bottom: none !important; text-decoration: none !important; transition: all 0.15s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 7px !important; text-transform: uppercase !important; letter-spacing: 0.3px !important; text-align: center !important; cursor: pointer !important; margin-bottom: 0 !important; }
.tab-btn:last-child { border-right: none !important; }
.tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.tab-btn.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; border-bottom: none !important; }


/* Modal inner tab buttons */
.modal-tab-btn {
    padding: 10px 16px;
    border: none;
    background: none;
    font-weight: 600;
    font-size: 14.5px;
    text-transform: uppercase;
    color: #64748b;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .15s;
    letter-spacing: .3px;
}
.modal-tab-btn.active,
.modal-tab-btn[data-active] {
    color: #002F70;
    border-bottom-color: #002F70;
    font-weight: 700;
}

/* Filter button variants */
.flt-btn-search { color: #002F70 !important; border-color: #002F70 !important; }
.flt-btn-search:hover { background: #002F70 !important; color: #fff !important; }
.flt-btn-reset { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover { background: #6b7280 !important; color: #fff !important; }

/* Form group */
.form-group { margin-bottom: 14px; }
.form-group label { display:block; font-size:14px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.3px; margin-bottom:5px; }
.form-group input, .form-group select, .form-group textarea { width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; box-sizing:border-box; outline:none; transition:border-color .15s; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#002F70; }


/* == UI BUTTONS == */
.ato-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 16px;
    border-radius: 7px;
    font-size: 15.5px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    text-decoration: none;
    transition: all .15s;
    height: 36px;
    white-space: nowrap;
    background: white !important;
}
.ato-btn-back {
    color: #4b5563 !important;
    border-color: #6b7280 !important;
}
.ato-btn-back:hover {
    background: #6b7280 !important;
    color: #fff !important;
}

/* Custom Outlined Buttons for Petron-clean Look */
.int-btn-outline {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 4px;
    font-size: 14px;
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
    font-size: 14px;
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
    font-size: 14px;
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

/* Export Button Styles */
.flt-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 14.5px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid;
    background: white !important;
    transition: all 0.2s;
    white-space: nowrap;
    text-decoration: none;
}
.flt-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-csv { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-csv:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.flt-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.flt-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }

/* == MODAL OVERLAY == */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px 20px 70px 20px;
}
.modal-overlay.open {
    display: flex;
}
.modal-box {
    background: #fff;
    border-radius: 12px;
    width: 600px;
    max-width: 100%;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 110px);
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: flex-start;
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

/* Modal tab button overrides to prevent global button overrides */
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
.mif-wrap { width: 100%; max-width: 100%; box-sizing: border-box; overflow-x: hidden !important; padding: 0 !important; margin: 0 !important; }
</style>

<div class="mif-wrap">
<!-- â• â•  Page Header â• â•  -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-gas-pump"></i> Fuel Inventory Monitoring</h1>
    </div>
    
    <?php if ($active_tab === 'alerts'): ?>
    <!-- Back Button (Alerts Tab) -->
    <div>
        <a href="manager_inventory_fuel.php?tab=overview" class="ato-btn ato-btn-back">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- â•â• Sub-Tab Navigation (4 Tabs) â•â• -->
<div class="tab-nav" style="overflow-x: hidden; flex-wrap:nowrap; white-space:nowrap; padding-bottom:4px;">
    <a href="manager_inventory_fuel.php?tab=overview" class="tab-btn <?= $active_tab === 'overview' ? 'active' : '' ?>">
        <i class="fas fa-list"></i> Fuel Overview
    </a>
    <a href="manager_inventory_fuel.php?tab=movement" class="tab-btn <?= ($active_tab === 'movement' || $active_tab === 'movements') ? 'active' : '' ?>">
        <i class="fas fa-exchange-alt"></i> Stock Movement Monitoring
    </a>
    <a href="manager_inventory_fuel.php?tab=readings" class="tab-btn <?= $active_tab === 'readings' ? 'active' : '' ?>">
        <i class="fas fa-ruler-combined"></i> Meter Readings
    </a>
    <a href="manager_inventory_fuel.php?tab=alerts" class="tab-btn <?= $active_tab === 'alerts' ? 'active' : '' ?>">
        <i class="fas fa-exclamation-triangle"></i> Stock Alerts
        <?php if ($total_alert_tanks > 0): ?>
            <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:14px;margin-left:4px;"><?= $total_alert_tanks ?></span>
        <?php endif; ?>
    </a>
</div>

<?php if ($active_tab === 'overview'): ?>
<!-- â•â• Summary Cards (6 Cards) â•â• -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
    <!-- Total Fuel Available -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Total Fuel Available</div>
            <div style="font-size:24px;font-weight:800;color:#002F70;margin-top:4px;"><?= number_format($total_fuel_volume, 0) ?> L</div>
        </div>
        <div style="background:#f0f4ff;color:#002F70;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-gas-pump"></i></div>
    </div>
    <!-- Diesel Available -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Diesel Available</div>
            <div style="font-size:24px;font-weight:800;color:#1e293b;margin-top:4px;"><?= number_format($diesel_available, 0) ?> L</div>
        </div>
        <div style="background:#f8fafc;color:#64748b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-tint"></i></div>
    </div>
    <!-- Premium Available -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Premium Available</div>
            <div style="font-size:24px;font-weight:800;color:#1e293b;margin-top:4px;"><?= number_format($premium_available, 0) ?> L</div>
        </div>
        <div style="background:#f8fafc;color:#64748b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-star"></i></div>
    </div>
    <!-- Regular Available -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Regular Available</div>
            <div style="font-size:24px;font-weight:800;color:#1e293b;margin-top:4px;"><?= number_format($regular_available, 0) ?> L</div>
        </div>
        <div style="background:#f8fafc;color:#64748b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-leaf"></i></div>
    </div>
    <!-- Low Fuel Types -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #fed7aa;">
        <div>
            <div style="font-size:14px;font-weight:700;color:#ea580c;text-transform:uppercase;letter-spacing:.3px;">Low Fuel Types</div>
            <div style="font-size:24px;font-weight:800;color:#ea580c;margin-top:4px;"><?= number_format($low_fuel_types) ?></div>
        </div>
        <div style="background:#fff7ed;color:#ea580c;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>

</div>

<!-- â•â• Fuel Catalog Card â•â• -->
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-gas-pump"></i> Fuel Tanks Catalog
        </div>
        <div class="inv-filter-bar" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <input type="text" id="fuelSearch" placeholder="Search Fuel Type / UGT..." oninput="filterFuelTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;width:200px;">
            
            <select id="fuelTypeFilter" onchange="filterFuelTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;">
                <option value="">All Fuel Types</option>
                <option value="diesel">Diesel</option>
                <option value="kerosene">Kerosene</option>
                <option value="turbo diesel">Turbo Diesel</option>
                <option value="xcs plus">XCS Plus</option>
                <option value="xtra unl">XTRA UNL</option>
            </select>

            <select id="fuelStatusFilter" onchange="filterFuelTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;">
                <option value="">All Statuses</option>
                <option value="normal">Normal</option>
                <option value="low">Low</option>
                <option value="critical">Critical</option>
                <option value="out of stock">Out of Stock</option>
                <option value="deactivated">Deactivated</option>
            </select>
            <button type="button" class="flt-btn flt-btn-search" onclick="filterFuelTable()"><i class="fas fa-search"></i> Filter</button>
            <button type="button" class="flt-btn flt-btn-reset" onclick="resetFuelFilters()"><i class="fas fa-rotate-left"></i> Reset</button>
        </div>
    </div>

    <div class="table-wrap" style="width:100%;overflow-x:hidden !important;box-sizing:border-box;">
        <table class="table" id="mgrFuelTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
            <colgroup>
                <col style="width: 7%;">   <!-- UGT No. -->
                <col style="width: 11%;">  <!-- Fuel Type -->
                <col style="width: 10%;">  <!-- Current Volume -->
                <col style="width: 8%;">   <!-- Capacity -->
                <col style="width: 10%;">  <!-- Available Space -->
                <col style="width: 8%;">   <!-- Reorder Level -->
                <col style="width: 8%;">   <!-- Critical Level -->
                <col style="width: 12%;">  <!-- Status -->
                <col style="width: 13%;">  <!-- Last Updated -->
                <col style="width: 13%;">  <!-- Actions -->
            </colgroup>
            <thead>
                <tr>
                    <th style="white-space:nowrap;">UGT No.</th>
                    <th style="white-space:nowrap;">Fuel Type</th>
                    <th style="text-align:right;white-space:nowrap;">Current Volume</th>
                    <th style="text-align:right;white-space:nowrap;">Capacity</th>
                    <th style="text-align:right;white-space:nowrap;">Available Space</th>
                    <th style="text-align:right;white-space:nowrap;">Reorder Level</th>
                    <th style="text-align:right;white-space:nowrap;">Critical Level</th>
                    <th style="text-align:center;white-space:nowrap;">Status</th>
                    <th style="white-space:nowrap;">Last Updated</th>
                    <th style="text-align:center;white-space:nowrap;">Actions</th>
                </tr>
            </thead>
            <tbody id="fuelTableBody">
            <?php foreach ($rows as $r): 
                $pct = $r['fill_pct'];
                $pct_color = $pct < 25 ? '#dc3545' : ($pct < 50 ? '#fd7e14' : '#28a745');
                $crit_level = max(1000, round($r['capacity'] * 0.15));
                $ugt_no = 'UGT-' . str_pad($r['tank_id'], 2, '0', STR_PAD_LEFT);
            ?>
                <tr class="fuel-row" 
                    data-id="<?= $r['tank_id'] ?>"
                    data-name="<?= strtolower(htmlspecialchars($r['tank_name'])) ?>"
                    data-desc="<?= strtolower(htmlspecialchars($r['tank_description'])) ?>"
                    data-type="<?= strtolower(htmlspecialchars($r['fuel_type'])) ?>"
                    data-status="<?= strtolower($r['status']) ?>">
                    <td><code style="font-weight:700;color:#002F70;font-size:14px;"><?= htmlspecialchars($ugt_no) ?></code></td>
                    <td><strong style="color:#0f172a;"><?= htmlspecialchars($r['fuel_type']) ?></strong></td>
                    <td style="text-align:right;font-weight:800;color:#002F70;"><?= number_format($r['current_volume'], 2) ?> L</td>
                    <td style="text-align:right;font-weight:600;color:#475569;"><?= number_format($r['capacity'], 0) ?> L</td>
                    <td style="text-align:right;font-weight:600;color:#16a34a;"><?= number_format($r['remaining_capacity'], 2) ?> L</td>
                    <td style="text-align:right;font-weight:600;color:#eab308;"><?= number_format($r['reorder_level'], 0) ?> L</td>
                    <td style="text-align:right;font-weight:600;color:#dc3545;"><?= number_format($crit_level, 0) ?> L</td>
                    <td style="text-align:center;white-space:normal !important;overflow:visible !important;text-overflow:clip !important;">
                        <span class="inv-stock-badge" style="background:<?= $r['status_color'] ?>20;color:<?= $r['status_color'] ?>;border:1px solid <?= $r['status_color'] ?>40;padding:3px 7px;border-radius:4px;font-size:11.5px;font-weight:700;text-transform:uppercase;white-space:nowrap;display:inline-block;line-height:1.2;">
                            <?= htmlspecialchars($r['status']) ?>
                        </span>
                    </td>
                    <td style="font-size:12px;color:#1e293b;line-height:1.3;white-space:normal !important;overflow:visible !important;text-overflow:clip !important;">
                        <?php if ($r['last_updated'] && strtotime($r['last_updated']) > 0): ?>
                            <div style="font-weight:600;white-space:nowrap;"><?= date('M d, Y', strtotime($r['last_updated'])) ?></div>
                            <div style="font-size:11px;color:#64748b;white-space:nowrap;"><?= date('h:i A', strtotime($r['last_updated'])) ?></div>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;white-space:nowrap;min-width:135px;width:135px;">
                        <div style="display:flex;flex-direction:column;gap:4px;align-items:center;width:100%;min-width:120px;max-width:130px;margin:0 auto;">
                            <button type="button" class="int-btn-outline" style="width:100%;font-size:12px;height:26px;padding:0 8px;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;justify-content:center;gap:4px;" data-fuel="<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>" onclick="event.stopPropagation(); openFuelModalFromBtn(this)">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button type="button" class="int-btn-outline" style="width:100%;font-size:12px;height:26px;padding:0 8px;cursor:pointer;border-color:#5b21b6;color:#5b21b6;white-space:nowrap;display:inline-flex;align-items:center;justify-content:center;gap:4px;" data-fuel="<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>" onclick="event.stopPropagation(); openAdjustReadingModalFromBtn(this)">
                                <i class="fas fa-balance-scale"></i> Adjust Reading
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrFuelPagination" style="padding:10px 20px;"></div>
</div>
<?php endif; ?>

<!-- â•â• TAB: FUEL DELIVERIES â•â• -->
<?php if ($active_tab === 'deliveries'): ?>
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-truck"></i> Fuel Deliveries Records
        </div>
        <div class="inv-filter-bar" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <input type="text" id="delSearchInput" placeholder="Search Delivery/PO/Supplier..." oninput="filterDelTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;width:220px;">
            
            <select id="delTypeFilter" onchange="filterDelTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;">
                <option value="">All Fuel Types</option>
                <option value="diesel">Diesel</option>
                <option value="kerosene">Kerosene</option>
                <option value="turbo diesel">Turbo Diesel</option>
                <option value="xcs plus">XCS Plus</option>
                <option value="xtra unl">XTRA UNL</option>
            </select>
            <button type="button" class="flt-btn flt-btn-search" onclick="filterDelTable()"><i class="fas fa-search"></i> Filter</button>
            <button type="button" class="flt-btn flt-btn-reset" onclick="document.getElementById('delSearchInput').value='';document.getElementById('delTypeFilter').value='';filterDelTable();"><i class="fas fa-rotate-left"></i> Reset</button>
        </div>
    </div>
    <div class="table-wrap" style="width:100%;overflow-x:hidden !important;box-sizing:border-box;">
        <table class="table" id="mgrDelTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
            <colgroup>
                <col style="width: 13%;"> <!-- Delivery No. -->
                <col style="width: 13%;"> <!-- PO No. -->
                <col style="width: 14%;"> <!-- Supplier -->
                <col style="width: 12%;"> <!-- Fuel Type -->
                <col style="width: 11%;"> <!-- UGT Assigned -->
                <col style="width: 11%;"> <!-- Liters -->
                <col style="width: 11%;"> <!-- Cost/Liter -->
                <col style="width: 15%;"> <!-- Date -->
            </colgroup>
            <thead>
                <tr style="background:#002F70; color:#fff;">
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Delivery No.</th>
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">PO No.</th>
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Supplier</th>
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Fuel Type</th>
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">UGT Assigned</th>
                    <th style="text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Liters</th>
                    <th style="text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Cost/Liter</th>
                    <th style="text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Date</th>
                </tr>
            </thead>
            <tbody id="delTableBody">
            <?php if (empty($deliveries_tab_list)): ?>
                <tr><td colspan="8" style="text-align:center; padding:32px; color:#64748b;"><i class="fas fa-info-circle" style="font-size:1.8em; display:block; margin-bottom:8px;"></i> No fuel delivery records found.</td></tr>
            <?php else: ?>
                <?php foreach ($deliveries_tab_list as $fd):
                    $ddate = !empty($fd['date']) ? (new DateTime($fd['date']))->format('M d, Y h:i A') : '—';
                    $del_type = $fd['fuel_type'] ?? 'Diesel';
                    $ugt = $fd['ugt_no'] ?? 'UGT-01';
                ?>
                <tr class="del-row" data-search="<?= strtolower(htmlspecialchars($fd['delivery_no'] . ' ' . $fd['po_no'] . ' ' . $fd['supplier'] . ' ' . $del_type . ' ' . $ugt)) ?>" data-type="<?= strtolower(htmlspecialchars($del_type)) ?>" style="border-bottom:1px solid #f1f5f9;">
                    <td><code style="font-size:14px; font-weight:700; color:#002F70;"><?php echo htmlspecialchars($fd['delivery_no']); ?></code></td>
                    <td><span style="font-size:14px; font-weight:600; color:#475569;"><?php echo htmlspecialchars($fd['po_no']); ?></span></td>
                    <td><strong><?php echo htmlspecialchars($fd['supplier']); ?></strong></td>
                    <td><span style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($del_type) ?></span></td>
                    <td><code style="font-size:14px;font-weight:700;color:#002F70;"><?= htmlspecialchars($ugt) ?></code></td>
                    <td style="text-align:right; font-weight:700; color:#16a34a; font-size:15.5px;"><?php echo number_format((float)$fd['liters'], 2); ?> L</td>
                    <td style="text-align:right; font-weight:600; color:#002F70;">₱<?php echo number_format((float)$fd['cost_per_liter'], 2); ?></td>
                    <td style="text-align:center; font-size:14px; color:#64748b;"><?php echo $ddate; ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrDelPagination" style="padding:10px 20px;"></div>
</div>
<?php endif; ?>

<!-- ══ TAB: FUEL STOCK MOVEMENT MONITORING ══ -->
<?php if ($active_tab === 'movement' || $active_tab === 'movements'): ?>
<?php
    $fmov_inflow = 0;
    $fmov_outflow = 0;
    foreach ($fuel_movement_history as $fm) {
        $fmov_inflow += (float)($fm['inflow'] ?? 0);
        $fmov_outflow += (float)($fm['outflow'] ?? 0);
    }
?>
<!-- Fuel Movement KPI Summary -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:20px;">
    <div style="background:#fff; border:1px solid #bbf7d0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:14px; font-weight:700; color:#15803d; text-transform:uppercase; letter-spacing:.3px;">Total Deliveries (Inflow)</div>
            <div style="font-size:22px; font-weight:800; color:#15803d; margin-top:4px;">+<?= number_format($fmov_inflow, 2) ?> L</div>
        </div>
        <div style="background:#dcfce7; color:#15803d; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-truck"></i></div>
    </div>
    <div style="background:#fff; border:1px solid #fed7aa; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:14px; font-weight:700; color:#c2410c; text-transform:uppercase; letter-spacing:.3px;">Total Dispensed (Outflow)</div>
            <div style="font-size:22px; font-weight:800; color:#c2410c; margin-top:4px;">-<?= number_format($fmov_outflow, 2) ?> L</div>
        </div>
        <div style="background:#ffedd5; color:#c2410c; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-gas-pump"></i></div>
    </div>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:14px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.3px;">Net Movement Volume</div>
            <div style="font-size:22px; font-weight:800; color:<?= ($fmov_inflow - $fmov_outflow) >= 0 ? '#15803d' : '#dc2626' ?>; margin-top:4px;"><?= (($fmov_inflow - $fmov_outflow) >= 0 ? '+' : '') . number_format($fmov_inflow - $fmov_outflow, 2) ?> L</div>
        </div>
        <div style="background:#f0f4ff; color:#002F70; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-scale-balanced"></i></div>
    </div>
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; align-items:center; justify-content:space-between;">
        <div>
            <div style="font-size:14px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px;">Total Movement Logs</div>
            <div style="font-size:22px; font-weight:800; color:#0f172a; margin-top:4px;"><?= count($fuel_movement_history) ?> Logs</div>
        </div>
        <div style="background:#f1f5f9; color:#475569; width:38px; height:38px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;"><i class="fas fa-list-check"></i></div>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="inv-filter-bar" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:20px; background:#fff; padding:12px 16px; border:1px solid #e2e8f0; border-radius:8px;">
  <div style="position:relative;">
    <i class="fas fa-search" style="position:absolute; left:10px; top:11px; color:#94a3b8; font-size:14.5px;"></i>
    <input type="text" id="mgrFmovSearch" placeholder="Search Ref, Type, Staff..." oninput="filterMgrFuelMovementTable()" style="padding:7px 10px 7px 28px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; width:220px; outline:none;">
  </div>
  <select id="mgrFmovTypeFilter" onchange="filterMgrFuelMovementTable()" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; color:#334155; outline:none; background:#fff;">
    <option value="">All Fuel Types</option>
    <option value="diesel">Diesel</option>
    <option value="kerosene">Kerosene</option>
    <option value="turbo diesel">Turbo Diesel</option>
    <option value="xcs">XCS</option>
    <option value="xtra advance">Xtra Advance</option>
    <option value="xtra unl">Xtra UNL</option>
  </select>
  <select id="mgrFmovMoveFilter" onchange="filterMgrFuelMovementTable()" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; color:#334155; outline:none; background:#fff;">
    <option value="">All Movement Types</option>
    <option value="delivery">Delivery (IN)</option>
    <option value="dispensed">Dispensed / Sales (OUT)</option>
    <option value="calibration">Calibration / Dip (ADJ)</option>
  </select>
  <button type="button" class="flt-btn flt-btn-csv" onclick="filterMgrFuelMovementTable()"><i class="fas fa-search"></i> Filter</button>
  <button type="button" class="flt-btn btn-cancel" onclick="resetMgrFuelMovementFilters()"><i class="fas fa-rotate-left"></i> Reset</button>
</div>

<!-- Flat Fuel Movement Table -->
<div style="background:#fff; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); margin-bottom:20px;">
  <div class="table-wrap" style="width:100%; overflow-x:hidden !important; box-sizing:border-box;">
    <table class="table" id="mgrFuelMovementTable" style="width:100% !important; table-layout:fixed !important; border-collapse:collapse !important;">
      <colgroup>
        <col style="width: 10%;"> <!-- Reference No. -->
        <col style="width: 13%;"> <!-- Date & Time -->
        <col style="width: 10%;"> <!-- Fuel Type -->
        <col style="width: 11%;"> <!-- Movement Type -->
        <col style="width:  9%;"> <!-- Inflow (IN) -->
        <col style="width:  9%;"> <!-- Outflow (OUT) -->
        <col style="width:  9%;"> <!-- Net Change -->
        <col style="width: 14%;"> <!-- Handled By -->
        <col style="width: 15%;"> <!-- Remarks / Notes -->
      </colgroup>
      <thead>
        <tr style="background:#002F70; color:#fff;">
          <th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Reference No.</th>
          <th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Date &amp; Time</th>
          <th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Fuel Type</th>
          <th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Movement Type</th>
          <th style="text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Inflow (IN)</th>
          <th style="text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Outflow (OUT)</th>
          <th style="text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Net Change</th>
          <th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Handled By</th>
          <th style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Remarks / Notes</th>
        </tr>
      </thead>
      <tbody id="mgrFuelMovementTbody">
      <?php if (empty($fuel_movement_history)): ?>
        <tr><td colspan="9" style="text-align:center; padding:32px; color:#6c757d;">No fuel stock movements recorded yet.</td></tr>
      <?php else: ?>
        <?php foreach ($fuel_movement_history as $fm):
            $mtype = $fm['movement_type'];
            $is_in = stripos($mtype, 'delivery') !== false || stripos($mtype, 'in') !== false;
            $is_out = stripos($mtype, 'dispensed') !== false || stripos($mtype, 'sales') !== false || stripos($mtype, 'out') !== false;
            $badge_bg = $is_in ? '#dcfce7' : ($is_out ? '#fee2e2' : '#fef3c7');
            $badge_color = $is_in ? '#15803d' : ($is_out ? '#b91c1c' : '#b45309');
            $net = (float)$fm['net_change'];
            $net_color = $net > 0 ? '#15803d' : ($net < 0 ? '#dc2626' : '#64748b');
            $f_name = function_exists('get_canonical_fuel_name') ? get_canonical_fuel_name($fm['fuel_type']) : $fm['fuel_type'];
        ?>
        <tr class="mgr-fmov-row"
            data-search="<?= strtolower(htmlspecialchars($fm['ref_no'] . ' ' . $fm['fuel_type'] . ' ' . $fm['movement_type'] . ' ' . $fm['user_name'] . ' ' . $fm['remarks'])) ?>"
            data-fuel-type="<?= strtolower(htmlspecialchars($f_name)) ?>"
            data-move-type="<?= strtolower(htmlspecialchars($fm['movement_type'])) ?>">
          <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><code style="font-weight:700;color:#002F70;"><?= htmlspecialchars($fm['ref_no']) ?></code></td>
          <td style="color:#64748b; font-size:12.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= date('M d, Y h:i A', strtotime($fm['date'])) ?></td>
          <td style="font-weight:700;color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($f_name) ?></td>
          <td style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><span style="background:<?= $badge_bg ?>;color:<?= $badge_color ?>;padding:2px 7px;border-radius:12px;font-size:12px;font-weight:700;white-space:nowrap;"><?= htmlspecialchars($fm['movement_type']) ?></span></td>
          <td style="text-align:right; font-weight:700; color:#15803d; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= (float)$fm['inflow'] > 0 ? ('+' . number_format((float)$fm['inflow'], 2) . ' L') : '—' ?></td>
          <td style="text-align:right; font-weight:700; color:#dc2626; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= (float)$fm['outflow'] > 0 ? ('-' . number_format((float)$fm['outflow'], 2) . ' L') : '—' ?></td>
          <td style="text-align:right; font-weight:800; color:<?= $net_color ?>; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= ($net > 0 ? '+' : '') . number_format($net, 2) ?> L</td>
          <td style="font-size:12.5px; color:#334155; white-space:normal; word-break:break-word; overflow:visible;" title="<?= htmlspecialchars($fm['user_name']) ?>"><?= htmlspecialchars($fm['user_name']) ?></td>
          <td style="font-size:12.5px; color:#64748b; white-space:normal; word-break:break-word; overflow:visible;" title="<?= htmlspecialchars($fm['remarks']) ?>"><?= htmlspecialchars($fm['remarks'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
    <!-- Fuel Movement Pagination Footer -->
  <div id="mfmPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 11px 11px; font-size:15.5px; color:#475569; flex-wrap:wrap; gap:12px;">
      <div style="display:flex; align-items:center;">
          <span id="mfmShowingEntriesText" style="font-size:15.5px; color:#64748b; font-weight:600;">Showing <?= empty($fuel_movement_history) ? '0' : '1–'.min(10, count($fuel_movement_history)) ?> of <?= count($fuel_movement_history) ?> entries</span>
      </div>
      <div style="display:flex; align-items:center; gap:16px;">
          <div style="display:flex; align-items:center; gap:8px;">
              <label style="margin:0; font-weight:600; color:#64748b; font-size:15.5px;">Rows per page:</label>
              <select id="mfmPerPage" onchange="mfmChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                  <option value="10" selected>10</option>
                  <option value="20">20</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
              </select>
          </div>
          <div style="display:flex; align-items:center; gap:6px;">
              <button id="mfmPrevBtn" onclick="mfmGoPage(mfmState.page - 1)" 
                      style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                      onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                  <i class="fas fa-chevron-left"></i>
              </button>
              <span id="mfmPageLabel" style="color:#334155; font-size:15.5px; font-weight:600; padding:0 4px;">Page 1 of <?= max(1, ceil(count($fuel_movement_history) / 10)) ?></span>
              <button id="mfmNextBtn" onclick="mfmGoPage(mfmState.page + 1)" 
                      style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($fuel_movement_history) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($fuel_movement_history) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                      onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                  <i class="fas fa-chevron-right"></i>
              </button>
          </div>
      </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ TAB: METER READINGS ══ -->
<?php if ($active_tab === 'readings'): ?>
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-ruler-combined"></i> Fuel Meter Readings & Dipping Log
        </div>
        <div class="inv-filter-bar" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <input type="text" id="readSearchInput" placeholder="Search Readings..." oninput="filterReadingsTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;width:180px;">
            <select id="readTypeFilter" onchange="filterReadingsTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;">
                <option value="">All Fuel Types</option>
                <option value="diesel">Diesel</option>
                <option value="kerosene">Kerosene</option>
                <option value="turbo diesel">Turbo Diesel</option>
                <option value="xcs plus">XCS Plus</option>
                <option value="xtra unl">XTRA UNL</option>
            </select>
        </div>
    </div>
    <div class="table-wrap" style="width:100%;overflow-x:hidden !important;box-sizing:border-box;">
        <table class="table" id="mgrReadingsTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
            <colgroup>
                <col style="width: 14%;"> <!-- Date -->
                <col style="width: 9%;">  <!-- UGT No. -->
                <col style="width: 11%;"> <!-- Fuel Type -->
                <col style="width: 13%;"> <!-- Dipping Reading (L) -->
                <col style="width: 13%;"> <!-- Pump Meter Reading -->
                <col style="width: 11%;"> <!-- Variance (L) -->
                <col style="width: 12%;"> <!-- Adjusted By -->
                <col style="width: 17%;"> <!-- Remarks -->
            </colgroup>
            <thead>
                <tr style="background:#002F70; color:#fff;">
                    <th style="text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Date</th>
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">UGT No.</th>
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Fuel Type</th>
                    <th style="text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Dipping Reading (L)</th>
                    <th style="text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Pump Meter Reading</th>
                    <th style="text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Variance (L)</th>
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Adjusted By</th>
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Remarks</th>
                </tr>
            </thead>
            <tbody id="readingsTableBody">
            <?php if (empty($meter_readings_list)): ?>
                <?php foreach ($rows as $r): 
                    $ugt_no = 'UGT-' . str_pad($r['tank_id'], 2, '0', STR_PAD_LEFT);
                    $date_str = date('M d, Y h:i A');
                ?>
                <tr class="read-row" data-search="<?= strtolower(htmlspecialchars($ugt_no . ' ' . $r['fuel_type'])) ?>" data-type="<?= strtolower(htmlspecialchars($r['fuel_type'])) ?>" style="border-bottom:1px solid #f1f5f9;">
                    <td style="text-align:center; font-size:14px; color:#64748b;"><?= $date_str ?></td>
                    <td><code style="font-weight:700;color:#002F70;font-size:14.5px;"><?= htmlspecialchars($ugt_no) ?></code></td>
                    <td><strong><?= htmlspecialchars($r['fuel_type']) ?></strong></td>
                    <td style="text-align:right; font-weight:700; color:#002F70;"><?= number_format($r['current_volume'], 2) ?> L</td>
                    <td style="text-align:right; font-weight:600; color:#475569;"><?= number_format($r['current_volume'], 2) ?> L</td>
                    <td style="text-align:right; font-weight:600; color:#16a34a;">0.00 L</td>
                    <td style="font-size:14px; color:#334155;">Manager</td>
                    <td style="font-size:14px; color:#64748b;">Current dip level verified</td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($meter_readings_list as $mr):
                    $mdate = !empty($mr['date']) ? (new DateTime($mr['date']))->format('M d, Y h:i A') : '—';
                    $var = (float)($mr['variance'] ?? 0);
                    $var_color = $var > 0 ? '#16a34a' : ($var < 0 ? '#dc2626' : '#64748b');
                ?>
                <tr class="read-row" data-search="<?= strtolower(htmlspecialchars($mr['ugt_no'] . ' ' . $mr['fuel_type'] . ' ' . $mr['remarks'])) ?>" data-type="<?= strtolower(htmlspecialchars($mr['fuel_type'])) ?>" style="border-bottom:1px solid #f1f5f9;">
                    <td style="text-align:center; font-size:14px; color:#64748b;"><?= $mdate; ?></td>
                    <td><code style="font-size:14px; font-weight:700; color:#002F70;"><?= htmlspecialchars($mr['ugt_no']); ?></code></td>
                    <td><strong><?= htmlspecialchars($mr['fuel_type']); ?></strong></td>
                    <td style="text-align:right; font-weight:700; color:#002F70;"><?= number_format((float)$mr['dip_reading'], 2); ?> L</td>
                    <td style="text-align:right; font-weight:600; color:#475569;"><?= number_format((float)$mr['meter_reading'], 2); ?> L</td>
                    <td style="text-align:right; font-weight:700; color:<?= $var_color ?>;"><?= ($var >= 0 ? '+' : '') . number_format($var, 2); ?> L</td>
                    <td style="font-size:14px; color:#334155;"><?= htmlspecialchars($mr['adjusted_by']); ?></td>
                    <td style="font-size:14px; color:#64748b;"><?= htmlspecialchars($mr['remarks']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrReadingsPagination" style="padding:10px 20px;"></div>
</div>
<?php endif; ?>

<!-- â•â• TAB CONTENT: Stock Alerts â•â• -->
<?php if ($active_tab === 'alerts'): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:24px;">
    <!-- Low Fuel Tanks -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #fed7aa;">
        <div>
            <div style="font-size:14px;font-weight:700;color:#ea580c;text-transform:uppercase;letter-spacing:.3px;">Low Fuel Tanks</div>
            <div style="font-size:24px;font-weight:800;color:#ea580c;margin-top:4px;"><?= number_format($alert_low_tanks) ?></div>
        </div>
        <div style="background:#fff7ed;color:#ea580c;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
    <!-- Critical Fuel Tanks -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #fecaca;">
        <div>
            <div style="font-size:14px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.3px;">Critical Fuel Tanks</div>
            <div style="font-size:24px;font-weight:800;color:#dc2626;margin-top:4px;"><?= number_format($alert_critical_tanks) ?></div>
        </div>
        <div style="background:#fef2f2;color:#dc2626;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-fire"></i></div>
    </div>
    <!-- Empty Tanks -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #e2e8f0;">
        <div>
            <div style="font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.3px;">Empty Tanks</div>
            <div style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px;"><?= number_format($alert_empty_tanks) ?></div>
        </div>
        <div style="background:#f8fafc;color:#64748b;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-times-circle"></i></div>
    </div>
    <!-- Needing Delivery -->
    <div style="background:#fff;border-radius:8px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:space-between;border:1px solid #fecaca;">
        <div>
            <div style="font-size:14px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.3px;">Needing Delivery</div>
            <div style="font-size:24px;font-weight:800;color:#dc2626;margin-top:4px;"><?= number_format($alert_needing_delivery) ?></div>
        </div>
        <div style="background:#fef2f2;color:#dc2626;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;"><i class="fas fa-truck-loading"></i></div>
    </div>
</div>

<!-- â•â• Low Fuel Alert Table Card â•â• -->
<div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #e9ecef;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="font-size:1rem;font-weight:700;color:#002F70;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-exclamation-triangle" style="color:#ea580c;"></i> Low Fuel Alert Table
        </div>
        <div class="inv-filter-bar" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <input type="text" id="alertSearch" placeholder="Search..." oninput="filterAlertTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;width:180px;">
            
            <select id="alertTypeFilter" onchange="filterAlertTable()" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;">
                <option value="">All Fuel Types</option>
                <option value="diesel">Diesel</option>
                <option value="kerosene">Kerosene</option>
                <option value="turbo diesel">Turbo Diesel</option>
                <option value="xcs plus">XCS Plus</option>
                <option value="xtra unl">XTRA UNL</option>
            </select>
            <button type="button" class="flt-btn flt-btn-search" onclick="filterAlertTable()"><i class="fas fa-search"></i> Filter</button>
        </div>
    </div>

    <div class="table-wrap" style="width:100%;overflow-x:hidden !important;box-sizing:border-box;">
        <table class="table" id="mgrAlertTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
            <colgroup>
                <col style="width: 12%;"> <!-- UGT No. -->
                <col style="width: 18%;"> <!-- Fuel Type -->
                <col style="width: 18%;"> <!-- Current Volume -->
                <col style="width: 18%;"> <!-- Reorder Level -->
                <col style="width: 18%;"> <!-- Critical Level -->
                <col style="width: 16%;"> <!-- Status -->
            </colgroup>
            <thead>
                <tr>
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">UGT No.</th>
                    <th style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Fuel Type</th>
                    <th style="text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Current Volume</th>
                    <th style="text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Reorder Level</th>
                    <th style="text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Critical Level</th>
                    <th style="text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Status</th>
                </tr>
            </thead>
            <tbody id="alertTableBody">
            <?php if (empty($alert_rows)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;padding:30px;color:#28a745;font-weight:700;">
                        <i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                        All Fuel Tanks have healthy stock levels!
                    </td>
                </tr>
            <?php else: ?>
            <?php foreach ($alert_rows as $ar): 
                $pct = $ar['fill_pct'];
                $crit_level = max(1000, round($ar['capacity'] * 0.15));
                $ugt_no = 'UGT-' . str_pad($ar['tank_id'], 2, '0', STR_PAD_LEFT);
            ?>
                <tr class="alert-row" 
                    data-name="<?= strtolower(htmlspecialchars($ar['tank_name'])) ?>"
                    data-desc="<?= strtolower(htmlspecialchars($ar['tank_description'])) ?>"
                    data-type="<?= strtolower(htmlspecialchars($ar['fuel_type'])) ?>"
                    data-alert="<?= strtolower(htmlspecialchars($ar['alert_type'])) ?>">
                    <td><code style="font-weight:700;color:#002F70;font-size:14.5px;"><?= htmlspecialchars($ugt_no) ?></code></td>
                    <td><strong style="color:#0f172a;"><?= htmlspecialchars($ar['fuel_type']) ?></strong></td>
                    <td style="text-align:right;font-weight:800;color:#002F70;"><?= number_format($ar['current_volume'], 2) ?> L</td>
                    <td style="text-align:right;font-weight:600;color:#eab308;"><?= number_format($ar['reorder_level'], 0) ?> L</td>
                    <td style="text-align:right;font-weight:600;color:#dc3545;"><?= number_format($crit_level, 0) ?> L</td>
                    <td style="text-align:center;">
                        <span class="inv-stock-badge" style="background:<?= $ar['status_color'] ?>20;color:<?= $ar['status_color'] ?>;border:1px solid <?= $ar['status_color'] ?>40;padding:3px 7px;border-radius:4px;font-size:11.5px;font-weight:700;text-transform:uppercase;white-space:nowrap;display:inline-block;line-height:1.2;">
                            <?= htmlspecialchars($ar['status']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="mgrAlertPagination" style="padding:10px 20px;"></div>
</div>
<?php endif; ?>



<!-- ══ VIEW FUEL MODAL ══ -->
<div class="modal-overlay" id="viewFuelModal" style="z-index:10000;">
    <div style="background:#fff;border-radius:14px;width:96%;max-width:960px;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 24px 40px rgba(0,0,0,.18);overflow:hidden;position:relative;z-index:10001;">
        <!-- Header -->
        <div style="padding:16px 22px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;flex-shrink:0;">
            <div style="font-size:15px;font-weight:800;color:#002F70;text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:8px;">
                <i class="fas fa-gas-pump"></i> <span id="vfmTitle">View Fuel</span>
            </div>
        </div>
        <!-- Sub-tabs inside modal -->
        <div style="display:flex;border-bottom:2px solid #e2e8f0;background:#f8fafc;flex-shrink:0;padding:0 16px;overflow-x: hidden;white-space:nowrap;gap:4px;">
            <button type="button" class="modal-tab-btn active" id="vfmTab1" onclick="vfmSwitchTab(1)"><i class="fas fa-info-circle"></i> Fuel Info</button>
            <button type="button" class="modal-tab-btn" id="vfmTab2" onclick="vfmSwitchTab(2)"><i class="fas fa-truck"></i> Deliveries</button>
        </div>
        <!-- Body -->
        <div style="overflow-y:auto;overflow-x:hidden;flex:1;padding:18px 20px;" id="vfmBody">
            <!-- TAB 1: Fuel Information -->
            <div id="vfmPane1">
                <div style="font-size:14px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-gas-pump"></i> Fuel Information</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 24px;margin-bottom:20px;">
                    <div><div style="font-size:15.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Fuel Type</div><div id="vfmFuelType" style="font-weight:700;color:#002F70;font-size:15px;"></div></div>
                    <div><div style="font-size:15.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Current Volume</div><div id="vfmCurrentVolume" style="font-weight:800;color:#002F70;font-size:18px;"></div></div>
                    <div><div style="font-size:15.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Storage Capacity</div><div id="vfmCapacity" style="font-weight:600;color:#475569;"></div></div>
                    <div><div style="font-size:15.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Reorder Level</div><div id="vfmReorderLevel" style="font-weight:600;color:#fd7e14;"></div></div>
                    <div><div style="font-size:15.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Critical Level</div><div id="vfmCriticalLevel" style="font-weight:600;color:#dc3545;"></div></div>
                    <div><div style="font-size:15.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Status</div><div id="vfmStatus"></div></div>
                    <div><div style="font-size:15.5px;font-weight:700;color:#94a3b8;text-transform:uppercase;">Last Updated</div><div id="vfmLastUpdated" style="color:#64748b;font-size:14.5px;"></div></div>
                </div>
            </div>
            <!-- TAB 2: Delivery History -->
            <div id="vfmPane2" style="display:none;">
                <div style="font-size:14px;font-weight:700;color:#002F70;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:2px solid #e9ecef;"><i class="fas fa-truck"></i> Fuel Delivery History</div>
                <div id="vfmDeliveryTable"><div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>
            </div>
        </div>
        <!-- Footer -->
        <div style="padding:12px 22px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;flex-shrink:0;">
            <button type="button" onclick="closeFuelModal()" class="int-btn-outline" style="border-color:#6b7280;color:#6b7280;">Close</button>
        </div>
    </div>
</div>
<!-- â•â• FUEL STOCK ADJUSTMENT REQUEST MODAL (STEP 5: MANAGER REQUEST) â•â• -->
<div class="modal-overlay" id="adjustReadingModal" style="z-index:10005; display:none; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;">
    <div style="background:#fff; border-radius:14px; width:96%; max-width:580px; max-height:88vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 24px 40px rgba(0,0,0,.25); position:relative; z-index:10006; margin:auto;">


        <!-- Header (Fixed) -->
        <div style="padding:14px 20px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; background:#f8fafc; flex-shrink:0;">
            <div style="font-size:15px; font-weight:800; color:#002F70; text-transform:uppercase; letter-spacing:.4px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-sliders-h" style="color:#fd7e14;"></i> Fuel Stock Adjustment Request (Manager)
            </div>
        </div>
        
        <!-- Body (Scrollable) -->
        <div style="padding:20px; padding-bottom:30px; overflow-y:auto; flex:1; min-height:0;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div class="form-group">
                    <label style="font-weight:700; font-size:14.5px; color:#334155;">Fuel Type *</label>
                    <select id="afrFuelTypeSelect" onchange="onAfrFuelTypeChange(this.value)" style="width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; font-weight:700; color:#0f172a;">
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-weight:700; font-size:14.5px; color:#334155;">UGT Number</label>
                    <input type="text" id="afrUgtNo" readonly style="background:#f1f5f9; color:#002F70; font-weight:700;">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:10px;">
                <div class="form-group">
                    <label style="font-weight:700; font-size:14.5px; color:#334155;">Current Volume (Auto)</label>
                    <input type="text" id="afrCurReading" readonly style="background:#f1f5f9; color:#002F70; font-weight:800;">
                </div>
                <div class="form-group">
                    <label style="font-weight:700; font-size:14.5px; color:#334155;">Actual Tank Dip Volume (L) *</label>
                    <input type="number" id="afrNewReading" step="0.01" placeholder="Enter dip volume" oninput="calcFuelVariance()" style="font-weight:700; border:1px solid #94a3b8;">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-top:10px; background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-size:14px; font-weight:700; color:#64748b;">Variance (Auto)</label>
                    <input type="text" id="afrVariance" readonly style="background:#fff; color:#0f172a; font-weight:800; font-size:15.5px; border:1px solid #cbd5e1;">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-size:14px; font-weight:700; color:#64748b;">Direction</label>
                    <input type="text" id="afrDirection" readonly style="background:#fff; color:#0f172a; font-weight:800; font-size:15.5px; border:1px solid #cbd5e1;">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-size:14px; font-weight:700; color:#64748b;">Adjustment (L)</label>
                    <input type="text" id="afrAdjLiters" readonly style="background:#fff; color:#002F70; font-weight:800; font-size:15.5px; border:1px solid #cbd5e1;">
                </div>
            </div>
            <div class="form-group" style="margin-top:12px;">
                <label style="font-weight:700; font-size:14.5px; color:#334155;">Adjustment Type *</label>
                <select id="afrAdjType" style="width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; font-weight:600;">
                    <option value="Physical Count / Tank Dip">Physical Count / Tank Dip</option>
                    <option value="Spill">Spill</option>
                    <option value="Leakage">Leakage</option>
                    <option value="Evaporation">Evaporation</option>
                    <option value="Wrong Fuel Delivery">Wrong Fuel Delivery</option>
                    <option value="Encoding Error">Encoding Error</option>
                    <option value="System Correction">System Correction</option>
                    <option value="Others">Others</option>
                </select>
            </div>
            <div class="form-group" style="margin-top:10px;">
                <label style="font-weight:700; font-size:14.5px; color:#334155;">Reason <span style="color:#dc2626;">*</span></label>
                <textarea id="afrReason" rows="2" placeholder="State reason for stock adjustment..." style="width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; box-sizing:border-box; resize:vertical;"></textarea>
            </div>
            <div class="form-group" style="margin-top:10px;">
                <label style="font-weight:700; font-size:14.5px; color:#334155;">Remarks <span style="color:#94a3b8; font-size:15.5px;">(Optional)</span></label>
                <textarea id="afrRemarks" rows="2" placeholder="Additional remarks..." style="width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; box-sizing:border-box; resize:vertical;"></textarea>
            </div>
            <div id="afrError" style="color:#dc3545; font-size:14.5px; margin-top:8px; display:none; font-weight:600;"></div>
            <div style="height: 10px;"></div>
        </div>

        <!-- Footer (Fixed, Clean Outline Buttons) -->
        <div style="padding:12px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:flex-end; align-items:center; gap:10px; flex-shrink:0;">
            <button type="button" onclick="saveFuelAdjustment()" style="background:#ffffff !important; color:#002F70 !important; border:1px solid #002F70 !important; border-radius:6px; padding:8px 20px; font-size:15.5px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:none !important;"><i class="fas fa-paper-plane"></i> Submit Request</button>
            <button type="button" onclick="closeAdjustReadingModal()" style="background:#ffffff !important; color:#475569 !important; border:1px solid #cbd5e1 !important; border-radius:6px; padding:8px 20px; font-size:15.5px; font-weight:600; cursor:pointer; box-shadow:none !important;">Cancel</button>
        </div>
    </div>
</div>




<script>
function esc(str) {
    if (!str) return '';
    return str.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function filterFuelTable() {
    var search = (document.getElementById('fuelSearch') || {}).value || '';
    var type   = (document.getElementById('fuelTypeFilter') || {}).value || '';
    var status = (document.getElementById('fuelStatusFilter') || {}).value || '';
    search = search.toLowerCase().trim();
    type   = type.toLowerCase().trim();
    status = status.toLowerCase().trim();

    var rows = document.querySelectorAll('#fuelTableBody tr.fuel-row');

    rows.forEach(function(row) {
        var match = true;
        var rName   = (row.dataset.name   || '').toLowerCase();
        var rDesc   = (row.dataset.desc   || '').toLowerCase();
        var rType   = (row.dataset.type   || '').toLowerCase();
        var rStatus = (row.dataset.status || '').toLowerCase();

        // Search filter
        if (search && rName.indexOf(search) === -1 && rDesc.indexOf(search) === -1 && rType.indexOf(search) === -1) {
            match = false;
        }
        
        // Fuel type filter (substring match)
        if (type && rType.indexOf(type) === -1) {
            match = false;
        }
        
        // Status filter logic (Low, Critical, Out of Stock all group together; Deactivated is direct match)
        if (status) {
            if (status === 'normal') {
                if (rStatus !== 'normal') match = false;
            } else if (status === 'deactivated') {
                if (rStatus !== 'deactivated') match = false;
            } else if (status === 'low' || status === 'critical' || status === 'out of stock' || status.indexOf('low') !== -1 || status.indexOf('critical') !== -1 || status.indexOf('out') !== -1) {
                if (rStatus !== 'low' && rStatus !== 'critical' && rStatus !== 'out of stock') {
                    match = false;
                }
            } else if (rStatus.indexOf(status) === -1) {
                match = false;
            }
        }

        if (match) {
            row.classList.remove('search-hidden');
            row.style.display = '';
        } else {
            row.classList.add('search-hidden');
            row.style.display = 'none';
        }
    });

    // Re-sync pagination after filter changes
    if (typeof setupTablePagination === 'function') {
        setupTablePagination('mgrFuelTable', null, 'mgrFuelPagination', 20);
    } else if (typeof setTablePage === 'function') {
        setTablePage('mgrFuelTable', 1);
    }
}

function resetFuelFilters() {
    if (document.getElementById('fuelSearch')) document.getElementById('fuelSearch').value = '';
    ['fuelTypeFilter', 'fuelStatusFilter'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.value = '';
            el.dispatchEvent(new Event('change'));
        }
    });
    filterFuelTable();
}

function filterDelTable() {
    var search = ((document.getElementById('delSearchInput') || {}).value || '').toLowerCase().trim();
    var type   = ((document.getElementById('delTypeFilter') || {}).value || '').toLowerCase().trim();

    var rows = document.querySelectorAll('#delTableBody tr.del-row');
    rows.forEach(function(row) {
        var rSearch = (row.dataset.search || '').toLowerCase();
        var rType   = (row.dataset.type   || '').toLowerCase();
        var match   = true;

        if (search && rSearch.indexOf(search) === -1) match = false;
        if (type   && rType.indexOf(type) === -1) match = false;

        if (match) {
            row.classList.remove('search-hidden');
            row.style.display = '';
        } else {
            row.classList.add('search-hidden');
            row.style.display = 'none';
        }
    });

    if (typeof setupTablePagination === 'function') {
        setupTablePagination('mgrDelTable', null, 'mgrDelPagination', 20);
    }
}

function filterReadingsTable() {
    var search = ((document.getElementById('readSearchInput') || {}).value || '').toLowerCase().trim();
    var type   = ((document.getElementById('readTypeFilter') || {}).value || '').toLowerCase().trim();

    var rows = document.querySelectorAll('#readingsTableBody tr.read-row');
    rows.forEach(function(row) {
        var rSearch = (row.dataset.search || '').toLowerCase();
        var rType   = (row.dataset.type   || '').toLowerCase();
        var match   = true;

        if (search && rSearch.indexOf(search) === -1) match = false;
        if (type   && rType.indexOf(type) === -1) match = false;

        if (match) {
            row.classList.remove('search-hidden');
            row.style.display = '';
        } else {
            row.classList.add('search-hidden');
            row.style.display = 'none';
        }
    });

    if (typeof setupTablePagination === 'function') {
        setupTablePagination('mgrReadingsTable', null, 'mgrReadingsPagination', 20);
    }
}

// ── Open View Fuel Modal ──────────────────────────────────────────────────
var _currentFuelRow = null;

function openFuelModalFromBtn(btn) {
    if (!btn) return;
    try {
        var raw = btn.getAttribute('data-fuel');
        if (!raw) return;
        var r = JSON.parse(raw);
        openFuelModal(r);
    } catch(err) {
        console.error("openFuelModalFromBtn error:", err);
    }
}

function openAdjustReadingModalFromBtn(btn) {
    if (!btn) return;
    try {
        var raw = btn.getAttribute('data-fuel');
        if (!raw) return;
        var r = JSON.parse(raw);
        openAdjustReadingModal(r);
    } catch(err) {
        console.error("openAdjustReadingModalFromBtn error:", err);
    }
}

function openFuelModal(r) {
    _currentFuelRow = r;
    var overlay = document.getElementById('viewFuelModal');
    if (!overlay) return;

    try {
        if (overlay.parentNode !== document.body) {
            document.body.appendChild(overlay);
        }

        var ugtNoStr = r.ugt_no || ('UGT-' + String(r.tank_id || 0).padStart(2, '0'));
        var titleEl = document.getElementById('vfmTitle');
        if (titleEl) titleEl.textContent = 'View Fuel — ' + ugtNoStr + ' (' + (r.fuel_type || '') + ')';

        var ftEl = document.getElementById('vfmFuelType');
        if (ftEl) ftEl.textContent = r.fuel_type || '—';

        var volEl = document.getElementById('vfmCurrentVolume');
        if (volEl) volEl.textContent = Number(r.current_volume || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';

        var capEl = document.getElementById('vfmCapacity');
        if (capEl) capEl.textContent = Number(r.capacity || 0).toLocaleString() + ' L';

        var reorderEl = document.getElementById('vfmReorderLevel');
        if (reorderEl) reorderEl.textContent = Number(r.reorder_level || 0).toLocaleString() + ' L';

        var critLevel = Math.max(1000, Math.round((r.capacity || 0) * 0.15));
        var critEl = document.getElementById('vfmCriticalLevel');
        if (critEl) critEl.textContent = critLevel.toLocaleString() + ' L';

        var statusHtml = '<span style="background:' + (r.status_color || '#28a745') + '20;color:' + (r.status_color || '#28a745') + ';border:1px solid ' + (r.status_color || '#28a745') + '40;padding:4px 10px;border-radius:4px;font-size:14px;font-weight:700;text-transform:uppercase;">' + (r.status || 'Normal') + '</span>';
        var statusEl = document.getElementById('vfmStatus');
        if (statusEl) statusEl.innerHTML = statusHtml;

        var lastUpdatedEl = document.getElementById('vfmLastUpdated');
        if (lastUpdatedEl) lastUpdatedEl.textContent = r.last_updated ? new Date(r.last_updated).toLocaleString() : '—';

        // Reset tabs
        if (typeof vfmSwitchTab === 'function') vfmSwitchTab(1);

        // Load deliveries via AJAX placeholders
        var delTbl = document.getElementById('vfmDeliveryTable');
        if (delTbl) delTbl.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

        // Set adjust reading modal fuel type if elements exist
        var afrFt = document.getElementById('afrFuelType'); if (afrFt) afrFt.value = r.fuel_type || '';
        var afrCur = document.getElementById('afrCurReading'); if (afrCur) afrCur.value = r.current_volume || 0;

        // Show modal
        overlay.classList.add('open');
        overlay.style.display = 'flex';
        overlay.style.position = 'fixed';
        overlay.style.top = '0';
        overlay.style.left = '0';
        overlay.style.right = '0';
        overlay.style.bottom = '0';
        overlay.style.zIndex = '10000';
    } catch (err) {
        console.error("openFuelModal error:", err);
        overlay.classList.add('open');
        overlay.style.display = 'flex';
        overlay.style.position = 'fixed';
        overlay.style.top = '0';
        overlay.style.left = '0';
        overlay.style.right = '0';
        overlay.style.bottom = '0';
        overlay.style.zIndex = '10000';
    }

    // Fetch AJAX data for delivery history
    fetch('manager_inventory_fuel.php?ajax=1&action=get_fuel_details&fuel_type=' + encodeURIComponent(r.fuel_type || ''))
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (!data.success) {
            var delTbl = document.getElementById('vfmDeliveryTable'); if (delTbl) delTbl.innerHTML = '<div style="text-align:center;padding:16px;color:#dc3545;">Failed to load data.</div>';
            return;
        }

        // Delivery History Table
        var delTbl = document.getElementById('vfmDeliveryTable');
        if (delTbl) {
            if (!data.deliveries || data.deliveries.length === 0) {
                delTbl.innerHTML = '<div style="text-align:center;padding:16px;color:#94a3b8;">No delivery records found.</div>';
            } else {
                /* ── header row ── */
                var dHtml = '<div style="display:grid;grid-template-columns:18% 14% 20% 14% 10% 1fr;gap:0;border-bottom:2px solid #e2e8f0;background:#f8fafc;padding:7px 6px;">'
                    + '<div style="font-size:10.5px;font-weight:700;color:#475569;text-transform:uppercase;">Delivery No.</div>'
                    + '<div style="font-size:10.5px;font-weight:700;color:#475569;text-transform:uppercase;">Del. Date</div>'
                    + '<div style="font-size:10.5px;font-weight:700;color:#475569;text-transform:uppercase;">Supplier</div>'
                    + '<div style="font-size:10.5px;font-weight:700;color:#475569;text-transform:uppercase;text-align:right;">Liters Rcvd</div>'
                    + '<div style="font-size:10.5px;font-weight:700;color:#475569;text-transform:uppercase;text-align:right;">Cost/L</div>'
                    + '<div style="font-size:10.5px;font-weight:700;color:#475569;text-transform:uppercase;">Received By</div>'
                    + '</div>';
                /* ── data rows ── */
                var rowBase = 'display:grid;grid-template-columns:18% 14% 20% 14% 10% 1fr;gap:0;border-bottom:1px solid #f1f5f9;padding:7px 6px;align-items:start;';
                var cellBase = 'font-size:12px;color:#334155;word-break:break-word;overflow-wrap:break-word;white-space:normal;padding-right:4px;';
                data.deliveries.forEach(function(d) {
                    var dateStr = d.delivery_date ? new Date(d.delivery_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—';
                    dHtml += '<div style="' + rowBase + '">'
                        + '<div style="' + cellBase + '"><code style="color:#002F70;font-weight:700;font-size:11px;">' + esc(d.invoice_no || 'DEL-' + d.id) + '</code></div>'
                        + '<div style="' + cellBase + '">' + dateStr + '</div>'
                        + '<div style="' + cellBase + '">' + esc(d.supplier || 'Petron Supplier') + '</div>'
                        + '<div style="' + cellBase + 'text-align:right;font-weight:700;color:#002F70;">' + Number(d.delivery_liters || 0).toLocaleString('en-US',{minimumFractionDigits:2}) + ' L</div>'
                        + '<div style="' + cellBase + 'text-align:right;font-weight:600;color:#002F70;">&#8369;' + Number(d.cost_per_liter || 65.50).toFixed(2) + '</div>'
                        + '<div style="' + cellBase + 'font-weight:600;color:#334155;line-height:1.5;">' + esc(d.received_by || d.received_by_name || 'Edgar Eslit') + '</div>'
                        + '</div>';
                });
                delTbl.innerHTML = dHtml;
            }
        }
    })
    .catch(function(err) {
        console.error("AJAX get_fuel_details error:", err);
        var delTbl = document.getElementById('vfmDeliveryTable'); if (delTbl) delTbl.innerHTML = '<div style="text-align:center;padding:16px;color:#dc3545;">Could not load delivery records.</div>';
    });
}

function closeFuelModal() {
    var overlay = document.getElementById('viewFuelModal');
    if (overlay) {
        overlay.classList.remove('open');
        overlay.style.display = 'none';
    }
}

function vfmSwitchTab(tabNum) {
    for (var i = 1; i <= 2; i++) {
        var pane = document.getElementById('vfmPane' + i);
        var btn = document.getElementById('vfmTab' + i);
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

// â”€â”€ Print Fuel Details â”€â”€
function printFuelDetails() {
    var r = _currentFuelRow;
    if (!r) return;
    var pw = window.open('', '_blank');
    pw.document.write('<!DOCTYPE html><html><head><title>Fuel Details — ' + (r.fuel_type || '') + '</title>');
    pw.document.write('<style>body{font-family:Arial,sans-serif;font-size:15.5px;color:#222;margin:0;padding:24px;}.header{background:#002F6C;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;}.header h2{margin:0;font-size:16px;}.section{border:1px solid #e2e8f0;border-top:none;padding:16px 20px;margin-bottom:12px;}.section h4{margin:0 0 10px;color:#002F6C;font-size:14px;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}table.info{width:100%;border-collapse:collapse;font-size:14.5px;}table.info td{padding:5px 0;border-bottom:1px solid #f1f5f9;}table.info td:first-child{color:#64748b;font-weight:600;width:180px;}.footer{text-align:center;font-size:15.5px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}</style></head><body>');
    pw.document.write('<div class="header"><h2>Fuel Inventory Record — ' + (r.fuel_type || '') + '</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
    pw.document.write('<div class="section"><h4>Fuel Information</h4><table class="info">');
    pw.document.write('<tr><td>Fuel Type:</td><td><strong>' + (r.fuel_type || '—') + '</strong></td></tr>');
    pw.document.write('<tr><td>Current Volume:</td><td><strong style="color:#002F70;font-size:14px;">' + Number(r.current_volume || 0).toLocaleString('en-US', {minimumFractionDigits: 2}) + ' L</strong></td></tr>');
    pw.document.write('<tr><td>Storage Capacity:</td><td>' + Number(r.capacity || 0).toLocaleString() + ' L</td></tr>');
    pw.document.write('<tr><td>Reorder Level:</td><td>' + Number(r.reorder_level || 0).toLocaleString() + ' L</td></tr>');
    pw.document.write('<tr><td>Status:</td><td><span style="background:' + (r.status_color || '#28a745') + '20;color:' + (r.status_color || '#28a745') + ';padding:2px 8px;border-radius:4px;font-size:14px;font-weight:700;">' + (r.status || 'Normal') + '</span></td></tr>');
    pw.document.write('<tr><td>Last Updated:</td><td>' + (r.last_updated ? new Date(r.last_updated).toLocaleString() : '—') + '</td></tr>');
    pw.document.write('</table></div>');
    pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    pw.document.write('</body></html>');
    pw.document.close();
    setTimeout(function() { pw.print(); }, 300);
}

window._allFuelRows = <?= json_encode(array_values($rows)) ?>;

// â”€â”€ Adjust Reading Modal â”€â”€
function openAdjustReadingModal(r) {
    closeFuelModal(); // Close view fuel modal to prevent overlapping/sapaw

    var modal = document.getElementById('adjustReadingModal');
    if (!modal) return;
    if (modal.parentNode !== document.body) {
        document.body.appendChild(modal);
    }

    // Populate Fuel Type Select dropdown with index keys
    var sel = document.getElementById('afrFuelTypeSelect');
    if (sel && window._allFuelRows && window._allFuelRows.length > 0) {
        sel.innerHTML = '';
        window._allFuelRows.forEach(function(item, idx) {
            var opt = document.createElement('option');
            opt.value = idx;
            opt.textContent = item.fuel_type + ' (' + (item.ugt_no || ('UGT-' + String(idx + 1).padStart(2, '0'))) + ')';
            sel.appendChild(opt);
        });
    }

    if (r) {
        _currentFuelRow = r;
        var matchIdx = -1;
        if (window._allFuelRows && window._allFuelRows.length > 0) {
            matchIdx = window._allFuelRows.findIndex(function(item) {
                return (item.ugt_no && r.ugt_no && item.ugt_no === r.ugt_no) ||
                       (item.fuel_type.toLowerCase() === (r.fuel_type || '').toLowerCase());
            });
        }
        if (matchIdx >= 0) {
            if (sel) sel.value = matchIdx;
            onAfrFuelTypeChange(matchIdx);
        } else {
            if (sel) sel.value = 0;
            onAfrFuelTypeChange(0);
        }
    } else {
        if (sel) sel.value = 0;
        onAfrFuelTypeChange(0);
    }

    document.getElementById('afrNewReading').value = '';
    document.getElementById('afrVariance').value = '0.00 L';
    document.getElementById('afrDirection').value = 'Balanced';
    document.getElementById('afrAdjLiters').value = '0.00 L';
    document.getElementById('afrAdjType').value = 'Physical Count / Tank Dip';
    document.getElementById('afrReason').value = '';
    document.getElementById('afrRemarks').value = '';
    document.getElementById('afrError').style.display = 'none';
    modal.classList.add('open');
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.right = '0';
    modal.style.bottom = '0';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
    modal.style.padding = '20px';
    modal.style.boxSizing = 'border-box';
    modal.style.zIndex = '10005';

}

function onAfrFuelTypeChange(val) {
    if (!window._allFuelRows || window._allFuelRows.length === 0) return;
    var index = parseInt(val);
    if (isNaN(index) || index < 0 || index >= window._allFuelRows.length) {
        index = 0;
    }
    var target = window._allFuelRows[index];
    if (target) {
        _currentFuelRow = target;
        var ugtName = target.ugt_no || ('UGT-' + String(target.tank_id || (index + 1)).padStart(2, '0'));
        document.getElementById('afrUgtNo').value = ugtName;

        var vol = parseFloat(target.current_volume);
        if (isNaN(vol) || vol <= 0) {
            vol = parseFloat(target.ending_system) || parseFloat(target.current_level) || 0;
        }
        if (vol <= 0) {
            var activeTank = window._allFuelRows.find(function(r) {
                var v = parseFloat(r.current_volume);
                return r.fuel_type.toLowerCase() === target.fuel_type.toLowerCase() && !isNaN(v) && v > 0;
            });
            if (activeTank) {
                vol = parseFloat(activeTank.current_volume);
            }
        }

        document.getElementById('afrCurReading').value = Number(vol || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
        calcFuelVariance();
    }
}


function closeAdjustReadingModal() {
    var modal = document.getElementById('adjustReadingModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
}

function calcFuelVariance() {
    var curText = (document.getElementById('afrCurReading').value || '0').replace(/[^0-9.-]/g, '');
    var cur = parseFloat(curText) || 0;
    var nwText = document.getElementById('afrNewReading').value.trim();
    if (!nwText || isNaN(nwText)) {
        document.getElementById('afrVariance').value = '0.00 L';
        document.getElementById('afrDirection').value = 'Balanced';
        document.getElementById('afrAdjLiters').value = '0.00 L';
        return;
    }
    var nw  = parseFloat(nwText);
    var variance = nw - cur;
    var absVar = Math.abs(variance);
    
    document.getElementById('afrVariance').value = (variance >= 0 ? '+' : '') + variance.toFixed(2) + ' L';
    document.getElementById('afrDirection').value = variance > 0 ? 'Increase (+)' : (variance < 0 ? 'Decrease (-)' : 'Balanced');
    document.getElementById('afrAdjLiters').value = absVar.toFixed(2) + ' L';
}

function saveFuelAdjustment() {
    var actualDipInput = document.getElementById('afrNewReading');
    var reasonInput    = document.getElementById('afrReason');
    var actualDip      = actualDipInput.value.trim();
    var sel            = document.getElementById('afrFuelTypeSelect');
    var target         = (window._allFuelRows && sel) ? window._allFuelRows[sel.value] : null;
    var fuelType       = target ? target.fuel_type : (document.getElementById('afrFuelTypeSelect').selectedOptions[0]?.text || '');
    var ugtNo          = document.getElementById('afrUgtNo').value.trim();
    var adjType        = document.getElementById('afrAdjType').value;
    var reason         = reasonInput.value.trim();
    var remarks        = document.getElementById('afrRemarks').value.trim();
    var errEl          = document.getElementById('afrError');

    // Reset styles and errors
    if (actualDipInput) actualDipInput.style.border = '1px solid #94a3b8';
    if (reasonInput)    reasonInput.style.border    = '1px solid #cbd5e1';
    errEl.style.display = 'none';

    if (!actualDip || isNaN(actualDip)) {
        errEl.textContent = 'Actual Tank Dip Volume is required and must be a valid number.';
        errEl.style.display = 'block';
        if (actualDipInput) {
            actualDipInput.style.border = '2px solid #dc2626';
            actualDipInput.focus();
        }
        errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return;
    }
    if (!reason) {
        errEl.textContent = 'Reason for adjustment is required.';
        errEl.style.display = 'block';
        if (reasonInput) {
            reasonInput.style.border = '2px solid #dc2626';
            reasonInput.focus();
        }
        errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return;
    }

    var saveBtn = document.querySelector('#adjustReadingModal button[onclick="saveFuelAdjustment()"]');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...'; }

    var fd = new FormData();
    fd.append('action',             'create_adjustment');
    fd.append('fuel_type',          fuelType);
    fd.append('ugt_no',             ugtNo);
    fd.append('actual_dip_volume',  actualDip);
    fd.append('adjustment_type',    adjType);
    fd.append('reason',             reason);
    fd.append('remarks',            remarks);

    fetch('../backend/api/fuel_adjustments.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request'; }
        if (!data.success) {
            errEl.textContent = data.message || data.error || 'Failed to submit adjustment request.';
            errEl.style.display = 'block';
            errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        // Success — close both modals
        closeAdjustReadingModal();
        closeFuelModal();

        var msg = data.message || ('Stock adjustment request for ' + fuelType + ' submitted. Pending Admin approval.');
        if (window.showPetronFlash) {
            window.showPetronFlash(msg, 'success', 5000);
        } else if (window.showToast) {
            window.showToast(msg, 'success', 5000);
        } else {
            alert(msg);
        }

        setTimeout(function() { location.reload(); }, 1200);
    })
    .catch(function(err) {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request'; }
        errEl.textContent = 'Network error. Please try again.';
        errEl.style.display = 'block';
        errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
}


// ── Reset Fuel Filters ──
function resetFuelFilters() {
    if (document.getElementById('fuelSearch')) document.getElementById('fuelSearch').value = '';
    ['fuelTypeFilter', 'fuelStatusFilter'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.value = '';
            el.dispatchEvent(new Event('change'));
        }
    });
    filterFuelTable();
}

// â”€â”€ Alert Table Filter â”€â”€
function filterAlertTable() {
    var search   = (document.getElementById('alertSearch') || {}).value || '';
    var ftype    = (document.getElementById('alertTypeFilter') || {}).value || '';
    var severity = (document.getElementById('alertSeverityFilter') || {}).value || '';
    search   = search.toLowerCase().trim();
    ftype    = ftype.toLowerCase().trim();
    severity = severity.toLowerCase().trim();

    document.querySelectorAll('#alertTableBody tr.alert-row').forEach(function(row) {
        var name  = (row.dataset.name  || '').toLowerCase();
        var desc  = (row.dataset.desc  || '').toLowerCase();
        var type  = (row.dataset.type  || '').toLowerCase();
        var alert = (row.dataset.alert || '').toLowerCase();

        var ok = true;
        if (search && name.indexOf(search) === -1 && desc.indexOf(search) === -1 && type.indexOf(search) === -1) ok = false;
        if (ftype  && type.indexOf(ftype) === -1) ok = false;

        // Group all alert severities together (Low Fuel, Critical Fuel, Empty Tank)
        if (severity) {
            if (severity === 'low fuel' || severity === 'critical fuel' || severity === 'empty tank' || severity === 'low' || severity === 'critical' || severity === 'empty') {
                if (alert.indexOf('low') === -1 && alert.indexOf('critical') === -1 && alert.indexOf('empty') === -1 && alert.indexOf('out') === -1) {
                    ok = false;
                }
            } else if (alert.indexOf(severity) === -1) {
                ok = false;
            }
        }
        row.style.display = ok ? '' : 'none';
    });

    if (typeof setupTablePagination === 'function') {
        setupTablePagination('mgrAlertTable', null, 'mgrAlertPagination', 20);
    }
}

// â”€â”€ Export Functions (Alerts) â”€â”€
function exportAlertTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrAlertTable', 'Fuel Stock Alerts Report');
    } else {
        window.print();
    }
}

function exportAlertTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrAlertTable', 'fuel_stock_alerts.xls');
    } else {
        alert('Excel export not supported on this page.');
    }
}

function exportAlertTableCSV() {
    var rows = document.querySelectorAll('#mgrAlertTable tr');
    var csv  = [];
    rows.forEach(function(row) {
        var cells = row.querySelectorAll('td, th');
        var data  = [];
        cells.forEach(function(cell, idx) {
            if (idx === cells.length - 1) return; // skip Actions column
            var text = cell.innerText.trim().replace(/"/g, '""');
            data.push('"' + text + '"');
        });
        if (data.length) csv.push(data.join(','));
    });
    var blob = new Blob([csv.join('\n')], {type: 'text/csv'});
    var a    = document.createElement('a');
    a.href  = URL.createObjectURL(blob);
    a.download = 'fuel_stock_alerts_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// â”€â”€ Print Tank Alert â”€â”€
function printTankAlert(r) {
    var alertColor = r.alert_type === 'Empty Tank' ? '#dc3545' : (r.alert_type === 'Critical Fuel' ? '#dc3545' : '#fd7e14');
    var pw = window.open('', '_blank');
    pw.document.write('<!DOCTYPE html><html><head><title>Fuel Alert — ' + esc(r.tank_name) + '</title>');
    pw.document.write('<style>');
    pw.document.write('body{font-family:Arial,sans-serif;font-size:15.5px;color:#222;margin:0;padding:24px;}');
    pw.document.write('.header{background:' + alertColor + ';color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;}');
    pw.document.write('.header h2{margin:0;font-size:16px;letter-spacing:.5px;}');
    pw.document.write('.header p{margin:4px 0 0;font-size:14px;opacity:.8;}');
    pw.document.write('.section{border:1px solid #e2e8f0;border-top:none;padding:16px 20px;margin-bottom:12px;}');
    pw.document.write('.section h4{margin:0 0 10px;color:#002F6C;font-size:14px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}');
    pw.document.write('table.info{width:100%;border-collapse:collapse;font-size:14.5px;}');
    pw.document.write('table.info tr td:first-child{color:#64748b;font-weight:600;width:180px;padding:5px 0;}');
    pw.document.write('table.info tr td{padding:5px 0;border-bottom:1px solid #f1f5f9;}');
    pw.document.write('.badge{display:inline-block;padding:3px 10px;border-radius:4px;font-size:14px;font-weight:700;}');
    pw.document.write('.alert-box{background:' + alertColor + '15;border:1px solid ' + alertColor + '40;border-radius:6px;padding:12px 16px;margin:12px 0;font-weight:700;color:' + alertColor + ';}');
    pw.document.write('.footer{text-align:center;font-size:15.5px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}');
    pw.document.write('</style></head><body>');
    pw.document.write('<div class="header"><h2>âš  Fuel Stock Alert</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
    pw.document.write('<div class="section"><h4>Tank Information</h4>');
    pw.document.write('<table class="info">');
    var ugtDisp = r.ugt_no || ('UGT-' + String(r.tank_id || 0).padStart(2, '0'));
    pw.document.write('<tr><td>UGT No:</td><td><strong>' + esc(ugtDisp) + '</strong></td></tr>');
    pw.document.write('<tr><td>Tank Reference:</td><td><strong>' + esc(r.tank_name) + '</strong></td></tr>');
    pw.document.write('<tr><td>Location:</td><td>' + esc(r.tank_description) + '</td></tr>');
    pw.document.write('<tr><td>Fuel Type:</td><td><strong>' + esc(r.fuel_type) + '</strong></td></tr>');
    pw.document.write('</table></div>');
    pw.document.write('<div class="section"><h4>Alert Details</h4>');
    pw.document.write('<div class="alert-box">' + esc(r.alert_type) + ': ' + esc(r.recommended_action) + '</div>');
    pw.document.write('<table class="info">');
    pw.document.write('<tr><td>Current Volume:</td><td><strong style="color:#002F70;font-size:14px;">' + Number(r.current_volume).toLocaleString('en-US',{minimumFractionDigits:2}) + ' L</strong></td></tr>');
    pw.document.write('<tr><td>Tank Capacity:</td><td>' + Number(r.capacity).toLocaleString() + ' L</td></tr>');
    pw.document.write('<tr><td>Reorder Level:</td><td>' + (r.reorder_level ? Number(r.reorder_level).toLocaleString('en-US',{minimumFractionDigits:2}) + ' L' : '—') + '</td></tr>');
    pw.document.write('<tr><td>Last Updated:</td><td>' + (r.last_updated ? new Date(r.last_updated).toLocaleString() : '—') + '</td></tr>');
    pw.document.write('</table></div>');
    pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    pw.document.write('</body></html>');
    pw.document.close();
    pw.print();
}

// â”€â”€ Create Fuel Request Modal â”€â”€
var _fuelReqData = {};
function openCreateFuelRequest(fuelType, currentVolume, capacity, alertType) {
    _fuelReqData = { fuel_type: fuelType, current_level: currentVolume, capacity: capacity, stock_status: alertType.toUpperCase() };
    document.getElementById('frFuelType').textContent    = fuelType;
    document.getElementById('frCurrentVol').textContent  = Number(currentVolume).toLocaleString('en-US',{minimumFractionDigits:2}) + ' L';
    document.getElementById('frCapacity').textContent    = Number(capacity).toLocaleString() + ' L';
    document.getElementById('frAlertType').textContent   = alertType;
    document.getElementById('frAlertType').style.color   = alertType === 'Empty Tank' ? '#dc3545' : (alertType === 'Critical Fuel' ? '#dc3545' : '#fd7e14');
    var suggested = Math.max(0, capacity - currentVolume);
    document.getElementById('frRequestedLiters').value  = suggested.toFixed(2);
    document.getElementById('frRemarks').value           = '';
    document.getElementById('frResultMsg').style.display = 'none';
    document.getElementById('frSubmitBtn').disabled      = false;
    document.getElementById('frSubmitBtn').innerHTML     = '<i class="fas fa-paper-plane"></i> Submit Request';
    document.getElementById('createFuelRequestModal').classList.add('open');
}

function closeCreateFuelRequest() {
    document.getElementById('createFuelRequestModal').classList.remove('open');
}

document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('createFuelRequestForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var liters = parseFloat(document.getElementById('frRequestedLiters').value);
            if (!liters || liters <= 0) {
                alert('Please enter a valid requested liters amount.');
                return;
            }
            var btn = document.getElementById('frSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

            var payload = {
                fuel_type:        _fuelReqData.fuel_type,
                current_level:    _fuelReqData.current_level,
                capacity:         _fuelReqData.capacity,
                stock_status:     _fuelReqData.stock_status,
                requested_liters: liters,
                remarks:          document.getElementById('frRemarks').value.trim()
            };

            fetch('../backend/api/fuel_stock_request.php?action=create', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify(payload)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                var msgEl = document.getElementById('frResultMsg');
                if (res.success) {
                    msgEl.innerHTML  = '<i class="fas fa-check-circle" style="color:#28a745;"></i> Request submitted successfully! It is now <strong>Pending</strong> review.';
                    msgEl.style.background = '#e6f4ea';
                    msgEl.style.border     = '1px solid #c3e6cb';
                    msgEl.style.color      = '#155724';
                    btn.innerHTML = '<i class="fas fa-check"></i> Submitted';
                } else {
                    msgEl.innerHTML  = '<i class="fas fa-exclamation-circle" style="color:#dc3545;"></i> ' + (res.message || 'Submission failed.');
                    msgEl.style.background = '#fce8e6';
                    msgEl.style.border     = '1px solid #f5c2c7';
                    msgEl.style.color      = '#721c24';
                    btn.disabled  = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
                }
                msgEl.style.display = 'block';
            })
            .catch(function() {
                var msgEl = document.getElementById('frResultMsg');
                msgEl.innerHTML = '<i class="fas fa-exclamation-circle" style="color:#dc3545;"></i> Connection error. Please try again.';
                msgEl.style.display = 'block';
                btn.disabled  = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
            });
        });
    }

    if (typeof setupTablePagination === 'function') {
        setupTablePagination('mgrFuelTable', null, 'mgrFuelPagination', 20);
        setupTablePagination('mgrDelTable', null, 'mgrDelPagination', 20);
        setupTablePagination('mgrReadingsTable', null, 'mgrReadingsPagination', 20);
        setupTablePagination('mgrAlertTable', null, 'mgrAlertPagination', 20);
        setupTablePagination('mgrMovTable', null, 'mgrMovPagination', 20);
    }
});

// â”€â”€ Movement Table Filter â”€â”€
function filterMovTable() {
    var search = ((document.getElementById('movSearch') || {}).value || '').toLowerCase();
    var fuel   = ((document.getElementById('movFuelFilter') || {}).value || '').toLowerCase();
    var type   = ((document.getElementById('movTypeFilter') || {}).value || '').toLowerCase();
    document.querySelectorAll('#movTableBody tr.mov-row').forEach(function(row) {
        var ok = true;
        if (search && (row.dataset.search || '').indexOf(search) === -1) ok = false;
        if (fuel   && (row.dataset.fuel   || '') !== fuel)  ok = false;
        if (type   && (row.dataset.type   || '').indexOf(type) === -1)  ok = false;
        row.style.display = ok ? '' : 'none';
    });
}

// â”€â”€ Movement Details Modal â”€â”€
function viewMovDetails(m) {
    var typeColor = m.movement_id.startsWith('DEL') ? '#28a745'
                 : (m.movement_id.startsWith('SAL') ? '#dc3545' : '#6f42c1');
    document.getElementById('movDetId').textContent   = m.movement_id;
    document.getElementById('movDetDate').textContent = m.movement_date || '—';
    document.getElementById('movDetFuel').textContent = m.fuel_type || '—';
    document.getElementById('movDetTank').textContent = m.tank || '—';
    document.getElementById('movDetType').innerHTML   = '<span style="background:' + typeColor + '20;color:' + typeColor + ';border:1px solid ' + typeColor + '40;padding:3px 10px;border-radius:4px;font-size:14px;font-weight:700;">' + esc(m.movement_type) + '</span>';
    var lSign = m.movement_id.startsWith('SAL') ? '-' : (parseFloat(m.liters) >= 0 ? '+' : '');
    document.getElementById('movDetLiters').textContent    = lSign + Number(m.liters).toLocaleString('en-US',{minimumFractionDigits:2}) + ' L';
    document.getElementById('movDetLiters').style.color    = typeColor;
    document.getElementById('movDetPrevVol').textContent   = m.previous_volume != null ? Number(m.previous_volume).toLocaleString('en-US',{minimumFractionDigits:2}) + ' L' : '—';
    document.getElementById('movDetNewVol').textContent    = m.new_volume != null ? Number(m.new_volume).toLocaleString('en-US',{minimumFractionDigits:2}) + ' L' : '—';
    document.getElementById('movDetBy').textContent        = m.performed_by || '—';
    document.getElementById('movDetRef').textContent       = m.ref_no || '—';
    document.getElementById('movDetStatus').textContent    = m.status || '—';
    document.getElementById('movDetNotes').textContent     = m.notes || '—';
    document.getElementById('movDetailModal').classList.add('open');
}

function closeMovDetailModal() {
    document.getElementById('movDetailModal').classList.remove('open');
}

// â”€â”€ Print Movement Record â”€â”€
function printMovRecord(m) {
    var typeColor = m.movement_id.startsWith('DEL') ? '#28a745'
                 : (m.movement_id.startsWith('SAL') ? '#dc3545' : '#6f42c1');
    var lSign = m.movement_id.startsWith('SAL') ? '-' : (parseFloat(m.liters) >= 0 ? '+' : '');
    var pw = window.open('', '_blank');
    pw.document.write('<!DOCTYPE html><html><head><title>Movement Record — ' + esc(m.movement_id) + '</title>');
    pw.document.write('<style>');
    pw.document.write('body{font-family:Arial,sans-serif;font-size:15.5px;color:#222;margin:0;padding:24px;}');
    pw.document.write('.header{background:#002F6C;color:#fff;padding:16px 20px;border-radius:6px 6px 0 0;}');
    pw.document.write('.header h2{margin:0;font-size:16px;} .header p{margin:4px 0 0;font-size:14px;opacity:.8;}');
    pw.document.write('.section{border:1px solid #e2e8f0;border-top:none;padding:16px 20px;margin-bottom:12px;}');
    pw.document.write('.section h4{margin:0 0 10px;color:#002F6C;font-size:14px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;}');
    pw.document.write('table.info{width:100%;border-collapse:collapse;font-size:14.5px;}');
    pw.document.write('table.info td:first-child{color:#64748b;font-weight:600;width:180px;padding:5px 0;}');
    pw.document.write('table.info td{padding:5px 0;border-bottom:1px solid #f1f5f9;}');
    pw.document.write('.badge{display:inline-block;padding:3px 10px;border-radius:4px;font-size:14px;font-weight:700;}');
    pw.document.write('.footer{text-align:center;font-size:15.5px;color:#94a3b8;margin-top:20px;border-top:1px solid #e2e8f0;padding-top:10px;}');
    pw.document.write('</style></head><body>');
    pw.document.write('<div class="header"><h2>Fuel Movement Record</h2><p>Petron Station Management System &mdash; Printed: ' + new Date().toLocaleString() + '</p></div>');
    pw.document.write('<div class="section"><h4>Movement Information</h4><table class="info">');
    pw.document.write('<tr><td>Movement ID:</td><td><strong>' + esc(m.movement_id) + '</strong></td></tr>');
    pw.document.write('<tr><td>Date:</td><td>' + esc(m.movement_date) + '</td></tr>');
    pw.document.write('<tr><td>Fuel Type:</td><td><strong>' + esc(m.fuel_type) + '</strong></td></tr>');
    pw.document.write('<tr><td>Tank / Source:</td><td>' + esc(m.tank) + '</td></tr>');
    pw.document.write('<tr><td>Movement Type:</td><td><span class="badge" style="background:' + typeColor + '20;color:' + typeColor + ';border:1px solid ' + typeColor + '40;">' + esc(m.movement_type) + '</span></td></tr>');
    pw.document.write('<tr><td>Liters:</td><td><strong style="font-size:14px;color:' + typeColor + ';">' + lSign + Number(m.liters).toLocaleString('en-US',{minimumFractionDigits:2}) + ' L</strong></td></tr>');
    pw.document.write('<tr><td>Previous Volume:</td><td>' + (m.previous_volume != null ? Number(m.previous_volume).toLocaleString('en-US',{minimumFractionDigits:2}) + ' L' : '—') + '</td></tr>');
    pw.document.write('<tr><td>New Volume:</td><td>' + (m.new_volume != null ? Number(m.new_volume).toLocaleString('en-US',{minimumFractionDigits:2}) + ' L' : '—') + '</td></tr>');
    pw.document.write('<tr><td>Performed By:</td><td>' + esc(m.performed_by) + '</td></tr>');
    pw.document.write('<tr><td>Reference No.:</td><td>' + esc(m.ref_no || '—') + '</td></tr>');
    pw.document.write('<tr><td>Status:</td><td>' + esc(m.status || '—') + '</td></tr>');
    pw.document.write('<tr><td>Notes:</td><td>' + esc(m.notes || '—') + '</td></tr>');
    pw.document.write('</table></div>');
    pw.document.write('<div class="footer">Petron Station Management System &copy; ' + new Date().getFullYear() + '</div>');
    pw.document.write('</body></html>');
    pw.document.close();
    pw.print();
}

// ── Fuel Movement Pagination & Filter Engine ──
var mfmState = { page: 1, per_page: 10 };

function mfmRender() {
    var sq = (document.getElementById('mgrFmovSearch') ? document.getElementById('mgrFmovSearch').value : '').toLowerCase().trim();
    var ft = (document.getElementById('mgrFmovTypeFilter') ? document.getElementById('mgrFmovTypeFilter').value : '').toLowerCase().trim();
    var mv = (document.getElementById('mgrFmovMoveFilter') ? document.getElementById('mgrFmovMoveFilter').value : '').toLowerCase().trim();

    var allRows = Array.from(document.querySelectorAll('#mgrFuelMovementTbody tr.mgr-fmov-row'));
    var matched = allRows.filter(function(row) {
        var sText = (row.getAttribute('data-search') || '').toLowerCase();
        var fType = (row.getAttribute('data-fuel-type') || '').toLowerCase();
        var mType = (row.getAttribute('data-move-type') || '').toLowerCase();

        var matchS = !sq || sText.indexOf(sq) !== -1;
        var matchF = !ft || fType.indexOf(ft) !== -1;
        var matchM = !mv || mType.indexOf(mv) !== -1;

        return matchS && matchF && matchM;
    });

    var tot = matched.length;
    var pp = mfmState.per_page || 10;
    var tp = Math.max(1, Math.ceil(tot / pp));

    if (mfmState.page > tp) mfmState.page = tp;
    if (mfmState.page < 1) mfmState.page = 1;
    var p = mfmState.page;

    var start = (p - 1) * pp;
    var end   = p * pp;

    allRows.forEach(function(r) {
        var isMatched = matched.includes(r);
        var matchIndex = matched.indexOf(r);
        r.style.display = (isMatched && matchIndex >= start && matchIndex < end) ? '' : 'none';
    });

    // Update counter
    var showingStart = tot === 0 ? 0 : start + 1;
    var showingEnd   = Math.min(end, tot);
    var entriesLbl   = document.getElementById('mfmShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
    }

    var lbl = document.getElementById('mfmPageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

    var prev = document.getElementById('mfmPrevBtn');
    var next = document.getElementById('mfmNextBtn');
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

window.mfmState = mfmState;
window.mfmGoPage = function(p) {
    mfmState.page = p;
    mfmRender();
};

window.mfmChangePerPage = function() {
    var s = document.getElementById('mfmPerPage');
    if (s) mfmState.per_page = parseInt(s.value, 10);
    mfmState.page = 1;
    mfmRender();
};

function filterMgrFuelMovementTable() {
    mfmState.page = 1;
    mfmRender();
}

function resetMgrFuelMovementFilters() {
    if (document.getElementById('mgrFmovSearch')) document.getElementById('mgrFmovSearch').value = '';
    ['mgrFmovTypeFilter', 'mgrFmovMoveFilter'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.value = '';
            el.dispatchEvent(new Event('change'));
        }
    });
    mfmState.page = 1;
    mfmRender();
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
        if (select.id.indexOf('Move') !== -1 || select.id.indexOf('Type') !== -1) {
            wrap.style.minWidth = '180px';
        } else {
            wrap.style.minWidth = '150px';
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
    mfmRender();
    if (typeof setupPetronDownwardDropdowns === 'function') {
        setupPetronDownwardDropdowns([
            '#fuelTypeFilter', '#fuelStatusFilter',
            '#delTypeFilter',
            '#mgrFmovTypeFilter', '#mgrFmovMoveFilter',
            '#readTypeFilter',
            '#alertTypeFilter'
        ]);
    }
});

// ── Movement Export Functions ──
function exportMovTablePDF() {
    if (typeof exportTableToPDF === 'function') {
        exportTableToPDF('mgrMovTable', 'Fuel Movement History Report');
    } else { window.print(); }
}
function exportMovTableExcel() {
    if (typeof exportTableToExcel === 'function') {
        exportTableToExcel('mgrMovTable', 'fuel_movement_history.xls');
    } else { alert('Excel export not supported.'); }
}
function exportMovTableCSV() {
    var rows = document.querySelectorAll('#mgrMovTable tr');
    var csv  = [];
    rows.forEach(function(row) {
        var cells = row.querySelectorAll('td, th');
        var data  = [];
        cells.forEach(function(cell, idx) {
            if (idx === cells.length - 1) return;
            data.push('"' + cell.innerText.trim().replace(/"/g,'""') + '"');
        });
        if (data.length) csv.push(data.join(','));
    });
    var blob = new Blob([csv.join('\n')], {type:'text/csv'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'fuel_movement_history_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
</script>

<!-- â•â• Create Fuel Request Modal â•â• -->
<div class="modal-overlay" id="createFuelRequestModal">
    <div class="modal-box" style="width:520px;">
        <div class="modal-header">
            <h3><i class="fas fa-clipboard-list" style="color:#002F70;"></i> Create Fuel Delivery Request</h3>
        </div>
        <div class="modal-body">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:15.5px;">
                <table style="width:100%;border-collapse:collapse;">
                    <tr>
                        <td style="color:#64748b;font-weight:600;padding:4px 0;width:140px;">Fuel Type:</td>
                        <td style="font-weight:700;color:#002F70;" id="frFuelType">—</td>
                    </tr>
                    <tr>
                        <td style="color:#64748b;font-weight:600;padding:4px 0;">Current Volume:</td>
                        <td style="font-weight:700;color:#002F70;" id="frCurrentVol">—</td>
                    </tr>
                    <tr>
                        <td style="color:#64748b;font-weight:600;padding:4px 0;">Tank Capacity:</td>
                        <td style="font-weight:600;" id="frCapacity">—</td>
                    </tr>
                    <tr>
                        <td style="color:#64748b;font-weight:600;padding:4px 0;">Alert Status:</td>
                        <td style="font-weight:700;" id="frAlertType">—</td>
                    </tr>
                </table>
            </div>
            <form id="createFuelRequestForm">
                <div style="margin-bottom:14px;">
                    <label style="font-size:14.5px;font-weight:700;color:#475569;display:block;margin-bottom:5px;">
                        <i class="fas fa-tint"></i> Requested Liters <span style="color:#dc3545;">*</span>
                    </label>
                    <input type="number" id="frRequestedLiters" name="requested_liters" min="1" step="0.01"
                        style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;font-weight:700;color:#002F70;box-sizing:border-box;"
                        placeholder="e.g. 40000.00" required>
                    <small style="color:#64748b;">Pre-filled with the estimated refill volume (capacity - current level).</small>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="font-size:14.5px;font-weight:700;color:#475569;display:block;margin-bottom:5px;">
                        <i class="fas fa-comment-alt"></i> Remarks (optional)
                    </label>
                    <textarea id="frRemarks" name="remarks" rows="3"
                        style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;resize:vertical;box-sizing:border-box;"
                        placeholder="Additional notes for the delivery request..."></textarea>
                </div>
                <div id="frResultMsg" style="display:none;padding:10px 14px;border-radius:6px;font-size:15.5px;margin-bottom:14px;"></div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="closeCreateFuelRequest()" class="btn-cancel" style="height:36px;font-size:15.5px;padding:0 16px;">Cancel</button>
                    <button type="submit" id="frSubmitBtn" style="background:#002F70;color:#fff;border:none;border-radius:6px;padding:0 20px;height:36px;font-size:15.5px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- â•â• Movement Detail Modal â•â• -->
<div class="modal-overlay" id="movDetailModal">
    <div class="modal-box" style="width:540px;">
        <div class="modal-header">
            <h3><i class="fas fa-exchange-alt" style="color:#002F70;"></i> Movement Details</h3>
        </div>
        <div class="modal-body">
            <table style="width:100%;font-size:15.5px;border-collapse:collapse;">
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;width:160px;">Movement ID:</td><td id="movDetId" style="font-weight:700;color:#002F70;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;">Date:</td><td id="movDetDate" style="color:#334155;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;">Fuel Type:</td><td id="movDetFuel" style="font-weight:700;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;">Tank / Source:</td><td id="movDetTank" style="color:#334155;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;">Movement Type:</td><td id="movDetType" style="padding:9px 0;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;">Liters:</td><td id="movDetLiters" style="font-weight:800;font-size:15px;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;">Previous Volume:</td><td id="movDetPrevVol" style="color:#475569;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;">New Volume:</td><td id="movDetNewVol" style="color:#475569;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;">Performed By:</td><td id="movDetBy" style="font-weight:600;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;">Reference No.:</td><td id="movDetRef" style="color:#334155;"></td></tr>
                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:9px 0;color:#64748b;font-weight:600;">Status:</td><td id="movDetStatus" style="color:#334155;"></td></tr>
                <tr><td style="padding:9px 0;color:#64748b;font-weight:600;">Notes:</td><td id="movDetNotes" style="color:#64748b;font-style:italic;"></td></tr>
            </table>
        </div>
        <div class="modal-footer">
            <button onclick="closeMovDetailModal()" class="btn-cancel" style="height:32px;font-size:14.5px;padding:0 12px;">Close</button>
        </div>
    </div>
</div>
</div> <!-- /.mif-wrap -->
<?php include __DIR__ . '/../partials/footer.php'; ?>
