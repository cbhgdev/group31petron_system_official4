<?php
// Force browser to always load fresh — prevents stale CSS/JS cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$page_id = 'manager_set_prices';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

// Schema safety: widen fuel_inventory status column to VARCHAR(50) so 'active' and 'inactive' persist across page refreshes
try {
    $pdo->exec("ALTER TABLE fuel_inventory MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
} catch (Exception $e) {}

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// ── Access control ──────────────────────────────────────────────────────────
if (in_array($role, ['admin', 'superadmin'])) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: admin_set_prices.php' . $qs);
    exit;
}
if ($role !== 'manager') {
    header('Location: dashboard.php');
    exit;
}

// ── Top-Level Permanent Deletion of all TEST Fuel Products (UGT-08, UGT-09, UGT-10, etc.) ──
try {
    $stmt_del_fi = $pdo->prepare("SELECT id FROM fuel_inventory WHERE LOWER(TRIM(fuel_type)) LIKE '%test%' OR LOWER(TRIM(ugt_no)) IN ('ugt-08', 'ugt-09', 'ugt-10', 'ugt #8', 'ugt #9', 'ugt #10', 'ugt-8', 'ugt-9', 'ugt-10', 'ugt 08', 'ugt 09', 'ugt 10', 'ugt 8', 'ugt 9', 'ugt 10')");
    $stmt_del_fi->execute();
    $target_del_ids = $stmt_del_fi->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($target_del_ids)) {
        $in_del = implode(',', array_fill(0, count($target_del_ids), '?'));
        try { $pdo->prepare("DELETE FROM nozzles WHERE pump_id IN (SELECT id FROM fuel_pumps WHERE tank_id IN ($in_del)) OR LOWER(TRIM(ugt_no)) IN ('ugt-08', 'ugt-09', 'ugt-10', 'ugt #8', 'ugt #9', 'ugt #10', 'ugt-8', 'ugt-9', 'ugt-10', 'ugt 08', 'ugt 09', 'ugt 10', 'ugt 8', 'ugt 9', 'ugt 10')")->execute($target_del_ids); } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM fuel_pumps WHERE tank_id IN ($in_del) OR LOWER(TRIM(ugt_no)) IN ('ugt-08', 'ugt-09', 'ugt-10', 'ugt #8', 'ugt #9', 'ugt #10', 'ugt-8', 'ugt-9', 'ugt-10', 'ugt 08', 'ugt 09', 'ugt 10', 'ugt 8', 'ugt 9', 'ugt 10')")->execute($target_del_ids); } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM pending_price_approvals WHERE (product_type IN ('fuel','fuel_inventory') AND product_id IN ($in_del)) OR LOWER(TRIM(product_name)) LIKE '%test%'")->execute($target_del_ids); } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM fuel_price_history WHERE fuel_id IN ($in_del)")->execute($target_del_ids); } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM fuel_config_history WHERE fuel_inventory_id IN ($in_del)")->execute($target_del_ids); } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM fuel_status_history WHERE fuel_inventory_id IN ($in_del)")->execute($target_del_ids); } catch (Exception $e) {}
        $pdo->prepare("DELETE FROM fuel_inventory WHERE id IN ($in_del)")->execute($target_del_ids);
    }
} catch (Exception $e) {}

// Helper function to get the canonical 5 fuel types
if (!function_exists('get_canonical_fuel_name')) {
    function get_canonical_fuel_name($name) {
        $name_lower = strtolower(trim($name));
        if (strpos($name_lower, 'turbo') !== false) {
            return 'Turbo Diesel';
        } elseif (strpos($name_lower, 'diesel') !== false) {
            return 'Diesel';
        } elseif (strpos($name_lower, 'kerosene') !== false) {
            return 'Kerosene';
        } elseif (strpos($name_lower, 'xcs') !== false) {
            return 'XCS Plus';
        } elseif (strpos($name_lower, 'xtra') !== false || strpos($name_lower, 'unl') !== false || strpos($name_lower, 'advance') !== false) {
            return 'Xtra UNL';
        }
        return $name;
    }
}

if (!function_exists('fetch_pumps_for_fuel_product')) {
    function fetch_pumps_for_fuel_product($pdo, $station_id, $fuel) {
        $pumps = [];
        $ft_id = (int)($fuel['fuel_type_id'] ?? 0);
        $raw_ugt = trim($fuel['ugt_no'] ?? '');
        $ugt_num = (int)preg_replace('/[^0-9]/', '', $raw_ugt);
        $fname = strtolower(trim($fuel['fuel_type'] ?? ''));

        $ugt_list = array_filter(array_unique([
            $raw_ugt,
            $ugt_num ? sprintf('UGT-%02d', $ugt_num) : '',
            $ugt_num ? sprintf('UGT #%d', $ugt_num) : '',
            $ugt_num ? sprintf('UGT-%d', $ugt_num) : '',
            $ugt_num ? sprintf('UGT %d', $ugt_num) : '',
            $ugt_num ? (string)$ugt_num : '',
        ]));

        $prefix = '';
        if (strpos($fname, 'turbo') !== false) {
            $prefix = 'TURBO DIESEL - %';
        } elseif (strpos($fname, 'diesel 1') !== false || $ugt_num === 1) {
            $prefix = 'DIESEL 1 - %';
        } elseif (strpos($fname, 'diesel 2') !== false || $ugt_num === 2) {
            $prefix = 'DIESEL 2 - %';
        } elseif (strpos($fname, 'xcs') !== false || $ugt_num === 4) {
            $prefix = 'XCS PLUS - %';
        } elseif (strpos($fname, 'xtra unl 1') !== false || (strpos($fname, 'xtra') !== false && strpos($fname, '1') !== false) || $ugt_num === 5) {
            $prefix = 'XTRA UNL 1 - %';
        } elseif (strpos($fname, 'xtra unl 2') !== false || (strpos($fname, 'xtra') !== false && strpos($fname, '2') !== false) || $ugt_num === 6) {
            $prefix = 'XTRA UNL 2 - %';
        } elseif (strpos($fname, 'kero') !== false || $ugt_num === 7) {
            $prefix = 'KEROSENE - %';
        }

        $inv_id = (int)($fuel['id'] ?? 0);
        // 1. Direct tank_id lookup first (guarantees exact 1:1 match with fuel management)
        if ($inv_id > 0) {
            try {
                $p_stmt = $pdo->prepare("SELECT id, pump_number, pump_name, nozzle_number, status 
                                          FROM fuel_pumps 
                                          WHERE station_id = ? AND tank_id = ?
                                          ORDER BY pump_number ASC, id ASC");
                $p_stmt->execute([$station_id, $inv_id]);
                $pumps = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $pumps = []; }
        }

        // 2. If no direct tank_id linkage, fallback to fuel_type_id + ugt_no matching
        if (empty($pumps)) {
            try {
                $sql = "SELECT id, pump_number, pump_name, nozzle_number, status 
                        FROM fuel_pumps 
                        WHERE station_id = ? 
                          AND (
                            (? > 0 AND fuel_type_id = ?)
                            " . (!empty($ugt_list) ? " OR ugt_no IN (" . implode(',', array_fill(0, count($ugt_list), '?')) . ")" : "") . "
                            " . ($prefix !== '' ? " OR UPPER(pump_number) LIKE ?" : "") . "
                          )
                        ORDER BY pump_number ASC, id ASC";
                $params = [$station_id, $ft_id, $ft_id];
                if (!empty($ugt_list)) {
                    $params = array_merge($params, array_values($ugt_list));
                }
                if ($prefix !== '') {
                    $params[] = $prefix;
                }
                $p_stmt = $pdo->prepare($sql);
                $p_stmt->execute($params);
                $pumps = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $pumps = []; }
        }

        if (empty($pumps)) {
            try {
                $n_sql = "SELECT n.id, n.pump_id, 
                                 COALESCE(fp.pump_number, CONCAT(n.pump_name, ' - ', n.nozzle_number)) AS pump_number,
                                 n.pump_name, n.nozzle_number, n.status
                          FROM nozzles n
                          LEFT JOIN fuel_pumps fp ON fp.id = n.pump_id
                          WHERE n.station_id = ?
                            AND (
                              (? > 0 AND fp.tank_id = ?)
                              OR (? > 0 AND n.fuel_type_id = ?)
                              " . (!empty($ugt_list) ? " OR n.ugt_no IN (" . implode(',', array_fill(0, count($ugt_list), '?')) . ")" : "") . "
                              " . ($prefix !== '' ? " OR UPPER(fp.pump_number) LIKE ?" : "") . "
                            )
                          ORDER BY n.id ASC";
                $n_params = [$station_id, $inv_id, $inv_id, $ft_id, $ft_id];
                if (!empty($ugt_list)) {
                    $n_params = array_merge($n_params, array_values($ugt_list));
                }
                if ($prefix !== '') {
                    $n_params[] = $prefix;
                }
                $n_stmt = $pdo->prepare($n_sql);
                $n_stmt->execute($n_params);
                $pumps = $n_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $pumps = []; }
        }

        foreach ($pumps as &$p) {
            $p['id'] = (int)($p['id'] ?? 0);
            $p['pump_number'] = trim($p['pump_number'] ?? '');
            $p['pump_name'] = trim($p['pump_name'] ?? '');
            $p['nozzle_number'] = trim($p['nozzle_number'] ?? '');
            $p['status'] = ucfirst(strtolower($p['status'] ?? 'Active'));
        }
        unset($p);

        // Auto-backfill missing pump/nozzle records if num_pumps in fuel_inventory exceeds matched pumps
        $expected_pumps = isset($fuel['num_pumps']) && $fuel['num_pumps'] !== null ? max(0, (int)$fuel['num_pumps']) : 0;
        if ($expected_pumps <= 0 && $inv_id > 0) {
            try {
                $stmt_np = $pdo->prepare("SELECT num_pumps FROM fuel_inventory WHERE id = ? LIMIT 1");
                $stmt_np->execute([$inv_id]);
                $expected_pumps = (int)$stmt_np->fetchColumn();
            } catch (Exception $e) {}
        }



        if ($inv_id > 0 && $expected_pumps > count($pumps) && (int)$station_id > 0) {
            $clean_fuel_tag = strtoupper(trim($fuel['fuel_type'] ?? 'FUEL'));
            $capacity = (float)($fuel['capacity'] ?? 0);
            for ($pi = count($pumps) + 1; $pi <= $expected_pumps; $pi++) {
                $p_num_idx = (int)ceil($pi / 2);
                $n_num_idx = (int)((($pi - 1) % 2) + 1);
                $code = "{$p_num_idx}-{$n_num_idx}";
                $pump_num = "{$clean_fuel_tag} - {$code}";
                $pump_name = "Pump {$p_num_idx}";
                $nozzle_num = "Nozzle {$n_num_idx}";

                $chk_pump = $pdo->prepare("SELECT id FROM fuel_pumps WHERE station_id = ? AND tank_id = ? AND pump_number = ? LIMIT 1");
                $chk_pump->execute([$station_id, $inv_id, $pump_num]);
                $existing_pump_id = (int)$chk_pump->fetchColumn();

                if (!$existing_pump_id) {
                    try {
                        $ins_pump = $pdo->prepare("
                            INSERT INTO fuel_pumps (station_id, tank_id, pump_number, pump_name, nozzle_number, fuel_type_id, ugt_no, capacity, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())
                        ");
                        $ins_pump->execute([$station_id, $inv_id, $pump_num, $pump_name, $nozzle_num, $ft_id, $raw_ugt, $capacity]);
                        $existing_pump_id = (int)$pdo->lastInsertId();
                    } catch (Exception $e) {}
                }

                if ($existing_pump_id > 0) {
                    try {
                        $chk_noz = $pdo->prepare("SELECT id FROM nozzles WHERE station_id = ? AND pump_id = ? LIMIT 1");
                        $chk_noz->execute([$station_id, $existing_pump_id]);
                        if (!$chk_noz->fetchColumn()) {
                            $pdo->prepare("
                                INSERT INTO nozzles (station_id, pump_id, pump_name, nozzle_number, fuel_type_id, ugt_no, status, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, 'Active', NOW())
                            ")->execute([$station_id, $existing_pump_id, $pump_name, $nozzle_num, $ft_id, $raw_ugt]);
                        }
                    } catch (Exception $e) {}
                }
            }

            try {
                $p_stmt = $pdo->prepare("SELECT id, pump_number, pump_name, nozzle_number, status 
                                          FROM fuel_pumps 
                                          WHERE station_id = ? AND tank_id = ?
                                          ORDER BY pump_number ASC, id ASC");
                $p_stmt->execute([$station_id, $inv_id]);
                $pumps = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pumps as &$p) {
                    $p['id'] = (int)($p['id'] ?? 0);
                    $p['pump_number'] = trim($p['pump_number'] ?? '');
                    $p['pump_name'] = trim($p['pump_name'] ?? '');
                    $p['nozzle_number'] = trim($p['nozzle_number'] ?? '');
                    $p['status'] = ucfirst(strtolower($p['status'] ?? 'Active'));
                }
                unset($p);
            } catch (Exception $e) {}
        }

        if ($inv_id > 0 && !empty($raw_ugt)) {
            try {
                $pdo->prepare("UPDATE fuel_pumps SET ugt_no = ? WHERE station_id = ? AND tank_id = ? AND (ugt_no IS NULL OR TRIM(ugt_no) = '' OR ugt_no != ?)")
                    ->execute([$raw_ugt, $station_id, $inv_id, $raw_ugt]);
                $pdo->prepare("UPDATE nozzles SET ugt_no = ? WHERE station_id = ? AND pump_id IN (SELECT id FROM fuel_pumps WHERE station_id = ? AND tank_id = ?) AND (ugt_no IS NULL OR TRIM(ugt_no) = '' OR ugt_no != ?)")
                    ->execute([$raw_ugt, $station_id, $station_id, $inv_id, $raw_ugt]);
            } catch (Exception $e) {}
        }

        return $pumps;
    }
}

// ── Fetch fuel inventory ─────────────────────────────────────────────────────
$fuel_products = [];
$fuel_stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'last_updated' => null, 'updates_today' => 0];
try {
    $TANK_CONFIG_17 = get_tank_config((int)$station_id);

    $target_sid = $station_id;
    

    $fi_lookup = [];
    $fi_lookup_by_id = [];
    $fi_status_by_id = []; // track active/inactive by ID
    $s = $pdo->prepare("SELECT id, fuel_type, ugt_no, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, reorder_level, critical_level, num_pumps FROM fuel_inventory WHERE station_id = ? ORDER BY CAST(REGEXP_REPLACE(COALESCE(ugt_no,'0'), '[^0-9]', '') AS UNSIGNED) ASC, id ASC");
    $s->execute([$target_sid]);
    $fi_raw = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fi_raw as $row) {
        $fuel_key = strtolower(trim($row['fuel_type']));
        $ugt_val  = strtolower(trim($row['ugt_no'] ?? ''));

        if (!isset($fi_lookup[$fuel_key])) {
            $fi_lookup[$fuel_key] = $row;
        }
        if ($ugt_val) {
            $fi_lookup[$ugt_val] = $row;
            $u_num = preg_replace('/[^0-9]/', '', $ugt_val);
            if ($u_num) {
                $u_int = (int)$u_num;
                $fi_lookup['ugt_' . $u_int] = $row;
                $fi_lookup['ugt #' . $u_int] = $row;
                $fi_lookup['ugt-' . $u_int] = $row;
                $fi_lookup['ugt-' . sprintf('%02d', $u_int)] = $row;
                $fi_lookup['ugt #' . sprintf('%02d', $u_int)] = $row;
                $fi_lookup['ugt ' . $u_int] = $row;
                $fi_lookup['ugt' . $u_int] = $row;
                $fi_lookup[(string)$u_int] = $row;
            }
        }

        if (preg_match('/diesel\s*(\d)/i', $fuel_key, $m)) {
            $k = 'diesel ' . $m[1];
            if (!isset($fi_lookup[$k])) $fi_lookup[$k] = $row;
        }
        if (strpos($fuel_key, 'diesel') !== false && strpos($fuel_key, 'turbo') === false) {
            if (!isset($fi_lookup['diesel'])) $fi_lookup['diesel'] = $row;
        }
        if (preg_match('/(xtra unl|xtra unl)\s*(\d)/i', $fuel_key, $m)) {
            $k = 'xtra unl ' . $m[2];
            if (!isset($fi_lookup[$k])) $fi_lookup[$k] = $row;
            if (!isset($fi_lookup['xtra unl'])) $fi_lookup['xtra unl'] = $row;
        }
        if (strpos($fuel_key, 'xtra') !== false || strpos($fuel_key, 'unl') !== false) {
            if (!isset($fi_lookup['xtra unl'])) $fi_lookup['xtra unl'] = $row;
        }
        if (strpos($fuel_key, 'xcs') !== false) {
            if (!isset($fi_lookup['xcs plus'])) $fi_lookup['xcs plus'] = $row;
        }
        if (strpos($fuel_key, 'kerosene') !== false) {
            if (!isset($fi_lookup['kerosene'])) $fi_lookup['kerosene'] = $row;
        }
        if (strpos($fuel_key, 'turbo') !== false) {
            if (!isset($fi_lookup['turbo diesel'])) $fi_lookup['turbo diesel'] = $row;
        }

        $fi_lookup_by_id[(int)$row['id']] = $row;
        $st_lower = strtolower(trim($row['status'] ?? ''));
        $fi_status_by_id[(int)$row['id']] = in_array($st_lower, ['inactive', 'disabled', 'deactivated'], true) ? 'inactive' : 'active';
    }

    $del_lookup = [];
    $s = $pdo->prepare("SELECT tank_assigned, fuel_type, SUM(delivery_liters) AS total_del FROM fuel_deliveries WHERE station_id = ? AND DATE(delivery_date) = CURDATE() AND status = 'Verified' GROUP BY tank_assigned, fuel_type");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $del_lookup[strtolower(trim($row['tank_assigned']))] = (float)$row['total_del'];
    }

    $sales_lookup = [];
    $s = $pdo->prepare("SELECT fuel_type, SUM(liters_sold) AS total_sales FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'Verified' GROUP BY fuel_type");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sales_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_sales'];
    }

    $adj_lookup = [];
    $s = $pdo->prepare("SELECT fi.fuel_type, COALESCE(SUM(fa.liters),0) AS total_adj FROM fuel_adjustments fa JOIN fuel_inventory fi ON fa.fuel_type_id = fi.fuel_type_id AND fi.station_id = fa.station_id WHERE fa.station_id = ? AND DATE(fa.adjustment_date) = CURDATE() GROUP BY fi.fuel_type");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $adj_lookup[strtolower(trim($row['fuel_type']))] = (float)$row['total_adj'];
    }

    $price_lookup = [];
    $s = $pdo->prepare("SELECT ft.name AS fuel_type, fp.price_per_liter FROM fuel_pricing fp JOIN fuel_types ft ON fp.fuel_type_id = ft.id WHERE fp.station_id = ? AND fp.is_active = 1 ORDER BY fp.effective_date DESC");
    $s->execute([$target_sid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim($row['fuel_type']));
        if (!isset($price_lookup[$key])) $price_lookup[$key] = (float)$row['price_per_liter'];
    }

    $pending_approvals = [];
    try {
        $s_pa = $pdo->prepare("SELECT id AS approval_id, product_id, fuel_type_id, product_name, COALESCE(new_price, new_value) AS new_value, old_price, new_price, status, reason, requested_by, created_at, station_id FROM pending_price_approvals WHERE station_id = ? AND status = 'pending' AND product_type IN ('fuel', 'fuel_inventory')");
        $s_pa->execute([$target_sid]);
        foreach ($s_pa->fetchAll(PDO::FETCH_ASSOC) as $p_row) {
            $pid = (int)($p_row['product_id'] ?? 0);
            $ftid = (int)($p_row['fuel_type_id'] ?? 0);
            $pname = strtolower(trim($p_row['product_name'] ?? ''));

            if ($pid > 0) {
                $pending_approvals['id_' . $pid] = $p_row;
            }
            if ($ftid > 0) {
                $pending_approvals['id_' . $ftid] = $p_row;
            }
            if ($pname) {
                $pending_approvals['name_' . $pname] = $p_row;
                $canonical_pname = strtolower(get_canonical_fuel_name($pname));
                $pending_approvals['canon_' . $canonical_pname] = $p_row;
            }
        }
    } catch (Exception $e) {}

    // Preload pumps for each tank and UGT to compute pump counts
    // Keyed by fuel_inventory record id for exact 1:1 matching (same logic as fuel management)
    $pumps_by_fi_id = [];
    try {
        // Build a lookup: fi_id => [pumps] using fuel_type_id + ugt_no matching
        // This mirrors fetch_pumps_for_fuel_product() to stay in sync with fuel management
        $fi_ft_map = []; // fi_id => [fuel_type_id, ugt_no]
        foreach ($fi_raw as $_r) {
            $fi_ft_map[(int)$_r['id']] = [
                'fuel_type_id' => (int)($_r['fuel_type_id'] ?? 0),
                'ugt_no'       => trim($_r['ugt_no'] ?? ''),
            ];
        }

        $p_stmt = $pdo->prepare("SELECT id, tank_id, ugt_no, fuel_type_id, pump_number
                                  FROM fuel_pumps
                                  WHERE station_id = ?
                                    AND LOWER(COALESCE(status,'active')) = 'active'");
        $p_stmt->execute([$target_sid]);
        $all_pumps = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fi_ft_map as $_fi_id => $_fi_info) {
            $_ft_id  = $_fi_info['fuel_type_id'];
            $_ugt    = strtolower(trim($_fi_info['ugt_no']));
            $_ugt_num = (int)preg_replace('/[^0-9]/', '', $_ugt);

            $seen_pump_ids = [];
            foreach ($all_pumps as $pr) {
                $pr_id = (int)$pr['id'];
                if (isset($seen_pump_ids[$pr_id])) continue;

                $matched = false;
                // Direct match by tank_id (guarantees exact 1:1 match with fuel management)
                if (!empty($pr['tank_id']) && (int)$pr['tank_id'] === $_fi_id) {
                    $matched = true;
                }
                // Match by fuel_type_id
                if (!$matched && $_ft_id && (int)$pr['fuel_type_id'] === $_ft_id) {
                    $matched = true;
                }
                // Match by ugt_no (canonical number comparison)
                if (!$matched && $_ugt_num > 0) {
                    $pr_ugt_num = (int)preg_replace('/[^0-9]/', '', strtolower(trim($pr['ugt_no'] ?? '')));
                    if ($pr_ugt_num && $pr_ugt_num === $_ugt_num) {
                        $matched = true;
                    }
                }
                // Match by pump_number pattern (e.g. "Diesel 1", "UGT-01")
                if (!$matched && $_ugt_num > 0) {
                    $pn = strtoupper(trim($pr['pump_number'] ?? ''));
                    if (preg_match('/UGT[-_ #]*0*' . $_ugt_num . '\b/i', $pn)) {
                        $matched = true;
                    }
                }

                if ($matched) {
                    $pumps_by_fi_id[$_fi_id][] = $pr;
                    $seen_pump_ids[$pr_id] = true;
                }
            }
        }
    } catch (Exception $e) {}

    // Exactly 1:1 representation of fuel_inventory records for this station (No phantom or duplicated records)
    $fuel_products = [];
    foreach ($fi_raw as $row) {
        $r_id = (int)$row['id'];
        $ft_name = trim($row['fuel_type'] ?? '');
        $ft_key = strtolower($ft_name);
        $ugt_val = trim($row['ugt_no'] ?? '');
        $ugt_key = strtolower($ugt_val);
        $cap = (float)($row['capacity'] ?? 14000);
        if ($cap <= 0) $cap = 14000;

        $cur_level = (float)($row['current_level'] ?? $row['current_stock'] ?? 0);
        $tank_key = $ugt_key;
        $purchases = $del_lookup[$tank_key] ?? 0;
        $sales_total = $sales_lookup[$ft_key] ?? 0;
        $adj_total   = $adj_lookup[$ft_key] ?? 0;

        $beginning = $cur_level;
        $total_available = $beginning + $purchases;
        $ending_system   = min(max(0, $total_available - $sales_total - $adj_total), $cap);

        $crit = (float)($row['critical_level'] ?? 0);
        if ($crit <= 0) {
            $crit = ($cap == 14000) ? 2500 : (($cap == 7000) ? 1000 : $cap * 0.10);
        }
        $reord = (float)($row['reorder_level'] ?? 0);
        if ($reord <= 0) {
            $reord = ($cap == 14000) ? 5000 : (($cap == 7000) ? 2000 : $cap * 0.20);
        }

        $status = 'Normal';
        if ($ending_system <= 0) {
            $status = 'Out of Stock';
        } elseif ($ending_system <= $crit) {
            $status = 'Critical';
        } elseif ($ending_system <= $reord) {
            $status = 'Low';
        }

        $price = (float)($row['price_per_liter'] ?? 0);
        if ($price <= 0 && isset($price_lookup[$ft_key])) {
            $price = (float)$price_lookup[$ft_key];
        }

        // Dynamically fetch exact pumps for this fuel product and auto-backfill missing child pumps/nozzles if num_pumps > count(pumps)
        $actual_pumps = fetch_pumps_for_fuel_product($pdo, $target_sid, $row);
        $actual_count = count($actual_pumps);
        $p_count = max((int)($row['num_pumps'] ?? 0), $actual_count);

        if ($p_count > (int)($row['num_pumps'] ?? 0)) {
            try {
                $pdo->prepare("UPDATE fuel_inventory SET num_pumps = ? WHERE id = ? AND station_id = ?")
                    ->execute([$p_count, $r_id, $target_sid]);
            } catch (Exception $e_np) {}
        }

        // Pending approval check
        $app = null;
        $inv_canonical = strtolower(get_canonical_fuel_name($ft_name));
        if (isset($pending_approvals['id_' . $r_id])) {
            $app = $pending_approvals['id_' . $r_id];
        } elseif (isset($pending_approvals['name_' . $ft_key])) {
            $app = $pending_approvals['name_' . $ft_key];
        } elseif (isset($pending_approvals['canon_' . $inv_canonical])) {
            $app = $pending_approvals['canon_' . $inv_canonical];
        } elseif ($ugt_key && isset($pending_approvals['name_' . $ugt_key])) {
            $app = $pending_approvals['name_' . $ugt_key];
        }

        $numOnly = (int)preg_replace('/[^0-9]/', '', $ugt_val) ?: (count($fuel_products) + 1);

        $fuel_products[] = [
            'id'             => $r_id,
            'pump_id'        => $numOnly,
            'ugt_no'         => $ugt_val ?: ('UGT #' . $numOnly),
            'tank_label'     => $ugt_val ?: ('Tank #' . $numOnly),
            'fuel_type'      => $ft_name,
            'raw_fuel_type'  => $ft_name,
            'capacity'       => $cap,
            'current_stock'  => $ending_system,
            'critical_level' => $crit,
            'reorder_level'  => $reord,
            'status'         => $status,
            'inv_status'     => $fi_status_by_id[$r_id] ?? (strtolower($row['status'] ?? 'active')),
            'last_updated'   => $row['last_updated'] ?? null,
            'price_per_liter'=> $price,
            'pending_price'  => $app ? (float)$app['new_value'] : null,
            'approval_status'=> $app ? $app['status'] : null,
            'approval_id'    => $app ? $app['approval_id'] : null,
            'pump_count'     => $p_count,
            'num_pumps'      => $p_count
        ];
    }

    // Sort by UGT number (numeric) so display order matches Fuel Management
    usort($fuel_products, function($a, $b) {
        $an = (int)preg_replace('/[^0-9]/', '', $a['ugt_no'] ?? '');
        $bn = (int)preg_replace('/[^0-9]/', '', $b['ugt_no'] ?? '');
        return $an - $bn;
    });

    // Calculate stats
    $fuel_stats['total'] = count($fuel_products);
    foreach ($fuel_products as $f) {
        if (strtolower($f['status'] ?? 'normal') === 'normal') {
            $fuel_stats['active']++;
        } else {
            $fuel_stats['inactive']++;
        }
        if ($f['last_updated'] && (!$fuel_stats['last_updated'] || $f['last_updated'] > $fuel_stats['last_updated'])) {
            $fuel_stats['last_updated'] = $f['last_updated'];
        }
        if ($f['last_updated'] && date('Y-m-d', strtotime($f['last_updated'])) === date('Y-m-d')) {
            $fuel_stats['updates_today']++;
        }
    }
} catch (Exception $e) {
    $fuel_products = [];
    error_log('[manager_set_prices] fuel error: ' . $e->getMessage());
}

// ── Fetch merchandise grouped by category ───────────────────────────────────────
$merch_by_cat   = [];
$merch_all      = [];
$merch_stats    = ['total' => 0, 'valid_price' => 0, 'below_cost' => 0, 'unpriced' => 0];
$all_categories = [];
$all_brands     = [];
$all_units      = [];
$all_suppliers  = [];

try {
    $rows = load_merchandise_pricing_catalog($pdo, (int)$station_id);

    foreach ($rows as $row) {
        $cat    = $row['category_name'] ?? $row['category'] ?? 'Uncategorized';
        $cost   = (float)($row['unit_cost']  ?? 0);
        $price  = (float)($row['unit_price'] ?? 0);
        $stock  = (float)($row['stock_quantity'] ?? $row['stock'] ?? 0);

        $merch_stats['total']++;
        if ($price <= 0) {
            $merch_stats['unpriced']++;
        } elseif ($price < $cost) {
            $merch_stats['below_cost']++;
        } else {
            $merch_stats['valid_price']++;
        }

        $row['_cost']  = $cost;
        $row['_price'] = $price;
        $row['_stock'] = $stock;

        $merch_by_cat[$cat][] = $row;
        $merch_all[]          = $row;
        $all_categories[$cat] = true;
        $all_brands[$row['brand'] ?? 'Generic'] = true;
        $all_units[$row['unit'] ?? 'Piece (pc)'] = true;
        $all_suppliers[$row['supplier'] ?? 'Petron Corporation'] = true;
    }
} catch (Exception $e) {
    $merch_by_cat = [];
    error_log('[manager_set_prices] merch error: ' . $e->getMessage());
}

// ── Pre-load merchandise batches per product (cached/ajax-ready) ───────────
$merch_batches_by_product = [];

$all_categories = array_keys($all_categories);
sort($all_categories);
$all_brands = array_keys($all_brands);
sort($all_brands);
$all_units = array_keys($all_units);
sort($all_units);
$all_suppliers = array_keys($all_suppliers);
sort($all_suppliers);

