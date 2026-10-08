<?php
/**
 * Manager Set Prices Handler
 * Handles: Add fuel product, Edit fuel price, Deactivate fuel product
 */

ob_start(); // Buffer any stray PHP warnings/notices so they don't corrupt JSON

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

// Schema safety: widen fuel_inventory status column to VARCHAR(50) so 'active' and 'inactive' persist across page refreshes
try {
    $pdo->exec("ALTER TABLE fuel_inventory MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'active'");
} catch (Exception $e) {}

ob_clean(); // Discard any warnings printed before this point
header('Content-Type: application/json');

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Access control
if ($role !== 'manager') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if ((int)$station_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'No station assigned']);
    exit;
}

try {
    $pdo->exec("ALTER TABLE pending_price_approvals ADD COLUMN reason TEXT DEFAULT NULL");
} catch (Exception $e) {}

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

// Handle GET requests for data retrieval
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_fuel_details') {
        $fuel_id = (int)($_GET['id'] ?? 0);
        if ($fuel_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT f.*, COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'System') as updated_by_name FROM fuel_inventory f LEFT JOIN users u ON f.updated_by=u.id WHERE f.id=? AND f.station_id=? LIMIT 1");
            $stmt->execute([$fuel_id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fuel) { echo json_encode(['success'=>false,'message'=>'Fuel not found']); exit; }
            if ($fuel['last_updated']) $fuel['last_updated'] = date('M d, Y g:i A', strtotime($fuel['last_updated']));
            
            // Calculate Available Capacity
            $cap = (float)($fuel['capacity'] ?? 0);
            $stock = (float)($fuel['current_stock'] ?? ($fuel['current_level'] ?? 0));
            $fuel['current_stock'] = $stock;
            $fuel['available_capacity'] = max(0, $cap - $stock);

            // Find all matching fuel IDs for this station sharing the same fuel type
            $matching_ids = get_matching_fuel_ids($pdo, $station_id, $fuel_id, $fuel['fuel_type']);
            $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));

            // Check Price Request Status in pending_price_approvals
            $fuel['price_request_status'] = 'No Pending Request';
            try {
                $pa_params = array_merge([$station_id], $matching_ids, [$fuel['fuel_type'], get_canonical_fuel_name($fuel['fuel_type'])]);
                $pa_stmt = $pdo->prepare("SELECT new_price, status, created_at FROM pending_price_approvals WHERE station_id = ? AND (product_id IN ($in_clause) OR LOWER(product_name) = LOWER(?) OR LOWER(product_name) = LOWER(?)) AND status = 'pending' ORDER BY id DESC LIMIT 1");
                $pa_stmt->execute($pa_params);
                $pa = $pa_stmt->fetch(PDO::FETCH_ASSOC);
                if ($pa) {
                    $fuel['price_request_status'] = 'Pending Admin Approval (₱' . number_format((float)$pa['new_price'], 2) . ')';
                }
            } catch (Exception $e) {}

            // 1. Price History (Combines fuel_price_history & pending_price_approvals)
            $history = [];
            $seen_keys = [];
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_price_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL DEFAULT 0,
                  `fuel_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NULL,
                  `old_price` DECIMAL(12,2) DEFAULT 0,
                  `new_price` DECIMAL(12,2) DEFAULT 0,
                  `difference` DECIMAL(12,2) DEFAULT 0,
                  `reason` VARCHAR(255) NULL,
                  `requested_by` INT NULL,
                  `requested_by_name` VARCHAR(255) NULL,
                  `approved_by` INT NULL,
                  `approved_by_name` VARCHAR(255) NULL,
                  `updated_by` INT NULL,
                  `status` VARCHAR(50) DEFAULT 'Approved',
                  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`fuel_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmt = $pdo->prepare("SELECT h.id, h.created_at, h.old_price, h.new_price, h.reason, h.status,
                                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, h.requested_by_name, 'Manager') as requested_by_name,
                                       COALESCE(CONCAT(a.first_name, ' ', a.last_name), a.name, a.username, h.approved_by_name, 'System') as approved_by_name
                                FROM fuel_price_history h 
                                LEFT JOIN users u ON h.requested_by=u.id OR h.updated_by=u.id 
                                LEFT JOIN users a ON h.approved_by=a.id 
                                WHERE h.fuel_id IN ($in_clause) ORDER BY h.created_at DESC LIMIT 30");
                $stmt->execute($matching_ids);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = date('Y-m-d H:i', strtotime($row['created_at'])) . '_' . (float)$row['new_price'];
                    $seen_keys[$key] = true;
                    $history[] = $row;
                }
            } catch (Exception $e) {}

            try {
                $raw_fuel_type = $fuel['fuel_type'] ?? '';
                $canonical_fuel_type = get_canonical_fuel_name($raw_fuel_type);
                $pa_params = array_merge([$station_id], $matching_ids, [strtolower(trim($raw_fuel_type)), strtolower(trim($canonical_fuel_type))]);
                $pa_sql = "SELECT p.id, p.created_at, COALESCE(p.old_price, p.old_value) AS old_price, COALESCE(p.new_price, p.new_value) AS new_price, p.reason, p.status,
                                  COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, 'Manager') as requested_by_name,
                                  COALESCE(CONCAT(a.first_name, ' ', a.last_name), a.name, a.username, 'Admin') as approved_by_name
                           FROM pending_price_approvals p
                           LEFT JOIN users u ON p.requested_by=u.id OR p.manager_id=u.id
                           LEFT JOIN users a ON p.reviewed_by=a.id OR p.admin_id=a.id
                           WHERE p.station_id = ? AND p.product_type IN ('fuel', 'fuel_inventory')
                             AND (p.product_id IN ($in_clause) OR LOWER(p.product_name) = ? OR LOWER(p.product_name) = ?)
                           ORDER BY p.id DESC LIMIT 30";
                $stmt2 = $pdo->prepare($pa_sql);
                $stmt2->execute($pa_params);
                foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = date('Y-m-d H:i', strtotime($row['created_at'])) . '_' . (float)$row['new_price'];
                    if (!isset($seen_keys[$key])) {
                        $seen_keys[$key] = true;
                        $history[] = $row;
                    }
                }
            } catch (Exception $e) {}

            usort($history, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            foreach ($history as &$h) { 
                if ($h['created_at']) $h['created_at'] = date('M d, Y g:i A', strtotime($h['created_at'])); 
                $h['difference'] = (float)($h['new_price'] ?? 0) - (float)($h['old_price'] ?? 0);
                $h['status'] = !empty($h['status']) ? ucfirst($h['status']) : 'Approved';
            }

            // 2. Configuration History
            $config_history = [];
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_config_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL,
                  `fuel_inventory_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `field_name` VARCHAR(100) NOT NULL,
                  `old_value` VARCHAR(255) NULL,
                  `new_value` VARCHAR(255) NULL,
                  `updated_by` INT NULL,
                  `updated_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmt = $pdo->prepare("SELECT ch.*, COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, ch.updated_by_name, 'Manager') as updated_by_name FROM fuel_config_history ch LEFT JOIN users u ON ch.updated_by=u.id WHERE ch.fuel_inventory_id IN ($in_clause) AND ch.field_name IN ('Fuel Name', 'Tank Capacity', 'Capacity', 'Critical Level', 'Reorder Level', 'UGT No', 'UGT Number') ORDER BY ch.created_at DESC LIMIT 30");
                $stmt->execute($matching_ids);
                $config_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($config_history as &$ch) {
                    if ($ch['created_at']) $ch['created_at'] = date('M d, Y g:i A', strtotime($ch['created_at']));
                }
            } catch (Exception $e) {}

            // 3. Status History
            $status_history = [];
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_status_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL,
                  `fuel_inventory_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `old_status` VARCHAR(50) NULL,
                  `new_status` VARCHAR(50) NULL,
                  `status` VARCHAR(50) NOT NULL,
                  `reason` VARCHAR(255) NULL,
                  `changed_by` INT NULL,
                  `changed_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $stmt = $pdo->prepare("SELECT sh.*, COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, u.username, sh.changed_by_name, 'System') as changed_by_name FROM fuel_status_history sh LEFT JOIN users u ON sh.changed_by=u.id WHERE sh.fuel_inventory_id IN ($in_clause) ORDER BY sh.created_at DESC LIMIT 30");
                $stmt->execute($matching_ids);
                $status_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($status_history as &$sh) {
                    if ($sh['created_at']) $sh['created_at'] = date('M d, Y g:i A', strtotime($sh['created_at']));
                }
            } catch (Exception $e) {}

            $pumps = fetch_pumps_for_fuel_product($pdo, $station_id, $fuel);
            $fuel['clean_fuel_type'] = get_canonical_fuel_name($fuel['fuel_type']);
            $fuel['num_pumps']       = max((int)($fuel['num_pumps'] ?? 0), count($pumps));
            $fuel['pump_count']      = count($pumps);
            echo json_encode(['success'=>true,'fuel'=>$fuel,'pumps'=>$pumps,'history'=>$history,'config_history'=>$config_history,'status_history'=>$status_history]); exit;
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }

    if ($action === 'get_merch_details') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
            $row = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
            $row['size'] = $row['unit'] ?? 'Piece (pc)';
            echo json_encode(['success'=>true,'item'=>$row]); exit;
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }

    if ($action === 'get_product_batches') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
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

            // 2. Fallback to merchandise_stock_in if no records in merchandise_batches
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

            echo json_encode(['success'=>true,'batches'=>$batches]); exit;
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
        }
    }

    if ($action === 'get_service_details') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
            $stmt = $pdo->prepare("
                SELECT id, service_code, service_name, category, service_key,
                       service_price, labor_fee, estimated_duration, required_mechanics,
                       description, active, status, created_at, updated_at
                FROM job_order_service_types WHERE id=? LIMIT 1
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
            echo json_encode(['success'=>true,'service'=>$row]); exit;
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }

    if ($action === 'get_service_history') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        try {
            // Ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS service_fee_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                service_id INT NOT NULL,
                change_type ENUM('service_fee','labor_fee','created','updated','activated','deactivated') NOT NULL DEFAULT 'updated',
                old_service_fee DECIMAL(10,2) DEFAULT NULL,
                new_service_fee DECIMAL(10,2) DEFAULT NULL,
                old_labor_fee DECIMAL(10,2) DEFAULT NULL,
                new_labor_fee DECIMAL(10,2) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                changed_by INT DEFAULT NULL,
                changed_by_name VARCHAR(100) DEFAULT NULL,
                changed_by_role VARCHAR(50) DEFAULT NULL,
                approval_status ENUM('pending','approved','rejected','direct') NOT NULL DEFAULT 'direct',
                approval_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_service_id (service_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $stmt = $pdo->prepare("
                SELECT sfh.*, u.first_name, u.last_name
                FROM service_fee_history sfh
                LEFT JOIN users u ON sfh.changed_by = u.id
                WHERE sfh.service_id = ?
                ORDER BY sfh.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$id]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'history'=>$history]); exit;
        } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }

    if ($action === 'lookup_barcode') {
        $barcode = trim($_GET['barcode'] ?? '');
        if ($barcode === '') {
            echo json_encode(['success' => false, 'message' => 'No barcode provided']);
            exit;
        }

        $product = null;

        // 1. Search products table first
        try {
            $stmt = $pdo->prepare("
                SELECT p.id, p.sku, p.barcode, p.name, p.brand, p.unit, p.price, pc.name as category_name
                FROM products p
                LEFT JOIN product_categories pc ON p.category_id = pc.id
                WHERE p.barcode = ?
                LIMIT 1
            ");
            $stmt->execute([$barcode]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        // 2. Fallback to inventory_products
        if (!$product) {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, sku, barcode, product_name as name, brand, category as category_name, size as unit, unit_price as price
                    FROM inventory_products
                    WHERE barcode = ?
                    LIMIT 1
                ");
                $stmt->execute([$barcode]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }

        if ($product) {
            echo json_encode(['success' => true, 'product' => $product]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No product found with this barcode']);
        }
        exit;
    }

    if ($action === 'get_merchandise_details') {

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }

        // 1. Master product data
        $stmt = $pdo->prepare("
            SELECT p.id, p.sku, p.barcode, p.name, p.brand, p.unit, p.cost, p.price, p.min_stock_level, p.max_stock_level, p.current_stock, p.status, p.expiration_date, pc.name as category_name 
            FROM products p 
            LEFT JOIN product_categories pc ON p.category_id = pc.id 
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            $stmt = $pdo->prepare("SELECT id, sku, barcode, product_name as name, brand, category as category_name, size as unit, unit_cost as cost, unit_price as price, reorder_level as min_stock_level, critical_level as max_stock_level, stock_quantity as current_stock, status, expiration_date FROM inventory_products WHERE id = ? LIMIT 1");
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

        // Cost from latest approved Stock-In/Batch
        try {
            $cost_stmt = $pdo->prepare("SELECT unit_cost FROM merchandise_batches WHERE product_id IN ($in_clause) ORDER BY id DESC LIMIT 1");
            $cost_stmt->execute($matching_ids);
            $latest_cost = $cost_stmt->fetchColumn();
            if ($latest_cost !== false && $latest_cost !== null) {
                $prod['cost'] = (float)$latest_cost;
            }
        } catch (Exception $e) {}

        // Total stock across batches
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

        // 2. Batches (Read Only)
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

        // 3. Price History
        $price_history = [];
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

        // 4. Config History
        $config_history = [];
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

            $ch_stmt = $pdo->prepare("SELECT id, field_name, old_value, new_value, changed_by_name, created_at FROM product_config_history WHERE product_id IN ($in_clause) ORDER BY id DESC");
            $ch_stmt->execute($matching_ids);
            $config_history = $ch_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        // 5. Status History
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
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // ══════════════════════════════════════════════════════════════════════
        // ADD FUEL PRODUCT
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
                } else {
                    $ins_ft = $pdo->prepare("INSERT INTO fuel_types (name, price_per_liter) VALUES (?, ?)");
                    $ins_ft->execute([$fuel_type, $price]);
                    $fuel_type_id = (int)$pdo->lastInsertId();
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
                        $p_num_idx = (int)ceil($pi / 2);
                        $n_num_idx = (int)((($pi - 1) % 2) + 1);
                        $code = "{$p_num_idx}-{$n_num_idx}";
                        $pump_num = "{$clean_fuel_tag} - {$code}";
                        $pump_name = "Pump {$p_num_idx}";
                        $nozzle_num = "Nozzle {$n_num_idx}";
                        
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
                                $pdo->prepare("UPDATE nozzles SET pump_name = ?, nozzle_number = ?, ugt_no = ?, status = ? WHERE id = ?")
                                    ->execute([$pump_name, $nozzle_num, $ugt_no, $p_status, $noz_id]);
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

                // Log Audit Trail
                log_activity($pdo, $me['id'], 'Add Fuel Product',
                    "Manager added new fuel product: {$fuel_type} ({$ugt_no}) with {$num_pumps} pumps at ₱{$price}/L. Status: {$status}. Remarks: {$remarks}");

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
        // EDIT FUEL PRICE
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_fuel_price':
            $id             = (int)($_POST['id'] ?? 0);
            $new_price      = (float)($_POST['price'] ?? 0);
            $reason         = trim($_POST['reason'] ?? '');
            $effective_date = trim($_POST['effective_date'] ?? date('Y-m-d'));
            
            if ($id <= 0 || $new_price < 0 || empty($reason)) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }
            
            // Verify fuel belongs to manager's station
            $stmt = $pdo->prepare("
                SELECT fuel_type, price_per_liter 
                FROM fuel_inventory 
                WHERE id = ? AND station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found']);
                exit;
            }
            
            $old_price = (float)$fuel['price_per_liter'];

            if ($old_price == $new_price) {
                echo json_encode(['success' => true, 'message' => 'Price is unchanged.']);
                exit;
            }
            
            $matching_ids = get_matching_fuel_ids($pdo, $station_id, $id, $fuel['fuel_type']);
            $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));

            // Clear any previous pending approval for matching fuel items
            $del_params = array_merge([$station_id], $matching_ids);
            $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id IN ($in_clause) AND status='pending'")
                ->execute($del_params);

            // Submit new pending price approval for all matching fuel tank records (Requires Admin Approval)
            $stmt = $pdo->prepare("
                INSERT INTO pending_price_approvals
                (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_cost, new_cost, old_price, new_price, requested_by, manager_id, status, created_at)
                VALUES (?, 'fuel', ?, ?, 'price', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            foreach ($matching_ids as $m_id) {
                $stmt->execute([
                    $station_id, $m_id, $fuel['fuel_type'],
                    $old_price, $new_price,
                    $old_price, $new_price,
                    $old_price, $new_price,
                    $me['id'], $me['id']
                ]);
            }
            
            // Log activity
            log_activity($pdo, $me['id'], 'Edit Fuel Price Request',
                "Manager requested price change for {$fuel['fuel_type']} from ₱{$old_price} to ₱{$new_price}. Reason: {$reason}");
            
            echo json_encode(['success' => true, 'message' => 'Price change submitted for Admin approval.']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // SUBMIT PRICE RESTORATION REQUEST
        // ══════════════════════════════════════════════════════════════════════
        case 'submit_price_restoration':
            $fuel_id       = (int)($_POST['fuel_id'] ?? 0);
            $target_price  = (float)($_POST['target_price'] ?? 0);
            $reason        = trim($_POST['reason'] ?? '');
            
            if ($fuel_id <= 0 || $target_price <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid price restoration parameters.']);
                exit;
            }
            
            // Verify fuel product belongs to manager's station
            $stmt = $pdo->prepare("SELECT fuel_type, price_per_liter FROM fuel_inventory WHERE id = ? AND station_id = ? LIMIT 1");
            $stmt->execute([$fuel_id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found.']);
                exit;
            }
            
            $current_price = (float)$fuel['price_per_liter'];
            if (abs($current_price - $target_price) < 0.001) {
                echo json_encode(['success' => false, 'message' => 'Target price is already the current selling price.']);
                exit;
            }
            
            $reason_text = !empty($reason) ? "Price Restoration: " . $reason : "Price Restoration Request to ₱" . number_format($target_price, 2) . "/L";
            
            $matching_ids = get_matching_fuel_ids($pdo, $station_id, $fuel_id, $fuel['fuel_type']);
            $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));

            // Delete any existing pending approval for matching fuel items to prevent duplicates
            $del_params = array_merge([$station_id], $matching_ids);
            $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id IN ($in_clause) AND status='pending'")
                ->execute($del_params);

            // Submit new pending price approval for all matching fuel tank records (Requires Admin Approval)
            $stmt = $pdo->prepare("
                INSERT INTO pending_price_approvals
                (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_cost, new_cost, old_price, new_price, requested_by, manager_id, reason, status, created_at)
                VALUES (?, 'fuel', ?, ?, 'price', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            foreach ($matching_ids as $m_id) {
                $stmt->execute([
                    $station_id, $m_id, $fuel['fuel_type'],
                    $current_price, $target_price,
                    $current_price, $target_price,
                    $current_price, $target_price,
                    $me['id'], $me['id'],
                    $reason_text
                ]);
            }
            
            // Log Activity
            log_activity($pdo, $me['id'], 'Price Restoration Request',
                "Manager submitted price restoration request for {$fuel['fuel_type']} from ₱" . number_format($current_price, 2) . " to ₱" . number_format($target_price, 2) . ". Reason: {$reason_text}");
            
            echo json_encode(['success' => true, 'message' => 'Price restoration request submitted for Admin approval.']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ROLLBACK PRICE
        // ══════════════════════════════════════════════════════════════════════
        case 'rollback_price':
            $fuel_id    = (int)($_POST['fuel_id'] ?? 0);
            $history_id = (int)($_POST['history_id'] ?? 0);
            $reason     = trim($_POST['reason'] ?? '');
            
            if ($fuel_id <= 0 || $history_id <= 0 || empty($reason)) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }
            
            // Get the history record
            $stmt = $pdo->prepare("
                SELECT h.*, f.fuel_type, f.price_per_liter as current_price
                FROM fuel_price_history h
                JOIN fuel_inventory f ON h.fuel_id = f.id
                WHERE h.id = ? AND h.fuel_id = ? AND f.station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$history_id, $fuel_id, $station_id]);
            $history = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$history) {
                echo json_encode(['success' => false, 'message' => 'History record not found']);
                exit;
            }
            
            $rollback_price = (float)$history['old_price'];
            $current_price  = (float)$history['current_price'];
            
            $matching_ids = get_matching_fuel_ids($pdo, $station_id, $fuel_id, $history['fuel_type']);
            $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));

            // Insert new history record for all matching fuel tank records
            $hist_stmt = $pdo->prepare("
                INSERT INTO fuel_price_history 
                (station_id, fuel_id, fuel_type, old_price, new_price, reason, effective_date, updated_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            foreach ($matching_ids as $m_id) {
                $hist_stmt->execute([
                    $station_id,
                    $m_id,
                    $history['fuel_type'],
                    $current_price,
                    $rollback_price,
                    "ROLLBACK: " . $reason,
                    date('Y-m-d'),
                    $me['id']
                ]);
            }
            
            // Update fuel price for all matching fuel tank records
            $upd_params = array_merge([$rollback_price, $me['id']], $matching_ids);
            $pdo->prepare("UPDATE fuel_inventory SET price_per_liter = ?, updated_by = ?, last_updated = NOW() WHERE id IN ($in_clause)")
                ->execute($upd_params);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Rollback Fuel Price',
                "Manager rolled back {$history['fuel_type']} price from ₱{$current_price} to ₱{$rollback_price}. Reason: {$reason}");
            
            echo json_encode(['success' => true, 'message' => 'Price rolled back successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ══════════════════════════════════════════════════════════════════════
        // DEACTIVATE FUEL
        case 'deactivate_fuel':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Verify fuel belongs to manager's station
            $stmt = $pdo->prepare("
                SELECT id, fuel_type, ugt_no, status 
                FROM fuel_inventory 
                WHERE id = ? AND station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found']);
                exit;
            }
            
            // Update status to inactive
            $stmt = $pdo->prepare("
                UPDATE fuel_inventory 
                SET status = 'inactive',
                    updated_by = ?,
                    last_updated = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$me['id'], $id, $station_id]);

            // Log in fuel_status_history
            try {
                $user_name = $me['username'] ?? ($me['first_name'] ?? 'Manager');
                $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, old_status, new_status, status, reason, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, 'Active', 'Inactive', 'Deactivated', 'Deactivated by Manager', ?, ?, NOW())")
                    ->execute([$station_id, $id, $fuel['fuel_type'], $me['id'], $user_name]);
            } catch (Exception $e) {}
            
            // Log activity
            log_activity($pdo, $me['id'], 'Deactivate Fuel Product',
                "Manager deactivated fuel product: {$fuel['fuel_type']} ({$fuel['ugt_no']})");
            
            echo json_encode(['success' => true, 'message' => 'Fuel product deactivated successfully']);
            break;

        // ══════════════════════════════════════════════════════════════════════
        // ACTIVATE FUEL
        case 'activate_fuel':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Verify fuel belongs to manager's station
            $stmt = $pdo->prepare("
                SELECT id, fuel_type, ugt_no, status 
                FROM fuel_inventory 
                WHERE id = ? AND station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found']);
                exit;
            }
            
            // Update status to active
            $stmt = $pdo->prepare("
                UPDATE fuel_inventory 
                SET status = 'active',
                    updated_by = ?,
                    last_updated = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$me['id'], $id, $station_id]);

            // Log in fuel_status_history
            try {
                $user_name = $me['username'] ?? ($me['first_name'] ?? 'Manager');
                $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, old_status, new_status, status, reason, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, 'Inactive', 'Active', 'Activated', 'Activated by Manager', ?, ?, NOW())")
                    ->execute([$station_id, $id, $fuel['fuel_type'], $me['id'], $user_name]);
            } catch (Exception $e) {}
            
            // Log activity
            log_activity($pdo, $me['id'], 'Activate Fuel Product',
                "Manager activated fuel product: {$fuel['fuel_type']} ({$fuel['ugt_no']})");
            
            echo json_encode(['success' => true, 'message' => 'Fuel product activated successfully']);
            break;
            
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
                    echo json_encode(['success' => false, 'message' => 'A merchandise product with this name already exists at your station.']);
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
                    echo json_encode(['success' => false, 'message' => 'A merchandise product with this name already exists at your station.']);
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
                                $sku ?: null, $barcode ?: null, $size ?: 'pcs', $reorder_level, $critical_level, $station_id, $expiration_date]);
                $new_id = (int)$pdo->lastInsertId();

                // Auto-generate SKU if user left it blank
                if (empty($sku) && $new_id > 0) {
                    $sku = 'P' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
                    $pdo->prepare("UPDATE inventory_products SET sku = ? WHERE id = ?")->execute([$sku, $new_id]);
                }
            } catch (Exception $legacy_error) {
                // Fallback: insert into products table instead
                $category_id = ensure_product_category_id($pdo, $category);
                $unit_value = $size !== '' ? $size : 'pcs';
                $stmt = $pdo->prepare("
                    INSERT INTO products
                    (sku, name, description, category_id, cost, price, created_at, updated_at, min_stock_level, max_stock_level, station_id, current_stock, unit, capacity, status)
                    VALUES (?, ?, '', ?, ?, ?, NOW(), NOW(), ?, ?, ?, 0, ?, 480, 'active')
                ");
                $stmt->execute([$sku ?: null, $product_name, $category_id ?: null, $unit_cost, $unit_price,
                                $reorder_level, $reorder_level * 20, $station_id, $unit_value]);
                $new_id = (int)$pdo->lastInsertId();
                if (empty($sku) && $new_id > 0) {
                    $sku = 'P' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
                    try { $pdo->prepare("UPDATE products SET sku = ? WHERE id = ?")->execute([$sku, $new_id]); } catch (Exception $e2) {}
                }
            }

            if ($new_id > 0) {
                $unit_value = $size !== '' ? $size : 'pcs';

                // Sync products table so legacy catalog queries find this product
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

                // Initialize station_inventory row so inventory module picks it up
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
                "Manager added new merchandise: {$product_name}");

            echo json_encode(['success' => true, 'message' => 'Product added successfully!']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // EDIT FUEL — FULL (name, price, capacity, critical level, reorder level, status, remarks)
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_fuel_full':
            $id             = (int)($_POST['id'] ?? 0);
            $new_fuel_name  = trim($_POST['fuel_name'] ?? ($_POST['fuel_type'] ?? ''));
            $new_ugt_no     = trim($_POST['ugt_no'] ?? '');
            $new_price      = (float)($_POST['price'] ?? 0);
            $capacity       = (float)($_POST['capacity'] ?? 0);
            $critical_level = (float)($_POST['critical_level'] ?? 0);
            $reorder_level  = (float)($_POST['reorder_level'] ?? 0);
            $new_status     = strtolower(trim($_POST['status'] ?? 'active'));
            $remarks        = trim($_POST['remarks'] ?? '');

            if (!in_array($new_status, ['active', 'inactive'])) $new_status = 'active';

            if ($id <= 0 || $new_price < 0 || $capacity < 0 || $critical_level < 0 || $reorder_level < 0) {
                echo json_encode(['success'=>false,'message'=>'Invalid parameters']);
                exit;
            }

            // Ensure table fuel_config_history exists
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_config_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL,
                  `fuel_inventory_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `field_name` VARCHAR(100) NOT NULL,
                  `old_value` VARCHAR(255) NULL,
                  `new_value` VARCHAR(255) NULL,
                  `updated_by` INT NULL,
                  `updated_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Exception $e) {}

            // Fetch current record
            $stmt = $pdo->prepare("SELECT fuel_type, ugt_no, price_per_liter, capacity, critical_level, reorder_level, status FROM fuel_inventory WHERE id=? AND station_id=? LIMIT 1");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fuel) { echo json_encode(['success'=>false,'message'=>'Fuel product not found']); exit; }

            $old_fuel_name = $fuel['fuel_type'];
            $old_ugt_no    = $fuel['ugt_no'] ?? '';
            $old_price     = (float)$fuel['price_per_liter'];
            $old_cap       = (float)$fuel['capacity'];
            $old_crit      = (float)$fuel['critical_level'];
            $old_reorder   = (float)($fuel['reorder_level'] ?? 0);
            $old_status    = strtolower($fuel['status'] ?? 'active');
            $user_name     = $me['username'] ?? ($me['first_name'] ?? 'Manager');

            $target_fuel_name = !empty($new_fuel_name) ? $new_fuel_name : $old_fuel_name;
            $target_ugt_no    = !empty($new_ugt_no) ? $new_ugt_no : $old_ugt_no;

            // Check if renamed fuel product or UGT conflicts with another tank for this station
            if (!empty($new_fuel_name) || !empty($new_ugt_no)) {
                $chk_dup = $pdo->prepare("SELECT id FROM fuel_inventory WHERE station_id = ? AND id != ? AND (LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) OR LOWER(TRIM(ugt_no)) = LOWER(TRIM(?))) LIMIT 1");
                $chk_dup->execute([$station_id, $id, $target_fuel_name, $target_ugt_no]);
                if ($chk_dup->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'This fuel product already exists for this station.']);
                    exit;
                }
            }

            try {
                // Log configuration history for changed fields
                if (!empty($new_ugt_no) && strcasecmp($old_ugt_no, $new_ugt_no) !== 0) {
                    $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'UGT No', ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $target_fuel_name, $old_ugt_no, $new_ugt_no, $me['id'], $user_name]);
                }
                if (!empty($new_fuel_name) && strcasecmp($old_fuel_name, $new_fuel_name) !== 0) {
                    $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Fuel Name', ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $target_fuel_name, $old_fuel_name, $new_fuel_name, $me['id'], $user_name]);
                }
                if (abs($old_cap - $capacity) > 0.001) {
                    $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Capacity', ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $target_fuel_name, number_format($old_cap, 2) . ' L', number_format($capacity, 2) . ' L', $me['id'], $user_name]);
                }
                if (abs($old_crit - $critical_level) > 0.001) {
                    $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Critical Level', ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $target_fuel_name, number_format($old_crit, 2) . ' L', number_format($critical_level, 2) . ' L', $me['id'], $user_name]);
                }
                if (abs($old_reorder - $reorder_level) > 0.001) {
                    $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Reorder Level', ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $target_fuel_name, number_format($old_reorder, 2) . ' L', number_format($reorder_level, 2) . ' L', $me['id'], $user_name]);
                }
                if ($old_status !== $new_status) {
                    // Log exclusively in fuel_status_history
                    try {
                        $old_st_text = ucfirst($old_status);
                        $new_st_text = ucfirst($new_status);
                        $status_label = ($new_status === 'active') ? 'Activated' : 'Deactivated';
                        $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, old_status, new_status, status, reason, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                            ->execute([$station_id, $id, $target_fuel_name, $old_st_text, $new_st_text, $status_label, $remarks ?: null, $me['id'], $user_name]);
                    } catch (Exception $e) {}
                }

                // Update fuel_inventory
                $stmt = $pdo->prepare("UPDATE fuel_inventory SET fuel_type=?, ugt_no=?, capacity=?, critical_level=?, reorder_level=?, status=?, updated_by=?, last_updated=NOW() WHERE id=? AND station_id=?");
                $stmt->execute([$target_fuel_name, $target_ugt_no, $capacity, $critical_level, $reorder_level, $new_status, $me['id'], $id, $station_id]);

                // Update configured pumps for this fuel product
                if (!empty($_POST['edit_pump_status']) && is_array($_POST['edit_pump_status'])) {
                    foreach ($_POST['edit_pump_status'] as $p_id => $p_st) {
                        $p_id = (int)$p_id;
                        $p_st = in_array(strtolower($p_st), ['active', 'inactive', 'maintenance']) ? ucfirst(strtolower($p_st)) : 'Active';
                        if ($p_id > 0) {
                            try {
                                $pdo->prepare("UPDATE fuel_pumps SET status = ? WHERE id = ? AND station_id = ?")->execute([$p_st, $p_id, $station_id]);
                                $pdo->prepare("UPDATE nozzles SET status = ? WHERE pump_id = ? AND station_id = ?")->execute([$p_st, $p_id, $station_id]);
                            } catch (Exception $e) {}
                        }
                    }
                }

                $reason_text = trim($_POST['reason'] ?? '');
                if (empty($reason_text)) {
                    $reason_text = "Fuel Price Change from ₱" . number_format($old_price, 2) . " to ₱" . number_format($new_price, 2);
                }

                $has_config_changes = (!empty($new_fuel_name) && strcasecmp($old_fuel_name, $new_fuel_name) !== 0)
                    || (abs($old_cap - $capacity) > 0.001)
                    || (abs($old_crit - $critical_level) > 0.001)
                    || (abs($old_reorder - $reorder_level) > 0.001)
                    || ($old_status !== $new_status);

                if (abs($old_price - $new_price) > 0.001) {
                    $matching_ids = get_matching_fuel_ids($pdo, $station_id, $id, $old_fuel_name);
                    $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));

                    // Clear previous pending approval for all matching fuel tank records
                    $del_params = array_merge([$station_id], $matching_ids);
                    $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id IN ($in_clause) AND status='pending'")
                        ->execute($del_params);

                    // Submit pending price approval request for each matching fuel tank record
                    $stmt = $pdo->prepare("
                        INSERT INTO pending_price_approvals
                        (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_cost, new_cost, old_price, new_price, requested_by, manager_id, reason, status, created_at)
                        VALUES (?, 'fuel', ?, ?, 'price', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                    ");
                    foreach ($matching_ids as $m_id) {
                        $stmt->execute([
                            $station_id, $m_id, $target_fuel_name,
                            $old_price, $new_price,
                            $old_price, $new_price,
                            $old_price, $new_price,
                            $me['id'], $me['id'],
                            $reason_text
                        ]);
                    }

                    log_activity($pdo, $me['id'], 'Edit Fuel Product', "Manager requested price change for {$target_fuel_name} (₱{$old_price} -> ₱{$new_price}). Reason: {$reason_text}");
                    
                    $resp_msg = $has_config_changes 
                        ? 'Configuration updated immediately. Price change (₱' . number_format($old_price, 2) . ' → ₱' . number_format($new_price, 2) . ') submitted for Admin approval.'
                        : 'Price change request (₱' . number_format($old_price, 2) . ' → ₱' . number_format($new_price, 2) . ') submitted for Admin approval.';
                    
                    echo json_encode(['success'=>true,'message'=>$resp_msg]);
                } else {
                    log_activity($pdo, $me['id'], 'Edit Fuel Product', "Manager updated {$target_fuel_name}: Capacity={$capacity}L, Critical Level={$critical_level}L, Reorder Level={$reorder_level}L, Status={$new_status}");
                    echo json_encode(['success'=>true,'message'=>'Fuel configuration updated successfully']);
                }
            } catch (PDOException $pdoe) {
                echo json_encode(['success'=>false,'message'=>'Database error: ' . $pdoe->getMessage()]);
            }
            break;

        // ══════════════════════════════════════════════════════════════════════
        // TOGGLE FUEL STATUS (Activate / Deactivate with Confirmation Dialog)
        // ══════════════════════════════════════════════════════════════════════
        case 'toggle_fuel_status':
            $id            = (int)($_POST['id'] ?? 0);
            $target_status = strtolower(trim($_POST['target_status'] ?? ''));

            if ($id <= 0 || !in_array($target_status, ['active', 'inactive'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }

            // Verify fuel belongs to manager's station
            $stmt = $pdo->prepare("SELECT id, fuel_type, ugt_no, status FROM fuel_inventory WHERE id = ? AND station_id = ? LIMIT 1");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found']);
                exit;
            }

            $old_status = strtolower($fuel['status'] ?? 'active');
            if ($old_status === $target_status) {
                echo json_encode(['success' => true, 'message' => "Fuel product is already {$target_status}."]);
                exit;
            }

            // Update status in fuel_inventory
            $stmt = $pdo->prepare("UPDATE fuel_inventory SET status = ?, updated_by = ?, last_updated = NOW() WHERE id = ? AND station_id = ?");
            $stmt->execute([$target_status, $me['id'], $id, $station_id]);

            $user_name = $me['username'] ?? ($me['first_name'] ?? 'Manager');

            // Log in fuel_status_history
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_status_history` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT NOT NULL,
                  `fuel_inventory_id` INT NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `status` VARCHAR(50) NOT NULL,
                  `changed_by` INT NULL,
                  `changed_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $old_st_text = ucfirst($old_status);
                $new_st_text = ucfirst($target_status);
                $status_label = ($target_status === 'active') ? 'Activated' : 'Deactivated';
                $remarks_val  = trim($_POST['remarks'] ?? ($_POST['reason'] ?? ''));
                $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, old_status, new_status, status, reason, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$station_id, $id, $fuel['fuel_type'], $old_st_text, $new_st_text, $status_label, $remarks_val ?: null, $me['id'], $user_name]);
            } catch (Exception $e) {}

            // Log activity
            log_activity($pdo, $me['id'], 'Toggle Fuel Status',
                "Manager changed status of fuel product: {$fuel['fuel_type']} ({$fuel['ugt_no']}) to {$target_status}");

            echo json_encode([
                'success' => true,
                'message' => "Fuel product {$fuel['fuel_type']} has been " . ($target_status === 'active' ? 'activated' : 'deactivated') . " successfully."
            ]);
            break;

        case 'edit_merchandise_full':
            $id             = (int)($_POST['id'] ?? 0);
            $product_name   = trim($_POST['product_name'] ?? '');
            $sku            = sanitize_optional_field($_POST['sku'] ?? '');
            $category       = trim($_POST['category'] ?? '');
            $brand          = sanitize_optional_field($_POST['brand'] ?? '');
            $size           = sanitize_optional_field($_POST['size'] ?? '');
            $barcode        = sanitize_optional_field($_POST['barcode'] ?? '');
            $unit_cost      = (float)($_POST['unit_cost'] ?? 0);
            $unit_price     = (float)($_POST['unit_price'] ?? 0);
            $reorder_level  = (int)($_POST['reorder_level'] ?? 24);
            $critical_level = (int)($_POST['critical_level'] ?? 10);
            $prod_status    = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
            $expiration_date = !empty($_POST['expiration_date']) ? trim($_POST['expiration_date']) : null;

            $placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];
            if ($id <= 0 || empty($product_name) || in_array(strtolower($product_name), $placeholders, true)) {
                echo json_encode(['success' => false, 'message' => 'Product Name is required and cannot be N/A or a placeholder.']);
                exit;
            }
            if (empty($category) || in_array(strtolower($category), $placeholders, true)) {
                echo json_encode(['success' => false, 'message' => 'Category is required and cannot be N/A or a placeholder.']);
                exit;
            }

            $old = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            if (!$old) { echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

            $old_price   = (float)$old['unit_price'];
            $old_cost    = (float)($old['unit_cost'] ?? 0);
            $category_id = ensure_product_category_id($pdo, $category);
            $unit_value  = $size !== '' ? $size : ($old['unit'] ?? 'pcs');

            // Price change requires Admin approval — keep old_price in live tables if price changed!
            $live_price = ($old_price > 0 && $old_price != $unit_price) ? $old_price : $unit_price;

            // Log configuration history for changed fields
            $user_name_edit = $me['username'] ?? ($me['first_name'] ?? 'Manager');
            $ch_fields = [
                'Product Name' => [$old['product_name'] ?? '', $product_name],
                'Category'     => [$old['category'] ?? '', $category],
                'Brand'        => [$old['brand'] ?? '', $brand],
                'Unit'         => [$old['unit'] ?? '', $unit_value ?? $size],
                'Barcode'      => [$old['barcode'] ?? '', $barcode],
                'Reorder Level'=> [(string)($old['reorder_level'] ?? ''), (string)$reorder_level],
                'Expiration Date'=> [(string)($old['expiration_date'] ?? ''), (string)($expiration_date ?? '')],
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
                foreach ($ch_fields as $field_label => [$old_val, $new_val]) {
                    if ((string)$old_val !== (string)$new_val && $new_val !== '') {
                        $pdo->prepare("INSERT INTO product_config_history (station_id, product_id, field_name, old_value, new_value, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
                            ->execute([$station_id, $id, $field_label, $old_val, $new_val, $me['id'], $user_name_edit]);
                    }
                }
            } catch (Exception $e) {}


            // 1. Update inventory_products (primary source)
            try {
                $stmt = $pdo->prepare("
                    UPDATE inventory_products
                    SET product_name=?, sku=?, barcode=?, category=?, brand=?, size=?,
                        unit_cost=?, unit_price=?, reorder_level=?, critical_level=?, status=?, expiration_date=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([$product_name, $sku, $barcode ?: null, $category, $brand,
                                $size, $unit_cost, $live_price, $reorder_level, $critical_level, $prod_status, $expiration_date, $id]);
            } catch (Exception $e) {}

            // 2. Update products table (legacy source)
            try {
                if ($category_id > 0) {
                    $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, category_id=?, unit=?, cost=?, price=?, updated_at=NOW() WHERE id=?");
                    $stmt->execute([$product_name, $sku, $category_id, $unit_value, $unit_cost, $live_price, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE products SET name=?, sku=?, unit=?, cost=?, price=?, updated_at=NOW() WHERE id=?");
                    $stmt->execute([$product_name, $sku, $unit_value, $unit_cost, $live_price, $id]);
                }
            } catch (Exception $e) {}

            // 3. Upsert station_inventory
            try {
                $stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                $stmt->execute([$station_id, $id]);
                $si_id = (int)($stmt->fetchColumn() ?: 0);
                if ($si_id > 0) {
                    $pdo->prepare("UPDATE station_inventory SET unit=?, cost=?, price=?, reorder_level=?, critical_level=?, status=?, expiration_date=?, last_updated=NOW() WHERE id=?")
                        ->execute([$unit_value, $unit_cost, $live_price, $reorder_level, $critical_level, $prod_status, $expiration_date, $si_id]);
                } else {
                    $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, unit, cost, price, reorder_level, critical_level, status, expiration_date, last_updated) VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, NOW())")
                        ->execute([$station_id, $id, $unit_value, $unit_cost, $live_price, $reorder_level, $critical_level, $prod_status, $expiration_date]);
                }
            } catch (Exception $e) {}

            if ($old_price != $unit_price && $old_price > 0) {
                // Clear existing pending request for this product
                $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type IN ('merchandise','product','inventory_product') AND product_id=? AND status='pending'")
                    ->execute([$station_id, $id]);

                // Create pending price request for Admin Approval
                $stmt = $pdo->prepare("
                    INSERT INTO pending_price_approvals
                    (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_cost, new_cost, old_price, new_price, requested_by, manager_id, status, created_at)
                    VALUES (?, 'merchandise', ?, ?, 'price', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $station_id, $id, $product_name,
                    $old_price, $unit_price,
                    $old_cost, $unit_cost,
                    $old_price, $unit_price,
                    $me['id'], $me['id']
                ]);

                log_activity($pdo, $me['id'], 'Edit Merchandise Price Request', "Manager requested price change for {$product_name} (₱{$old_price} -> ₱{$unit_price})");
                echo json_encode(['success'=>true, 'message'=>'Product details updated. Price change submitted for Admin approval.']);
            } else {
                log_activity($pdo, $me['id'], 'Edit Merchandise', "Manager updated product: {$product_name}");
                echo json_encode(['success'=>true, 'message'=>'Product updated successfully']);
            }
            break;

        case 'edit_merchandise_price':
            $id        = (int)($_POST['id'] ?? 0);
            $new_price = (float)($_POST['price'] ?? 0);
            if ($id <= 0 || $new_price < 0) { echo json_encode(['success'=>false,'message'=>'Invalid parameters']); exit; }
            $merch = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            if (!$merch) { echo json_encode(['success'=>false,'message'=>'Merchandise not found']); exit; }
            $old_price = (float)$merch['unit_price'];

            if ($old_price == $new_price) {
                echo json_encode(['success'=>true, 'message'=>'Price is unchanged.']);
                exit;
            }

            // Clear existing pending request
            $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type IN ('merchandise','product','inventory_product') AND product_id=? AND status='pending'")
                ->execute([$station_id, $id]);

            // Submit price change request for Admin approval
            $stmt = $pdo->prepare("
                INSERT INTO pending_price_approvals
                (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_cost, new_cost, old_price, new_price, requested_by, manager_id, status, created_at)
                VALUES (?, 'merchandise', ?, ?, 'price', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $station_id, $id, $merch['product_name'],
                $old_price, $new_price,
                (float)($merch['unit_cost']??0), (float)($merch['unit_cost']??0),
                $old_price, $new_price,
                $me['id'], $me['id']
            ]);

            log_activity($pdo, $me['id'], 'Edit Merchandise Price Request', "Manager requested price change for {$merch['product_name']} (₱{$old_price} -> ₱{$new_price})");
            echo json_encode(['success'=>true, 'message'=>'Price change submitted for Admin approval.']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // DEACTIVATE MERCHANDISE
        // ══════════════════════════════════════════════════════════════════════
        case 'deactivate_merchandise':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Direct DB lookup — works regardless of current active/inactive status
            $merch_row = null;
            $chk = $pdo->prepare("SELECT id, product_name FROM inventory_products WHERE id = ? LIMIT 1");
            $chk->execute([$id]);
            $merch_row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$merch_row) {
                $chk2 = $pdo->prepare("SELECT id, name AS product_name FROM products WHERE id = ? LIMIT 1");
                $chk2->execute([$id]);
                $merch_row = $chk2->fetch(PDO::FETCH_ASSOC);
            }
            if (!$merch_row) {
                echo json_encode(['success' => false, 'message' => 'Merchandise not found']);
                exit;
            }
            
            // Update both tables
            try { $pdo->prepare("UPDATE inventory_products SET status = 'inactive', updated_at = NOW() WHERE id = ?")->execute([$id]); } catch (Exception $e) {}
            try { $pdo->prepare("UPDATE products SET status = 'inactive', updated_at = NOW() WHERE id = ?")->execute([$id]); } catch (Exception $e) {}
            try { $pdo->prepare("UPDATE station_inventory SET status = 'inactive', last_updated = NOW() WHERE station_id = ? AND product_id = ?")->execute([$station_id, $id]); } catch (Exception $e) {}
            
            $user_name = $me['username'] ?? ($me['first_name'] ?? 'Manager');
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
                $pdo->prepare("INSERT INTO product_status_history (station_id, product_id, old_status, new_status, changed_by, changed_by_name, reason, created_at) VALUES (?, ?, 'active', 'inactive', ?, ?, 'Manager Deactivation', NOW())")
                    ->execute([$station_id, $id, $me['id'], $user_name]);
            } catch (Exception $e) {}

            log_activity($pdo, $me['id'], 'Deactivate Merchandise',
                "Manager deactivated merchandise: {$merch_row['product_name']}");
            
            echo json_encode(['success' => true, 'message' => 'Merchandise deactivated successfully']);
            break;

        // ══════════════════════════════════════════════════════════════════════
        // ACTIVATE MERCHANDISE
        // ══════════════════════════════════════════════════════════════════════
        case 'activate_merchandise':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            $merch_row = null;
            $chk = $pdo->prepare("SELECT id, product_name FROM inventory_products WHERE id = ? LIMIT 1");
            $chk->execute([$id]);
            $merch_row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$merch_row) {
                $chk2 = $pdo->prepare("SELECT id, name AS product_name FROM products WHERE id = ? LIMIT 1");
                $chk2->execute([$id]);
                $merch_row = $chk2->fetch(PDO::FETCH_ASSOC);
            }
            if (!$merch_row) {
                echo json_encode(['success' => false, 'message' => 'Merchandise not found']);
                exit;
            }
            
            try { $pdo->prepare("UPDATE inventory_products SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$id]); } catch (Exception $e) {}
            try { $pdo->prepare("UPDATE products SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$id]); } catch (Exception $e) {}
            try { $pdo->prepare("UPDATE station_inventory SET status = 'active', last_updated = NOW() WHERE station_id = ? AND product_id = ?")->execute([$station_id, $id]); } catch (Exception $e) {}
            
            $user_name = $me['username'] ?? ($me['first_name'] ?? 'Manager');
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
                $pdo->prepare("INSERT INTO product_status_history (station_id, product_id, old_status, new_status, changed_by, changed_by_name, reason, created_at) VALUES (?, ?, 'inactive', 'active', ?, ?, 'Manager Activation', NOW())")
                    ->execute([$station_id, $id, $me['id'], $user_name]);
            } catch (Exception $e) {}

            log_activity($pdo, $me['id'], 'Activate Merchandise',
                "Manager activated merchandise: {$merch_row['product_name']}");
            
            echo json_encode(['success' => true, 'message' => 'Merchandise activated successfully']);
            break;

        case 'restore_merchandise_price':
            $id = (int)($_POST['id'] ?? 0);
            $target_price = (float)($_POST['target_price'] ?? 0);
            $reason = trim($_POST['reason'] ?? 'Price Restoration Request');

            if ($id <= 0 || $target_price <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }

            $merch = find_merchandise_pricing_item($pdo, (int)$station_id, $id);
            if (!$merch) {
                echo json_encode(['success' => false, 'message' => 'Product not found']);
                exit;
            }

            $current_price = (float)($merch['unit_price'] ?? $merch['price'] ?? 0);

            // Clear existing pending request
            $pdo->prepare("DELETE FROM pending_price_approvals WHERE station_id=? AND product_type IN ('merchandise','product') AND product_id=? AND status='pending'")
                ->execute([$station_id, $id]);

            // Create pending price restoration approval request
            $stmt = $pdo->prepare("
                INSERT INTO pending_price_approvals
                (station_id, product_type, product_id, product_name, field_name, old_value, new_value, old_price, new_price, requested_by, manager_id, status, reason, created_at)
                VALUES (?, 'merchandise', ?, ?, 'price_restoration', ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
            ");
            $stmt->execute([
                $station_id, $id, $merch['product_name'],
                $current_price, $target_price,
                $current_price, $target_price,
                $me['id'], $me['id'],
                $reason
            ]);

            log_activity($pdo, $me['id'], 'Restore Merchandise Price Request', "Manager requested price restoration for {$merch['product_name']} (₱{$current_price} -> ₱{$target_price})");
            echo json_encode(['success' => true, 'message' => 'Price restoration request submitted for Admin approval.']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ADD SERVICE
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
            $required_mechanics = ($mech_raw !== '' && is_numeric($mech_raw) && (int)$mech_raw > 0) ? (int)$mech_raw : null;
            $description        = sanitize_optional_field($_POST['description'] ?? '');

            if (empty($service_name) || empty($category)) {
                echo json_encode(['success' => false, 'message' => 'Service name and category are required']);
                exit;
            }
            if ($service_price <= 0 || $labor_fee <= 0) {
                echo json_encode(['success' => false, 'message' => 'Service fee and labor fee must be greater than ₱0.00']);
                exit;
            }

            // Auto-generate service_key if empty
            if (empty($service_key)) {
                $service_key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $service_name));
                $service_key = trim($service_key, '_');
            }

            // Station-scoped service name duplicate check
            $svc_dup = $pdo->prepare("
                SELECT id FROM job_order_service_types
                WHERE station_id = ? AND LOWER(service_name) = LOWER(?)
                LIMIT 1
            ");
            $svc_dup->execute([$station_id, $service_name]);
            if ($svc_dup->fetch()) {
                echo json_encode(['success' => false, 'message' => 'A service with this name already exists at your station.']);
                exit;
            }

            // Ensure service_key uniqueness — scoped to THIS station only
            // Different stations are allowed to use the same service key name
            $base_key = $service_key;
            $suffix = 1;
            while (true) {
                $chk = $pdo->prepare("SELECT id FROM job_order_service_types WHERE service_key = ? AND station_id = ? LIMIT 1");
                $chk->execute([$service_key, $station_id]);
                if (!$chk->fetch()) break;
                $service_key = $base_key . '_' . $suffix++;
            }

            // Auto-generate service_code: SVC-XXXX
            $max_stmt = $pdo->query("SELECT MAX(id) FROM job_order_service_types");
            $next_id = (int)$max_stmt->fetchColumn() + 1;
            $service_code = 'SVC-' . str_pad($next_id, 4, '0', STR_PAD_LEFT);

            // Insert new service
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

            // If code was a placeholder due to race condition, fix it
            $pdo->prepare("UPDATE job_order_service_types SET service_code = ? WHERE id = ? AND (service_code IS NULL OR service_code != ?)")
                ->execute(['SVC-' . str_pad($new_id, 4, '0', STR_PAD_LEFT), $new_id, 'SVC-' . str_pad($new_id, 4, '0', STR_PAD_LEFT)]);

            // Log to service_fee_history
            try {
                $pdo->prepare("
                    INSERT INTO service_fee_history
                    (service_id, change_type, new_service_fee, new_labor_fee, notes, changed_by, changed_by_name, changed_by_role, approval_status)
                    VALUES (?, 'created', ?, ?, ?, ?, ?, 'manager', 'direct')
                ")->execute([
                    $new_id, $service_price, $labor_fee,
                    "Service created: {$service_name}",
                    $me['id'], ($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')
                ]);
            } catch (Exception $e) { /* ignore */ }

            log_activity($pdo, $me['id'], 'Add Service Type',
                "Manager added service: {$service_name} ({$category}) - Fee: ₱{$service_price}, Labor: ₱{$labor_fee}");

            echo json_encode(['success' => true, 'message' => 'Service added successfully', 'service_code' => $service_code]);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // EDIT SERVICE — FULL (all fields, fee approval flow)
        // ══════════════════════════════════════════════════════════════════════
        case 'edit_service_full':
            $id                 = (int)($_POST['id'] ?? 0);
            $service_name       = trim($_POST['service_name'] ?? '');
            $category           = trim($_POST['category'] ?? '');
            $service_key        = trim($_POST['service_key'] ?? '');
            $service_price      = (float)($_POST['service_price'] ?? 0);
            $labor_fee          = (float)($_POST['labor_fee'] ?? 0);
            $dur_raw            = trim((string)($_POST['estimated_duration'] ?? ''));
            $estimated_duration = ($dur_raw !== '' && is_numeric($dur_raw) && (int)$dur_raw > 0) ? (int)$dur_raw : null;
            $mech_raw           = trim((string)($_POST['required_mechanics'] ?? ''));
            $required_mechanics = ($mech_raw !== '' && is_numeric($mech_raw) && (int)$mech_raw > 0) ? (int)$mech_raw : null;
            $description        = sanitize_optional_field($_POST['description'] ?? '');
            $active             = (int)($_POST['active'] ?? 1);

            if ($id <= 0 || empty($service_name) || empty($category) || $service_price <= 0 || $labor_fee <= 0) {
                echo json_encode(['success'=>false,'message'=>'Service name, category, and non-zero fees (greater than ₱0.00) are required']);
                exit;
            }

            // Auto-generate service_key if empty
            if (empty($service_key)) {
                $service_key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $service_name));
                $service_key = trim($service_key, '_');
            }

            // Get current service — verify it belongs to THIS station
            $stmt = $pdo->prepare("SELECT * FROM job_order_service_types WHERE id=? AND station_id=? LIMIT 1");
            $stmt->execute([$id, $station_id]);
            $svc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$svc) { echo json_encode(['success'=>false,'message'=>'Service not found or does not belong to your station']); exit; }

            // Check key uniqueness (excluding self) — scoped to THIS station only
            if (!empty($service_key)) {
                $chk = $pdo->prepare("SELECT id FROM job_order_service_types WHERE service_key=? AND station_id=? AND id!=? LIMIT 1");
                $chk->execute([$service_key, $station_id, $id]);
                if ($chk->fetch()) {
                    // Auto-deduplicate key
                    $service_key = $service_key . '_' . $id;
                }
            }

            $old_service_fee = (float)$svc['service_price'];
            $old_labor_fee   = (float)($svc['labor_fee'] ?? 0);
            $fee_changed     = ($old_service_fee != $service_price) || ($old_labor_fee != $labor_fee);

            if ($fee_changed) {
                // Update non-pricing fields immediately
                $stmt = $pdo->prepare("
                    UPDATE job_order_service_types
                    SET service_name=?, category=?, service_key=?,
                        estimated_duration=?, required_mechanics=?,
                        description=?, active=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([$service_name, $category, $service_key,
                    $estimated_duration, $required_mechanics,
                    $description ?: null, $active, $id]);

                // Remove old pending approvals for this service
                $pdo->prepare("DELETE FROM pending_price_approvals WHERE product_type IN ('service','service_type') AND product_id=? AND status='pending'")
                    ->execute([$id]);

                // Create pending price approval
                $stmt = $pdo->prepare("
                    INSERT INTO pending_price_approvals
                    (station_id, product_type, product_id, product_name, field_name,
                     old_value, new_value, old_cost, new_cost, old_price, new_price,
                     requested_by, manager_id, status, created_at)
                    VALUES (?, 'service', ?, ?, 'price', ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([
                    $station_id, $id, $service_name,
                    $old_service_fee, $service_price,
                    $old_labor_fee, $labor_fee,
                    $old_service_fee, $service_price,
                    $me['id'], $me['id']
                ]);
                $approval_id = (int)$pdo->lastInsertId();

                // Log to service_fee_history
                try {
                    $pdo->prepare("
                        INSERT INTO service_fee_history
                        (service_id, change_type, old_service_fee, new_service_fee,
                         old_labor_fee, new_labor_fee, notes,
                         changed_by, changed_by_name, changed_by_role,
                         approval_status, approval_id)
                        VALUES (?, 'service_fee', ?, ?, ?, ?, ?, ?, ?, 'manager', 'pending', ?)
                    ")->execute([
                        $id, $old_service_fee, $service_price,
                        $old_labor_fee, $labor_fee,
                        'Fee change pending admin approval',
                        $me['id'],
                        ($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''),
                        $approval_id
                    ]);
                } catch (Exception $e) { /* ignore */ }

                log_activity($pdo, $me['id'], 'Edit Service Fee',
                    "Manager requested fee change for: {$service_name} | Service: ₱{$old_service_fee}→₱{$service_price}, Labor: ₱{$old_labor_fee}→₱{$labor_fee}");
                echo json_encode(['success'=>true,'message'=>'Service updated. Fee changes submitted for Admin approval.','requires_approval'=>true]);
            } else {
                // No fee change — update all fields directly
                $stmt = $pdo->prepare("
                    UPDATE job_order_service_types
                    SET service_name=?, category=?, service_key=?,
                        service_price=?, labor_fee=?,
                        estimated_duration=?, required_mechanics=?,
                        description=?, active=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([
                    $service_name, $category, $service_key,
                    $service_price, $labor_fee,
                    $estimated_duration, $required_mechanics,
                    $description ?: null, $active, $id
                ]);

                // Log to service_fee_history
                try {
                    $pdo->prepare("
                        INSERT INTO service_fee_history
                        (service_id, change_type, old_service_fee, new_service_fee,
                         old_labor_fee, new_labor_fee, notes,
                         changed_by, changed_by_name, changed_by_role, approval_status)
                        VALUES (?, 'updated', ?, ?, ?, ?, ?, ?, ?, 'manager', 'direct')
                    ")->execute([
                        $id, $old_service_fee, $service_price,
                        $old_labor_fee, $labor_fee,
                        'Service details updated (no fee change)',
                        $me['id'],
                        ($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')
                    ]);
                } catch (Exception $e) { /* ignore */ }

                log_activity($pdo, $me['id'], 'Edit Service Type',
                    "Manager updated service: {$service_name} ({$category})");
                echo json_encode(['success'=>true,'message'=>'Service updated successfully']);
            }
            break;

        // (Legacy - price only, kept for backward compat)
        case 'edit_service_price':
            $id        = (int)($_POST['id'] ?? 0);
            $new_price = (float)($_POST['price'] ?? 0);
            
            if ($id <= 0 || $new_price < 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit;
            }
            
            // Get current service info
            $stmt = $pdo->prepare("
                SELECT service_name, service_price 
                FROM job_order_service_types 
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$service) {
                echo json_encode(['success' => false, 'message' => 'Service not found']);
                exit;
            }
            
            $old_price = (float)$service['service_price'];
            
            // Update price
            $stmt = $pdo->prepare("
                UPDATE job_order_service_types 
                SET service_price = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_price, $id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Edit Service Price',
                "Manager updated {$service['service_name']} price from ₱{$old_price} to ₱{$new_price}");
            
            echo json_encode(['success' => true, 'message' => 'Service price updated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // DEACTIVATE SERVICE
        // ══════════════════════════════════════════════════════════════════════
        case 'deactivate_service':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Get service — verify it belongs to THIS station
            $stmt = $pdo->prepare("
                SELECT service_name 
                FROM job_order_service_types 
                WHERE id = ? AND station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $station_id]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$service) {
                echo json_encode(['success' => false, 'message' => 'Service not found or does not belong to your station']);
                exit;
            }
            
            // Update status to inactive — also scoped to station
            $stmt = $pdo->prepare("
                UPDATE job_order_service_types 
                SET active = 0
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$id, $station_id]);

            // Log to history
            try {
                $pdo->prepare("
                    INSERT INTO service_fee_history
                    (service_id, change_type, notes, changed_by, changed_by_name, changed_by_role, approval_status)
                    VALUES (?, 'deactivated', ?, ?, ?, 'manager', 'direct')
                ")->execute([$id, 'Service deactivated', $me['id'], ($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')]);
            } catch (Exception $e) { /* ignore */ }

            // Log activity
            log_activity($pdo, $me['id'], 'Deactivate Service',
                "Manager deactivated service type: {$service['service_name']}");
            
            echo json_encode(['success' => true, 'message' => 'Service deactivated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ACTIVATE FUEL
        // ══════════════════════════════════════════════════════════════════════
        case 'activate_fuel':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Verify fuel belongs to manager's station
            $stmt = $pdo->prepare("
                SELECT fuel_type 
                FROM fuel_inventory 
                WHERE id = ? AND station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $station_id]);
            $fuel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fuel) {
                echo json_encode(['success' => false, 'message' => 'Fuel product not found']);
                exit;
            }
            
            // Update status to active
            $stmt = $pdo->prepare("
                UPDATE fuel_inventory 
                SET status = 'active',
                    updated_by = ?,
                    last_updated = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$me['id'], $id, $station_id]);
            
            // Log activity
            log_activity($pdo, $me['id'], 'Activate Fuel Product',
                "Manager activated fuel product: {$fuel['fuel_type']}");
            
            echo json_encode(['success' => true, 'message' => 'Fuel product activated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ACTIVATE MERCHANDISE
        // ══════════════════════════════════════════════════════════════════════
        case 'activate_merchandise':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Direct DB lookup — works for inactive products too
            $merch_row = null;
            $chk = $pdo->prepare("SELECT id, product_name FROM inventory_products WHERE id = ? LIMIT 1");
            $chk->execute([$id]);
            $merch_row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$merch_row) {
                $chk2 = $pdo->prepare("SELECT id, name AS product_name FROM products WHERE id = ? LIMIT 1");
                $chk2->execute([$id]);
                $merch_row = $chk2->fetch(PDO::FETCH_ASSOC);
            }
            if (!$merch_row) {
                echo json_encode(['success' => false, 'message' => 'Merchandise not found']);
                exit;
            }
            
            try { $pdo->prepare("UPDATE inventory_products SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$id]); } catch (Exception $e) {}
            try { $pdo->prepare("UPDATE products SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$id]); } catch (Exception $e) {}
            try { $pdo->prepare("UPDATE station_inventory SET status = 'active', last_updated = NOW() WHERE station_id = ? AND product_id = ?")->execute([$station_id, $id]); } catch (Exception $e) {}
            
            log_activity($pdo, $me['id'], 'Activate Merchandise',
                "Manager activated merchandise: {$merch_row['product_name']}");
            
            echo json_encode(['success' => true, 'message' => 'Merchandise activated successfully']);
            break;
            
        // ══════════════════════════════════════════════════════════════════════
        // ACTIVATE SERVICE
        // ══════════════════════════════════════════════════════════════════════
        case 'activate_service':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                exit;
            }
            
            // Get service — verify it belongs to THIS station
            $stmt = $pdo->prepare("
                SELECT service_name 
                FROM job_order_service_types 
                WHERE id = ? AND station_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id, $station_id]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$service) {
                echo json_encode(['success' => false, 'message' => 'Service not found or does not belong to your station']);
                exit;
            }
            
            // Update status to active — scoped to station
            $stmt = $pdo->prepare("
                UPDATE job_order_service_types 
                SET active = 1
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$id, $station_id]);

            // Log to history
            try {
                $pdo->prepare("
                    INSERT INTO service_fee_history
                    (service_id, change_type, notes, changed_by, changed_by_name, changed_by_role, approval_status)
                    VALUES (?, 'activated', ?, ?, ?, 'manager', 'direct')
                ")->execute([$id, 'Service activated', $me['id'], ($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')]);
            } catch (Exception $e) { /* ignore */ }

            // Log activity
            log_activity($pdo, $me['id'], 'Activate Service',
                "Manager activated service type: {$service['service_name']}");
            
            echo json_encode(['success' => true, 'message' => 'Service activated successfully']);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Manager Set Prices Handler Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
