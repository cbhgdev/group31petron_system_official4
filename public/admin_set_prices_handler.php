<?php
// Force browser to always load fresh — prevents stale CSS/JS cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if ($role === 'superadmin') {
    $req_station_id = (int)($_POST['station_id'] ?? ($_GET['station_id'] ?? 0));
    if ($req_station_id > 0) {
        $station_id = $req_station_id;
    }
} else {
    $station_id = (int)user_station_id();
}

if (!in_array($role, ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Admin permission required']);
    exit;
}

// Helper functions to normalize fuel names and find all tanks for the same fuel type
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

if (!function_exists('get_matching_fuel_ids')) {
    function get_matching_fuel_ids($pdo, $station_id, $fuel_id, $raw_fuel_type = '') {
        if (empty($raw_fuel_type) && $fuel_id > 0) {
            $stmt = $pdo->prepare("SELECT fuel_type FROM fuel_inventory WHERE id = ? LIMIT 1");
            $stmt->execute([$fuel_id]);
            $raw_fuel_type = $stmt->fetchColumn() ?: '';
        }
        $canonical = get_canonical_fuel_name($raw_fuel_type);
        $ids = [];
        $stmt = $pdo->prepare("SELECT id, fuel_type FROM fuel_inventory WHERE station_id = ?");
        $stmt->execute([$station_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['id'] == $fuel_id || 
                get_canonical_fuel_name($row['fuel_type']) === $canonical || 
                strcasecmp($row['fuel_type'], $raw_fuel_type) === 0) {
                $ids[] = (int)$row['id'];
            }
        }
        if (empty($ids) && $fuel_id > 0) {
            $ids = [(int)$fuel_id];
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('sanitize_optional_field')) {
    function sanitize_optional_field(?string $val): string {
        if ($val === null) return 'N/A';
        $trimmed = trim($val);
        if ($trimmed === '') return 'N/A';
        $lower = strtolower($trimmed);
        $invalid_placeholders = ['none', 'null', 'n/a', '-', 'unknown', 'not available', 'not_available', 'undefined', 'n.a.', 'n/a.'];
        if (in_array($lower, $invalid_placeholders, true)) {
            return 'N/A';
        }
        return $trimmed;
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

        return $pumps;
    }
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        // ══════════════════════════════════════════════════════════════════════
        // ADD FUEL PRODUCT (ADMIN DIRECT CREATION)
        // ══════════════════════════════════════════════════════════════════════
        case 'add_fuel_product':
            $fuel_type      = trim($_POST['fuel_type'] ?? '');
            $ugt_no         = trim($_POST['ugt_no'] ?? '');
            if (preg_match('/^\d+$/', $ugt_no)) {
                $ugt_no = sprintf('UGT-%02d', (int)$ugt_no);
            } elseif (preg_match('/^ugt\s*[-#]?\s*(\d+)$/i', $ugt_no, $m)) {
                $ugt_no = sprintf('UGT-%02d', (int)$m[1]);
            }
            $price          = (float)($_POST['price'] ?? 0);
            $capacity       = (float)($_POST['capacity'] ?? 0);
            $critical_level = (float)($_POST['critical_level'] ?? 0);
            $reorder_level  = (float)($_POST['reorder_level'] ?? 0);
            $current_volume = max(0.0, (float)($_POST['current_volume'] ?? 0));
            $status         = strtolower(trim($_POST['status'] ?? 'active'));
            $remarks        = trim($_POST['remarks'] ?? '');

            if ($status !== 'inactive') $status = 'active';

            if ((int)$station_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'No station assigned to your account. Please contact Super Admin.']);
                exit;
            }

            // Validation rules
            if (empty($fuel_type)) {
                echo json_encode(['success' => false, 'message' => 'Fuel Name is required.']);
                exit;
            }
            if (mb_strlen($fuel_type) > 50) {
                echo json_encode(['success' => false, 'message' => 'Fuel Name must not exceed 50 characters.']);
                exit;
            }
            if (empty($ugt_no)) {
                echo json_encode(['success' => false, 'message' => 'UGT Number is required.']);
                exit;
            }
            if ($price <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid Selling Price.']);
                exit;
            }
            if ($capacity <= 0) {
                echo json_encode(['success' => false, 'message' => 'Tank Capacity must be greater than 0.']);
                exit;
            }
            if ($capacity <= $reorder_level) {
                echo json_encode(['success' => false, 'message' => 'Tank Capacity must be greater than Reorder Level.']);
                exit;
            }
            if ($reorder_level <= $critical_level && $critical_level > 0) {
                echo json_encode(['success' => false, 'message' => 'Reorder Level must be greater than Critical Level.']);
                exit;
            }

            $num_pumps_raw = $_POST['num_pumps'] ?? null;
            if ($num_pumps_raw === null || $num_pumps_raw === '') {
                echo json_encode(['success' => false, 'message' => 'Please enter the Number of Pumps.']);
                exit;
            }
            $num_pumps = max(0, (int)$num_pumps_raw);

            // Server-side duplicate-submit debounce protection
            $sub_key = 'last_add_fuel_' . md5($station_id . '|' . strtolower($fuel_type) . '|' . strtolower($ugt_no));
            $last_sub_time = (float)($_SESSION[$sub_key] ?? 0);
            if (microtime(true) - $last_sub_time < 3.0) {
                echo json_encode(['success' => false, 'message' => 'This fuel product already exists for this station.']);
                exit;
            }
            $_SESSION[$sub_key] = microtime(true);

            // Transactional duplicate validation & atomic single-record creation
            try {
                $pdo->beginTransaction();

                // Re-check with row-level lock: ensure no fuel product or UGT exists for this station
                $chk_dup = $pdo->prepare("SELECT id, fuel_type, ugt_no FROM fuel_inventory WHERE station_id = ? AND (LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) OR LOWER(TRIM(ugt_no)) = LOWER(TRIM(?))) LIMIT 1 FOR UPDATE");
                $chk_dup->execute([$station_id, $fuel_type, $ugt_no]);
                $existing_row = $chk_dup->fetch(PDO::FETCH_ASSOC);
                if ($existing_row) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'This fuel product already exists for this station.']);
                    exit;
                }

                // Resolve fuel_type_id from fuel_types table
                $fuel_type_id = 0;
                $ft = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $ft->execute([$fuel_type]);
                $ft_row = $ft->fetch(PDO::FETCH_ASSOC);
                if ($ft_row) {
                    $fuel_type_id = (int)$ft_row['id'];
                    try {
                        $pdo->prepare("UPDATE fuel_types SET price_per_liter = ? WHERE id = ?")->execute([$price, $fuel_type_id]);
                    } catch (Exception $e_ft_up) {}
                } else {
                    try {
                        $ins_ft = $pdo->prepare("INSERT INTO fuel_types (name, price_per_liter) VALUES (?, ?)");
                        $ins_ft->execute([$fuel_type, $price]);
                        $fuel_type_id = (int)$pdo->lastInsertId();
                    } catch (Exception $e_ft_ins) {
                        $ins_ft = $pdo->prepare("INSERT INTO fuel_types (name) VALUES (?)");
                        $ins_ft->execute([$fuel_type]);
                        $fuel_type_id = (int)$pdo->lastInsertId();
                    }
                }

                // Insert EXACTLY ONE master fuel product record into fuel_inventory
                // Cap current_volume to capacity (server-side safety)
                if ($capacity > 0 && $current_volume > $capacity) {
                    $current_volume = $capacity;
                }
                $stmt = $pdo->prepare("
                    INSERT INTO fuel_inventory
                    (station_id, fuel_type_id, fuel_type, ugt_no, price_per_liter, capacity, critical_level, reorder_level,
                     current_level, current_stock, status, num_pumps, updated_by, last_updated)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $station_id,
                    $fuel_type_id,
                    $fuel_type,
                    $ugt_no,
                    $price,
                    $capacity,
                    $critical_level,
                    $reorder_level,
                    $current_volume,
                    $current_volume,
                    $status,
                    $num_pumps,
                    $me['id']
                ]);
                $new_fuel_id = (int)$pdo->lastInsertId();

                // ── Station-Specific Child Pump & Nozzle Creation (ONLY child records, never new product records) ──
                $pump_configs_raw = $_POST['pump_configs'] ?? '[]';
                $pump_configs = json_decode($pump_configs_raw, true) ?: [];

                if ($num_pumps > 0 && $station_id > 0) {
                    $clean_fuel_tag = strtoupper(trim($fuel_type));
                    for ($pi = 1; $pi <= $num_pumps; $pi++) {
                        $p_cfg = $pump_configs[$pi - 1] ?? [];
                        $p_status = ucfirst(strtolower($p_cfg['status'] ?? 'Active'));
                        if (!in_array($p_status, ['Active', 'Inactive', 'Maintenance'])) {
                            $p_status = 'Active';
                        }
                        $pump_num = "{$clean_fuel_tag} - {$pi}";
                        $pump_name = "Pump {$pi}";
                        $nozzle_num = "Nozzle {$pi}";
                        
                        // Check if pump exists for this station and tank with this specific pump_number
                        $chk_pump = $pdo->prepare("SELECT id FROM fuel_pumps WHERE station_id = ? AND tank_id = ? AND pump_number = ? LIMIT 1");
                        $chk_pump->execute([$station_id, $new_fuel_id, $pump_num]);
                        $existing_pump_id = (int)$chk_pump->fetchColumn();
                        
                        if (!$existing_pump_id) {
                            $chk_unlinked = $pdo->prepare("SELECT id FROM fuel_pumps WHERE station_id = ? AND pump_number = ? AND (tank_id IS NULL OR tank_id = 0) LIMIT 1");
                            $chk_unlinked->execute([$station_id, $pump_num]);
                            $existing_pump_id = (int)$chk_unlinked->fetchColumn();
                        }

                        if (!$existing_pump_id) {
                            $ins_pump = $pdo->prepare("
                                INSERT INTO fuel_pumps (station_id, tank_id, pump_number, pump_name, nozzle_number, fuel_type_id, ugt_no, capacity, status, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $ins_pump->execute([$station_id, $new_fuel_id, $pump_num, $pump_name, $nozzle_num, $fuel_type_id, $ugt_no, $capacity, $p_status]);
                            $existing_pump_id = (int)$pdo->lastInsertId();
                        } else {
                            $pdo->prepare("UPDATE fuel_pumps SET tank_id = ?, pump_name = ?, nozzle_number = ?, fuel_type_id = ?, ugt_no = ?, status = ? WHERE id = ?")
                                ->execute([$new_fuel_id, $pump_name, $nozzle_num, $fuel_type_id, $ugt_no, $p_status, $existing_pump_id]);
                        }

                        // Sync into nozzles table
                        try {
                            $chk_noz = $pdo->prepare("SELECT id FROM nozzles WHERE station_id = ? AND pump_id = ? LIMIT 1");
                            $chk_noz->execute([$station_id, $existing_pump_id]);
                            $noz_id = (int)$chk_noz->fetchColumn();
                            if (!$noz_id) {
                                $pdo->prepare("
                                    INSERT INTO nozzles (station_id, pump_id, pump_name, nozzle_number, fuel_type_id, ugt_no, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                                ")->execute([$station_id, $existing_pump_id, $pump_name, $nozzle_num, $fuel_type_id, $ugt_no, $p_status]);
                            } else {
                                $pdo->prepare("UPDATE nozzles SET pump_name = ?, nozzle_number = ?, fuel_type_id = ?, ugt_no = ?, status = ? WHERE id = ?")
                                    ->execute([$pump_name, $nozzle_num, $fuel_type_id, $ugt_no, $p_status, $noz_id]);
                            }
                        } catch (Exception $e) {}
                    }
                }

                // Sync to inventory_products for POS/Inventory
                try {
                    $chk_ip = $pdo->prepare("SELECT id FROM inventory_products WHERE station_id = ? AND LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND LOWER(COALESCE(category,'')) IN ('fuel', 'fuel products') LIMIT 1");
                    $chk_ip->execute([$station_id, $fuel_type]);
                    $ip_id = $chk_ip->fetchColumn();
                    if (!$ip_id) {
                        $pdo->prepare("INSERT INTO inventory_products (station_id, product_name, category, unit_cost, unit_price, stock, status, created_at) VALUES (?, ?, 'Fuel', ?, ?, 0, 'active', NOW())")
                            ->execute([$station_id, $fuel_type, $price, $price]);
                    } else {
                        $pdo->prepare("UPDATE inventory_products SET unit_cost = ?, unit_price = ? WHERE id = ?")
                            ->execute([$price, $price, $ip_id]);
                    }
                } catch (Exception $e_ip) {}

                // Sync to fuel_pricing so station has an active price row
                if ($station_id > 0 && $fuel_type_id > 0) {
                    try {
                        $fp_stmt = $pdo->prepare("SELECT id FROM fuel_pricing WHERE station_id = ? AND fuel_type_id = ? AND is_active = 1 LIMIT 1");
                        $fp_stmt->execute([$station_id, $fuel_type_id]);
                        $fp_id = $fp_stmt->fetchColumn();
                        if ($fp_id) {
                            $pdo->prepare("UPDATE fuel_pricing SET price_per_liter = ?, updated_at = NOW() WHERE id = ?")
                                ->execute([$price, $fp_id]);
                        } else {
                            $pdo->prepare("INSERT INTO fuel_pricing (station_id, fuel_type_id, price_per_liter, effective_date, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, NOW(), 1, ?, NOW(), NOW())")
                                ->execute([$station_id, $fuel_type_id, $price, $me['id']]);
                        }
                    } catch (Exception $e_fp) {}
                }

                // Sync to fuel_price_history
                if ($station_id > 0 && !empty($new_fuel_id)) {
                    try {
                        $pdo->prepare("INSERT INTO fuel_price_history (station_id, fuel_id, fuel_type, old_price, new_price, difference, reason, requested_by, approved_by, status, created_at) VALUES (?, ?, ?, 0, ?, ?, 'Initial Product Creation', ?, ?, 'Approved', NOW())")
                            ->execute([$station_id, $new_fuel_id, $fuel_type, $price, $price, $me['id'], $me['id']]);
                    } catch (Exception $e_fph) {}
                }

                // Log Audit Trail
                log_activity($pdo, $me['id'], 'Add Fuel Product',
                    "Admin added new fuel product: {$fuel_type} ({$ugt_no}) with {$num_pumps} pumps at ₱{$price}/L. Status: {$status}. Remarks: {$remarks}");

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Fuel product added successfully.',
                    'fuel_product_id' => $new_fuel_id,
                    'pump_count' => $num_pumps
                ]);
            } catch (PDOException $pdoe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($pdoe->getCode() == 23000 || strpos($pdoe->getMessage(), 'Duplicate entry') !== false) {
                    echo json_encode(['success' => false, 'message' => 'This fuel product already exists for this station.']);
                    exit;
                }
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $pdoe->getMessage()]);
                exit;
            }
            break;

        // ══════════════════════════════════════════════════════════════════════
        // ADD MERCHANDISE (ADMIN DIRECT CREATION)
        // ══════════════════════════════════════════════════════════════════════
        case 'add_merchandise':
            $product_name  = trim($_POST['product_name'] ?? '');
            $category      = trim($_POST['category'] ?? '');
            $brand         = sanitize_optional_field($_POST['brand'] ?? '');
            $unit_cost     = (float)($_POST['unit_cost'] ?? 0);
            $unit_price    = (float)($_POST['unit_price'] ?? 0);
            $sku           = sanitize_optional_field($_POST['sku'] ?? '');
            $size          = sanitize_optional_field($_POST['size'] ?? '');
            $barcode       = sanitize_optional_field($_POST['barcode'] ?? '');
            $reorder_level = (int)($_POST['reorder_level'] ?? 24);
            $critical_level= (int)($_POST['critical_level'] ?? 10);
            $expiration_date = !empty($_POST['expiration_date']) ? trim($_POST['expiration_date']) : null;

            if ((int)$station_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'No station assigned to your account. Please contact Super Admin.']);
                exit;
            }

            $placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];
            if (empty($product_name) || in_array(strtolower($product_name), $placeholders, true)) {
                echo json_encode(['success' => false, 'message' => 'Product Name is required and cannot be N/A or a placeholder.']);
                exit;
            }
            if (empty($category) || in_array(strtolower($category), $placeholders, true)) {
                echo json_encode(['success' => false, 'message' => 'Category is required and cannot be N/A or a placeholder.']);
                exit;
            }

            if ($unit_price <= 0) {
                echo json_encode(['success' => false, 'message' => 'Default Selling Price must be greater than ₱0.00.']);
                exit;
            }

            // Station-scoped duplicate check: block if same product name already in this station
            try {
                $dup_ip = $pdo->prepare("
                    SELECT ip.id FROM inventory_products ip
                    INNER JOIN station_inventory si ON si.product_id = ip.id
                    WHERE si.station_id = ? AND LOWER(ip.product_name) = LOWER(?)
                    LIMIT 1
                ");
                $dup_ip->execute([$station_id, $product_name]);
                if ($dup_ip->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'A merchandise product with this name already exists at this station.']);
                    exit;
                }
                // Also check products table (legacy path)
                $dup_p = $pdo->prepare("
                    SELECT p.id FROM products p
                    INNER JOIN station_inventory si ON si.product_id = p.id
                    WHERE si.station_id = ? AND LOWER(p.name) = LOWER(?)
                    LIMIT 1
                ");
                $dup_p->execute([$station_id, $product_name]);
                if ($dup_p->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'A merchandise product with this name already exists at this station.']);
                    exit;
                }
            } catch (Exception $e) { /* non-fatal, proceed */ }

            $new_id = 0;
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_products
                    (product_name, category, brand, unit_cost, unit_price, sku, barcode, size,
                     reorder_level, critical_level, stock_quantity, status, created_at, station_id, expiration_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'active', NOW(), ?, ?)
                ");
                $stmt->execute([$product_name, $category, $brand, $unit_cost, $unit_price,
                                $sku !== 'N/A' && $sku !== '' ? $sku : null,
                                $barcode !== 'N/A' && $barcode !== '' ? $barcode : null,
                                $size !== 'N/A' && $size !== '' ? $size : 'pcs',
                                $reorder_level, $critical_level, $station_id, $expiration_date]);
                $new_id = (int)$pdo->lastInsertId();

                // Auto-generate SKU if blank or N/A
                if ((empty($sku) || $sku === 'N/A') && $new_id > 0) {
                    $sku = 'P' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
                    $pdo->prepare("UPDATE inventory_products SET sku = ? WHERE id = ?")->execute([$sku, $new_id]);
                }
            } catch (Exception $legacy_error) {
                // Fallback to products table
                $category_id = ensure_product_category_id($pdo, $category);
                $unit_value = ($size !== 'N/A' && $size !== '') ? $size : 'pcs';
                $stmt = $pdo->prepare("
                    INSERT INTO products
                    (sku, name, description, category_id, cost, price, created_at, updated_at, min_stock_level, max_stock_level, station_id, current_stock, unit, capacity, status)
                    VALUES (?, ?, '', ?, ?, ?, NOW(), NOW(), ?, ?, ?, 0, ?, 480, 'active')
                ");
                $stmt->execute([$sku !== 'N/A' && $sku !== '' ? $sku : null, $product_name, $category_id ?: null, $unit_cost, $unit_price,
                                $reorder_level, $reorder_level * 20, $station_id, $unit_value]);
                $new_id = (int)$pdo->lastInsertId();
                if ((empty($sku) || $sku === 'N/A') && $new_id > 0) {
                    $sku = 'P' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
                    try { $pdo->prepare("UPDATE products SET sku = ? WHERE id = ?")->execute([$sku, $new_id]); } catch (Exception $e2) {}
                }
            }

            if ($new_id > 0) {
                $unit_value = ($size !== 'N/A' && $size !== '') ? $size : 'pcs';

                // Sync products table
                try {
                    $category_id = ensure_product_category_id($pdo, $category);
                    $chk_p = $pdo->prepare("SELECT id FROM products WHERE id = ? LIMIT 1");
                    $chk_p->execute([$new_id]);
                    if (!$chk_p->fetchColumn()) {
                        $pdo->prepare("
                            INSERT INTO products
                            (id, sku, name, description, category_id, brand, unit, cost, price,
                             created_at, updated_at, min_stock_level, max_stock_level,
                             station_id, current_stock, capacity, status)
                            VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, 0, 480, 'active')
                        ")->execute([$new_id, $sku, $product_name, $category_id ?: null,
                                     $brand, $unit_value, $unit_cost, $unit_price,
                                     $reorder_level, $critical_level, $station_id]);
                    }
                } catch (Exception $e) {}

                // Initialize station_inventory row
                try {
                    $stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                    $stmt->execute([$station_id, $new_id]);
                    if (!$stmt->fetchColumn()) {
                        $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, unit, cost, price, reorder_level, critical_level, status, expiration_date, last_updated) VALUES (?, ?, 0, ?, ?, ?, ?, ?, 'active', ?, NOW())")
                            ->execute([$station_id, $new_id, $unit_value, $unit_cost, $unit_price, $reorder_level, $critical_level, $expiration_date]);
                    }
                } catch (Exception $e) {}
            }

            log_activity($pdo, $me['id'], 'Add Merchandise',
                "Admin added new merchandise: {$product_name}");

            echo json_encode(['success' => true, 'message' => 'Product added successfully!']);
            break;

        // ══════════════════════════════════════════════════════════════════════
        // DEACTIVATE MERCHANDISE
        // ══════════════════════════════════════════════════════════════════════
        case 'deactivate_merchandise':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

            $chk = $pdo->prepare("SELECT id, product_name FROM inventory_products WHERE id = ? LIMIT 1");
            $chk->execute([$id]);
            $merch_row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$merch_row) {
                $chk2 = $pdo->prepare("SELECT id, name AS product_name FROM products WHERE id = ? LIMIT 1");
                $chk2->execute([$id]);
                $merch_row = $chk2->fetch(PDO::FETCH_ASSOC);
            }
            if (!$merch_row) { echo json_encode(['success' => false, 'message' => 'Merchandise not found']); exit; }

            try { $pdo->prepare("UPDATE inventory_products SET status = 'inactive', updated_at = NOW() WHERE id = ?")->execute([$id]); } catch (Exception $e) {}
            try { $pdo->prepare("UPDATE products SET status = 'inactive', updated_at = NOW() WHERE id = ?")->execute([$id]); } catch (Exception $e) {}
            try { $pdo->prepare("UPDATE station_inventory SET status = 'inactive', last_updated = NOW() WHERE station_id = ? AND product_id = ?")->execute([$station_id, $id]); } catch (Exception $e) {}

            $user_name = $me['username'] ?? ($me['first_name'] ?? 'Admin');
            try {
                $pdo->prepare("INSERT INTO product_status_history (station_id, product_id, old_status, new_status, changed_by, changed_by_name, reason, created_at) VALUES (?, ?, 'active', 'inactive', ?, ?, 'Admin Deactivation', NOW())")
                    ->execute([$station_id, $id, $me['id'], $user_name]);
            } catch (Exception $e) {}

            log_activity($pdo, $me['id'], 'Deactivate Merchandise', "Admin deactivated merchandise: {$merch_row['product_name']}");
            echo json_encode(['success' => true, 'message' => 'Merchandise deactivated successfully']);
            break;

        // ══════════════════════════════════════════════════════════════════════
        // ACTIVATE MERCHANDISE
        // ══════════════════════════════════════════════════════════════════════
        case 'activate_merchandise':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

            $chk = $pdo->prepare("SELECT id, product_name FROM inventory_products WHERE id = ? LIMIT 1");
            $chk->execute([$id]);
            $merch_row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$merch_row) {
                $chk2 = $pdo->prepare("SELECT id, name AS product_name FROM products WHERE id = ? LIMIT 1");
                $chk2->execute([$id]);
                $merch_row = $chk2->fetch(PDO::FETCH_ASSOC);
            }
            if (!$merch_row) { echo json_encode(['success' => false, 'message' => 'Merchandise not found']); exit; }

            try { $pdo->prepare("UPDATE inventory_products SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$id]); } catch (Exception $e) {}
            try { $pdo->prepare("UPDATE products SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$id]); } catch (Exception $e) {}
            try { $pdo->prepare("UPDATE station_inventory SET status = 'active', last_updated = NOW() WHERE station_id = ? AND product_id = ?")->execute([$station_id, $id]); } catch (Exception $e) {}

            $user_name = $me['username'] ?? ($me['first_name'] ?? 'Admin');
            try {
                $pdo->prepare("INSERT INTO product_status_history (station_id, product_id, old_status, new_status, changed_by, changed_by_name, reason, created_at) VALUES (?, ?, 'inactive', 'active', ?, ?, 'Admin Activation', NOW())")
                    ->execute([$station_id, $id, $me['id'], $user_name]);
            } catch (Exception $e) {}

            log_activity($pdo, $me['id'], 'Activate Merchandise', "Admin activated merchandise: {$merch_row['product_name']}");
            echo json_encode(['success' => true, 'message' => 'Merchandise activated successfully']);
            break;

        // ══════════════════════════════════════════════════════════════════════
        // ADD SERVICE (ADMIN DIRECT CREATION)
        // ══════════════════════════════════════════════════════════════════════
        case 'add_service':
            $service_name       = trim($_POST['service_name'] ?? '');
            $category           = trim($_POST['category'] ?? '');
            $service_key        = trim($_POST['service_key'] ?? '');
            $service_price      = (float)($_POST['service_price'] ?? 0);
            $labor_fee          = (float)($_POST['labor_fee'] ?? 0);
            $dur_raw            = trim((string)($_POST['estimated_duration'] ?? ''));
            $estimated_duration = ($dur_raw !== '' && is_numeric($dur_raw) && (int)$dur_raw > 0) ? (int)$dur_raw : null;
            $mech_raw           = trim((string)($_POST['required_mechanics'] ?? ''));
            $required_mechanics = ($mech_raw !== '' && is_numeric($mech_raw) && (int)$mech_raw > 0) ? (int)$mech_raw : 1;
            $description        = sanitize_optional_field($_POST['description'] ?? '');

            if ((int)$station_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'No station assigned to your account. Please contact Super Admin.']);
                exit;
            }

            if (empty($service_name) || empty($category)) {
                echo json_encode(['success' => false, 'message' => 'Service name and category are required']);
                exit;
            }
            if ($service_price <= 0 || $labor_fee <= 0) {
                echo json_encode(['success' => false, 'message' => 'Service fee and labor fee must be greater than ₱0.00']);
                exit;
            }

            // Station-scoped service name duplicate check
            $svc_dup = $pdo->prepare("
                SELECT id FROM job_order_service_types
                WHERE station_id = ? AND LOWER(service_name) = LOWER(?)
                LIMIT 1
            ");
            $svc_dup->execute([$station_id, $service_name]);
            if ($svc_dup->fetch()) {
                echo json_encode(['success' => false, 'message' => 'A service with this name already exists at this station.']);
                exit;
            }

            if (empty($service_key)) {
                $service_key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $service_name));
                $service_key = trim($service_key, '_');
            }

            $base_key = $service_key;
            $suffix = 1;
            while (true) {
                $chk = $pdo->prepare("SELECT id FROM job_order_service_types WHERE service_key = ? AND station_id = ? LIMIT 1");
                $chk->execute([$service_key, $station_id]);
                if (!$chk->fetch()) break;
                $service_key = $base_key . '_' . $suffix++;
            }

            $max_stmt = $pdo->query("SELECT MAX(id) FROM job_order_service_types");
            $next_id = (int)$max_stmt->fetchColumn() + 1;
            $service_code = 'SVC-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("
                INSERT INTO job_order_service_types
                (service_code, service_name, category, service_key, service_price,
                 labor_fee, estimated_duration, required_mechanics, description,
                 station_id, created_by, status, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', 1)
            ");
            $stmt->execute([
                $service_code, $service_name, $category, $service_key,
                $service_price, $labor_fee, $estimated_duration, $required_mechanics,
                $description, $station_id, $me['id']
            ]);
            $new_id = (int)$pdo->lastInsertId();

            $pdo->prepare("UPDATE job_order_service_types SET service_code = ? WHERE id = ? AND (service_code IS NULL OR service_code != ?)")
                ->execute(['SVC-' . str_pad($new_id, 4, '0', STR_PAD_LEFT), $new_id, 'SVC-' . str_pad($new_id, 4, '0', STR_PAD_LEFT)]);

            try {
                $pdo->prepare("
                    INSERT INTO service_fee_history
                    (service_id, change_type, new_service_fee, new_labor_fee, notes, changed_by, changed_by_name, changed_by_role, approval_status)
                    VALUES (?, 'created', ?, ?, ?, ?, ?, 'admin', 'direct')
                ")->execute([
                    $new_id, $service_price, $labor_fee,
                    "Service created: {$service_name}",
                    $me['id'], ($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')
                ]);
            } catch (Exception $e) {}

            log_activity($pdo, $me['id'], 'Add Service Type',
                "Admin added service: {$service_name} ({$category}) - Fee: ₱{$service_price}, Labor: ₱{$labor_fee}");

            echo json_encode(['success' => true, 'message' => 'Service added successfully', 'service_code' => $service_code]);
            break;

        // ══════════════════════════════════════════════════════════════════════
        // GET FUEL DETAILS FOR ADMIN VIEW MODAL
        // ══════════════════════════════════════════════════════════════════════
        case 'get_fuel_details_admin':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Fuel ID']); exit; }

            // Fetch fuel row — try station_id first, fall back to any station
            $fuel = null;
            try {
                $stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id=? LIMIT 1");
                $stmt->execute([$id]);
                $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found (ID: ' . $id . ')']);
                exit;
            }

            // Use the fuel's own station_id for all sub-queries
            $fuel_station_id = (int)($fuel['station_id'] ?? $station_id);
            $fuel_type_name  = $fuel['fuel_type'] ?? '';

            // Inline matching: get all fuel_inventory IDs that share the same canonical fuel type at this station
            $matching_ids = [$id];
            try {
                $canonical_name = '';
                $n = strtolower(trim($fuel_type_name));
                if (strpos($n, 'turbo') !== false)      $canonical_name = 'Turbo Diesel';
                elseif (strpos($n, 'diesel') !== false) $canonical_name = 'Diesel';
                elseif (strpos($n, 'kerosene') !== false) $canonical_name = 'Kerosene';
                elseif (strpos($n, 'xcs') !== false)    $canonical_name = 'XCS Plus';
                elseif (strpos($n, 'xtra') !== false || strpos($n, 'unl') !== false || strpos($n, 'advance') !== false) $canonical_name = 'Xtra UNL';
                else $canonical_name = $fuel_type_name;

                $all_rows = $pdo->prepare("SELECT id, fuel_type FROM fuel_inventory WHERE station_id=?");
                $all_rows->execute([$fuel_station_id]);
                foreach ($all_rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $n2 = strtolower(trim($row['fuel_type']));
                    $c2 = '';
                    if (strpos($n2, 'turbo') !== false)      $c2 = 'Turbo Diesel';
                    elseif (strpos($n2, 'diesel') !== false) $c2 = 'Diesel';
                    elseif (strpos($n2, 'kerosene') !== false) $c2 = 'Kerosene';
                    elseif (strpos($n2, 'xcs') !== false)    $c2 = 'XCS Plus';
                    elseif (strpos($n2, 'xtra') !== false || strpos($n2, 'unl') !== false || strpos($n2, 'advance') !== false) $c2 = 'Xtra UNL';
                    else $c2 = $row['fuel_type'];
                    if ($c2 === $canonical_name) $matching_ids[] = (int)$row['id'];
                }
                $matching_ids = array_values(array_unique($matching_ids));
            } catch (Exception $e) {}

            $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));

            // 1. Fetch Pending Request — search by product_id OR canonical product_name match
            $pending_req = null;
            try {
                // First: try by product_id in matching_ids
                $p_stmt = $pdo->prepare("
                    SELECT p.*,
                           COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'Manager') as requested_by_name
                    FROM pending_price_approvals p
                    LEFT JOIN users u ON p.requested_by = u.id
                    WHERE p.product_type IN ('fuel','fuel_inventory')
                      AND p.station_id = ?
                      AND p.product_id IN ($in_clause)
                      AND p.status='pending'
                    ORDER BY p.id DESC LIMIT 1
                ");
                $p_stmt->execute(array_merge([$fuel_station_id], $matching_ids));
                $pending_req = $p_stmt->fetch(PDO::FETCH_ASSOC);

                // Fallback: search by station + fuel type name
                if (!$pending_req) {
                    $p_stmt2 = $pdo->prepare("
                        SELECT p.*,
                               COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'Manager') as requested_by_name
                        FROM pending_price_approvals p
                        LEFT JOIN users u ON p.requested_by = u.id
                        WHERE p.product_type IN ('fuel','fuel_inventory')
                          AND p.station_id = ?
                          AND p.status = 'pending'
                        ORDER BY p.id DESC LIMIT 1
                    ");
                    $p_stmt2->execute([$fuel_station_id]);
                    $pending_req = $p_stmt2->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {}

            // 2. Fetch Price History
            $price_history = [];
            try {
                $hist_stmt = $pdo->prepare("
                    SELECT h.*,
                           COALESCE(CONCAT(u1.first_name, ' ', u1.last_name), u1.name, u1.username, 'Manager') as requested_by_name,
                           COALESCE(CONCAT(u2.first_name, ' ', u2.last_name), u2.name, u2.username, 'Admin') as approved_by_name
                    FROM fuel_price_history h
                    LEFT JOIN users u1 ON h.requested_by = u1.id
                    LEFT JOIN users u2 ON h.approved_by = u2.id
                    WHERE h.station_id=? AND h.fuel_id IN ($in_clause)
                    ORDER BY h.created_at DESC LIMIT 20
                ");
                $hist_stmt->execute(array_merge([$fuel_station_id], $matching_ids));
                $price_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            // Fallback: also pull from pending_price_approvals history
            if (empty($price_history)) {
                try {
                    $hist2 = $pdo->prepare("
                        SELECT p.created_at, p.old_price, p.new_price,
                               (p.new_price - p.old_price) AS difference,
                               COALESCE(p.reason, 'Price Change Request') AS reason,
                               p.status,
                               COALESCE(CONCAT(u1.first_name, ' ', u1.last_name), u1.name, u1.username, 'Manager') as requested_by_name,
                               COALESCE(CONCAT(u2.first_name, ' ', u2.last_name), u2.name, u2.username, 'Admin') as approved_by_name
                        FROM pending_price_approvals p
                        LEFT JOIN users u1 ON p.requested_by = u1.id
                        LEFT JOIN users u2 ON p.admin_id = u2.id
                        WHERE p.station_id=? AND p.product_id IN ($in_clause)
                        ORDER BY p.created_at DESC LIMIT 20
                    ");
                    $hist2->execute(array_merge([$fuel_station_id], $matching_ids));
                    $price_history = $hist2->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {}
            }

            // 3. Fetch Configuration History (UGT, Fuel Name, Capacity, Critical Level, Reorder Level)
            $config_history = [];
            try {
                $config_stmt = $pdo->prepare("SELECT ch.*, COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, ch.updated_by_name, 'Manager') as updated_by_name FROM fuel_config_history ch LEFT JOIN users u ON ch.updated_by=u.id WHERE ch.station_id=? AND ch.fuel_inventory_id IN ($in_clause) AND ch.field_name IN ('Fuel Name', 'Tank Capacity', 'Capacity', 'Critical Level', 'Reorder Level', 'UGT No', 'UGT Number') ORDER BY ch.created_at DESC LIMIT 20");
                $config_stmt->execute(array_merge([$fuel_station_id], $matching_ids));
                $config_history = $config_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $config_history = []; }

            // 4. Fetch Status Change History
            $status_history = [];
            try {
                $status_stmt = $pdo->prepare("SELECT sh.*, COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, sh.changed_by_name, 'System') as changed_by_name FROM fuel_status_history sh LEFT JOIN users u ON sh.changed_by=u.id WHERE sh.station_id=? AND sh.fuel_inventory_id IN ($in_clause) ORDER BY sh.created_at DESC LIMIT 20");
                $status_stmt->execute(array_merge([$fuel_station_id], $matching_ids));
                $status_history = $status_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $status_history = []; }

            // 5. Fetch Pumps for this fuel product (Identical to Meter Reading nozzles)
            $pumps = fetch_pumps_for_fuel_product($pdo, $fuel_station_id, $fuel);

            $fuel['clean_fuel_type'] = $canonical_name;

            echo json_encode([
                'success'         => true,
                'fuel'            => $fuel,
                'pumps'           => $pumps,
                'pending_request' => $pending_req,
                'price_history'   => $price_history,
                'config_history'  => $config_history,
                'status_history'  => $status_history
            ]);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // RENAME PUMP / NOZZLE (ADMIN EDIT PUMP NAME)
        // ══════════════════════════════════════════════════════════════════════
        case 'rename_pump_admin':
            $pump_id  = (int)($_POST['pump_id'] ?? 0);
            $new_name = trim($_POST['new_name'] ?? '');
            if ($pump_id <= 0 || $new_name === '') {
                echo json_encode(['success' => false, 'message' => 'Pump ID and Name are required.']);
                exit;
            }
            try {
                $target_station = (int)$station_id;
                $chk_p = $pdo->prepare("SELECT station_id, pump_number FROM fuel_pumps WHERE id = ? LIMIT 1");
                $chk_p->execute([$pump_id]);
                $p_row = $chk_p->fetch(PDO::FETCH_ASSOC);
                if ($p_row && (int)$p_row['station_id'] > 0) {
                    $target_station = (int)$p_row['station_id'];
                }

                $up = $pdo->prepare("UPDATE fuel_pumps SET pump_number = ?, pump_name = ? WHERE id = ? AND station_id = ?");
                $up->execute([$new_name, $new_name, $pump_id, $target_station]);

                try {
                    $pdo->prepare("UPDATE nozzles SET pump_name = ? WHERE pump_id = ? AND station_id = ?")
                        ->execute([$new_name, $pump_id, $target_station]);
                } catch (Exception $e) {}

                $user_name = $me['username'] ?? ($me['first_name'] ?? 'Admin');
                log_activity($pdo, $me['id'], 'Rename Pump / Nozzle', "Admin renamed pump #{$pump_id} from '" . ($p_row['pump_number'] ?? '') . "' to '{$new_name}'");
                echo json_encode(['success' => true, 'message' => "Pump/Nozzle name updated to '{$new_name}'!", 'new_name' => $new_name]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // GET MERCHANDISE DETAILS FOR EDIT MODAL
        // ══════════════════════════════════════════════════════════════════════
        case 'get_merch_details_admin':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Product ID']); exit; }
            session_write_close(); // Read-only — release session lock immediately

            // Fast indexed single-row lookup
            $stmt = $pdo->prepare("SELECT id, product_name, sku, category, brand, size as unit, unit_price, reorder_level, critical_level, status, expiration_date FROM inventory_products WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                $stmt2 = $pdo->prepare("SELECT p.id, p.name as product_name, p.sku, COALESCE(pc.name, 'Merchandise') as category, p.brand, p.unit, p.price as unit_price, p.min_stock_level as reorder_level, 10 as critical_level, p.status FROM products p LEFT JOIN product_categories pc ON pc.id = p.category_id WHERE p.id = ? LIMIT 1");
                $stmt2->execute([$id]);
                $item = $stmt2->fetch(PDO::FETCH_ASSOC);
            }
            if (!$item) {
                $item = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            }
            if (!$item) { echo json_encode(['success' => false, 'message' => 'Product not found']); exit; }

            echo json_encode(['success' => true, 'item' => $item]);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // EDIT PRODUCT DETAILS (ADMIN) - Price is NOT editable here
        // ══════════════════════════════════════════════════════════════════════
        // ══════════════════════════════════════════════════════════════════════
        // EDIT PRODUCT DETAILS (ADMIN) - Admin can update price directly
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_product_admin':
            $id             = (int)($_POST['id'] ?? 0);
            $product_name   = trim($_POST['product_name'] ?? '');
            $sku            = trim($_POST['sku'] ?? '');
            $category       = trim($_POST['category'] ?? '');
            $brand          = trim($_POST['brand'] ?? '');
            $unit           = trim($_POST['unit'] ?? '');
            $unit_price     = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : -1;
            $reorder_level  = (int)($_POST['reorder_level'] ?? 24);
            $critical_level = (int)($_POST['critical_level'] ?? 10);
            $prod_status    = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
            $expiration_date = !empty($_POST['expiration_date']) ? trim($_POST['expiration_date']) : null;

            if ($id <= 0 || empty($product_name) || empty($category)) {
                echo json_encode(['success' => false, 'message' => 'Product name and category are required.']);
                exit;
            }

            $placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];
            if (in_array(strtolower($product_name), $placeholders, true)) {
                echo json_encode(['success' => false, 'message' => 'Product name cannot be N/A or a placeholder.']);
                exit;
            }
            if (in_array(strtolower($category), $placeholders, true)) {
                echo json_encode(['success' => false, 'message' => 'Category cannot be N/A or a placeholder.']);
                exit;
            }
            if ($unit_price <= 0) {
                echo json_encode(['success' => false, 'message' => 'Default selling price must be a valid number greater than 0.']);
                exit;
            }
            if ($reorder_level <= 0) {
                echo json_encode(['success' => false, 'message' => 'Reorder level must be a valid number greater than 0.']);
                exit;
            }
            if ($critical_level <= 0) {
                echo json_encode(['success' => false, 'message' => 'Critical level must be a valid number greater than 0.']);
                exit;
            }

            $category_id = ensure_product_category_id($pdo, $category);
            $unit_value  = $unit !== '' ? $unit : 'pcs';

            // Fetch old product details for history logging
            $old_prod = null;
            try {
                $stmt_old = $pdo->prepare("SELECT product_name as name, sku, category, brand, size as unit, unit_price as price, reorder_level, critical_level, status FROM inventory_products WHERE id=? LIMIT 1");
                $stmt_old->execute([$id]);
                $old_prod = $stmt_old->fetch(PDO::FETCH_ASSOC);
                if (!$old_prod) {
                    $stmt_old2 = $pdo->prepare("SELECT p.name, p.sku, pc.name as category, p.brand, p.unit, p.price, p.min_stock_level as reorder_level, p.max_stock_level as critical_level, p.status FROM products p LEFT JOIN product_categories pc ON p.category_id=pc.id WHERE p.id=? LIMIT 1");
                    $stmt_old2->execute([$id]);
                    $old_prod = $stmt_old2->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {}

            $admin_user = $me['username'] ?? ($me['first_name'] ?? 'Admin');

            // 1. Update inventory_products
            try {
                if ($unit_price >= 0) {
                    $stmt = $pdo->prepare("
                        UPDATE inventory_products
                        SET product_name=?, sku=?, category=?, brand=?, size=?, unit_price=?,
                            reorder_level=?, critical_level=?, status=?, expiration_date=?, updated_at=NOW()
                        WHERE id=?
                    ");
                    $stmt->execute([$product_name, $sku, $category, $brand, $unit_value, $unit_price, $reorder_level, $critical_level, $prod_status, $expiration_date, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE inventory_products
                        SET product_name=?, sku=?, category=?, brand=?, size=?,
                            reorder_level=?, critical_level=?, status=?, expiration_date=?, updated_at=NOW()
                        WHERE id=?
                    ");
                    $stmt->execute([$product_name, $sku, $category, $brand, $unit_value, $reorder_level, $critical_level, $prod_status, $expiration_date, $id]);
                }
            } catch (Exception $e) {}

            // 2. Update products
            try {
                if ($unit_price >= 0) {
                    if ($category_id > 0) {
                        $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, category_id=?, brand=?, unit=?, price=?, status=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([$product_name, $sku, $category_id, $brand, $unit_value, $unit_price, $prod_status, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, brand=?, unit=?, price=?, status=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([$product_name, $sku, $brand, $unit_value, $unit_price, $prod_status, $id]);
                    }
                } else {
                    if ($category_id > 0) {
                        $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, category_id=?, brand=?, unit=?, status=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([$product_name, $sku, $category_id, $brand, $unit_value, $prod_status, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, brand=?, unit=?, status=?, updated_at=NOW() WHERE id=?");
                        $stmt->execute([$product_name, $sku, $brand, $unit_value, $prod_status, $id]);
                    }
                }
            } catch (Exception $e) {}

            // 3. Update station_inventory
            try {
                $stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                $stmt->execute([$station_id, $id]);
                $si_id = (int)($stmt->fetchColumn() ?: 0);
                if ($si_id > 0) {
                    if ($unit_price >= 0) {
                        $pdo->prepare("UPDATE station_inventory SET unit=?, price=?, reorder_level=?, critical_level=?, status=?, expiration_date=?, last_updated=NOW() WHERE id=?")
                            ->execute([$unit_value, $unit_price, $reorder_level, $critical_level, $prod_status, $expiration_date, $si_id]);
                    } else {
                        $pdo->prepare("UPDATE station_inventory SET unit=?, reorder_level=?, critical_level=?, status=?, expiration_date=?, last_updated=NOW() WHERE id=?")
                            ->execute([$unit_value, $reorder_level, $critical_level, $prod_status, $expiration_date, $si_id]);
                    }
                } else {
                    $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, unit, price, reorder_level, critical_level, status, expiration_date, last_updated) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $unit_value, max(0, $unit_price), $reorder_level, $critical_level, $prod_status, $expiration_date]);
                }
            } catch (Exception $e) {}

            // 4. Log History Entries if fields changed
            if ($old_prod) {
                // Price History Log
                $old_p = (float)($old_prod['price'] ?? 0);
                if ($unit_price >= 0 && abs($old_p - $unit_price) > 0.001) {
                    try {
                        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_price_history` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `station_id` INT NOT NULL,
                            `product_id` INT NOT NULL,
                            `old_price` DECIMAL(10,2) NOT NULL,
                            `new_price` DECIMAL(10,2) NOT NULL,
                            `requested_by` INT NULL,
                            `requested_by_name` VARCHAR(255) NULL,
                            `approved_by` INT NULL,
                            `approved_by_name` VARCHAR(255) NULL,
                            `status` VARCHAR(50) NOT NULL DEFAULT 'Approved',
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                        $pdo->prepare("INSERT INTO product_price_history (station_id, product_id, old_price, new_price, requested_by, requested_by_name, approved_by, approved_by_name, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Approved', NOW())")
                            ->execute([$station_id, $id, $old_p, $unit_price, $me['id'], $admin_user, $me['id'], $admin_user]);
                    } catch (Exception $e) {}
                }

                // Status History Log
                $old_st = strtolower($old_prod['status'] ?? 'active');
                if ($old_st !== strtolower($prod_status)) {
                    try {
                        $pdo->exec("CREATE TABLE IF NOT EXISTS `product_status_history` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `station_id` INT NOT NULL,
                            `product_id` INT NOT NULL,
                            `old_status` VARCHAR(50) NOT NULL,
                            `new_status` VARCHAR(50) NOT NULL,
                            `changed_by` INT NULL,
                            `changed_by_name` VARCHAR(255) NULL,
                            `reason` TEXT NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                        $pdo->prepare("INSERT INTO product_status_history (station_id, product_id, old_status, new_status, changed_by, changed_by_name, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Admin Status Update', NOW())")
                            ->execute([$station_id, $id, $old_prod['status'], $prod_status, $me['id'], $admin_user]);
                    } catch (Exception $e) {}
                }

                // Config History Log
                $cfg_changes = [
                    'Product Name'  => [$old_prod['name'] ?? '', $product_name],
                    'SKU'           => [$old_prod['sku'] ?? '', $sku],
                    'Category'      => [$old_prod['category'] ?? '', $category],
                    'Brand'         => [$old_prod['brand'] ?? '', $brand],
                    'Unit'          => [$old_prod['unit'] ?? '', $unit_value],
                    'Reorder Level' => [(string)($old_prod['reorder_level'] ?? ''), (string)$reorder_level],
                    'Critical Level'=> [(string)($old_prod['critical_level'] ?? ''), (string)$critical_level],
                ];

                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_config_history` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `station_id` INT NOT NULL,
                        `product_id` INT NOT NULL,
                        `field_name` VARCHAR(100) NOT NULL,
                        `old_value` VARCHAR(255) NULL,
                        `new_value` VARCHAR(255) NULL,
                        `changed_by` INT NULL,
                        `changed_by_name` VARCHAR(255) NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    foreach ($cfg_changes as $f_name => [$o_val, $n_val]) {
                        if ((string)$o_val !== (string)$n_val && $n_val !== '') {
                            $pdo->prepare("INSERT INTO product_config_history (station_id, product_id, field_name, old_value, new_value, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                                ->execute([$station_id, $id, $f_name, (string)$o_val, (string)$n_val, $me['id'], $admin_user]);
                        }
                    }
                } catch (Exception $e) {}
            }

            // If Admin changed price, mark any pending price requests for this product as approved
            if ($unit_price >= 0) {
                $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE station_id=? AND product_type IN ('merchandise','product','inventory_product') AND product_id=? AND status='pending'")
                    ->execute([$me['id'], $me['id'], $station_id, $id]);
            }

            log_activity($pdo, $me['id'], 'Admin Edit Product', "Admin updated master details for: {$product_name}" . ($unit_price >= 0 ? " (Price: ₱{$unit_price})" : ""));
            echo json_encode(['success' => true, 'message' => 'Product updated successfully!']);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // ADMIN EDIT FUEL PRODUCT DIRECTLY
        // ══════════════════════════════════════════════════════════════════════
        case 'admin_edit_fuel':
            $id             = (int)($_POST['id'] ?? 0);
            $new_price      = (float)($_POST['price'] ?? 0);
            $new_fuel_name  = trim($_POST['fuel_name'] ?? '');
            $new_ugt_no     = trim($_POST['ugt_no'] ?? '');
            $capacity       = (float)($_POST['capacity'] ?? 0);
            $critical_level = (float)($_POST['critical_level'] ?? 0);
            $reorder_level  = (float)($_POST['reorder_level'] ?? 0);

            if ($id <= 0 || $new_price < 0 || $capacity < 0 || $critical_level < 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT fuel_type, ugt_no, fuel_type_id, price_per_liter FROM fuel_inventory WHERE id=? AND station_id=? LIMIT 1");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fuel) { echo json_encode(['success' => false, 'message' => 'Fuel product not found']); exit; }

            $old_price    = (float)$fuel['price_per_liter'];
            $fuel_type_id = $fuel['fuel_type_id'] ?? null;
            $target_fuel_name = !empty($new_fuel_name) ? $new_fuel_name : $fuel['fuel_type'];
            $target_ugt_no    = !empty($new_ugt_no)    ? $new_ugt_no    : $fuel['ugt_no'];

            // Admin is owner: auto-cancel any pending price approvals for this product
            try {
                $pdo->prepare("UPDATE pending_price_approvals SET status='cancelled', updated_at=NOW() WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id=? AND status='pending'")
                    ->execute([$station_id, $id]);
            } catch (Exception $e) {}

            // Check if renamed fuel product or UGT conflicts with another tank for this station
            if (!empty($new_fuel_name) || !empty($new_ugt_no)) {
                $chk_dup = $pdo->prepare("SELECT id FROM fuel_inventory WHERE station_id = ? AND id != ? AND (LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) OR LOWER(TRIM(ugt_no)) = LOWER(TRIM(?))) LIMIT 1");
                $chk_dup->execute([$station_id, $id, $target_fuel_name, $target_ugt_no]);
                if ($chk_dup->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'This fuel product already exists for this station.']);
                    exit;
                }
            }

            $num_pumps = isset($_POST['num_pumps']) && $_POST['num_pumps'] !== '' ? max(0, (int)$_POST['num_pumps']) : null;

            // Admin always updates everything including price immediately (updates existing record, keeping ID)
            if ($num_pumps !== null) {
                $stmt = $pdo->prepare("UPDATE fuel_inventory SET fuel_type=?, ugt_no=?, price_per_liter=?, capacity=?, critical_level=?, reorder_level=?, num_pumps=?, updated_by=?, last_updated=NOW() WHERE id=? AND station_id=?");
                $stmt->execute([$target_fuel_name, $target_ugt_no, $new_price, $capacity, $critical_level, $reorder_level, $num_pumps, $me['id'], $id, $station_id]);

                // Synchronize fuel_pumps and nozzles for this tank
                $chk_pumps = $pdo->prepare("SELECT id, pump_number, status FROM fuel_pumps WHERE station_id = ? AND tank_id = ? ORDER BY id ASC");
                $chk_pumps->execute([$station_id, $id]);
                $cur_pumps = $chk_pumps->fetchAll(PDO::FETCH_ASSOC);
                $cur_count = count($cur_pumps);

                if ($num_pumps > $cur_count) {
                    $clean_fuel_tag = strtoupper(trim($target_fuel_name));
                    for ($pi = $cur_count + 1; $pi <= $num_pumps; $pi++) {
                        $pump_num = "{$clean_fuel_tag} - {$pi}";
                        $pump_name = "Pump {$pi}";
                        $nozzle_num = "Nozzle {$pi}";
                        
                        $ins_pump = $pdo->prepare("INSERT INTO fuel_pumps (station_id, tank_id, pump_number, pump_name, nozzle_number, fuel_type_id, ugt_no, capacity, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())");
                        $ins_pump->execute([$station_id, $id, $pump_num, $pump_name, $nozzle_num, $fuel_type_id, $target_ugt_no, $capacity]);
                        $new_pid = (int)$pdo->lastInsertId();

                        try {
                            $pdo->prepare("INSERT INTO nozzles (station_id, pump_id, pump_name, nozzle_number, fuel_type_id, ugt_no, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Active', NOW())")
                                ->execute([$station_id, $new_pid, $pump_name, $nozzle_num, $fuel_type_id, $target_ugt_no]);
                        } catch (Exception $e_nz) {}
                    }
                } elseif ($num_pumps < $cur_count) {
                    $excess = array_slice($cur_pumps, $num_pumps);
                    foreach ($excess as $ep) {
                        $ep_id = (int)$ep['id'];
                        $pdo->prepare("UPDATE fuel_pumps SET status = 'Inactive' WHERE id = ? AND station_id = ?")->execute([$ep_id, $station_id]);
                        $pdo->prepare("UPDATE nozzles SET status = 'Inactive' WHERE pump_id = ? AND station_id = ?")->execute([$ep_id, $station_id]);
                    }
                }
            } else {
                $stmt = $pdo->prepare("UPDATE fuel_inventory SET fuel_type=?, ugt_no=?, price_per_liter=?, capacity=?, critical_level=?, reorder_level=?, updated_by=?, last_updated=NOW() WHERE id=? AND station_id=?");
                $stmt->execute([$target_fuel_name, $target_ugt_no, $new_price, $capacity, $critical_level, $reorder_level, $me['id'], $id, $station_id]);
            }

            // ── Sync to fuel_types.price_per_liter (so meter reading shows correct price) ──
            if ($fuel_type_id) {
                try {
                    $pdo->prepare("UPDATE fuel_types SET price_per_liter = ? WHERE id = ?")
                        ->execute([$new_price, $fuel_type_id]);
                } catch (Exception $e) {}
            }

            // ── Sync to fuel_pricing (used by Inventory / Reports pages) ──
            if ($fuel_type_id) {
                try {
                    $fp_stmt = $pdo->prepare("SELECT id FROM fuel_pricing WHERE station_id = ? AND fuel_type_id = ? AND is_active = 1 LIMIT 1");
                    $fp_stmt->execute([$station_id, $fuel_type_id]);
                    $fp_id = $fp_stmt->fetchColumn();
                    if ($fp_id) {
                        $pdo->prepare("UPDATE fuel_pricing SET price_per_liter = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$new_price, $fp_id]);
                    } else {
                        $pdo->prepare("INSERT INTO fuel_pricing (station_id, fuel_type_id, price_per_liter, effective_date, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, NOW(), 1, ?, NOW(), NOW())")
                            ->execute([$station_id, $fuel_type_id, $new_price, $me['id']]);
                    }
                } catch (Exception $e) {}
            }

            if ($old_price != $new_price) {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS fuel_price_history (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            fuel_id INT NOT NULL,
                            old_price DECIMAL(10,2) NOT NULL,
                            new_price DECIMAL(10,2) NOT NULL,
                            reason VARCHAR(500),
                            effective_date DATE,
                            updated_by INT,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                    $pdo->prepare("INSERT INTO fuel_price_history (fuel_id, old_price, new_price, reason, effective_date, updated_by, created_at) VALUES (?, ?, ?, 'Direct Admin Edit', CURDATE(), ?, NOW())")
                        ->execute([$id, $old_price, $new_price, $me['id']]);
                } catch (Exception $e) {}
            }

            echo json_encode(['success' => true, 'message' => 'Fuel product updated successfully! Price synced across all modules.']);
            break;

        case 'toggle_fuel_status_admin':
            $id          = (int)($_POST['id'] ?? 0);
            $new_status  = trim($_POST['status'] ?? 'active');
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid fuel product ID.']);
                exit;
            }

            $stmt_f = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id=? AND station_id=? LIMIT 1");
            $stmt_f->execute([$id, $station_id]);
            $old_fuel = $stmt_f->fetch(PDO::FETCH_ASSOC);

            if ($old_fuel) {
                $user_name = $me['username'] ?? ($me['first_name'] ?? 'Admin');
                $old_st_text = ucfirst(strtolower($old_fuel['status'] ?? 'active'));
                $new_st_text = ucfirst(strtolower($new_status));
                $status_label = (strtolower($new_status) === 'active') ? 'Activated' : 'Deactivated';
                try {
                    $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, old_status, new_status, status, reason, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Admin Action', ?, ?, NOW())")
                        ->execute([$station_id, $id, $old_fuel['fuel_type'], $old_st_text, $new_st_text, $status_label, $me['id'], $user_name]);
                } catch (Exception $e) {}

                $pdo->prepare("UPDATE fuel_inventory SET status = ?, updated_by = ?, last_updated = NOW() WHERE id = ? AND station_id = ?")
                    ->execute([$new_status, $me['id'], $id, $station_id]);

                log_activity($pdo, $me['id'], 'Update Fuel Status', "Fuel {$old_fuel['fuel_type']} ({$old_fuel['ugt_no']}) marked {$new_status}");
                echo json_encode(['success' => true, 'message' => "Fuel product {$status_label} successfully."]);
                exit;
            }
            echo json_encode(['success' => false, 'message' => 'Fuel product not found.']);
            exit;

        case 'admin_edit_merchandise':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['product_name'] ?? ($_POST['name'] ?? ''));
            $barcode = trim($_POST['barcode'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $brand = trim($_POST['brand'] ?? '');
            $unit = trim($_POST['unit'] ?? 'pcs');
            $new_price = (float)($_POST['price'] ?? 0);
            $reorder = (int)($_POST['reorder_level'] ?? 24);

            if ($id <= 0 || empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Product name is required']);
                exit;
            }

            $category_id = ensure_product_category_id($pdo, $category);

            $old_stmt = $pdo->prepare("SELECT price, name FROM products WHERE id = ? LIMIT 1");
            $old_stmt->execute([$id]);
            $old = $old_stmt->fetch(PDO::FETCH_ASSOC);
            $old_price = (float)($old['price'] ?? 0);

            if ($category_id > 0) {
                $stmt = $pdo->prepare("UPDATE products SET name=?, barcode=?, brand=?, category_id=?, unit=?, price=?, min_stock_level=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$name, $barcode ?: null, $brand, $category_id, $unit, $new_price, $reorder, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE products SET name=?, barcode=?, brand=?, unit=?, price=?, min_stock_level=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$name, $barcode ?: null, $brand, $unit, $new_price, $reorder, $id]);
            }

            try {
                $stmt = $pdo->prepare("UPDATE inventory_products SET product_name=?, barcode=?, brand=?, category=?, size=?, unit_price=?, reorder_level=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$name, $barcode ?: null, $brand, $category, $unit, $new_price, $reorder, $id]);
            } catch (Exception $e) {}

            try {
                $pdo->prepare("UPDATE pending_price_approvals SET status='cancelled', updated_at=NOW() WHERE product_id=? AND product_type IN ('merchandise','product') AND status='pending'")
                    ->execute([$id]);
            } catch (Exception $e) {}

            if (abs($old_price - $new_price) > 0.001) {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_price_history` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `station_id` INT NOT NULL,
                        `product_id` INT NOT NULL,
                        `old_price` DECIMAL(10,2) NOT NULL,
                        `new_price` DECIMAL(10,2) NOT NULL,
                        `requested_by` INT NULL,
                        `requested_by_name` VARCHAR(255) NULL,
                        `approved_by` INT NULL,
                        `approved_by_name` VARCHAR(255) NULL,
                        `status` VARCHAR(50) NOT NULL DEFAULT 'Approved',
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $user_name = $me['username'] ?? ($me['first_name'] ?? 'Admin');
                    $pdo->prepare("INSERT INTO product_price_history (station_id, product_id, old_price, new_price, requested_by, requested_by_name, approved_by, approved_by_name, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Approved', NOW())")
                        ->execute([$station_id, $id, $old_price, $new_price, $me['id'], $user_name, $me['id'], $user_name]);
                } catch (Exception $e) {}
            }

            log_activity($pdo, $me['id'], 'Admin Edit Merchandise', "Admin updated merchandise {$name} price: ₱{$old_price} -> ₱{$new_price}");
            echo json_encode(['success' => true, 'message' => 'Merchandise updated successfully!']);
            exit;

        case 'get_merchandise_details_admin':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            session_write_close(); // Read-only — release session lock immediately

            $stmt = $pdo->prepare("
                SELECT p.id, p.sku, p.barcode, p.name, p.brand, p.unit, p.cost, p.price, p.min_stock_level, p.max_stock_level, p.current_stock, p.status, pc.name as category_name 
                FROM products p 
                LEFT JOIN product_categories pc ON p.category_id = pc.id 
                WHERE p.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prod) {
                $stmt = $pdo->prepare("SELECT id, sku, barcode, product_name as name, brand, category as category_name, size as unit, unit_cost as cost, unit_price as price, reorder_level as min_stock_level, critical_level as max_stock_level, stock_quantity as current_stock, status FROM inventory_products WHERE id = ? LIMIT 1");
                $stmt->execute([$id]);
                $prod = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$prod) {
                echo json_encode(['success' => false, 'message' => 'Merchandise product not found']);
                exit;
            }

            $pname = $prod['name'] ?? '';
            $sku   = $prod['sku']  ?? '';

            $matching_ids = [$id];
            if ($pname !== '') {
                try {
                    $stmt1 = $pdo->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) OR (sku = ? AND sku != '')");
                    $stmt1->execute([$pname, $sku]);
                    foreach ($stmt1->fetchAll(PDO::FETCH_COLUMN) as $pid) { $matching_ids[] = (int)$pid; }

                    $stmt2 = $pdo->prepare("SELECT id FROM inventory_products WHERE LOWER(product_name) = LOWER(?) OR (sku = ? AND sku != '')");
                    $stmt2->execute([$pname, $sku]);
                    foreach ($stmt2->fetchAll(PDO::FETCH_COLUMN) as $ipid) { $matching_ids[] = (int)$ipid; }
                } catch (Exception $e) {}
            }
            $matching_ids = array_values(array_unique($matching_ids));
            $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));

            try {
                $cost_stmt = $pdo->prepare("SELECT unit_cost FROM merchandise_batches WHERE product_id IN ($in_clause) ORDER BY id DESC LIMIT 1");
                $cost_stmt->execute($matching_ids);
                $latest_cost = $cost_stmt->fetchColumn();
                if ($latest_cost !== false && $latest_cost !== null) {
                    $prod['cost'] = (float)$latest_cost;
                }
            } catch (Exception $e) {}

            try {
                $stock_stmt = $pdo->prepare("SELECT SUM(remaining_qty) as total_stock, COUNT(id) as batch_count FROM merchandise_batches WHERE product_id IN ($in_clause) AND status = 'active'");
                $stock_stmt->execute($matching_ids);
                $stock_info = $stock_stmt->fetch(PDO::FETCH_ASSOC);
                if ($stock_info && $stock_info['total_stock'] !== null && (float)$stock_info['total_stock'] > 0) {
                    $prod['current_stock'] = (float)$stock_info['total_stock'];
                    $prod['batch_count'] = (int)$stock_info['batch_count'];
                } else {
                    $prod['batch_count'] = 0;
                }
            } catch (Exception $e) {
                $prod['batch_count'] = 0;
            }

            $batches = [];
            try {
                $b_stmt = $pdo->prepare("
                    SELECT 
                        id,
                        COALESCE(NULLIF(batch_number, ''), CONCAT('BT-', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(id, 4, '0'))) AS batch_number,
                        remaining_qty,
                        date_received,
                        status,
                        'N/A' AS expiration_date
                    FROM merchandise_batches 
                    WHERE product_id IN ($in_clause)
                    ORDER BY id DESC
                ");
                $b_stmt->execute($matching_ids);
                $batches = $b_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            if (empty($batches)) {
                try {
                    $si_stmt = $pdo->prepare("
                        SELECT 
                            id,
                            COALESCE(NULLIF(batch_ref, ''), CONCAT('BT-', DATE_FORMAT(encoded_at, '%Y%m%d'), '-', LPAD(id, 4, '0'))) AS batch_number,
                            qty_received AS remaining_qty,
                            DATE(encoded_at) AS date_received,
                            'active' AS status,
                            'N/A' AS expiration_date
                        FROM merchandise_stock_in
                        WHERE product_id IN ($in_clause) OR LOWER(product_name) = LOWER(?)
                        ORDER BY id DESC
                    ");
                    $si_params = array_merge($matching_ids, [$pname]);
                    $si_stmt->execute($si_params);
                    $batches = $si_stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {}
            }

            $price_history = [];
            try {
                $h_stmt = $pdo->prepare("
                    SELECT id, old_price, new_price, requested_by_name, approved_by_name, status, created_at, 'history' as source
                    FROM product_price_history
                    WHERE product_id IN ($in_clause)
                    ORDER BY created_at DESC
                ");
                $h_stmt->execute($matching_ids);
                $price_history = array_merge($price_history, $h_stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {}

            try {
                $a_stmt = $pdo->prepare("
                    SELECT id, old_value as old_price, new_value as new_price,
                           (SELECT username FROM users WHERE id=requested_by) as requested_by_name,
                           (SELECT username FROM users WHERE id=reviewed_by) as approved_by_name,
                           status, created_at, 'approval' as source
                    FROM pending_price_approvals
                    WHERE station_id = ? AND (product_id IN ($in_clause) OR LOWER(product_name) = LOWER(?)) AND product_type IN ('merchandise','product')
                    ORDER BY created_at DESC
                ");
                $a_params = array_merge([$station_id], $matching_ids, [$pname]);
                $a_stmt->execute($a_params);
                $price_history = array_merge($price_history, $a_stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {}

            usort($price_history, function($a, $b) {
                return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
            });

            $config_history = [];
            try {
                $ch_stmt = $pdo->prepare("SELECT id, field_name, old_value, new_value, changed_by_name, created_at FROM product_config_history WHERE product_id IN ($in_clause) ORDER BY id DESC");
                $ch_stmt->execute($matching_ids);
                $config_history = $ch_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            $status_history = [];
            try {
                $sh_stmt = $pdo->prepare("SELECT id, old_status, new_status, changed_by_name, reason, created_at FROM product_status_history WHERE product_id IN ($in_clause) ORDER BY id DESC");
                $sh_stmt->execute($matching_ids);
                $status_history = $sh_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            echo json_encode([
                'success' => true,
                'product' => $prod,
                'batches' => $batches,
                'price_history' => $price_history,
                'config_history' => $config_history,
                'status_history' => $status_history
            ]);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // ADMIN EDIT SERVICE TYPE DIRECTLY
        // ══════════════════════════════════════════════════════════════════════
        case 'admin_edit_service':
            $id            = (int)($_POST['id'] ?? 0);
            $service_name  = trim($_POST['service_name'] ?? '');
            $category      = trim($_POST['category'] ?? '');
            $service_key   = trim($_POST['service_key'] ?? '');
            $service_price = (float)($_POST['service_price'] ?? 0);
            $active        = (int)($_POST['active'] ?? 1);

            if ($id <= 0 || empty($service_name) || empty($category) || empty($service_key)) {
                echo json_encode(['success' => false, 'message' => 'Service name, category, and service key are required.']);
                exit;
            }

            $placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];
            if (in_array(strtolower($service_name), $placeholders, true)) {
                echo json_encode(['success' => false, 'message' => 'Service Name cannot be N/A or a placeholder.']);
                exit;
            }
            if (in_array(strtolower($category), $placeholders, true)) {
                echo json_encode(['success' => false, 'message' => 'Category cannot be N/A or a placeholder.']);
                exit;
            }
            if (in_array(strtolower($service_key), $placeholders, true)) {
                echo json_encode(['success' => false, 'message' => 'Service Key cannot be N/A or a placeholder.']);
                exit;
            }
            if ($service_price <= 0) {
                echo json_encode(['success' => false, 'message' => 'Service Price must be a valid number greater than 0.']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id, service_price FROM job_order_service_types WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $svc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$svc) { echo json_encode(['success' => false, 'message' => 'Service type not found']); exit; }

            $old_price = (float)$svc['service_price'];

            // Update job_order_service_types immediately
            $stmt = $pdo->prepare("UPDATE job_order_service_types SET service_name=?, category=?, service_key=?, service_price=?, active=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$service_name, $category, $service_key, $service_price, $active, $id]);

            // Mark any pending price request for this service as approved
            $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE product_type IN ('service','service_type') AND product_id=? AND status='pending'")
                ->execute([$me['id'], $me['id'], $id]);

            log_activity($pdo, $me['id'], 'Admin Edit Service Type', "Admin updated service {$service_name} price: ₱{$old_price} -> ₱{$service_price}");
            echo json_encode(['success' => true, 'message' => 'Service type updated successfully!']);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // ADMIN TOGGLE SERVICE ACTIVE STATUS
        // ══════════════════════════════════════════════════════════════════════
        case 'admin_toggle_service':
            $id     = (int)($_POST['id'] ?? 0);
            $active = (int)($_POST['active'] ?? 1);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid Service ID']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE job_order_service_types SET active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$active, $id]);
            $statusStr = $active ? 'Activated' : 'Deactivated';
            log_activity($pdo, $me['id'], 'Admin Toggle Service Status', "Admin {$statusStr} service #{$id}");
            echo json_encode(['success' => true, 'message' => "Service {$statusStr} successfully!"]);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // ADMIN RESTORE SERVICE FEES
        // ══════════════════════════════════════════════════════════════════════
        case 'restore_service_fees':
            $id           = (int)($_POST['id'] ?? 0);
            $old_svc_fee = (float)($_POST['old_service_fee'] ?? 0);
            $old_lab_fee = (float)($_POST['old_labor_fee'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid Service ID']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE job_order_service_types SET service_price = ?, labor_fee = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$old_svc_fee, $old_lab_fee, $id]);

            // Clear any pending approvals for this service
            $pdo->prepare("UPDATE pending_price_approvals SET status='cancelled', updated_at=NOW() WHERE product_type IN ('service','service_type') AND product_id=? AND status='pending'")
                ->execute([$id]);

            log_activity($pdo, $me['id'], 'Restore Service Fees', "Admin restored previous fees for service #{$id} (Service: ₱{$old_svc_fee}, Labor: ₱{$old_lab_fee})");
            echo json_encode(['success' => true, 'message' => 'Service fees restored successfully!']);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // GET PRICE REQUEST DETAILS (VIEW REQUEST MODAL)
        // ══════════════════════════════════════════════════════════════════════
        case 'get_request_details':
            $approval_id = (int)($_GET['approval_id'] ?? 0);
            if ($approval_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Approval ID']); exit; }

            $stmt = $pdo->prepare("
                SELECT ppa.*,
                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'Manager') AS requested_by_name
                FROM pending_price_approvals ppa
                LEFT JOIN users u ON u.id = ppa.manager_id OR u.id = ppa.requested_by
                WHERE ppa.id = ?
                LIMIT 1
            ");
            $stmt->execute([$approval_id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) { echo json_encode(['success' => false, 'message' => 'Price request not found']); exit; }

            if ($role !== 'superadmin' && (int)($req['station_id'] ?? 0) !== (int)$station_id) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: This price approval request belongs to another station.']);
                exit;
            }

            // Get product name if missing
            if (empty($req['product_name'])) {
                $pid = (int)$req['product_id'];
                $item = find_merchandise_pricing_item($pdo, (int)$station_id, $pid);
                $req['product_name'] = $item['product_name'] ?? "Product #$pid";
            }

            echo json_encode(['success' => true, 'request' => $req]);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // APPROVE PRICE CHANGE REQUEST
        // ══════════════════════════════════════════════════════════════════════
        case 'approve_price_request':
            $approval_id = (int)($_POST['approval_id'] ?? 0);
            if ($approval_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Approval ID']); exit; }

            $stmt = $pdo->prepare("SELECT * FROM pending_price_approvals WHERE id = ? AND status = 'pending'");
            $stmt->execute([$approval_id]);
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pending) {
                echo json_encode(['success' => false, 'message' => 'Price request not found or already processed']);
                exit;
            }

            if ($role !== 'superadmin' && (int)($pending['station_id'] ?? 0) !== (int)$station_id) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: You cannot approve price requests for another station.']);
                exit;
            }

            $pid           = (int)($pending['product_id'] ?? 0);
            $prod_type     = strtolower(trim($pending['product_type'] ?? ''));
            $prod_name     = trim($pending['product_name'] ?? '');
            $new_price_val = (float)($pending['new_price'] ?? $pending['new_value'] ?? 0);
            $old_price_val = (float)($pending['old_price'] ?? $pending['old_value'] ?? 0);
            $new_cost_val  = (float)($pending['new_cost'] ?? 0);
            $target_sid    = (int)($pending['station_id'] ?? $station_id);
            if ($target_sid <= 0) $target_sid = (int)$station_id;

            $is_fuel = in_array($prod_type, ['fuel', 'fuel_inventory'], true) || (!empty($pending['fuel_type_id']) && (int)$pending['fuel_type_id'] > 0);

            if ($is_fuel) {
                // ── 1. FUEL PRICE APPROVAL (Sync across ALL tanks sharing the same canonical fuel type) ──
                $fuel_type_id  = (int)($pending['fuel_type_id'] ?? 0);
                $raw_fuel_type = $prod_name;
                
                if ($pid > 0) {
                    $f_stmt = $pdo->prepare("SELECT fuel_type, fuel_type_id FROM fuel_inventory WHERE id = ? LIMIT 1");
                    $f_stmt->execute([$pid]);
                    $f_row = $f_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($f_row) {
                        if (empty($raw_fuel_type)) $raw_fuel_type = $f_row['fuel_type'];
                        if (!$fuel_type_id && !empty($f_row['fuel_type_id'])) $fuel_type_id = (int)$f_row['fuel_type_id'];
                    }
                }

                // Get all matching fuel_inventory IDs sharing the canonical fuel type at this station
                $matching_ids = get_matching_fuel_ids($pdo, $target_sid, $pid, $raw_fuel_type);
                $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));
                $canonical_name = get_canonical_fuel_name($raw_fuel_type);

                // Update ALL matching tanks in fuel_inventory at this station
                $upd_sql = "UPDATE fuel_inventory SET price_per_liter = ?, updated_by = ?, last_updated = NOW() WHERE station_id = ? AND id IN ($in_clause)";
                $upd_params = array_merge([$new_price_val, $me['id'], $target_sid], $matching_ids);
                $pdo->prepare($upd_sql)->execute($upd_params);

                // Sync to fuel_types (so POS & meter readings get the updated price)
                try {
                    if ($fuel_type_id > 0) {
                        $pdo->prepare("UPDATE fuel_types SET price_per_liter = ? WHERE id = ?")->execute([$new_price_val, $fuel_type_id]);
                    }
                    $pdo->prepare("UPDATE fuel_types SET price_per_liter = ? WHERE LOWER(name) = LOWER(?) OR LOWER(name) LIKE ?")
                        ->execute([$new_price_val, $canonical_name, '%' . strtolower($canonical_name) . '%']);
                } catch (Exception $e) {}

                // Sync to fuel_pricing (active pricing table used in reports)
                try {
                    $ft_ids = [];
                    if ($fuel_type_id > 0) $ft_ids[] = $fuel_type_id;
                    $ft_stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(name) = LOWER(?) OR LOWER(name) LIKE ?");
                    $ft_stmt->execute([$canonical_name, '%' . strtolower($canonical_name) . '%']);
                    foreach ($ft_stmt->fetchAll(PDO::FETCH_COLUMN) as $ftid) {
                        $ft_ids[] = (int)$ftid;
                    }
                    $ft_ids = array_values(array_unique($ft_ids));

                    foreach ($ft_ids as $ftid) {
                        $fp_check = $pdo->prepare("SELECT id FROM fuel_pricing WHERE station_id = ? AND fuel_type_id = ? AND is_active = 1 LIMIT 1");
                        $fp_check->execute([$target_sid, $ftid]);
                        $fp_id = $fp_check->fetchColumn();
                        if ($fp_id) {
                            $pdo->prepare("UPDATE fuel_pricing SET price_per_liter = ?, updated_at = NOW() WHERE id = ?")
                                ->execute([$new_price_val, $fp_id]);
                        } else {
                            $pdo->prepare("INSERT INTO fuel_pricing (station_id, fuel_type_id, price_per_liter, effective_date, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, NOW(), 1, ?, NOW(), NOW())")
                                ->execute([$target_sid, $ftid, $new_price_val, $me['id']]);
                        }
                    }
                } catch (Exception $e) {}

                // Mark ALL pending price approvals for this station and matching fuel IDs / canonical fuel type as APPROVED
                try {
                    $app_sql = "UPDATE pending_price_approvals
                                SET status = 'approved', admin_id = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                                WHERE station_id = ? AND status = 'pending'
                                  AND (product_id IN ($in_clause) OR LOWER(product_name) LIKE ? OR LOWER(product_name) LIKE ?)";
                    $app_params = array_merge([$me['id'], $me['id'], $target_sid], $matching_ids, ['%' . strtolower($canonical_name) . '%', '%' . strtolower($raw_fuel_type) . '%']);
                    $pdo->prepare($app_sql)->execute($app_params);
                } catch (Exception $e) {}

                // Log to fuel_price_history for each matching tank
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS fuel_price_history (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            station_id INT NOT NULL DEFAULT 0,
                            fuel_id INT NOT NULL,
                            fuel_type VARCHAR(100) NULL,
                            old_price DECIMAL(12,2) DEFAULT 0,
                            new_price DECIMAL(12,2) DEFAULT 0,
                            difference DECIMAL(12,2) DEFAULT 0,
                            reason VARCHAR(255) NULL,
                            requested_by INT NULL,
                            approved_by INT NULL,
                            updated_by INT NULL,
                            status VARCHAR(50) DEFAULT 'Approved',
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            INDEX (fuel_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                    $diff = $new_price_val - $old_price_val;
                    $req_by = (int)($pending['requested_by'] ?? $pending['manager_id'] ?? $me['id']);
                    foreach ($matching_ids as $fid) {
                        $pdo->prepare("
                            INSERT INTO fuel_price_history (station_id, fuel_id, fuel_type, old_price, new_price, difference, reason, requested_by, approved_by, updated_by, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', NOW())
                        ")->execute([$target_sid, $fid, $canonical_name, $old_price_val, $new_price_val, $diff, "Approved Price Change Request #$approval_id", $req_by, $me['id'], $me['id']]);
                    }
                } catch (Exception $e) {}

                log_activity($pdo, $me['id'], 'Approve Fuel Price Change', "Admin approved price change for fuel '{$canonical_name}' (₱{$old_price_val} → ₱{$new_price_val}) across " . count($matching_ids) . " tanks at Station #{$target_sid}");

            } elseif ($prod_type === 'service_type' || $prod_type === 'service') {
                // ── 2. SERVICE TYPE PRICE APPROVAL ──
                $new_labor_val = (float)($pending['new_cost'] ?? 0);
                try {
                    $pdo->prepare("UPDATE job_order_service_types SET service_price = ?, labor_fee = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$new_price_val, $new_labor_val, $pid]);
                } catch (Exception $e) {}

                $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
                    ->execute([$me['id'], $me['id'], $approval_id]);

                log_activity($pdo, $me['id'], 'Approve Service Price Change', "Admin approved service price change for product #{$pid} from ₱{$old_price_val} to ₱{$new_price_val}");

            } else {
                // ── 3. MERCHANDISE PRICE APPROVAL ──
                try {
                    $pdo->prepare("UPDATE inventory_products SET unit_cost=?, unit_price=?, updated_at=NOW() WHERE id=?")
                        ->execute([$new_cost_val, $new_price_val, $pid]);
                } catch (Exception $e) {}

                try {
                    $pdo->prepare("UPDATE products SET cost=?, price=?, updated_at=NOW() WHERE id=?")
                        ->execute([$new_cost_val, $new_price_val, $pid]);
                } catch (Exception $e) {}

                try {
                    $si_stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                    $si_stmt->execute([$target_sid, $pid]);
                    $si_id = (int)($si_stmt->fetchColumn() ?: 0);
                    if ($si_id > 0) {
                        $pdo->prepare("UPDATE station_inventory SET cost=?, price=?, last_updated=NOW() WHERE id=?")
                            ->execute([$new_cost_val, $new_price_val, $si_id]);
                    } else {
                        $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, cost, price, status, last_updated) VALUES (?, ?, 0, ?, ?, 'active', NOW())")
                            ->execute([$target_sid, $pid, $new_cost_val, $new_price_val]);
                    }
                } catch (Exception $e) {}

                $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
                    ->execute([$me['id'], $me['id'], $approval_id]);

                // Log to product_price_history
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_price_history` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `station_id` INT NOT NULL,
                        `product_id` INT NOT NULL,
                        `old_price` DECIMAL(10,2) NOT NULL,
                        `new_price` DECIMAL(10,2) NOT NULL,
                        `requested_by` INT NULL,
                        `requested_by_name` VARCHAR(255) NULL,
                        `approved_by` INT NULL,
                        `approved_by_name` VARCHAR(255) NULL,
                        `status` VARCHAR(50) NOT NULL DEFAULT 'Approved',
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $req_by_name = (string)($pending['requested_by_name'] ?? 'Manager');
                    $app_by_name = $me['username'] ?? ($me['first_name'] ?? 'Admin');
                    $req_by_id = (int)($pending['requested_by'] ?? $pending['manager_id'] ?? 0);

                    $pdo->prepare("INSERT INTO product_price_history (station_id, product_id, old_price, new_price, requested_by, requested_by_name, approved_by, approved_by_name, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Approved', NOW())")
                        ->execute([$target_sid, $pid, $old_price_val, $new_price_val, $req_by_id, $req_by_name, $me['id'], $app_by_name]);
                } catch (Exception $e) {}

                log_activity($pdo, $me['id'], 'Approve Price Change', "Admin approved price change for product #{$pid} from ₱{$old_price_val} to ₱{$new_price_val}");
            }

            // Notify Manager
            $manager_user_id = (int)($pending['manager_id'] ?? $pending['requested_by'] ?? 0);
            if ($manager_user_id > 0) {
                $pname = !empty($prod_name) ? $prod_name : "Product #$pid";
                try {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, status, created_at)
                        VALUES (?, 'price_approved', 'Price Change Approved', ?, 'unread', NOW())
                    ")->execute([$manager_user_id, "Your price change request for '{$pname}' (₱{$old_price_val} → ₱{$new_price_val}) has been APPROVED."]);
                } catch (Exception $e) {}
                try {
                    $pdo->prepare("
                        INSERT INTO user_notifications (user_id, type, title, message, is_read, created_at)
                        VALUES (?, 'price_approved', 'Price Change Approved', ?, 0, NOW())
                    ")->execute([$manager_user_id, "Your price change request for '{$pname}' (₱{$old_price_val} → ₱{$new_price_val}) has been APPROVED."]);
                } catch (Exception $e) {}
            }

            echo json_encode(['success' => true, 'message' => 'Price change request approved successfully!']);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // REJECT PRICE CHANGE REQUEST
        // ══════════════════════════════════════════════════════════════════════
        case 'reject_price_request':
            $approval_id      = (int)($_POST['approval_id'] ?? 0);
            $rejection_reason = trim($_POST['rejection_reason'] ?? $_POST['remarks'] ?? '');

            if ($approval_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Approval ID']); exit; }

            $stmt = $pdo->prepare("SELECT * FROM pending_price_approvals WHERE id = ? AND status = 'pending'");
            $stmt->execute([$approval_id]);
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pending) {
                echo json_encode(['success' => false, 'message' => 'Price request not found or already processed']);
                exit;
            }

            if ($role !== 'superadmin' && (int)($pending['station_id'] ?? 0) !== (int)$station_id) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: You cannot reject price requests for another station.']);
                exit;
            }

            $pid        = (int)($pending['product_id'] ?? 0);
            $prod_type  = strtolower(trim($pending['product_type'] ?? ''));
            $prod_name  = trim($pending['product_name'] ?? '');
            $target_sid = (int)($pending['station_id'] ?? $station_id);
            if ($target_sid <= 0) $target_sid = (int)$station_id;

            $is_fuel = in_array($prod_type, ['fuel', 'fuel_inventory'], true) || (!empty($pending['fuel_type_id']) && (int)$pending['fuel_type_id'] > 0);

            if ($is_fuel) {
                $matching_ids = get_matching_fuel_ids($pdo, $target_sid, $pid, $prod_name);
                $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));
                $canonical_name = get_canonical_fuel_name($prod_name);

                $app_sql = "UPDATE pending_price_approvals
                            SET status='rejected', rejection_reason=?, reviewer_notes=?, admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW()
                            WHERE station_id=? AND status='pending'
                              AND (product_id IN ($in_clause) OR LOWER(product_name) LIKE ? OR LOWER(product_name) LIKE ?)";
                $app_params = array_merge([$rejection_reason, $rejection_reason, $me['id'], $me['id'], $target_sid], $matching_ids, ['%' . strtolower($canonical_name) . '%', '%' . strtolower($prod_name) . '%']);
                $pdo->prepare($app_sql)->execute($app_params);
            } else {
                $pdo->prepare("
                    UPDATE pending_price_approvals
                    SET status='rejected', rejection_reason=?, reviewer_notes=?, admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW()
                    WHERE id=?
                ")->execute([$rejection_reason, $rejection_reason, $me['id'], $me['id'], $approval_id]);
            }

            // Save Audit Trail
            log_activity($pdo, $me['id'], 'Reject Price Change', "Admin rejected price change request #{$approval_id}. Reason: {$rejection_reason}");

            // Notify Manager
            $manager_user_id = (int)($pending['manager_id'] ?? $pending['requested_by'] ?? 0);
            if ($manager_user_id > 0) {
                $pname = !empty($prod_name) ? $prod_name : "Product #$pid";
                try {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, type, title, message, status, created_at)
                        VALUES (?, 'price_rejected', 'Price Change Rejected', ?, 'unread', NOW())
                    ")->execute([$manager_user_id, "Your price change request for '{$pname}' was REJECTED. Reason: {$rejection_reason}"]);
                } catch (Exception $e) {}
                try {
                    $pdo->prepare("
                        INSERT INTO user_notifications (user_id, type, title, message, is_read, created_at)
                        VALUES (?, 'price_rejected', 'Price Change Rejected', ?, 0, NOW())
                    ")->execute([$manager_user_id, "Your price change request for '{$pname}' was REJECTED. Reason: {$rejection_reason}"]);
                } catch (Exception $e) {}
            }

            echo json_encode(['success' => true, 'message' => 'Price change request rejected.']);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // GET PRICE HISTORY
        // ══════════════════════════════════════════════════════════════════════
        case 'get_price_history':
            $product_id = (int)($_GET['product_id'] ?? 0);
            if ($product_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Product ID']); exit; }

            // Fetch from pending_price_approvals
            $history = [];
            try {
                $stmt = $pdo->prepare("
                    SELECT ppa.id, ppa.old_price, ppa.new_price, ppa.created_at AS date_requested, ppa.reviewed_at AS date_approved,
                           ppa.status, ppa.rejection_reason,
                           COALESCE(CONCAT(u1.first_name, ' ', u1.last_name), u1.name, u1.username, 'Manager') AS requested_by,
                           COALESCE(CONCAT(u2.first_name, ' ', u2.last_name), u2.name, u2.username, 'Admin') AS approved_by
                    FROM pending_price_approvals ppa
                    LEFT JOIN users u1 ON u1.id = ppa.manager_id OR u1.id = ppa.requested_by
                    LEFT JOIN users u2 ON u2.id = ppa.admin_id OR u2.id = ppa.reviewed_by
                    WHERE ppa.product_id = ?
                    ORDER BY ppa.created_at DESC
                ");
                $stmt->execute([$product_id]);
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            // Also fetch from fuel_price_history if present
            try {
                $stmt2 = $pdo->prepare("
                    SELECT fph.id, fph.old_price, fph.new_price, fph.created_at AS date_requested, fph.created_at AS date_approved,
                           'approved' AS status, '' AS rejection_reason,
                           COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'Manager') AS requested_by,
                           'Admin' AS approved_by
                    FROM fuel_price_history fph
                    LEFT JOIN users u ON u.id = fph.updated_by
                    WHERE fph.fuel_id = ?
                    ORDER BY fph.created_at DESC
                ");
                $stmt2->execute([$product_id]);
                $history2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $history = array_merge($history, $history2);
            } catch (Exception $e) {}

            echo json_encode(['success' => true, 'history' => $history]);
            exit;

        // ══════════════════════════════════════════════════════════════════════
        // GET PRODUCT BATCHES (WITH FALLBACK)
        // ══════════════════════════════════════════════════════════════════════
        case 'get_product_batches':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid Product ID']); exit; }

            // 1. Try merchandise_batches
            $stmt = $pdo->prepare("
                SELECT mb.id,
                       COALESCE(NULLIF(mb.batch_number, ''), CONCAT('BT-', DATE_FORMAT(mb.created_at, '%Y%m%d'), '-', LPAD(mb.id, 4, '0'))) AS batch_number,
                       mb.quantity_received,
                       mb.remaining_qty,
                       mb.unit_cost,
                       mb.selling_price,
                       mb.date_received,
                       mb.status,
                       mb.supplier,
                       mb.notes,
                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'System') AS encoded_by
                FROM merchandise_batches mb
                LEFT JOIN users u ON u.id = mb.encoded_by
                WHERE mb.product_id = ?
                ORDER BY mb.date_received ASC, mb.id ASC
            ");
            $stmt->execute([$id]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Fallback to merchandise_stock_in
            if (empty($batches)) {
                $stmt2 = $pdo->prepare("
                    SELECT msi.id,
                           COALESCE(NULLIF(msi.batch_ref, ''), CONCAT('BT-', DATE_FORMAT(msi.encoded_at, '%Y%m%d'), '-', LPAD(msi.id, 4, '0'))) AS batch_number,
                           msi.qty_received AS quantity_received,
                           msi.qty_received AS remaining_qty,
                           msi.unit_cost,
                           msi.selling_price,
                           DATE(msi.encoded_at) AS date_received,
                           'active' AS status,
                           'Petron Corporation' AS supplier,
                           msi.remarks AS notes,
                           COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'System') AS encoded_by
                    FROM merchandise_stock_in msi
                    LEFT JOIN users u ON u.id = msi.encoded_by
                    WHERE msi.product_id = ?
                    ORDER BY msi.encoded_at ASC, msi.id ASC
                ");
                $stmt2->execute([$id]);
                $batches = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'batches' => $batches]);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