// ── Fetch service types & pending approvals ────────────────────────────────
$service_types = [];
$service_error = null;
try {

    $stmt = $pdo->prepare("
        SELECT s.id, s.service_code, s.service_name, s.service_key, s.category,
               s.service_price, s.labor_fee, s.estimated_duration, s.required_mechanics,
               s.description, s.status, s.active, s.updated_at,
               p.new_price  AS pending_service_fee,
               p.new_cost   AS pending_labor_fee,
               p.status     AS approval_status,
               p.id         AS approval_id
        FROM job_order_service_types s
        LEFT JOIN pending_price_approvals p
               ON s.id = p.product_id
              AND p.station_id = s.station_id
              AND p.product_type = 'service'
              AND p.status = 'pending'
        WHERE s.station_id = ?
        ORDER BY s.category ASC, s.service_name ASC
    ");
    $stmt->execute([(int)$station_id]);
    $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $service_types = [];
    $service_error = null;
    error_log("[manager_set_prices] service types error: " . $e->getMessage());
}

// ── Log page view ────────────────────────────────────────────────────────────
try {
    log_activity($pdo, $me['id'], 'View Product Pricing',
        "Manager viewed pricing for station {$station_id}");
} catch (Exception $e) { /* silent */ }

// ── Active tab (persists across refresh via ?tab= query param) ───────────────
$active_tab = $_GET['tab'] ?? 'fuel';
if (!in_array($active_tab, ['fuel', 'merch', 'services'])) $active_tab = 'fuel';

// ── AJAX JSON POLLING ENDPOINT FOR PRODUCT & PRICING MANAGEMENT ─────────────────
if (isset($_GET['ajax_psp']) && $_GET['ajax_psp'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'counts' => [
            'fuel_count'    => count($fuel_products),
            'merch_count'   => count($merch_all),
            'service_count' => count($service_types),
            'total_count'   => count($fuel_products) + count($merch_all) + count($service_types)
        ]
    ]);
    exit;
}




include __DIR__ . '/../partials/header.php';
?>

<style>
/* ABSOLUTE NO TEXT OVERLAPPING RULE */
.cust-section, .table-wrap, .table-responsive, .table-card, .card {
    overflow-x: hidden !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}

table.cust-table, #merchTable, #mgrMerchTable, table.pricing-table, table.tbl-requests, table.table {
    table-layout: fixed !important;
    width: 100% !important;
    min-width: 100% !important;
    max-width: 100% !important;
    border-collapse: collapse !important;
    box-sizing: border-box !important;
}

table:not(.data-tbl) th {
    padding: 9px 8px !important;
    font-size: 12.5px !important;
    font-weight: 800 !important;
    letter-spacing: 0.2px !important;
    text-transform: uppercase !important;
    white-space: nowrap !important;
}

table:not(.data-tbl) td {
    padding: 9px 8px !important;
    font-size: 13.5px !important;
    line-height: 1.3 !important;
    word-break: normal !important;
    overflow-wrap: break-word !important;
}

/* Customer ID Monospace Code */
.cust-table td:first-child, .cust-table td code {
    font-size: 12.5px !important;
    font-weight: 800 !important;
    font-family: monospace !important;
    white-space: nowrap !important;
}

/* Customer Name High Legibility */
.cust-table td:nth-child(2) strong {
    font-size: 14px !important;
    font-weight: 800 !important;
    color: #002F6C !important;
    white-space: nowrap !important;
}

/* Customer table specific: Vehicles, Amounts, & Dates */
.cust-table td:nth-child(3), .cust-table td:nth-child(4), .cust-table td:nth-child(5), .cust-table td:nth-child(6), .cust-table td:nth-child(7), .cust-table td:nth-child(8), .cust-table td:nth-child(9), .cust-table td:nth-child(10),
.cust-table th:nth-child(3), .cust-table th:nth-child(4), .cust-table th:nth-child(5), .cust-table th:nth-child(6), .cust-table th:nth-child(7), .cust-table th:nth-child(8), .cust-table th:nth-child(9), .cust-table th:nth-child(10) {
    white-space: nowrap !important;
}

/* Status Pill */
.pill, .pill.active, .pill.inactive, .pill.archived, .pill.regular, .pill.credit, .status-pill, .badge {
    white-space: nowrap !important;
    display: inline-block !important;
    padding: 3px 8px !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    border-radius: 5px !important;
    line-height: 1.1 !important;
}

/* Action Buttons */
.cust-actions button, .cust-table .btn-plain, .act-btn, .tbl-btn {
    font-size: 11px !important;
    font-weight: 700 !important;
    height: 24px !important;
    padding: 0 6px !important;
    white-space: nowrap !important;
    border-radius: 5px !important;
    overflow: visible !important;
}
/* Reports-Style Export Bar & Buttons (Matches System Reports & Mechanics Exactly) */
.rpt-export-group {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    margin-left: auto !important;
    white-space: nowrap !important;
    flex-wrap: wrap !important;
}
.rpt-export-btn {
    padding: 7px 13px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    border-radius: 4px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    background: #ffffff !important;
    border: 1px solid !important;
    transition: all 0.18s !important;
    text-decoration: none !important;
}
.rpt-btn-print  { color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; color: #00264D !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; color: #991b1b !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; color: #166534 !important; }
.rpt-btn-csv   { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdf4 !important; color: #166534 !important; }
</style><style>
/* ══════════════════════════════════════════════════════════════════
   STRICT ANTI-OVERLAP RULES — GUARANTEE ZERO COLUMN OVERLAPPING
   ══════════════════════════════════════════════════════════════════ */
#merchTable,
#servicePricingTable,
.pricing-table {
    table-layout: fixed !important;
    width: 100% !important;
    border-collapse: collapse !important;
}

#merchTable th,
#merchTable td,
#servicePricingTable th,
#servicePricingTable td,
.pricing-table th,
.pricing-table td {
    box-sizing: border-box !important;
    vertical-align: middle !important;
}

/* Col 2 (Product Name in Merch): Clean multi-line wrap with ZERO overlap */
#merchTable td:nth-child(2),
.pricing-table td:nth-child(2) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    line-height: 1.35 !important;
    max-width: 0 !important;
}

#merchTable td:nth-child(2) strong,
.pricing-table td:nth-child(2) strong {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    display: block !important;
    line-height: 1.35 !important;
}

/* Col 3 (Category) & Col 4 (Brand): Clean text wrap */
#merchTable td:nth-child(3),
#merchTable td:nth-child(4) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    line-height: 1.3 !important;
    max-width: 0 !important;
}

/* Columns that should remain compact on a single line */
#merchTable td:nth-child(1),
#merchTable td:nth-child(5),
#merchTable td:nth-child(6),
#merchTable td:nth-child(7),
#merchTable td:nth-child(8) {
    white-space: nowrap !important;
    text-overflow: ellipsis !important;
}

/* Col 9 (Actions): Aligned vertical button stack */
#merchTable td:nth-child(9) {
    white-space: nowrap !important;
    overflow: visible !important;
    text-align: center !important;
    vertical-align: middle !important;
}

#merchTable .act-btn-wrap,
.pricing-table .act-btn-wrap {
    display: flex !important;
    flex-direction: column !important;
    gap: 3px !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

#merchTable .act-btn,
.pricing-table .act-btn {
    width: 96px !important;
    min-width: 96px !important;
    max-width: 96px !important;
    height: 25px !important;
    min-height: 25px !important;
    padding: 3px 6px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
    margin-bottom: 0 !important;
    box-sizing: border-box !important;
    text-align: center !important;
}
</style>















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





<style>
body, html { overflow-x: hidden; max-width: 100%; }
/* ── Page-level styles ─────────────────────────────────────────────────────── */
/* Navigation Tabs - Matches Reports sub-tab design */
.ato-tab-bar { display: flex !important; flex-wrap: wrap !important; margin-bottom: 22px !important; border: 1px solid #d1d9e6 !important; border-radius: 0 !important; overflow: hidden !important; border-bottom: 3px solid #00264D !important; gap: 0 !important; background: transparent !important; padding: 0 !important; width: 100% !important; }
.ato-tab { flex: 1 !important; min-width: 140px !important; padding: 12px 16px !important; font-size: 11.5px !important; font-weight: 700 !important; color: #334155 !important; background: #ffffff !important; border: none !important; border-right: 1px solid #d1d9e6 !important; border-radius: 0 !important; text-decoration: none !important; transition: all 0.15s ease !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 7px !important; text-transform: uppercase !important; letter-spacing: 0.3px !important; text-align: center !important; cursor: pointer !important; pointer-events: all !important; user-select: none !important; position: relative !important; z-index: 5 !important; margin-bottom: 0 !important; box-shadow: none !important; }
.ato-tab * { pointer-events: none !important; }
.ato-tab:last-child { border-right: none !important; }
.ato-tab:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.ato-tab.active { background: #00264D !important; color: #ffffff !important; font-weight: 800 !important; box-shadow: none !important; }
.pricing-tabs { display: none; } /* replaced by dropdown */
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* Summary cards */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: #fff; 
    border: 1px solid #e2e8f0; 
    border-radius: 10px;
    padding: 16px; 
    text-align: center;
    transition: transform .2s, box-shadow .2s;
}
.summary-card:hover { 
    transform: translateY(-2px); 
    box-shadow: 0 2px 8px rgba(0,0,0,.08); 
}
.summary-card .s-num  { font-size: 1.8rem; font-weight: 700; line-height: 1; color: #002F70; }
.summary-card .s-lbl  { font-size: .75rem; color: #666; text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }
.summary-card.s-total  .s-num { color: #002F6C; }
.summary-card.s-valid  .s-num { color: #16a34a; }
.summary-card.s-below  .s-num { color: #dc2626; }
.summary-card.s-unpriced .s-num { color: #d97706; }

/* Toolbar */
.toolbar {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
    margin-bottom: 16px;
}
.toolbar input[type="text"],
.toolbar select {
    padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 15.5px; color: #334155; background: #fff;
}
.toolbar input[type="text"]:focus,
.toolbar select:focus { outline: none; border-color: #002F6C; box-shadow: 0 0 0 2px rgba(0,47,108,.12); }

/* Table tweaks - Fix horizontal overflow */
.table-wrap {
    overflow-x: hidden !important;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}
.pricing-table, #merchTable, #mgrMerchTable, table.tbl-requests {
    width: 100% !important;
    max-width: 100% !important;
    border-collapse: collapse !important;
    box-sizing: border-box !important;
    table-layout: fixed !important;
}
.pricing-table th {
    background: #002F70 !important; 
    color: #fff !important; 
    padding: 10px 8px !important;
    font-size: 11.5px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: .3px !important;
    white-space: nowrap !important;
    vertical-align: middle !important;
}
.pricing-table td {
    padding: 9px 8px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    vertical-align: middle !important;
    font-size: 13px !important;
    line-height: 1.3 !important;
}
.pricing-table tbody tr:hover { background: #e3f2fd; }

/* Category header row */
.cat-row td {
    background: #f1f5f9 !important; font-weight: 700; font-size: 14px;
    text-transform: uppercase; letter-spacing: .5px; color: #475569;
    padding: 7px 12px; border-bottom: 1px solid #e2e8f0;
}

/* Row highlight for price-below-cost */
.row-below-cost { background: #fff5f5 !important; }
.row-below-cost:hover { background: #fee2e2 !important; }

/* Badges */
.badge-normal    { background: #dcfce7; color: #166534; }
.badge-low       { background: #fef9c3; color: #854d0e; }
.badge-critical  { background: #fee2e2; color: #991b1b; }
.badge-out       { background: #fee2e2; color: #991b1b; }
.badge-available { background: #dcfce7; color: #166534; }
.badge-noprice   { background: #fef3c7; color: #92400e; }
.badge-warn      { background: #fee2e2; color: #991b1b; }
.badge-ok        { background: #dcfce7; color: #166534; }

.badge {
    display: inline-block; padding: 3px 9px; border-radius: 999px;
    font-size: 14px; font-weight: 600; white-space: nowrap;
}

/* == MODAL STYLES (Transaction Module Design - Centered within Content Layout Area) == */
.modal { position:fixed; top:70px; left:250px; right:0; bottom:40px; display:none; align-items:center; justify-content:center; z-index:9999; background:rgba(0,0,0,0.55); backdrop-filter:blur(4px); padding:20px; box-sizing:border-box; transition: left 0.3s ease; }
.modal-card { position:relative; background:#fff; border-radius:16px; max-width:600px; width:90%; max-height:calc(100% - 20px); overflow:hidden; box-shadow:0 24px 64px rgba(0,0,0,.3); animation:modalSlideIn .18s ease; }
@keyframes modalSlideIn { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
.modal-head { display:flex; justify-content:space-between; align-items:center; padding:15px 20px; background:#fff; border-bottom:2px solid #e2e8f0; color:#1e293b; }
.modal-head .modal-icon { width:34px; height:34px; background:#f1f5f9; border-radius:8px; display:flex; align-items:center; justify-content:center; margin-right:10px; }
.modal-head .modal-icon i { color:#64748b; font-size:15px; }
.modal-title { font-weight:700; font-size:14px; color:#1e293b; }
.modal-subtitle { font-size:14px; color:#64748b; margin-top:1px; }
.modal-close { background:#f1f5f9; border:none; color:#64748b; font-size:17px; cursor:pointer; width:28px; height:28px; border-radius:6px; display:flex; align-items:center; justify-content:center; transition:background .15s; }
.modal-close:hover { background:#e2e8f0; color:#475569; }
.modal-body { padding:20px; overflow-y:auto; max-height:calc(90vh - 140px); }
.modal-body label { font-size:15.5px; font-weight:600; color:#334155 !important; display:block; margin-bottom:6px; letter-spacing:.3px; }
.modal-body .input { width:100%; padding:9px 12px; border:1.5px solid #d1d5db; border-radius:8px; font-size:15.5px; box-sizing:border-box; color:#1e293b; background:#fff; outline:none; transition:border-color .15s; }
.modal-body .input:focus { border-color:#003d7a; }
.modal-body textarea.input { resize:vertical; font-family:inherit; }
.modal-body select.input { cursor:pointer; }
.modal-actions { display:flex; justify-content:flex-end; gap:8px; padding:15px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; }
.modal-info-box { background:#f0f7ff; border:1px solid #dbeafe; border-radius:8px; padding:12px; margin-bottom:20px; }
.modal-info-box h4 { margin:0 0 10px 0; font-size:15.5px; color:#003d7a; font-weight:700; }
.modal-info-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; font-size:14.5px; }
.modal-info-grid div { display:flex; flex-direction:column; }
.modal-info-grid strong { color:#374151; font-size:14px; margin-bottom:2px; }

/* Button styles */
.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 16px;
    font-size: 15.5px;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
    border: 1.5px solid transparent;
}
.flt-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}
.flt-btn-solid-primary {
    background: #002F70 !important;
    color: #fff !important;
    border-color: #002F70 !important;
}
.flt-btn-solid-primary:hover {
    background: #001a3d !important;
    border-color: #001a3d !important;
}
.flt-btn-reset {
    background: #f1f5f9 !important;
    color: #64748b !important;
    border-color: #e2e8f0 !important;
}
.flt-btn-reset:hover {
    background: #e2e8f0 !important;
    color: #334155 !important;
}
.flt-btn-danger {
    background: #dc2626 !important;
    color: #fff !important;
    border-color: #dc2626 !important;
}
.flt-btn-danger:hover {
    background: #b91c1c !important;
    border-color: #b91c1c !important;
}

/* == Action buttons — ultra crisp & visible outline style == */
.act-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 4px !important;
    padding: 3px 6px !important;
    border-radius: 5px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    width: 96px !important;
    min-width: 96px !important;
    max-width: 96px !important;
    min-height: 25px !important;
    height: 25px !important;
    margin-bottom: 0 !important;
    transition: all .18s ease-in-out !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
    opacity: 1 !important;
    overflow: visible !important;
    text-align: center !important;
}
.act-btn:last-child { margin-bottom: 0 !important; }

.act-btn-view { color: #475569 !important; -webkit-text-fill-color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.act-btn-view:hover { background: #475569 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #475569 !important; }

.act-btn-edit { color: #002F6C !important; -webkit-text-fill-color: #002F6C !important; border-color: #002F6C !important; background: #ffffff !important; }
.act-btn-edit:hover { background: #002F6C !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #002F6C !important; }

.act-btn-deactivate { color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; border-color: #f87171 !important; background: #fff5f5 !important; }
.act-btn-deactivate:hover { background: #dc2626 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #dc2626 !important; }

.act-btn-activate { color: #16a34a !important; -webkit-text-fill-color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.act-btn-activate:hover { background: #16a34a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #16a34a !important; }

.act-btn-batches { color: #0284c7 !important; -webkit-text-fill-color: #0284c7 !important; border-color: #0284c7 !important; background: #ffffff !important; }
.act-btn-batches:hover { background: #0284c7 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #0284c7 !important; }

.act-btn-history { color: #6366f1 !important; -webkit-text-fill-color: #6366f1 !important; border-color: #c7d2fe !important; background: #ffffff !important; }
.act-btn-history:hover { background: #6366f1 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #6366f1 !important; }

.act-btn i { color: inherit !important; -webkit-text-fill-color: inherit !important; font-size: 11px !important; flex-shrink: 0 !important; }
.act-btn-wrap { display: flex !important; flex-direction: column !important; gap: 3px !important; width: 100% !important; align-items: center !important; justify-content: center !important; box-sizing: border-box !important; }

.ppm-wrap { width: 100%; max-width: 100%; box-sizing: border-box; overflow-x: hidden !important; padding: 0 !important; margin: 0 !important; }
.page-head { display:flex; justify-content:space-between; gap:16px; align-items:center; margin-top:0 !important; margin-bottom:25px !important; padding:0 !important; border:none !important; width:100%; }
.page-head h1, .page-head .h1 { margin:0; color:#002f70 !important; font-size:24px !important; font-weight:700 !important; text-transform:uppercase !important; letter-spacing:0.5px !important; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif !important; display:flex !important; align-items:center !important; gap:10px !important; line-height:1.2 !important; }

@media (max-width: 768px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .toolbar { flex-direction: column; align-items: stretch; }
    .toolbar input[type="text"] { min-width: unset; width: 100%; }
}

/* ── Print Isolation & Anti-Overlap Styles for Pricing Reports ── */
@media print {
    body.report-printing > *:not(#report-print-root) {
        display: none !important;
    }
    body.report-printing,
    body.report-printing #report-print-root {
        display: block !important;
        position: static !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #ffffff !important;
    }
    #report-print-root .rpt-paper-sheet {
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        max-width: none !important;
        border-radius: 0 !important;
    }
    #report-print-root table.data-tbl,
    table.data-tbl {
        width: 100% !important;
        border-collapse: collapse !important;
        table-layout: fixed !important;
        margin-top: 4px !important;
    }
    #report-print-root table.data-tbl th,
    table.data-tbl th {
        white-space: normal !important;
        word-break: normal !important;
        overflow-wrap: break-word !important;
        font-size: 7.5pt !important;
        padding: 5px 3px !important;
        line-height: 1.25 !important;
        font-weight: bold !important;
        text-transform: uppercase !important;
        background: #00264D !important;
        color: #ffffff !important;
        border: 1px solid #00264D !important;
        vertical-align: middle !important;
        box-sizing: border-box !important;
    }
    #report-print-root table.data-tbl td,
    table.data-tbl td {
        white-space: normal !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        font-size: 7.5pt !important;
        padding: 4px 4px !important;
        line-height: 1.25 !important;
        border: 1px solid #cbd5e1 !important;
        vertical-align: middle !important;
        box-sizing: border-box !important;
    }
}

/* ── Completely Hide/Remove any Filter Reset Buttons ── */
button[onclick*="resetMerchFilters"],
button[onclick*="resetServiceFilters"],
button[onclick*="resetFilters"],
button[title*="Reset filters"],
button[title*="Reset"],
.btn-filter-reset {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
    width: 0 !important;
    height: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    border: none !important;
}
</style>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="ppm-wrap">
<div class="page-head" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="h1"><i class="fas fa-tags"></i> Product &amp; Pricing Management</h1>
    </div>
    <!-- EXPORT TOOLBAR (Upper Right, Aligned with Controls) -->
    <div class="rpt-export-group">
        <button type="button" class="rpt-export-btn rpt-btn-print" onclick="exportPricing('print')">
            <i class="fas fa-print"></i> Print
        </button>
        <button type="button" class="rpt-export-btn rpt-btn-pdf" onclick="exportPricing('pdf')">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <button type="button" class="rpt-export-btn rpt-btn-excel" onclick="exportPricing('excel')">
            <i class="fas fa-file-excel"></i> Excel
        </button>
        <button type="button" class="rpt-export-btn rpt-btn-csv" onclick="exportPricing('csv')">
            <i class="fas fa-file-csv"></i> CSV
        </button>
    </div>
</div>



<div style="display:inline-flex;align-items:center;gap:8px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;margin-bottom:14px;">
    <i class="fas fa-file-invoice-dollar" style="color:#002F6C;font-size:14px;"></i>
    <span><strong>Pricing Mode: VAT-Inclusive</strong> &mdash; All product selling prices and service rates displayed and edited already include 12% VAT.</span>
</div>

<!-- ── Section Tabs ──────────────────────────────────────────────────── -->
<input type="hidden" id="activeSection" value="<?php echo htmlspecialchars($active_tab); ?>">
<div class="ato-tab-bar">
    <a href="javascript:void(0)" onclick="switchTab('fuel'); return false;" id="tab-btn-fuel" class="ato-tab <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>"><i class="fas fa-gas-pump"></i> Fuel Products</a>
    <a href="javascript:void(0)" onclick="switchTab('merch'); return false;" id="tab-btn-merch" class="ato-tab <?php echo $active_tab === 'merch' ? 'active' : ''; ?>"><i class="fas fa-box"></i> Merchandise</a>
    <a href="javascript:void(0)" onclick="switchTab('services'); return false;" id="tab-btn-services" class="ato-tab <?php echo $active_tab === 'services' ? 'active' : ''; ?>"><i class="fas fa-wrench"></i> Service Types</a>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 1 — FUEL PRODUCTS
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-fuel" class="tab-panel <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>">
    <div style="display:flex;align-items:center;justify-content:flex-start;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
        <input type="text" id="fuelSearchInput" oninput="filterFuelTable()" placeholder="&#x1F50D; Search UGT or Fuel Type..." style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;color:#334155;background:#fff;min-width:220px;">
        <select id="fuelTypeFilter" onchange="filterFuelTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;color:#334155;background:#fff;">
            <option value="">All Fuel Types</option>
            <option value="Diesel">Diesel</option>
            <option value="Kerosene">Kerosene</option>
            <option value="Turbo Diesel">Turbo Diesel</option>
            <option value="XCS Plus">XCS Plus</option>
            <option value="Xtra UNL">Xtra UNL</option>
        </select>
        <select id="fuelStatusFilter" onchange="filterFuelTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;color:#334155;background:#fff;">
            <option value="">All Statuses</option>
            <option value="Normal">Normal</option>
            <option value="Low Stock">Low Stock</option>
            <option value="Out of Stock">Out of Stock</option>
            <option value="Deactivated">Deactivated</option>
        </select>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
        <button onclick="openAddProductModal()" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:15.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:all 0.2s;">
            <i class="fas fa-plus-circle"></i> Add Product
        </button>
    </div>

    <div class="card" style="padding:0;overflow:hidden;">
        <div class="table-wrap" style="overflow-x: hidden; width:100%;">
            <table class="pricing-table" id="fuelPricingTable" style="table-layout:fixed; width:100%;">
                <colgroup>
                    <col style="width: 9%;">  <!-- UGT No. -->
                    <col style="width: 18%;"> <!-- Fuel Type -->
                    <col style="width: 11%;"> <!-- Price / Liter -->
                    <col style="width: 12%;"> <!-- Current Volume -->
                    <col style="width: 10%;"> <!-- Capacity -->
                    <col style="width: 10%;"> <!-- Reorder Level -->
                    <col style="width: 9%;">  <!-- Status -->
                    <col style="width: 8%;">  <!-- Last Updated -->
                    <col style="width: 13%;"> <!-- Actions -->
                </colgroup>
                <thead>
                    <tr>
                        <th style="text-align:left;padding-left:12px;">UGT No.</th>
                        <th style="text-align:left;">Fuel Type</th>
                        <th style="text-align:right;">Price / Liter (&#8369;)</th>
                        <th style="text-align:right;">Current Volume (L)</th>
                        <th style="text-align:right;">Capacity (L)</th>
                        <th style="text-align:right;">Reorder Level (L)</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:center;">Last Updated</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($fuel_products)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:28px;color:#94a3b8;">
                            <i class="fas fa-info-circle"></i> No fuel inventory records found for this station.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    foreach ($fuel_products as $f):
                        $level    = (float)($f['current_stock'] ?? 0);
                        $critical = (float)($f['critical_level'] ?? 0);
                        $reorder  = (float)($f['reorder_level'] ?? 0);
                        $capacity = (float)($f['capacity'] ?? 0);
                        
                        $raw_status = strtolower(trim($f['status'] ?? 'normal'));
                        $fuel_active_status = strtolower($f['inv_status'] ?? ($f['status'] ?? 'active'));
                        $is_deactivated = in_array($fuel_active_status, ['inactive', 'disabled', 'deactivated'], true);

                        if ($is_deactivated) {
                            $status_label = 'Deactivated';
                            $status_class = 'badge-inactive';
                            $bar_color    = '#94a3b8';
                            $badge_style  = 'background:#fee2e2 !important;color:#b91c1c !important;border:1.5px solid #f87171 !important;';
                        } elseif ($level <= 0) {
                            $status_label = 'Out of Stock';
                            $status_class = 'badge-out';
                            $bar_color    = '#dc2626';
                            $badge_style  = 'background:#fee2e2 !important;color:#b91c1c !important;border:1px solid #fca5a5 !important;';
                        } elseif ($reorder > 0 && $level <= $reorder) {
                            $status_label = 'Low Stock';
                            $status_class = 'badge-low';
                            $bar_color    = '#dc2626';
                            $badge_style  = 'background:#fee2e2 !important;color:#b91c1c !important;border:1px solid #fca5a5 !important;';
                        } else {
                            $status_label = 'Normal';
                            $status_class = 'badge-normal';
                            $bar_color    = '#16a34a';
                            $badge_style  = 'background:#dcfce7 !important;color:#15803d !important;border:1px solid #86efac !important;';
                        }
                        
                        $pct = $capacity > 0 ? min(100, round($level / $capacity * 100)) : 0;
                        $ugt_str = $f['ugt_no'] ?? ('UGT #' . $f['pump_id']);
                        $raw_name = !empty($f['fuel_type']) ? $f['fuel_type'] : ($f['raw_fuel_type'] ?? 'Fuel');
                        $clean_name = trim(preg_replace('/\s*\(UGT\s*#?\d+\)/i', '', $raw_name));
                        $full_fuel_name = $clean_name !== '' ? $clean_name : $raw_name;
                        $canonical_type = get_canonical_fuel_name($full_fuel_name);
                    ?>
                    <tr class="fuel-row" data-ugt="<?php echo htmlspecialchars(strtolower($ugt_str)); ?>" data-name="<?php echo htmlspecialchars(strtolower($full_fuel_name)); ?>" data-fueltype="<?php echo htmlspecialchars(strtolower($canonical_type)); ?>" data-status="<?php echo htmlspecialchars($status_label); ?>" data-active="<?php echo $is_deactivated ? 'inactive' : 'active'; ?>" style="<?php echo $is_deactivated ? 'background:#fff5f5;' : ''; ?>">
                        <td style="padding-left:12px;white-space:nowrap;">
                            <strong style="font-family:monospace;color:#002F6C;font-size:13px;letter-spacing:0.2px;"><?php echo htmlspecialchars($ugt_str); ?></strong>
                        </td>
                        <td style="word-break:break-word;line-height:1.25;vertical-align:middle;">
                            <div style="display:flex;flex-direction:column;align-items:flex-start;gap:4px;">
                                <strong style="<?php echo $is_deactivated ? 'color:#64748b;' : 'color:#0f172a;'; ?>font-size:13px;line-height:1.3;word-break:break-word;"><?php echo htmlspecialchars($full_fuel_name); ?></strong>
                                <?php if (!empty($f['pump_count']) && (int)$f['pump_count'] > 0): ?>
                                    <span class="badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:4px;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
                                        <i class="fas fa-gas-pump" style="font-size:10px;"></i> <?php echo (int)$f['pump_count']; ?> <?php echo ((int)$f['pump_count'] === 1) ? 'Pump' : 'Pumps'; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($is_deactivated): ?>
                                <div style="font-size:10px;color:#dc2626;font-weight:700;margin-top:2px;">
                                    <i class="fas fa-ban"></i> Deactivated (Disabled)
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <strong style="color:#002F6C;font-size:13px;">&#8369;<?php echo number_format((float)($f['price_per_liter'] ?? 0), 2); ?></strong>
                            <?php if (($f['approval_status'] ?? '') === 'pending'): ?>
                                <div style="font-size:11px; color:#d97706; background:#fef3c7; padding:2px 5px; border-radius:4px; margin-top:2px; display:inline-block; font-weight:600;">
                                    Pending: ₱<?php echo number_format($f['pending_price'], 2); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <span style="font-size:12.5px;font-weight:600;"><?php echo number_format($level, 2); ?></span>
                            <div style="margin-top:3px;height:4px;background:#e2e8f0;border-radius:2px;width:100%;">
                                <div style="height:4px;background:<?php echo $bar_color; ?>;border-radius:2px;width:<?php echo $pct; ?>%;"></div>
                            </div>
                        </td>
                        <td style="text-align:right;white-space:nowrap;font-size:12.5px;"><?php echo number_format($capacity, 2); ?></td>
                        
                        <td style="text-align:right;white-space:nowrap;"><strong style="color:#475569;font-size:12.5px;"><?php echo number_format((float)$reorder, 2); ?></strong></td>
                        <td style="text-align:center;white-space:nowrap;">
                            <span class="badge <?php echo $status_class; ?>" style="<?php echo $badge_style; ?>display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;">
                                <?php if ($is_deactivated): ?>
                                    <i class="fas fa-ban" style="font-size:9.5px;"></i> DEACTIVATED
                                <?php else: ?>
                                    <?php echo htmlspecialchars($status_label); ?>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="muted" style="font-size:11.5px;text-align:center;white-space:nowrap;">
                            <?php echo $f['last_updated'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($f['last_updated']))) : '&mdash;'; ?>
                        </td>
                        <td style="text-align:center;white-space:nowrap;">
                            <div class="act-btn-wrap">
                                <?php if (!empty($f['id'])): ?>
                                    <button onclick="viewFuelDetails(<?php echo $f['id']; ?>)" class="act-btn act-btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button onclick="openEditPriceModal(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($full_fuel_name)); ?>', <?php echo (float)($f['price_per_liter'] ?? 0); ?>, <?php echo (float)($f['capacity'] ?? 0); ?>, <?php echo (float)($f['critical_level'] ?? 0); ?>, <?php echo (float)($f['reorder_level'] ?? 0); ?>, '<?php echo htmlspecialchars(addslashes($ugt_str)); ?>')" class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if (!$is_deactivated): ?>
                                        <button onclick="toggleFuelStatus(<?php echo $f['id']; ?>, 'inactive', '<?php echo htmlspecialchars(addslashes($full_fuel_name . ' (' . $ugt_str . ')')); ?>')" class="act-btn act-btn-deactivate" style="color:#dc2626 !important;border-color:#fca5a5 !important;background:#fef2f2 !important;">
                                            <i class="fas fa-ban"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button onclick="toggleFuelStatus(<?php echo $f['id']; ?>, 'active', '<?php echo htmlspecialchars(addslashes($full_fuel_name . ' (' . $ugt_str . ')')); ?>')" class="act-btn act-btn-activate" style="color:#16a34a !important;border-color:#86efac !important;background:#f0fdf4 !important;">
                                            <i class="fas fa-check-circle"></i> Activate
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="font-size:12px;color:#94a3b8;font-style:italic;">No Actions</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="fuelNoResults" style="display:none;padding:30px;text-align:center;color:#94a3b8;">
            <i class="fas fa-search" style="font-size:28px;margin-bottom:8px;display:block;"></i>
            No fuel products match your search/filter criteria.
        </div>
    </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 2 — MERCHANDISE PRODUCTS
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-merch" class="tab-panel <?php echo $active_tab === 'merch' ? 'active' : ''; ?>">

    <!-- Toolbar: search + filters -->
    <div class="toolbar">
        <input type="text" id="merchSearchInput" placeholder="&#128269; Search by product name or SKU&hellip;" oninput="filterTable()">
        <select id="catFilter" onchange="filterTable()">
            <option value="">All Categories</option>
            <?php foreach ($all_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="brandFilter" onchange="filterTable()">
            <option value="">All Brands</option>
            <?php foreach ($all_brands as $brand): ?>
                <option value="<?php echo htmlspecialchars($brand); ?>"><?php echo htmlspecialchars($brand); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="unitFilter" onchange="filterTable()">
            <option value="">All UOMs</option>
            <?php foreach ($all_units as $unit): ?>
                <option value="<?php echo htmlspecialchars($unit); ?>"><?php echo htmlspecialchars($unit); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="supplierFilter" onchange="filterTable()">
            <option value="">All Suppliers</option>
            <?php foreach ($all_suppliers as $supplier): ?>
                <option value="<?php echo htmlspecialchars($supplier); ?>"><?php echo htmlspecialchars($supplier); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="statusFilter" onchange="filterTable()">
            <option value="">All Statuses</option>
            <option value="available">Available</option>
            <option value="critical">Critical Stock</option>
            <option value="low">Low Stock</option>
            <option value="out">Out of Stock</option>
            <option value="inactive">Deactivated</option>
            <option value="noprice">No Price Set</option>
            <option value="belowcost">Price Below Cost</option>
        </select>
    </div>
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
        <button onclick="openAddMerchandiseModal()" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:15.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:all 0.2s;">
            <i class="fas fa-plus-circle"></i> Add Merchandise
        </button>
    </div>

    <?php if (empty($merch_by_cat)): ?>
        <div class="card" style="padding:28px;text-align:center;color:#94a3b8;">
            <i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>
            No merchandise products found.
        </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="table-wrap" style="overflow-x:hidden; width:100%;">
            <table class="pricing-table" id="merchTable" style="width:100%; table-layout:fixed;">
                <colgroup>
                    <col style="width: 10%;"> <!-- SKU / Code -->
                    <col style="width: 27%;"> <!-- Product Name (expanded for clean wrapping) -->
                    <col style="width: 12%;"> <!-- Category -->
                    <col style="width: 8%;">  <!-- Brand -->
                    <col style="width: 6%;">  <!-- UOM -->
                    <col style="width: 9%;">  <!-- Default Selling Price -->
                    <col style="width: 6%;">  <!-- Reorder Lvl -->
                    <col style="width: 8%;">  <!-- Status -->
                    <col style="width: 14%;"> <!-- Actions -->
                </colgroup>
                <thead>
                    <tr>
                        <th style="text-align:left;padding-left:12px;">SKU / Code</th>
                        <th style="text-align:left;">Product Name</th>
                        <th style="text-align:left;">Category</th>
                        <th style="text-align:left;">Brand</th>
                        <th style="text-align:left;">UOM</th>
                        <th style="text-align:right;">Selling Price (VAT-Inc)</th>
                        <th style="text-align:center;">Reorder Lvl</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="merchBody">
                <?php foreach ($merch_by_cat as $cat_label => $items): ?>
                    <tr class="cat-row" data-cat-header="<?php echo htmlspecialchars($cat_label); ?>">
                        <td colspan="9" style="padding:8px 12px;">
                            <i class="fas fa-folder" style="color:#002F6C;margin-right:4px;"></i>
                            <strong><?php echo htmlspecialchars($cat_label); ?></strong>
                            <span class="muted cat-count" style="font-weight:600;margin-left:6px;font-size:12px;">(<?php echo count($items); ?> items)</span>
                        </td>
                    </tr>
                    <?php foreach ($items as $item):
                        $price         = $item['_price'];
                        $stock         = $item['_stock'];
                        $reorder_level = (int)($item['reorder_level']  ?? 24);
                        $critical_lvl  = (int)($item['critical_level'] ?? 10);
                        $no_price      = ($price <= 0);
                        $brand_display = htmlspecialchars($item['brand'] ?? '—');
                        $product_status = strtolower(trim($item['status'] ?? 'active'));
                        $is_inactive   = in_array($product_status, ['inactive','disabled','deactivated']);

                        if ($is_inactive) {
                            $st_label   = 'Deactivated';
                            $st_class   = 'badge-inactive';
                            $st_key     = 'inactive';
                            $style_attr = 'background:#fee2e2 !important;color:#b91c1c !important;border:1.5px solid #f87171 !important;';
                        } elseif ($stock <= 0) {
                            $st_label   = 'Out of Stock';
                            $st_class   = 'badge-out';
                            $st_key     = 'out';
                            $style_attr = 'background:#fee2e2 !important;color:#b91c1c !important;border:1px solid #fca5a5 !important;';
                        } elseif ($critical_lvl > 0 && $stock <= $critical_lvl) {
                            $st_label   = 'Critical Stock';
                            $st_class   = 'badge-critical';
                            $st_key     = 'critical';
                            $style_attr = 'background:#fef2f2 !important;color:#991b1b !important;border:1.5px solid #ef4444 !important;';
                        } elseif ($reorder_level > 0 && $stock <= $reorder_level) {
                            $st_label   = 'Low Stock';
                            $st_class   = 'badge-low';
                            $st_key     = 'low';
                            $style_attr = 'background:#fff7ed !important;color:#c2410c !important;border:1px solid #fdba74 !important;';
                        } else {
                            $st_label   = 'Available';
                            $st_class   = 'badge-available';
                            $st_key     = 'available';
                            $style_attr = 'background:#dcfce7 !important;color:#15803d !important;border:1px solid #86efac !important;';
                        }
                    ?>
                    <tr class="merch-row"
                        data-id="<?php echo (int)($item['id'] ?? 0); ?>"
                        data-name="<?php echo strtolower(htmlspecialchars($item['product_name'] ?? '')); ?>"
                        data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                        data-brand="<?php echo strtolower(htmlspecialchars($item['brand'] ?? '')); ?>"
                        data-unit="<?php echo strtolower(htmlspecialchars($item['unit'] ?? '')); ?>"
                        data-supplier="<?php echo strtolower(htmlspecialchars($item['supplier'] ?? 'Petron Corporation')); ?>"
                        data-cat="<?php echo htmlspecialchars($cat_label); ?>"
                        data-status="<?php echo $st_key; ?>"
                        data-noprice="<?php echo $no_price ? '1' : '0'; ?>"
                        <?php if ($is_inactive): ?>style="background:#fff5f5;"<?php endif; ?>>
                        <!-- SKU / Code -->
                        <td style="padding-left:12px;white-space:nowrap;">
                            <code style="font-size:12px;color:#3730a3;background:#e0e7ff;padding:3px 7px;border-radius:4px;font-weight:800;white-space:nowrap;display:inline-block;letter-spacing:0.2px;font-family:monospace;">
                                <?php echo htmlspecialchars($item['sku'] ?? '—'); ?>
                            </code>
                        </td>
                        <!-- Product Name -->
                        <td style="word-break:break-word;line-height:1.25;">
                            <strong style="<?php echo $is_inactive ? 'color:#64748b;' : 'color:#0f172a;'; ?>font-size:13px;display:block;"><?php echo htmlspecialchars($item['product_name'] ?? ''); ?></strong>
                            <?php if ($is_inactive): ?>
                                <span style="font-size:10px;background:#fee2e2;color:#b91c1c;padding:1px 5px;border-radius:3px;font-weight:700;display:inline-block;margin-top:2px;">
                                    <i class="fas fa-ban" style="font-size:9px;"></i> DEACTIVATED
                                </span>
                            <?php endif; ?>
                            <?php if (($item['approval_status'] ?? '') === 'pending'): ?>
                                <div style="font-size:11px;color:#d97706;background:#fef3c7;padding:2px 5px;border-radius:3px;margin-top:2px;font-weight:600;display:inline-block;">Pending: ₱<?php echo number_format($item['pending_price'], 2); ?></div>
                            <?php endif; ?>
                        </td>
                        <!-- Category -->
                        <td style="font-size:12.5px;color:#334155;word-break:break-word;line-height:1.25;"><?php echo htmlspecialchars($cat_label); ?></td>
                        <!-- Brand -->
                        <td style="font-size:12.5px;color:#64748b;word-break:break-word;"><?php echo $brand_display ?: '—'; ?></td>
                        <!-- UOM -->
                        <td style="font-size:12.5px;color:#334155;font-weight:600;white-space:nowrap;"><?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></td>
                        <!-- Default Selling Price -->
                        <td style="text-align:right;white-space:nowrap;">
                            <?php if ($no_price): ?>
                                <span class="badge badge-noprice" style="font-size:11px;">No Price</span>
                            <?php else: ?>
                                <strong style="color:#002F6C;font-size:13px;">&#8369;<?php echo number_format($price, 2); ?></strong>
                            <?php endif; ?>
                        </td>
                        <!-- Reorder Level -->
                        <td style="text-align:center;white-space:nowrap;">
                            <span style="display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:4px;padding:2px 7px;font-size:12px;font-weight:700;"><?php echo number_format($reorder_level); ?></span>
                        </td>
                        <!-- Status -->
                        <td style="text-align:center;white-space:nowrap;">
                            <span class="badge <?php echo $st_class; ?>" style="<?php echo $style_attr; ?>display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;">
                                <?php if ($is_inactive): ?>
                                    <i class="fas fa-ban" style="font-size:9.5px;"></i> DEACTIVATED
                                <?php else: ?>
                                    <?php echo htmlspecialchars($st_label); ?>
                                <?php endif; ?>
                            </span>
                        </td>
                        <!-- Actions -->
                        <td style="text-align:center;white-space:nowrap;">
                            <div class="act-btn-wrap">
                                <button onclick="viewMerchandiseDetails(<?php echo $item['id']; ?>)" class="act-btn act-btn-view">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button onclick="openEditMerchModal(<?php echo $item['id']; ?>)" class="act-btn act-btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if (!$is_inactive): ?>
                                    <button onclick="deactivateMerchandise(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'] ?? '')); ?>')" class="act-btn act-btn-deactivate" style="color:#dc2626 !important;border-color:#fca5a5 !important;background:#fef2f2 !important;">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                <?php else: ?>
                                    <button onclick="activateMerchandise(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'] ?? '')); ?>')" class="act-btn act-btn-activate" style="color:#16a34a !important;border-color:#86efac !important;background:#f0fdf4 !important;">
                                        <i class="fas fa-check-circle"></i> Activate
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="merchNoResults" style="display:none;padding:30px;text-align:center;color:#94a3b8;">
            <i class="fas fa-search" style="font-size:28px;margin-bottom:8px;display:block;"></i>
            No merchandise products match your search/filter criteria.
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 3 — SERVICE TYPES
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-services" class="tab-panel <?php echo $active_tab === 'services' ? 'active' : ''; ?>">
    <?php if (isset($service_error)): ?>
        <div style="padding:20px;background:#fee2e2;color:#991b1b;border-radius:8px;margin-bottom:16px;">
            <strong>Notice:</strong> <?php echo htmlspecialchars($service_error); ?>
        </div>
    <?php endif; ?>

    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <strong style="font-size:15px;color:#002F6C;"><i class="fas fa-tools"></i> Service Management</strong>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" id="svcSearchInput" oninput="filterServiceTable()" placeholder="&#x1F50D; Search services..." style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;color:#334155;background:#fff;min-width:180px;">
                <select id="serviceCategoryFilter" onchange="filterServiceTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;color:#334155;background:#fff;">
                    <option value="">All Categories</option>
                    <option value="Lubrication">Lubrication</option>
                    <option value="Preventive Maintenance">Preventive Maintenance</option>
                    <option value="Oil &amp; Lubrication Services">Oil &amp; Lubrication Services</option>
                    <option value="Engine Services">Engine Services</option>
                    <option value="Brake Services">Brake Services</option>
                    <option value="Tire Services">Tire Services</option>
                    <option value="Battery Services">Battery Services</option>
                    <option value="Cooling System">Cooling System</option>
                    <option value="Electrical Services">Electrical Services</option>
                    <option value="Air Conditioning">Air Conditioning</option>
                    <option value="Undercarriage Services">Undercarriage Services</option>
                    <option value="Cleaning Services">Cleaning Services</option>
                    <option value="Emergency Services">Emergency Services</option>
                    <option value="Others">Others</option>
                    <option value="Custom Services">Custom Services</option>
                </select>
                <select id="svcStatusFilter" onchange="filterServiceTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;color:#334155;background:#fff;">
                    <option value="">All Status</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
        </div>
        <div style="padding:10px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:flex-end;">
            <button onclick="openAddServiceModal()" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:15.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:all 0.2s;">
                <i class="fas fa-plus-circle"></i> Add Service
            </button>
        </div>
        
        <?php if (empty($service_types)): ?>
            <div style="padding:40px;text-align:center;color:#94a3b8;">
                <i class="fas fa-tools" style="font-size:36px;margin-bottom:12px;display:block;color:#cbd5e1;"></i>
                <div style="font-size:15px;font-weight:600;color:#64748b;margin-bottom:4px;">No service types found</div>
                <div style="font-size:15.5px;">Click <strong>Add Service</strong> to add your first service type.</div>
            </div>
        <?php else: ?>
        <div class="table-wrap" style="overflow-x: hidden; width:100%;">
            <table class="pricing-table" id="servicePricingTable" style="table-layout:fixed; width:100%;">
                <colgroup>
                    <col style="width: 8%;">   <!-- Code -->
                    <col style="width: 22%;">  <!-- Service Name -->
                    <col style="width: 12%;">  <!-- Category -->
                    <col style="width: 10%;">  <!-- Service Fee -->
                    <col style="width: 10%;">  <!-- Labor Fee -->
                    <col style="width: 12%;">  <!-- Price Req. -->
                    <col style="width: 8%;">   <!-- Status -->
                    <col style="width: 8%;">   <!-- Last Updated -->
                    <col style="width: 10%;">  <!-- Action -->
                </colgroup>
                <thead>
                    <tr>
                        <th style="text-align:left;padding-left:8px;">Code</th>
                        <th style="text-align:left;">Service Name</th>
                        <th style="text-align:left;">Category</th>
                        <th style="text-align:right;">Service Fee (VAT-Inc)</th>
                        <th style="text-align:right;">Labor Fee (VAT-Inc)</th>
                        <th style="text-align:center;">Price Req.</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:center;">Last Updated</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody id="serviceTableBody">
                    <?php foreach ($service_types as $svc):
                        $svcId        = (int)$svc['id'];
                        $svcCode      = htmlspecialchars($svc['service_code'] ?? ('SVC-' . str_pad($svcId, 4, '0', STR_PAD_LEFT)));
                        $svcName      = htmlspecialchars($svc['service_name']);
                        $svcKey       = htmlspecialchars($svc['service_key'] ?? '');
                        $svcCat       = htmlspecialchars($svc['category'] ?? 'Others');
                        $svcFee       = (float)($svc['service_price'] ?? 0);
                        $laborFee     = (float)($svc['labor_fee'] ?? 0);
                        $duration     = (int)($svc['estimated_duration'] ?? 60);
                        $mechanics    = (int)($svc['required_mechanics'] ?? 1);
                        $svcDesc      = htmlspecialchars($svc['description'] ?? '');
                        $isActive     = (int)($svc['active'] ?? 1) === 1;
                        $hasPending   = ($svc['approval_status'] ?? '') === 'pending';
                        $pendSvcFee   = $hasPending ? (float)($svc['pending_service_fee'] ?? 0) : 0;
                        $pendLabFee   = $hasPending ? (float)($svc['pending_labor_fee'] ?? 0) : 0;
                        $updatedAt    = !empty($svc['updated_at']) ? date('M j, Y', strtotime($svc['updated_at'])) : '—';
                        $hrs          = floor($duration / 60);
                        $mins         = $duration % 60;
                        $durationStr  = ($hrs > 0 ? $hrs . 'h' : '') . ($mins > 0 ? ($hrs > 0 ? ' ' : '') . $mins . 'm' : ($hrs === 0 ? '0m' : ''));
                        $jsObj = json_encode([
                            'id'                 => $svcId,
                            'service_code'       => $svc['service_code'] ?? '',
                            'service_name'       => $svc['service_name'],
                            'service_key'        => $svc['service_key'] ?? '',
                            'category'           => $svc['category'] ?? '',
                            'service_price'      => $svcFee,
                            'labor_fee'          => $laborFee,
                            'estimated_duration' => $duration,
                            'duration_str'       => $durationStr,
                            'required_mechanics' => $mechanics,
                            'description'        => $svc['description'] ?? '',
                            'active'             => $isActive ? 1 : 0,
                            'updated_at'         => $updatedAt,
                        ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    ?>
                    <tr class="service-row"
                        data-category="<?php echo $svcCat; ?>"
                        data-active="<?php echo $isActive ? '1' : '0'; ?>"
                        data-name="<?php echo strtolower(htmlspecialchars($svc['service_name'])); ?>"
                        <?php if (!$isActive): ?>style="background:#fff5f5;"<?php endif; ?>>
                        
                        <!-- Code -->
                        <td style="padding:6px 6px;box-sizing:border-box;vertical-align:middle;">
                            <span style="font-family:monospace;font-size:11px;color:#0369a1;font-weight:700;background:#e0f2fe;padding:2px 6px;border-radius:4px;white-space:nowrap;border:1px solid #bae6fd;display:inline-block;"><?php echo $svcCode; ?></span>
                        </td>

                        <!-- Service Name -->
                        <td style="padding:6px 8px;box-sizing:border-box;vertical-align:middle;">
                            <div style="font-weight:700;<?php echo !$isActive ? 'color:#64748b;' : 'color:#1e293b;'; ?>font-size:12px;line-height:1.35;"><?php echo $svcName; ?></div>
                            <?php if (!$isActive): ?>
                                <span style="font-size:9.5px;background:#fee2e2;color:#b91c1c;padding:1px 5px;border-radius:3px;font-weight:700;display:inline-block;margin-top:2px;">
                                    <i class="fas fa-ban" style="font-size:8.5px;"></i> DEACTIVATED
                                </span>
                            <?php endif; ?>
                            <?php if ($svcDesc): ?>
                            <div style="font-size:10.5px;color:#64748b;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $svcDesc; ?>"><?php echo $svcDesc; ?></div>
                            <?php endif; ?>
                        </td>

                        <!-- Category -->
                        <td style="padding:6px 6px;box-sizing:border-box;vertical-align:middle;">
                            <span style="background:#f0f7ff;color:#003d7a;border:1px solid #dbeafe;padding:2px 7px;border-radius:999px;font-size:10.5px;font-weight:700;display:inline-block;white-space:nowrap;"><?php echo $svcCat; ?></span>
                        </td>

                        <!-- Service Fee -->
                        <td style="padding:6px 6px;box-sizing:border-box;vertical-align:middle;text-align:right;">
                            <div style="font-weight:700;color:#002F6C;font-size:12px;white-space:nowrap;">&#8369;<?php echo number_format($svcFee, 2); ?></div>
                            <?php if ($hasPending && $pendSvcFee > 0): ?>
                            <div style="font-size:9.5px;color:#d97706;background:#fef3c7;border:1px solid #fde68a;padding:1px 4px;border-radius:4px;margin-top:2px;font-weight:700;display:inline-block;white-space:nowrap;">
                                <i class="fas fa-hourglass-half" style="font-size:8px;"></i> &#8369;<?php echo number_format($pendSvcFee, 2); ?>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Labor Fee -->
                        <td style="padding:6px 6px;box-sizing:border-box;vertical-align:middle;text-align:right;">
                            <div style="font-weight:700;color:#0369a1;font-size:12px;white-space:nowrap;">&#8369;<?php echo number_format($laborFee, 2); ?></div>
                            <?php if ($hasPending && $pendLabFee > 0): ?>
                            <div style="font-size:9.5px;color:#d97706;background:#fef3c7;border:1px solid #fde68a;padding:1px 4px;border-radius:4px;margin-top:2px;font-weight:700;display:inline-block;white-space:nowrap;">
                                <i class="fas fa-hourglass-half" style="font-size:8px;"></i> &#8369;<?php echo number_format($pendLabFee, 2); ?>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Price Request Status -->
                        <td style="padding:6px 4px;box-sizing:border-box;vertical-align:middle;text-align:center;">
                            <?php if ($hasPending): ?>
                            <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;white-space:nowrap;" title="Requested: Svc Fee ₱<?php echo number_format($pendSvcFee, 2); ?><?php echo $pendLabFee > 0 ? ' | Labor ₱'.number_format($pendLabFee, 2) : ''; ?>">
                                <i class="fas fa-clock" style="font-size:8.5px;"></i> Pending (₱<?php echo number_format($pendSvcFee, 2); ?>)
                            </span>
                            <?php else: ?>
                            <span style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:700;display:inline-flex;align-items:center;gap:3px;white-space:nowrap;">
                                <i class="fas fa-check-circle" style="font-size:8.5px;"></i> Current
                            </span>
                            <?php endif; ?>
                        </td>

                        <!-- Status -->
                        <td style="padding:6px 4px;box-sizing:border-box;vertical-align:middle;text-align:center;">
                            <?php if ($isActive): ?>
                            <span style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:700;display:inline-block;white-space:nowrap;text-transform:uppercase;">Active</span>
                            <?php else: ?>
                            <span style="background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:700;display:inline-block;white-space:nowrap;text-transform:uppercase;">Inactive</span>
                            <?php endif; ?>
                        </td>

                        <!-- Last Updated -->
                        <td style="padding:6px 4px;text-align:center;font-size:11px;color:#64748b;white-space:nowrap;"><?php echo $updatedAt; ?></td>

                        <!-- Action -->
                        <td style="text-align:center;vertical-align:middle;padding:6px 4px !important;overflow:visible !important;max-width:none !important;">
                            <div class="act-btn-wrap">
                                <button type="button" onclick='openViewServiceModal(<?php echo htmlspecialchars($jsObj, ENT_QUOTES, "UTF-8"); ?>)' class="act-btn act-btn-view">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button type="button" onclick='openEditServiceModal(<?php echo htmlspecialchars($jsObj, ENT_QUOTES, "UTF-8"); ?>)' class="act-btn act-btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if ($isActive): ?>
                                <button type="button" onclick="deactivateService(<?php echo $svcId; ?>, '<?php echo addslashes($svc['service_name']); ?>')" class="act-btn act-btn-deactivate">
                                    <i class="fas fa-ban"></i> Deactivate
                                </button>
                                <?php else: ?>
                                <button type="button" onclick="activateService(<?php echo $svcId; ?>, '<?php echo addslashes($svc['service_name']); ?>')" class="act-btn act-btn-activate">
                                    <i class="fas fa-check-circle"></i> Activate
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="svcNoResults" style="display:none;padding:30px;text-align:center;color:#94a3b8;">
            <i class="fas fa-search" style="font-size:28px;margin-bottom:8px;display:block;"></i>
            No services match your search/filter criteria.
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ── Helper: sanitize decimal inputs (avoids regex in HTML attributes which breaks draft engine) ──
function sanitizeDecimalInput(el) {
    var v = el.value.replace(/[^0-9.]/g, '');
    var parts = v.split('.');
    if (parts.length > 2) { v = parts[0] + '.' + parts.slice(1).join(''); }
    el.value = v;
}
// ── Tab switching — updates URL & sessionStorage so refresh stays on same tab ─
// ── Export Product & Pricing Report ─────────────────────────────────────────
function exportPricing(format) {
    const activeTab = document.getElementById('activeSection')?.value || 'fuel';
    let q = '', st = '', cat = '', brd = '';
    if (activeTab === 'fuel') {
        q  = encodeURIComponent(document.getElementById('fuelSearchInput')?.value || '');
        st = encodeURIComponent(document.getElementById('fuelStatusFilter')?.value || '');
    } else if (activeTab === 'merch') {
        q   = encodeURIComponent(document.getElementById('merchSearchInput')?.value || '');
        st  = encodeURIComponent(document.getElementById('statusFilter')?.value || '');
        cat = encodeURIComponent(document.getElementById('catFilter')?.value || '');
        brd = encodeURIComponent(document.getElementById('brandFilter')?.value || '');
    } else if (activeTab === 'services') {
        q   = encodeURIComponent(document.getElementById('svcSearchInput')?.value || '');
        st  = encodeURIComponent(document.getElementById('svcStatusFilter')?.value || '');
        cat = encodeURIComponent(document.getElementById('serviceCategoryFilter')?.value || '');
    }
    const url = `export_pricing_products.php?tab=${activeTab}&format=${format}&station_id=<?php echo (int)$station_id; ?>&q=${q}&status=${st}&category=${cat}&brand=${brd}&_ts=${Date.now()}`;

    if (format === 'print') {
        // Direct print on current page — no new tab or popup
        var printUrl = 'export_pricing_products.php?tab=' + activeTab + '&format=print&station_id=<?php echo (int)$station_id; ?>&q=' + q + '&status=' + st + '&category=' + cat + '&brand=' + brd + '&_ts=' + Date.now();
        fetch(printUrl, { credentials: 'same-origin' })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                doc.querySelectorAll('script').forEach(function(s) { s.remove(); });

                var existing = document.getElementById('report-print-root');
                if (existing) existing.remove();
                var existingPs = document.getElementById('pricing-print-styles');
                if (existingPs) existingPs.remove();

                var printRoot = document.createElement('div');
                printRoot.id = 'report-print-root';
                printRoot.innerHTML = doc.body ? doc.body.innerHTML : html;
                document.body.appendChild(printRoot);

                var styleContent = '';
                doc.querySelectorAll('style').forEach(function(s) { styleContent += s.textContent; });
                if (styleContent) {
                    var styleEl = document.createElement('style');
                    styleEl.id = 'pricing-print-styles';
                    styleEl.textContent = styleContent;
                    document.head.appendChild(styleEl);
                }

                document.body.classList.add('report-printing');
                var cleaned = false;
                var cleanup = function() {
                    if (cleaned) return;
                    cleaned = true;
                    document.body.classList.remove('report-printing');
                    var n = document.getElementById('report-print-root');
                    if (n) n.remove();
                    var ps = document.getElementById('pricing-print-styles');
                    if (ps) ps.remove();
                    window.removeEventListener('afterprint', cleanup);
                };
                window.addEventListener('afterprint', cleanup);
                setTimeout(function() {
                    window.print();
                }, 120);
                setTimeout(cleanup, 60000);
            })
            .catch(function() {
                // Fallback: open in new tab if fetch fails
                window.open('export_pricing_products.php?tab=' + activeTab + '&format=print&station_id=<?php echo (int)$station_id; ?>&q=' + q + '&status=' + st + '&category=' + cat + '&brand=' + brd, '_blank');
            });
    } else {
        // PDF, Excel, CSV — direct download via location.href (no new tab)
        window.location.href = url;
    }
}

function switchTab(name) {
    if (['fuel', 'merch', 'services'].indexOf(name) === -1) name = 'fuel';

    document.querySelectorAll('.tab-panel').forEach(function(p) {
        if (p) p.classList.remove('active');
    });
    var targetTab = document.getElementById('tab-' + name);
    if (targetTab) targetTab.classList.add('active');

    document.querySelectorAll('.ato-tab').forEach(function(btn) {
        btn.classList.remove('active');
    });
    var targetBtn = document.getElementById('tab-btn-' + name);
    if (targetBtn) targetBtn.classList.add('active');

    var activeSec = document.getElementById('activeSection');
    if (activeSec) activeSec.value = name;

    // Update URL without reloading so refresh lands on the same tab
    try {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        window.history.replaceState(null, '', url.toString());
    } catch (e) {}

    // Persist in sessionStorage
    try {
        sessionStorage.setItem('petron_manager_active_tab', name);
    } catch (e) {}

    // Immediately apply active filters for the selected tab
    if (name === 'fuel' && typeof window.filterFuelTable === 'function') {
        window.filterFuelTable();
    } else if (name === 'merch' && typeof window.filterTable === 'function') {
        window.filterTable();
    } else if (name === 'services' && typeof window.filterServiceTable === 'function') {
        window.filterServiceTable();
    }
}

(function() {
    var urlParams = new URLSearchParams(window.location.search);
    var tabFromUrl = urlParams.get('tab');
    var tabFromStorage = null;
    try { tabFromStorage = sessionStorage.getItem('petron_manager_active_tab'); } catch (e) {}

    var targetTab = (tabFromUrl && ['fuel', 'merch', 'services'].indexOf(tabFromUrl) !== -1)
        ? tabFromUrl
        : (tabFromStorage && ['fuel', 'merch', 'services'].indexOf(tabFromStorage) !== -1 ? tabFromStorage : 'fuel');

    switchTab(targetTab);
})();

// ── Manager Filter Caches ───────────────────────────────────────────────────
var _mgrFuelCache = null;
var _mgrMerchCache = null;
var _mgrCatHeaderCache = null;
var _mgrSvcCache = null;

function getMgrFuelCache() {
    if (_mgrFuelCache === null) {
        var rows = document.querySelectorAll('#fuelPricingTable tr.fuel-row, #tab-fuel tr.fuel-row');
        _mgrFuelCache = [];
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            _mgrFuelCache.push({
                el: row,
                ugt: (row.getAttribute('data-ugt') || '').toLowerCase(),
                name: (row.getAttribute('data-name') || '').toLowerCase(),
                fueltype: (row.getAttribute('data-fueltype') || '').toLowerCase().trim(),
                status: (row.getAttribute('data-status') || '').trim(),
                active: (row.getAttribute('data-active') || '').trim()
            });
        }
    }
    return _mgrFuelCache;
}

function getMgrMerchCache() {
    if (_mgrMerchCache === null) {
        var rows = document.querySelectorAll('#merchBody .merch-row');
        _mgrMerchCache = [];
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            _mgrMerchCache.push({
                el: row,
                name: (row.getAttribute('data-name') || '').toLowerCase(),
                sku: (row.getAttribute('data-sku') || '').toLowerCase(),
                brand: (row.getAttribute('data-brand') || '').toLowerCase(),
                unit: (row.getAttribute('data-unit') || '').toLowerCase(),
                supplier: (row.getAttribute('data-supplier') || '').toLowerCase(),
                cat: row.getAttribute('data-cat') || '',
                catLower: (row.getAttribute('data-cat') || '').toLowerCase(),
                status: (row.getAttribute('data-status') || '').trim(),
                noprice: row.getAttribute('data-noprice') === '1',
                belowcost: row.getAttribute('data-belowcost') === '1'
            });
        }
        var catHeaders = document.querySelectorAll('#merchBody .cat-row');
        _mgrCatHeaderCache = [];
        for (var j = 0; j < catHeaders.length; j++) {
            var hdr = catHeaders[j];
            _mgrCatHeaderCache.push({
                el: hdr,
                cat: hdr.getAttribute('data-cat-header') || '',
                countSpan: hdr.querySelector('.cat-count')
            });
        }
    }
    return { rows: _mgrMerchCache, headers: _mgrCatHeaderCache };
}

function getMgrSvcCache() {
    if (_mgrSvcCache === null) {
        var rows = document.querySelectorAll('#servicePricingTable tr.service-row, #serviceTableBody tr.service-row, #tab-services tr.service-row');
        _mgrSvcCache = [];
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            _mgrSvcCache.push({
                el: row,
                name: (row.getAttribute('data-name') || '').toLowerCase(),
                cat: (row.getAttribute('data-category') || '').trim(),
                catLower: (row.getAttribute('data-category') || '').toLowerCase().trim(),
                active: (row.getAttribute('data-active') || '').trim()
            });
        }
    }
    return _mgrSvcCache;
}

// ── Fuel Products Filter (Cached & Optimized) ──────────────────────────────
window.filterFuelTable = function filterFuelTable() {
    var searchEl = document.getElementById('fuelSearchInput');
    var q        = searchEl ? (searchEl.value || '').toLowerCase().trim() : '';
    var stFilter = document.getElementById('fuelStatusFilter') ? document.getElementById('fuelStatusFilter').value.trim() : '';
    var ftFilter = document.getElementById('fuelTypeFilter') ? document.getElementById('fuelTypeFilter').value.trim().toLowerCase() : '';

    // Persist filter values
    try {
        sessionStorage.setItem('petron_mgr_fuel_q', q);
        sessionStorage.setItem('petron_mgr_fuel_st', stFilter);
        sessionStorage.setItem('petron_mgr_fuel_ft', ftFilter);
    } catch(e) {}

    var rows = getMgrFuelCache();
    var visible = 0;

    for (var i = 0; i < rows.length; i++) {
        var item = rows[i];
        var matchQ  = !q || item.ugt.indexOf(q) !== -1 || item.name.indexOf(q) !== -1 || item.fueltype.indexOf(q) !== -1;
        var matchFt = !ftFilter || item.fueltype === ftFilter;
        var matchSt = true;
        if (stFilter === 'Normal') matchSt = (item.status === 'Normal' && item.active !== 'inactive');
        else if (stFilter === 'Low Stock') matchSt = (item.status === 'Low Stock' && item.active !== 'inactive');
        else if (stFilter === 'Out of Stock') matchSt = (item.status === 'Out of Stock' && item.active !== 'inactive');
        else if (stFilter === 'Deactivated') matchSt = (item.status === 'Deactivated' || item.active === 'inactive');

        var show = matchQ && matchFt && matchSt;
        item.el.style.display = show ? '' : 'none';
        if (show) visible++;
    }

    var noRes = document.getElementById('fuelNoResults');
    if (noRes) noRes.style.display = (visible === 0 && rows.length > 0) ? 'block' : 'none';
};

// ── Merchandise filter (Cached & Optimized) ─────────────────────────────────
window.filterTable = function filterTable() {
    var merchTab = document.getElementById('tab-merch');
    if (!merchTab) return;
    
    var searchEl       = document.getElementById('merchSearchInput') || document.getElementById('searchInput');
    var q              = searchEl ? (searchEl.value || '').toLowerCase().trim() : '';
    var catFilter      = document.getElementById('catFilter') ? document.getElementById('catFilter').value : '';
    var brandFilter    = document.getElementById('brandFilter') ? document.getElementById('brandFilter').value : '';
    var unitFilter     = document.getElementById('unitFilter') ? document.getElementById('unitFilter').value : '';
    var supplierFilter = document.getElementById('supplierFilter') ? document.getElementById('supplierFilter').value : '';
    var stFilter       = document.getElementById('statusFilter') ? document.getElementById('statusFilter').value : '';

    // Persist filter values
    try {
        sessionStorage.setItem('petron_mgr_merch_q', q);
        sessionStorage.setItem('petron_mgr_merch_cat', catFilter);
        sessionStorage.setItem('petron_mgr_merch_brand', brandFilter);
        sessionStorage.setItem('petron_mgr_merch_unit', unitFilter);
        sessionStorage.setItem('petron_mgr_merch_supplier', supplierFilter);
        sessionStorage.setItem('petron_mgr_merch_st', stFilter);
    } catch(e) {}

    var cache = getMgrMerchCache();
    var rows = cache.rows;
    var catHeaders = cache.headers;
    var visible = 0;
    var catVisibleCount = {};

    var brandFilterLower = brandFilter ? brandFilter.toLowerCase() : '';
    var unitFilterLower = unitFilter ? unitFilter.toLowerCase() : '';
    var supplierFilterLower = supplierFilter ? supplierFilter.toLowerCase() : '';

    for (var i = 0; i < rows.length; i++) {
        var item = rows[i];
        var matchQ = !q || item.name.indexOf(q) !== -1 || item.sku.indexOf(q) !== -1 || item.brand.indexOf(q) !== -1 || item.unit.indexOf(q) !== -1 || item.supplier.indexOf(q) !== -1 || item.catLower.indexOf(q) !== -1;
        var matchCat      = !catFilter || item.cat === catFilter;
        var matchBrand    = !brandFilterLower || item.brand === brandFilterLower;
        var matchUnit     = !unitFilterLower || item.unit === unitFilterLower;
        var matchSupplier = !supplierFilterLower || item.supplier === supplierFilterLower;
        var matchSt       = true;
        if (stFilter === 'available')  matchSt = (item.status === 'available');
        else if (stFilter === 'low')   matchSt = (item.status === 'low');
        else if (stFilter === 'critical') matchSt = (item.status === 'critical');
        else if (stFilter === 'out')   matchSt = (item.status === 'out');
        else if (stFilter === 'inactive' || stFilter === 'deactivated') matchSt = (item.status === 'inactive' || item.status === 'deactivated');
        else if (stFilter === 'noprice')   matchSt = item.noprice;
        else if (stFilter === 'belowcost') matchSt = item.belowcost;

        var show = matchQ && matchCat && matchBrand && matchUnit && matchSupplier && matchSt;
        item.el.style.display = show ? '' : 'none';
        if (show) {
            visible++;
            catVisibleCount[item.cat] = (catVisibleCount[item.cat] || 0) + 1;
        }
    }

    for (var j = 0; j < catHeaders.length; j++) {
        var hdr = catHeaders[j];
        var count = catVisibleCount[hdr.cat] || 0;
        if (count > 0) {
            hdr.el.style.display = '';
            if (hdr.countSpan) hdr.countSpan.textContent = '(' + count + ' item' + (count !== 1 ? 's' : '') + ')';
        } else {
            hdr.el.style.display = 'none';
        }
    }

    var noRes = document.getElementById('merchNoResults');
    if (noRes) noRes.style.display = (visible === 0 && rows.length > 0) ? 'block' : 'none';
};

// ── Service Types filter (Cached & Optimized) ───────────────────────────────
window.filterServiceTable = function filterServiceTable() {
    var searchEl  = document.getElementById('svcSearchInput');
    var q         = searchEl ? (searchEl.value || '').toLowerCase().trim() : '';
    var catFilter = document.getElementById('serviceCategoryFilter') ? document.getElementById('serviceCategoryFilter').value.trim().toLowerCase() : '';
    var stFilter  = document.getElementById('svcStatusFilter') ? document.getElementById('svcStatusFilter').value.trim() : '';

    // Persist filter values
    try {
        sessionStorage.setItem('petron_mgr_svc_q', q);
        sessionStorage.setItem('petron_mgr_svc_cat', catFilter);
        sessionStorage.setItem('petron_mgr_svc_st', stFilter);
    } catch(e) {}

    var rows = getMgrSvcCache();
    var visible = 0;

    for (var i = 0; i < rows.length; i++) {
        var item = rows[i];
        var matchQ   = !q || item.name.indexOf(q) !== -1 || item.catLower.indexOf(q) !== -1;
        var matchCat = !catFilter || item.catLower === catFilter;
        var matchSt  = true;
        if (stFilter === '1') matchSt = (item.active === '1');
        else if (stFilter === '0') matchSt = (item.active === '0');

        var show = matchQ && matchCat && matchSt;
        item.el.style.display = show ? '' : 'none';
        if (show) visible++;
    }

    var noRes = document.getElementById('svcNoResults');
    if (noRes) noRes.style.display = (visible === 0 && rows.length > 0) ? 'block' : 'none';
};

var _debouncedMgrFuelTimer = null;
function debouncedFilterFuelTable() {
    clearTimeout(_debouncedMgrFuelTimer);
    _debouncedMgrFuelTimer = setTimeout(window.filterFuelTable, 160);
}

var _debouncedMgrMerchTimer = null;
function debouncedFilterTable() {
    clearTimeout(_debouncedMgrMerchTimer);
    _debouncedMgrMerchTimer = setTimeout(window.filterTable, 160);
}

var _debouncedMgrSvcTimer = null;
function debouncedFilterServiceTable() {
    clearTimeout(_debouncedMgrSvcTimer);
    _debouncedMgrSvcTimer = setTimeout(window.filterServiceTable, 160);
}

document.addEventListener('DOMContentLoaded', function() {
    // 1. Restore Fuel Filters from sessionStorage
    try {
        var savedFuelQ  = sessionStorage.getItem('petron_mgr_fuel_q');
        var savedFuelSt = sessionStorage.getItem('petron_mgr_fuel_st');
        var fuelQEl = document.getElementById('fuelSearchInput');
        var fuelStEl = document.getElementById('fuelStatusFilter');
        if (fuelQEl && savedFuelQ !== null && savedFuelQ !== '') fuelQEl.value = savedFuelQ;
        if (fuelStEl && savedFuelSt !== null && savedFuelSt !== '') fuelStEl.value = savedFuelSt;
    } catch(e) {}

    // 2. Restore Merchandise Filters from sessionStorage
    try {
        var savedMerchQ   = sessionStorage.getItem('petron_mgr_merch_q');
        var savedMerchCat = sessionStorage.getItem('petron_mgr_merch_cat');
        var savedMerchBrd = sessionStorage.getItem('petron_mgr_merch_brand');
        var savedMerchUnt = sessionStorage.getItem('petron_mgr_merch_unit');
        var savedMerchSup = sessionStorage.getItem('petron_mgr_merch_supplier');
        var savedMerchSt  = sessionStorage.getItem('petron_mgr_merch_st');

        var mQEl   = document.getElementById('merchSearchInput') || document.getElementById('searchInput');
        var mCatEl = document.getElementById('catFilter');
        var mBrdEl = document.getElementById('brandFilter');
        var mUntEl = document.getElementById('unitFilter');
        var mSupEl = document.getElementById('supplierFilter');
        var mStEl  = document.getElementById('statusFilter');

        if (mQEl   && savedMerchQ   !== null && savedMerchQ !== '')   mQEl.value   = savedMerchQ;
        if (mCatEl && savedMerchCat !== null && savedMerchCat !== '') mCatEl.value = savedMerchCat;
        if (mBrdEl && savedMerchBrd !== null && savedMerchBrd !== '') mBrdEl.value = savedMerchBrd;
        if (mUntEl && savedMerchUnt !== null && savedMerchUnt !== '') mUntEl.value = savedMerchUnt;
        if (mSupEl && savedMerchSup !== null && savedMerchSup !== '') mSupEl.value = savedMerchSup;
        if (mStEl  && savedMerchSt  !== null && savedMerchSt !== '')  mStEl.value  = savedMerchSt;
    } catch(e) {}

    // 3. Restore Service Filters from sessionStorage
    try {
        var savedSvcQ   = sessionStorage.getItem('petron_mgr_svc_q');
        var savedSvcCat = sessionStorage.getItem('petron_mgr_svc_cat');
        var savedSvcSt  = sessionStorage.getItem('petron_mgr_svc_st');

        var sQEl   = document.getElementById('svcSearchInput');
        var sCatEl = document.getElementById('serviceCategoryFilter');
        var sStEl  = document.getElementById('svcStatusFilter');

        if (sQEl   && savedSvcQ   !== null && savedSvcQ !== '')   sQEl.value   = savedSvcQ;
        if (sCatEl && savedSvcCat !== null && savedSvcCat !== '') sCatEl.value = savedSvcCat;
        if (sStEl  && savedSvcSt  !== null && savedSvcSt !== '')  sStEl.value  = savedSvcSt;
    } catch(e) {}

    // 4. Attach Event Listeners (Debounced single input listener for buttery smooth typing)
    var fuelQInput = document.getElementById('fuelSearchInput');
    if (fuelQInput) {
        fuelQInput.addEventListener('input', debouncedFilterFuelTable);
    }
    var fuelStSelect = document.getElementById('fuelStatusFilter');
    if (fuelStSelect) {
        fuelStSelect.addEventListener('change', window.filterFuelTable);
    }

    ['merchSearchInput', 'searchInput'].forEach(function(id) {
        var input = document.getElementById(id);
        if (input) {
            input.addEventListener('input', debouncedFilterTable);
        }
    });
    ['catFilter', 'brandFilter', 'unitFilter', 'supplierFilter', 'statusFilter'].forEach(function(id) {
        var sel = document.getElementById(id);
        if (sel) {
            sel.addEventListener('change', window.filterTable);
        }
    });

    var svcQInput = document.getElementById('svcSearchInput');
    if (svcQInput) {
        svcQInput.addEventListener('input', debouncedFilterServiceTable);
    }
    ['serviceCategoryFilter', 'svcStatusFilter'].forEach(function(id) {
        var sel = document.getElementById(id);
        if (sel) {
            sel.addEventListener('change', window.filterServiceTable);
        }
    });

    // 5. Initial filter execution to apply restored values
    window.filterFuelTable();
    window.filterTable();
    window.filterServiceTable();
});
</script>

<!-- ══════════════════════════════════════════════════════════════════════════
     MODALS — Add Product & Edit Price
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- Add Product Modal (Landscape Layout) -->
<div id="addProductModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px 20px 60px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:12px;width:92%;max-width:760px;max-height:calc(100vh - 110px);display:flex;flex-direction:column;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:auto;overflow:hidden;">
        <!-- Modal Header -->
        <div style="flex-shrink:0;background:linear-gradient(135deg,#002F6C,#004494);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;">
            <h3 style="margin:0;font-size:17px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;letter-spacing:0.3px;">
                <i class="fas fa-plus-circle" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:18px;"></i>
                <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">ADD FUEL PRODUCT</span>
            </h3>
        </div>
        <!-- Modal Form Body (Landscape 2-Column Grid with Scroll) -->
        <form id="addProductForm" style="padding:16px 24px 18px;overflow-y:auto;flex:1 1 auto;display:flex;flex-direction:column;">
            <!-- Row 1: Fuel Name + UGT Number -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Fuel Name <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newFuelName" maxlength="50" required
                           style="width:100%;padding:7px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="e.g. Diesel, XCS Plus, Turbo Diesel">
                </div>
                <div>
                    <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        UGT Number <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" id="newUgtNo" maxlength="20" required value=""
                           style="width:100%;padding:7px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="e.g. UGT #8"
                           autocomplete="off">
                </div>
            </div>

            <!-- Row 2: Price + Capacity -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Selling Price Per Liter (₱) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newPrice" step="0.01" min="0.01" required
                           style="width:100%;padding:7px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="84.00">
                </div>
                <div>
                    <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Tank Capacity (Liters) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newCapacity" step="1" min="1" required
                           style="width:100%;padding:7px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="15000">
                </div>
            </div>

            <!-- Row 3: Critical Level + Reorder Level -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Critical Level (Liters) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newCriticalLevel" step="1" min="1" required
                           style="width:100%;padding:7px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="2500">
                </div>
                <div>
                    <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Reorder Level (Liters) <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newReorderLevel" step="1" min="1" required
                           style="width:100%;padding:7px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="5000">
                </div>
            </div>

            <!-- Row 3b: Current Volume (Liters) -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:10px;">
                <div>
                    <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Current Volume (Liters) <span style="color:#94a3b8;font-weight:400;text-transform:none;">(Optional)</span>
                    </label>
                    <input type="number" id="newCurrentVolume" step="0.01" min="0" value="0"
                           style="width:100%;padding:7px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="0.00">
                    <small style="font-size:11px;color:#64748b;display:block;margin-top:2px;">
                        <i class="fas fa-info-circle"></i> Initial fuel volume currently in the tank (0 = empty).
                    </small>
                </div>
            </div>

            <!-- Row 4: Number of Pumps + Status -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px;align-items:start;">
                <div>
                    <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                        Number of Pumps <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="newNumPumps" min="0" max="30" step="1" required value=""
                           style="width:100%;padding:7px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           placeholder="e.g. 4">
                    <small style="font-size:11px;color:#64748b;display:block;margin-top:2px;">
                        <i class="fas fa-info-circle"></i> Station-specific: total pumps/nozzles for this fuel type (0 = configure later).
                    </small>
                </div>
                <div>
                    <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:6px;">
                        Product Status <span style="color:#dc2626;">*</span>
                    </label>
                    <div style="display:flex;gap:18px;align-items:center;padding-top:2px;">
                        <label style="display:flex;align-items:center;gap:6px;font-size:14.5px;cursor:pointer;font-weight:600;color:#166534;">
                            <input type="radio" name="newStatus" value="active" checked style="accent-color:#16a34a;"> Active
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-size:14.5px;cursor:pointer;font-weight:600;color:#991b1b;">
                            <input type="radio" name="newStatus" value="inactive" style="accent-color:#dc2626;"> Inactive
                        </label>
                    </div>
                </div>
            </div>

            <!-- Row 5: Remarks -->
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:13.5px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:4px;">
                    Remarks <span style="color:#94a3b8;font-weight:400;text-transform:none;">(Optional)</span>
                </label>
                <input type="text" id="newRemarks"
                       style="width:100%;padding:7px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;"
                       onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                       placeholder="Optional notes or remarks...">
            </div>

            <!-- Actions Footer (Sticky at bottom of modal with top border) -->
            <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1.5px solid #e2e8f0;padding-top:12px;margin-top:auto;background:#ffffff;position:sticky;bottom:0;z-index:10;">
                <button type="button" onclick="closeAddProductModal()"
                        style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:8px 18px;border-radius:6px;font-size:14.5px;font-weight:700;cursor:pointer;">
                    Cancel
                </button>
                <button type="submit"
                        style="background:#00264D !important;color:#ffffff !important;border:none !important;padding:8px 22px;border-radius:6px;font-size:14.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                    <i class="fas fa-check" style="color:#ffffff !important;"></i> Add Fuel Product
                </button>
            </div>
        </form>
    </div>
</div>


<!-- Edit Fuel Modal — Full Edit (Landscape Grid Layout) -->
<div id="editPriceModal" style="display:none;position:fixed;top:0;left:250px;right:0;bottom:0;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:12px 16px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:12px;width:94%;max-width:690px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:auto;overflow:hidden;">
    <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:11px 20px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:15.5px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:8px;letter-spacing:0.3px;">
        <i class="fas fa-edit" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:16px;"></i>
        <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">EDIT FUEL PRODUCT</span>
      </h3>
    </div>
    <form id="editPriceForm" style="padding:12px 20px 12px 20px;">
      <input type="hidden" id="editFuelId">
      <input type="hidden" id="editFuelType">
      <input type="hidden" id="editFuelCritical" value="0">

      <!-- Row 1: UGT Number + Fuel Name -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:7px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">UGT Number</label>
          <input type="text" id="editUgtNo" style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;color:#002F70;font-weight:800;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Fuel Name</label>
          <input type="text" id="editFuelName" style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;color:#0f172a;font-weight:700;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        </div>
      </div>

      <!-- Row 2: Price Per Liter + Tank Capacity -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:7px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Price / Liter (₱) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="editPrice" step="0.01" min="0" required style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00">
          <small style="font-size:11px;color:#d97706;display:block;margin-top:2px;font-weight:500;"><i class="fas fa-info-circle"></i> Price changes require Admin approval.</small>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Tank Capacity (L) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="editFuelCapacity" step="1" min="0" required style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00">
        </div>
      </div>

      <!-- Row 3: Reorder Level + Status -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:7px;align-items:start;">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Reorder Level (L) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="editFuelReorder" step="1" min="0" required style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="0.00">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Status <span style="color:#dc2626;">*</span></label>
          <div style="display:flex;gap:16px;align-items:center;padding-top:2px;">
            <label style="display:flex;align-items:center;gap:5px;font-size:13.5px;cursor:pointer;font-weight:600;color:#166534;">
              <input type="radio" id="editFuelStatusActive" name="editFuelStatus" value="active" checked style="accent-color:#16a34a;"> Active
            </label>
            <label style="display:flex;align-items:center;gap:5px;font-size:13.5px;cursor:pointer;font-weight:600;color:#991b1b;">
              <input type="radio" id="editFuelStatusInactive" name="editFuelStatus" value="inactive" style="accent-color:#dc2626;"> Inactive
            </label>
          </div>
        </div>
      </div>

      <!-- Row 4: Remarks -->
      <div style="margin-bottom:7px;">
        <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Remarks <span style="color:#94a3b8;font-weight:400;text-transform:none;">(Optional)</span></label>
        <input type="text" id="editFuelRemarks" style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="Optional notes or remarks...">
      </div>

      <!-- Row 5: Dynamic Pump Configuration Card for this Fuel Product (matches Meter Reading) -->
      <div style="margin-bottom:9px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;">
          <span style="font-size:11.5px;font-weight:800;color:#002F6C;text-transform:uppercase;letter-spacing:0.3px;display:flex;align-items:center;gap:5px;">
            <i class="fas fa-gas-pump" style="color:#002F6C;"></i> PUMP &amp; NOZZLE CONFIGURATION
          </span>
          <span id="mef_pump_count_badge" style="background:#e0f2fe;color:#0369a1;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;">
            0 Nozzles / Pumps
          </span>
        </div>
        <div id="mef_pumps_container" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:6px;max-height:120px;overflow-y:auto;padding-right:2px;">
          <div style="color:#64748b;font-size:12px;font-style:italic;padding:3px 0;"><i class="fas fa-spinner fa-spin"></i> Loading pump configuration...</div>
        </div>
      </div>

      <!-- Actions -->
      <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:10px;">
        <button type="button" onclick="closeEditPriceModal()" style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:6px 16px;border-radius:6px;font-size:13.5px;font-weight:700;cursor:pointer;transition:all 0.2s;"><i class="fas fa-times-circle"></i> Cancel</button>
        <button type="submit" style="background:#002F6C !important;color:#ffffff !important;border:none !important;padding:6px 20px;border-radius:6px;font-size:13.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;"><i class="fas fa-save" style="color:#ffffff !important;"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- View Fuel Details Modal (Matches Add Fuel Product Modal Centering & Dimensions Exactly) -->
<div id="viewFuelModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;overflow:hidden;">
    <div style="background:#fff;border-radius:12px;width:94%;max-width:1000px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:0 auto;overflow:hidden;max-height:calc(100% - 20px);display:flex;flex-direction:column;">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:14px 22px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <h3 style="margin:0;font-size:16.5px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;letter-spacing:0.3px;">
                <i class="fas fa-gas-pump" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:18px;"></i>
                <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">FUEL PRODUCT SPECIFICATION &amp; HISTORY</span>
            </h3>
            <button type="button" onclick="closeViewFuelModal()" title="Close" style="background:rgba(255,255,255,0.18) !important;border:none !important;color:#ffffff !important;width:32px;height:32px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:15px;transition:background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.35)'" onmouseout="this.style.background='rgba(255,255,255,0.18)'">
                <i class="fas fa-times" style="color:#ffffff !important;"></i>
            </button>
        </div>

        <!-- Body Content -->
        <div style="padding:12px 24px 24px 24px;overflow-y:auto;overflow-x:hidden;flex:1 1 auto;background:#ffffff;min-height:0;box-sizing:border-box;">
            <!-- Fuel Specification & Overview -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:20px;margin-top:4px;width:100%;box-sizing:border-box;">
                <h4 style="margin:0 0 14px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
                    <i class="fas fa-info-circle" style="color:#002F6C;"></i> Fuel Specification &amp; Overview
                </h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(190px, 1fr));gap:14px;font-size:15.5px;width:100%;">
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Fuel Name</strong>
                        <span style="font-weight:700;color:#002F6C;font-size:14px;" id="viewFuelType">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">UGT Number</strong>
                        <span style="font-weight:700;color:#1e293b;" id="viewUgtNo">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Current Price / Liter</strong>
                        <span style="font-weight:700;color:#16a34a;font-size:14px;" id="viewCurrentPrice">₱0.00</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Current Volume</strong>
                        <span style="font-weight:700;color:#002F6C;" id="viewStock">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Available Capacity</strong>
                        <span style="font-weight:700;color:#2563eb;" id="viewAvailableCapacity">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Tank Capacity</strong>
                        <span style="font-weight:600;color:#334155;" id="viewCapacity">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Critical Level</strong>
                        <span style="font-weight:600;color:#dc2626;" id="viewCriticalLevel">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Reorder Level</strong>
                        <span style="font-weight:600;color:#d97706;" id="viewReorderLevel">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Stock Status</strong>
                        <span style="font-weight:600;" id="viewStatus">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Product Status</strong>
                        <span style="font-weight:600;" id="viewProductStatus">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Price Request Status</strong>
                        <span style="font-weight:600;color:#475569;" id="viewPriceRequestStatus">No Pending Request</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Last Updated</strong>
                        <span style="font-size:14.5px;color:#475569;" id="viewLastUpdated">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Updated By</strong>
                        <span style="font-size:14.5px;color:#475569;" id="viewUpdatedBy">-</span>
                    </div>
                </div>
            </div>

            <!-- Assigned Fuel Pumps / Nozzles (Synced with Fuel Management) -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px;margin-bottom:20px;width:100%;box-sizing:border-box;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
                    <h4 style="margin:0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-gas-pump" style="color:#002F6C;"></i> Assigned Pumps &amp; Nozzles (Fuel Management)
                    </h4>
                    <span id="m_view_pump_count_badge" style="background:#e0f2fe;color:#0369a1;padding:2px 10px;border-radius:10px;font-size:11.5px;font-weight:700;">
                        0 Pumps Assigned
                    </span>
                </div>
                <div id="m_view_pumps_container" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:8px;">
                    <div style="grid-column:1/-1;color:#64748b;font-size:13px;font-style:italic;padding:6px 0;"><i class="fas fa-spinner fa-spin"></i> Loading assigned pumps...</div>
                </div>
            </div>

            <!-- Price Change History -->
            <div style="margin-bottom:20px;width:100%;box-sizing:border-box;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-history" style="color:#002F6C;"></i> Price Change History
                </h4>
                <div class="modal-table-wrap" style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;width:100%;box-sizing:border-box;">
                    <table class="no-min-width" style="width:100% !important;min-width:0 !important;border-collapse:collapse;font-size:13.5px;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:13px;letter-spacing:0.3px;">
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Effective Date</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Price</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Requested By</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Approved By</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Status</th>
                                <th style="padding:8px 10px;text-align:center;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="priceHistoryBody">
                            <tr><td colspan="6" style="text-align:center;padding:16px;color:#94a3b8;">No price history available</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Configuration Change History -->
            <div style="margin-bottom:20px;width:100%;box-sizing:border-box;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-sliders-h" style="color:#002F6C;"></i> Configuration Change History
                </h4>
                <div class="modal-table-wrap" style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;width:100%;box-sizing:border-box;">
                    <table class="no-min-width" style="width:100% !important;min-width:0 !important;border-collapse:collapse;font-size:13.5px;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:13px;letter-spacing:0.3px;">
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Date</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Field Changed</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Old Value</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">New Value</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="configHistoryBody">
                            <tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No configuration changes recorded yet</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Status Change History -->
            <div style="margin-bottom:10px;width:100%;box-sizing:border-box;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-toggle-on" style="color:#002F6C;"></i> Status Change History
                </h4>
                <div class="modal-table-wrap" style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;width:100%;box-sizing:border-box;">
                    <table class="no-min-width" style="width:100% !important;min-width:0 !important;border-collapse:collapse;font-size:13.5px;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:13px;letter-spacing:0.3px;">
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Date</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Old Status</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">New Status</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Reason</th>
                                <th style="padding:8px 10px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="statusHistoryBody">
                            <tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No status history recorded yet</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div style="display:flex;justify-content:flex-end;padding:14px 24px;border-top:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0;gap:10px;">
            <button type="button" onclick="editFuelFromView()" style="background:#002F6C !important;color:#ffffff !important;border:none !important;padding:8px 20px;border-radius:6px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-edit"></i> Edit Product
            </button>
            <button type="button" onclick="closeViewFuelModal()" style="background:transparent !important;color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:8px 20px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Professional Confirmation Modal -->
<div id="confirmationModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,.6);z-index:10000;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:12px;width:90%;max-width:480px;max-height:calc(100% - 20px);overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.35);animation:modalSlideIn .18s ease;">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#dc2626,#991b1b);padding:18px 24px;display:flex;align-items:center;gap:12px;">
            <div style="width:42px;height:42px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-exclamation-triangle" style="color:#fff;font-size:20px;"></i>
            </div>
            <div>
                <h3 style="margin:0;font-size:16px;font-weight:700;color:#fff;" id="confirmModalTitle">Confirm Action</h3>
                <p style="margin:2px 0 0 0;font-size:14.5px;color:rgba(255,255,255,0.9);" id="confirmModalSubtitle">Please confirm your action</p>
            </div>
        </div>
        
        <!-- Body -->
        <div style="padding:24px;">
            <p style="margin:0;font-size:14px;color:#475569;line-height:1.6;" id="confirmModalMessage">Are you sure you want to proceed?</p>
        </div>
        
        <!-- Footer -->
        <div style="display:flex;justify-content:flex-end;gap:10px;padding:16px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;">
            <button type="button" onclick="closeConfirmModal()" style="background:#f1f5f9 !important;color:#0f172a !important;-webkit-text-fill-color:#0f172a !important;border:1px solid #cbd5e1;padding:9px 20px;border-radius:6px;font-size:15.5px;font-weight:700 !important;cursor:pointer;transition:all .2s;">
                <i class="fas fa-times" style="color:#0f172a !important;-webkit-text-fill-color:#0f172a !important;"></i> Cancel
            </button>
            <button type="button" id="confirmModalBtn" onclick="confirmModalAction()" style="background:#dc2626 !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 24px;border-radius:6px;font-size:15.5px;font-weight:700 !important;cursor:pointer;transition:all .2s;box-shadow:0 2px 4px rgba(220,38,38,0.3);">
                <i class="fas fa-check" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm
            </button>
        </div>
    </div>
</div>

<!-- Rollback Price Modal -->
<div id="rollbackPriceModal" class="modal">
    <div class="modal-card">
        <div class="modal-head">
            <div style="display:flex;align-items:center;">
                <div class="modal-icon"><i class="fas fa-undo"></i></div>
                <div>
                    <div class="modal-title">Rollback Price</div>
                    <div class="modal-subtitle">Revert to previous price</div>
                </div>
            </div>
        </div>
        <form id="rollbackPriceForm">
            <input type="hidden" id="rollbackFuelId">
            <input type="hidden" id="rollbackHistoryId">
            
            <div class="modal-body">
                <div style="margin-bottom:15px;">
                    <label>FUEL</label>
                    <input type="text" id="rollbackFuelName" readonly class="input" style="background:#f8fafc;color:#64748b;">
                </div>
                
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                    <div>
                        <label>CURRENT PRICE</label>
                        <input type="text" id="rollbackCurrentPrice" readonly class="input" style="background:#fef2f2;color:#dc2626;font-weight:700;">
                    </div>
                    <div>
                        <label>ROLLBACK TO</label>
                        <input type="text" id="rollbackToPrice" readonly class="input" style="background:#f0fdf4;color:#16a34a;font-weight:700;">
                    </div>
                </div>
                
                <div>
                    <label>REASON <span style="color:#dc2626;">*</span></label>
                    <textarea id="rollbackReason" required rows="3" class="input" placeholder="Explain why you are rolling back this price..."></textarea>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="flt-btn flt-btn-reset" onclick="closeRollbackModal()"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="flt-btn flt-btn-danger"><i class="fas fa-undo"></i> Confirm Rollback</button>
            </div>
        </form>
    </div>
</div>

<!-- Restore Price Modal (Confirmation & Audit Dialog) -->
<div id="restorePriceModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,.65);z-index:10000;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:12px;width:92%;max-width:540px;max-height:calc(100% - 20px);box-shadow:0 16px 48px rgba(0,0,0,.35);margin:auto;overflow:hidden;">
    <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-undo" style="color:#ffffff !important;"></i>
        <span>RESTORE HISTORICAL PRICE</span>
      </h3>
    </div>
    <form id="restorePriceForm" style="padding:20px 24px;">
      <input type="hidden" id="restoreFuelId">
      <input type="hidden" id="restoreTargetPrice">

      <!-- Confirmation Notice -->
      <div style="background:#f0f7ff;border:1px solid #bae6fd;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
        <p style="margin:0 0 8px 0;font-size:15.5px;font-weight:700;color:#0369a1;">
          Restore this previous price as the current selling price?
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:14.5px;margin-top:10px;background:#ffffff;padding:10px 12px;border-radius:6px;border:1px solid #e0f2fe;">
          <div>
            <span style="color:#64748b;display:block;font-size:14px;">Fuel Product:</span>
            <strong id="restoreFuelNameDisplay" style="color:#002F6C;font-size:15.5px;">-</strong>
          </div>
          <div>
            <span style="color:#64748b;display:block;font-size:14px;">Historical Date:</span>
            <strong id="restoreDateDisplay" style="color:#334155;font-size:14.5px;">-</strong>
          </div>
          <div>
            <span style="color:#64748b;display:block;font-size:14px;">Current Price:</span>
            <strong id="restoreCurrentPriceDisplay" style="color:#dc2626;font-size:14px;">₱0.00</strong>
          </div>
          <div>
            <span style="color:#64748b;display:block;font-size:14px;">Previous Price (To Restore):</span>
            <strong id="restoreTargetPriceDisplay" style="color:#16a34a;font-size:14px;">₱0.00</strong>
          </div>
        </div>
      </div>

      <!-- Optional Reason -->
      <div style="margin-bottom:18px;">
        <label style="display:block;font-size:14.5px;font-weight:700;color:#334155;margin-bottom:6px;">
          Reason for Restoration <span style="color:#94a3b8;font-weight:400;">(Optional note for Admin)</span>
        </label>
        <textarea id="restoreReason" rows="2" style="width:100%;padding:8px 12px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;box-sizing:border-box;" placeholder="e.g. Restoring previous standard pricing following promotional period..."></textarea>
      </div>

      <!-- Actions -->
      <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:14px;">
        <button type="button" onclick="closeRestorePriceModal()" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:8px 18px;border-radius:6px;font-size:15.5px;font-weight:700;cursor:pointer;">
          Cancel
        </button>
        <button type="submit" style="background:#002F6C;color:#ffffff;border:none;padding:8px 20px;border-radius:6px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
          <i class="fas fa-paper-plane"></i> Submit Restoration Request
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Add Merchandise Modal -->
<div id="addMerchandiseModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:650px;max-height:calc(100% - 20px);overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
    <div style="background:linear-gradient(135deg,#002F6C,#004494);border-radius:12px 12px 0 0;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-plus-circle"></i> ADD NEW MERCHANDISE PRODUCT
      </h3>
    </div>
    <form id="addMerchandiseForm" style="padding:22px;">
      <!-- Row 1: Product Name + SKU -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Product Name <span style="color:#dc2626;">*</span></label>
          <input type="text" id="newMerchName" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Coke 1.5L" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\/\,\.\&]/g, '');">
        </div>
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">SKU / Product Code</label>
          <input type="text" id="newMerchSku" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;box-sizing:border-box;font-family:monospace;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. ITEM-001 (auto if blank)" oninput="this.value = this.value.toUpperCase().replace(/[^a-zA-Z0-9\-\_]/g, '');">
        </div>
      </div>
      <!-- Row 2: Category + Brand -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Category <span style="color:#dc2626;">*</span></label>
          <input type="text" id="newMerchCategory" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Drinks/Food" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\/]/g, '');">
        </div>
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Brand</label>
          <input type="text" id="newMerchBrand" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Coca-Cola, Petron" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\&]/g, '');">
        </div>
      </div>
      <!-- Row 3: UOM + Default Selling Price -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Unit of Measure (UOM)</label>
          <input type="text" id="newMerchSize" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Bottle, Box, pcs, 500ml" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\/]/g, '');">
        </div>
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#002F6C;text-transform:uppercase;margin-bottom:4px;">Default Selling Price (&#8369;) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="newMerchPrice" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:2px solid #002F6C;border-radius:7px;font-size:14px;font-weight:600;box-sizing:border-box;" onfocus="this.style.borderColor='#004494'" onblur="this.style.borderColor='#002F6C'" placeholder="0.00">
          <small style="color:#64748b;font-size:14px;">Cost price will be set per delivery batch (Record Delivery)</small>
        </div>
      </div>
      <!-- Row 5: Reorder Level + Critical Level -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:4px;">Reorder Level</label>
          <input type="number" id="newMerchReorder" min="0" value="24" style="width:100%;padding:9px 11px;border:1.5px solid #fde68a;border-radius:7px;font-size:15.5px;background:#fffbeb;box-sizing:border-box;" onfocus="this.style.borderColor='#f59e0b'" onblur="this.style.borderColor='#fde68a'" placeholder="24">
        </div>
        <input type="hidden" id="newMerchCritical" value="0">
      </div>
      <!-- Row 6: Expiration Date (Optional) -->
      <div style="margin-bottom:18px;">
        <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Expiration Date <span style="color:#94a3b8;font-weight:400;text-transform:none;">(Optional)</span></label>
        <input type="date" id="newMerchExpiry" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        <small style="color:#64748b;font-size:13px;">Leave blank if product has no expiration (e.g. tools, accessories)</small>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:16px;">
        <button type="button" onclick="closeAddMerchandiseModal()" style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:9px 18px;border-radius:6px;font-size:15.5px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#00264D !important;color:#ffffff !important;border:none !important;padding:9px 22px;border-radius:6px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-check" style="color:#ffffff !important;"></i> Add Product</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Merchandise Modal — Full Edit -->
<div id="editMerchPriceModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:650px;max-height:calc(100% - 20px);overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
    <div style="background:#002F6C;border-radius:12px 12px 0 0;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-edit"></i> EDIT MERCHANDISE PRODUCT
      </h3>

    </div>
    <form id="editMerchPriceForm" style="padding:22px;">
      <input type="hidden" id="editMerchId">
      <!-- Row 1: Product Name + SKU -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Product Name <span style="color:#dc2626;">*</span></label>
          <input type="text" id="editMerchName" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\/\,\.\&]/g, '');">
        </div>
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">SKU / Product Code <span style="color:#94a3b8;font-weight:400;text-transform:none;">(read-only)</span></label>
          <input type="text" id="editMerchSku" readonly style="width:100%;padding:9px 11px;border:1.5px solid #cbd5e1;border-radius:7px;font-size:15.5px;background:#f8fafc;color:#4f46e5;font-weight:700;box-sizing:border-box;font-family:monospace;">
        </div>
      </div>
      <!-- Row 2: Category + Brand -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Category <span style="color:#dc2626;">*</span></label>
          <input type="text" id="editMerchCategory" required style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\/]/g, '');">
        </div>
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Brand</label>
          <input type="text" id="editMerchBrand" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Coca-Cola, Petron" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\&]/g, '');">
        </div>
      </div>
      <!-- Row 3: UOM + Default Selling Price -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Unit of Measure (UOM)</label>
          <input type="text" id="editMerchSize" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'" placeholder="e.g. Bottle, Box, pcs" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\/]/g, '');">
        </div>
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#002F6C;text-transform:uppercase;margin-bottom:4px;">Default Selling Price (&#8369;) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="editMerchPrice" step="0.01" min="0" required style="width:100%;padding:9px 11px;border:2px solid #002F6C;border-radius:7px;font-size:14px;font-weight:600;box-sizing:border-box;" onfocus="this.style.borderColor='#004494'" onblur="this.style.borderColor='#002F6C'" placeholder="0.00" oninput="sanitizeDecimalInput(this)">
          <small style="color:#64748b;font-size:14px;">Cost price is managed per delivery batch</small>
        </div>
      </div>
      <!-- Row 4: Reorder Level + Status -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px;">
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:4px;">Reorder Level</label>
          <input type="number" id="editMerchReorder" min="0" style="width:100%;padding:9px 11px;border:1.5px solid #fde68a;border-radius:7px;font-size:15.5px;background:#fffbeb;box-sizing:border-box;" onfocus="this.style.borderColor='#f59e0b'" onblur="this.style.borderColor='#fde68a'" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
        </div>
        <div>
          <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Status</label>
          <select id="editMerchStatus" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15.5px;">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <input type="hidden" id="editMerchCritical" value="0">
      </div>
      <!-- Row: Expiration Date (Optional) -->
      <div style="margin-bottom:18px;">
        <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Expiration Date <span style="color:#94a3b8;font-weight:400;text-transform:none;">(Optional)</span></label>
        <input type="date" id="editMerchExpiry" style="width:100%;padding:9px 11px;border:1.5px solid #d1d5db;border-radius:7px;font-size:15px;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        <small style="color:#64748b;font-size:13px;">Leave blank for non-perishable products (tools, accessories, etc.)</small>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:16px;">
        <button type="button" onclick="closeEditMerchPriceModal()" style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:9px 18px;border-radius:6px;font-size:15.5px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#00264D !important;color:#ffffff !important;border:none !important;padding:9px 22px;border-radius:6px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-save" style="color:#ffffff !important;"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- View Merchandise Specification & History Modal -->
<div id="viewMerchModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;overflow-y:auto;">
    <div style="background:#fff;border-radius:12px;width:94%;max-width:1000px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:0 auto;overflow:hidden;max-height:calc(100% - 20px);display:flex;flex-direction:column;">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;gap:14px;flex-shrink:0;">
            <i class="fas fa-box" style="color:#ffffff !important;font-size:22px;flex-shrink:0;"></i>
            <h3 id="vm_title" style="margin:0;font-size:17px;font-weight:800;color:#ffffff !important;letter-spacing:0.3px;">
                MERCHANDISE SPECIFICATION &amp; HISTORY
            </h3>
        </div>

        <!-- Body Content -->
        <div style="padding:20px 24px;overflow-y:auto;overflow-x:hidden;flex:1 1 auto;background:#ffffff;min-height:0;box-sizing:border-box;">
            
            <!-- 1. Product Specification & Overview -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:20px;">
                <h4 style="margin:0 0 14px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
                    <i class="fas fa-info-circle"></i> Product Specification &amp; Overview
                </h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;font-size:14.5px;">
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">SKU / Code:</span><br><code id="vm_sku" style="font-weight:800;color:#4f46e5;">-</code></div>
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Product Name:</span><br><strong id="vm_name" style="color:#0f172a;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Category:</span><br><strong id="vm_category" style="color:#0f172a;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Brand:</span><br><strong id="vm_brand" style="color:#0f172a;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Unit (UOM):</span><br><strong id="vm_unit" style="color:#0f172a;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Current Selling Price:</span><br><strong id="vm_price" style="color:#002F6C;font-size:15px;font-weight:800;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Current Cost Price:</span><br><strong id="vm_cost" style="color:#16a34a;font-size:14px;font-weight:800;">-</strong> <small style="color:#94a3b8;font-size:12px;">(latest approved Stock-In)</small></div>
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Total Stock:</span><br><strong id="vm_stock" style="font-size:14px;color:#0f172a;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Batch Count:</span><br><strong id="vm_batch_count" style="color:#0f172a;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Reorder Level:</span><br><strong id="vm_reorder" style="color:#d97706;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Status:</span><br><span id="vm_status">-</span></div>
                </div>
            </div>

            <!-- 2. Read-Only Batch Summary -->
            <div style="margin-bottom:24px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-layer-group" style="color:#0284c7;"></i> Batch Summary <small style="color:#64748b;font-weight:400;">(Read Only)</small>
                </h4>
                <div class="modal-table-wrap" style="border:1px solid #e2e8f0;border-radius:8px;overflow-x:auto;width:100%;box-sizing:border-box;">
                    <table class="no-min-width" style="width:100% !important;min-width:0 !important;border-collapse:collapse;font-size:13.5px;text-align:left;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;font-weight:700;">
                                <th style="padding:8px 10px;white-space:nowrap;">Batch No.</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Remaining Qty</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Expiration</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="vm_batches_body">
                            <tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No batches found</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. Price History -->
            <div style="margin-bottom:24px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-history" style="color:#4f46e5;"></i> Price History
                </h4>
                <div class="modal-table-wrap" style="border:1px solid #e2e8f0;border-radius:8px;overflow-x:auto;width:100%;box-sizing:border-box;">
                    <table class="no-min-width" style="width:100% !important;min-width:0 !important;border-collapse:collapse;font-size:13.5px;text-align:left;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;font-weight:700;">
                                <th style="padding:8px 10px;white-space:nowrap;">Date</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Old Price</th>
                                <th style="padding:8px 10px;white-space:nowrap;">New Price</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Requested By</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Approved By</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Status</th>
                                <th style="padding:8px 10px;text-align:center;white-space:nowrap;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="vm_price_history_body">
                            <tr><td colspan="7" style="text-align:center;padding:12px;color:#94a3b8;">No price history</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 4. Configuration History -->
            <div style="margin-bottom:24px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-sliders-h" style="color:#d97706;"></i> Configuration History
                </h4>
                <div class="modal-table-wrap" style="border:1px solid #e2e8f0;border-radius:8px;overflow-x:auto;width:100%;box-sizing:border-box;">
                    <table class="no-min-width" style="width:100% !important;min-width:0 !important;border-collapse:collapse;font-size:13.5px;text-align:left;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;font-weight:700;">
                                <th style="padding:8px 10px;white-space:nowrap;">Date</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Field</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Old Value</th>
                                <th style="padding:8px 10px;white-space:nowrap;">New Value</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="vm_config_history_body">
                            <tr><td colspan="5" style="text-align:center;padding:12px;color:#94a3b8;">No configuration changes recorded</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 5. Status History -->
            <div style="margin-bottom:12px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-power-off" style="color:#dc2626;"></i> Status History
                </h4>
                <div class="modal-table-wrap" style="border:1px solid #e2e8f0;border-radius:8px;overflow-x:auto;width:100%;box-sizing:border-box;">
                    <table class="no-min-width" style="width:100% !important;min-width:0 !important;border-collapse:collapse;font-size:13.5px;text-align:left;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;font-weight:700;">
                                <th style="padding:8px 10px;white-space:nowrap;">Date</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Old Status</th>
                                <th style="padding:8px 10px;white-space:nowrap;">New Status</th>
                                <th style="padding:8px 10px;white-space:nowrap;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="vm_status_history_body">
                            <tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No status changes recorded</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 24px;display:flex;justify-content:flex-end;flex-shrink:0;gap:10px;">
            <button type="button" onclick="editMerchFromView()" style="background:#002F6C !important;color:#fff !important;border:none;padding:8px 20px;border-radius:6px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-edit"></i> Edit Product
            </button>
            <button onclick="closeViewMerchModal()" style="background:transparent !important;color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:8px 20px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<!-- View Batches Modal -->
<div id="viewBatchesModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:12px;width:95%;max-width:900px;max-height:calc(100% - 20px);overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);">
    <div style="background:linear-gradient(135deg,#0369a1,#0284c7);border-radius:12px 12px 0 0;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-layer-group"></i> <span id="viewBatchesTitle">Product Batches</span>
      </h3>
    </div>
    <div id="viewBatchesContent" style="padding:22px;">
      <div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading batches...</div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     ADD SERVICE MODAL
     ══════════════════════════════════════════════════════════ -->
<div id="addServiceModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,0.65);z-index:9999;align-items:center;justify-content:center;padding:20px 24px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:720px;max-height:calc(100% - 20px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.35);margin:auto;animation:slideDown 0.25s ease-out;">
    <!-- Header -->
    <div style="flex-shrink:0;background:linear-gradient(135deg,#002F6C,#0052A5);border-radius:14px 14px 0 0;padding:16px 22px;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-plus-circle"></i> Add New Service
      </h3>
    </div>
    <!-- Form Body -->
    <form id="addServiceForm" style="flex:1 1 auto;overflow-y:auto;padding:22px;display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div>
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Service Name <span style="color:#dc2626;">*</span></label>
            <input type="text" id="addSvcName" required placeholder="e.g. Change Oil - Mineral"
              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;font-weight:500;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
              oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\/\,\.\&]/g, '');">
          </div>
          <div>
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Category <span style="color:#dc2626;">*</span></label>
            <div style="position:relative;" id="addSvcCatContainer">
              <input type="text" id="addSvcCategory" required autocomplete="off"
                placeholder="Select or type category..."
                style="width:100%;padding:9px 36px 9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;font-weight:500;box-sizing:border-box;background:#fff;"
                onfocus="showSvcCatDrop('add')"
                oninput="filterSvcCatDrop('add', this.value)"
                onkeydown="handleSvcCatKey(event, 'add')">
              <span id="addSvcCatChevronBtn" onclick="toggleSvcCatDrop('add')"
                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:transparent !important;border:none !important;cursor:pointer;padding:4px;display:flex;align-items:center;justify-content:center;user-select:none;line-height:1;">
                <i class="fas fa-chevron-down" id="addSvcCatChevron" style="font-size:12px;color:#64748b !important;transition:transform 0.2s ease;"></i>
              </span>
              <div id="addSvcCatDrop"
                style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#ffffff;border:1.5px solid #002F6C;border-radius:8px;box-shadow:0 10px 25px -5px rgba(0,47,108,0.2), 0 8px 10px -6px rgba(0,47,108,0.1);z-index:99999;max-height:220px;overflow-y:auto;">
              </div>
            </div>
          </div>
          <div>
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Service Fee (₱) <span style="color:#dc2626;">*</span></label>
            <input type="number" id="addSvcServiceFee" step="0.01" min="0" required placeholder="0.00"
              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
              oninput="sanitizeDecimalInput(this)">
            <small style="color:#94a3b8;font-size:12.5px;">Parts/materials fee</small>
          </div>
          <div>
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Labor Fee (₱) <span style="color:#dc2626;">*</span></label>
            <input type="number" id="addSvcLaborFee" step="0.01" min="0" required placeholder="0.00"
              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
            <small style="color:#94a3b8;font-size:12.5px;">Mechanic labor fee</small>
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Required Mechanics <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
            <input type="number" id="addSvcMechanics" min="1" max="10"
              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Description <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
            <textarea id="addSvcDescription" rows="2" placeholder="Brief description of the service..."
              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;resize:vertical;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"></textarea>
          </div>
        </div>
      </div>
      <!-- Footer Actions -->
      <div style="display:flex;gap:10px;justify-content:flex-end;padding-top:14px;border-top:1px solid #e2e8f0;margin-top:auto;">
        <button type="button" onclick="closeAddServiceModal()" style="background:#f1f5f9 !important;color:#0f172a !important;border:1px solid #cbd5e1 !important;padding:9px 22px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#002F6C;color:#fff;border:none;padding:9px 26px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
          <i class="fas fa-check-circle"></i> Add Service
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     EDIT SERVICE MODAL
     ══════════════════════════════════════════════════════════ -->
<div id="editServiceModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,0.65);z-index:9999;align-items:center;justify-content:center;padding:20px 24px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:720px;max-height:calc(100% - 20px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.35);margin:auto;animation:slideDown 0.25s ease-out;">
    <!-- Header -->
    <div style="flex-shrink:0;background:linear-gradient(135deg,#002F6C,#0052A5);border-radius:14px 14px 0 0;padding:16px 22px;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-edit"></i> Edit Service
      </h3>
      <div id="editSvcCodeDisplay" style="font-size:14px;color:rgba(255,255,255,.7);margin-top:3px;font-family:monospace;"></div>
    </div>
    <!-- Approval Notice -->
    <div id="editSvcApprovalNotice" style="display:none;flex-shrink:0;background:#fef3c7;border-bottom:1px solid #fde68a;padding:10px 22px;font-size:14px;color:#92400e;display:flex;align-items:center;gap:8px;">
      <i class="fas fa-exclamation-triangle"></i>
      <span><strong>Fee changes require Admin approval.</strong> Non-fee fields (name, category, mechanics, etc.) will save immediately.</span>
    </div>
    <!-- Form Body -->
    <form id="editServiceForm" style="flex:1 1 auto;overflow-y:auto;padding:22px;display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <input type="hidden" id="editSvcId">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div>
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Service Name <span style="color:#dc2626;">*</span></label>
            <input type="text" id="editSvcName" required
              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;font-weight:500;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
              oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\/\,\.\&]/g, '');">
          </div>
          <div>
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Category <span style="color:#dc2626;">*</span></label>
            <div style="position:relative;" id="editSvcCatContainer">
              <input type="text" id="editSvcCategory" required autocomplete="off"
                placeholder="Select or type category..."
                style="width:100%;padding:9px 36px 9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;font-weight:500;box-sizing:border-box;background:#fff;"
                onfocus="showSvcCatDrop('edit')"
                oninput="filterSvcCatDrop('edit', this.value)"
                onkeydown="handleSvcCatKey(event, 'edit')">
              <span id="editSvcCatChevronBtn" onclick="toggleSvcCatDrop('edit')"
                style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:transparent !important;border:none !important;cursor:pointer;padding:4px;display:flex;align-items:center;justify-content:center;user-select:none;line-height:1;">
                <i class="fas fa-chevron-down" id="editSvcCatChevron" style="font-size:12px;color:#64748b !important;transition:transform 0.2s ease;"></i>
              </span>
              <div id="editSvcCatDrop"
                style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#ffffff;border:1.5px solid #002F6C;border-radius:8px;box-shadow:0 10px 25px -5px rgba(0,47,108,0.2), 0 8px 10px -6px rgba(0,47,108,0.1);z-index:99999;max-height:220px;overflow-y:auto;">
              </div>
            </div>
          </div>
          <div>
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Service Fee (₱) <span style="color:#dc2626;">*</span></label>
            <input type="number" id="editSvcServiceFee" step="0.01" min="0" required placeholder="0.00"
              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
              oninput="checkSvcFeeChange()">
            <small style="color:#94a3b8;font-size:12.5px;">Parts/materials fee</small>
          </div>
          <div>
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Labor Fee (₱) <span style="color:#dc2626;">*</span></label>
            <input type="number" id="editSvcLaborFee" step="0.01" min="0" required placeholder="0.00"
              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
              oninput="checkSvcFeeChange()">
            <small style="color:#94a3b8;font-size:12.5px;">Mechanic labor fee</small>
          </div>
          <div>
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Required Mechanics <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
            <input type="number" id="editSvcMechanics" min="1" max="10"
              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
              oninput="this.value = this.value.replace(/[^0-9]/g, '');">
          </div>
          <div>
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Status</label>
            <select id="editSvcActive" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;box-sizing:border-box;">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:13.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Description</label>
            <textarea id="editSvcDescription" rows="2"
              style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;resize:vertical;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"></textarea>
          </div>
        </div>
      </div>
      <!-- Footer Actions -->
      <div style="display:flex;gap:10px;justify-content:flex-end;padding-top:14px;border-top:1px solid #e2e8f0;margin-top:auto;">
        <button type="button" onclick="closeEditServiceModal()" style="background:#f1f5f9 !important;color:#0f172a !important;border:1px solid #cbd5e1;padding:9px 22px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#002F6C;color:#fff;border:none;padding:9px 26px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     VIEW SERVICE MODAL (with Fee History)
     ══════════════════════════════════════════════════════════ -->
<div id="viewServiceModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,0.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;overflow-y:auto;">
  <div style="background:#fff;border-radius:12px;width:92%;max-width:880px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:0 auto;overflow:hidden;max-height:calc(100% - 20px);display:flex;flex-direction:column;">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:16px 24px;display:flex;align-items:center;gap:12px;flex-shrink:0;">
      <i class="fas fa-tools" style="color:#ffffff !important;font-size:20px;flex-shrink:0;"></i>
      <div>
        <h3 style="margin:0;font-size:17px;font-weight:800;color:#ffffff !important;letter-spacing:0.3px;">
          SERVICE SPECIFICATION &amp; HISTORY
        </h3>
        <div id="viewSvcCodeDisplay" style="font-size:13px;color:rgba(255,255,255,0.75);margin-top:3px;font-family:monospace;letter-spacing:0.5px;font-weight:500;"></div>
      </div>
    </div>

    <!-- Body Content -->
    <div style="padding:20px 24px;overflow-y:auto;overflow-x:hidden;flex:1 1 auto;background:#ffffff;min-height:0;box-sizing:border-box;">
      
      <!-- 1. Service Specification & Overview (Corporate Clean Grid — No Summary Cards) -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:20px;">
        <h4 style="margin:0 0 14px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
          <i class="fas fa-info-circle"></i> Service Specification &amp; Overview
        </h4>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;font-size:14.5px;">
          <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Service Name:</span><br><strong id="viewSvcName" style="color:#0f172a;font-size:15px;">-</strong></div>
          <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Category:</span><br><strong id="viewSvcCategory" style="color:#0f172a;">-</strong></div>
          <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Status:</span><br><span id="viewSvcStatus">-</span></div>
          <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Service Fee:</span><br><strong id="viewSvcServiceFee" style="color:#002F6C;font-size:15px;font-weight:800;">₱0.00</strong></div>
          <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Labor Fee:</span><br><strong id="viewSvcLaborFee" style="color:#002F6C;font-size:15px;font-weight:800;">₱0.00</strong></div>
          <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Total Fee:</span><br><strong id="viewSvcTotalFee" style="color:#16a34a;font-size:16px;font-weight:800;">₱0.00</strong></div>
          <div><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Required Mechanics:</span><br><strong id="viewSvcMechanics" style="color:#334155;">-</strong></div>
          <div style="grid-column:1/-1;"><span style="color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;">Description:</span><br><span id="viewSvcDesc" style="color:#475569;font-size:14px;line-height:1.5;">-</span></div>
        </div>
      </div>

      <!-- 2. Fee Change History -->
      <div style="margin-bottom:10px;">
        <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;">
          <i class="fas fa-history" style="color:#0284c7;"></i> Fee Change History
        </h4>
        <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;">
          <div id="viewSvcHistory" style="min-height:50px;">
            <div style="text-align:center;color:#94a3b8;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer: Close button with NO background color, visible dark text -->
    <div style="flex-shrink:0;padding:14px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;align-items:center;background:#ffffff;border-radius:0 0 12px 12px;">
      <button type="button" onclick="closeViewServiceModal()" style="background:transparent !important;background-color:transparent !important;color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 28px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;transition:all 0.15s ease;" onmouseover="this.style.background='#f1f5f9';this.style.borderColor='#94a3b8';" onmouseout="this.style.background='transparent';this.style.borderColor='#cbd5e1';">Close</button>
    </div>
  </div>
</div>



<style>
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Force ultra-bright white title text in all modal headers */
div[id$="Modal"] h3,
div[id$="Modal"] h3 *,
.modal h3,
.modal h3 * {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    opacity: 1 !important;
}

/* Ensure ultra-crisp high contrast text inside all modal inputs */
.modal input[type="text"],
.modal input[type="number"],
.modal input[type="date"],
.modal select:not([name^="edit_pump_status"]),
.modal textarea,
div[id$="Modal"] input[type="text"],
div[id$="Modal"] input[type="number"],
div[id$="Modal"] input[type="date"],
div[id$="Modal"] select:not([name^="edit_pump_status"]),
div[id$="Modal"] textarea {
    color: #0f172a !important;
    background-color: #ffffff !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    border: 1.5px solid #94a3b8 !important;
    border-radius: 8px !important;
    padding: 10px 14px !important;
    box-sizing: border-box !important;
    outline: none !important;
    transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
}

.modal input[type="text"]:focus,
.modal input[type="number"]:focus,
.modal input[type="date"]:focus,
.modal select:not([name^="edit_pump_status"]):focus,
.modal textarea:focus {
    border-color: #002F6C !important;
    box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.15) !important;
}

/* Pump & Nozzle Status Dropdown Badges — exactly matching Admin */
#mef_pumps_container select,
#aef_pumps_container select,
select[name^="edit_pump_status"] {
    font-size: 11.5px !important;
    font-weight: 700 !important;
    padding: 2px 8px !important;
    border-radius: 4px !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    box-sizing: border-box !important;
    height: 24px !important;
    line-height: 1.2 !important;
    text-align: center !important;
    outline: none !important;
    box-shadow: none !important;
}

.modal input::placeholder,
.modal textarea::placeholder {
    color: #64748b !important;
    font-weight: 400 !important;
    opacity: 0.85 !important;
}

.modal label {
    color: #1e293b !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
}

/* ══════════════════════════════════════════════════════════
   MODAL POSITIONING — MAIN LAYOUT CENTERING ONLY
   Do not cover or include sidebar navigation in modal centering.
   Modals are strictly centered in the content layout area.
   ══════════════════════════════════════════════════════════ */
div[id$="Modal"],
.modal,
.cust-modal {
    position: fixed !important;
    top: 70px !important;
    left: 250px !important;
    right: 0 !important;
    bottom: 40px !important;
    width: auto !important;
    height: auto !important;
    box-sizing: border-box !important;
    align-items: center !important;
    justify-content: center !important;
    transition: left 0.3s ease !important;
}

body.sidebar-collapsed div[id$="Modal"],
body.sidebar-collapsed .modal,
body.sidebar-collapsed .cust-modal,
.sidebar-collapsed div[id$="Modal"],
.sidebar-collapsed .modal,
.sidebar-collapsed .cust-modal {
    left: 70px !important;
}

@media (max-width: 991px) {
    div[id$="Modal"],
    .modal,
    .cust-modal {
        left: 0 !important;
        top: 60px !important;
    }
}

/* ══════════════════════════════════════════════════════════
   MODAL TABLES — ZERO CLIPPING, FULL VISIBILITY
   Exempt all tables inside modals from 1050px min-width rule
   ══════════════════════════════════════════════════════════ */
div[id$="Modal"] table,
div[id$="Modal"] table.no-min-width,
.modal table,
.modal table.no-min-width,
table.no-min-width {
    width: 100% !important;
    min-width: 0 !important;
    table-layout: auto !important;
}

div[id$="Modal"] th,
div[id$="Modal"] td,
.modal th,
.modal td {
    padding: 8px 10px !important;
    font-size: 13.5px !important;
}

div[id$="Modal"] th,
.modal th {
    font-size: 12.5px !important;
    font-weight: 700 !important;
    white-space: nowrap !important;
}

div[id$="Modal"] .modal-table-wrap {
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch !important;
    width: 100% !important;
    box-sizing: border-box !important;
}
</style>

<script>
// ── Right-Side Toast Banner Notification System (Replaces modal popup forms) ──
var mgrToastDismissTimer = null;
var mgrToastRemoveTimer = null;

function showCustomAlert(message, type, callback) {
    type = type || 'success';
    var isError = (type === 'error' || type === 'danger');

    // Dismiss any existing global toasts from header
    var globalStack = document.getElementById('globalToastStack');
    if (globalStack) globalStack.innerHTML = '';

    var container = document.getElementById('rightToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'rightToastContainer';
        container.style.cssText = 'position:fixed;top:85px;right:24px;z-index:2147483647;display:flex;flex-direction:column;gap:10px;max-width:400px;width:calc(100% - 48px);pointer-events:none;';
        document.body.appendChild(container);
    } else {
        container.style.top = '85px';
        container.style.zIndex = '2147483647';
    }

    // Strictly ONE banner at a time: clear existing toasts and pending timers
    if (mgrToastDismissTimer) clearTimeout(mgrToastDismissTimer);
    if (mgrToastRemoveTimer) clearTimeout(mgrToastRemoveTimer);
    container.innerHTML = '';

    var toast = document.createElement('div');
    toast.className = 'right-toast-banner ' + (isError ? 'error' : 'success');
    toast.style.cssText = `
        pointer-events: auto;
        background: #ffffff;
        border-radius: 10px;
        padding: 14px 18px;
        box-shadow: 0 16px 36px rgba(0, 47, 108, 0.22), 0 4px 12px rgba(0, 0, 0, 0.08);
        display: flex;
        align-items: flex-start;
        gap: 12px;
        border: 1px solid #e2e8f0;
        transform: translateX(120%);
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    `;

    var iconBg = isError ? '#fee2e2' : '#dcfce7';
    var iconColor = isError ? '#dc2626' : '#16a34a';
    var iconClass = isError ? 'fa-exclamation-triangle' : 'fa-check-circle';
    var titleText = isError ? 'Notice / Error' : 'Action Successful';

    toast.innerHTML = `
        <div style="width:34px;height:34px;border-radius:50%;background:${iconBg};color:${iconColor};display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;margin-top:1px;">
            <i class="fas ${iconClass}"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <h4 style="font-size:15.5px;font-weight:800;color:#0f172a;margin:0 0 3px 0;line-height:1.2;">${titleText}</h4>
            <p style="font-size:14.5px;font-weight:500;color:#475569;margin:0;line-height:1.35;">${message}</p>
        </div>
        ${isError ? '<button type="button" onclick="this.parentElement.remove();" style="background:none;border:none;color:#94a3b8;font-size:18px;line-height:1;cursor:pointer;padding:0 2px;margin-top:-2px;">&times;</button>' : ''}
    `;

    container.appendChild(toast);

    setTimeout(function() {
        toast.style.transform = 'translateX(0)';
        toast.style.opacity = '1';
    }, 20);

    var delay = (typeof callback === 'function') ? 2200 : 4000;
    mgrToastDismissTimer = setTimeout(function() {
        toast.style.transform = 'translateX(120%)';
        toast.style.opacity = '0';
        mgrToastRemoveTimer = setTimeout(function() {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
            if (typeof callback === 'function') {
                callback();
            }
        }, 300);
    }, delay);
}

function closeStatusNotificationModal() {
    var container = document.getElementById('rightToastContainer');
    if (container) container.innerHTML = '';
}

// ── Professional Confirmation Modal ─────────────────────────────────────────
var confirmModalCallback = null;
var confirmModalData = null;

function showConfirmModal(title, subtitle, message, callback, data) {
    confirmModalCallback = callback || null;
    confirmModalData = data || null;
    
    document.getElementById('confirmModalTitle').textContent = title || 'Confirm Action';
    document.getElementById('confirmModalSubtitle').textContent = subtitle || 'Please confirm your action';
    document.getElementById('confirmModalMessage').textContent = message || 'Are you sure you want to proceed?';
    document.getElementById('confirmationModal').style.display = 'flex';
}

function closeConfirmModal() {
    document.getElementById('confirmationModal').style.display = 'none';
    confirmModalCallback = null;
    confirmModalData = null;
}

function confirmModalAction() {
    var cb = confirmModalCallback;
    var data = confirmModalData;
    closeConfirmModal();
    if (typeof cb === 'function') {
        cb(data);
    }
}

// ── Modal functions ─────────────────────────────────────────────────────────
function updatePumpConfigPreview(val) {
    var count = parseInt(val);
    if (isNaN(count) || count < 0) count = 0;
    if (count > 30) count = 30;
    
    var badge = document.getElementById('pumpConfigCountBadge');
    if (badge) badge.textContent = count + (count === 1 ? ' Pump' : ' Pumps');
    
    var container = document.getElementById('pumpConfigContainer');
    if (!container) return;
    
    if (count === 0) {
        container.innerHTML = '<div style="grid-column:1/-1;padding:12px;text-align:center;color:#64748b;font-size:12.5px;font-style:italic;background:#fff;border-radius:6px;border:1px dashed #cbd5e1;"><i class="fas fa-info-circle"></i> 0 pumps will be created. Admin or Manager can configure pumps for this station later in Pump & Nozzle Configuration.</div>';
        return;
    }
    
    var html = '';
    for (var i = 1; i <= count; i++) {
        html += '<div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 2px rgba(0,0,0,0.03);">' +
            '<div style="font-weight:700;color:#1e293b;font-size:13.5px;display:flex;align-items:center;gap:6px;">' +
                '<i class="fas fa-gas-pump" style="font-size:12px;color:#002F6C;"></i>' +
                '<span>Pump ' + i + '</span>' +
            '</div>' +
            '<select class="new-pump-status" data-pump-idx="' + i + '" style="font-size:12px;font-weight:700;padding:3px 8px;border-radius:4px;border:1px solid #86efac;background:#dcfce7;color:#166534;cursor:pointer;" onchange="this.style.background = this.value === \'Active\' ? \'#dcfce7\' : \'#fee2e2\'; this.style.color = this.value === \'Active\' ? \'#166534\' : \'#991b1b\'; this.style.borderColor = this.value === \'Active\' ? \'#86efac\' : \'#fca5a5\';">' +
                '<option value="Active" selected>Active</option>' +
                '<option value="Inactive">Inactive</option>' +
            '</select>' +
        '</div>';
    }
    container.innerHTML = html;
}

function openAddProductModal() {
    document.getElementById('addProductModal').style.display = 'flex';
    var ugtEl = document.getElementById('newUgtNo');
    if (ugtEl) ugtEl.value = '';
    var fuelInp = document.getElementById('newFuelName');
    if (fuelInp) {
        fuelInp.value = '';
        try { fuelInp.focus(); } catch(e) {}
    }
    var numPumpsEl = document.getElementById('newNumPumps');
    if (numPumpsEl) {
        numPumpsEl.value = '';
    }
    // Reset hidden fields
    var ftiEl = document.getElementById('newFuelTypeId');
    var ftnEl = document.getElementById('newFuelTypeName');
    if (ftiEl) ftiEl.value = '';
    if (ftnEl) ftnEl.value = '';
}

function closeAddProductModal() {
    document.getElementById('addProductModal').style.display = 'none';
    var form = document.getElementById('addProductForm');
    if (form) form.reset();
    var ugtEl = document.getElementById('newUgtNo');
    if (ugtEl) ugtEl.value = '';
    var numPumpsEl = document.getElementById('newNumPumps');
    if (numPumpsEl) numPumpsEl.value = '';
    var ftiEl = document.getElementById('newFuelTypeId');
    var ftnEl = document.getElementById('newFuelTypeName');
    if (ftiEl) ftiEl.value = '';
    if (ftnEl) ftnEl.value = '';
}

function getCleanCanonicalFuelName(name) {
    if (!name) return 'Fuel';
    return String(name).replace(/\s*\(UGT\s*#?\d+\)/gi, '').trim();
}

function openEditPriceModal(id, fuelType, currentPrice, capacity, criticalLevel, reorderLevel, ugtNo) {
    document.getElementById('editFuelId').value = id;
    
    var cleanFuelName = getCleanCanonicalFuelName(fuelType);
    var ugtVal = ugtNo || '';
    
    var ugtEl = document.getElementById('editUgtNo');
    if (ugtEl) ugtEl.value = ugtVal;
    
    var nameInput = document.getElementById('editFuelName');
    if (nameInput) nameInput.value = cleanFuelName;
    
    if (document.getElementById('editFuelType')) document.getElementById('editFuelType').value = cleanFuelName;
    var displayEl = document.getElementById('editFuelTypeDisplay');
    if (displayEl) displayEl.textContent = cleanFuelName;

    if (document.getElementById('editPrice')) document.getElementById('editPrice').value = parseFloat(currentPrice || 0).toFixed(2);
    if (document.getElementById('editFuelCapacity')) document.getElementById('editFuelCapacity').value = capacity || '';
    if (document.getElementById('editFuelCritical')) document.getElementById('editFuelCritical').value = criticalLevel || '';
    if (document.getElementById('editFuelReorder')) document.getElementById('editFuelReorder').value = reorderLevel || '';

    var pumpBadge = document.getElementById('mef_pump_count_badge');
    var pumpCont  = document.getElementById('mef_pumps_container');
    if (pumpBadge) pumpBadge.textContent = 'Loading...';
    if (pumpCont)  pumpCont.innerHTML = '<div style="color:#64748b;font-size:13px;font-style:italic;padding:8px 0;"><i class="fas fa-spinner fa-spin"></i> Loading pump configuration...</div>';
    
    fetch('manager_set_prices_handler.php?action=get_fuel_details&id=' + id)
        .then(r => r.json()).then(data => {
            if (data.success && data.fuel) {
                var f = data.fuel;
                var rawName = getCleanCanonicalFuelName(f.fuel_type || f.clean_fuel_type || fuelType);
                var ugt = f.ugt_no || ugtNo || '';
                
                if (document.getElementById('editUgtNo')) document.getElementById('editUgtNo').value = ugt;
                if (document.getElementById('editFuelName')) document.getElementById('editFuelName').value = rawName;
                if (document.getElementById('editFuelType')) document.getElementById('editFuelType').value = rawName;
                document.getElementById('editFuelCapacity').value = (f.capacity       !== undefined && f.capacity       !== null) ? f.capacity       : (capacity || '');
                document.getElementById('editFuelCritical').value = (f.critical_level !== undefined && f.critical_level !== null) ? f.critical_level : (criticalLevel || '');
                document.getElementById('editFuelReorder').value  = (f.reorder_level  !== undefined && f.reorder_level  !== null) ? f.reorder_level  : (reorderLevel || '');
                if (f.status) {
                    var st = (f.status || '').toLowerCase();
                    if (st === 'inactive' || st === 'deactivated') {
                        var inactRadio = document.getElementById('editFuelStatusInactive');
                        if (inactRadio) inactRadio.checked = true;
                    } else {
                        var actRadio = document.getElementById('editFuelStatusActive');
                        if (actRadio) actRadio.checked = true;
                    }
                }

                // Render Pump & Nozzle Configuration for this fuel product (matches Meter Reading)
                var pumps = data.pumps || [];
                if (pumpBadge) pumpBadge.textContent = pumps.length + (pumps.length === 1 ? ' Nozzle / Pump' : ' Nozzles / Pumps');

                if (pumpCont) {
                    if (pumps.length === 0) {
                        pumpCont.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:12px;color:#64748b;font-size:13px;font-style:italic;background:#fff;border-radius:6px;border:1px dashed #cbd5e1;"><i class="fas fa-info-circle"></i> No pumps or nozzles currently assigned to this fuel product.</div>';
                    } else {
                        var pHtml = '';
                        pumps.forEach(function(pm) {
                            var pSt = (pm.status || 'Active');
                            var isAct = pSt.toLowerCase() === 'active';
                            var bgCol = isAct ? '#dcfce7' : '#fee2e2';
                            var txtCol = isAct ? '#166534' : '#991b1b';
                            var brdCol = isAct ? '#86efac' : '#fca5a5';
                            
                            // Format nozzle label cleanly to Title Case (e.g. "DIESEL 1 - 1" -> "Diesel 1 - 1") matching staff fuel management
                            var rawLabel = (pm.pump_number || pm.pump_name || ('Pump #' + pm.id)).trim();
                            var meterLabel = rawLabel.replace(/\b[a-zA-Z]+/g, function(w) {
                                var up = w.toUpperCase();
                                if (up === 'XCS' || up === 'UGT' || up === 'UNL') return up;
                                return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                            });
                            
                            pHtml += '<div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 2px rgba(0,0,0,0.03);gap:8px;">' +
                                '<div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1 1 auto;">' +
                                    '<div style="width:26px;height:26px;border-radius:5px;background:#e0f2fe;color:#002F6C;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;">' +
                                        '<i class="fas fa-gas-pump"></i>' +
                                    '</div>' +
                                    '<div style="font-weight:800;color:#0f172a;font-size:13px;line-height:1.2;white-space:nowrap;letter-spacing:0.2px;">' +
                                        meterLabel +
                                    '</div>' +
                                '</div>' +
                                '<select name="edit_pump_status[' + pm.id + ']" style="font-size:11.5px !important;font-weight:700 !important;padding:2px 8px !important;border-radius:4px !important;border:1px solid ' + brdCol + ' !important;background:' + bgCol + ' !important;background-color:' + bgCol + ' !important;color:' + txtCol + ' !important;cursor:pointer;flex-shrink:0;height:24px !important;line-height:1.2 !important;" onchange="this.style.setProperty(\'background\', this.value === \'Active\' ? \'#dcfce7\' : \'#fee2e2\', \'important\'); this.style.setProperty(\'background-color\', this.value === \'Active\' ? \'#dcfce7\' : \'#fee2e2\', \'important\'); this.style.setProperty(\'color\', this.value === \'Active\' ? \'#166534\' : \'#991b1b\', \'important\'); this.style.setProperty(\'border-color\', this.value === \'Active\' ? \'#86efac\' : \'#fca5a5\', \'important\');">' +
                                    '<option value="Active"' + (isAct ? ' selected' : '') + '>Active</option>' +
                                    '<option value="Inactive"' + (!isAct ? ' selected' : '') + '>Inactive</option>' +
                                '</select>' +
                            '</div>';
                        });
                        pumpCont.innerHTML = pHtml;
                    }
                }
            }
        }).catch(function(){});
    document.getElementById('editPriceModal').style.display = 'flex';
    document.getElementById('editPrice').focus();
}

function closeEditPriceModal() {
    document.getElementById('editPriceModal').style.display = 'none';
    document.getElementById('editPriceForm').reset();
    var pumpCont = document.getElementById('mef_pumps_container');
    if (pumpCont) pumpCont.innerHTML = '';
}

// Close modals on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAddProductModal();
        closeEditPriceModal();
        closeViewFuelModal();
        closeRollbackModal();
        closeAddMerchandiseModal();
        closeEditMerchPriceModal();
        closeAddServiceModal();
        closeEditServicePriceModal();
    }
});

// Helper to safely attach event listener without throwing if element is missing
function safeAddListener(id, event, handler) {
    var el = document.getElementById(id);
    if (el) el.addEventListener(event, handler);
}

// Close modals on background click
safeAddListener('addProductModal', 'click', function(e) { if (e.target === this) closeAddProductModal(); });
safeAddListener('editPriceModal', 'click', function(e) { if (e.target === this) closeEditPriceModal(); });
safeAddListener('viewFuelModal', 'click', function(e) { if (e.target === this) closeViewFuelModal(); });
safeAddListener('rollbackPriceModal', 'click', function(e) { if (e.target === this) closeRollbackModal(); });
safeAddListener('addMerchandiseModal', 'click', function(e) { if (e.target === this) closeAddMerchandiseModal(); });
safeAddListener('editMerchPriceModal', 'click', function(e) { if (e.target === this) closeEditMerchPriceModal(); });
safeAddListener('addServiceModal', 'click', function(e) { if (e.target === this) closeAddServiceModal(); });
safeAddListener('editServicePriceModal', 'click', function(e) { if (e.target === this) closeEditServicePriceModal(); });


// Auto-format UGT number on blur if user typed just digits or 'ugt X'
safeAddListener('newUgtNo', 'blur', function() {
    var v = this.value.trim();
    if (/^\d+$/.test(v)) {
        this.value = 'UGT #' + v;
    } else if (/^ugt\s*#?\s*(\d+)$/i.test(v)) {
        var m = v.match(/^ugt\s*#?\s*(\d+)$/i);
        this.value = 'UGT #' + m[1];
    }
});

// ── Add Fuel Product Form Handler ───────────────────────────────────────────
var isSubmittingFuelProduct = false;
safeAddListener('addProductForm', 'submit', function(e) {
    e.preventDefault();
    if (isSubmittingFuelProduct) {
        return false;
    }

    var form = e.target;
    var fuelName = (document.getElementById('newFuelName') || {}).value || '';
    fuelName = fuelName.trim();
    var ugtNo    = (document.getElementById('newUgtNo') || {}).value || '';
    ugtNo = ugtNo.trim();
    if (/^\d+$/.test(ugtNo)) {
        ugtNo = 'UGT #' + ugtNo;
    } else if (/^ugt\s*#?\s*(\d+)$/i.test(ugtNo)) {
        var m = ugtNo.match(/^ugt\s*#?\s*(\d+)$/i);
        ugtNo = 'UGT #' + m[1];
    }
    if (document.getElementById('newUgtNo')) {
        document.getElementById('newUgtNo').value = ugtNo;
    }

    var priceRaw = (document.getElementById('newPrice') || {}).value || '';
    var price    = parseFloat(priceRaw);
    var capRaw   = (document.getElementById('newCapacity') || {}).value || '';
    var capacity = parseFloat(capRaw);
    var critRaw  = (document.getElementById('newCriticalLevel') || {}).value || '';
    var critical = parseFloat(critRaw) || 0;
    var reordRaw = (document.getElementById('newReorderLevel') || {}).value || '';
    var reorder  = parseFloat(reordRaw) || 0;
    var statusEl = document.querySelector('input[name="newStatus"]:checked');
    var status   = statusEl ? statusEl.value : 'active';
    var remarks  = (document.getElementById('newRemarks') || {}).value || '';
    remarks = remarks.trim();

    if (!fuelName) {
        showCustomAlert('Fuel Name is required.', 'error');
        return;
    }
    if (!ugtNo) {
        showCustomAlert('Please enter or select a UGT Number.', 'error');
        return;
    }
    if (isNaN(price) || price <= 0) {
        showCustomAlert('Please enter a valid selling price per liter.', 'error');
        return;
    }
    if (isNaN(capacity) || capacity <= 0) {
        showCustomAlert('Please enter a valid tank capacity.', 'error');
        return;
    }
    if (capacity <= reorder) {
        showCustomAlert('Tank Capacity must be greater than Reorder Level.', 'error');
        return;
    }
    if (reorder <= critical && critical > 0) {
        showCustomAlert('Reorder Level must be greater than Critical Level.', 'error');
        return;
    }

    var numPumpsRaw = (document.getElementById('newNumPumps') || {}).value;
    if (numPumpsRaw === '' || numPumpsRaw === undefined || numPumpsRaw === null) {
        showCustomAlert('Please enter the Number of Pumps.', 'error');
        return;
    }
    var numPumps = parseInt(numPumpsRaw);
    if (isNaN(numPumps) || numPumps < 0) {
        showCustomAlert('Please enter a valid Number of Pumps (0 or more).', 'error');
        return;
    }

    // Lock submission flag immediately to prevent double submission
    isSubmittingFuelProduct = true;

    var btn = form.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.7';
        btn.style.cursor = 'not-allowed';
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding Fuel Product...';
    }
    var formInputs = form.querySelectorAll('input, select, textarea, button');
    formInputs.forEach(function(el) { if (el !== btn) el.disabled = true; });

    var fd = new FormData();
    fd.append('action',         'add_fuel_product');
    fd.append('fuel_type',      fuelName);
    fd.append('ugt_no',         ugtNo);
    fd.append('price',          price);
    fd.append('capacity',       capacity);
    fd.append('critical_level', critical);
    fd.append('reorder_level',  reorder);
    fd.append('current_volume', parseFloat((document.getElementById('newCurrentVolume') || {}).value || 0) || 0);
    fd.append('status',         status);
    fd.append('remarks',        remarks);
    fd.append('num_pumps',      numPumps);
    fd.append('pump_configs',   '[]');

    fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                closeAddProductModal();
                showCustomAlert(data.message || 'Fuel product added successfully!', 'success', function() {
                    location.reload();
                });
            } else {
                showCustomAlert(data.message || 'Failed to add fuel product.', 'error');
                isSubmittingFuelProduct = false;
                formInputs.forEach(function(el) { el.disabled = false; });
                if (btn) {
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    btn.style.cursor = 'pointer';
                    btn.innerHTML = '<i class="fas fa-check"></i> Add Fuel Product';
                }
            }
        })
        .catch(function() {
            showCustomAlert('Network error. Please try again.', 'error');
            isSubmittingFuelProduct = false;
            formInputs.forEach(function(el) { el.disabled = false; });
            if (btn) {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                btn.innerHTML = '<i class="fas fa-check"></i> Add Fuel Product';
            }
        });
});

// ── Edit Fuel Full Form Handler ─────────────────────────────────────────────
safeAddListener('editPriceForm', 'submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    var statusVal = document.querySelector('input[name="editFuelStatus"]:checked') ? document.querySelector('input[name="editFuelStatus"]:checked').value : 'active';
    var critEl = document.getElementById('editFuelCritical');
    fd.append('action',         'edit_fuel_full');
    fd.append('id',             document.getElementById('editFuelId').value);
    fd.append('ugt_no',         document.getElementById('editUgtNo') ? document.getElementById('editUgtNo').value.trim() : '');
    fd.append('fuel_name',      document.getElementById('editFuelName').value.trim());
    fd.append('price',          document.getElementById('editPrice').value);
    fd.append('capacity',       document.getElementById('editFuelCapacity').value);
    fd.append('critical_level', critEl ? critEl.value : '0');
    fd.append('reorder_level',  document.getElementById('editFuelReorder').value);
    fd.append('status',         statusVal);
    fd.append('remarks',        document.getElementById('editFuelRemarks') ? document.getElementById('editFuelRemarks').value.trim() : '');

    // Include pump status values
    var pumpSelects = document.querySelectorAll('#mef_pumps_container select');
    pumpSelects.forEach(function(sel) {
        if (sel.name) {
            fd.append(sel.name, sel.value);
        }
    });
    
    fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeEditPriceModal();
                showCustomAlert(data.message || 'Fuel product updated!', 'success', function() {
                    location.reload();
                });
            } else {
                showCustomAlert(data.message || 'Update failed', 'error');
            }
        }).catch(function() { showCustomAlert('Network error. Please try again.', 'error'); });
});

var _currentViewFuel = null;

// ── View Fuel Details (4 Full Sections) ─────────────────────────────────────
function viewFuelDetails(fuelId) {
    // Reset pump section to loading state before fetch completes
    var mPumpBadge = document.getElementById('m_view_pump_count_badge');
    var mPumpCont  = document.getElementById('m_view_pumps_container');
    if (mPumpBadge) mPumpBadge.textContent = '...';
    if (mPumpCont)  mPumpCont.innerHTML = '<div style="grid-column:1/-1;color:#64748b;font-size:13px;font-style:italic;padding:6px 0;"><i class="fas fa-spinner fa-spin"></i> Loading assigned pumps...</div>';

    fetch('manager_set_prices_handler.php?action=get_fuel_details&id=' + fuelId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                var fuel = data.fuel;
                _currentViewFuel = fuel;
                var history = data.history || [];
                var configHistory = data.config_history || [];
                var statusHistory = data.status_history || [];
                
                // Section 1 – Fuel Information
                var cleanFuelName = (fuel.fuel_type || '').replace(/\s*\(UGT\s*#?\d+\)/gi, '').trim();
                document.getElementById('viewFuelType').textContent = cleanFuelName;
                var ugtEl = document.getElementById('viewUgtNo');
                if (ugtEl) ugtEl.textContent = fuel.ugt_no || '-';
                document.getElementById('viewCurrentPrice').textContent = '₱' + parseFloat(fuel.price_per_liter || 0).toFixed(2);
                document.getElementById('viewStock').textContent = parseFloat(fuel.current_stock || fuel.current_level || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
                var availCap = parseFloat(fuel.available_capacity || (fuel.capacity - fuel.current_stock) || 0);
                document.getElementById('viewAvailableCapacity').textContent = availCap.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
                document.getElementById('viewCapacity').textContent = parseFloat(fuel.capacity || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
                document.getElementById('viewCriticalLevel').textContent = parseFloat(fuel.critical_level || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
                document.getElementById('viewReorderLevel').textContent = parseFloat(fuel.reorder_level || 0).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
                
                // Stock Status
                var stock = parseFloat(fuel.current_stock || 0);
                var crit = parseFloat(fuel.critical_level || 0);
                var stockStatusText = '<span style="color:#16a34a;font-weight:700;">Normal</span>';
                if (stock <= 0) {
                    stockStatusText = '<span style="color:#dc2626;font-weight:700;">Out of Stock</span>';
                } else if (stock <= crit) {
                    stockStatusText = '<span style="color:#dc2626;font-weight:700;">Critical</span>';
                }
                document.getElementById('viewStatus').innerHTML = stockStatusText;

                // Product Status (Active / Inactive)
                var prodStatus = (fuel.status || 'active').toLowerCase();
                var prodBadge = prodStatus === 'active' 
                    ? '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:12px;font-size:14.5px;font-weight:700;">Active</span>' 
                    : '<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:12px;font-size:14.5px;font-weight:700;">Inactive</span>';
                document.getElementById('viewProductStatus').innerHTML = prodBadge;

                // Price Request Status
                document.getElementById('viewPriceRequestStatus').textContent = fuel.price_request_status || 'No Pending Request';
                document.getElementById('viewLastUpdated').textContent = fuel.last_updated || '-';
                document.getElementById('viewUpdatedBy').textContent = fuel.updated_by_name || '-';
                
                // Section 2 – Price History
                var historyBody = document.getElementById('priceHistoryBody');
                historyBody.innerHTML = '';
                if (history.length === 0) {
                    historyBody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;color:#94a3b8;">No price history available</td></tr>';
                } else {
                    history.forEach(function(h) {
                        var targetPrice = parseFloat(h.new_price || 0);
                        var curPrice = parseFloat(fuel.price_per_liter || 0);
                        var isCurrent = Math.abs(targetPrice - curPrice) < 0.001;
                        
                        var actionCell = '';
                        if (isCurrent) {
                            actionCell = '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:12px;font-size:14px;font-weight:700;"><i class="fas fa-check"></i> Current</span>';
                        } else {
                            var safeFuelName = (fuel.fuel_type || '').replace(/'/g, "\\'");
                            var safeDate = (h.created_at || '').replace(/'/g, "\\'");
                            actionCell = '<button type="button" onclick="openRestorePriceModal(' + fuel.id + ', \'' + safeFuelName + '\', ' + curPrice + ', ' + targetPrice + ', \'' + safeDate + '\')" style="background:#002F6C !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:5px 12px;border-radius:6px;font-size:14px;font-weight:700 !important;cursor:pointer;display:inline-flex;align-items:center;gap:4px;box-shadow:0 2px 4px rgba(0,47,108,0.2);"><i class="fas fa-undo" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Restore</button>';
                        }

                        var stLower = (h.status || 'Approved').toLowerCase();
                        var statusBadge = '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:14px;font-weight:700;">Approved</span>';
                        if (stLower === 'pending') {
                            statusBadge = '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:14px;font-weight:700;">Pending</span>';
                        } else if (stLower === 'rejected') {
                            statusBadge = '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:14px;font-weight:700;">Rejected</span>';
                        }

                        var row = document.createElement('tr');
                        row.style.borderBottom = '1px solid #f1f5f9';
                        row.innerHTML = `
                            <td style="padding:10px 12px;color:#475569;">${h.created_at || '-'}</td>
                            <td style="padding:10px 12px;font-weight:700;color:#002F6C;">₱${targetPrice.toFixed(2)}</td>
                            <td style="padding:10px 12px;">${h.requested_by_name || 'Manager'}</td>
                            <td style="padding:10px 12px;">${h.approved_by_name || 'Admin'}</td>
                            <td style="padding:10px 12px;">${statusBadge}</td>
                            <td style="padding:10px 12px;text-align:center;">${actionCell}</td>
                        `;
                        historyBody.appendChild(row);
                    });
                }
                
                // Section 3 – Configuration History
                var configBody = document.getElementById('configHistoryBody');
                if (configBody) {
                    configBody.innerHTML = '';
                    if (configHistory.length === 0) {
                        configBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No configuration changes recorded yet</td></tr>';
                    } else {
                        configHistory.forEach(function(ch) {
                            var row = document.createElement('tr');
                            row.style.borderBottom = '1px solid #f1f5f9';
                            row.innerHTML = `
                                <td style="padding:8px 12px;color:#475569;">${ch.created_at || '-'}</td>
                                <td style="padding:8px 12px;font-weight:700;color:#002F6C;">${ch.field_name || '-'}</td>
                                <td style="padding:8px 12px;color:#dc2626;font-weight:600;">${ch.old_value || '-'}</td>
                                <td style="padding:8px 12px;color:#16a34a;font-weight:700;">${ch.new_value || '-'}</td>
                                <td style="padding:8px 12px;">${ch.updated_by_name || '-'}</td>
                            `;
                            configBody.appendChild(row);
                        });
                    }
                }

                // Section 4 – Status History
                var statusBody = document.getElementById('statusHistoryBody');
                if (statusBody) {
                    statusBody.innerHTML = '';
                    if (statusHistory.length === 0) {
                        statusBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No status history recorded yet</td></tr>';
                    } else {
                        statusHistory.forEach(function(sh) {
                            var oldSt = (sh.old_status || (sh.status === 'Activated' ? 'Inactive' : (sh.status === 'Deactivated' ? 'Active' : 'Active'))).toLowerCase();
                            var newSt = (sh.new_status || (sh.status === 'Deactivated' ? 'Inactive' : (sh.status === 'Activated' ? 'Active' : 'Inactive'))).toLowerCase();
                            
                            var oldBadge = oldSt === 'active'
                                ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:14px;font-weight:700;">Active</span>'
                                : '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:14px;font-weight:700;">Inactive</span>';
                            
                            var newBadge = newSt === 'active'
                                ? '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:14px;font-weight:700;">Active</span>'
                                : '<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:14px;font-weight:700;">Inactive</span>';
                            
                            var reasonTxt = sh.reason ? sh.reason : '-';

                            var row = document.createElement('tr');
                            row.style.borderBottom = '1px solid #f1f5f9';
                            row.innerHTML = `
                                <td style="padding:8px 12px;color:#475569;">${sh.created_at || '-'}</td>
                                <td style="padding:8px 12px;">${oldBadge}</td>
                                <td style="padding:8px 12px;">${newBadge}</td>
                                <td style="padding:8px 12px;color:#64748b;">${reasonTxt}</td>
                                <td style="padding:8px 12px;">${sh.changed_by_name || 'Manager'}</td>
                            `;
                            statusBody.appendChild(row);
                        });
                    }
                }
                
                // Section 5 – Assigned Pumps (synced with Fuel Management)
                mPumpBadge = document.getElementById('m_view_pump_count_badge');
                mPumpCont  = document.getElementById('m_view_pumps_container');
                var pumps = data.pumps || [];
                if (mPumpBadge) mPumpBadge.textContent = pumps.length + (pumps.length === 1 ? ' Pump Assigned' : ' Pumps Assigned');
                if (mPumpCont) {
                    if (pumps.length === 0) {
                        mPumpCont.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:12px;color:#64748b;font-size:13px;font-style:italic;background:#fff;border-radius:6px;border:1px dashed #cbd5e1;"><i class="fas fa-info-circle"></i> No pumps currently assigned to this fuel product in Fuel Management.</div>';
                    } else {
                        mPumpCont.innerHTML = pumps.map(function(pm) {
                            var isAct = (pm.status || 'Active').toLowerCase() === 'active';
                            var bgBadge  = isAct ? '#dcfce7' : '#fee2e2';
                            var txtBadge = isAct ? '#166534' : '#991b1b';
                            var brdBadge = isAct ? '#86efac' : '#fca5a5';
                            var rawLabel = (pm.pump_number || pm.pump_name || ('Pump #' + pm.id)).trim();
                            var meterLabel = rawLabel.replace(/\b[a-zA-Z]+/g, function(w) {
                                var up = w.toUpperCase();
                                if (up === 'XCS' || up === 'UGT' || up === 'UNL') return up;
                                return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                            });
                            return '<div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 3px rgba(0,0,0,0.04);">' +
                                '<div style="display:flex;align-items:center;gap:8px;min-width:0;">' +
                                    '<div style="width:28px;height:28px;border-radius:6px;background:#e0f2fe;color:#002F6C;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;">' +
                                        '<i class="fas fa-gas-pump"></i>' +
                                    '</div>' +
                                    '<div>' +
                                        '<strong style="font-size:13.5px;color:#0f172a;display:block;line-height:1.2;">' + meterLabel + '</strong>' +
                                        '<span style="font-size:11px;color:#64748b;">Nozzle: ' + (pm.nozzle_number || 'Nozzle 1') + '</span>' +
                                    '</div>' +
                                '</div>' +
                                '<span style="background:' + bgBadge + ';color:' + txtBadge + ';border:1px solid ' + brdBadge + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;white-space:nowrap;">' +
                                    (isAct ? 'Active' : 'Inactive') +
                                '</span>' +
                            '</div>';
                        }).join('');
                    }
                }
                
                document.getElementById('viewFuelModal').style.display = 'flex';
            } else {
                alert('Error: ' + (data.message || 'Failed to load fuel details'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading fuel details. Please try again.');
        });
}

function closeViewFuelModal() {
    document.getElementById('viewFuelModal').style.display = 'none';
}

function editFuelFromView() {
    if (!_currentViewFuel) return;
    var f = _currentViewFuel;
    closeViewFuelModal();
    setTimeout(function() {
        openEditPriceModal(
            f.id,
            f.fuel_type,
            f.price_per_liter,
            f.capacity,
            f.critical_level,
            f.reorder_level,
            f.ugt_no
        );
    }, 80);
}

// ── Toggle Fuel Status (Activate / Deactivate with Confirmation Dialog) ─────
function toggleFuelStatus(id, targetStatus, fuelName) {
    var actionText = targetStatus === 'active' ? 'activate' : 'deactivate';
    var titleText = targetStatus === 'active' ? 'Activate Fuel Product' : 'Deactivate Fuel Product';
    var messageText = 'Are you sure you want to ' + actionText + ' "' + fuelName + '"?\n\nThis will set the product status to ' + targetStatus + '.';
    
    showConfirmModal(
        titleText,
        'Confirm ' + actionText,
        messageText,
        function(data) {
            var formData = new FormData();
            formData.append('action', 'toggle_fuel_status');
            formData.append('id', data.id);
            formData.append('target_status', data.targetStatus);
            
            fetch('manager_set_prices_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showCustomAlert(data.message || 'Status updated successfully!', 'success', function() {
                        location.reload();
                    });
                } else {
                    showCustomAlert(data.message || 'Failed to update status', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showCustomAlert('Error updating fuel status. Please try again.', 'error');
            });
        },
        { id: id, targetStatus: targetStatus, fuelName: fuelName }
    );
}

// ── Rollback Price ──────────────────────────────────────────────────────────
function openRollbackModal(fuelId, historyId, fuelName, currentPrice, rollbackPrice) {
    document.getElementById('rollbackFuelId').value = fuelId;
    document.getElementById('rollbackHistoryId').value = historyId;
    document.getElementById('rollbackFuelName').value = fuelName;
    document.getElementById('rollbackCurrentPrice').value = '₱' + currentPrice.toFixed(2);
    document.getElementById('rollbackToPrice').value = '₱' + rollbackPrice.toFixed(2);
    document.getElementById('rollbackPriceModal').style.display = 'flex';
    document.getElementById('rollbackReason').focus();
}

function closeRollbackModal() {
    document.getElementById('rollbackPriceModal').style.display = 'none';
    document.getElementById('rollbackPriceForm').reset();
}

// Rollback Form Handler
safeAddListener('rollbackPriceForm', 'submit', function(e) {
    e.preventDefault();
    
    var fuelId = document.getElementById('rollbackFuelId').value;
    var historyId = document.getElementById('rollbackHistoryId').value;
    var reason = document.getElementById('rollbackReason').value.trim();
    
    if (!fuelId || !historyId || !reason) {
        showCustomAlert('Please provide a reason for the rollback.', 'error');
        return;
    }
    
    var formData = new FormData();
    formData.append('action', 'rollback_price');
    formData.append('fuel_id', fuelId);
    formData.append('history_id', historyId);
    formData.append('reason', reason);
    
    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeRollbackModal();
            closeViewFuelModal();
            showCustomAlert('Price rolled back successfully!', 'success', function() {
                location.reload();
            });
        } else {
            showCustomAlert(data.message || 'Failed to rollback price', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showCustomAlert('Error rolling back price. Please try again.', 'error');
    });
});

// ── Price Restoration Modal Functions & Handler ──────────────────────────────
function openRestorePriceModal(fuelId, fuelName, currentPrice, targetPrice, effectiveDate) {
    document.getElementById('restoreFuelId').value = fuelId;
    document.getElementById('restoreTargetPrice').value = targetPrice;
    document.getElementById('restoreFuelNameDisplay').textContent = fuelName;
    document.getElementById('restoreDateDisplay').textContent = effectiveDate || '-';
    document.getElementById('restoreCurrentPriceDisplay').textContent = '₱' + parseFloat(currentPrice).toFixed(2);
    document.getElementById('restoreTargetPriceDisplay').textContent = '₱' + parseFloat(targetPrice).toFixed(2);
    document.getElementById('restoreReason').value = '';
    document.getElementById('restorePriceModal').style.display = 'flex';
}

function closeRestorePriceModal() {
    document.getElementById('restorePriceModal').style.display = 'none';
}

safeAddListener('restorePriceForm', 'submit', function(e) {
    e.preventDefault();
    var fuelId = document.getElementById('restoreFuelId').value;
    var targetPrice = document.getElementById('restoreTargetPrice').value;
    var reason = document.getElementById('restoreReason').value.trim();

    var formData = new FormData();
    formData.append('action', 'submit_price_restoration');
    formData.append('fuel_id', fuelId);
    formData.append('target_price', targetPrice);
    formData.append('reason', reason);

    fetch('manager_set_prices_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeRestorePriceModal();
            closeViewFuelModal();
            showCustomAlert(data.message || 'Price restoration request submitted for Admin approval.', 'success', function() {
                location.reload();
            });
        } else {
            showCustomAlert(data.message || 'Failed to submit price restoration request.', 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showCustomAlert('Error submitting price restoration request.', 'error');
    });
});

// ── Deactivate Fuel ─────────────────────────────────────────────────────────
function deactivateFuel(id, fuelType) {
    showConfirmModal(
        'Deactivate Fuel Product',
        'Confirm deactivation',
        'Are you sure you want to deactivate "' + fuelType + '"?\n\nThis will set the fuel status to inactive.',
        function(data) {
            var formData = new FormData();
            formData.append('action', 'deactivate_fuel');
            formData.append('id', data.id);
            
            fetch('manager_set_prices_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showCustomAlert('Fuel product deactivated successfully!', 'success', function() {
                        location.reload();
                    });
                } else {
                    showCustomAlert(data.message || 'Failed to deactivate product', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showCustomAlert('Error deactivating product. Please try again.', 'error');
            });
        },
        { id: id, fuelType: fuelType }
    );
}

// ── Activate Fuel ───────────────────────────────────────────────────────────
function activateFuel(id, fuelType) {
    showConfirmModal(
        'Activate Fuel Product',
        'Confirm activation',
        'Are you sure you want to activate "' + fuelType + '"?\n\nThis will set the fuel status to active.',
        function(data) {
            var formData = new FormData();
            formData.append('action', 'activate_fuel');
            formData.append('id', data.id);
            
            fetch('manager_set_prices_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showCustomAlert('Fuel product activated successfully!', 'success', function() {
                        location.reload();
                    });
                } else {
                    showCustomAlert(data.message || 'Failed to activate product', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showCustomAlert('Error activating product. Please try again.', 'error');
            });
        },
        { id: id, fuelType: fuelType }
    );
}

// ══════════════════════════════════════════════════════════════════════════
// MERCHANDISE FUNCTIONS
// ══════════════════════════════════════════════════════════════════════════

function openAddMerchandiseModal() {
    document.getElementById('addMerchandiseModal').style.display = 'flex';
    document.getElementById('newMerchName').focus();
}

// escHtml: escape HTML special chars for safe display
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function closeAddMerchandiseModal() {
    document.getElementById('addMerchandiseModal').style.display = 'none';
    document.getElementById('addMerchandiseForm').reset();
}

// Open Edit Merchandise Modal — populates all FIFO Product Management fields
function openEditMerchModal(id) {
    document.getElementById('editMerchId').value = id;
    document.getElementById('editMerchPriceModal').style.display = 'flex';
    // Fetch full details from handler
    fetch('manager_set_prices_handler.php?action=get_merch_details&id=' + id)
        .then(r => r.json()).then(data => {
            if (data.success && data.item) {
                var i = data.item;
                document.getElementById('editMerchName').value      = i.product_name || '';
                document.getElementById('editMerchSku').value       = i.sku || '';
                document.getElementById('editMerchCategory').value  = i.category || '';
                document.getElementById('editMerchBrand').value     = i.brand || '';
                document.getElementById('editMerchSize').value      = i.size || i.unit || '';
                document.getElementById('editMerchPrice').value     = parseFloat(i.unit_price || 0);
                document.getElementById('editMerchReorder').value   = parseInt(i.reorder_level || 24);
                document.getElementById('editMerchCritical').value  = parseInt(i.critical_level || 10);
                document.getElementById('editMerchStatus').value    = i.status || 'active';
                if (document.getElementById('editMerchExpiry')) document.getElementById('editMerchExpiry').value = i.expiration_date || '';
            }
        });
    document.getElementById('editMerchName').focus();
}

// Keep old name as alias for backward compat
function openEditMerchPriceModal(id, productName, currentPrice) {
    openEditMerchModal(id);
}


function closeEditMerchPriceModal() {
    document.getElementById('editMerchPriceModal').style.display = 'none';
    document.getElementById('editMerchPriceForm').reset();
}

// ── View Merchandise Details Modal ──────────────────────────────────────────
function viewMerchandiseDetails(id) {
    _currentViewMerchId = id;
    var modal = document.getElementById('viewMerchModal');
    if (modal) modal.style.display = 'flex';

    function setSafeText(elemId, val) {
        var el = document.getElementById(elemId);
        if (el) el.textContent = (val !== null && val !== undefined && val !== '') ? val : '—';
    }
    function setSafeHtml(elemId, val) {
        var el = document.getElementById(elemId);
        if (el) el.innerHTML = val;
    }

    // Loading placeholders
    ['vm_sku','vm_name','vm_category','vm_brand','vm_unit','vm_price','vm_cost','vm_stock','vm_batch_count','vm_reorder','vm_code_sub'].forEach(function(el){
        setSafeText(el, '...');
    });
    setSafeHtml('vm_status', '...');
    ['vm_batches_body','vm_price_history_body','vm_config_history_body','vm_status_history_body'].forEach(function(el){
        setSafeHtml(el, '<tr><td colspan="7" style="text-align:center;padding:12px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>');
    });

    fetch('manager_set_prices_handler.php?action=get_merchandise_details&id=' + id)
    .then(function(r) {
        var ct = r.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            // Server returned HTML/text (e.g. redirect to login) — handle gracefully
            return r.text().then(function(txt) {
                console.warn('[viewMerch] Non-JSON response:', txt.substring(0, 200));
                throw new Error('Server returned non-JSON response. Check login or handler.');
            });
        }
        return r.json();
    })
    .then(data => {
        if (!data.success) { showCustomAlert(data.message || 'Failed to load details.', 'error'); closeViewMerchModal(); return; }
        var p = data.product || {};
        setSafeText('vm_title', ((p.name || 'Product').toUpperCase() + ' — SPECIFICATION & HISTORY'));
        setSafeText('vm_code_sub', p.sku || '—');
        setSafeText('vm_sku', p.sku || '—');
        setSafeText('vm_name', p.name || '—');
        setSafeText('vm_category', p.category_name || '—');
        setSafeText('vm_brand', p.brand || '—');
        setSafeText('vm_unit', p.unit || '—');
        setSafeText('vm_price', '₱' + parseFloat(p.price || 0).toFixed(2));
        setSafeText('vm_cost', '₱' + parseFloat(p.cost || 0).toFixed(2));
        setSafeText('vm_stock', parseFloat(p.current_stock || 0).toLocaleString());
        setSafeText('vm_batch_count', (p.batch_count || 0) + ' batch(es)');
        setSafeText('vm_reorder', p.min_stock_level || '—');
        var stLower = (p.status || 'active').toLowerCase();
        var stColor = stLower === 'active' ? '#16a34a' : '#dc2626';
        var stBg = stLower === 'active' ? '#dcfce7' : '#fee2e2';
        setSafeHtml('vm_status', '<span style="background:' + stBg + ';color:' + stColor + ';padding:2px 10px;border-radius:20px;font-size:14px;font-weight:700;">' + (p.status || 'Active') + '</span>');

        // Batches
        var bb = document.getElementById('vm_batches_body');
        if (data.batches && data.batches.length > 0) {
            bb.innerHTML = data.batches.map(function(b) {
                var stBadge = b.status === 'active' ? '<span style="background:#dcfce7;color:#16a34a;padding:1px 7px;border-radius:10px;font-size:14px;font-weight:700;">Active</span>' : '<span style="background:#fee2e2;color:#dc2626;padding:1px 7px;border-radius:10px;font-size:14px;font-weight:700;">' + b.status + '</span>';
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-family:monospace;font-weight:700;color:#0284c7;">' + (b.batch_number || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;">' + parseFloat(b.remaining_qty || 0).toLocaleString() + '</td>' +
                    '<td style="padding:8px 12px;">' + (b.expiration_date || '—') + '</td>' +
                    '<td style="padding:8px 12px;">' + stBadge + '</td></tr>';
            }).join('');
        } else {
            bb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No batch records</td></tr>';
        }

        // Price History
        var pb = document.getElementById('vm_price_history_body');
        if (data.price_history && data.price_history.length > 0) {
            pb.innerHTML = data.price_history.map(function(h, idx) {
                var statusColor = h.status === 'approved' ? '#16a34a' : h.status === 'rejected' ? '#dc2626' : '#d97706';
                var statusBg = h.status === 'approved' ? '#dcfce7' : h.status === 'rejected' ? '#fee2e2' : '#fef3c7';
                var actionBtn = '';
                if (h.status === 'approved' && idx > 0) {
                    actionBtn = '<button onclick="restoreMerchPrice(' + (p.id || id) + ',' + h.old_price + ')" style="background:#4f46e5;color:#fff;border:none;padding:3px 10px;border-radius:5px;font-size:14px;cursor:pointer;font-weight:700;"><i class=\'fas fa-undo\'></i> Restore</button>';
                } else if (h.status === 'approved' && idx === 0) {
                    actionBtn = '<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:10px;font-size:15.5px;font-weight:700;">Current</span>';
                } else if (h.status === 'pending') {
                    actionBtn = '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:15.5px;font-weight:700;">Awaiting Approval</span>';
                }
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-size:14px;color:#64748b;">' + (h.created_at || '—') + '</td>' +
                    '<td style="padding:8px 12px;">₱' + parseFloat(h.old_price || 0).toFixed(2) + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;color:#002F6C;">₱' + parseFloat(h.new_price || 0).toFixed(2) + '</td>' +
                    '<td style="padding:8px 12px;font-size:14px;">' + (h.requested_by_name || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-size:14px;">' + (h.approved_by_name || '—') + '</td>' +
                    '<td style="padding:8px 12px;"><span style="background:' + statusBg + ';color:' + statusColor + ';padding:2px 8px;border-radius:10px;font-size:15.5px;font-weight:700;">' + (h.status || '—') + '</span></td>' +
                    '<td style="padding:8px 12px;text-align:center;">' + actionBtn + '</td></tr>';
            }).join('');
        } else {
            pb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:12px;color:#94a3b8;">No price history</td></tr>';
        }

        // Config History
        var cb = document.getElementById('vm_config_history_body');
        if (data.config_history && data.config_history.length > 0) {
            cb.innerHTML = data.config_history.map(function(h) {
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-size:14px;color:#64748b;">' + (h.created_at || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;">' + (h.field_name || '—') + '</td>' +
                    '<td style="padding:8px 12px;color:#dc2626;">' + (h.old_value || '—') + '</td>' +
                    '<td style="padding:8px 12px;color:#16a34a;font-weight:700;">' + (h.new_value || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-size:14px;">' + (h.changed_by_name || '—') + '</td></tr>';
            }).join('');
        } else {
            cb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:12px;color:#94a3b8;">No configuration changes recorded</td></tr>';
        }

        // Status History
        var sb = document.getElementById('vm_status_history_body');
        if (data.status_history && data.status_history.length > 0) {
            sb.innerHTML = data.status_history.map(function(h) {
                return '<tr style="border-top:1px solid #f1f5f9;">' +
                    '<td style="padding:8px 12px;font-size:14px;color:#64748b;">' + (h.created_at || '—') + '</td>' +
                    '<td style="padding:8px 12px;color:#64748b;">' + (h.old_status || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-weight:700;">' + (h.new_status || '—') + '</td>' +
                    '<td style="padding:8px 12px;font-size:14px;">' + (h.changed_by_name || '—') + '</td></tr>';
            }).join('');
        } else {
            sb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No status changes recorded</td></tr>';
        }
    })
    .catch(function(err) {
        console.error('[viewMerch] Fetch error:', err);
        closeViewMerchModal();
        showCustomAlert('Error loading product details. Please try again.', 'error');
    });
}


function closeViewMerchModal() {
    document.getElementById('viewMerchModal').style.display = 'none';
}

function editMerchFromView() {
    if (!_currentViewMerchId) return;
    var id = _currentViewMerchId;
    closeViewMerchModal();
    setTimeout(function() {
        openEditMerchModal(id);
    }, 80);
}

function restoreMerchPrice(id, targetPrice) {
    if (!confirm('Request restore of selling price to ₱' + parseFloat(targetPrice).toFixed(2) + '?\n\nThis will be submitted for Admin approval.')) return;
    var fd = new FormData();
    fd.append('action', 'restore_merchandise_price');
    fd.append('id', id);
    fd.append('target_price', targetPrice);
    fd.append('reason', 'Price Restoration Request by Manager');
    fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showCustomAlert ? showCustomAlert(data.message, 'success') : alert(data.message);
            closeViewMerchModal();
        } else {
            showCustomAlert ? showCustomAlert(data.message || 'Failed to submit restore request.', 'error') : alert(data.message);
        }
    })
    .catch(() => alert('Error submitting restore request.'));
}

// Add Merchandise Form Handler
safeAddListener('addMerchandiseForm', 'submit', function(e) {
    e.preventDefault();

    var name     = document.getElementById('newMerchName').value.trim();
    var category = document.getElementById('newMerchCategory').value.trim();
    var price    = parseFloat(document.getElementById('newMerchPrice').value);
    var sku      = document.getElementById('newMerchSku').value.trim();
    var brand    = document.getElementById('newMerchBrand').value.trim();
    var size     = document.getElementById('newMerchSize').value.trim();
    var reorder  = parseInt(document.getElementById('newMerchReorder').value) || 24;
    var critical = parseInt(document.getElementById('newMerchCritical').value) || 10;
    var expiry   = ((document.getElementById('newMerchExpiry') || {}).value || '').trim();

    var placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];
    if (!name || placeholders.includes(name.toLowerCase())) {
        showCustomAlert('Product Name is required and cannot be N/A or a placeholder.', 'error');
        document.getElementById('newMerchName').focus();
        return;
    }

    if (!category || placeholders.includes(category.toLowerCase())) {
        showCustomAlert('Category is required and cannot be N/A or a placeholder.', 'error');
        document.getElementById('newMerchCategory').focus();
        return;
    }

    if (isNaN(price) || price <= 0) {
        showCustomAlert('Default Selling Price must be a valid number greater than ₱0.00.', 'error');
        document.getElementById('newMerchPrice').focus();
        return;
    }

    var formData = new FormData();
    formData.append('action', 'add_merchandise');
    formData.append('product_name', name);
    formData.append('category', category);
    formData.append('brand', brand);
    formData.append('unit_price', price);
    formData.append('unit_cost', 0); // cost set per delivery batch
    formData.append('sku', sku);
    formData.append('size', size);
    formData.append('reorder_level', reorder);
    formData.append('critical_level', critical);
    formData.append('expiration_date', expiry);

    fetch('manager_set_prices_handler.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeAddMerchandiseModal();
            showCustomAlert('Product added successfully!', 'success', function() {
                location.reload();
            });
        } else {
            showCustomAlert(data.message || 'Failed to add product', 'error');
        }
    })
    .catch(() => showCustomAlert('Error adding product. Please try again.', 'error'));
});

// Edit Merchandise Full Form Handler
safeAddListener('editMerchPriceForm', 'submit', function(e) {
    e.preventDefault();

    var name     = document.getElementById('editMerchName').value.trim();
    var category = document.getElementById('editMerchCategory').value.trim();
    var price    = parseFloat(document.getElementById('editMerchPrice').value);

    var placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];
    if (!name || placeholders.includes(name.toLowerCase())) {
        showCustomAlert('Product Name is required and cannot be N/A or a placeholder.', 'error');
        document.getElementById('editMerchName').focus();
        return;
    }

    if (!category || placeholders.includes(category.toLowerCase())) {
        showCustomAlert('Category is required and cannot be N/A or a placeholder.', 'error');
        document.getElementById('editMerchCategory').focus();
        return;
    }

    if (isNaN(price) || price <= 0) {
        showCustomAlert('Default Selling Price must be a valid number greater than ₱0.00.', 'error');
        document.getElementById('editMerchPrice').focus();
        return;
    }

    var fd = new FormData();
    fd.append('action',         'edit_merchandise_full');
    fd.append('id',             document.getElementById('editMerchId').value);
    fd.append('product_name',   name);
    fd.append('sku',            document.getElementById('editMerchSku').value.trim());
    fd.append('category',       document.getElementById('editMerchCategory').value.trim());
    fd.append('brand',          document.getElementById('editMerchBrand').value.trim());
    fd.append('size',           document.getElementById('editMerchSize').value.trim());
    fd.append('unit_price',     document.getElementById('editMerchPrice').value);
    fd.append('unit_cost',      0); // cost managed per delivery batch
    fd.append('reorder_level',  document.getElementById('editMerchReorder').value);
    fd.append('critical_level', document.getElementById('editMerchCritical').value);
    fd.append('status',         document.getElementById('editMerchStatus').value);
    fd.append('expiration_date', ((document.getElementById('editMerchExpiry') || {}).value || '').trim());
    fetch('manager_set_prices_handler.php', {method:'POST', body:fd})
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeEditMerchPriceModal();
                showCustomAlert('Product updated successfully!', 'success', function() {
                    location.reload();
                });
            } else {
                showCustomAlert(data.message || 'Failed to update product', 'error');
            }
        }).catch(() => showCustomAlert('Error updating product.', 'error'));
});

// ── Deactivate Merchandise ──────────────────────────────────────────────────
function deactivateMerchandise(id, productName) {
    showConfirmModal(
        'Deactivate Merchandise Product',
        'Confirm deactivation',
        'Are you sure you want to deactivate "' + productName + '"?\n\nThis will set the product status to inactive.',
        function(data) {
            var formData = new FormData();
            formData.append('action', 'deactivate_merchandise');
            formData.append('id', data.id);
            
            fetch('manager_set_prices_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showCustomAlert(data.message || 'Product deactivated successfully!', 'success', function() {
                        location.reload();
                    });
                } else {
                    showCustomAlert(data.message || 'Failed to deactivate product', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showCustomAlert('Error deactivating product. Please try again.', 'error');
            });
        },
        { id: id, productName: productName }
    );
}

// ── Activate Merchandise ────────────────────────────────────────────────────
function activateMerchandise(id, productName) {
    showConfirmModal(
        'Activate Merchandise Product',
        'Confirm activation',
        'Are you sure you want to activate "' + productName + '"?\n\nThis will set the product status to active.',
        function(data) {
            var formData = new FormData();
            formData.append('action', 'activate_merchandise');
            formData.append('id', data.id);
            
            fetch('manager_set_prices_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showCustomAlert(data.message || 'Product activated successfully!', 'success', function() {
                        location.reload();
                    });
                } else {
                    showCustomAlert(data.message || 'Failed to activate product', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showCustomAlert('Error activating product. Please try again.', 'error');
            });
        },
        { id: id, productName: productName }
    );
}

// View Batches function
function viewProductBatches(productId, productName) {
    document.getElementById('viewBatchesTitle').textContent = productName + ' — Batch History';
    document.getElementById('viewBatchesContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading batches...</div>';
    document.getElementById('viewBatchesModal').style.display = 'flex';

    fetch('manager_set_prices_handler.php?action=get_product_batches&id=' + productId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.batches || data.batches.length === 0) {
                document.getElementById('viewBatchesContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>No batch records found for this product.<br><small>Record a delivery to create the first batch.</small></div>';
                return;
            }
            var batches = data.batches;
            var firstActive = true;
            var rows = batches.map(function(b) {
                var isFirst = firstActive && b.status === 'active';
                if (isFirst) firstActive = false;
                var bNum = b.batch_number || ('B' + String(b.id).padStart(4,'0'));
                var fifo = isFirst ? '<span style="background:#16a34a;color:#fff;font-size:15.5px;padding:1px 5px;border-radius:3px;font-weight:700;margin-left:4px;">NEXT FIFO</span>' : '';
                var statusBadge = b.status === 'active'
                    ? '<span style="background:#dcfce7;color:#166534;padding:2px 7px;border-radius:4px;font-size:14px;font-weight:600;">Active</span>'
                    : '<span style="background:#f1f5f9;color:#64748b;padding:2px 7px;border-radius:4px;font-size:14px;">Depleted</span>';
                return '<tr style="border-bottom:1px solid #f1f5f9;">'
                    + '<td style="padding:8px 10px;"><code style="color:#4f46e5;background:#ede9fe;padding:2px 6px;border-radius:3px;font-size:14.5px;">' + bNum + '</code>' + fifo + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;">' + parseInt(b.quantity_received||0) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;font-weight:700;">' + parseInt(b.remaining_qty||0) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;color:#64748b;">&#8369;' + parseFloat(b.unit_cost||0).toFixed(2) + '</td>'
                    + '<td style="padding:8px 10px;font-size:14px;color:#64748b;">' + (b.date_received||'—').substring(0,10) + '</td>'
                    + '<td style="padding:8px 10px;text-align:center;">' + statusBadge + '</td>'
                    + '</tr>';
            }).join('');
            document.getElementById('viewBatchesContent').innerHTML =
                '<div style="overflow-x: hidden;">'
                + '<table style="width:100%;border-collapse:collapse;font-size:15.5px;">'
                + '<thead><tr style="background:#002F6C;color:#fff;">'
                + '<th style="padding:10px;text-align:left;">Batch No.</th>'
                + '<th style="padding:10px;text-align:right;">Received Qty</th>'
                + '<th style="padding:10px;text-align:right;">Remaining</th>'
                + '<th style="padding:10px;text-align:right;">Unit Cost</th>'
                + '<th style="padding:10px;text-align:right;">Selling Price</th>'
                + '<th style="padding:10px;">Date Received</th>'
                + '<th style="padding:10px;text-align:center;">Status</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
        })
        .catch(() => {
            document.getElementById('viewBatchesContent').innerHTML = '<div style="text-align:center;color:#dc2626;padding:20px;">Error loading batch data.</div>';
        });
}

// ══════════════════════════════════════════════════════════════════════════
// SERVICE FUNCTIONS — fully wired to new modals
// ══════════════════════════════════════════════════════════════════════════

// ── Shared state ─────────────────────────────────────────────────────────
var _svcOriginalFee   = 0;
var _svcOriginalLabor = 0;
var _viewSvcData      = null; // keeps current view data so "Edit from View" works

// ── Merchandise Products Filter (Search + Category + Brand + Unit + Supplier + Status) ─────
var _mgrMerchCache = null;
var _mgrCatHeaderCache = null;

function invalidateMgrMerchCache() {
    _mgrMerchCache = null;
    _mgrCatHeaderCache = null;
}

function getMgrMerchCache(forceReload) {
    if (_mgrMerchCache === null || forceReload) {
        var rows = document.querySelectorAll('#merchBody tr.merch-row');
        _mgrMerchCache = [];
        rows.forEach(function(row) {
            _mgrMerchCache.push({
                el: row,
                name: (row.getAttribute('data-name') || '').toLowerCase().trim(),
                sku: (row.getAttribute('data-sku') || '').toLowerCase().trim(),
                brand: (row.getAttribute('data-brand') || '').toLowerCase().trim(),
                unit: (row.getAttribute('data-unit') || '').toLowerCase().trim(),
                supplier: (row.getAttribute('data-supplier') || '').toLowerCase().trim(),
                catKey: (row.getAttribute('data-cat') || '').toLowerCase().trim(),
                status: (row.getAttribute('data-status') || '').toLowerCase().trim(),
                noprice: row.getAttribute('data-noprice') === '1'
            });
        });
    }

    if (_mgrCatHeaderCache === null || forceReload) {
        var headers = document.querySelectorAll('#merchBody tr.cat-row');
        _mgrCatHeaderCache = [];
        headers.forEach(function(hdr) {
            var rawCat = hdr.getAttribute('data-cat-header') || '';
            var countSpan = hdr.querySelector('.cat-count');
            _mgrCatHeaderCache.push({
                el: hdr,
                catKey: rawCat.toLowerCase().trim(),
                countSpan: countSpan
            });
        });
    }

    return { items: _mgrMerchCache, headers: _mgrCatHeaderCache };
}

function filterTable() {
    var searchEl   = document.getElementById('merchSearchInput');
    var catEl      = document.getElementById('catFilter');
    var brandEl    = document.getElementById('brandFilter');
    var unitEl     = document.getElementById('unitFilter');
    var supplierEl = document.getElementById('supplierFilter');
    var statusEl   = document.getElementById('statusFilter');

    var q        = searchEl ? searchEl.value.toLowerCase().trim() : '';
    var cat      = catEl ? catEl.value.toLowerCase().trim() : '';
    var brand    = brandEl ? brandEl.value.toLowerCase().trim() : '';
    var unit     = unitEl ? unitEl.value.toLowerCase().trim() : '';
    var supplier = supplierEl ? supplierEl.value.toLowerCase().trim() : '';
    var status   = statusEl ? statusEl.value.toLowerCase().trim() : '';

    var cacheData = getMgrMerchCache();
    var items = cacheData.items;
    var headers = cacheData.headers;

    var catVisibleCount = {};
    var totalVisible = 0;

    items.forEach(function(item) {
        var matchQ = !q || item.name.indexOf(q) !== -1 || item.sku.indexOf(q) !== -1 || item.brand.indexOf(q) !== -1 || item.catKey.indexOf(q) !== -1;
        var matchCat = !cat || item.catKey === cat;
        var matchBrand = !brand || item.brand === brand;
        var matchUnit = !unit || item.unit === unit;
        var matchSupplier = !supplier || item.supplier === supplier;
        
        var matchStatus = true;
        if (status) {
            if (status === 'noprice') {
                matchStatus = item.noprice;
            } else if (status === 'belowcost') {
                matchStatus = item.status === 'belowcost';
            } else {
                matchStatus = item.status === status;
            }
        }

        if (matchQ && matchCat && matchBrand && matchUnit && matchSupplier && matchStatus) {
            item.el.style.display = '';
            catVisibleCount[item.catKey] = (catVisibleCount[item.catKey] || 0) + 1;
            totalVisible++;
        } else {
            item.el.style.display = 'none';
        }
    });

    headers.forEach(function(hdr) {
        var count = catVisibleCount[hdr.catKey] || 0;
        if (count > 0) {
            hdr.el.style.display = '';
            if (hdr.countSpan) {
                hdr.countSpan.textContent = '(' + count + ' item' + (count === 1 ? '' : 's') + ')';
            }
        } else {
            hdr.el.style.display = 'none';
        }
    });

    var noRes = document.getElementById('merchNoResults');
    if (noRes) {
        noRes.style.display = (totalVisible === 0 && items.length > 0) ? 'block' : 'none';
    }
}

function resetMerchFilters() {
    ['merchSearchInput', 'catFilter', 'brandFilter', 'unitFilter', 'supplierFilter', 'statusFilter'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
    filterTable();
}

window.filterTable = filterTable;
window.resetMerchFilters = resetMerchFilters;
window.invalidateMgrMerchCache = invalidateMgrMerchCache;

// ── Fuel Products Filter (search + fuel type + status) ───────────────────
function filterFuelTable() {
    var q        = (document.getElementById('fuelSearchInput') || {}).value || '';
    var status   = (document.getElementById('fuelStatusFilter') || {}).value || '';
    var ftFilter = ((document.getElementById('fuelTypeFilter') || {}).value || '').toLowerCase().trim();
    q = q.toLowerCase().trim();

    var rows    = document.querySelectorAll('.fuel-row');
    var visible = 0;

    rows.forEach(function(row) {
        var ugt      = (row.getAttribute('data-ugt') || '').toLowerCase();
        var name     = (row.getAttribute('data-name') || '').toLowerCase();
        var fueltype = (row.getAttribute('data-fueltype') || '').toLowerCase().trim();
        var rSt      = (row.getAttribute('data-status') || '').trim();

        var matchQ  = !q || ugt.indexOf(q) !== -1 || name.indexOf(q) !== -1 || fueltype.indexOf(q) !== -1;
        var matchFt = !ftFilter || fueltype === ftFilter;
        var matchSt = !status || rSt.toLowerCase() === status.toLowerCase();

        if (matchQ && matchFt && matchSt) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });
}

// ── Filter (search + category + status) ──────────────────────────────────
function filterServiceTable() {
    var q       = (document.getElementById('svcSearchInput') || {}).value || '';
    var cat     = (document.getElementById('serviceCategoryFilter') || {}).value || '';
    var status  = (document.getElementById('svcStatusFilter') || {}).value || '';
    q = q.toLowerCase().trim();

    var rows    = document.querySelectorAll('#serviceTableBody .service-row');
    var visible = 0;

    rows.forEach(function(row) {
        var name   = row.getAttribute('data-name') || '';
        var rCat   = row.getAttribute('data-category') || '';
        var rAct   = row.getAttribute('data-active') || '';

        var matchQ   = !q   || name.indexOf(q) !== -1 || rCat.toLowerCase().indexOf(q) !== -1;
        var matchCat = !cat || rCat === cat;
        var matchSt  = !status || rAct === status;

        if (matchQ && matchCat && matchSt) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    var noRes = document.getElementById('svcNoResults');
    if (noRes) noRes.style.display = visible === 0 && rows.length > 0 ? 'block' : 'none';
}

// ── Service Category Professional Custom Dropdown ────────────────────────
var _svcPresetCategories = [
    'Lubrication',
    'Preventive Maintenance',
    'Engine Services',
    'Brake Services',
    'Tire Services',
    'Battery Services',
    'Cooling System',
    'Electrical Services',
    'Air Conditioning',
    'Undercarriage Services',
    'Cleaning Services',
    'Emergency Services',
    'Others'
];

var _svcActiveIndex = { add: -1, edit: -1 };

function _renderSvcCatItems(mode, query) {
    var drop = document.getElementById(mode === 'add' ? 'addSvcCatDrop' : 'editSvcCatDrop');
    if (!drop) return;

    query = (query || '').trim().toLowerCase();
    var filtered = query 
        ? _svcPresetCategories.filter(function(c) { return c.toLowerCase().indexOf(query) !== -1; })
        : _svcPresetCategories;

    _svcActiveIndex[mode] = -1;

    var html = '';
    if (filtered.length > 0) {
        filtered.forEach(function(cat, idx) {
            var esc = cat.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            html += '<div class="svc-cat-opt" data-index="' + idx + '" data-value="' + esc + '" ' +
                'onmousedown="selectSvcCat(\'' + mode + '\', \'' + esc + '\')" ' +
                'style="padding:10px 16px;cursor:pointer;font-size:14.5px;color:#1e293b;border-bottom:1px solid #f1f5f9;transition:background 0.12s, color 0.12s;" ' +
                'onmouseover="this.style.background=\'#f0f7ff\';this.style.color=\'#002F6C\';this.style.fontWeight=\'600\';" ' +
                'onmouseout="this.style.background=\'\';this.style.color=\'#1e293b\';this.style.fontWeight=\'500\';">' +
                cat +
                '</div>';
        });
    }

    if (query) {
        var exactMatch = _svcPresetCategories.some(function(c) { return c.toLowerCase() === query; });
        if (!exactMatch) {
            var escQuery = query.replace(/</g, '&lt;').replace(/>/g, '&gt;');
            html += '<div style="padding:10px 14px;font-size:13px;color:#0284c7;background:#f0f9ff;display:flex;align-items:center;">' +
                '<i class="fas fa-pencil-alt" style="margin-right:8px;font-size:11.5px;"></i>' +
                '<span>Using custom category: <strong>' + escQuery + '</strong></span>' +
                '</div>';
        }
    }

    drop.innerHTML = html;
    drop.style.display = 'block';
}

function showSvcCatDrop(mode) {
    var inp = document.getElementById(mode === 'add' ? 'addSvcCategory' : 'editSvcCategory');
    var chevron = document.getElementById(mode === 'add' ? 'addSvcCatChevron' : 'editSvcCatChevron');
    if (inp) inp.style.borderColor = '#002F6C';
    if (chevron) chevron.style.transform = 'rotate(180deg)';
    _renderSvcCatItems(mode, inp ? inp.value : '');
}

function filterSvcCatDrop(mode, val) {
    showSvcCatDrop(mode);
}

function hideSvcCatDrop(mode) {
    var drop = document.getElementById(mode === 'add' ? 'addSvcCatDrop' : 'editSvcCatDrop');
    var inp = document.getElementById(mode === 'add' ? 'addSvcCategory' : 'editSvcCategory');
    var chevron = document.getElementById(mode === 'add' ? 'addSvcCatChevron' : 'editSvcCatChevron');
    if (drop) drop.style.display = 'none';
    if (inp) inp.style.borderColor = '#d1d5db';
    if (chevron) chevron.style.transform = 'rotate(0deg)';
}

function toggleSvcCatDrop(mode) {
    var drop = document.getElementById(mode === 'add' ? 'addSvcCatDrop' : 'editSvcCatDrop');
    var inp = document.getElementById(mode === 'add' ? 'addSvcCategory' : 'editSvcCategory');
    if (!drop) return;
    if (drop.style.display === 'none' || !drop.style.display) {
        if (inp) inp.focus();
        showSvcCatDrop(mode);
    } else {
        hideSvcCatDrop(mode);
    }
}

function selectSvcCat(mode, val) {
    var inp = document.getElementById(mode === 'add' ? 'addSvcCategory' : 'editSvcCategory');
    if (inp) inp.value = val;
    hideSvcCatDrop(mode);
}

function handleSvcCatKey(e, mode) {
    var drop = document.getElementById(mode === 'add' ? 'addSvcCatDrop' : 'editSvcCatDrop');
    if (!drop || drop.style.display === 'none') {
        if (e.key === 'ArrowDown') {
            showSvcCatDrop(mode);
            e.preventDefault();
        }
        return;
    }
    var items = drop.querySelectorAll('.svc-cat-opt');
    if (!items.length) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        _svcActiveIndex[mode] = Math.min(_svcActiveIndex[mode] + 1, items.length - 1);
        _highlightSvcCatItem(items, _svcActiveIndex[mode]);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        _svcActiveIndex[mode] = Math.max(_svcActiveIndex[mode] - 1, 0);
        _highlightSvcCatItem(items, _svcActiveIndex[mode]);
    } else if (e.key === 'Enter') {
        if (_svcActiveIndex[mode] >= 0 && items[_svcActiveIndex[mode]]) {
            e.preventDefault();
            var val = items[_svcActiveIndex[mode]].getAttribute('data-value');
            if (val) selectSvcCat(mode, val);
        } else {
            hideSvcCatDrop(mode);
        }
    } else if (e.key === 'Escape') {
        hideSvcCatDrop(mode);
    }
}

function _highlightSvcCatItem(items, activeIdx) {
    items.forEach(function(el, i) {
        if (i === activeIdx) {
            el.style.background = '#f0f7ff';
            el.style.color = '#002F6C';
            el.style.fontWeight = '600';
            el.scrollIntoView({ block: 'nearest' });
        } else {
            el.style.background = '';
            el.style.color = '#1e293b';
            el.style.fontWeight = '500';
        }
    });
}

// Global click-outside listener to close dropdowns
document.addEventListener('click', function(e) {
    var addBox = document.getElementById('addSvcCatContainer');
    if (addBox && !addBox.contains(e.target)) {
        hideSvcCatDrop('add');
    }
    var editBox = document.getElementById('editSvcCatContainer');
    if (editBox && !editBox.contains(e.target)) {
        hideSvcCatDrop('edit');
    }
});

// ── ADD SERVICE MODAL ─────────────────────────────────────────────────────
function openAddServiceModal() {
    var modal = document.getElementById('addServiceModal');
    if (!modal) return;
    document.getElementById('addServiceForm').reset();
    // Explicitly clear all fields for clean manual input
    var nameEl = document.getElementById('addSvcName');
    if (nameEl) nameEl.value = '';
    var catEl = document.getElementById('addSvcCategory');
    if (catEl) catEl.value = '';
    var mechEl = document.getElementById('addSvcMechanics');
    if (mechEl) mechEl.value = '';
    var feeEl = document.getElementById('addSvcServiceFee');
    if (feeEl) feeEl.value = '';
    var laborEl = document.getElementById('addSvcLaborFee');
    if (laborEl) laborEl.value = '';
    var descEl = document.getElementById('addSvcDescription');
    if (descEl) descEl.value = '';

    hideSvcCatDrop('add');
    modal.style.display = 'flex';
    var f = document.getElementById('addSvcName');
    if (f) setTimeout(function() { f.focus(); }, 80);
}

function closeAddServiceModal() {
    var modal = document.getElementById('addServiceModal');
    if (modal) modal.style.display = 'none';
    var form = document.getElementById('addServiceForm');
    if (form) form.reset();
    var catEl = document.getElementById('addSvcCategory');
    if (catEl) catEl.value = '';
    var mechEl = document.getElementById('addSvcMechanics');
    if (mechEl) mechEl.value = '';
    hideSvcCatDrop('add');
}

safeAddListener('addServiceForm', 'submit', function(e) {
    e.preventDefault();

    var name     = (document.getElementById('addSvcName')        || {}).value || '';
    var category = (document.getElementById('addSvcCategory')    || {}).value || '';

    var svcFee   = parseFloat((document.getElementById('addSvcServiceFee') || {}).value);
    var laborFee = parseFloat((document.getElementById('addSvcLaborFee')    || {}).value);
    if (isNaN(svcFee))   svcFee   = 0;
    if (isNaN(laborFee)) laborFee = 0;

    var mechsVal    = ((document.getElementById('addSvcMechanics') || {}).value || '').trim();
    var mechs       = (mechsVal !== '' && !isNaN(parseInt(mechsVal))) ? parseInt(mechsVal) : null;
    var desc        = ((document.getElementById('addSvcDescription') || {}).value || '').trim();

    name     = name.trim();
    category = category.trim();

    var placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];
    if (!name || placeholders.includes(name.toLowerCase())) {
        showCustomAlert('Service Name is required and cannot be N/A or a placeholder.', 'error');
        document.getElementById('addSvcName').focus();
        return;
    }
    if (!category || placeholders.includes(category.toLowerCase())) {
        showCustomAlert('Category is required and cannot be N/A or a placeholder.', 'error');
        document.getElementById('addSvcCategory').focus();
        return;
    }
    if (svcFee < 0 || laborFee < 0) {
        showCustomAlert('Fees cannot be negative.', 'error');
        return;
    }

    var btn = e.target.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }

    var fd = new FormData();
    fd.append('action',             'add_service');
    fd.append('service_name',       name);
    fd.append('category',           category);
    fd.append('service_price',      svcFee);
    fd.append('labor_fee',          laborFee);
    fd.append('required_mechanics', mechs    !== null ? mechs    : '');
    fd.append('description',        desc);

    fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            closeAddServiceModal();
            showCustomAlert(data.message || 'Service added successfully!', 'success', function() {
                location.reload();
            });
        } else {
            showCustomAlert(data.message || 'Failed to add service.', 'error');
        }
    })
    .catch(function() {
        showCustomAlert('Network error. Please try again.', 'error');
    })
    .finally(function() {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle"></i> Add Service'; }
    });
});

// ── EDIT SERVICE MODAL ────────────────────────────────────────────────────
function openEditServiceModal(svc) {
    if (typeof svc === 'string') { try { svc = JSON.parse(svc); } catch(e) { return; } }

    _svcOriginalFee   = parseFloat(svc.service_price) || 0;
    _svcOriginalLabor = parseFloat(svc.labor_fee)     || 0;

    document.getElementById('editSvcId').value           = svc.id        || '';
    document.getElementById('editSvcName').value         = svc.service_name || '';

    // Set category directly into the text input
    var catVal = svc.category || '';
    var catInput = document.getElementById('editSvcCategory');
    if (catInput) catInput.value = catVal;
    hideSvcCatDrop('edit');

    document.getElementById('editSvcServiceFee').value   = _svcOriginalFee.toFixed(2);
    document.getElementById('editSvcLaborFee').value     = _svcOriginalLabor.toFixed(2);
    var mechVal = (svc.required_mechanics  !== null && svc.required_mechanics  !== undefined && svc.required_mechanics  !== '') ? svc.required_mechanics  : '';
    document.getElementById('editSvcMechanics').value    = mechVal;
    document.getElementById('editSvcDescription').value  = svc.description || '';
    document.getElementById('editSvcActive').value       = svc.active ? '1' : '0';

    var codeEl = document.getElementById('editSvcCodeDisplay');
    if (codeEl) codeEl.textContent = svc.service_code ? 'Code: ' + svc.service_code : '';

    // Hide approval notice initially
    var notice = document.getElementById('editSvcApprovalNotice');
    if (notice) notice.style.display = 'none';

    document.getElementById('editServiceModal').style.display = 'flex';
    var f = document.getElementById('editSvcName');
    if (f) setTimeout(function() { f.focus(); }, 80);
}

function closeEditServiceModal() {
    var modal = document.getElementById('editServiceModal');
    if (modal) modal.style.display = 'none';
    hideSvcCatDrop('edit');
}

// Show approval notice if fee fields changed
function checkSvcFeeChange() {
    var newSvc   = parseFloat((document.getElementById('editSvcServiceFee') || {}).value) || 0;
    var newLabor = parseFloat((document.getElementById('editSvcLaborFee')   || {}).value) || 0;
    var changed  = Math.abs(newSvc - _svcOriginalFee) > 0.001 || Math.abs(newLabor - _svcOriginalLabor) > 0.001;
    var notice   = document.getElementById('editSvcApprovalNotice');
    if (notice) notice.style.display = changed ? 'flex' : 'none';
}

safeAddListener('editServiceForm', 'submit', function(e) {
    e.preventDefault();

    var id       = document.getElementById('editSvcId').value;
    var name     = document.getElementById('editSvcName').value.trim();
    var category = document.getElementById('editSvcCategory').value.trim();

    var svcFee      = parseFloat(document.getElementById('editSvcServiceFee').value) || 0;
    var laborFee    = parseFloat(document.getElementById('editSvcLaborFee').value)   || 0;
    var mechsVal    = ((document.getElementById('editSvcMechanics') || {}).value || '').trim();
    var mechs       = (mechsVal !== '' && !isNaN(parseInt(mechsVal))) ? parseInt(mechsVal) : null;
    var desc        = document.getElementById('editSvcDescription').value || '';
    var active   = document.getElementById('editSvcActive').value;

    var placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];
    if (!name || placeholders.includes(name.toLowerCase())) {
        showCustomAlert('Service Name is required and cannot be N/A or a placeholder.', 'error');
        document.getElementById('editSvcName').focus();
        return;
    }
    if (!category || placeholders.includes(category.toLowerCase())) {
        showCustomAlert('Category is required and cannot be N/A or a placeholder.', 'error');
        document.getElementById('editSvcCategory').focus();
        return;
    }

    var btn = e.target.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }

    var fd = new FormData();
    fd.append('action',             'edit_service_full');
    fd.append('id',                 id);
    fd.append('service_name',       name);
    fd.append('category',           category);
    fd.append('service_price',      svcFee);
    fd.append('labor_fee',          laborFee);
    fd.append('required_mechanics', mechs    !== null ? mechs    : '');
    fd.append('description',        desc);
    fd.append('active',             active);

    fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            closeEditServiceModal();
            showCustomAlert(data.message || 'Service updated successfully!', 'success', function() {
                location.reload();
            });
        } else {
            showCustomAlert(data.message || 'Failed to update service.', 'error');
        }
    })
    .catch(function() {
        showCustomAlert('Network error. Please try again.', 'error');
    })
    .finally(function() {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Changes'; }
    });
});

// ── VIEW SERVICE MODAL ────────────────────────────────────────────────────
function openViewServiceModal(svc) {
    if (typeof svc === 'string') { try { svc = JSON.parse(svc); } catch(e) { return; } }
    _viewSvcData = svc;

    var svcFee   = parseFloat(svc.service_price) || 0;
    var laborFee = parseFloat(svc.labor_fee)     || 0;
    var total    = svcFee + laborFee;

    // Populate
    document.getElementById('viewSvcCodeDisplay').textContent  = svc.service_code ? 'Code: ' + svc.service_code : '';
    document.getElementById('viewSvcName').textContent         = svc.service_name || '';
    document.getElementById('viewSvcDesc').textContent         = svc.description  || '';
    document.getElementById('viewSvcCategory').textContent     = svc.category     || '—';
    document.getElementById('viewSvcServiceFee').textContent   = '₱' + svcFee.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('viewSvcLaborFee').textContent     = '₱' + laborFee.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('viewSvcTotalFee').textContent     = '₱' + total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    var hasMech = svc.required_mechanics !== null && svc.required_mechanics !== undefined && svc.required_mechanics !== '';
    document.getElementById('viewSvcMechanics').textContent    = hasMech ? (parseInt(svc.required_mechanics) + ' mechanic(s)') : 'N/A';

    var statusEl = document.getElementById('viewSvcStatus');
    if (statusEl) {
        statusEl.innerHTML = svc.active
            ? '<span style="background:#dcfce7;color:#15803d;padding:5px 14px;border-radius:999px;font-size:14.5px;font-weight:700;">Active</span>'
            : '<span style="background:#fee2e2;color:#b91c1c;padding:5px 14px;border-radius:999px;font-size:14.5px;font-weight:700;">Inactive</span>';
    }

    // Open modal
    document.getElementById('viewServiceModal').style.display = 'flex';

    // Load history async
    var histEl = document.getElementById('viewSvcHistory');
    if (histEl) histEl.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px;"><i class="fas fa-spinner fa-spin" style="font-size:20px;"></i></div>';

    fetch('manager_set_prices_handler.php?action=get_service_history&id=' + encodeURIComponent(svc.id))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!histEl) return;
        if (!data.success || !data.history || data.history.length === 0) {
            histEl.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:16px;font-size:15.5px;"><i class="fas fa-history" style="font-size:22px;display:block;margin-bottom:8px;"></i>No fee change history yet.</div>';
            return;
        }
        var rows = data.history.map(function(h) {
            var statusBadge = {
                'pending':  '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:999px;font-size:15.5px;font-weight:700;">Pending</span>',
                'approved': '<span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:999px;font-size:15.5px;font-weight:700;">Approved</span>',
                'rejected': '<span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:999px;font-size:15.5px;font-weight:700;">Rejected</span>',
                'direct':   '<span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:999px;font-size:15.5px;font-weight:700;">Direct</span>',
            }[h.approval_status] || '<span style="color:#64748b;font-size:15.5px;">' + escHtml(h.approval_status || '') + '</span>';

            var changeType = {
                'service_fee': '<i class="fas fa-tag" style="color:#002F6C;"></i> Service Fee',
                'labor_fee':   '<i class="fas fa-hammer" style="color:#0369a1;"></i> Labor Fee',
                'created':     '<i class="fas fa-plus-circle" style="color:#16a34a;"></i> Created',
                'updated':     '<i class="fas fa-edit" style="color:#d97706;"></i> Updated',
                'activated':   '<i class="fas fa-check-circle" style="color:#16a34a;"></i> Activated',
                'deactivated': '<i class="fas fa-ban" style="color:#dc2626;"></i> Deactivated',
            }[h.change_type] || '<i class="fas fa-history"></i> ' + escHtml(h.change_type || '');

            var oldNew = '';
            if (h.old_service_fee != null && h.new_service_fee != null) {
                oldNew += '<div style="font-size:14px;color:#64748b;">Svc Fee: <span style="text-decoration:line-through;color:#dc2626;">₱' + parseFloat(h.old_service_fee).toFixed(2) + '</span> → <strong style="color:#16a34a;">₱' + parseFloat(h.new_service_fee).toFixed(2) + '</strong></div>';
            }
            if (h.old_labor_fee != null && h.new_labor_fee != null) {
                oldNew += '<div style="font-size:14px;color:#64748b;">Labor Fee: <span style="text-decoration:line-through;color:#dc2626;">₱' + parseFloat(h.old_labor_fee).toFixed(2) + '</span> → <strong style="color:#16a34a;">₱' + parseFloat(h.new_labor_fee).toFixed(2) + '</strong></div>';
            }

            var who   = h.changed_by_name || ((h.first_name || '') + ' ' + (h.last_name || '')).trim() || 'System';
            var when  = h.created_at ? new Date(h.created_at).toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'}) : '';

            return '<div style="padding:10px 0;border-bottom:1px solid #f1f5f9;display:flex;gap:12px;align-items:flex-start;">'
                + '<div style="flex:1;">'
                    + '<div style="font-size:14.5px;font-weight:600;color:#334155;display:flex;align-items:center;gap:6px;">' + changeType + '</div>'
                    + (oldNew ? '<div style="margin-top:4px;">' + oldNew + '</div>' : '')
                    + (h.notes ? '<div style="font-size:14px;color:#94a3b8;margin-top:3px;">' + escHtml(h.notes) + '</div>' : '')
                + '</div>'
                + '<div style="text-align:right;flex-shrink:0;">'
                    + statusBadge
                    + '<div style="font-size:15.5px;color:#94a3b8;margin-top:3px;">' + escHtml(who) + '</div>'
                    + '<div style="font-size:15.5px;color:#cbd5e1;">' + escHtml(when) + '</div>'
                + '</div>'
                + '</div>';
        }).join('');
        histEl.innerHTML = '<div style="max-height:240px;overflow-y:auto;padding-right:4px;">' + rows + '</div>';
    })
    .catch(function() {
        if (histEl) histEl.innerHTML = '<div style="text-align:center;color:#dc2626;padding:16px;font-size:15.5px;">Failed to load history.</div>';
    });
}

function closeViewServiceModal() {
    var modal = document.getElementById('viewServiceModal');
    if (modal) modal.style.display = 'none';
    _viewSvcData = null;
}

function editServiceFromView() {
    if (!_viewSvcData) return;
    closeViewServiceModal();
    setTimeout(function() { openEditServiceModal(_viewSvcData); }, 80);
}

// ── DEACTIVATE / ACTIVATE ─────────────────────────────────────────────────
function deactivateService(id, serviceName) {
    showConfirmModal(
        'Deactivate Service',
        'Confirm deactivation',
        'Are you sure you want to deactivate "' + serviceName + '"?\n\nThis will mark the service as inactive.',
        function(payload) {
            var fd = new FormData();
            fd.append('action', 'deactivate_service');
            fd.append('id', payload.id);
            fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showCustomAlert('Service deactivated successfully!', 'success', function() { location.reload(); });
                } else {
                    showCustomAlert(data.message || 'Failed to deactivate service.', 'error');
                }
            })
            .catch(function() { showCustomAlert('Network error. Please try again.', 'error'); });
        },
        { id: id, serviceName: serviceName }
    );
}

function activateService(id, serviceName) {
    showConfirmModal(
        'Activate Service',
        'Confirm activation',
        'Are you sure you want to activate "' + serviceName + '"?\n\nThis will mark the service as active.',
        function(payload) {
            var fd = new FormData();
            fd.append('action', 'activate_service');
            fd.append('id', payload.id);
            fetch('manager_set_prices_handler.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showCustomAlert('Service activated successfully!', 'success', function() { location.reload(); });
                } else {
                    showCustomAlert(data.message || 'Failed to activate service.', 'error');
                }
            })
            .catch(function() { showCustomAlert('Network error. Please try again.', 'error'); });
        },
        { id: id, serviceName: serviceName }
    );
}

/* ── Auto-open product modal when navigated from global search ── */
document.addEventListener('DOMContentLoaded', function () {
    // Ensure absolutely no filter Reset button exists anywhere in the DOM
    document.querySelectorAll('button').forEach(function(b) {
        var oc = (b.getAttribute('onclick') || '').toLowerCase();
        var txt = (b.textContent || '').trim().toLowerCase();
        var title = (b.getAttribute('title') || '').toLowerCase();
        if ((oc.includes('reset') && !oc.includes('close')) || txt === 'reset' || title.includes('reset filter')) {
            b.remove();
        }
    });

    var urlParams = new URLSearchParams(window.location.search);
    var pid       = parseInt(urlParams.get('product_id') || '0', 10);
    var searchQ   = urlParams.get('search') || '';
    var autoOpen  = urlParams.get('auto_open') === '1';

    if (!autoOpen || !pid) return;

    // Strip auto-open params from URL immediately so refresh won't re-trigger
    (function() {
        var clean = new URLSearchParams(window.location.search);
        ['auto_open','product_id','pid','search_query','search'].forEach(function(k){ clean.delete(k); });
        var newUrl = window.location.pathname + (clean.toString() ? '?' + clean.toString() : '');
        history.replaceState(null, '', newUrl);
    })();

    /* 1. Pre-fill the merchandise search input */
    var searchEl = document.getElementById('merchSearchInput') || document.getElementById('searchInput');
    if (searchEl && searchQ) {
        searchEl.value = searchQ;
        if (typeof window.filterTable === 'function') {
            window.filterTable();
        }
    }

    /* 2. After short delay (allow table render), find row, highlight only — no modal */
    setTimeout(function () {
        var tbody = document.getElementById('merchBody') || document.querySelector('#merch-tab-content table tbody');
        if (!tbody) return;

        var targetRow = tbody.querySelector('tr.merch-row[data-id="' + pid + '"]');
        if (targetRow) {
            // Scroll smoothly to row and highlight it (no modal auto-open)
            targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });

            var origOutline    = targetRow.style.outline;
            var origBackground = targetRow.style.background;
            targetRow.style.outline    = '3px solid #2563eb';
            targetRow.style.background = '#eff6ff';
            setTimeout(function () {
                targetRow.style.outline    = origOutline;
                targetRow.style.background = origBackground;
            }, 2500);
        }
    }, 400);
});
</script>


<!-- Custom Status/Notification Modal Dialog (Replaces native browser alert popups) -->
<div id="statusNotificationModal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,.65);z-index:10001;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:440px;max-height:calc(100% - 20px);box-shadow:0 20px 50px rgba(0,0,0,.4);margin:auto;overflow:hidden;text-align:center;animation:statusPopIn .2s ease-out;">
    <div id="statusNotificationHeader" style="padding:22px 20px 14px 20px;background:#f0fdf4;">
      <div id="statusNotificationIcon" style="width:54px;height:54px;border-radius:50%;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;margin:0 auto 12px auto;font-size:24px;">
        <i class="fas fa-check-circle"></i>
      </div>
      <h3 id="statusNotificationTitle" style="margin:0;font-size:17px;font-weight:800;color:#166534;">Success</h3>
    </div>
    <div style="padding:16px 22px 22px 22px;">
      <p id="statusNotificationMessage" style="margin:0 0 20px 0;font-size:15.5px;color:#334155;line-height:1.5;font-weight:500;">
        Action completed successfully.
      </p>
      <button type="button" id="statusNotificationBtn" onclick="closeStatusNotificationModal()" style="background:#002F6C;color:#ffffff;border:none;padding:10px 28px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;box-shadow:0 4px 12px rgba(0,47,108,0.25);width:100%;transition:all 0.15s ease;">
        OK
      </button>
    </div>
  </div>
</div>
<style>
@keyframes statusPopIn {
    from { opacity: 0; transform: scale(0.92); }
    to { opacity: 1; transform: scale(1); }
}

/* ── Ensure modal header titles are strictly left-aligned across all modals ── */
#viewFuelModal > div > div:first-child,
#viewMerchModal > div > div:first-child,
#viewServiceModal > div > div:first-child,
#viewBatchesModal > div > div:first-child {
    justify-content: flex-start !important;
    text-align: left !important;
}
#viewFuelModal h3,
#viewMerchModal h3,
#viewServiceModal h3,
#viewBatchesModal h3,
[id*="Modal"] .modal-header h3,
[id*="Modal"] .modal-header h4 {
    text-align: left !important;
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
