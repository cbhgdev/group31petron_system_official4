<?php
// Force browser to always load fresh — prevents stale CSS/JS cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$page_id = 'admin_set_prices';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

// ── Access control ──────────────────────────────────────────────────────────
if (!in_array($role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}
if ((int)$station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

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
        return trim($name);
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

// ── Superadmin: station selection (supports URL station_id override or defaults to first station) ─
if ($role === 'superadmin') {
    $selected_sid = (int)($_GET['station_id'] ?? 0);
    if ($selected_sid > 0) {
        $station_id = $selected_sid;
    } elseif ((int)$station_id <= 0) {
        // Default to first available station
        try {
            $first_s = $pdo->query("SELECT id FROM stations ORDER BY id LIMIT 1")->fetchColumn();
            $station_id = $first_s ?: 0;
        } catch (Exception $e) { $station_id = 0; }
    }
}

// ── Ensure job_order_service_types table exists ────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_order_service_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL DEFAULT 1,
        service_key VARCHAR(100) NOT NULL,
        service_name VARCHAR(200) NOT NULL,
        service_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        min_price DECIMAL(12,2) DEFAULT 0,
        max_price DECIMAL(12,2) DEFAULT 0,
        price_description TEXT DEFAULT NULL,
        pricing_notes TEXT DEFAULT NULL,
        icon_class VARCHAR(100) DEFAULT 'fa-wrench',
        color_class VARCHAR(100) DEFAULT 'text-primary',
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_station (station_id),
        INDEX idx_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* table already exists */ }

// ── Handle Approvals / Rejections ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect_tab = trim($_POST['active_tab'] ?? 'fuel');
    if (!in_array($redirect_tab, ['fuel', 'merch', 'services'])) $redirect_tab = 'fuel';

    try {
        $action = $_POST['action'] ?? '';

    if ($action === 'approve_price') {
        $approval_id = (int)$_POST['approval_id'];
        $stmt = $pdo->prepare("SELECT * FROM pending_price_approvals WHERE id = ? AND status = 'pending'");
        $stmt->execute([$approval_id]);
        $pending = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pending) {
            if ($role !== 'superadmin' && (int)($pending['station_id'] ?? 0) !== (int)$station_id) {
                $_SESSION['error'] = "Unauthorized: You cannot approve price requests for another station.";
                header("Location: admin_set_prices.php?tab={$redirect_tab}");
                exit;
            }
            $ptype = $pending['product_type'] ?? '';
            // Support both new_price (new schema) and new_value (legacy schema)
            $new_price_val = $pending['new_price'] ?? $pending['new_value'] ?? 0;
            $old_price_val = $pending['old_price'] ?? $pending['old_value'] ?? 0;
            $new_cost_val  = $pending['new_cost']  ?? $pending['new_value'] ?? 0;
            $pid           = (int)($pending['product_id'] ?? 0);

            if ($ptype === 'merchandise') {
                $target_station_id = (int)($pending['station_id'] ?? $station_id);
                if ($target_station_id <= 0) $target_station_id = (int)$station_id;

                try {
                    $pdo->prepare("UPDATE inventory_products SET unit_cost=?, unit_price=?, updated_at=NOW() WHERE id=?")
                        ->execute([$new_cost_val, $new_price_val, $pid]);
                } catch (Exception $legacy_error) {
                    // Current inventory source uses products + station_inventory.
                }

                $pdo->prepare("UPDATE products SET cost=?, price=?, updated_at=NOW() WHERE id=?")
                    ->execute([$new_cost_val, $new_price_val, $pid]);

                $si_stmt = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id=? AND product_id=? LIMIT 1");
                $si_stmt->execute([$target_station_id, $pid]);
                $si_id = (int)($si_stmt->fetchColumn() ?: 0);
                if ($si_id > 0) {
                    $pdo->prepare("UPDATE station_inventory SET cost=?, price=?, last_updated=NOW() WHERE id=?")
                        ->execute([$new_cost_val, $new_price_val, $si_id]);
                } else {
                    $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, cost, price, status, last_updated) VALUES (?, ?, 0, ?, ?, 'active', NOW())")
                        ->execute([$target_station_id, $pid, $new_cost_val, $new_price_val]);
                }
            } elseif ($ptype === 'service_type' || $ptype === 'service') {
                $svc_id      = (int)($pending['service_type_id'] ?? $pid);
                $new_labor   = (float)($pending['new_cost'] ?? 0);
                $pdo->prepare("UPDATE job_order_service_types SET service_price=?, labor_fee=?, updated_at=NOW() WHERE id=?")
                    ->execute([$new_price_val, $new_labor, $svc_id]);
            } else {
                // covers 'fuel' and 'fuel_inventory'
                $fuel_id = (int)($pending['fuel_type_id'] ?? $pid);
                $matching_ids = get_matching_fuel_ids($pdo, $target_station_id, $fuel_id, $pending['product_name'] ?? '');

                $in_clause = implode(',', array_fill(0, count($matching_ids), '?'));
                $upd_params = array_merge([$new_price_val], $matching_ids);
                $pdo->prepare("UPDATE fuel_inventory SET price_per_liter=?, last_updated=NOW() WHERE id IN ($in_clause)")
                    ->execute($upd_params);

                // Insert new history record for all matching tanks (Preserves complete linear audit trail!)
                try {
                    $diff = (float)$new_price_val - (float)$old_price_val;
                    $is_restoration = stripos($pending['reason'] ?? '', 'restor') !== false;
                    $action_reason = $is_restoration ? 'Price Restored' : 'Price Updated';
                    
                    $hist_stmt = $pdo->prepare("INSERT INTO fuel_price_history 
                        (station_id, fuel_id, fuel_type, old_price, new_price, difference, reason, requested_by, approved_by, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', NOW())");
                    foreach ($matching_ids as $m_id) {
                        $hist_stmt->execute([
                            $target_station_id,
                            $m_id,
                            $pending['product_name'] ?? 'Fuel',
                            $old_price_val,
                            $new_price_val,
                            $diff,
                            $action_reason,
                            $pending['requested_by'] ?? $pending['manager_id'],
                            $me['id']
                        ]);
                    }
                } catch (Exception $e) {}

                // Mark any other pending approval records for these matching tanks as approved
                try {
                    $del_params = array_merge([$me['id'], $me['id']], [$target_station_id], $matching_ids);
                    $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id IN ($in_clause) AND status='pending'")
                        ->execute($del_params);
                } catch (Exception $e) {}
            }
            // Update status — write to both admin_id and reviewed_by for compatibility
            $pdo->prepare("UPDATE pending_price_approvals SET status='approved', admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$me['id'], $me['id'], $approval_id]);
            log_activity($pdo, $me['id'], 'Approve Price',
                "Admin approved price change for {$ptype} ID {$pid}. New value: {$new_price_val}");
            $_SESSION['success'] = "Price change approved successfully!";
        }
    } elseif ($action === 'reject_price') {
        $approval_id = (int)$_POST['approval_id'];
        $remarks = trim($_POST['remarks'] ?? '');

        // Fetch pending approval details first
        $stmt_p = $pdo->prepare("SELECT * FROM pending_price_approvals WHERE id=? LIMIT 1");
        $stmt_p->execute([$approval_id]);
        $pending = $stmt_p->fetch(PDO::FETCH_ASSOC);

        if ($pending && $role !== 'superadmin' && (int)($pending['station_id'] ?? 0) !== (int)$station_id) {
            $_SESSION['error'] = "Unauthorized: You cannot reject price requests for another station.";
            header("Location: admin_set_prices.php?tab={$redirect_tab}");
            exit;
        }

        $stmt = $pdo->prepare("UPDATE pending_price_approvals SET status='rejected', rejection_reason=?, reviewer_notes=?, admin_id=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE id=? AND status='pending'");
        $stmt->execute([$remarks, $remarks, $me['id'], $me['id'], $approval_id]);
        if ($stmt->rowCount() > 0 && $pending) {
            $ptype = $pending['product_type'] ?? 'fuel';
            $pid   = (int)($pending['product_id'] ?? 0);
            $target_station_id = (int)($pending['station_id'] ?? $station_id);

            if (in_array($ptype, ['fuel', 'fuel_inventory'], true)) {
                $fuel_id = (int)($pending['fuel_type_id'] ?? $pid);
                $matching_ids = get_matching_fuel_ids($pdo, $target_station_id, $fuel_id, $pending['product_name'] ?? '');

                try {
                    $old_price_val = (float)($pending['old_price'] ?? $pending['old_value'] ?? 0);
                    $new_price_val = (float)($pending['new_price'] ?? $pending['new_value'] ?? 0);
                    $diff = $new_price_val - $old_price_val;

                    $hist_stmt = $pdo->prepare("INSERT INTO fuel_price_history 
                        (station_id, fuel_id, fuel_type, old_price, new_price, difference, reason, requested_by, approved_by, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Rejected', NOW())");
                    foreach ($matching_ids as $m_id) {
                        $hist_stmt->execute([
                            $target_station_id,
                            $m_id,
                            $pending['product_name'] ?? 'Fuel',
                            $old_price_val,
                            $new_price_val,
                            $diff,
                            !empty($remarks) ? "Rejected: " . $remarks : "Price Change Request Rejected by Admin",
                            $pending['requested_by'] ?? $pending['manager_id'],
                            $me['id']
                        ]);
                    }
                } catch (Exception $e) {}
            }

            log_activity($pdo, $me['id'], 'Reject Price',
                "Admin rejected price change for {$pending['product_name']} (Approval ID $approval_id). Remarks: $remarks");
            $_SESSION['success'] = "Price change request has been rejected.";
        }
    } elseif ($action === 'admin_edit_fuel_direct') {
        $id             = (int)($_POST['id'] ?? 0);
        $ugt_no         = trim($_POST['ugt_no'] ?? '');
        $fuel_name      = trim($_POST['fuel_name'] ?? '');
        $price          = (float)($_POST['price'] ?? 0);
        $capacity       = (float)($_POST['capacity'] ?? 0);
        $critical_level = (float)($_POST['critical_level'] ?? 0);
        $reorder_level  = (float)($_POST['reorder_level'] ?? 0);
        $status_val     = trim($_POST['status'] ?? 'active');

        if ($id <= 0) throw new Exception('Invalid fuel product ID.');
        if (empty($fuel_name)) throw new Exception('Fuel product name cannot be empty.');
        if ($price <= 0) throw new Exception('Price per liter must be a positive number greater than 0.');
        if ($capacity <= 0) throw new Exception('Tank capacity must be a positive number greater than 0.');
        if ($critical_level <= 0) throw new Exception('Critical level must be a positive number greater than 0.');
        if ($reorder_level <= 0) throw new Exception('Reorder level must be a positive number greater than 0.');
        if ($critical_level >= $capacity) throw new Exception('Critical level cannot exceed tank capacity.');
        if ($reorder_level >= $capacity) throw new Exception('Reorder level cannot exceed tank capacity.');

        $stmt_f = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id=? AND station_id=? LIMIT 1");
        $stmt_f->execute([$id, $station_id]);
        $old_fuel = $stmt_f->fetch(PDO::FETCH_ASSOC);
        if (!$old_fuel) {
            $stmt_f = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id=? LIMIT 1");
            $stmt_f->execute([$id]);
            $old_fuel = $stmt_f->fetch(PDO::FETCH_ASSOC);
        }

        if ($old_fuel) {
            $fuel_station_id = (int)($old_fuel['station_id'] ?? $station_id);
            $old_ugt     = trim($old_fuel['ugt_no'] ?? '');
            $old_price   = (float)($old_fuel['price_per_liter'] ?? 0);
            $old_name    = trim($old_fuel['fuel_type'] ?? '');
            $old_cap     = (float)($old_fuel['capacity'] ?? 0);
            $old_crit    = (float)($old_fuel['critical_level'] ?? 0);
            $old_reorder = (float)($old_fuel['reorder_level'] ?? 0);
            $old_status  = strtolower($old_fuel['status'] ?? 'active');
            $user_name   = $me['username'] ?? ($me['first_name'] ?? 'Admin');

            $target_fuel_name = !empty($fuel_name) ? $fuel_name : $old_name;
            $target_ugt       = !empty($ugt_no) ? $ugt_no : $old_ugt;

            // Ensure unique_station_fuel constraint is not violated if new name conflicts with an existing tank
            if (strcasecmp($target_fuel_name, $old_name) !== 0) {
                $chk_stmt = $pdo->prepare("SELECT id FROM fuel_inventory WHERE station_id = ? AND fuel_type = ? AND id != ? LIMIT 1");
                $chk_stmt->execute([$fuel_station_id, $target_fuel_name, $id]);
                if ($chk_stmt->fetchColumn()) {
                    $target_fuel_name = $old_name;
                }
            }

            $matching_ids = get_matching_fuel_ids($pdo, $fuel_station_id, $id, $old_name);
            if (empty($matching_ids)) $matching_ids = [$id];
            $in_clause   = implode(',', array_fill(0, count($matching_ids), '?'));

            // ── Admin is owner: cancel any pending price requests for this fuel ──
            try {
                $cancel_params = array_merge([$fuel_station_id], $matching_ids);
                $pdo->prepare("UPDATE pending_price_approvals SET status='cancelled', updated_at=NOW() WHERE station_id=? AND product_type IN ('fuel','fuel_inventory') AND product_id IN ($in_clause) AND status='pending'")
                    ->execute($cancel_params);
            } catch (Exception $e) { /* column may not exist — silently ignore */ }

            // Ensure table fuel_config_history exists
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `fuel_config_history` (
                  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  `station_id` INT(11) NOT NULL,
                  `fuel_inventory_id` INT(11) NOT NULL,
                  `fuel_type` VARCHAR(100) NOT NULL,
                  `field_name` VARCHAR(50) NOT NULL,
                  `old_value` VARCHAR(255) NULL,
                  `new_value` VARCHAR(255) NULL,
                  `updated_by` INT(11) NULL,
                  `updated_by_name` VARCHAR(255) NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (`station_id`),
                  INDEX (`fuel_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Exception $e) {}

            // ── Log configuration changes (non-price fields) ──
            if (!empty($ugt_no) && strcasecmp($old_ugt, $ugt_no) !== 0) {
                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'UGT No', ?, ?, ?, ?, NOW())")
                    ->execute([$fuel_station_id, $id, $target_fuel_name, $old_ugt ?: 'None', $ugt_no, $me['id'], $user_name]);
            }
            if (!empty($fuel_name) && strcasecmp($old_name, $fuel_name) !== 0) {
                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Fuel Name', ?, ?, ?, ?, NOW())")
                    ->execute([$fuel_station_id, $id, $target_fuel_name, $old_name, $fuel_name, $me['id'], $user_name]);
            }
            if (abs($old_cap - $capacity) > 0.001) {
                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Capacity', ?, ?, ?, ?, NOW())")
                    ->execute([$fuel_station_id, $id, $target_fuel_name, number_format($old_cap, 2) . ' L', number_format($capacity, 2) . ' L', $me['id'], $user_name]);
            }
            if (abs($old_crit - $critical_level) > 0.001) {
                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Critical Level', ?, ?, ?, ?, NOW())")
                    ->execute([$fuel_station_id, $id, $target_fuel_name, number_format($old_crit, 2) . ' L', number_format($critical_level, 2) . ' L', $me['id'], $user_name]);
            }
            if (abs($old_reorder - $reorder_level) > 0.001) {
                $pdo->prepare("INSERT INTO fuel_config_history (station_id, fuel_inventory_id, fuel_type, field_name, old_value, new_value, updated_by, updated_by_name, created_at) VALUES (?, ?, ?, 'Reorder Level', ?, ?, ?, ?, NOW())")
                    ->execute([$fuel_station_id, $id, $target_fuel_name, number_format($old_reorder, 2) . ' L', number_format($reorder_level, 2) . ' L', $me['id'], $user_name]);
            }
            if (strcasecmp($old_status, $status_val) !== 0) {
                $status_label = (strtolower($status_val) === 'active') ? 'Activated' : 'Deactivated';
                $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, old_status, new_status, status, reason, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Direct Admin Edit', ?, ?, NOW())")
                    ->execute([$fuel_station_id, $id, $target_fuel_name, ucfirst($old_status), ucfirst($status_val), $status_label, $me['id'], $user_name]);
            }

            // ── Admin always updates ALL fields immediately — no approval needed ──
            // 1. Update this specific fuel inventory record
            $pdo->prepare("UPDATE fuel_inventory SET ugt_no=?, fuel_type=?, price_per_liter=?, capacity=?, critical_level=?, reorder_level=?, status=?, updated_by=?, last_updated=NOW() WHERE id=?")
                ->execute([$target_ugt, $target_fuel_name, $price, $capacity, $critical_level, $reorder_level, $status_val, $me['id'], $id]);

            // 2. Sync price_per_liter ONLY to other matching fuel tanks (do NOT touch fuel_type to prevent duplicate key error)
            if (count($matching_ids) > 1) {
                $other_ids = array_values(array_filter($matching_ids, fn($mid) => (int)$mid !== (int)$id));
                if (!empty($other_ids)) {
                    $other_in = implode(',', array_fill(0, count($other_ids), '?'));
                    $pdo->prepare("UPDATE fuel_inventory SET price_per_liter=?, updated_by=?, last_updated=NOW() WHERE id IN ($other_in)")
                        ->execute(array_merge([$price, $me['id']], $other_ids));
                }
            }

            // 3. Update configured pumps for this fuel product (status and name)
            if (!empty($_POST['edit_pump_status']) && is_array($_POST['edit_pump_status'])) {
                foreach ($_POST['edit_pump_status'] as $p_id => $p_st) {
                    $p_id = (int)$p_id;
                    $p_st = in_array(strtolower($p_st), ['active', 'inactive', 'maintenance']) ? ucfirst(strtolower($p_st)) : 'Active';
                    $p_name = isset($_POST['edit_pump_name'][$p_id]) ? trim($_POST['edit_pump_name'][$p_id]) : '';
                    if ($p_id > 0) {
                        try {
                            if ($p_name !== '') {
                                $pdo->prepare("UPDATE fuel_pumps SET status = ?, pump_number = ?, pump_name = ? WHERE id = ? AND station_id = ?")
                                    ->execute([$p_st, $p_name, $p_name, $p_id, $fuel_station_id]);
                                $pdo->prepare("UPDATE nozzles SET status = ?, pump_name = ? WHERE pump_id = ? AND station_id = ?")
                                    ->execute([$p_st, $p_name, $p_id, $fuel_station_id]);
                            } else {
                                $pdo->prepare("UPDATE fuel_pumps SET status = ? WHERE id = ? AND station_id = ?")->execute([$p_st, $p_id, $fuel_station_id]);
                                $pdo->prepare("UPDATE nozzles SET status = ? WHERE pump_id = ? AND station_id = ?")->execute([$p_st, $p_id, $fuel_station_id]);
                            }
                        } catch (Exception $e) {}
                    }
                }
            }
            if (!empty($_POST['edit_pump_name']) && is_array($_POST['edit_pump_name'])) {
                foreach ($_POST['edit_pump_name'] as $p_id => $p_name) {
                    $p_id = (int)$p_id;
                    $p_name = trim($p_name);
                    if ($p_id > 0 && $p_name !== '') {
                        try {
                            $pdo->prepare("UPDATE fuel_pumps SET pump_number = ?, pump_name = ? WHERE id = ? AND station_id = ?")
                                ->execute([$p_name, $p_name, $p_id, $fuel_station_id]);
                            $pdo->prepare("UPDATE nozzles SET pump_name = ? WHERE pump_id = ? AND station_id = ?")
                                ->execute([$p_name, $p_id, $fuel_station_id]);
                        } catch (Exception $e) {}
                    }
                }
            }

            // ── Sync to fuel_types & fuel_pricing across all matching tanks ──
            try {
                $m_stmt = $pdo->prepare("SELECT id, fuel_type_id FROM fuel_inventory WHERE id IN ($in_clause)");
                $m_stmt->execute($matching_ids);
                $all_m_rows = $m_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($all_m_rows as $mrow) {
                    $ft_id = (int)($mrow['fuel_type_id'] ?? 0);
                    if ($ft_id > 0) {
                        try {
                            if ((int)$mrow['id'] === (int)$id && !empty($fuel_name) && strcasecmp($target_fuel_name, $old_name) === 0) {
                                $pdo->prepare("UPDATE fuel_types SET name = ?, price_per_liter = ? WHERE id = ?")
                                    ->execute([$target_fuel_name, $price, $ft_id]);
                            } else {
                                $pdo->prepare("UPDATE fuel_types SET price_per_liter = ? WHERE id = ?")
                                    ->execute([$price, $ft_id]);
                            }
                        } catch (Exception $e) {}

                        try {
                            $fp_stmt = $pdo->prepare("SELECT id FROM fuel_pricing WHERE station_id = ? AND fuel_type_id = ? AND is_active = 1 LIMIT 1");
                            $fp_stmt->execute([$fuel_station_id, $ft_id]);
                            $fp_id = $fp_stmt->fetchColumn();
                            if ($fp_id) {
                                $pdo->prepare("UPDATE fuel_pricing SET price_per_liter = ?, updated_at = NOW() WHERE id = ?")
                                    ->execute([$price, $fp_id]);
                            } else {
                                $pdo->prepare("INSERT INTO fuel_pricing (station_id, fuel_type_id, price_per_liter, effective_date, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, NOW(), 1, ?, NOW(), NOW())")
                                    ->execute([$fuel_station_id, $ft_id, $price, $me['id']]);
                            }
                        } catch (Exception $e) {}
                    }
                }
            } catch (Exception $e) {}

            // ── Log price change to history if price changed ──
            if (abs($price - $old_price) > 0.001) {
                $diff = $price - $old_price;
                $hist_stmt = $pdo->prepare("INSERT INTO fuel_price_history
                    (station_id, fuel_id, fuel_type, old_price, new_price, difference, reason, requested_by, approved_by, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'Direct Admin Edit', ?, ?, 'Approved', NOW())");
                foreach ($matching_ids as $m_id) {
                    $hist_stmt->execute([
                        $fuel_station_id, $m_id, $target_fuel_name, $old_price, $price, $diff, $me['id'], $me['id']
                    ]);
                }
            }

            log_activity($pdo, $me['id'], 'Direct Admin Edit Fuel', "Admin directly updated fuel [ID: {$id}]: Name '{$old_name}' -> '{$target_fuel_name}', UGT '{$old_ugt}' -> '{$target_ugt}', Price ₱{$old_price} -> ₱{$price}, Capacity {$capacity}L, Critical {$critical_level}L, Reorder {$reorder_level}L, Status {$status_val}");
            $_SESSION['success'] = "Fuel product updated successfully!";
        }
        header("Location: admin_set_prices.php?tab=fuel");
        exit;
    } elseif ($action === 'toggle_fuel_status_admin') {
        $id          = (int)($_POST['id'] ?? 0);
        $new_status  = trim($_POST['status'] ?? 'active');
        $stmt_f = $pdo->prepare("SELECT * FROM fuel_inventory WHERE id=? AND station_id=? LIMIT 1");
        $stmt_f->execute([$id, $station_id]);
        $old_fuel = $stmt_f->fetch(PDO::FETCH_ASSOC);

        if ($old_fuel) {
            $user_name = $me['username'] ?? ($me['first_name'] ?? 'Admin');
            $old_st_text = ucfirst(strtolower($old_fuel['status'] ?? 'active'));
            $new_st_text = ucfirst(strtolower($new_status));
            $status_label = (strtolower($new_status) === 'active') ? 'Activated' : 'Deactivated';
            $pdo->prepare("INSERT INTO fuel_status_history (station_id, fuel_inventory_id, fuel_type, old_status, new_status, status, reason, changed_by, changed_by_name, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Admin Action', ?, ?, NOW())")
                ->execute([$station_id, $id, $old_fuel['fuel_type'], $old_st_text, $new_st_text, $status_label, $me['id'], $user_name]);

            $pdo->prepare("UPDATE fuel_inventory SET status=?, updated_by=?, last_updated=NOW() WHERE id=? AND station_id=?")
                ->execute([$new_status, $me['id'], $id, $station_id]);
            log_activity($pdo, $me['id'], 'Toggle Fuel Status', "Admin set status of {$old_fuel['fuel_type']} ({$old_fuel['ugt_no']}) to {$new_status}");
            $_SESSION['success'] = "Fuel status updated to " . ucfirst($new_status) . ".";
        }
        header("Location: admin_set_prices.php?tab=fuel");
        exit;
    }
        header("Location: admin_set_prices.php?tab=" . urlencode($redirect_tab));
        exit;
    } catch (Throwable $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
        header("Location: admin_set_prices.php?tab=" . urlencode($redirect_tab ?? 'fuel'));
        exit;
    }
}



// ── Fetch station name ──────────────────────────────────────────────────────────────
$station_name = 'Unknown Station';
try {
    $stmt_sn = $pdo->prepare('SELECT name FROM stations WHERE id = ? LIMIT 1');
    $stmt_sn->execute([$station_id]);
    $station_name = $stmt_sn->fetchColumn() ?: 'Unknown Station';
} catch (Exception $e) { /* silent */ }

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

// ── Fetch fuel inventory ────────────────────────────────────────────────────
$fuel_products = [];
try {
    $TANK_CONFIG_17 = get_tank_config((int)$station_id);
    $target_sid = (int)$station_id;
    

    $fi_lookup = [];
    $fi_lookup_by_id = [];
    $fi_status_by_id = [];
    $s = $pdo->prepare("SELECT id, fuel_type, ugt_no, current_level, current_stock, capacity, price_per_liter, latest_calibration, status, last_updated, reorder_level, critical_level FROM fuel_inventory WHERE station_id = ? ORDER BY CAST(REGEXP_REPLACE(COALESCE(ugt_no,'0'), '[^0-9]', '') AS UNSIGNED) ASC, id ASC");
    $s->execute([$target_sid]);
    $fi_raw = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fi_raw as $row) {
        $fuel_key = strtolower(trim($row['fuel_type']));
        $ugt_val  = strtolower(trim($row['ugt_no'] ?? ''));

        // Index by full fuel_type name
        if (!isset($fi_lookup[$fuel_key])) {
            $fi_lookup[$fuel_key] = $row;
        }
        // Index by UGT number string (e.g. "ugt #1")
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

        // ── Extra canonical/variant keys so TANK_CONFIG ft_key matches ─────
        // e.g. fuel_type='Diesel 1 (UGT #1)' -> also store under 'diesel 1', 'diesel', 'diesel 1 (ugt #1)'
        // Extract number suffix from fuel_type if present
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

        // Pump count — directly from the deduplicated fuel management lookup
        $p_count = count($pumps_by_fi_id[$r_id] ?? []);

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
            'pump_count'     => $p_count
        ];
    }

    // Sort by UGT number (numeric) so display order matches Fuel Management
    usort($fuel_products, function($a, $b) {
        $an = (int)preg_replace('/[^0-9]/', '', $a['ugt_no'] ?? '');
        $bn = (int)preg_replace('/[^0-9]/', '', $b['ugt_no'] ?? '');
        return $an - $bn;
    });

} catch (Exception $e) {
    $fuel_products = [];
    error_log('[admin_set_prices] fuel error: ' . $e->getMessage());
}

// ── Fetch merchandise grouped by category ───────────────────────────────────
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
}

// ── Admin Summary Metrics ───────────────────────────────────────────────────
$approved_today_count = 0;
$pending_requests_count = 0;
try {
    $stmt_ap = $pdo->prepare("
        SELECT COUNT(*) FROM pending_price_approvals
        WHERE station_id = ? AND status = 'approved' AND (DATE(reviewed_at) = CURDATE() OR DATE(updated_at) = CURDATE())
    ");
    $stmt_ap->execute([(int)$target_sid]);
    $approved_today_count = (int)$stmt_ap->fetchColumn();

    $stmt_pr = $pdo->prepare("
        SELECT COUNT(*) FROM pending_price_approvals
        WHERE station_id = ? AND status = 'pending' AND product_type = 'merchandise'
    ");
    $stmt_pr->execute([(int)$target_sid]);
    $pending_requests_count = (int)$stmt_pr->fetchColumn();
} catch (Exception $e) {}


// ── Pre-load merchandise batches per product ──────────────────────────────
$merch_batches_by_product = [];
try {
    $bStmt = $pdo->prepare("
        SELECT mb.*
        FROM merchandise_batches mb
        WHERE mb.station_id = ? AND LOWER(COALESCE(mb.status, 'active')) NOT IN ('cancelled', 'disabled')
        ORDER BY mb.date_received ASC, mb.id ASC
    ");
    $bStmt->execute([(int)$station_id]);
    foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $merch_batches_by_product[(int)$b['product_id']][] = $b;
    }
} catch (Exception $e) {}

$all_categories = array_keys($all_categories);
sort($all_categories);
$all_brands = array_keys($all_brands);
sort($all_brands);
$all_units = array_keys($all_units);
sort($all_units);
$all_suppliers = array_keys($all_suppliers);
sort($all_suppliers);

// ── Log page view ────────────────────────────────────────────────────────────
try {
    log_activity($pdo, $me['id'], 'View Product Pricing',
        "Admin viewed pricing for station {$station_id}");
} catch (Exception $e) { /* silent */ }

// ── Fetch service types with pending approvals ─────────────────────────────
$service_types = [];
$service_error = null;
try {
    $stmt = $pdo->prepare("
        SELECT s.id,
               COALESCE(s.service_code, CONCAT('SRV-', LPAD(s.id,4,'0'))) AS service_code,
               s.service_name, s.service_key, s.category, s.service_price,
               s.labor_fee, s.estimated_duration, s.required_mechanics,
               s.description, s.active, s.updated_at,
               p.new_price     AS pending_price,
               p.new_cost      AS pending_labor_fee,
               p.old_price     AS old_service_fee,
               p.old_cost      AS old_labor_fee,
               p.manager_id    AS pending_manager_id,
               p.status        AS approval_status,
               p.id            AS approval_id
        FROM job_order_service_types s
        LEFT JOIN pending_price_approvals p
               ON s.id = p.product_id
              AND p.station_id = s.station_id
              AND p.product_type IN ('service', 'service_type')
              AND p.status = 'pending'
        WHERE s.station_id = ?
        ORDER BY s.service_name
    ");
    $stmt->execute([(int)$station_id]);
    $service_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add manager names in a second pass
    foreach ($service_types as &$svc) {
        $svc['manager_name'] = null;
        if (!empty($svc['pending_manager_id'])) {
            try {
                $uStmt = $pdo->prepare("SELECT COALESCE(CONCAT(first_name,' ',last_name), username) FROM users WHERE id = ? LIMIT 1");
                $uStmt->execute([$svc['pending_manager_id']]);
                $svc['manager_name'] = $uStmt->fetchColumn() ?: 'Unknown';
            } catch (Exception $ue) {
                $svc['manager_name'] = 'Unknown';
            }
        }
    }
    unset($svc);
} catch (Exception $e) {
    $service_types = [];
    $service_error = null; // suppress debug output in production
    error_log("[admin_set_prices] service types error: " . $e->getMessage());
}

// ── Active tab (persists across refresh via ?tab= query param) ───────────────
$active_tab = $_GET['tab'] ?? 'fuel';
if (!in_array($active_tab, ['fuel', 'merch', 'services'])) $active_tab = 'fuel';

// ── AJAX JSON POLLING ENDPOINT FOR ADMIN PRODUCT & PRICING OVERVIEW ─────────
if (isset($_GET['ajax_asp']) && $_GET['ajax_asp'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'counts' => [
            'fuel_count'      => count($fuel_products),
            'merch_count'     => count($merch_all),
            'service_count'   => count($service_types),
            'total_count'     => count($fuel_products) + count($merch_all) + count($service_types),
            'approved_today'  => (int)$approved_today_count,
            'pending_requests'=> (int)$pending_requests_count
        ]
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Main Content & Page Header ── */
.main-content {
    padding: 0 !important;
    box-sizing: border-box;
    width: 100%;
    overflow-y: visible !important;
    overflow-x: visible !important;
    height: auto !important;
}
/* Reports-Style Export Bar & Buttons */
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
.page-head {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin-bottom: 22px !important;
    margin-top: 0 !important;
    padding: 0 !important;
    border: none !important;
    width: 100%;
}
.page-head h1, .page-head .h1 {
    margin: 0 !important;
    color: #002f70 !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    line-height: 1.2 !important;
}

/* ── Section Tabs ── */
.ato-tab-bar {
    display: flex !important;
    flex-wrap: wrap !important;
    margin-bottom: 22px !important;
    border: 1px solid #d1d9e6 !important;
    border-radius: 0 !important;
    overflow: hidden !important;
    border-bottom: 3px solid #00264D !important;
    gap: 0 !important;
    background: transparent !important;
    padding: 0 !important;
    width: 100% !important;
}
.ato-tab {
    flex: 1 !important;
    min-width: 140px !important;
    padding: 12px 18px !important;
    font-size: 13px !important;
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
    gap: 8px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    text-align: center !important;
    cursor: pointer !important;
    pointer-events: all !important;
    user-select: none !important;
    position: relative !important;
    z-index: 5 !important;
    margin-bottom: 0 !important;
    box-shadow: none !important;
}
.ato-tab * { pointer-events: none !important; }
.ato-tab:last-child { border-right: none !important; }
.ato-tab:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.ato-tab.active {
    background: #00264D !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    border-bottom-color: transparent !important;
}

/* Tab Panels */
.tab-panel { display: none !important; }
.tab-panel.active { display: block !important; }

/* ── Summary Metric Cards ── */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px 18px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.summary-card .s-num { font-size: 28px; font-weight: 700; line-height: 1; text-decoration: none !important; }
.summary-card .s-lbl { font-size: 13px; color: #64748b; margin-top: 6px; font-weight: 600; }
.summary-card.s-total .s-num { color: #002F6C; }
.summary-card.s-valid .s-num { color: #16a34a; }
.summary-card.s-below .s-num { color: #dc2626; }
.summary-card.s-unpriced .s-num { color: #d97706; }

/* ── Toolbar & Filters ── */
.toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 16px;
    background: #f8fafc;
    padding: 12px 16px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}
.toolbar input[type="text"],
.toolbar select {
    padding: 9px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 14.5px;
    color: #334155;
    background: #fff;
}
.toolbar input[type="text"]:focus,
.toolbar select:focus {
    outline: none;
    border-color: #002F6C;
    box-shadow: 0 0 0 2px rgba(0,47,108,.12);
}

/* ── Professional, Zero-Overlap Table System ── */
.table-card, .card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
    overflow-y: visible !important;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04);
}
.table-wrap {
    width: 100% !important;
    overflow-x: hidden !important;
    overflow-y: visible !important;
    -webkit-overflow-scrolling: touch;
    box-sizing: border-box !important;
}

table.pricing-table {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    table-layout: fixed !important;
    border-collapse: collapse !important;
    box-sizing: border-box !important;
    background: #ffffff !important;
}

table.pricing-table th {
    background: #002F6C !important;
    color: #ffffff !important;
    padding: 10px 6px !important;
    font-size: 12.5px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.2px !important;
    border-bottom: 2px solid #001f48 !important;
    border-top: none !important;
    white-space: normal !important;
    word-break: normal !important;
    vertical-align: middle !important;
    line-height: 1.2 !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
}

table.pricing-table td {
    padding: 8px 6px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    vertical-align: middle !important;
    font-size: 13px !important;
    line-height: 1.35 !important;
    box-sizing: border-box !important;
    color: #1e293b !important;
    white-space: normal !important;
    word-break: normal !important;
    overflow-wrap: break-word !important;
    overflow: hidden !important;
}

table.pricing-table tbody tr {
    transition: background 0.12s ease;
}
table.pricing-table tbody tr:hover {
    background: #f8fafc !important;
}

/* Category header row */
.cat-row td {
    background: #f1f5f9 !important;
    font-weight: 800 !important;
    font-size: 14px !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
    color: #1e293b !important;
    padding: 10px 16px !important;
    border-bottom: 1px solid #e2e8f0 !important;
}

/* Row highlight for price-below-cost */
.row-below-cost { background: #fff5f5 !important; }
.row-below-cost:hover { background: #fee2e2 !important; }

/* ── Badges & Status Indicators ── */
.badge, .status-badge, .status-pill {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 3px !important;
    padding: 3px 7px !important;
    border-radius: 999px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.2px !important;
    white-space: nowrap !important;
    line-height: 1.15 !important;
    box-sizing: border-box !important;
    max-width: 100% !important;
}
.badge-normal    { background: #dcfce7 !important; color: #15803d !important; border: 1px solid #86efac !important; }
.badge-available { background: #dcfce7 !important; color: #15803d !important; border: 1px solid #86efac !important; }
.badge-ok        { background: #dcfce7 !important; color: #15803d !important; border: 1px solid #86efac !important; }
.badge-active    { background: #dcfce7 !important; color: #15803d !important; border: 1px solid #86efac !important; }
.badge-critical  { background: #fee2e2 !important; color: #b91c1c !important; border: 1px solid #fca5a5 !important; }
.badge-low       { background: #fef3c7 !important; color: #92400e !important; border: 1px solid #fde68a !important; }
.badge-out       { background: #fee2e2 !important; color: #b91c1c !important; border: 1px solid #fca5a5 !important; }
.badge-inactive  { background: #fee2e2 !important; color: #b91c1c !important; border: 1px solid #f87171 !important; }
.badge-noprice   { background: #fef3c7 !important; color: #92400e !important; border: 1px solid #fde68a !important; }
.badge-warn      { background: #fee2e2 !important; color: #b91c1c !important; border: 1px solid #fca5a5 !important; }

/* ── Action Buttons ── */
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
    gap: 4px !important;
    width: 96px !important;
    min-width: 96px !important;
    max-width: 96px !important;
    min-height: 25px !important;
    height: 25px !important;
    padding: 3px 6px !important;
    border-radius: 5px !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    line-height: 1.2 !important;
    margin-bottom: 0 !important;
    transition: all .15s ease-in-out !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    text-decoration: none !important;
    box-sizing: border-box !important;
    text-align: center !important;
}
.act-btn i { color: inherit !important; -webkit-text-fill-color: inherit !important; font-size: 11px !important; flex-shrink: 0 !important; }

.act-btn-view { color: #475569 !important; -webkit-text-fill-color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.act-btn-view:hover { background: #475569 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #475569 !important; }

.act-btn-viewreq { color: #475569 !important; -webkit-text-fill-color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.act-btn-viewreq:hover { background: #475569 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #475569 !important; }

.act-btn-batches { color: #475569 !important; -webkit-text-fill-color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.act-btn-batches:hover { background: #475569 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #475569 !important; }

.act-btn-history { color: #64748b !important; -webkit-text-fill-color: #64748b !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.act-btn-history:hover { background: #64748b !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #64748b !important; }

.act-btn-edit { color: #002F6C !important; -webkit-text-fill-color: #002F6C !important; border-color: #002F6C !important; background: #ffffff !important; }
.act-btn-edit:hover { background: #002F6C !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #002F6C !important; }

.act-btn-deactivate { color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; border: 1.5px solid #f87171 !important; background: #fff5f5 !important; }
.act-btn-deactivate:hover { background: #dc2626 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #dc2626 !important; }

.act-btn-activate { color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border: 1.5px solid #15803d !important; background: #16a34a !important; font-weight: 700 !important; box-shadow: 0 1px 3px rgba(22,163,74,0.3) !important; }
.act-btn-activate:hover { background: #15803d !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #166534 !important; }

.act-btn-approve { color: #16a34a !important; -webkit-text-fill-color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.act-btn-approve:hover { background: #16a34a !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #16a34a !important; }

.act-btn-reject { color: #dc2626 !important; -webkit-text-fill-color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.act-btn-reject:hover { background: #dc2626 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #dc2626 !important; }

.act-btn-restore { color: #d97706 !important; -webkit-text-fill-color: #d97706 !important; border-color: #d97706 !important; background: #ffffff !important; }
.act-btn-restore:hover { background: #d97706 !important; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; border-color: #d97706 !important; }

@media (max-width: 768px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .toolbar { flex-direction: column; align-items: stretch; }
    .toolbar input[type="text"] { min-width: unset; width: 100%; }
}

/* ── Clean Modal Footer Buttons (No solid dark blue or light blue background) ── */
#viewAdminServiceModal #adm_vs_close_btn,
#viewAdminServiceModal button[onclick*="closeAdminViewServiceModal"] {
    background: transparent !important;
    background-color: transparent !important;
    background-image: none !important;
    color: #475569 !important;
    -webkit-text-fill-color: #475569 !important;
    border: 1.5px solid #cbd5e1 !important;
    box-shadow: none !important;
}
#viewAdminServiceModal #adm_vs_close_btn:hover,
#viewAdminServiceModal button[onclick*="closeAdminViewServiceModal"]:hover {
    background: #f1f5f9 !important;
    background-color: #f1f5f9 !important;
    color: #0f172a !important;
    -webkit-text-fill-color: #0f172a !important;
    border-color: #94a3b8 !important;
}

#viewAdminServiceModal #adm_vs_edit_btn {
    background: transparent !important;
    background-color: transparent !important;
    background-image: none !important;
    color: #002F6C !important;
    -webkit-text-fill-color: #002F6C !important;
    border: 1.5px solid #002F6C !important;
    box-shadow: none !important;
}
#viewAdminServiceModal #adm_vs_edit_btn:hover {
    background: #f0f7ff !important;
    background-color: #f0f7ff !important;
    color: #001f47 !important;
    -webkit-text-fill-color: #001f47 !important;
    border-color: #001f47 !important;
}

#priceHistoryModal button[onclick*="closePriceHistoryModal"],
#viewAdminMerchModal button[onclick*="closeAdminViewMerchModal"],
#viewAdminBatchesModal button[onclick*="closeAdminBatchesModal"],
#viewFuelModalAdmin button[onclick*="closeViewFuelModalAdmin"] {
    background: transparent !important;
    background-color: transparent !important;
    background-image: none !important;
    color: #475569 !important;
    -webkit-text-fill-color: #475569 !important;
    border: 1.5px solid #cbd5e1 !important;
    box-shadow: none !important;
}
#priceHistoryModal button[onclick*="closePriceHistoryModal"]:hover,
#viewAdminMerchModal button[onclick*="closeAdminViewMerchModal"]:hover,
#viewAdminBatchesModal button[onclick*="closeAdminBatchesModal"]:hover,
#viewFuelModalAdmin button[onclick*="closeViewFuelModalAdmin"]:hover {
    background: #f1f5f9 !important;
    background-color: #f1f5f9 !important;
    color: #0f172a !important;
    -webkit-text-fill-color: #0f172a !important;
    border-color: #94a3b8 !important;
}

/* ── Pump card edit & save buttons (Remove dark blue button background, high visibility) ── */
#aef_pumps_container button,
#aef_pumps_container .btn-edit-pump-name,
#aef_pumps_container button[id^="btn_edit_pump_"],
.btn-edit-pump-name {
    width: 26px !important;
    height: 26px !important;
    min-width: 26px !important;
    max-width: 26px !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    background-image: none !important;
    border: 1.5px solid #94a3b8 !important;
    border-radius: 5px !important;
    color: #002F6C !important;
    -webkit-text-fill-color: #002F6C !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06) !important;
    transition: all 0.15s ease !important;
}
#aef_pumps_container .btn-edit-pump-name:hover,
#aef_pumps_container button[id^="btn_edit_pump_"]:hover,
.btn-edit-pump-name:hover {
    background: #e0f2fe !important;
    background-color: #e0f2fe !important;
    border-color: #002F6C !important;
    color: #002F6C !important;
    -webkit-text-fill-color: #002F6C !important;
}
#aef_pumps_container .btn-edit-pump-name i,
#aef_pumps_container button[id^="btn_edit_pump_"] i,
.btn-edit-pump-name i {
    font-size: 11.5px !important;
    color: #002F6C !important;
    -webkit-text-fill-color: #002F6C !important;
    display: inline-block !important;
    line-height: 1 !important;
}

/* ── Modal Layout Centering Fix (Excluding Sidebar Navigation from Centering) ── */
.admin-layout-modal {
    position: fixed !important;
    top: 70px !important;
    bottom: 40px !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    height: auto !important;
    max-height: calc(100vh - 110px) !important;
    box-sizing: border-box !important;
    z-index: 9999 !important;
    overflow: hidden !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 20px !important;
}

.admin-modal-close-x {
    background: rgba(255, 255, 255, 0.18) !important;
    border: none !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    width: 32px !important;
    height: 32px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 15px !important;
    box-shadow: none !important;
    transition: background 0.15s ease !important;
}
.admin-modal-close-x:hover {
    background: rgba(255, 255, 255, 0.35) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: none !important;
}

@media (min-width: 992px) {
    .admin-layout-modal,
    #viewAdminMerchModal,
    #viewFuelModalAdmin,
    #viewAdminServiceModal,
    #viewAdminBatchesModal,
    #adminEditProductModal,
    #adminEditFuelModal,
    #adminEditServiceModal,
    #addProductModal,
    #addMerchandiseModal,
    #addServiceModal,
    #priceHistoryModal,
    #viewRequestModal,
    #rejectModal,
    #approveConfirmModal,
    #rejectReasonModal,
    #editPriceModalAdmin,
    #rejectPriceModalAdmin,
    #approvePriceModalAdmin,
    #toggleFuelStatusModal,
    #toggleServiceStatusModal,
    #restoreServiceFeesModal {
        left: 250px !important;
        width: calc(100% - 250px) !important;
        right: 0 !important;
        bottom: 40px !important;
        box-sizing: border-box !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 20px !important;
    }
    /* confirmationModal always covers full viewport — never offset by sidebar */
    #confirmationModal {
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        height: 100% !important;
        bottom: 0 !important;
        right: 0 !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 20px !important;
        box-sizing: border-box !important;
    }

    body.sidebar-collapsed .admin-layout-modal,
    body.sidebar-collapsed #viewAdminMerchModal,
    body.sidebar-collapsed #viewFuelModalAdmin,
    body.sidebar-collapsed #viewAdminServiceModal,
    body.sidebar-collapsed #viewAdminBatchesModal,
    body.sidebar-collapsed #adminEditProductModal,
    body.sidebar-collapsed #adminEditFuelModal,
    body.sidebar-collapsed #adminEditServiceModal,
    body.sidebar-collapsed #addProductModal,
    body.sidebar-collapsed #addMerchandiseModal,
    body.sidebar-collapsed #addServiceModal,
    body.sidebar-collapsed #priceHistoryModal,
    body.sidebar-collapsed #viewRequestModal,
    body.sidebar-collapsed #rejectModal,
    body.sidebar-collapsed #approveConfirmModal,
    body.sidebar-collapsed #rejectReasonModal,
    body.sidebar-collapsed #editPriceModalAdmin,
    body.sidebar-collapsed #rejectPriceModalAdmin,
    body.sidebar-collapsed #approvePriceModalAdmin,
    body.sidebar-collapsed #toggleFuelStatusModal,
    body.sidebar-collapsed #toggleServiceStatusModal,
    body.sidebar-collapsed #restoreServiceFeesModal {
        left: 70px !important;
        width: calc(100% - 70px) !important;
        right: 0 !important;
        bottom: 40px !important;
    }

    #editPriceModalAdmin,
    #adminEditProductModal,
    #adminEditFuelModal,
    #adminEditServiceModal,
    #addProductModal,
    #addMerchandiseModal,
    #addServiceModal {
        align-items: flex-start !important;
        padding-top: 25px !important;
        padding-bottom: 50px !important;
        overflow-y: auto !important;
    }
    #editPriceModalAdmin > div,
    #adminEditProductModal > div,
    #adminEditFuelModal > div,
    #adminEditServiceModal > div,
    #addProductModal > div,
    #addMerchandiseModal > div,
    #addServiceModal > div {
        margin: 15px auto 50px auto !important;
        max-height: calc(100vh - 120px) !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }
}

.modal-table-wrap {
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

.no-min-width {
    width: 100% !important;
    min-width: 0 !important;
}
</style>
<style>
/* ── STRICT ANTI-OVERLAP RULES FOR ADMIN TABLES ── */
#adminMerchTable,
#adminFuelTable,
.pricing-table {
    table-layout: fixed !important;
    width: 100% !important;
}

#adminMerchTable th,
#adminMerchTable td,
#adminFuelTable th,
#adminFuelTable td {
    box-sizing: border-box !important;
    vertical-align: middle !important;
}

#adminMerchTable td:nth-child(2) {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    line-height: 1.35 !important;
    max-width: 0 !important;
}

#adminMerchTable td:nth-child(2) strong {
    white-space: normal !important;
    word-break: break-word !important;
    overflow-wrap: break-word !important;
    display: block !important;
}
</style>


<div class="main-content">
<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-tags"></i> Product &amp; Pricing Overview</h1>
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


<!-- ── Section Tabs ──────────────────────────────────────────────────── -->
<input type="hidden" id="activeSection" value="<?php echo htmlspecialchars($active_tab); ?>">
<div class="ato-tab-bar">
    <a href="javascript:void(0)" onclick="switchTab('fuel'); return false;" id="tab-btn-fuel" class="ato-tab <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>"><i class="fas fa-gas-pump"></i> Fuel Products</a>
    <a href="javascript:void(0)" onclick="switchTab('merch'); return false;" id="tab-btn-merch" class="ato-tab <?php echo $active_tab === 'merch' ? 'active' : ''; ?>"><i class="fas fa-box"></i> Merchandise</a>
    <a href="javascript:void(0)" onclick="switchTab('services'); return false;" id="tab-btn-services" class="ato-tab <?php echo $active_tab === 'services' ? 'active' : ''; ?>"><i class="fas fa-wrench"></i> Service Types</a>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 1 — FUEL PRODUCTS (ADMIN OVERVIEW & APPROVALS)
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-fuel" class="tab-panel <?php echo $active_tab === 'fuel' ? 'active' : ''; ?>">

<?php if (!empty($_SESSION['success'])): ?>
    <div style="background:#dcfce7;border:1.5px solid #86efac;border-radius:8px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:15.5px;color:#166534;font-weight:600;">
        <i class="fas fa-check-circle" style="font-size:16px;"></i>
        <span><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
    </div>
<?php elseif (!empty($_SESSION['error'])): ?>
    <div style="background:#fee2e2;border:1.5px solid #fca5a5;border-radius:8px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:15.5px;color:#991b1b;font-weight:600;">
        <i class="fas fa-exclamation-circle" style="font-size:16px;"></i>
        <span><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
    </div>
<?php elseif (!empty($_SESSION['warning'])): ?>
    <div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:8px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:15.5px;color:#92400e;font-weight:600;">
        <i class="fas fa-lock" style="font-size:16px;"></i>
        <span><?php echo $_SESSION['warning']; unset($_SESSION['warning']); ?></span>
    </div>
<?php endif; ?>

    <?php
    $total_fuel_count   = count($fuel_products);
    $pending_req_count  = 0;
    $active_fuel_count  = 0;
    $inactive_fuel_count = 0;
    $station_fuel_types = [];
    $station_ugts       = [];

    foreach ($fuel_products as $fp) {
        if (!empty($fp['approval_status']) && $fp['approval_status'] === 'pending') {
            $pending_req_count++;
        }
        if (($fp['inv_status'] ?? 'active') === 'active') {
            $active_fuel_count++;
        } else {
            $inactive_fuel_count++;
        }

        $raw_name = !empty($fp['fuel_type']) ? $fp['fuel_type'] : ($fp['raw_fuel_type'] ?? '');
        $clean_name = trim(preg_replace('/\s*\(UGT\s*#?\d+\)/i', '', $raw_name));
        $full_name = $clean_name !== '' ? $clean_name : $raw_name;
        $canonical_ft = get_canonical_fuel_name($full_name);
        if ($canonical_ft !== '') {
            $station_fuel_types[$canonical_ft] = $canonical_ft;
        }

        $ugt_str = !empty($fp['ugt_no']) ? $fp['ugt_no'] : (!empty($fp['pump_id']) ? ('UGT #' . $fp['pump_id']) : '');
        if ($ugt_str !== '') {
            $station_ugts[$ugt_str] = $ugt_str;
        }
    }

    // Ensure the 5 canonical fuel types matching Manager view are accurately fetched and populated
    try {
        $db_ft_stmt = $pdo->query("
            SELECT DISTINCT
                CASE
                    WHEN UPPER(name) LIKE '%TURBO%DIESEL%' THEN 'Turbo Diesel'
                    WHEN UPPER(name) LIKE '%KEROSENE%'     THEN 'Kerosene'
                    WHEN UPPER(name) LIKE '%XCS%'          THEN 'XCS Plus'
                    WHEN UPPER(name) LIKE '%XTRA%UNL%'     THEN 'Xtra UNL'
                    WHEN UPPER(name) LIKE '%DIESEL%'       THEN 'Diesel'
                    ELSE TRIM(name)
                END AS canonical_name
            FROM fuel_types
            WHERE name IS NOT NULL AND TRIM(name) != ''
            ORDER BY canonical_name ASC
        ");
        foreach ($db_ft_stmt->fetchAll(PDO::FETCH_COLUMN) as $dft) {
            $c_name = get_canonical_fuel_name($dft);
            if ($c_name !== '') {
                $station_fuel_types[$c_name] = $c_name;
            }
        }
    } catch (Exception $e) {}

    // Guarantee exactly the 5 standard Petron fuel types matching Manager
    $standard_5_fuels = ['Diesel', 'Kerosene', 'Turbo Diesel', 'XCS Plus', 'Xtra UNL'];
    foreach ($standard_5_fuels as $s5) {
        $station_fuel_types[$s5] = $s5;
    }
    ksort($station_fuel_types);
    natsort($station_ugts);
    ?>

    <!-- ── 1. Admin Filters Bar (Positioned at top so select dropdowns open downwards, matching Manager) ── -->
    <div class="toolbar" style="margin-bottom:16px;background:#f8fafc;padding:12px 16px;border-radius:10px;border:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <input type="text" id="adminFuelSearch" placeholder="Search UGT or Fuel Name..." oninput="filterAdminFuelTable()" style="min-width:200px;flex:1;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;">
        
        <select id="adminFuelTypeFilter" onchange="filterAdminFuelTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;background:#fff;">
            <option value="">All Fuel Types</option>
            <?php foreach ($station_fuel_types as $ft): ?>
                <option value="<?php echo htmlspecialchars($ft); ?>"><?php echo htmlspecialchars($ft); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminFuelUgtFilter" onchange="filterAdminFuelTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;background:#fff;">
            <option value="">All UGTs</option>
            <?php foreach ($station_ugts as $ugt): ?>
                <option value="<?php echo htmlspecialchars($ugt); ?>"><?php echo htmlspecialchars($ugt); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminFuelPriceReqFilter" onchange="filterAdminFuelTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;background:#fff;">
            <option value="">All Request Statuses</option>
            <option value="pending">Pending Approval Only</option>
            <option value="none">None / Approved</option>
            <option value="rejected">Rejected</option>
        </select>

        <select id="adminFuelStatusFilter" onchange="filterAdminFuelTable()" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:15.5px;background:#fff;">
            <option value="">All Active/Inactive Statuses</option>
            <option value="active">Active Only</option>
            <option value="inactive">Inactive Only</option>
        </select>
    </div>

    <!-- ── 2. Admin Fuel Summary Metric Cards ────────────────────────────── -->
    <div class="summary-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(170px, 1fr));gap:14px;margin-bottom:16px;">
        <div class="summary-card s-total" onclick="filterAdminFuelByCard('all')" style="cursor:pointer;transition:transform 0.15s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div class="s-num"><?php echo $total_fuel_count; ?></div>
            <div class="s-lbl"><i class="fas fa-gas-pump"></i> Total Fuel Products</div>
        </div>
        <div class="summary-card" onclick="filterAdminFuelByCard('pending')" style="cursor:pointer;background:#fffbeb;border:1.5px solid #fde68a;transition:transform 0.15s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div class="s-num" style="color:#d97706;"><?php echo $pending_req_count; ?></div>
            <div class="s-lbl" style="color:#b45309;font-weight:700;"><i class="fas fa-clock"></i> Pending Price Requests</div>
        </div>
        <div class="summary-card s-valid" onclick="filterAdminFuelByCard('active')" style="cursor:pointer;transition:transform 0.15s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div class="s-num" style="color:#16a34a;"><?php echo $active_fuel_count; ?></div>
            <div class="s-lbl"><i class="fas fa-check-circle"></i> Active Products</div>
        </div>
        <div class="summary-card s-below" onclick="filterAdminFuelByCard('inactive')" style="cursor:pointer;transition:transform 0.15s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
            <div class="s-num" style="color:#dc2626;"><?php echo $inactive_fuel_count; ?></div>
            <div class="s-lbl"><i class="fas fa-ban"></i> Inactive Products</div>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
        <button type="button" onclick="openAddProductModal()" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:15.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:all 0.2s;">
            <i class="fas fa-plus-circle"></i> Add Product
        </button>
    </div>

    <!-- ── 3. Fuel Inventory & Pricing Table ─────────────────────────────── -->
    <div class="card" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;border-radius:10px;">

        <div class="table-wrap" style="width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;">
            <table class="pricing-table" id="adminFuelTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
                <colgroup>
                    <col style="width:6.5%;">  <!-- UGT No. -->
                    <col style="width:14%;">    <!-- Fuel Type -->
                    <col style="width:8.5%;">   <!-- Price / Liter -->
                    <col style="width:8.5%;">   <!-- Current Volume -->
                    <col style="width:8.5%;">   <!-- Capacity -->
                    <col style="width:8%;">     <!-- Reorder Level -->
                    <col style="width:9.5%;">   <!-- Status -->
                    <col style="width:13.5%;">  <!-- Price Request Status -->
                    <col style="width:9.5%;">   <!-- Last Updated -->
                    <col style="width:13.5%;">  <!-- Actions -->
                </colgroup>
                <thead>
                    <tr style="background:#002F6C !important;">
                        <th style="width:6.5%;text-align:left;">UGT No.</th>
                        <th style="width:14%;text-align:left;">Fuel Type</th>
                        <th style="width:8.5%;text-align:right;">Price / Liter</th>
                        <th style="width:8.5%;text-align:right;">Current Vol (L)</th>
                        <th style="width:8.5%;text-align:right;">Capacity (L)</th>
                        <th style="width:8%;text-align:right;">Reorder (L)</th>
                        <th style="width:9.5%;text-align:center;">Status</th>
                        <th style="width:13.5%;text-align:center;">Price Req.</th>
                        <th style="width:9.5%;text-align:center;">Last Updated</th>
                        <th style="width:13.5%;text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="adminFuelTableBody">
                <?php if (empty($fuel_products)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:28px;color:#94a3b8;font-size:14.5px;">
                            <i class="fas fa-info-circle"></i> No fuel inventory records found for this station.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($fuel_products as $f):
                        $level    = (float)($f['current_stock'] ?? 0);
                        $critical = (float)($f['critical_level'] ?? 0);
                        $capacity = (float)($f['capacity'] ?? 0);
                        $reorder  = (float)($f['reorder_level'] ?? 0);
                        
                        $raw_status = strtolower(trim($f['status'] ?? 'normal'));
                        $fuel_active_status = strtolower($f['inv_status'] ?? ($f['status'] ?? 'active'));
                        $is_deactivated = in_array($fuel_active_status, ['inactive', 'disabled', 'deactivated'], true);

                        if ($is_deactivated) {
                            $status_label = 'Deactivated';
                            $status_class = 'badge-inactive';
                            $badge_style  = 'background:#fee2e2 !important;color:#b91c1c !important;border:1.5px solid #f87171 !important;';
                        } elseif ($level <= 0 || in_array($raw_status, ['out of stock', 'out', 'empty'])) {
                            $status_label = 'Out of Stock';
                            $status_class = 'badge-out';
                            $badge_style  = 'background:#fee2e2 !important;color:#b91c1c !important;border:1px solid #fca5a5 !important;';
                        } elseif (($critical > 0 && $level <= $critical) || in_array($raw_status, ['critical', 'crit'])) {
                            $status_label = 'Critical';
                            $status_class = 'badge-critical';
                            $badge_style  = 'background:#fee2e2 !important;color:#b91c1c !important;border:1px solid #fca5a5 !important;';
                        } elseif (($reorder > 0 && $level <= $reorder) || in_array($raw_status, ['low', 'low stock', 'reorder'])) {
                            $status_label = 'Low Stock';
                            $status_class = 'badge-low';
                            $badge_style  = 'background:#fef3c7 !important;color:#92400e !important;border:1px solid #fde68a !important;';
                        } else {
                            $status_label = 'Normal';
                            $status_class = 'badge-normal';
                            $badge_style  = 'background:#dcfce7 !important;color:#15803d !important;border:1px solid #86efac !important;';
                        }
                        
                        $ugt_str = $f['ugt_no'] ?? ('UGT #' . $f['pump_id']);
                        $raw_name = !empty($f['fuel_type']) ? $f['fuel_type'] : ($f['raw_fuel_type'] ?? 'Fuel');
                        $clean_name = trim(preg_replace('/\s*\(UGT\s*#?\d+\)/i', '', $raw_name));
                        $full_fuel_name = $clean_name !== '' ? $clean_name : $raw_name;
                        $canonical_type = get_canonical_fuel_name($full_fuel_name);
                        $req_status = strtolower($f['approval_status'] ?? '');
                    ?>
                    <tr class="admin-fuel-row" 
                        data-ugt="<?php echo htmlspecialchars($ugt_str); ?>" 
                        data-fueltype="<?php echo htmlspecialchars($canonical_type); ?>"
                        data-fullname="<?php echo htmlspecialchars($full_fuel_name); ?>"
                        data-reqstatus="<?php echo htmlspecialchars($req_status ?: 'none'); ?>"
                        data-activestatus="<?php echo $is_deactivated ? 'inactive' : 'active'; ?>"
                        style="<?php echo $is_deactivated ? 'background:#fff5f5;' : ''; ?>">
                        
                        <!-- UGT No. -->
                        <td style="vertical-align:middle;">
                            <strong style="font-family:monospace;color:#002F6C;font-size:13px;white-space:nowrap;"><?php echo htmlspecialchars($ugt_str); ?></strong>
                        </td>
                        
                        <!-- Fuel Type -->
                        <td style="vertical-align:middle;">
                            <div style="display:flex;flex-direction:column;align-items:flex-start;gap:4px;">
                                <strong style="font-size:13.5px;<?php echo $is_deactivated ? 'color:#64748b;' : 'color:#0f172a;'; ?>line-height:1.3;word-break:break-word;"><?php echo htmlspecialchars($full_fuel_name); ?></strong>
                                <?php if (!empty($f['pump_count']) && (int)$f['pump_count'] > 0): ?>
                                    <span class="badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:4px;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
                                        <i class="fas fa-gas-pump" style="font-size:10px;"></i> <?php echo (int)$f['pump_count']; ?> <?php echo ((int)$f['pump_count'] === 1) ? 'Pump' : 'Pumps'; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($is_deactivated): ?>
                                <div style="font-size:11px;color:#dc2626;font-weight:700;margin-top:2px;">
                                    <i class="fas fa-ban"></i> Deactivated
                                </div>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Current Price -->
                        <td style="vertical-align:middle;text-align:right;">
                            <strong style="color:#002F6C;font-size:14.5px;font-weight:800;white-space:nowrap;">&#8369;<?php echo number_format((float)($f['price_per_liter'] ?? 0), 2); ?></strong>
                        </td>
                        
                        <!-- Current Volume -->
                        <td style="vertical-align:middle;text-align:right;font-size:13px;font-weight:700;color:#1e293b;white-space:nowrap;">
                            <?php echo number_format($level, 2); ?>
                        </td>
                        
                        <!-- Capacity -->
                        <td style="vertical-align:middle;text-align:right;font-size:13px;font-weight:600;color:#334155;white-space:nowrap;"><?php echo number_format($capacity, 2); ?></td>
                        
                        <!-- Reorder Level -->
                        <td style="vertical-align:middle;text-align:right;font-size:13px;font-weight:600;color:#64748b;white-space:nowrap;"><?php echo number_format($reorder, 2); ?></td>
                        
                        <!-- Status -->
                        <td style="vertical-align:middle;text-align:center;">
                            <span class="badge <?php echo $status_class; ?>" style="<?php echo $badge_style; ?>display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;">
                                <?php if ($is_deactivated): ?>
                                    <i class="fas fa-ban" style="font-size:9.5px;"></i> DEACTIVATED
                                <?php else: ?>
                                    <?php echo htmlspecialchars($status_label); ?>
                                <?php endif; ?>
                            </span>
                        </td>
                        
                        <!-- Price Request Status -->
                        <td style="vertical-align:middle;text-align:center;">
                            <?php if ($req_status === 'pending'): ?>
                                <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-weight:700;padding:3px 6px;font-size:11px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;">
                                    <i class="fas fa-clock" style="font-size:9px;"></i> Pending (&#8369;<?php echo number_format((float)$f['pending_price'], 2); ?>)
                                </span>
                            <?php elseif ($req_status === 'rejected'): ?>
                                <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;font-weight:700;padding:3px 6px;font-size:11px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;">
                                    <i class="fas fa-times-circle" style="font-size:9px;"></i> Rejected
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background:#dcfce7;color:#166534;border:1px solid #86efac;font-weight:700;padding:3px 6px;font-size:11px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;">
                                    <i class="fas fa-check-circle" style="font-size:9px;"></i> None / Approved
                                </span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Last Updated -->
                        <td style="vertical-align:middle;text-align:center;">
                            <?php if (!empty($f['last_updated'])): ?>
                                <div style="font-size:12px;font-weight:700;color:#1e293b;white-space:nowrap;"><?php echo date('M d, Y', strtotime($f['last_updated'])); ?></div>
                                <div style="font-size:11px;color:#64748b;white-space:nowrap;"><?php echo date('h:i A', strtotime($f['last_updated'])); ?></div>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:12px;">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Actions Column -->
                        <td style="vertical-align:middle;text-align:center;">
                            <div class="act-btn-wrap">
                                <?php if (!empty($f['id'])): ?>
                                    <!-- View Button -->
                                    <button type="button" onclick="openViewFuelModalAdmin(<?php echo $f['id']; ?>)" class="act-btn act-btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    
                                    <!-- Edit Button -->
                                    <button type="button" onclick="openEditPriceModalAdmin(<?php echo $f['id']; ?>, '<?php echo htmlspecialchars(addslashes($full_fuel_name)); ?>', <?php echo (float)($f['price_per_liter'] ?? 0); ?>, <?php echo (float)($f['capacity'] ?? 0); ?>, <?php echo (float)($f['critical_level'] ?? 0); ?>, <?php echo (float)($f['reorder_level'] ?? 0); ?>, '<?php echo htmlspecialchars(addslashes($ugt_str)); ?>', <?php echo $req_status === 'pending' ? 'true' : 'false'; ?>)" class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>

                                    <!-- Approve & Reject Buttons -->
                                    <?php if ($req_status === 'pending' && !empty($f['approval_id'])): ?>
                                        <button type="button" onclick="openApprovePriceModalAdmin(<?php echo $f['approval_id']; ?>, '<?php echo htmlspecialchars(addslashes($full_fuel_name)); ?>', <?php echo (float)($f['price_per_liter'] ?? 0); ?>, <?php echo (float)($f['pending_price'] ?? 0); ?>)" class="act-btn act-btn-approve">
                                            <i class="fas fa-check"></i> Approve
                                        </button>

                                        <button type="button" onclick="openRejectPriceModalAdmin(<?php echo $f['approval_id']; ?>, '<?php echo htmlspecialchars(addslashes($full_fuel_name)); ?>', <?php echo (float)($f['price_per_liter'] ?? 0); ?>, <?php echo (float)($f['pending_price'] ?? 0); ?>)" class="act-btn act-btn-reject">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    <?php endif; ?>

                                    <!-- Deactivate / Activate Button -->
                                    <?php if ($fuel_active_status !== 'inactive'): ?>
                                        <button type="button" onclick="openToggleFuelStatusModal(<?php echo $f['id']; ?>, 'inactive', '<?php echo htmlspecialchars(addslashes($full_fuel_name . ' (' . $ugt_str . ')')); ?>')" class="act-btn act-btn-deactivate">
                                            <i class="fas fa-ban"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="openToggleFuelStatusModal(<?php echo $f['id']; ?>, 'active', '<?php echo htmlspecialchars(addslashes($full_fuel_name . ' (' . $ugt_str . ')')); ?>')" class="act-btn act-btn-activate">
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
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 2 — MERCHANDISE PRODUCTS
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="tab-merch" class="tab-panel <?php echo $active_tab === 'merch' ? 'active' : ''; ?>">

    <!-- ── 1. Filters Toolbar (Positioned at top so select dropdowns open downwards, matching Manager) ── -->
    <div class="toolbar" style="margin-bottom: 16px;">
        <input type="text" id="adminSearchInput" placeholder="&#128269; Search Product / SKU&hellip;" oninput="filterAdminMerchTable()" style="min-width: 220px;">
        
        <select id="adminCatFilter" onchange="filterAdminMerchTable()">
            <option value="">All Categories</option>
            <?php foreach ($all_categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminBrandFilter" onchange="filterAdminMerchTable()">
            <option value="">All Brands</option>
            <?php foreach ($all_brands as $brand): ?>
                <option value="<?php echo htmlspecialchars($brand); ?>"><?php echo htmlspecialchars($brand); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminUnitFilter" onchange="filterAdminMerchTable()">
            <option value="">All UOMs</option>
            <?php foreach ($all_units as $unit): ?>
                <option value="<?php echo htmlspecialchars($unit); ?>"><?php echo htmlspecialchars($unit); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="adminProdStatusFilter" onchange="filterAdminMerchTable()">
            <option value="">All Product Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>

        <select id="adminReqStatusFilter" onchange="filterAdminMerchTable()">
            <option value="">All Request Statuses</option>
            <option value="current">Current</option>
            <option value="pending">Pending Approval</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
        </select>
    </div>

    <!-- ── 2. Summary Cards ────────────────────────────────────────────────── -->
    <div class="summary-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 18px;">
        <div class="summary-card s-total">
            <i class="fas fa-box" style="font-size:22px;color:#002F6C;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo count($merch_all); ?></div>
            <div class="s-lbl">Total Products</div>
        </div>
        <div class="summary-card s-valid">
            <i class="fas fa-tags" style="font-size:22px;color:#16a34a;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo $merch_stats['valid_price']; ?></div>
            <div class="s-lbl">Current Active Prices</div>
        </div>
        <div class="summary-card s-unpriced">
            <i class="fas fa-hourglass-half" style="font-size:22px;color:#d97706;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo $pending_requests_count; ?></div>
            <div class="s-lbl">Pending Price Requests</div>
        </div>
        <div class="summary-card s-total">
            <i class="fas fa-check-double" style="font-size:22px;color:#16a34a;margin-bottom:4px;display:block;"></i>
            <div class="s-num"><?php echo $approved_today_count; ?></div>
            <div class="s-lbl">Approved Today</div>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
        <button type="button" onclick="openAddMerchandiseModal()" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:15.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:all 0.2s;">
            <i class="fas fa-plus-circle"></i> Add Merchandise
        </button>
    </div>

    <!-- ── 3. Merchandise Table ────────────────────────────────────────────── -->
    <?php if (empty($merch_by_cat)): ?>
        <div class="card" style="padding:28px;text-align:center;color:#94a3b8;">
            <i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>
            No merchandise products found.
        </div>
    <?php else: ?>
    <div class="card" style="padding:0;overflow:hidden;border:1px solid #e2e8f0;border-radius:10px;">
        <div class="table-wrap" style="width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;">
            <table class="pricing-table" id="adminMerchTable" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
                <colgroup>
                    <col style="width:11%;">   <!-- SKU -->
                    <col style="width:23%;">   <!-- Product (expanded) -->
                    <col style="width:10%;">   <!-- Category / Brand -->
                    <col style="width:6%;">    <!-- UOM -->
                    <col style="width:7.5%;">  <!-- Selling Price -->
                    <col style="width:5%;">    <!-- Stock -->
                    <col style="width:9.5%;">  <!-- Request Status -->
                    <col style="width:7%;">    <!-- Status -->
                    <col style="width:7%;">    <!-- Updated -->
                    <col style="width:14%;">   <!-- Actions -->
                </colgroup>
                <thead style="background:#002F6C !important;">
                    <tr style="background:#002F6C !important;">
                        <th style="width:13%;text-align:left;">SKU</th>
                        <th style="width:17%;text-align:left;">Product Name</th>
                        <th style="width:11%;text-align:left;">Category / Brand</th>
                        <th style="width:6.5%;text-align:center;">UOM</th>
                        <th style="width:7.5%;text-align:right;">Selling Price</th>
                        <th style="width:5%;text-align:center;">Stock</th>
                        <th style="width:9.5%;text-align:center;">Price Req.</th>
                        <th style="width:7%;text-align:center;">Status</th>
                        <th style="width:8%;text-align:center;">Updated</th>
                        <th style="width:15.5%;text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="adminMerchBody">
                <?php foreach ($merch_by_cat as $cat_label => $items): ?>
                    <tr class="cat-row" data-cat-header="<?php echo htmlspecialchars($cat_label); ?>">
                        <td colspan="10" style="background:#f1f5f9;font-weight:800;font-size:14px;padding:10px 16px;">
                            <i class="fas fa-folder"></i>
                            <?php echo htmlspecialchars($cat_label); ?>
                            <span class="muted cat-count" style="font-weight:600;margin-left:8px;font-size:13px;">(<?php echo count($items); ?> items)</span>
                        </td>
                    </tr>
                    <?php foreach ($items as $item):
                        $price         = $item['_price'];
                        $stock         = $item['_stock'];
                        $updated       = !empty($item['last_updated']) ? date('M d, Y', strtotime($item['last_updated'])) : '&mdash;';
                        $app_status    = strtolower(trim($item['approval_status'] ?? 'current'));
                        if (empty($app_status)) $app_status = 'current';

                        $prod_status   = strtolower(trim($item['status'] ?? 'active'));
                        $is_inactive   = in_array($prod_status, ['inactive', 'disabled', 'deactivated']);
                    ?>
                    <tr class="admin-merch-row"
                        data-id="<?php echo (int)($item['id'] ?? 0); ?>"
                        data-name="<?php echo strtolower(htmlspecialchars($item['product_name'] ?? '')); ?>"
                        data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                        data-brand="<?php echo strtolower(htmlspecialchars($item['brand'] ?? 'Generic')); ?>"
                        data-unit="<?php echo strtolower(htmlspecialchars($item['unit'] ?? 'pcs')); ?>"
                        data-cat="<?php echo htmlspecialchars($cat_label); ?>"
                        data-prodstatus="<?php echo $is_inactive ? 'inactive' : 'active'; ?>"
                        data-reqstatus="<?php echo $app_status; ?>"
                        <?php if ($is_inactive): ?>style="background:#f8fafc;"<?php endif; ?>>
                        
                        <!-- SKU -->
                        <td style="vertical-align:middle;padding:8px 6px;white-space:nowrap;">
                            <code style="font-size:12px;color:#4338ca;background:#ede9fe;padding:3px 6px;border-radius:5px;font-weight:700;font-family:monospace;white-space:nowrap;display:inline-block;">
                                <?php echo htmlspecialchars($item['sku'] ?? '—'); ?>
                            </code>
                        </td>

                        <!-- Product -->
                        <td style="vertical-align:middle;padding:8px 8px;">
                            <strong style="color:#0f172a;font-size:13.5px;line-height:1.35;display:block;white-space:normal !important;word-break:normal !important;overflow-wrap:break-word !important;"><?php echo htmlspecialchars($item['product_name'] ?? ''); ?></strong>
                        </td>

                        <!-- Category / Brand -->
                        <td style="vertical-align:middle;padding:8px 6px;">
                            <div style="font-weight:700;color:#1e293b;font-size:13px;line-height:1.3;white-space:normal !important;word-break:normal !important;overflow-wrap:break-word !important;"><?php echo htmlspecialchars($cat_label); ?></div>
                            <div class="muted" style="font-size:11.5px;color:#64748b;margin-top:2px;white-space:normal !important;word-break:normal !important;overflow-wrap:break-word !important;"><?php echo htmlspecialchars($item['brand'] ?? 'Generic'); ?></div>
                        </td>

                        <!-- UOM -->
                        <td style="vertical-align:middle;text-align:center;font-size:12.5px;color:#334155;font-weight:600;white-space:nowrap;padding:8px 4px;"><?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></td>

                        <!-- Default Selling Price -->
                        <td style="vertical-align:middle;text-align:right;padding:8px 6px;">
                            <?php if ($price <= 0): ?>
                                <span class="badge badge-noprice" style="font-size:11px;padding:3px 6px;white-space:nowrap;">No Price</span>
                            <?php else: ?>
                                <strong style="color:#002F6C;font-size:14.5px;font-weight:800;white-space:nowrap;">&#8369;<?php echo number_format($price, 2); ?></strong>
                            <?php endif; ?>
                        </td>

                        <!-- Total Stock -->
                        <td style="vertical-align:middle;text-align:center;padding:8px 4px;">
                            <strong style="font-size:13px;color:#0f172a;white-space:nowrap;"><?php echo number_format($stock, 0); ?></strong>
                        </td>

                        <!-- Request Status -->
                        <td style="vertical-align:middle;text-align:center;padding:8px 4px;">
                            <?php if ($app_status === 'pending'): ?>
                                <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-weight:700;padding:3px 6px;font-size:11px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;"><i class="fas fa-clock" style="font-size:9px;"></i> Pending</span>
                            <?php elseif ($app_status === 'approved'): ?>
                                <span class="badge" style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;font-weight:700;padding:3px 6px;font-size:11px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;"><i class="fas fa-check-circle" style="font-size:9px;"></i> Approved</span>
                            <?php elseif ($app_status === 'rejected'): ?>
                                <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;font-weight:700;padding:3px 6px;font-size:11px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;"><i class="fas fa-times-circle" style="font-size:9px;"></i> Rejected</span>
                            <?php else: ?>
                                <span class="badge" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;font-weight:600;padding:3px 6px;font-size:11px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;"><i class="fas fa-check" style="font-size:9px;color:#16a34a;"></i> Current</span>
                            <?php endif; ?>
                        </td>

                        <!-- Product Status -->
                        <td style="vertical-align:middle;text-align:center;padding:8px 4px;">
                            <?php if ($is_inactive): ?>
                                <span class="badge badge-out" style="padding:3px 6px;font-size:11px;font-weight:700;white-space:nowrap;">Inactive</span>
                            <?php else: ?>
                                <span class="badge badge-available" style="padding:3px 6px;font-size:11px;font-weight:700;white-space:nowrap;">Active</span>
                            <?php endif; ?>
                        </td>

                        <!-- Updated -->
                        <td style="vertical-align:middle;text-align:center;padding:8px 4px;">
                            <?php if (!empty($item['last_updated'])): ?>
                                <div style="font-size:12px;font-weight:700;color:#1e293b;white-space:nowrap;"><?php echo date('M d, Y', strtotime($item['last_updated'])); ?></div>
                                <div style="font-size:11px;color:#64748b;white-space:nowrap;"><?php echo date('h:i A', strtotime($item['last_updated'])); ?></div>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:12px;">&mdash;</span>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td style="vertical-align:middle;text-align:center;padding:8px 4px;">
                            <div class="act-btn-wrap">
                                <?php if ($app_status === 'pending'): ?>
                                    <button type="button" onclick="viewAdminMerchandiseDetails(<?php echo $item['id']; ?>)" class="act-btn act-btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button type="button" onclick="openViewRequestModal(<?php echo $item['approval_id']; ?>)" class="act-btn act-btn-viewreq">
                                        <i class="fas fa-eye"></i> View Req
                                    </button>
                                    <button type="button" onclick="openApproveConfirmModal(<?php echo $item['approval_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>', '<?php echo number_format($price, 2); ?>', '<?php echo number_format($item['pending_price'], 2); ?>')" class="act-btn act-btn-approve">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button type="button" onclick="openRejectReasonModal(<?php echo $item['approval_id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'])); ?>')" class="act-btn act-btn-reject">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                <?php else: ?>
                                    <button type="button" onclick="viewAdminMerchandiseDetails(<?php echo $item['id']; ?>)" class="act-btn act-btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button type="button" onclick="openAdminEditProductModal(<?php echo (int)$item['id']; ?>)" class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if (!$is_inactive): ?>
                                        <button type="button" onclick="deactivateMerchandise(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'] ?? '')); ?>')" class="act-btn act-btn-deactivate" style="color:#dc2626 !important;border-color:#fca5a5 !important;background:#fef2f2 !important;">
                                            <i class="fas fa-ban"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="activateMerchandise(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['product_name'] ?? '')); ?>')" class="act-btn act-btn-activate" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:1.5px solid #15803d !important;background:#16a34a !important;font-weight:700 !important;box-shadow:0 1px 3px rgba(22,163,74,0.3) !important;">
                                            <i class="fas fa-check-circle" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Activate
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="adminMerchNoResults" style="display:none;padding:30px;text-align:center;color:#94a3b8;">
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
    
    <div class="card" style="padding:0;overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
            <strong style="font-size:15px;color:#002F6C;"><i class="fas fa-wrench"></i> Service Types</strong>
            <button type="button" onclick="openAddServiceModal()" style="background:linear-gradient(135deg,#002F6C 0%,#004494 100%);color:#fff;border:none;padding:9px 18px;border-radius:6px;font-size:15.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 4px rgba(0,47,108,0.2);transition:all 0.2s;">
                <i class="fas fa-plus-circle"></i> Add Service
            </button>
        </div>
        
        <?php if (empty($service_types)): ?>
            <div style="padding:28px;text-align:center;color:#94a3b8;">
                <i class="fas fa-wrench" style="font-size:32px;margin-bottom:10px;display:block;"></i>
                No service types found.
            </div>
        <?php else: ?>
            <div class="table-wrap" style="width:100% !important;overflow-x:hidden !important;box-sizing:border-box !important;">
                <table class="pricing-table" style="width:100% !important;table-layout:fixed !important;border-collapse:collapse !important;">
                    <colgroup>
                        <col style="width:7%;">    <!-- Code -->
                        <col style="width:19%;">   <!-- Service Name -->
                        <col style="width:12%;">   <!-- Category -->
                        <col style="width:9%;">    <!-- Service Fee -->
                        <col style="width:9%;">    <!-- Labor Fee -->
                        <col style="width:11%;">   <!-- Price Req. -->
                        <col style="width:8%;">    <!-- Status -->
                        <col style="width:10%;">   <!-- Last Updated -->
                        <col style="width:15%;">   <!-- Action -->
                    </colgroup>
                    <thead>
                        <tr style="background:#002F6C !important;">
                            <th style="color:#fff;width:7%;text-align:left;">Code</th>
                            <th style="color:#fff;width:19%;text-align:left;">Service Name</th>
                            <th style="color:#fff;width:12%;text-align:left;">Category</th>
                            <th style="color:#fff;width:9%;text-align:right;">Service Fee</th>
                            <th style="color:#fff;width:9%;text-align:right;">Labor Fee</th>
                            <th style="color:#fff;width:11%;text-align:center;">Price Req.</th>
                            <th style="color:#fff;width:8%;text-align:center;">Status</th>
                            <th style="color:#fff;width:10%;text-align:center;">Last Updated</th>
                            <th style="color:#fff;width:15%;text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($service_types as $svc):
                            $svcId        = (int)$svc['id'];
                            $svcCode      = htmlspecialchars($svc['service_code'] ?? ('SRV-' . str_pad($svcId, 4, '0', STR_PAD_LEFT)));
                            $svcName      = htmlspecialchars($svc['service_name']);
                            $svcKey       = htmlspecialchars($svc['service_key'] ?? '');
                            $svcCat       = htmlspecialchars($svc['category'] ?? 'Others');
                            $svcDesc      = htmlspecialchars($svc['description'] ?? '');
                            $currentSvcFee  = (float)($svc['service_price'] ?? 0);
                            $currentLabFee  = (float)($svc['labor_fee'] ?? 0);
                            $oldSvcFee      = (float)($svc['old_service_fee'] ?? 0);
                            $oldLabFee      = (float)($svc['old_labor_fee'] ?? 0);
                            $duration     = (!empty($svc['estimated_duration']) && (int)$svc['estimated_duration'] > 0) ? (int)$svc['estimated_duration'] : null;
                            $mechanics    = (int)($svc['required_mechanics'] ?? 1);
                            $isActive     = (int)($svc['active'] ?? 1) === 1;
                            $hasPending   = ($svc['approval_status'] ?? '') === 'pending';
                            $pendSvcFee   = $hasPending ? (float)($svc['pending_price'] ?? 0) : 0;
                            $pendLabFee   = $hasPending ? (float)($svc['pending_labor_fee'] ?? 0) : 0;
                            $updatedAt    = !empty($svc['updated_at']) ? date('M j, Y', strtotime($svc['updated_at'])) : '—';
                            $hrs          = $duration !== null ? floor($duration / 60) : 0;
                            $mins         = $duration !== null ? ($duration % 60) : 0;
                            $durationStr  = ($duration !== null && $duration > 0) ? (($hrs > 0 ? $hrs . 'h' : '') . ($mins > 0 ? ($hrs > 0 ? ' ' : '') . $mins . 'm' : ($hrs === 0 ? '0m' : ''))) : '—';
                            $managerName  = htmlspecialchars($svc['manager_name'] ?? '');
                            $approvalId   = (int)($svc['approval_id'] ?? 0);
                            $jsObj = json_encode([
                                'id'                 => $svcId,
                                'service_code'       => $svc['service_code'] ?? '',
                                'service_name'       => $svc['service_name'],
                                'service_key'        => $svc['service_key'] ?? '',
                                'category'           => $svc['category'] ?? '',
                                'service_price'      => $currentSvcFee,
                                'labor_fee'          => $currentLabFee,
                                'estimated_duration' => $duration,
                                'duration_str'       => $durationStr,
                                'required_mechanics' => $mechanics,
                                'description'        => $svc['description'] ?? '',
                                'active'             => $isActive ? 1 : 0,
                                'updated_at'         => $updatedAt,
                            ], JSON_HEX_APOS | JSON_HEX_QUOT);
                        ?>
                        <tr>
                            <!-- Code -->
                            <td style="vertical-align:middle;">
                                <span style="font-family:monospace;font-size:13px;color:#0369a1;font-weight:700;background:#e0f2fe;padding:4px 7px;border-radius:4px;white-space:nowrap;border:1px solid #bae6fd;display:inline-block;"><?php echo $svcCode; ?></span>
                            </td>

                            <!-- Service Name -->
                            <td style="vertical-align:middle;">
                                <div style="font-weight:700;color:#0f172a;font-size:14.5px;line-height:1.35;word-break:break-word;overflow-wrap:break-word;"><?php echo $svcName; ?></div>
                                <?php if ($svcDesc): ?>
                                <div style="font-size:12.5px;color:#64748b;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo $svcDesc; ?>"><?php echo $svcDesc; ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- Category -->
                            <td style="vertical-align:middle;">
                                <span style="background:#f0f7ff;color:#003d7a;border:1px solid #dbeafe;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:700;display:inline-block;white-space:nowrap;"><?php echo $svcCat; ?></span>
                            </td>

                            <!-- Service Fee -->
                            <td style="vertical-align:middle;text-align:right;">
                                <div style="font-weight:800;color:#002F6C;font-size:15px;white-space:nowrap;">&#8369;<?php echo number_format($currentSvcFee, 2); ?></div>
                                <?php if ($hasPending && $pendSvcFee > 0): ?>
                                <div style="font-size:11px;color:#d97706;background:#fef3c7;border:1px solid #fde68a;padding:2px 6px;border-radius:4px;margin-top:2px;font-weight:700;display:inline-block;white-space:nowrap;">
                                    <i class="fas fa-hourglass-half" style="font-size:9px;"></i> &#8369;<?php echo number_format($pendSvcFee, 2); ?>
                                </div>
                                <?php endif; ?>
                            </td>

                            <!-- Labor Fee -->
                            <td style="vertical-align:middle;text-align:right;">
                                <div style="font-weight:800;color:#0369a1;font-size:15px;white-space:nowrap;">&#8369;<?php echo number_format($currentLabFee, 2); ?></div>
                                <?php if ($hasPending && $pendLabFee > 0): ?>
                                <div style="font-size:11px;color:#d97706;background:#fef3c7;border:1px solid #fde68a;padding:2px 6px;border-radius:4px;margin-top:2px;font-weight:700;display:inline-block;white-space:nowrap;">
                                    <i class="fas fa-hourglass-half" style="font-size:9px;"></i> &#8369;<?php echo number_format($pendLabFee, 2); ?>
                                </div>
                                <?php endif; ?>
                            </td>

                            <!-- Price Request Status -->
                            <td style="vertical-align:middle;text-align:center;padding:8px 4px;">
                                <?php if ($hasPending): ?>
                                    <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-weight:700;padding:3px 6px;font-size:11px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;" title="Manager requested: Svc Fee ₱<?php echo number_format($pendSvcFee, 2); ?><?php echo $pendLabFee > 0 ? ' | Labor ₱'.number_format($pendLabFee, 2) : ''; ?>">
                                        <i class="fas fa-clock" style="font-size:9px;"></i> Pending (&#8369;<?php echo number_format($pendSvcFee, 2); ?>)
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:#dcfce7;color:#166534;border:1px solid #86efac;font-weight:700;padding:3px 6px;font-size:11px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;">
                                        <i class="fas fa-check-circle" style="font-size:9px;"></i> None / Approved
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Status -->
                            <td style="vertical-align:middle;text-align:center;">
                                <?php if ($isActive): ?>
                                <span class="badge badge-available" style="padding:5px 10px;font-size:12px;font-weight:700;white-space:nowrap;">Active</span>
                                <?php else: ?>
                                <span class="badge badge-out" style="padding:5px 10px;font-size:12px;font-weight:700;white-space:nowrap;">Inactive</span>
                                <?php endif; ?>
                            </td>

                            <!-- Last Updated -->
                            <td style="vertical-align:middle;text-align:center;font-size:13px;font-weight:600;color:#1e293b;white-space:nowrap;"><?php echo $updatedAt; ?></td>

                            <!-- Action (Admin Functions) -->
                            <td style="text-align:center;vertical-align:middle;">
                                <div class="act-btn-wrap">
                                    <button type="button"
                                        onclick="openAdminViewServiceModal(<?php echo htmlspecialchars($jsObj, ENT_QUOTES, 'UTF-8'); ?>)"
                                        class="act-btn act-btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php if ($hasPending): ?>
                                    <button type="button"
                                        onclick="openApprovePriceModalAdmin(<?php echo $approvalId; ?>, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>', <?php echo $currentSvcFee; ?>, <?php echo $pendSvcFee; ?>, 'services')"
                                        class="act-btn act-btn-approve">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button type="button"
                                        onclick="openRejectPriceModalAdmin(<?php echo $approvalId; ?>, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>', <?php echo $currentSvcFee; ?>, <?php echo $pendSvcFee; ?>, 'services')"
                                        class="act-btn act-btn-reject">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                    <?php endif; ?>
                                    <?php if (!$hasPending && $oldSvcFee > 0 && ($oldSvcFee !== $currentSvcFee || $oldLabFee !== $currentLabFee)): ?>
                                    <button type="button"
                                        onclick="restoreServiceFees(<?php echo $svcId; ?>, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>', <?php echo $oldSvcFee; ?>, <?php echo $oldLabFee; ?>)"
                                        class="act-btn act-btn-restore">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                    <?php endif; ?>
                                    <button onclick="openAdminEditServiceModal(<?php echo $svcId; ?>, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>', '<?php echo htmlspecialchars(addslashes($svc['category']??'')); ?>', '<?php echo htmlspecialchars(addslashes($svc['service_key']??'')); ?>', <?php echo $currentSvcFee; ?>, <?php echo (int)($svc['active']??1); ?>)"
                                        class="act-btn act-btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if ($isActive): ?>
                                    <button type="button"
                                        onclick="adminToggleService(<?php echo $svcId; ?>, 0, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>')"
                                        class="act-btn act-btn-deactivate">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                    <?php else: ?>
                                    <button type="button"
                                        onclick="adminToggleService(<?php echo $svcId; ?>, 1, '<?php echo htmlspecialchars(addslashes($svc['service_name'])); ?>')"
                                        class="act-btn act-btn-activate">
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
        <?php endif; ?>
    </div>
</div>

<!-- Rejection Modal -->
<style>
/* Modal styles matching transaction module design */
.modal { display:none; position:fixed; z-index:1050; inset:0; background:rgba(0,0,0,.5); align-items:center; justify-content:center; }
.modal.open { display:flex; }
.modal-content { background:#fff; border-radius:12px; width:92%; max-width:640px; box-shadow:0 8px 32px rgba(0,0,0,.18); overflow:hidden; max-height:90vh; display:flex; flex-direction:column; }
.modal-header { display:flex; justify-content:space-between; align-items:center; padding:18px 24px; background:#fff; border-bottom:1px solid #e9ecef; flex-shrink:0; }
.modal-header h3 { margin:0; font-size:1.05rem; font-weight:600; color:#1e293b; display:flex; align-items:center; gap:8px; }
.modal-body { padding:24px; flex:1; overflow-y:auto; }
.modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; border-top:1px solid #e9ecef; background:#fff; flex-shrink:0; }
.modal-footer .btn { padding:10px 20px; border-radius:6px; font-size:15.5px; font-weight:600; cursor:pointer; transition:all .15s; border:none; }
.modal-footer .btn-cancel { background:#fff; color:#475569; border:1px solid #cbd5e1; }
.modal-footer .btn-cancel:hover { background:#f8fafc; border-color:#94a3b8; }
.modal-footer .btn-reject { background:#dc2626; color:#fff; }
.modal-footer .btn-reject:hover { background:#b91c1c; }
</style>
<div class="modal" id="rejectModal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Reject Price Proposal</h3>
    </div>
    <form method="post" id="rejectForm">
      <div class="modal-body">
          <input type="hidden" name="action" value="reject_price">
          <input type="hidden" name="approval_id" id="rejectApprovalId" value="">
          <input type="hidden" name="active_tab" id="rejectActiveTab" value="fuel">
          <label style="display:block; margin-bottom:8px; font-weight:600; font-size:15.5px; color:#1e293b;">
            Reason for Rejection <span style="color:#dc2626;">*</span>
          </label>
          <textarea name="remarks" style="width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:8px; font-size:15.5px; font-family:inherit; resize:vertical; min-height:100px; transition:border-color .15s;" placeholder="Provide detailed remarks for the manager regarding the price rejection..." required onfocus="this.style.borderColor='#002F70';this.style.boxShadow='0 0 0 3px rgba(0,47,112,.1)'" onblur="this.style.borderColor='#cbd5e1';this.style.boxShadow='none'"></textarea>
          <p style="margin-top:8px; font-size:14.5px; color:#64748b;">
            <i class="fas fa-info-circle"></i> This feedback will be sent to the manager who submitted the price change request.
          </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-cancel" onclick="closeRejectModal()">Cancel</button>
        <button type="submit" class="btn btn-reject">Reject Proposal</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     ADMIN MODALS & JAVASCRIPT LOGIC
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- 1. ADMIN EDIT PRODUCT MODAL (Price read-only) -->
<div id="adminEditProductModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:550px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
        <div style="background:#002F6C; color:#ffffff !important; padding:16px 20px; display:flex; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:700; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:flex; align-items:center; gap:8px;"><i class="fas fa-edit" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important;"></i> Edit Product Details</h4>
        </div>
        <form id="adminEditProductForm" style="padding:20px;">
            <input type="hidden" id="adminEditId">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div style="grid-column: span 2;">
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Product Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="adminEditName" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\/\.\,\&]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Category <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="adminEditCategory" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\&\.\,]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Brand</label>
                    <input type="text" id="adminEditBrand" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\&\.\,]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">SKU / Product Code</label>
                    <input type="text" id="adminEditSku" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-\_\.]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Unit of Measure (UOM)</label>
                    <input type="text" id="adminEditUnit" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\(\)]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Reorder Level <span style="color:#dc2626;">*</span></label>
                    <input type="number" id="adminEditReorder" min="1" value="24" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Critical Level <span style="color:#dc2626;">*</span></label>
                    <input type="number" id="adminEditCritical" min="1" value="10" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Product Status <span style="color:#dc2626;">*</span></label>
                    <select id="adminEditStatus" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Default Selling Price (&#8369;) <span style="color:#dc2626;">*</span></label>
                    <input type="number" step="0.01" min="0" id="adminEditPrice" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; font-weight:700; color:#002F6C;" placeholder="0.00"              oninput="sanitizeDecimalInput(this)">
                </div>
                <div style="grid-column: span 2;">
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Expiration Date <span style="color:#94a3b8; font-weight:400;">(Optional)</span></label>
                    <input type="date" id="adminEditExpiry" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15px; box-sizing:border-box;">
                    <small style="color:#64748b; font-size:13px;">Leave blank for non-perishable products (tools, accessories, etc.)</small>
                </div>
            </div>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeAdminEditProductModal()" style="padding:8px 16px !important; border:1px solid #cbd5e1 !important; background:#f1f5f9 !important; color:#0f172a !important; border-radius:6px !important; cursor:pointer !important; font-weight:600 !important; font-size:13px !important;">Cancel</button>
                <button type="submit" style="padding:8px 18px; border:none; background:#002F6C; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 1.1 ADMIN EDIT FUEL MODAL -->
<div id="adminEditFuelModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#ffffff !important; padding:16px 20px; display:flex; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:700; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:flex; align-items:center; gap:8px;"><i class="fas fa-gas-pump" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important;"></i> Edit Fuel Product (Admin)</h4>
        </div>
        <form id="adminEditFuelForm" style="padding:20px;">
            <input type="hidden" id="adminEditFuelId">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:14.5px; font-weight:600; color:#64748b; margin-bottom:4px;">Fuel Type</label>
                <div id="adminEditFuelTypeDisplay" style="width:100%; padding:8px 12px; border:1px solid #e2e8f0; background:#f8fafc; color:#1e293b; font-weight:700; border-radius:6px; font-size:15.5px;"></div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Price / Liter (₱)</label>
                    <input type="number" step="0.01" min="0" id="adminEditFuelPrice" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; font-weight:700; color:#002F6C;" placeholder="0.00">
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Capacity (L)</label>
                    <input type="number" step="0.01" min="0" id="adminEditFuelCapacity" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" placeholder="0.00">
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Critical Level (L)</label>
                <input type="number" step="0.01" min="0" id="adminEditFuelCritical" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" placeholder="0.00">
            </div>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeAdminEditFuelModal()" style="padding:8px 16px !important; border:1px solid #cbd5e1 !important; background:#f1f5f9 !important; color:#0f172a !important; border-radius:6px !important; cursor:pointer !important; font-weight:600 !important; font-size:13px !important;">Cancel</button>
                <button type="submit" style="padding:8px 18px; border:none; background:#002F6C; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 1.2 ADMIN EDIT SERVICE MODAL -->
<div id="adminEditServiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#ffffff !important; padding:16px 20px; display:flex; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:700; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:flex; align-items:center; gap:8px;"><i class="fas fa-wrench" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important;"></i> Edit Service Type (Admin)</h4>
        </div>
        <form id="adminEditServiceForm" style="padding:20px;">
            <input type="hidden" id="adminEditServiceId">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Service Name <span style="color:#dc2626;">*</span></label>
                <input type="text" id="adminEditServiceName" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\/\.\,\&]/g, '');">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Category <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="adminEditServiceCategory" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\&\.\,]/g, '');">
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Service Key <span style="color:#dc2626;">*</span></label>
                    <input type="text" id="adminEditServiceKey" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\_\-]/g, '');">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Service Price (₱) <span style="color:#dc2626;">*</span></label>
                    <input type="number" step="0.01" min="0" id="adminEditServicePrice" required style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; font-weight:700; color:#002F6C;" placeholder="0.00"              oninput="sanitizeDecimalInput(this)">
                </div>
                <div>
                    <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:4px;">Status <span style="color:#dc2626;">*</span></label>
                    <select id="adminEditServiceActive" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px;">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeAdminEditServiceModal()" style="padding:8px 16px; border:1px solid #cbd5e1; background:#f1f5f9 !important; color:#0f172a !important; border-radius:6px; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="submit" style="padding:8px 18px; border:none; background:#002F6C; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW ADMIN SERVICE DETAILS MODAL -->
<div id="viewAdminServiceModal" class="admin-layout-modal" style="display:none;position:fixed;top:70px;bottom:40px;left:250px;right:0;background:rgba(15,23,42,0.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;overflow:hidden;">
    <div style="background:#fff;border-radius:12px;width:94%;max-width:900px;box-shadow:0 20px 50px rgba(0,0,0,0.35);margin:0 auto;overflow:hidden;max-height:calc(100% - 20px);display:flex;flex-direction:column;">
        <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:14px 22px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-wrench" style="color:#fff;font-size:16px;"></i>
                <span id="adm_vs_title" style="color:#fff;">SERVICE SPECIFICATION &amp; DETAILS</span>
            </h3>
        </div>
        <div style="padding:22px 24px;overflow-y:auto;overflow-x:hidden;flex:1 1 auto;background:#fff;box-sizing:border-box;">
            <!-- Overview Card -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:18px;">
                <h4 style="margin:0 0 14px 0;font-size:13px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;text-transform:uppercase;letter-spacing:0.3px;">
                    <i class="fas fa-info-circle"></i> Service Information
                </h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(190px, 1fr));gap:14px;font-size:12px;">
                    <div><span style="color:#64748b;font-weight:600;">Service Code:</span><br><code id="adm_vs_code" style="font-weight:800;color:#0369a1;background:#e0f2fe;padding:2px 6px;border-radius:4px;display:inline-block;margin-top:2px;">-</code></div>
                    <div><span style="color:#64748b;font-weight:600;">Category:</span><br><span id="adm_vs_category" style="background:#f0f7ff;color:#003d7a;border:1px solid #dbeafe;padding:2px 8px;border-radius:999px;font-weight:700;display:inline-block;margin-top:2px;">-</span></div>
                    <div style="grid-column: 1 / -1;"><span style="color:#64748b;font-weight:600;">Service Name:</span><br><strong id="adm_vs_name" style="color:#0f172a;font-size:14px;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Service Fee:</span><br><strong id="adm_vs_fee" style="color:#002F6C;font-size:14px;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Labor Fee:</span><br><strong id="adm_vs_labor" style="color:#0369a1;font-size:14px;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Total Service Rate:</span><br><strong id="adm_vs_total" style="color:#15803d;font-size:14px;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Required Mechanics:</span><br><span id="adm_vs_mechanics" style="color:#334155;font-weight:600;">-</span></div>
                    <div><span style="color:#64748b;font-weight:600;">Status:</span><br><span id="adm_vs_status" style="margin-top:2px;display:inline-block;">-</span></div>
                    <div><span style="color:#64748b;font-weight:600;">Last Updated:</span><br><span id="adm_vs_updated" style="color:#475569;font-weight:600;">-</span></div>
                    <div style="grid-column: 1 / -1;"><span style="color:#64748b;font-weight:600;">Description / Scope of Work:</span><br><div id="adm_vs_desc" style="color:#334155;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;margin-top:4px;line-height:1.4;">-</div></div>
                </div>
            </div>
        </div>
        <div style="background:#ffffff;padding:14px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:12px;flex-shrink:0;">
            <button type="button" id="adm_vs_close_btn" onclick="closeAdminViewServiceModal()" style="background:transparent !important;background-color:transparent !important;background-image:none !important;color:#475569 !important;-webkit-text-fill-color:#475569 !important;border:1.5px solid #cbd5e1 !important;padding:9px 24px;border-radius:6px;font-size:15px;font-weight:700 !important;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:none !important;transition:all 0.15s ease;" onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a'" onmouseout="this.style.background='transparent';this.style.color='#475569'">
                <i class="fas fa-times" style="color:inherit !important;-webkit-text-fill-color:inherit !important;"></i> Close
            </button>
            <button type="button" id="adm_vs_edit_btn" style="background:transparent !important;background-color:transparent !important;background-image:none !important;color:#002F6C !important;-webkit-text-fill-color:#002F6C !important;border:1.5px solid #002F6C !important;padding:9px 22px;border-radius:6px;font-size:15px;font-weight:700 !important;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:none !important;transition:all 0.15s ease;" onmouseover="this.style.background='#f0f7ff';this.style.color='#001f47';this.style.borderColor='#001f47'" onmouseout="this.style.background='transparent';this.style.color='#002F6C';this.style.borderColor='#002F6C'">
                <i class="fas fa-edit" style="color:inherit !important;-webkit-text-fill-color:inherit !important;"></i> Edit Service
            </button>
        </div>
    </div>
</div>

<!-- 2. VIEW REQUEST MODAL -->
<div id="viewRequestModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#ffffff !important; padding:16px 20px; display:flex; align-items:center;">
            <h4 style="margin:0; font-size:16px; font-weight:700; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:flex; align-items:center; gap:8px;"><i class="fas fa-file-invoice-dollar" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important;"></i> PRICE CHANGE REQUEST</h4>
        </div>
        <div id="viewRequestContent" style="padding:20px;">
            <!-- Loaded dynamically via JS -->
        </div>
    </div>
</div>

<!-- 3. APPROVE CONFIRMATION MODAL -->
<div id="approveConfirmModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:440px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#16a34a; color:#ffffff !important; padding:16px 20px;">
            <h4 style="margin:0; font-size:16px; font-weight:700; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:flex; align-items:center; gap:8px;"><i class="fas fa-check-circle" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important;"></i> Confirm Approval</h4>
        </div>
        <div style="padding:20px;">
            <p style="font-size:14px; color:#1e293b; margin-top:0;">Approve this price change?</p>
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; padding:14px; border-radius:8px; margin-bottom:20px;">
                <div id="appModalProdName" style="font-weight:700; color:#166534; font-size:14px; margin-bottom:6px;"></div>
                <div style="font-size:15.5px; color:#334155; display:flex; justify-content:space-between;">
                    <span>Old Price: <strong id="appModalOldPrice" style="color:#64748b;"></strong></span>
                    <span>→</span>
                    <span>New Price: <strong id="appModalNewPrice" style="color:#16a34a;"></strong></span>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <input type="hidden" id="confirmApproveId">
                <button type="button" onclick="closeApproveConfirmModal()" style="padding:8px 16px !important; border:1px solid #cbd5e1 !important; background:#f1f5f9 !important; color:#0f172a !important; border-radius:6px !important; cursor:pointer !important; font-weight:600 !important; font-size:13px !important;">Cancel</button>
                <button type="button" onclick="confirmApprovePriceRequest()" style="padding:8px 18px; border:none; background:#16a34a; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-check"></i> Approve Request</button>
            </div>
        </div>
    </div>
</div>

<!-- 4. REJECT REASON MODAL -->
<div id="rejectReasonModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:440px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#dc2626; color:#ffffff !important; padding:16px 20px;">
            <h4 style="margin:0; font-size:16px; font-weight:700; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:flex; align-items:center; gap:8px;"><i class="fas fa-times-circle" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important;"></i> Reject Price Change</h4>
        </div>
        <div style="padding:20px;">
            <input type="hidden" id="rejectReasonApprovalId">
            <p id="rejectModalProdName" style="font-size:14px; font-weight:600; color:#1e293b; margin-top:0;"></p>
            <label style="display:block; font-size:14.5px; font-weight:600; color:#334155; margin-bottom:6px;">Rejection Reason</label>
            <textarea id="rejectReasonText" rows="3" required placeholder="Enter reason for rejecting this price request&hellip;" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:15.5px; box-sizing:border-box;"></textarea>
            <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeRejectReasonModal()" style="padding:8px 16px !important; border:1px solid #cbd5e1 !important; background:#f1f5f9 !important; color:#0f172a !important; border-radius:6px !important; cursor:pointer !important; font-weight:600 !important; font-size:13px !important;">Cancel</button>
                <button type="button" onclick="confirmRejectPriceRequest()" style="padding:8px 18px; border:none; background:#dc2626; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-times"></i> Reject Request</button>
            </div>
        </div>
    </div>
</div>

<!-- 5. VIEW PRICE HISTORY MODAL -->
<div id="priceHistoryModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:650px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#ffffff !important; padding:16px 20px; display:flex; align-items:center;">
            <h4 id="priceHistoryTitle" style="margin:0; font-size:16px; font-weight:700; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:flex; align-items:center; gap:8px;"><i class="fas fa-history" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important;"></i> Price History</h4>
        </div>
        <div id="priceHistoryContent" style="padding:20px; max-height:450px; overflow-y:auto;">
            <!-- Loaded dynamically via JS -->
        </div>
        <div style="background:#ffffff;border-top:1px solid #e2e8f0;padding:12px 20px;display:flex;justify-content:flex-end;">
            <button type="button" onclick="closePriceHistoryModal()" style="background:transparent !important;background-color:transparent !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:8px 24px;border-radius:8px;font-size:14.5px;font-weight:700 !important;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:none !important;transition:all 0.15s ease;" onmouseover="this.style.background='#f1f5f9';this.style.borderColor='#94a3b8';" onmouseout="this.style.background='transparent';this.style.borderColor='#cbd5e1';">
                <i class="fas fa-times" style="color:#64748b !important;-webkit-text-fill-color:#64748b !important;"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- VIEW ADMIN MERCHANDISE DETAILS MODAL -->
<div id="viewAdminMerchModal" class="admin-layout-modal" style="display:none;position:fixed;top:70px;bottom:40px;left:250px;right:0;background:rgba(15,23,42,0.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;overflow:hidden;">
    <div style="background:#fff;border-radius:12px;width:94%;max-width:1000px;box-shadow:0 20px 50px rgba(0,0,0,0.35);margin:0 auto;overflow:hidden;max-height:calc(100% - 20px);display:flex;flex-direction:column;">
        <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:14px 22px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <h3 style="margin:0;font-size:16.5px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-box" style="color:#fff;font-size:17px;"></i>
                <span id="adm_vm_title" style="color:#fff;">MERCHANDISE SPECIFICATION &amp; HISTORY</span>
            </h3>
        </div>
        <div style="padding:20px 24px;overflow-y:auto;overflow-x:hidden;flex:1 1 auto;background:#fff;min-height:0;box-sizing:border-box;">
            <!-- Overview -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:20px;">
                <h4 style="margin:0 0 14px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;"><i class="fas fa-info-circle"></i> Product Specification &amp; Overview</h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;font-size:15.5px;">
                    <div><span style="color:#64748b;font-weight:600;">SKU / Code:</span><br><code id="adm_vm_sku" style="font-weight:800;color:#4f46e5;">-</code></div>
                    <div><span style="color:#64748b;font-weight:600;">Barcode:</span><br><code id="adm_vm_barcode" style="font-weight:800;color:#0284c7;">-</code></div>
                    <div><span style="color:#64748b;font-weight:600;">Product Name:</span><br><strong id="adm_vm_name" style="color:#0f172a;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Category:</span><br><strong id="adm_vm_category">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Brand:</span><br><strong id="adm_vm_brand">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Unit (UOM):</span><br><strong id="adm_vm_unit">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Current Selling Price:</span><br><strong id="adm_vm_price" style="color:#002F6C;font-size:15px;">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Current Cost Price:</span><br><strong id="adm_vm_cost" style="color:#16a34a;">-</strong> <small style="color:#94a3b8;font-size:15.5px;">(latest Stock-In)</small></div>
                    <div><span style="color:#64748b;font-weight:600;">Total Stock:</span><br><strong id="adm_vm_stock">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Batch Count:</span><br><strong id="adm_vm_batch_count">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Reorder Level:</span><br><strong id="adm_vm_reorder">-</strong></div>
                    <div><span style="color:#64748b;font-weight:600;">Status:</span><br><span id="adm_vm_status">-</span></div>
                </div>
            </div>
            <!-- Batch Summary -->
            <div style="margin-bottom:20px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="fas fa-layer-group" style="color:#0284c7;"></i> Batch Summary <small style="color:#64748b;font-weight:400;">(Read Only)</small></h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;">
                    <table style="width:100%;border-collapse:collapse;font-size:13.5px;table-layout:fixed;">
                        <colgroup>
                            <col style="width:28%;">
                            <col style="width:24%;">
                            <col style="width:24%;">
                            <col style="width:24%;">
                        </colgroup>
                        <thead><tr style="background:#f1f5f9;color:#334155;font-weight:700;"><th style="padding:10px 12px;text-align:left;">Batch No.</th><th style="padding:10px 12px;text-align:left;">Remaining Qty</th><th style="padding:10px 12px;text-align:left;">Expiration</th><th style="padding:10px 12px;text-align:left;">Status</th></tr></thead>
                        <tbody id="adm_vm_batches_body"><tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No batches</td></tr></tbody>
                    </table>
                </div>
            </div>
            <!-- Price History -->
            <div style="margin-bottom:20px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="fas fa-history" style="color:#4f46e5;"></i> Price History</h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;">
                        <colgroup>
                            <col style="width:18%;">
                            <col style="width:13%;">
                            <col style="width:13%;">
                            <col style="width:23%;">
                            <col style="width:23%;">
                            <col style="width:10%;">
                        </colgroup>
                        <thead><tr style="background:#f1f5f9;color:#334155;font-weight:700;"><th style="padding:10px 12px;text-align:left;">Date</th><th style="padding:10px 12px;text-align:left;">Old Price</th><th style="padding:10px 12px;text-align:left;">New Price</th><th style="padding:10px 12px;text-align:left;">Requested By</th><th style="padding:10px 12px;text-align:left;">Approved By</th><th style="padding:10px 12px;text-align:left;">Status</th></tr></thead>
                        <tbody id="adm_vm_price_history_body" style="word-break:break-word;"><tr><td colspan="6" style="text-align:center;padding:12px;color:#94a3b8;">No price history</td></tr></tbody>
                    </table>
                </div>
            </div>
            <!-- Config History -->
            <div style="margin-bottom:20px;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="fas fa-sliders-h" style="color:#d97706;"></i> Configuration History</h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;">
                        <colgroup>
                            <col style="width:18%;">
                            <col style="width:16%;">
                            <col style="width:23%;">
                            <col style="width:23%;">
                            <col style="width:20%;">
                        </colgroup>
                        <thead><tr style="background:#f1f5f9;color:#334155;font-weight:700;"><th style="padding:10px 12px;text-align:left;">Date</th><th style="padding:10px 12px;text-align:left;">Field</th><th style="padding:10px 12px;text-align:left;">Old Value</th><th style="padding:10px 12px;text-align:left;">New Value</th><th style="padding:10px 12px;text-align:left;">Changed By</th></tr></thead>
                        <tbody id="adm_vm_config_history_body" style="word-break:break-word;"><tr><td colspan="5" style="text-align:center;padding:12px;color:#94a3b8;">No changes recorded</td></tr></tbody>
                    </table>
                </div>
            </div>
            <!-- Status History -->
            <div>
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#0f172a;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="fas fa-power-off" style="color:#dc2626;"></i> Status History</h4>
                <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed;">
                        <colgroup>
                            <col style="width:20%;">
                            <col style="width:18%;">
                            <col style="width:18%;">
                            <col style="width:44%;">
                        </colgroup>
                        <thead><tr style="background:#f1f5f9;color:#334155;font-weight:700;"><th style="padding:10px 12px;text-align:left;">Date</th><th style="padding:10px 12px;text-align:left;">Old Status</th><th style="padding:10px 12px;text-align:left;">New Status</th><th style="padding:10px 12px;text-align:left;">Changed By</th></tr></thead>
                        <tbody id="adm_vm_status_history_body" style="word-break:break-word;"><tr><td colspan="4" style="text-align:center;padding:12px;color:#94a3b8;">No status changes</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div style="background:#ffffff;border-top:1px solid #e2e8f0;padding:12px 24px;display:flex;justify-content:flex-end;flex-shrink:0;">
            <button type="button" onclick="closeAdminViewMerchModal()" style="background:transparent !important;background-color:transparent !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:8px 24px;border-radius:8px;font-size:15px;font-weight:700 !important;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:none !important;transition:all 0.15s ease;" onmouseover="this.style.background='#f1f5f9';this.style.borderColor='#94a3b8';" onmouseout="this.style.background='transparent';this.style.borderColor='#cbd5e1';">
                <i class="fas fa-times" style="color:#64748b !important;-webkit-text-fill-color:#64748b !important;"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- 6. VIEW BATCHES MODAL (ADMIN) -->
<div id="viewAdminBatchesModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:700px; border-radius:12px; overflow:hidden; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="background:#002F6C; color:#ffffff !important; padding:16px 20px; display:flex; align-items:center;">
            <h4 id="adminBatchesTitle" style="margin:0; font-size:16px; font-weight:700; color:#ffffff !important; -webkit-text-fill-color:#ffffff !important; display:flex; align-items:center; gap:8px;"><i class="fas fa-layer-group" style="color:#ffffff !important; -webkit-text-fill-color:#ffffff !important;"></i> Batch History</h4>
        </div>
        <div id="adminBatchesContent" style="padding:20px; max-height:450px; overflow-y:auto;">
            <!-- Loaded dynamically via JS -->
        </div>
        <div style="background:#ffffff;border-top:1px solid #e2e8f0;padding:12px 20px;display:flex;justify-content:flex-end;">
            <button type="button" onclick="closeAdminBatchesModal()" style="background:transparent !important;background-color:transparent !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:8px 24px;border-radius:8px;font-size:14.5px;font-weight:700 !important;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:none !important;transition:all 0.15s ease;" onmouseover="this.style.background='#f1f5f9';this.style.borderColor='#94a3b8';" onmouseout="this.style.background='transparent';this.style.borderColor='#cbd5e1';">
                <i class="fas fa-times" style="color:#64748b !important;-webkit-text-fill-color:#64748b !important;"></i> Close
            </button>
        </div>
    </div>
</div>


<script>
// ── Helper: sanitize decimal inputs (avoids regex with > in HTML attributes breaking draft engine) ──
function sanitizeDecimalInput(el) {
    var v = el.value.replace(/[^0-9.]/g, '');
    var parts = v.split('.');
    if (parts.length > 2) { v = parts[0] + '.' + parts.slice(1).join(''); }
    el.value = v;
}
function sanitizeIntegerInput(el) {
    el.value = el.value.replace(/[^0-9]/g, '');
}
// ── Admin Merchandise Filter Function ──────────────────────────────────────
function filterAdminMerchTable() {
    var q          = (document.getElementById('adminSearchInput') ? document.getElementById('adminSearchInput').value : '').toLowerCase().trim();
    var catFilter  = document.getElementById('adminCatFilter') ? document.getElementById('adminCatFilter').value : '';
    var brandFilter= (document.getElementById('adminBrandFilter') ? document.getElementById('adminBrandFilter').value : '').toLowerCase();
    var unitFilter = (document.getElementById('adminUnitFilter') ? document.getElementById('adminUnitFilter').value : '').toLowerCase();
    var pStFilter  = document.getElementById('adminProdStatusFilter') ? document.getElementById('adminProdStatusFilter').value : '';
    var rStFilter  = document.getElementById('adminReqStatusFilter') ? document.getElementById('adminReqStatusFilter').value : '';

    var rows       = document.querySelectorAll('#adminMerchBody .admin-merch-row');
    var catHeaders = document.querySelectorAll('#adminMerchBody .cat-row');
    var catVisibleCount = {};
    var visible    = 0;

    rows.forEach(function(row) {
        var name     = row.getAttribute('data-name') || '';
        var sku      = row.getAttribute('data-sku')  || '';
        var brand    = row.getAttribute('data-brand') || '';
        var unit     = row.getAttribute('data-unit')  || '';
        var cat      = row.getAttribute('data-cat')   || '';
        var pStatus  = row.getAttribute('data-prodstatus') || '';
        var rStatus  = row.getAttribute('data-reqstatus')  || '';

        var matchQ      = !q || name.indexOf(q) !== -1 || sku.indexOf(q) !== -1 || brand.indexOf(q) !== -1;
        var matchCat    = !catFilter || cat === catFilter;
        var matchBrand  = !brandFilter || brand === brandFilter;
        var matchUnit   = !unitFilter || unit === unitFilter;
        var matchPStatus= !pStFilter || pStatus === pStFilter || (pStFilter === 'inactive' && (pStatus === 'disabled' || pStatus === 'deactivated'));
        var matchRStatus= !rStFilter || rStatus === rStFilter;

        var show = matchQ && matchCat && matchBrand && matchUnit && matchPStatus && matchRStatus;
        row.style.display = show ? '' : 'none';
        if (show) {
            visible++;
            catVisibleCount[cat] = (catVisibleCount[cat] || 0) + 1;
        }
    });

    catHeaders.forEach(function(hdr) {
        var cat = hdr.getAttribute('data-cat-header') || '';
        var count = catVisibleCount[cat] || 0;
        hdr.style.display = count > 0 ? '' : 'none';
    });

    var noRes = document.getElementById('adminMerchNoResults');
    if (noRes) noRes.style.display = (visible === 0 && rows.length > 0) ? 'block' : 'none';
}
window.filterAdminMerchTable = filterAdminMerchTable;

// ── Professional Toast Banner ─────────────────────────────────────────────
var adminToastDismissTimer = null;
var adminToastRemoveTimer = null;

function showCustomAlert(message, type, callback) {
    type = type || 'success';
    var isError = (type === 'error' || type === 'danger' || type === 'warning');
    var isWarning = (type === 'warning');

    // Dismiss any existing global toasts from header
    var globalStack = document.getElementById('globalToastStack');
    if (globalStack) globalStack.innerHTML = '';

    var container = document.getElementById('adminToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'adminToastContainer';
        container.style.cssText = 'position:fixed;top:85px;right:24px;z-index:2147483647;display:flex;flex-direction:column;gap:10px;max-width:400px;width:calc(100% - 48px);pointer-events:none;';
        document.body.appendChild(container);
    } else {
        container.style.top = '85px';
        container.style.zIndex = '2147483647';
    }

    // Strictly ONE banner at a time: clear existing toasts and pending timers
    if (adminToastDismissTimer) clearTimeout(adminToastDismissTimer);
    if (adminToastRemoveTimer) clearTimeout(adminToastRemoveTimer);
    container.innerHTML = '';

    var toast = document.createElement('div');
    var accentColor = isWarning ? '#d97706' : (isError ? '#dc2626' : '#16a34a');
    var iconBg      = isWarning ? '#fef3c7' : (isError ? '#fee2e2' : '#dcfce7');
    var iconClass   = isWarning ? 'fa-exclamation-circle' : (isError ? 'fa-times-circle' : 'fa-check-circle');
    var titleText   = isWarning ? 'Validation Error' : (isError ? 'Action Failed' : 'Action Successful');

    toast.style.cssText = [
        'pointer-events:auto',
        'background:#ffffff',
        'border-radius:12px',
        'padding:16px 18px',
        'box-shadow:0 20px 40px rgba(0,0,0,0.12),0 4px 12px rgba(0,0,0,0.06)',
        'display:flex',
        'align-items:flex-start',
        'gap:14px',
        'border:1px solid #e2e8f0',
        'transform:translateX(120%)',
        'opacity:0',
        'transition:all 0.35s cubic-bezier(0.16,1,0.3,1)',
        "font-family:'Segoe UI',Roboto,system-ui,sans-serif"
    ].filter(Boolean).join(';');

    toast.innerHTML =
        '<div style="width:38px;height:38px;border-radius:50%;background:' + iconBg + ';color:' + accentColor + ';display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;margin-top:1px;">'
            + '<i class="fas ' + iconClass + '"></i>'
        + '</div>'
        + '<div style="flex:1;min-width:0;">'
            + '<div style="font-size:14.5px;font-weight:800;color:#0f172a;margin-bottom:3px;letter-spacing:0.01em;">' + titleText + '</div>'
            + '<div style="font-size:15.5px;font-weight:500;color:#475569;line-height:1.45;">' + message + '</div>'
        + '</div>';

    container.appendChild(toast);
    setTimeout(function() { toast.style.transform = 'translateX(0)'; toast.style.opacity = '1'; }, 20);

    var delay = (typeof callback === 'function') ? 1400 : (isError || isWarning ? 4000 : 3000);
    adminToastDismissTimer = setTimeout(function() {
        toast.style.transform = 'translateX(120%)';
        toast.style.opacity = '0';
        adminToastRemoveTimer = setTimeout(function() {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
            if (typeof callback === 'function') callback();
        }, 350);
    }, delay);
}

// Map window.alert to showCustomAlert for clean, uniform feedback without standard popup dialogs
window.alert = function(msg) {
    showCustomAlert(msg, 'warning');
};

// ── 1. Admin Edit Product Modal ────────────────────────────────────────────
function openAdminEditProductModal(id) {
    // Show modal immediately — instant feedback on click
    document.getElementById('adminEditId').value = id;
    // Clear previous values while loading
    var fields = ['adminEditName','adminEditCategory','adminEditBrand','adminEditSku'];
    fields.forEach(function(f){ var el = document.getElementById(f); if(el) el.value = ''; });
    document.getElementById('adminEditProductModal').style.display = 'flex';
    // Fetch fresh data in background
    fetch('admin_set_prices_handler.php?action=get_merch_details_admin&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.item) {
                var i = data.item;
                document.getElementById('adminEditName').value     = i.product_name || '';
                document.getElementById('adminEditCategory').value = i.category || '';
                document.getElementById('adminEditBrand').value    = i.brand || '';
                document.getElementById('adminEditSku').value      = i.sku || '';
                document.getElementById('adminEditUnit').value     = i.unit || 'pcs';
                document.getElementById('adminEditReorder').value  = parseInt(i.reorder_level || 24);
                document.getElementById('adminEditCritical').value = parseInt(i.critical_level || 10);
                document.getElementById('adminEditStatus').value   = i.status || 'active';
                document.getElementById('adminEditPrice').value    = parseFloat(i.unit_price || 0).toFixed(2);
                document.getElementById('adminEditExpiry').value   = i.expiration_date || '';
            }
        })
        .catch(function() { /* Modal stays open; user can still proceed */ });
}

function closeAdminEditProductModal() {
    document.getElementById('adminEditProductModal').style.display = 'none';
    document.getElementById('adminEditProductForm').reset();
}

document.getElementById('adminEditProductForm').addEventListener('submit', function(e) {
    e.preventDefault();

    var nameVal  = document.getElementById('adminEditName').value.trim();
    var catVal   = document.getElementById('adminEditCategory').value.trim();
    var priceVal = parseFloat(document.getElementById('adminEditPrice').value || 0);
    var reorderVal = parseFloat(document.getElementById('adminEditReorder').value || 0);
    var criticalVal = parseFloat(document.getElementById('adminEditCritical').value || 0);

    var placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];

    if (!nameVal || placeholders.includes(nameVal.toLowerCase())) {
        showCustomAlert('Product Name is required and cannot be N/A or a placeholder.', 'warning');
        document.getElementById('adminEditName').focus();
        return;
    }

    if (!catVal || placeholders.includes(catVal.toLowerCase())) {
        showCustomAlert('Category is required and cannot be N/A or a placeholder.', 'warning');
        document.getElementById('adminEditCategory').focus();
        return;
    }

    if (isNaN(priceVal) || priceVal <= 0) {
        showCustomAlert('Default Selling Price must be a valid number greater than 0.', 'warning');
        document.getElementById('adminEditPrice').focus();
        return;
    }

    if (isNaN(reorderVal) || reorderVal <= 0) {
        showCustomAlert('Reorder Level must be a valid number greater than 0.', 'warning');
        document.getElementById('adminEditReorder').focus();
        return;
    }

    if (isNaN(criticalVal) || criticalVal <= 0) {
        showCustomAlert('Critical Level must be a valid number greater than 0.', 'warning');
        document.getElementById('adminEditCritical').focus();
        return;
    }

    var fd = new FormData();
    fd.append('action',         'edit_product_admin');
    fd.append('id',             document.getElementById('adminEditId').value);
    fd.append('product_name',   nameVal);
    fd.append('category',       catVal);
    fd.append('brand',          document.getElementById('adminEditBrand').value.trim());
    fd.append('sku',            document.getElementById('adminEditSku').value.trim());
    fd.append('unit',           document.getElementById('adminEditUnit').value.trim());
    fd.append('unit_price',     priceVal);
    fd.append('reorder_level',  reorderVal);
    fd.append('critical_level', criticalVal);
    fd.append('status',         document.getElementById('adminEditStatus').value);
    fd.append('expiration_date', ((document.getElementById('adminEditExpiry') || {}).value || '').trim());

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeAdminEditProductModal();
                showCustomAlert('Product updated successfully!', 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to update product.', 'error');
            }
        }).catch(function() { showCustomAlert('Network error while updating product.', 'error'); });
});

// ── 1.1 Admin Edit Fuel Modal ─────────────────────────────────────────────
function openAdminEditFuelModal(id, fuelType, price, capacity, critical) {
    document.getElementById('adminEditFuelId').value = id;
    document.getElementById('adminEditFuelTypeDisplay').textContent = fuelType;
    document.getElementById('adminEditFuelPrice').value = parseFloat(price || 0).toFixed(2);
    document.getElementById('adminEditFuelCapacity').value = parseFloat(capacity || 0).toFixed(2);
    document.getElementById('adminEditFuelCritical').value = parseFloat(critical || 0).toFixed(2);
    document.getElementById('adminEditFuelModal').style.display = 'flex';
}

function closeAdminEditFuelModal() {
    document.getElementById('adminEditFuelModal').style.display = 'none';
    document.getElementById('adminEditFuelForm').reset();
}

document.getElementById('adminEditFuelForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData();
    fd.append('action',         'admin_edit_fuel');
    fd.append('id',             document.getElementById('adminEditFuelId').value);
    fd.append('price',          document.getElementById('adminEditFuelPrice').value);
    fd.append('capacity',       document.getElementById('adminEditFuelCapacity').value);
    fd.append('critical_level', document.getElementById('adminEditFuelCritical').value);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeAdminEditFuelModal();
                showCustomAlert('Fuel product updated successfully!', 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to update fuel product.', 'error');
            }
        }).catch(function() { showCustomAlert('Network error while updating fuel product.', 'error'); });
});

// ── 1.1b Admin View Service Modal ──────────────────────────────────────────
function openAdminViewServiceModal(svc) {
    if (typeof svc === 'string') {
        try { svc = JSON.parse(svc); } catch(e) { console.error(e); }
    }
    if (!svc) return;

    document.getElementById('adm_vs_code').textContent = svc.service_code || ('SVC-' + String(svc.id).padStart(4, '0'));
    document.getElementById('adm_vs_name').textContent = svc.service_name || '—';
    document.getElementById('adm_vs_category').textContent = svc.category || 'General';
    document.getElementById('adm_vs_fee').textContent = '₱' + (parseFloat(svc.service_price || 0)).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('adm_vs_labor').textContent = '₱' + (parseFloat(svc.labor_fee || 0)).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    var totalFee = (parseFloat(svc.service_price || 0) + parseFloat(svc.labor_fee || 0)).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('adm_vs_total').textContent = '₱' + totalFee;
    document.getElementById('adm_vs_mechanics').textContent = (svc.required_mechanics || 1) + ' mechanic(s)';
    document.getElementById('adm_vs_updated').textContent = svc.updated_at || '—';
    document.getElementById('adm_vs_desc').textContent = svc.description || 'No description provided.';
    
    var statusEl = document.getElementById('adm_vs_status');
    if (svc.active == 1) {
        statusEl.innerHTML = '<span style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;text-transform:uppercase;">Active</span>';
    } else {
        statusEl.innerHTML = '<span style="background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;text-transform:uppercase;">Inactive</span>';
    }
    
    var editBtn = document.getElementById('adm_vs_edit_btn');
    editBtn.onclick = function() {
        closeAdminViewServiceModal();
        openAdminEditServiceModal(svc.id, svc.service_name, svc.category, svc.service_key, svc.service_price, svc.active);
    };

    document.getElementById('viewAdminServiceModal').style.display = 'flex';
}

function closeAdminViewServiceModal() {
    document.getElementById('viewAdminServiceModal').style.display = 'none';
}

// ── 1.2 Admin Edit Service Modal ──────────────────────────────────────────
function openAdminEditServiceModal(id, name, category, key, price, active) {
    document.getElementById('adminEditServiceId').value = id;
    document.getElementById('adminEditServiceName').value = name;
    document.getElementById('adminEditServiceCategory').value = category;
    document.getElementById('adminEditServiceKey').value = key;
    document.getElementById('adminEditServicePrice').value = parseFloat(price || 0).toFixed(2);
    document.getElementById('adminEditServiceActive').value = active ? '1' : '0';
    document.getElementById('adminEditServiceModal').style.display = 'flex';
}

function closeAdminEditServiceModal() {
    document.getElementById('adminEditServiceModal').style.display = 'none';
    document.getElementById('adminEditServiceForm').reset();
}

document.getElementById('adminEditServiceForm').addEventListener('submit', function(e) {
    e.preventDefault();

    var nameVal  = document.getElementById('adminEditServiceName').value.trim();
    var catVal   = document.getElementById('adminEditServiceCategory').value.trim();
    var keyVal   = document.getElementById('adminEditServiceKey').value.trim();
    var priceVal = parseFloat(document.getElementById('adminEditServicePrice').value || 0);

    var placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];

    if (!nameVal || placeholders.includes(nameVal.toLowerCase())) {
        showCustomAlert('Service Name is required and cannot be N/A or a placeholder.', 'warning');
        document.getElementById('adminEditServiceName').focus();
        return;
    }

    if (!catVal || placeholders.includes(catVal.toLowerCase())) {
        showCustomAlert('Category is required and cannot be N/A or a placeholder.', 'warning');
        document.getElementById('adminEditServiceCategory').focus();
        return;
    }

    if (!keyVal || placeholders.includes(keyVal.toLowerCase())) {
        showCustomAlert('Service Key is required and cannot be N/A or a placeholder.', 'warning');
        document.getElementById('adminEditServiceKey').focus();
        return;
    }

    if (isNaN(priceVal) || priceVal <= 0) {
        showCustomAlert('Service Price must be a valid number greater than 0.', 'warning');
        document.getElementById('adminEditServicePrice').focus();
        return;
    }

    var fd = new FormData();
    fd.append('action',        'admin_edit_service');
    fd.append('id',            document.getElementById('adminEditServiceId').value);
    fd.append('service_name',  nameVal);
    fd.append('category',      catVal);
    fd.append('service_key',   keyVal);
    fd.append('service_price', priceVal);
    fd.append('active',        document.getElementById('adminEditServiceActive').value);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeAdminEditServiceModal();
                showCustomAlert('Service type updated successfully!', 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to update service type.', 'error');
            }
        }).catch(function() { showCustomAlert('Network error while updating service type.', 'error'); });
});

function adminToggleService(id, active, name) {
    var idInput  = document.getElementById('toggleServiceStatusId');
    var valInput = document.getElementById('toggleServiceStatusValue');
    if (idInput)  idInput.value = id;
    if (valInput) valInput.value = active;

    var header     = document.getElementById('toggleServiceStatusHeader');
    var title      = document.getElementById('toggleServiceStatusTitle');
    var hIcon      = document.getElementById('toggleServiceHeaderIcon');
    var btnIcon    = document.getElementById('toggleServiceBtnIcon');
    var desc       = document.getElementById('toggleServiceStatusDesc');
    var confirmBtn = document.getElementById('toggleServiceStatusConfirmBtn');

    var safeName = document.createElement('div');
    safeName.textContent = name;
    var escapedName = safeName.innerHTML;

    if (parseInt(active, 10) === 1) {
        if (header) header.style.background = 'linear-gradient(135deg, #16a34a, #15803d)';
        if (title) title.innerText = 'Activate Service';
        if (hIcon) hIcon.className = 'fas fa-check-circle';
        if (btnIcon) btnIcon.className = 'fas fa-check-circle';
        if (desc) desc.innerHTML = 'Are you sure you want to activate <strong style="color:#0f172a;">' + escapedName + '</strong>? This service will become active for job orders.';
        if (confirmBtn) {
            confirmBtn.style.background = '#16a34a';
            confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Activation';
        }
    } else {
        if (header) header.style.background = 'linear-gradient(135deg, #dc2626, #b91c1c)';
        if (title) title.innerText = 'Deactivate Service';
        if (hIcon) hIcon.className = 'fas fa-ban';
        if (btnIcon) btnIcon.className = 'fas fa-ban';
        if (desc) desc.innerHTML = 'Are you sure you want to deactivate <strong style="color:#0f172a;">' + escapedName + '</strong>? Deactivated services will be hidden from new job orders.';
        if (confirmBtn) {
            confirmBtn.style.background = '#dc2626';
            confirmBtn.innerHTML = '<i class="fas fa-ban"></i> Confirm Deactivation';
        }
    }

    var modal = document.getElementById('toggleServiceStatusModal');
    if (modal) modal.style.display = 'flex';
}

function closeToggleServiceStatusModal() {
    document.getElementById('toggleServiceStatusModal').style.display = 'none';
}

function confirmAdminToggleService() {
    var id     = document.getElementById('toggleServiceStatusId').value;
    var active = document.getElementById('toggleServiceStatusValue').value;

    var fd = new FormData();
    fd.append('action', 'admin_toggle_service');
    fd.append('id', id);
    fd.append('active', active);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            closeToggleServiceStatusModal();
            if (data.success) {
                showCustomAlert(data.message, 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to update service status.', 'error');
            }
        }).catch(function() {
            closeToggleServiceStatusModal();
            showCustomAlert('Network error while updating service status.', 'error');
        });
}

function restoreServiceFees(id, name, oldSvcFee, oldLabFee) {
    document.getElementById('restoreSvcId').value = id;
    document.getElementById('restoreOldSvcFee').value = oldSvcFee;
    document.getElementById('restoreOldLabFee').value = oldLabFee;

    var safeName = document.createElement('div');
    safeName.textContent = name;
    var escapedName = safeName.innerHTML;

    var desc = document.getElementById('restoreSvcDesc');
    desc.innerHTML = 'Are you sure you want to restore previous fees for <strong style="color:#0f172a;">' + escapedName + '</strong>?<br><br>' +
        '• <strong>Service Fee:</strong> ₱' + parseFloat(oldSvcFee).toFixed(2) + '<br>' +
        '• <strong>Labor Fee:</strong> ₱' + parseFloat(oldLabFee).toFixed(2);

    document.getElementById('restoreServiceFeesModal').style.display = 'flex';
}

function closeRestoreServiceFeesModal() {
    document.getElementById('restoreServiceFeesModal').style.display = 'none';
}

function confirmRestoreServiceFees() {
    var id        = document.getElementById('restoreSvcId').value;
    var oldSvcFee = document.getElementById('restoreOldSvcFee').value;
    var oldLabFee = document.getElementById('restoreOldLabFee').value;

    var fd = new FormData();
    fd.append('action', 'restore_service_fees');
    fd.append('id', id);
    fd.append('old_service_fee', oldSvcFee);
    fd.append('old_labor_fee', oldLabFee);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            closeRestoreServiceFeesModal();
            if (data.success) {
                showCustomAlert(data.message, 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to restore service fees.', 'error');
            }
        }).catch(function() {
            closeRestoreServiceFeesModal();
            showCustomAlert('Network error while restoring service fees.', 'error');
        });
}

// ── 2. View Request Modal ──────────────────────────────────────────────────
function openViewRequestModal(approvalId) {
    document.getElementById('viewRequestContent').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading details&hellip;</div>';
    document.getElementById('viewRequestModal').style.display = 'flex';

    fetch('admin_set_prices_handler.php?action=get_request_details&approval_id=' + approvalId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.request) {
                document.getElementById('viewRequestContent').innerHTML = '<div style="color:#dc2626;text-align:center;padding:10px;">Failed to load request details.</div><div style="margin-top:16px;text-align:center;"><button type="button" onclick="closeViewRequestModal()" style="padding:8px 18px;border:1px solid #cbd5e1;background:#f1f5f9;color:#0f172a;border-radius:6px;cursor:pointer;font-weight:600;">Close</button></div>';
                return;
            }
            var req = data.request;
            var oldP = parseFloat(req.old_price || req.old_value || 0).toFixed(2);
            var newP = parseFloat(req.new_price || req.new_value || 0).toFixed(2);
            var reqBy = req.requested_by_name || 'Manager';
            var reason = req.reason || req.remarks || 'Supplier acquisition cost change';
            var dateReq = (req.created_at || '').substring(0, 16);

            document.getElementById('viewRequestContent').innerHTML = `
                <table style="width:100%; border-collapse:collapse; font-size:15.5px;">
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Product:</td><td style="padding:8px 0; font-weight:700; color:#002F6C; text-align:right;">${req.product_name}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Current Price:</td><td style="padding:8px 0; font-weight:600; text-align:right; color:#64748b;">₱${oldP}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Requested Price:</td><td style="padding:8px 0; font-weight:700; text-align:right; color:#16a34a; font-size:15px;">₱${newP}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Requested By:</td><td style="padding:8px 0; text-align:right; font-weight:600;">${reqBy}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Reason:</td><td style="padding:8px 0; text-align:right; color:#334155;">${reason}</td></tr>
                    <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:8px 0; color:#64748b; font-weight:600;">Date Requested:</td><td style="padding:8px 0; text-align:right; color:#64748b;">${dateReq}</td></tr>
                </table>
                <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeViewRequestModal()" style="padding:8px 16px; border:1px solid #cbd5e1; background:#f1f5f9; color:#0f172a; border-radius:6px; cursor:pointer; font-weight:600;">Cancel</button>
                    <button onclick="closeViewRequestModal(); openApproveConfirmModal(${approvalId}, '${req.product_name.replace(/'/g, "\\'")}', '${oldP}', '${newP}')" style="padding:8px 18px; border:none; background:#16a34a; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-check"></i> Approve</button>
                    <button onclick="closeViewRequestModal(); openRejectReasonModal(${approvalId}, '${req.product_name.replace(/'/g, "\\'")}')" style="padding:8px 18px; border:none; background:#dc2626; color:#fff; border-radius:6px; cursor:pointer; font-weight:600;"><i class="fas fa-times"></i> Reject</button>
                </div>
            `;
        });
}

function closeViewRequestModal() {
    document.getElementById('viewRequestModal').style.display = 'none';
}

// ── 3. Approve Confirmation Modal ──────────────────────────────────────────
function openApproveConfirmModal(approvalId, productName, currentPrice, requestedPrice) {
    document.getElementById('confirmApproveId').value = approvalId;
    document.getElementById('appModalProdName').textContent = productName;
    document.getElementById('appModalOldPrice').textContent = '₱' + currentPrice;
    document.getElementById('appModalNewPrice').textContent = '₱' + requestedPrice;
    document.getElementById('approveConfirmModal').style.display = 'flex';
}

function closeApproveConfirmModal() {
    document.getElementById('approveConfirmModal').style.display = 'none';
}

function confirmApprovePriceRequest() {
    var id = document.getElementById('confirmApproveId').value;
    var fd = new FormData();
    fd.append('action', 'approve_price_request');
    fd.append('approval_id', id);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeApproveConfirmModal();
                showCustomAlert('Price change approved successfully!', 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to approve request.', 'error');
            }
        }).catch(function() { showCustomAlert('Network error while approving request.', 'error'); });
}

// ── 4. Reject Reason Modal ────────────────────────────────────────────────
function openRejectReasonModal(approvalId, productName) {
    document.getElementById('rejectReasonApprovalId').value = approvalId;
    document.getElementById('rejectModalProdName').textContent = productName;
    document.getElementById('rejectReasonText').value = '';
    document.getElementById('rejectReasonModal').style.display = 'flex';
}

function closeRejectReasonModal() {
    document.getElementById('rejectReasonModal').style.display = 'none';
}

function confirmRejectPriceRequest() {
    var id = document.getElementById('rejectReasonApprovalId').value;
    var reason = document.getElementById('rejectReasonText').value.trim();

    if (!reason) {
        showCustomAlert('Please enter a rejection reason before submitting.', 'warning');
        return;
    }

    var fd = new FormData();
    fd.append('action', 'reject_price_request');
    fd.append('approval_id', id);
    fd.append('rejection_reason', reason);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                closeRejectReasonModal();
                showCustomAlert('Price change request rejected.', 'success', function() { location.reload(); });
            } else {
                showCustomAlert(data.message || 'Failed to reject request.', 'error');
            }
        }).catch(function() { showCustomAlert('Network error while rejecting request.', 'error'); });
}

// ── 5. View Price History Modal ───────────────────────────────────────────
function openPriceHistoryModal(productId, productName) {
    document.getElementById('priceHistoryTitle').textContent = productName + ' — Price History';
    document.getElementById('priceHistoryContent').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading history&hellip;</div>';
    document.getElementById('priceHistoryModal').style.display = 'flex';

    fetch('admin_set_prices_handler.php?action=get_price_history&product_id=' + productId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.history || data.history.length === 0) {
                document.getElementById('priceHistoryContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-history" style="font-size:32px;margin-bottom:10px;display:block;"></i>No price change history found for this product.</div>';
                return;
            }
            var rows = data.history.map(function(h) {
                var oldP = parseFloat(h.old_price || 0).toFixed(2);
                var newP = parseFloat(h.new_price || 0).toFixed(2);
                var dateStr = (h.date_approved || h.date_requested || '').substring(0, 10);
                var reqBy = h.requested_by || 'Manager';
                var appBy = h.approved_by || 'Admin';
                var statusBadge = h.status === 'approved'
                    ? '<span style="background:#dcfce7;color:#166534;padding:2px 6px;border-radius:4px;font-size:15.5px;font-weight:700;">Approved</span>'
                    : '<span style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:15.5px;font-weight:700;">Rejected</span>';

                return '<tr style="border-bottom:1px solid #f1f5f9;">'
                    + '<td style="padding:8px 10px; font-size:14.5px; color:#64748b;">' + dateStr + '</td>'
                    + '<td style="padding:8px 10px; text-align:right; color:#64748b;">₱' + oldP + '</td>'
                    + '<td style="padding:8px 10px; text-align:right; font-weight:700; color:#002F6C;">₱' + newP + '</td>'
                    + '<td style="padding:8px 10px; font-size:14.5px;">' + reqBy + '</td>'
                    + '<td style="padding:8px 10px; font-size:14.5px;">' + appBy + ' ' + statusBadge + '</td>'
                    + '</tr>';
            }).join('');

            document.getElementById('priceHistoryContent').innerHTML =
                '<table style="width:100%; border-collapse:collapse; font-size:15.5px;">'
                + '<thead><tr style="background:#002F6C; color:#fff;">'
                + '<th style="padding:8px 10px; text-align:left;">Date</th>'
                + '<th style="padding:8px 10px; text-align:right;">Old Price</th>'
                + '<th style="padding:8px 10px; text-align:right;">New Price</th>'
                + '<th style="padding:8px 10px; text-align:left;">Requested By</th>'
                + '<th style="padding:8px 10px; text-align:left;">Approved By</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table>';
        });
}

function closePriceHistoryModal() {
    document.getElementById('priceHistoryModal').style.display = 'none';
}

// ── 6. View Batches Modal (Admin) ──────────────────────────────────────────
function viewAdminBatches(productId, productName) {
    document.getElementById('adminBatchesTitle').textContent = productName + ' — Batch History';
    document.getElementById('adminBatchesContent').innerHTML = '<div style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Loading batches&hellip;</div>';
    document.getElementById('viewAdminBatchesModal').style.display = 'flex';

    fetch('admin_set_prices_handler.php?action=get_product_batches&id=' + productId)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.batches || data.batches.length === 0) {
                document.getElementById('adminBatchesContent').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:30px;"><i class="fas fa-box-open" style="font-size:32px;margin-bottom:10px;display:block;"></i>No batch records found for this product.<br><small>Record a delivery to create the first batch.</small></div>';
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
                    + '<td style="padding:8px 10px;text-align:right;font-weight:700;">' + parseInt(b.remaining_qty||0) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;color:#64748b;">&#8369;' + parseFloat(b.unit_cost||0).toFixed(2) + '</td>'
                    + '<td style="padding:8px 10px;text-align:right;color:#002F6C;font-weight:600;">&#8369;' + parseFloat(b.selling_price||0).toFixed(2) + '</td>'
                    + '<td style="padding:8px 10px;font-size:14px;color:#64748b;">' + (b.date_received||'—').substring(0,10) + '</td>'
                    + '</tr>';
            }).join('');

            document.getElementById('adminBatchesContent').innerHTML =
                '<table style="width:100%;border-collapse:collapse;font-size:15.5px;">'
                + '<thead><tr style="background:#002F6C;color:#fff;">'
                + '<th style="padding:10px;text-align:left;">Batch</th>'
                + '<th style="padding:10px;text-align:right;">Remaining</th>'
                + '<th style="padding:10px;text-align:right;">Cost</th>'
                + '<th style="padding:10px;text-align:right;">Selling</th>'
                + '<th style="padding:10px;">Date</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table>';
        });
}

function closeAdminBatchesModal() {
    document.getElementById('viewAdminBatchesModal').style.display = 'none';
}

// ── Admin View Merchandise Details Modal ─────────────────────────────────────
function viewAdminMerchandiseDetails(id) {
    document.getElementById('viewAdminMerchModal').style.display = 'flex';
    ['adm_vm_sku','adm_vm_barcode','adm_vm_name','adm_vm_category','adm_vm_brand','adm_vm_unit','adm_vm_price','adm_vm_cost','adm_vm_stock','adm_vm_batch_count','adm_vm_reorder'].forEach(function(el){
        var e = document.getElementById(el); if(e) e.textContent = '...';
    });
    ['adm_vm_batches_body','adm_vm_price_history_body','adm_vm_config_history_body','adm_vm_status_history_body'].forEach(function(el){
        var e = document.getElementById(el);
        if(e) e.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:12px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
    });

    fetch('admin_set_prices_handler.php?action=get_merchandise_details_admin&id=' + id)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data || !data.success) {
            closeAdminViewMerchModal();
            showCustomAlert((data && data.message) ? data.message : 'Failed to load product details.', 'error');
            return;
        }
        var p = data.product || {};
        
        function setVmText(elId, val) {
            var el = document.getElementById(elId);
            if (el) el.textContent = (val !== null && val !== undefined && val !== '') ? val : '—';
        }

        setVmText('adm_vm_title', (p.name || 'Product') + ' — SPECIFICATION & HISTORY');
        setVmText('adm_vm_sku', p.sku);
        setVmText('adm_vm_barcode', p.barcode);
        setVmText('adm_vm_name', p.name);
        setVmText('adm_vm_category', p.category_name);
        setVmText('adm_vm_brand', p.brand);
        setVmText('adm_vm_unit', p.unit);
        setVmText('adm_vm_price', '₱' + parseFloat(p.price || 0).toFixed(2));
        setVmText('adm_vm_cost', '₱' + parseFloat(p.cost || 0).toFixed(2));
        setVmText('adm_vm_stock', parseFloat(p.current_stock || 0).toLocaleString());
        setVmText('adm_vm_batch_count', (p.batch_count || 0) + ' batch(es)');
        setVmText('adm_vm_reorder', p.min_stock_level);
        
        var stLower = (p.status || 'active').toLowerCase();
        var stColor = stLower === 'active' ? '#16a34a' : '#dc2626';
        var stBg = stLower === 'active' ? '#dcfce7' : '#fee2e2';
        var stEl = document.getElementById('adm_vm_status');
        if (stEl) {
            stEl.innerHTML = '<span style="background:' + stBg + ';color:' + stColor + ';padding:2px 10px;border-radius:20px;font-size:14px;font-weight:700;">' + (p.status || 'Active') + '</span>';
        }

        // Batches
        var bb = document.getElementById('adm_vm_batches_body');
        if (bb) {
            if (data.batches && data.batches.length > 0) {
                bb.innerHTML = data.batches.map(function(b) {
                    var stBadge = b.status === 'active' ? '<span style="background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:10px;font-size:12.5px;font-weight:700;white-space:nowrap;">Active</span>' : '<span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:10px;font-size:12.5px;font-weight:700;white-space:nowrap;">' + b.status + '</span>';
                    var tdB = 'padding:9px 12px;font-size:13.5px;vertical-align:top;word-break:break-word;overflow-wrap:break-word;';
                    return '<tr style="border-top:1px solid #f1f5f9;">' +
                        '<td style="' + tdB + 'font-family:monospace;font-weight:700;color:#0284c7;">' + (b.batch_number || '—') + '</td>' +
                        '<td style="' + tdB + 'font-weight:700;">' + parseFloat(b.remaining_qty || 0).toLocaleString() + '</td>' +
                        '<td style="' + tdB + '">' + (b.expiration_date || '—') + '</td>' +
                        '<td style="' + tdB + '">' + stBadge + '</td></tr>';
                }).join('');
            } else { bb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:14px;color:#94a3b8;font-size:13.5px;">No batch records</td></tr>'; }
        }

        // Price History
        var pb = document.getElementById('adm_vm_price_history_body');
        if (pb) {
            if (data.price_history && data.price_history.length > 0) {
                pb.innerHTML = data.price_history.map(function(h) {
                    var statusColor = h.status === 'approved' ? '#16a34a' : h.status === 'rejected' ? '#dc2626' : '#d97706';
                    var statusBg = h.status === 'approved' ? '#dcfce7' : h.status === 'rejected' ? '#fee2e2' : '#fef3c7';
                    var tdBase = 'padding:9px 10px;font-size:13px;vertical-align:top;word-break:break-word;overflow-wrap:break-word;';
                    var tdUser = 'padding:9px 10px;font-size:12.5px;vertical-align:top;word-break:break-all;overflow-wrap:anywhere;';
                    return '<tr style="border-top:1px solid #f1f5f9;">' +
                        '<td style="' + tdBase + 'color:#64748b;line-height:1.35;">' + (h.created_at || '—') + '</td>' +
                        '<td style="' + tdBase + '">₱' + parseFloat(h.old_price || 0).toFixed(2) + '</td>' +
                        '<td style="' + tdBase + 'font-weight:800;color:#002F6C;">₱' + parseFloat(h.new_price || 0).toFixed(2) + '</td>' +
                        '<td style="' + tdUser + '">' + (h.requested_by_name || '—') + '</td>' +
                        '<td style="' + tdUser + '">' + (h.approved_by_name || '—') + '</td>' +
                        '<td style="' + tdBase + '"><span style="background:' + statusBg + ';color:' + statusColor + ';padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;white-space:nowrap;">' + (h.status || '—') + '</span></td></tr>';
                }).join('');
            } else { pb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:14px;color:#94a3b8;font-size:13.5px;">No price history</td></tr>'; }
        }

        // Config History
        var cb = document.getElementById('adm_vm_config_history_body');
        if (cb) {
            if (data.config_history && data.config_history.length > 0) {
                cb.innerHTML = data.config_history.map(function(h) {
                    var tdBase = 'padding:9px 10px;font-size:13px;vertical-align:top;word-break:break-word;overflow-wrap:break-word;';
                    var tdUser = 'padding:9px 10px;font-size:12.5px;vertical-align:top;word-break:break-all;overflow-wrap:anywhere;';
                    return '<tr style="border-top:1px solid #f1f5f9;">' +
                        '<td style="' + tdBase + 'color:#64748b;line-height:1.35;">' + (h.created_at || '—') + '</td>' +
                        '<td style="' + tdBase + 'font-weight:700;color:#002F6C;">' + (h.field_name || '—') + '</td>' +
                        '<td style="' + tdBase + 'color:#dc2626;line-height:1.4;">' + (h.old_value || '—') + '</td>' +
                        '<td style="' + tdBase + 'color:#16a34a;font-weight:700;line-height:1.4;">' + (h.new_value || '—') + '</td>' +
                        '<td style="' + tdUser + 'color:#334155;">' + (h.changed_by_name || '—') + '</td></tr>';
                }).join('');
            } else { cb.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:14px;color:#94a3b8;font-size:13.5px;">No configuration changes recorded</td></tr>'; }
        }

        // Status History
        var sb = document.getElementById('adm_vm_status_history_body');
        if (sb) {
            if (data.status_history && data.status_history.length > 0) {
                sb.innerHTML = data.status_history.map(function(h) {
                    var tdBase = 'padding:9px 10px;font-size:13px;vertical-align:top;word-break:break-word;overflow-wrap:break-word;';
                    var tdUser = 'padding:9px 10px;font-size:12.5px;vertical-align:top;word-break:break-all;overflow-wrap:anywhere;';
                    return '<tr style="border-top:1px solid #f1f5f9;">' +
                        '<td style="' + tdBase + 'color:#64748b;line-height:1.35;">' + (h.created_at || '—') + '</td>' +
                        '<td style="' + tdBase + 'color:#64748b;text-transform:capitalize;">' + (h.old_status || '—') + '</td>' +
                        '<td style="' + tdBase + 'font-weight:700;text-transform:capitalize;color:#16a34a;">' + (h.new_status || '—') + '</td>' +
                        '<td style="' + tdUser + 'color:#334155;">' + (h.changed_by_name || '—') + '</td></tr>';
                }).join('');
            } else { sb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:14px;color:#94a3b8;font-size:13.5px;">No status changes recorded</td></tr>'; }
        }
    })
    .catch(function(err) {
        console.error('Error in viewAdminMerchandiseDetails:', err);
        closeAdminViewMerchModal();
        showCustomAlert('Error loading product details.', 'error');
    });
}

function closeAdminViewMerchModal() {
    document.getElementById('viewAdminMerchModal').style.display = 'none';
}

// ── Admin Fuel Table Filters ──────────────────────────────────────────────
function filterAdminFuelTable() {
    var searchVal = (document.getElementById('adminFuelSearch') ? document.getElementById('adminFuelSearch').value : '').toLowerCase().trim();
    var fuelTypeVal = document.getElementById('adminFuelTypeFilter') ? document.getElementById('adminFuelTypeFilter').value : '';
    var ugtVal = document.getElementById('adminFuelUgtFilter') ? document.getElementById('adminFuelUgtFilter').value : '';
    var reqStatusVal = document.getElementById('adminFuelPriceReqFilter') ? document.getElementById('adminFuelPriceReqFilter').value : '';
    var statusVal = document.getElementById('adminFuelStatusFilter') ? document.getElementById('adminFuelStatusFilter').value : '';

    var rows = document.querySelectorAll('#adminFuelTableBody tr.admin-fuel-row');
    rows.forEach(function(row) {
        var ugt = row.getAttribute('data-ugt') || '';
        var fueltype = row.getAttribute('data-fueltype') || '';
        var fullname = row.getAttribute('data-fullname') || '';
        var reqstatus = row.getAttribute('data-reqstatus') || 'none';
        var activestatus = row.getAttribute('data-activestatus') || 'active';

        var matchesSearch = !searchVal || ugt.toLowerCase().indexOf(searchVal) !== -1 || fullname.toLowerCase().indexOf(searchVal) !== -1 || fueltype.toLowerCase().indexOf(searchVal) !== -1;
        var matchesFuelType = !fuelTypeVal || fueltype.toLowerCase() === fuelTypeVal.toLowerCase();
        var matchesUgt = !ugtVal || ugt.toLowerCase() === ugtVal.toLowerCase();
        var matchesReqStatus = !reqStatusVal || (reqStatusVal === 'pending' && reqstatus === 'pending') || (reqStatusVal === 'rejected' && reqstatus === 'rejected') || (reqStatusVal === 'none' && (reqstatus === 'none' || reqstatus === 'approved' || !reqstatus));
        var matchesStatus = !statusVal || activestatus === statusVal;

        if (matchesSearch && matchesFuelType && matchesUgt && matchesReqStatus && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
window.filterAdminFuelTable = filterAdminFuelTable;

function filterAdminFuelByCard(type) {
    var searchEl = document.getElementById('adminFuelSearch');
    var fuelTypeEl = document.getElementById('adminFuelTypeFilter');
    var ugtEl = document.getElementById('adminFuelUgtFilter');
    var reqStatusEl = document.getElementById('adminFuelPriceReqFilter');
    var statusEl = document.getElementById('adminFuelStatusFilter');

    if (searchEl) searchEl.value = '';
    if (fuelTypeEl) fuelTypeEl.value = '';
    if (ugtEl) ugtEl.value = '';
    
    if (type === 'all') {
        if (reqStatusEl) reqStatusEl.value = '';
        if (statusEl) statusEl.value = '';
    } else if (type === 'pending') {
        if (reqStatusEl) reqStatusEl.value = 'pending';
        if (statusEl) statusEl.value = '';
    } else if (type === 'active') {
        if (reqStatusEl) reqStatusEl.value = '';
        if (statusEl) statusEl.value = 'active';
    } else if (type === 'inactive') {
        if (reqStatusEl) reqStatusEl.value = '';
        if (statusEl) statusEl.value = 'inactive';
    }
    filterAdminFuelTable();
}

var _currentAdminViewFuel = null;

// ── Admin View Fuel Modal ──────────────────────────────────────────────────
function openViewFuelModalAdmin(id) {
    var modal = document.getElementById('viewFuelModalAdmin');
    if (!modal) return;
    modal.style.display = 'flex';

    // Clear previous or set loading state
    var pendingCont = document.getElementById('adm_fuel_pending_container');
    if (pendingCont) pendingCont.innerHTML = '';
    var pBody = document.getElementById('adm_priceHistoryBody');
    if (pBody) pBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading price history...</td></tr>';
    var cBody = document.getElementById('adm_configHistoryBody');
    if (cBody) cBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading configuration history...</td></tr>';
    var sBody = document.getElementById('adm_statusHistoryBody');
    if (sBody) sBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading status history...</td></tr>';
    var vpBadge = document.getElementById('adm_view_pump_count_badge');
    if (vpBadge) vpBadge.textContent = '...';
    var vpCont = document.getElementById('adm_view_pumps_container');
    if (vpCont) vpCont.innerHTML = '<div style="grid-column:1/-1;color:#64748b;font-size:13px;font-style:italic;padding:8px 0;"><i class="fas fa-spinner fa-spin"></i> Loading assigned pumps...</div>';

    fetch('admin_set_prices_handler.php?action=get_fuel_details_admin&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.fuel) {
                showCustomAlert(data.message || 'Failed to load fuel details.', 'error');
                return;
            }

            var f = data.fuel;
            _currentAdminViewFuel = f;
            var req = data.pending_request;
            var history = data.price_history || [];
            var configHist = data.config_history || [];
            var statusHist = data.status_history || [];

            var ugt = f.ugt_no || ('UGT #' + f.pump_id);
            var fName = (f.raw_fuel_type || f.fuel_type || 'Fuel').replace(/\s*\(UGT\s*#?\d+\)/gi, '').trim();
            var curPrice = parseFloat(f.price_per_liter || 0).toFixed(2);
            var capacity = parseFloat(f.capacity || 0).toFixed(2);
            var curStock = parseFloat(f.current_stock || f.current_level || 0).toFixed(2);
            var critical = parseFloat(f.critical_level || 0).toFixed(2);
            var reorder = parseFloat(f.reorder_level || 0).toFixed(2);
            var lastUpd = f.last_updated ? f.last_updated : '—';
            var availCap = Math.max(0, parseFloat(capacity) - parseFloat(curStock)).toFixed(2);

            // Populate Overview Card
            var el = document.getElementById('adm_viewFuelType'); if (el) el.textContent = fName;
            el = document.getElementById('adm_viewUgtNo'); if (el) el.textContent = ugt;
            el = document.getElementById('adm_viewCurrentPrice'); if (el) el.textContent = '₱' + curPrice;
            el = document.getElementById('adm_viewStock'); if (el) el.textContent = parseFloat(curStock).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
            el = document.getElementById('adm_viewAvailableCapacity'); if (el) el.textContent = parseFloat(availCap).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
            el = document.getElementById('adm_viewCapacity'); if (el) el.textContent = parseFloat(capacity).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
            el = document.getElementById('adm_viewCriticalLevel'); if (el) el.textContent = parseFloat(critical).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';
            el = document.getElementById('adm_viewReorderLevel'); if (el) el.textContent = parseFloat(reorder).toLocaleString(undefined, {minimumFractionDigits: 2}) + ' L';

            var stock = parseFloat(curStock);
            var crit = parseFloat(critical);
            var stockStatusText = '<span style="color:#16a34a;font-weight:700;">Normal</span>';
            if (stock <= 0) stockStatusText = '<span style="color:#dc2626;font-weight:700;">Out of Stock</span>';
            else if (stock <= crit) stockStatusText = '<span style="color:#dc2626;font-weight:700;">Critical</span>';
            el = document.getElementById('adm_viewStatus'); if (el) el.innerHTML = stockStatusText;

            var prodStatus = (f.status || 'active').toLowerCase();
            var prodBadge = prodStatus === 'active'
                ? '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:12px;font-size:14px;font-weight:700;">Active</span>'
                : '<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:12px;font-size:14px;font-weight:700;">Inactive</span>';
            el = document.getElementById('adm_viewProductStatus'); if (el) el.innerHTML = prodBadge;

            el = document.getElementById('adm_viewPriceRequestStatus'); if (el) el.textContent = (req && req.status === 'pending') ? 'Pending Admin Approval' : 'No Pending Request';
            el = document.getElementById('adm_viewLastUpdated'); if (el) el.textContent = lastUpd;
            el = document.getElementById('adm_viewUpdatedBy'); if (el) el.textContent = f.updated_by_name || f.last_updated_by || 'Admin';

            // Populate Assigned Pumps Section (from Fuel Management)
            var pumps = data.pumps || [];
            if (vpBadge) vpBadge.textContent = pumps.length + (pumps.length === 1 ? ' Pump Assigned' : ' Pumps Assigned');
            if (vpCont) {
                if (pumps.length === 0) {
                    vpCont.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:12px;color:#64748b;font-size:13px;font-style:italic;background:#fff;border-radius:6px;border:1px dashed #cbd5e1;"><i class="fas fa-info-circle"></i> No pumps currently assigned to this fuel product in Fuel Management.</div>';
                } else {
                    vpCont.innerHTML = pumps.map(function(pm) {
                        var isAct = (pm.status || 'Active').toLowerCase() === 'active';
                        var bgBadge = isAct ? '#dcfce7' : '#fee2e2';
                        var txtBadge = isAct ? '#166534' : '#991b1b';
                        var borderBadge = isAct ? '#86efac' : '#fca5a5';
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
                            '<span style="background:' + bgBadge + ';color:' + txtBadge + ';border:1px solid ' + borderBadge + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px;white-space:nowrap;">' +
                                (isAct ? 'Active' : 'Inactive') +
                            '</span>' +
                        '</div>';
                    }).join('');
                }
            }

            // Pending request banner
            if (pendingCont) {
                if (req && req.status === 'pending') {
                    var oldP = parseFloat(req.old_price || req.old_value || curPrice).toFixed(2);
                    var newP = parseFloat(req.new_price || req.new_value || 0).toFixed(2);
                    var diffVal = (newP - oldP).toFixed(2);
                    var diffBadge = diffVal > 0 
                        ? '<span style="color:#16a34a;font-weight:700;">+₱' + diffVal + '/L</span>'
                        : '<span style="color:#dc2626;font-weight:700;">-₱' + Math.abs(diffVal).toFixed(2) + '/L</span>';
                    var reqBy = req.requested_by_name || 'Manager';
                    var reasonText = req.reason || 'Price change requested by Manager';

                    pendingCont.innerHTML = `
                        <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:16px;margin-bottom:20px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                                <h4 style="margin:0;font-size:14px;color:#92400e;font-weight:800;display:flex;align-items:center;gap:8px;">
                                    <i class="fas fa-clock" style="color:#d97706;"></i> PENDING PRICE CHANGE REQUEST
                                </h4>
                                <span style="background:#fef3c7;color:#92400e;font-size:14px;font-weight:700;padding:2px 8px;border-radius:4px;">Action Required</span>
                            </div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));gap:12px;font-size:15.5px;margin-bottom:14px;">
                                <div><strong style="display:block;font-size:14px;color:#92400e;">CURRENT PRICE</strong>₱${oldP}</div>
                                <div><strong style="display:block;font-size:14px;color:#92400e;">REQUESTED PRICE</strong><span style="font-weight:800;color:#16a34a;font-size:15px;">₱${newP}</span></div>
                                <div><strong style="display:block;font-size:14px;color:#92400e;">DIFFERENCE</strong>${diffBadge}</div>
                                <div><strong style="display:block;font-size:14px;color:#92400e;">REQUESTED BY</strong>${reqBy}</div>
                                <div><strong style="display:block;font-size:14px;color:#92400e;">DATE REQUESTED</strong>${(req.created_at||'').substring(0,16)}</div>
                            </div>
                            <div style="font-size:14.5px;color:#78350f;margin-bottom:14px;background:#fef3c7;padding:8px 12px;border-radius:6px;">
                                <strong>Reason:</strong> ${reasonText}
                            </div>
                            <div style="display:flex;justify-content:flex-end;gap:10px;">
                                <button type="button" onclick="closeViewFuelModalAdmin(); openApprovePriceModalAdmin(${req.id}, '${fName.replace(/'/g, "\\'")}', ${oldP}, ${newP})" style="background:#16a34a;color:#fff;border:none;padding:8px 18px;border-radius:6px;font-size:14.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-check"></i> Approve Request</button>
                                <button type="button" onclick="closeViewFuelModalAdmin(); openRejectPriceModalAdmin(${req.id}, '${fName.replace(/'/g, "\\'")}', ${oldP}, ${newP})" style="background:#dc2626;color:#fff;border:none;padding:8px 18px;border-radius:6px;font-size:14.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times"></i> Reject Request</button>
                            </div>
                        </div>
                    `;
                } else {
                    pendingCont.innerHTML = '';
                }
            }

            // Price History Rows
            if (pBody) {
                if (history.length === 0) {
                    pBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No price history available</td></tr>';
                } else {
                    pBody.innerHTML = history.map(function(h) {
                        var stBadge = (h.status === 'Approved' || h.status === 'approved')
                            ? '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:10px;font-size:13px;font-weight:700;white-space:nowrap;display:inline-block;">Approved</span>'
                            : '<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:10px;font-size:13px;font-weight:700;white-space:nowrap;display:inline-block;">Rejected</span>';
                        return `
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:10px 12px;color:#475569;white-space:nowrap;">${(h.created_at||'').substring(0,16)}</td>
                                <td style="padding:10px 12px;font-weight:700;color:#002F6C;white-space:nowrap;">₱${parseFloat(h.new_price||0).toFixed(2)}</td>
                                <td style="padding:10px 12px;word-break:break-word;">${h.requested_by_name || 'Manager'}</td>
                                <td style="padding:10px 12px;word-break:break-word;">${h.approved_by_name || 'Admin'}</td>
                                <td style="padding:10px 12px;white-space:nowrap;">${stBadge}</td>
                            </tr>
                        `;
                    }).join('');
                }
            }

            // Config History Rows
            if (cBody) {
                if (configHist.length === 0) {
                    cBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No configuration changes recorded yet</td></tr>';
                } else {
                    cBody.innerHTML = configHist.map(function(c) {
                        return `
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:10px 12px;color:#475569;white-space:nowrap;">${(c.created_at||'').substring(0,16)}</td>
                                <td style="padding:10px 12px;font-weight:700;color:#002F6C;word-break:break-word;">${c.field_name || '-'}</td>
                                <td style="padding:10px 12px;color:#dc2626;font-weight:600;word-break:break-word;">${c.old_value || '-'}</td>
                                <td style="padding:10px 12px;color:#16a34a;font-weight:700;word-break:break-word;">${c.new_value || '-'}</td>
                                <td style="padding:10px 12px;word-break:break-word;">${c.updated_by_name || 'Manager'}</td>
                            </tr>
                        `;
                    }).join('');
                }
            }

            // Status History Rows
            if (sBody) {
                if (statusHist.length === 0) {
                    sBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No status history recorded yet</td></tr>';
                } else {
                    sBody.innerHTML = statusHist.map(function(s) {
                        var oldSt = (s.old_status || (s.status === 'Activated' ? 'Inactive' : (s.status === 'Deactivated' ? 'Active' : 'Active'))).toLowerCase();
                        var newSt = (s.new_status || (s.status === 'Deactivated' ? 'Inactive' : (s.status === 'Activated' ? 'Active' : 'Inactive'))).toLowerCase();
                        var oldBadge = oldSt === 'active'
                            ? '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:10px;font-size:13px;font-weight:700;white-space:nowrap;display:inline-block;">Active</span>'
                            : '<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:10px;font-size:13px;font-weight:700;white-space:nowrap;display:inline-block;">Inactive</span>';
                        var newBadge = newSt === 'active'
                            ? '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:10px;font-size:13px;font-weight:700;white-space:nowrap;display:inline-block;">Active</span>'
                            : '<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:10px;font-size:13px;font-weight:700;white-space:nowrap;display:inline-block;">Inactive</span>';
                        var reasonTxt = s.reason ? s.reason : '-';
                        return `
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:10px 12px;color:#475569;white-space:nowrap;">${(s.created_at||'').substring(0,16)}</td>
                                <td style="padding:10px 12px;white-space:nowrap;">${oldBadge}</td>
                                <td style="padding:10px 12px;white-space:nowrap;">${newBadge}</td>
                                <td style="padding:10px 12px;color:#64748b;word-break:break-word;">${reasonTxt}</td>
                                <td style="padding:10px 12px;word-break:break-word;">${s.changed_by_name || 'Manager'}</td>
                            </tr>
                        `;
                    }).join('');
                }
            }
        })
        .catch(function(err) {
            showCustomAlert('Could not load fuel details: ' + (err.message || err), 'error');
        });
}

function closeViewFuelModalAdmin() {
    var modal = document.getElementById('viewFuelModalAdmin');
    if (modal) modal.style.display = 'none';
}

function editFuelFromViewAdmin() {
    if (!_currentAdminViewFuel) return;
    var f = _currentAdminViewFuel;
    closeViewFuelModalAdmin();
    var fName = (f.raw_fuel_type || f.fuel_type || 'Fuel').replace(/\s*\(UGT\s*#?\d+\)/gi, '').trim();
    openEditPriceModalAdmin(f.id, fName, f.price_per_liter, f.capacity, f.critical_level, f.reorder_level, f.ugt_no, false);
}

// Global helper: smart default based on tank capacity (mirrors PHP fallback)
function adminSmartDefault(val, cap, isReorder) {
    var v = parseFloat(val);
    if (!isNaN(v) && v > 0) return v;
    var c = parseFloat(cap) || 0;
    if (isReorder) {
        return (c === 14000) ? 5000 : ((c === 7000) ? 2000 : parseFloat((c * 0.20).toFixed(2)));
    } else {
        return (c === 14000) ? 2500 : ((c === 7000) ? 1000 : parseFloat((c * 0.10).toFixed(2)));
    }
}

function getCleanCanonicalFuelName(name) {
    if (!name) return '';
    return String(name).replace(/\s*\(UGT\s*#?[^)]*\)/gi, '').trim();
}

function openEditPriceModalAdmin(id, fuelName, currentPrice, capacity, critical, reorder, ugtNo, hasPending) {
    // Pre-fill form fields immediately from inline PHP values
    if (document.getElementById('aef_fuel_id')) document.getElementById('aef_fuel_id').value = id;
    var cleanFuelName = getCleanCanonicalFuelName(fuelName);
    if (document.getElementById('aef_ugt_no')) document.getElementById('aef_ugt_no').value = ugtNo || '';
    if (document.getElementById('aef_fuel_name')) document.getElementById('aef_fuel_name').value = cleanFuelName;
    if (document.getElementById('aef_capacity')) document.getElementById('aef_capacity').value = parseFloat(capacity) || '';
    if (document.getElementById('aef_price')) document.getElementById('aef_price').value = parseFloat(currentPrice || 0).toFixed(2);
    if (document.getElementById('aef_critical')) document.getElementById('aef_critical').value = adminSmartDefault(critical, capacity, false);
    if (document.getElementById('aef_reorder')) document.getElementById('aef_reorder').value = adminSmartDefault(reorder, capacity, true);

    // Admin can always edit price directly
    var priceInput  = document.getElementById('aef_price');
    var priceNotice = document.getElementById('aef_price_notice');
    if (priceInput) {
        priceInput.removeAttribute('readonly');
        priceInput.style.background  = '';
        priceInput.style.borderColor = '';
        priceInput.style.color       = '';
        priceInput.style.cursor      = '';
    }
    if (priceNotice) priceNotice.style.display = 'none';

    var modal = document.getElementById('editPriceModalAdmin');
    if (modal) modal.style.display = 'flex';

    var pumpBadge = document.getElementById('aef_pump_count_badge');
    if (pumpBadge) pumpBadge.textContent = '...';
    var pumpCont = document.getElementById('aef_pumps_container');
    if (pumpCont) {
        pumpCont.innerHTML = '<div style="grid-column:1/-1;color:#64748b;font-size:13px;font-style:italic;padding:8px 0;"><i class="fas fa-spinner fa-spin"></i> Loading pump configuration...</div>';
    }

    // Fetch fresh live values from DB to overwrite with accurate data
    if (parseInt(id) > 0) {
        fetch('admin_set_prices_handler.php?action=get_fuel_details_admin&id=' + parseInt(id))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success || !data.fuel) return;
                var f   = data.fuel;
                var cap = parseFloat(f.capacity || capacity || 0);

                if (document.getElementById('aef_ugt_no')) document.getElementById('aef_ugt_no').value = f.ugt_no || ugtNo || '';
                var rawName = getCleanCanonicalFuelName(f.fuel_type || f.raw_fuel_type || fuelName || '');
                if (document.getElementById('aef_fuel_name')) document.getElementById('aef_fuel_name').value = rawName;

                // Overwrite capacity
                document.getElementById('aef_capacity').value = cap || '';

                // Overwrite price
                var livePrice = parseFloat(f.price_per_liter);
                document.getElementById('aef_price').value = (isNaN(livePrice) ? parseFloat(currentPrice || 0) : livePrice).toFixed(2);

                // Overwrite critical level — DB first, smart default as fallback
                document.getElementById('aef_critical').value = adminSmartDefault(f.critical_level, cap, false);

                // Overwrite reorder level — DB first, smart default as fallback
                document.getElementById('aef_reorder').value  = adminSmartDefault(f.reorder_level, cap, true);

                // Sync status radio buttons
                var liveStatus  = (f.status || 'active').toLowerCase();
                var radActive   = document.getElementById('aef_status_active');
                var radInactive = document.getElementById('aef_status_inactive');
                if (radActive && radInactive) {
                    radActive.checked   = (liveStatus === 'active');
                    radInactive.checked = (liveStatus !== 'active');
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
                            var safeMeterLabel = meterLabel.replace(/"/g, '&quot;');
                            
                            pHtml += '<div id="pump_card_' + pm.id + '" style="background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 2px rgba(0,0,0,0.03);gap:8px;">' +
                                '<div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1 1 auto;">' +
                                    '<div style="width:26px;height:26px;border-radius:5px;background:#e0f2fe;color:#002F6C;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;">' +
                                        '<i class="fas fa-gas-pump"></i>' +
                                    '</div>' +
                                    '<div id="pump_name_display_' + pm.id + '" style="font-weight:800;color:#0f172a;font-size:13px;line-height:1.2;white-space:nowrap;letter-spacing:0.2px;overflow:hidden;text-overflow:ellipsis;" title="' + safeMeterLabel + '">' +
                                        meterLabel +
                                    '</div>' +
                                    '<input type="text" id="pump_name_input_' + pm.id + '" name="edit_pump_name[' + pm.id + ']" value="' + safeMeterLabel + '" style="display:none;font-weight:700;color:#002F6C;font-size:12px;padding:2px 6px;border:1.5px solid #002F6C;border-radius:4px;width:125px;height:24px;box-sizing:border-box;outline:none;" placeholder="Pump/Tanker Name..." onblur="finishInlinePumpNameEdit(' + pm.id + ')" onkeydown="if(event.key===\'Enter\'){event.preventDefault();finishInlinePumpNameEdit(' + pm.id + ');}else if(event.key===\'Escape\'){event.preventDefault();cancelInlinePumpNameEdit(' + pm.id + ');}">' +
                                '</div>' +
                                '<div style="display:flex;align-items:center;gap:5px;flex-shrink:0;">' +
                                    '<button type="button" class="btn-edit-pump-name" id="btn_edit_pump_' + pm.id + '" onclick="startInlinePumpNameEdit(' + pm.id + ')" title="Edit Tanker / Pump Name" style="width:26px !important;height:26px !important;min-width:26px !important;border:1.5px solid #94a3b8 !important;background:#ffffff !important;background-color:#ffffff !important;background-image:none !important;color:#002F6C !important;-webkit-text-fill-color:#002F6C !important;border-radius:5px !important;cursor:pointer !important;display:inline-flex !important;align-items:center !important;justify-content:center !important;padding:0 !important;box-shadow:0 1px 2px rgba(0,0,0,0.06) !important;transition:all 0.15s ease !important;" onmouseover="this.style.setProperty(\'background\',\'#e0f2fe\',\'important\');this.style.setProperty(\'background-color\',\'#e0f2fe\',\'important\');this.style.setProperty(\'border-color\',\'#002F6C\',\'important\');" onmouseout="this.style.setProperty(\'background\',\'#ffffff\',\'important\');this.style.setProperty(\'background-color\',\'#ffffff\',\'important\');this.style.setProperty(\'border-color\',\'#94a3b8\',\'important\');">' +
                                        '<i class="fas fa-edit" style="font-size:12px !important;color:#002F6C !important;-webkit-text-fill-color:#002F6C !important;pointer-events:none;"></i>' +
                                    '</button>' +
                                    '<select name="edit_pump_status[' + pm.id + ']" style="font-size:11.5px !important;font-weight:700 !important;padding:2px 8px !important;border-radius:4px !important;border:1px solid ' + brdCol + ' !important;background:' + bgCol + ' !important;background-color:' + bgCol + ' !important;color:' + txtCol + ' !important;cursor:pointer;flex-shrink:0;height:24px !important;line-height:1.2 !important;" onchange="this.style.setProperty(\'background\', this.value === \'Active\' ? \'#dcfce7\' : \'#fee2e2\', \'important\'); this.style.setProperty(\'background-color\', this.value === \'Active\' ? \'#dcfce7\' : \'#fee2e2\', \'important\'); this.style.setProperty(\'color\', this.value === \'Active\' ? \'#166534\' : \'#991b1b\', \'important\'); this.style.setProperty(\'border-color\', this.value === \'Active\' ? \'#86efac\' : \'#fca5a5\', \'important\');">' +
                                        '<option value="Active"' + (isAct ? ' selected' : '') + '>Active</option>' +
                                        '<option value="Inactive"' + (!isAct ? ' selected' : '') + '>Inactive</option>' +
                                    '</select>' +
                                '</div>' +
                            '</div>';
                        });
                        pumpCont.innerHTML = pHtml;
                    }
                }
            })
            .catch(function() { /* keep inline values on network error */ });
    }
}

function closeEditPriceModalAdmin() {
    var modal = document.getElementById('editPriceModalAdmin');
    if (modal) modal.style.display = 'none';
    var pumpCont = document.getElementById('aef_pumps_container');
    if (pumpCont) pumpCont.innerHTML = '';
}

window.startInlinePumpNameEdit = function(pumpId) {
    var displayEl = document.getElementById('pump_name_display_' + pumpId);
    var inputEl   = document.getElementById('pump_name_input_' + pumpId);
    var editBtn   = document.getElementById('btn_edit_pump_' + pumpId);

    if (!displayEl || !inputEl) return;

    if (inputEl.style.display === 'none' || !inputEl.style.display) {
        inputEl.dataset.originalVal = inputEl.value.trim();
        displayEl.style.display = 'none';
        inputEl.style.display = 'inline-block';
        inputEl.focus();
        inputEl.select();
        if (editBtn) {
            editBtn.style.setProperty('background', '#e0f2fe', 'important');
            editBtn.style.setProperty('background-color', '#e0f2fe', 'important');
            editBtn.style.setProperty('border-color', '#002F6C', 'important');
        }
    } else {
        finishInlinePumpNameEdit(pumpId);
    }
};

window.finishInlinePumpNameEdit = function(pumpId) {
    var displayEl = document.getElementById('pump_name_display_' + pumpId);
    var inputEl   = document.getElementById('pump_name_input_' + pumpId);
    var editBtn   = document.getElementById('btn_edit_pump_' + pumpId);

    if (!displayEl || !inputEl) return;

    var origVal = inputEl.dataset.originalVal || displayEl.textContent.trim();
    var newName = inputEl.value.trim();
    if (!newName) {
        newName = origVal || ('Pump #' + pumpId);
        inputEl.value = newName;
    }

    displayEl.textContent = newName;
    displayEl.title = newName;
    displayEl.style.display = 'block';
    inputEl.style.display = 'none';

    if (editBtn) {
        editBtn.style.setProperty('background', '#ffffff', 'important');
        editBtn.style.setProperty('background-color', '#ffffff', 'important');
        editBtn.style.setProperty('border-color', '#94a3b8', 'important');
    }
};

window.cancelInlinePumpNameEdit = function(pumpId) {
    var displayEl = document.getElementById('pump_name_display_' + pumpId);
    var inputEl   = document.getElementById('pump_name_input_' + pumpId);
    var editBtn   = document.getElementById('btn_edit_pump_' + pumpId);

    if (displayEl && inputEl) {
        var origVal = inputEl.dataset.originalVal || displayEl.textContent.trim();
        inputEl.value = origVal;
        displayEl.style.display = 'block';
        inputEl.style.display = 'none';
    }
    if (editBtn) {
        editBtn.style.setProperty('background', '#ffffff', 'important');
        editBtn.style.setProperty('background-color', '#ffffff', 'important');
        editBtn.style.setProperty('border-color', '#94a3b8', 'important');
    }
};

function validateEditFuelForm() {
    // Sync any open inline pump name inputs before form submission
    document.querySelectorAll('[id^="pump_name_input_"]').forEach(function(inp) {
        var pid = inp.id.replace('pump_name_input_', '');
        if (inp.style.display !== 'none') {
            finishInlinePumpNameEdit(pid);
        }
    });
    const nameEl = document.getElementById('aef_fuel_name');
    const pEl  = document.getElementById('aef_price');
    const cEl  = document.getElementById('aef_capacity');
    const crEl = document.getElementById('aef_critical');
    const rEl  = document.getElementById('aef_reorder');

    if (nameEl && !nameEl.value.trim()) {
        showCustomAlert('Fuel product name cannot be empty.', 'warning');
        nameEl.focus();
        return false;
    }

    const p  = parseFloat(pEl?.value || 0);
    const c  = parseFloat(cEl?.value || 0);
    const cr = parseFloat(crEl?.value || 0);
    const r  = parseFloat(rEl?.value || 0);

    if (isNaN(p) || p <= 0) {
        showCustomAlert('Price / Liter must be a valid positive number greater than 0.', 'warning');
        if (pEl) pEl.focus();
        return false;
    }
    if (isNaN(c) || c <= 0) {
        showCustomAlert('Tank Capacity must be a valid positive number greater than 0.', 'warning');
        if (cEl) cEl.focus();
        return false;
    }
    if (isNaN(cr) || cr <= 0) {
        showCustomAlert('Critical Level must be a valid positive number greater than 0.', 'warning');
        if (crEl) crEl.focus();
        return false;
    }
    if (cr >= c) {
        showCustomAlert('Critical Level cannot be greater than or equal to Tank Capacity (' + c + ' L).', 'error');
        if (crEl) crEl.focus();
        return false;
    }
    if (isNaN(r) || r <= 0) {
        showCustomAlert('Reorder Level must be a valid positive number greater than 0.', 'warning');
        if (rEl) rEl.focus();
        return false;
    }
    if (r >= c) {
        showCustomAlert('Reorder Level cannot be greater than or equal to Tank Capacity (' + c + ' L).', 'error');
        if (rEl) rEl.focus();
        return false;
    }
    return true;
}

function openRejectPriceModalAdmin(approvalId, productName, oldPrice, newPrice, tab) {
    tab = tab || 'fuel';
    document.getElementById('adminRejectApprovalId').value = approvalId;
    var tabInput = document.getElementById('adminRejectActiveTab');
    if (tabInput) tabInput.value = tab;
    document.getElementById('adminRejectProdName').textContent = productName;
    document.getElementById('adminRejectOldPrice').textContent = '₱' + parseFloat(oldPrice).toFixed(2);
    document.getElementById('adminRejectNewPrice').textContent = '₱' + parseFloat(newPrice).toFixed(2);
    document.getElementById('adminRejectRemarks').value = '';
    document.getElementById('rejectPriceModalAdmin').style.display = 'flex';
}

function closeRejectPriceModalAdmin() {
    document.getElementById('rejectPriceModalAdmin').style.display = 'none';
}

function openToggleFuelStatusModal(id, newStatus, fuelName) {
    var modal = document.getElementById('toggleFuelStatusModal');
    document.getElementById('toggleFuelStatusId').value = id;
    document.getElementById('toggleFuelStatusValue').value = newStatus;
    document.getElementById('toggleFuelStatusName').textContent = fuelName;
    var isDeactivate = (newStatus === 'inactive');
    var header = document.getElementById('toggleFuelStatusHeader');
    var icon = document.getElementById('toggleFuelStatusIcon');
    var titleEl = document.getElementById('toggleFuelStatusTitle');
    var descEl = document.getElementById('toggleFuelStatusDesc');
    var confirmBtn = document.getElementById('toggleFuelStatusConfirmBtn');
    if (isDeactivate) {
        if (header) header.style.background = 'linear-gradient(135deg,#dc2626,#b91c1c)';
        if (icon) icon.innerHTML = '<i class="fas fa-ban" style="font-size:24px;color:#fff;"></i>';
        if (titleEl) titleEl.textContent = 'Deactivate Fuel Product';
        if (descEl) descEl.innerHTML = 'You are about to set <strong style="color:#0f172a;">' + fuelName + '</strong> to <strong style="color:#dc2626;">Inactive</strong>. This will prevent it from being used in transactions until reactivated.';
        if (confirmBtn) {
            confirmBtn.style.setProperty('background', '#dc2626', 'important');
            confirmBtn.style.setProperty('color', '#ffffff', 'important');
            confirmBtn.innerHTML = '<i class="fas fa-ban" style="color:#ffffff !important;"></i> Confirm Deactivation';
        }
    } else {
        if (header) header.style.background = 'linear-gradient(135deg,#16a34a,#15803d)';
        if (icon) icon.innerHTML = '<i class="fas fa-check-circle" style="font-size:24px;color:#fff;"></i>';
        if (titleEl) titleEl.textContent = 'Activate Fuel Product';
        if (descEl) descEl.innerHTML = 'You are about to set <strong style="color:#0f172a;">' + fuelName + '</strong> to <strong style="color:#16a34a;">Active</strong>. It will be available for transactions.';
        if (confirmBtn) {
            confirmBtn.style.setProperty('background', '#16a34a', 'important');
            confirmBtn.style.setProperty('color', '#ffffff', 'important');
            confirmBtn.innerHTML = '<i class="fas fa-check-circle" style="color:#ffffff !important;"></i> Confirm Activation';
        }
    }
    modal.style.display = 'flex';
}

function closeToggleFuelStatusModal() {
    document.getElementById('toggleFuelStatusModal').style.display = 'none';
}

function confirmToggleFuelStatus() {
    var id     = document.getElementById('toggleFuelStatusId').value;
    var status = document.getElementById('toggleFuelStatusValue').value;
    var btn    = document.getElementById('toggleFuelStatusConfirmBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }
    var fd = new FormData();
    fd.append('action', 'toggle_fuel_status_admin');
    fd.append('id', id);
    fd.append('status', status);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            closeToggleFuelStatusModal();
            if (data.success) {
                showCustomAlert(data.message || 'Fuel product status updated successfully.', 'success', function() {
                    location.reload();
                });
            } else {
                showCustomAlert(data.message || 'Failed to update fuel status.', 'error');
            }
        })
        .catch(function() {
            closeToggleFuelStatusModal();
            showCustomAlert('Network error while updating fuel status.', 'error');
        })
        .finally(function() {
            if (btn) {
                btn.disabled = false;
                var isDeact = (status === 'inactive');
                btn.style.setProperty('background', isDeact ? '#dc2626' : '#16a34a', 'important');
                btn.innerHTML = isDeact ? '<i class="fas fa-ban" style="color:#ffffff !important;"></i> Confirm Deactivation' : '<i class="fas fa-check-circle" style="color:#ffffff !important;"></i> Confirm Activation';
            }
        });
}

function openApprovePriceModalAdmin(approvalId, productName, oldPrice, newPrice, tab) {
    tab = tab || 'fuel';
    document.getElementById('adminApproveApprovalId').value = approvalId;
    document.getElementById('adminApproveActiveTab').value = tab;
    document.getElementById('adminApproveProdName').textContent = productName;
    document.getElementById('adminApproveOldPrice').textContent = '\u20b1' + parseFloat(oldPrice).toFixed(2);
    document.getElementById('adminApproveNewPrice').textContent = '\u20b1' + parseFloat(newPrice).toFixed(2);
    var diff = parseFloat(newPrice) - parseFloat(oldPrice);
    var diffEl = document.getElementById('adminApproveDiff');
    diffEl.textContent = (diff >= 0 ? '+' : '') + '\u20b1' + diff.toFixed(2);
    diffEl.style.color = diff >= 0 ? '#16a34a' : '#dc2626';
    document.getElementById('approvePriceModalAdmin').style.display = 'flex';
}

function closeApprovePriceModalAdmin() {
    document.getElementById('approvePriceModalAdmin').style.display = 'none';
}

// ── Tab Switching ─────────────────────────────────────────────────────────
// ── Export Product & Pricing Report ─────────────────────────────────────────
function exportPricing(format) {
    const activeTab = document.getElementById('activeSection')?.value || 'fuel';
    let q = '', st = '', cat = '', brd = '';
    if (activeTab === 'fuel') {
        q  = encodeURIComponent(document.getElementById('adminFuelSearch')?.value || document.getElementById('fuelSearchInput')?.value || '');
        st = encodeURIComponent(document.getElementById('adminFuelStatusFilter')?.value || document.getElementById('fuelStatusFilter')?.value || '');
    } else if (activeTab === 'merch') {
        q   = encodeURIComponent(document.getElementById('adminSearchInput')?.value || document.getElementById('merchSearchInput')?.value || '');
        st  = encodeURIComponent(document.getElementById('adminProdStatusFilter')?.value || document.getElementById('statusFilter')?.value || '');
        cat = encodeURIComponent(document.getElementById('adminCatFilter')?.value || document.getElementById('catFilter')?.value || '');
        brd = encodeURIComponent(document.getElementById('adminBrandFilter')?.value || document.getElementById('brandFilter')?.value || '');
    } else if (activeTab === 'services') {
        q   = encodeURIComponent(document.getElementById('svcSearchInput')?.value || '');
        st  = encodeURIComponent(document.getElementById('svcStatusFilter')?.value || '');
        cat = encodeURIComponent(document.getElementById('serviceCategoryFilter')?.value || '');
    }
    const url = `export_pricing_products.php?tab=${activeTab}&format=${format}&station_id=<?php echo (int)$station_id; ?>&q=${q}&status=${st}&category=${cat}&brand=${brd}&_ts=${Date.now()}`;

    if (format === 'print') {
        // Direct print on current page — no new tab or popup
        // Fetch the print-format HTML and inject into #report-print-root, then call window.print()
        var printUrl = 'export_pricing_products.php?tab=' + activeTab + '&format=print&station_id=<?php echo (int)$station_id; ?>&q=' + q + '&status=' + st + '&category=' + cat + '&brand=' + brd + '&_ts=' + Date.now();
        fetch(printUrl, { credentials: 'same-origin' })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                // Remove auto-print scripts from the fetched page
                doc.querySelectorAll('script').forEach(function(s) { s.remove(); });

                // Remove any existing print root and print styles
                var existing = document.getElementById('report-print-root');
                if (existing) existing.remove();
                var existingPs = document.getElementById('pricing-print-styles');
                if (existingPs) existingPs.remove();

                // Inject fetched content into report-print-root
                var printRoot = document.createElement('div');
                printRoot.id = 'report-print-root';
                printRoot.innerHTML = doc.body ? doc.body.innerHTML : html;
                document.body.appendChild(printRoot);

                // Inject print styles from the fetched page
                var styleContent = '';
                doc.querySelectorAll('style').forEach(function(s) { styleContent += s.textContent; });
                if (styleContent) {
                    var styleEl = document.createElement('style');
                    styleEl.id = 'pricing-print-styles';
                    styleEl.textContent = styleContent;
                    document.head.appendChild(styleEl);
                }

                // Use report-printing class (footer.php handles: hide all UI, show only #report-print-root)
                document.body.classList.add('report-printing');
                var cleanup = function() {
                    document.body.classList.remove('report-printing');
                    var n = document.getElementById('report-print-root');
                    if (n) n.remove();
                    var ps = document.getElementById('pricing-print-styles');
                    if (ps) ps.remove();
                    window.removeEventListener('afterprint', cleanup);
                };
                window.addEventListener('afterprint', cleanup);
                window.print();
                setTimeout(cleanup, 1500);
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

function switchTab(tabName) {
    if (['fuel', 'merch', 'services'].indexOf(tabName) === -1) tabName = 'fuel';

    document.querySelectorAll('.tab-panel').forEach(function(panel) {
        panel.classList.remove('active');
    });
    document.querySelectorAll('.ato-tab').forEach(function(btn) {
        btn.classList.remove('active');
    });
    var panel = document.getElementById('tab-' + tabName);
    if (panel) panel.classList.add('active');
    var btn = document.getElementById('tab-btn-' + tabName);
    if (btn) btn.classList.add('active');
    var hidden = document.getElementById('activeSection');
    if (hidden) hidden.value = tabName;

    // Update URL without reloading so refresh lands on the same tab
    try {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tabName);
        window.history.replaceState(null, '', url.toString());
    } catch (e) {}

    // Persist in sessionStorage
    try {
        sessionStorage.setItem('petron_admin_active_tab', tabName);
    } catch (e) {}
}

(function() {
    var urlParams = new URLSearchParams(window.location.search);
    var tabFromUrl = urlParams.get('tab');

    // Only restore saved tab if there is an explicit ?tab= in the URL.
    // On fresh sidebar navigation (no tab param), always open the default 'fuel' tab.
    if (tabFromUrl && ['fuel', 'merch', 'services'].indexOf(tabFromUrl) !== -1) {
        switchTab(tabFromUrl);
    } else {
        switchTab('fuel');
    }
})();

// ══════════════════════════════════════════════════════════════════════════
// SAFE LISTENER HELPER
// ══════════════════════════════════════════════════════════════════════════
function safeAddListener(id, event, handler) {
    function tryAttach() {
        var el = document.getElementById(id);
        if (!el) return false;
        var key = '_sal_' + event;
        if (!el[key]) el[key] = [];
        if (el[key].indexOf(handler) === -1) {
            el.addEventListener(event, handler);
            el[key].push(handler);
        }
        return true;
    }
    if (!tryAttach()) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', tryAttach);
        } else {
            setTimeout(tryAttach, 0);
        }
    }
}

// Background overlay click listeners
safeAddListener('adminEditProductModal', 'click', function(e) { if (e.target === this) closeAdminEditProductModal(); });
safeAddListener('adminEditFuelModal', 'click', function(e) { if (e.target === this) closeAdminEditFuelModal(); });
safeAddListener('adminEditServiceModal', 'click', function(e) { if (e.target === this) closeAdminEditServiceModal(); });
safeAddListener('adminViewServiceModal', 'click', function(e) { if (e.target === this) closeAdminViewServiceModal(); });
safeAddListener('viewAdminServiceModal', 'click', function(e) { if (e.target === this) closeAdminViewServiceModal(); });
safeAddListener('viewRequestModal', 'click', function(e) { if (e.target === this) closeViewRequestModal(); });
safeAddListener('approveConfirmModal', 'click', function(e) { if (e.target === this) closeApproveConfirmModal(); });
safeAddListener('rejectReasonModal', 'click', function(e) { if (e.target === this) closeRejectReasonModal(); });
safeAddListener('priceHistoryModal', 'click', function(e) { if (e.target === this) closePriceHistoryModal(); });
safeAddListener('viewAdminMerchModal', 'click', function(e) { if (e.target === this) closeAdminViewMerchModal(); });
safeAddListener('viewAdminBatchesModal', 'click', function(e) { if (e.target === this) closeAdminBatchesModal(); });
safeAddListener('viewFuelModalAdmin', 'click', function(e) { if (e.target === this) closeViewFuelModalAdmin(); });
safeAddListener('editPriceModalAdmin', 'click', function(e) { if (e.target === this) closeEditPriceModalAdmin(); });
safeAddListener('rejectPriceModalAdmin', 'click', function(e) { if (e.target === this) closeRejectPriceModalAdmin(); });
safeAddListener('approvePriceModalAdmin', 'click', function(e) { if (e.target === this) closeApprovePriceModalAdmin(); });
safeAddListener('toggleFuelStatusModal', 'click', function(e) { if (e.target === this) closeToggleFuelStatusModal(); });
safeAddListener('toggleServiceStatusModal', 'click', function(e) { if (e.target === this) closeToggleServiceStatusModal(); });
safeAddListener('restoreServiceFeesModal', 'click', function(e) { if (e.target === this) closeRestoreServiceFeesModal(); });
safeAddListener('addProductModal', 'click', function(e) { if (e.target === this) closeAddProductModal(); });
safeAddListener('addMerchandiseModal', 'click', function(e) { if (e.target === this) closeAddMerchandiseModal(); });
safeAddListener('addServiceModal', 'click', function(e) { if (e.target === this) closeAddServiceModal(); });
safeAddListener('confirmationModal', 'click', function(e) { if (e.target === this) closeConfirmModal(); });

// ══════════════════════════════════════════════════════════════════════════
// ADMIN ADD FUEL PRODUCT MODAL & HANDLERS
// ══════════════════════════════════════════════════════════════════════════
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
    var modal = document.getElementById('addProductModal');
    if (modal) modal.style.display = 'flex';
    var ugtEl = document.getElementById('newUgtNo');
    if (ugtEl) ugtEl.value = '';
    var nameInput = document.getElementById('newFuelName');
    if (nameInput) {
        nameInput.value = '';
        setTimeout(function() { nameInput.focus(); }, 80);
    }
    var numPumpsEl = document.getElementById('newNumPumps');
    if (numPumpsEl) {
        numPumpsEl.value = '';
    }
}

function closeAddProductModal() {
    var modal = document.getElementById('addProductModal');
    if (modal) modal.style.display = 'none';
    var form = document.getElementById('addProductForm');
    if (form) form.reset();
    var ugtEl = document.getElementById('newUgtNo');
    if (ugtEl) ugtEl.value = '';
    var numPumpsEl = document.getElementById('newNumPumps');
    if (numPumpsEl) numPumpsEl.value = '';
}

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
    fd.append('station_id',     '<?php echo (int)$station_id; ?>');
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

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                closeAddProductModal();
                try {
                    sessionStorage.setItem('admin_flash_toast', JSON.stringify({ message: data.message || 'Fuel product added successfully!', type: 'success' }));
                } catch(e) {}
                showCustomAlert(data.message || 'Fuel product added successfully!', 'success', function() {
                    location.href = 'admin_set_prices.php?tab=fuel';
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
        .catch(function(err) {
            console.error('Add fuel product error:', err);
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

// ══════════════════════════════════════════════════════════════════════════
// ADMIN ADD MERCHANDISE MODAL & HANDLERS
// ══════════════════════════════════════════════════════════════════════════
function openAddMerchandiseModal() {
    var modal = document.getElementById('addMerchandiseModal');
    if (modal) modal.style.display = 'flex';
    var nameField = document.getElementById('newMerchName');
    if (nameField) setTimeout(function() { nameField.focus(); }, 80);
    var bs = document.getElementById('newMerchBarcodeStatus');
    if (bs) bs.innerHTML = '';
    var bf = document.getElementById('newMerchBarcode');
    if (bf) { bf.style.borderColor = '#d1d5db'; bf.value = ''; }
}

function closeAddMerchandiseModal() {
    var modal = document.getElementById('addMerchandiseModal');
    if (modal) modal.style.display = 'none';
    var form = document.getElementById('addMerchandiseForm');
    if (form) form.reset();
}

function activateBarcodeScan(inputId, context) {
    var el = document.getElementById(inputId);
    if (!el) return;
    el.focus();
    el.style.borderColor = '#f59e0b';
    el.style.background  = '#fffbeb';
    var st = document.getElementById('newMerchBarcodeStatus');
    if (st) st.innerHTML = '<span style="color:#d97706;font-size:13px;"><i class="fas fa-barcode"></i> Ready - scan now or type barcode</span>';
}

function handleBarcodeKeydown(event, context) {
    var el   = event.target;
    var key  = event.key || '';
    var code = event.keyCode || event.which;
    if (key === 'Enter' || code === 13) {
        event.preventDefault();
        event.stopPropagation();
        var barcodeVal = el.value.trim();
        if (barcodeVal.length === 0) return;
        el.style.borderColor = '#16a34a';
        el.style.background  = '#f0fdf4';
        var st = document.getElementById('newMerchBarcodeStatus');
        if (st) st.innerHTML = '<span style="color:#16a34a;font-size:13px;"><i class="fas fa-check-circle"></i> Barcode captured: ' + barcodeVal + '</span>';
    }
}

safeAddListener('addMerchandiseForm', 'submit', function(e) {
    e.preventDefault();

    var name     = (document.getElementById('newMerchName') || {}).value || '';
    name = name.trim();
    var category = (document.getElementById('newMerchCategory') || {}).value || '';
    category = category.trim();
    var price    = parseFloat((document.getElementById('newMerchPrice') || {}).value);
    var sku      = ((document.getElementById('newMerchSku') || {}).value || '').trim();
    var brand    = ((document.getElementById('newMerchBrand') || {}).value || '').trim();
    var size     = ((document.getElementById('newMerchSize') || {}).value || '').trim();
    var barcode  = ((document.getElementById('newMerchBarcode') || {}).value || '').trim();
    var reorder  = parseInt((document.getElementById('newMerchReorder') || {}).value) || 24;
    var critical = parseInt((document.getElementById('newMerchCritical') || {}).value) || 10;
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

    var btn = e.target.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...'; }

    var formData = new FormData();
    formData.append('action', 'add_merchandise');
    formData.append('station_id', '<?php echo (int)$station_id; ?>');
    formData.append('product_name', name);
    formData.append('category', category);
    formData.append('brand', brand);
    formData.append('unit_price', price);
    formData.append('unit_cost', 0);
    formData.append('sku', sku);
    formData.append('size', size);
    formData.append('barcode', barcode);
    formData.append('reorder_level', reorder);
    formData.append('critical_level', critical);
    formData.append('expiration_date', expiry);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: formData })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            closeAddMerchandiseModal();
            try {
                sessionStorage.setItem('admin_flash_toast', JSON.stringify({ message: data.message || 'Product added successfully!', type: 'success' }));
            } catch(e) {}
            showCustomAlert(data.message || 'Product added successfully!', 'success', function() {
                location.href = 'admin_set_prices.php?tab=merch';
            });
        } else {
            showCustomAlert(data.message || 'Failed to add product', 'error');
        }
    })
    .catch(function(err) {
        console.error('Add merchandise error:', err);
        showCustomAlert('Error adding product. Please try again.', 'error');
    })
    .finally(function() {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Add Product'; }
    });
});

// ══════════════════════════════════════════════════════════════════════════
// ADMIN MERCHANDISE DEACTIVATE / ACTIVATE & CONFIRMATION MODAL
// ══════════════════════════════════════════════════════════════════════════
var confirmModalCallback = null;
var confirmModalData = null;

function showConfirmModal(title, subtitle, message, callback, data) {
    confirmModalCallback = callback || null;
    confirmModalData = data || null;

    var isDeactivate = !title || title.toLowerCase().includes('deactivate');

    var titleEl = document.getElementById('confirmActionTitle');
    if (titleEl) titleEl.textContent = title || 'Confirm Action';

    var subEl = document.getElementById('confirmActionSubtitle');
    if (subEl) subEl.textContent = subtitle || 'Please confirm this action.';

    var headerEl = document.getElementById('confirmActionHeader');
    var iconEl = document.getElementById('confirmActionIcon');
    var btnEl = document.getElementById('confirmActionBtn');
    var btnIconEl = document.getElementById('confirmActionBtnIcon');
    var btnLabelEl = document.getElementById('confirmActionBtnLabel');

    if (isDeactivate) {
        if (headerEl) headerEl.style.background = 'linear-gradient(135deg, #dc2626, #b91c1c)';
        if (iconEl) iconEl.className = 'fas fa-ban';
        if (btnEl) {
            btnEl.style.setProperty('background', '#dc2626', 'important');
            btnEl.style.setProperty('box-shadow', '0 2px 6px rgba(220,38,38,0.35)', 'important');
        }
        if (btnIconEl) btnIconEl.className = 'fas fa-ban';
        if (btnLabelEl) btnLabelEl.textContent = 'Confirm Deactivation';
    } else {
        if (headerEl) headerEl.style.background = 'linear-gradient(135deg, #15803d, #166534)';
        if (iconEl) iconEl.className = 'fas fa-check-circle';
        if (btnEl) {
            btnEl.style.setProperty('background', '#16a34a', 'important');
            btnEl.style.setProperty('box-shadow', '0 2px 6px rgba(22,163,74,0.35)', 'important');
        }
        if (btnIconEl) btnIconEl.className = 'fas fa-check-circle';
        if (btnLabelEl) btnLabelEl.textContent = 'Confirm Activation';
    }

    var msgEl = document.getElementById('confirmActionMessage');
    if (msgEl) {
        var match = (message || '').match(/"([^"]+)"/);
        if (match && match[1]) {
            var actionVerb = isDeactivate ? 'deactivate' : 'activate';
            msgEl.innerHTML = 'Are you sure you want to ' + actionVerb + ' <strong style="color:#0f172a; font-weight:700;">"' + match[1] + '"</strong>?';
        } else {
            msgEl.textContent = (message || 'Are you sure you want to proceed?').replace(/\n\n.*$/, '');
        }
    }

    var modal = document.getElementById('confirmationModal');
    if (modal) {
        modal.style.cssText = 'display:flex !important; position:fixed !important; top:0 !important; left:0 !important; right:0 !important; bottom:0 !important; width:100vw !important; height:100vh !important; background:rgba(15,23,42,0.65) !important; backdrop-filter:blur(3px) !important; z-index:99999 !important; align-items:center !important; justify-content:center !important; padding:20px !important; box-sizing:border-box !important;';
    }
}

function closeConfirmModal() {
    var modal = document.getElementById('confirmationModal');
    if (modal) {
        modal.style.cssText = 'display:none !important;';
    }
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

function deactivateMerchandise(id, productName) {
    showConfirmModal(
        'Deactivate Merchandise Product',
        'Confirm deactivation',
        'Are you sure you want to deactivate "' + productName + '"?',
        function(data) {
            var formData = new FormData();
            formData.append('action', 'deactivate_merchandise');
            formData.append('id', data.id);
            
            fetch('admin_set_prices_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(res) {
                if (res.success) {
                    showCustomAlert(res.message || 'Product deactivated successfully!', 'success', function() {
                        location.reload();
                    });
                } else {
                    showCustomAlert(res.message || 'Failed to deactivate product', 'error');
                }
            })
            .catch(function() { showCustomAlert('Error deactivating product', 'error'); });
        },
        { id: id }
    );
}

function activateMerchandise(id, productName) {
    showConfirmModal(
        'Activate Merchandise Product',
        'Confirm activation',
        'Are you sure you want to activate "' + productName + '"?',
        function(data) {
            var formData = new FormData();
            formData.append('action', 'activate_merchandise');
            formData.append('id', data.id);
            
            fetch('admin_set_prices_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(res) {
                if (res.success) {
                    showCustomAlert(res.message || 'Product activated successfully!', 'success', function() {
                        location.reload();
                    });
                } else {
                    showCustomAlert(res.message || 'Failed to activate product', 'error');
                }
            })
            .catch(function() { showCustomAlert('Error activating product', 'error'); });
        },
        { id: id }
    );
}

// ══════════════════════════════════════════════════════════════════════════
// ADMIN ADD SERVICE MODAL & HANDLERS
// ══════════════════════════════════════════════════════════════════════════
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
    if (!modal) return;
    var form = document.getElementById('addServiceForm');
    if (form) form.reset();
    var catEl = document.getElementById('addSvcCategory');
    if (catEl) catEl.value = '';
    var mechEl = document.getElementById('addSvcMechanics');
    if (mechEl) mechEl.value = '';
    hideSvcCatDrop('add');
    modal.style.display = 'none';
}

safeAddListener('addServiceForm', 'submit', function(e) {
    e.preventDefault();

    var name     = ((document.getElementById('addSvcName') || {}).value || '').trim();
    var category = ((document.getElementById('addSvcCategory') || {}).value || '').trim();

    var svcFee      = parseFloat((document.getElementById('addSvcServiceFee') || {}).value) || 0;
    var laborFee    = parseFloat((document.getElementById('addSvcLaborFee')   || {}).value) || 0;
    var mechsVal    = ((document.getElementById('addSvcMechanics') || {}).value || '').trim();
    var mechs       = (mechsVal !== '' && !isNaN(parseInt(mechsVal))) ? parseInt(mechsVal) : 1;
    var desc        = (document.getElementById('addSvcDescription')  || {}).value || '';

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
    if (svcFee <= 0 || laborFee <= 0) {
        showCustomAlert('Service fee and labor fee must be greater than ₱0.00.', 'error');
        return;
    }

    var btn = e.target.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...'; }

    var fd = new FormData();
    fd.append('action',               'add_service');
    fd.append('station_id',           '<?php echo (int)$station_id; ?>');
    fd.append('service_name',         name);
    fd.append('category',             category);
    fd.append('service_price',        svcFee);
    fd.append('labor_fee',            laborFee);
    fd.append('required_mechanics',   mechs);
    fd.append('description',          desc);

    fetch('admin_set_prices_handler.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            closeAddServiceModal();
            try {
                sessionStorage.setItem('admin_flash_toast', JSON.stringify({ message: data.message || 'Service added successfully!', type: 'success' }));
            } catch(e) {}
            showCustomAlert(data.message || 'Service added successfully!', 'success', function() {
                location.href = 'admin_set_prices.php?tab=services';
            });
        } else {
            showCustomAlert(data.message || 'Failed to add service.', 'error');
        }
    })
    .catch(function(err) {
        console.error('Add service error:', err);
        showCustomAlert('Network error. Please try again.', 'error');
    })
    .finally(function() {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check-circle"></i> Add Service'; }
    });
});
</script>

<!-- Admin View Fuel Product & History Modal (Matches Manager Format & Dimensions Exactly) -->
<div id="viewFuelModalAdmin" class="admin-layout-modal" style="display:none;position:fixed;top:70px;left:250px;right:0;bottom:40px;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;overflow:hidden;">
    <div style="background:#fff;border-radius:12px;width:94%;max-width:1000px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:0 auto;overflow:hidden;max-height:calc(100% - 20px);display:flex;flex-direction:column;">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:14px 22px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <h3 style="margin:0;font-size:16.5px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;letter-spacing:0.3px;">
                <i class="fas fa-gas-pump" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:18px;"></i>
                <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">FUEL PRODUCT SPECIFICATION &amp; HISTORY</span>
            </h3>
        </div>

        <!-- Body Content -->
        <div id="adm_fuel_modal_body" style="padding:12px 24px 24px 24px;overflow-y:auto;overflow-x:hidden;flex:1 1 auto;background:#ffffff;min-height:0;box-sizing:border-box;width:100% !important;">
            <!-- Pending Price Change Request Banner -->
            <div id="adm_fuel_pending_container"></div>

            <!-- Fuel Specification & Overview -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:20px;margin-top:4px;width:100%;box-sizing:border-box;">
                <h4 style="margin:0 0 14px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
                    <i class="fas fa-info-circle" style="color:#002F6C;"></i> Fuel Specification &amp; Overview
                </h4>
                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;font-size:15.5px;width:100%;">
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Fuel Name</strong>
                        <span style="font-weight:700;color:#002F6C;font-size:14px;" id="adm_viewFuelType">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">UGT Number</strong>
                        <span style="font-weight:700;color:#1e293b;" id="adm_viewUgtNo">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Current Price / Liter</strong>
                        <span style="font-weight:700;color:#16a34a;font-size:14px;" id="adm_viewCurrentPrice">₱0.00</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Current Volume</strong>
                        <span style="font-weight:700;color:#002F6C;" id="adm_viewStock">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Available Capacity</strong>
                        <span style="font-weight:700;color:#2563eb;" id="adm_viewAvailableCapacity">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Tank Capacity</strong>
                        <span style="font-weight:600;color:#334155;" id="adm_viewCapacity">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Critical Level</strong>
                        <span style="font-weight:600;color:#dc2626;" id="adm_viewCriticalLevel">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Reorder Level</strong>
                        <span style="font-weight:600;color:#d97706;" id="adm_viewReorderLevel">0.00 L</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Stock Status</strong>
                        <span style="font-weight:600;" id="adm_viewStatus">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Product Status</strong>
                        <span style="font-weight:600;" id="adm_viewProductStatus">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Price Request Status</strong>
                        <span style="font-weight:600;color:#475569;" id="adm_viewPriceRequestStatus">No Pending Request</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Last Updated</strong>
                        <span style="font-size:14.5px;color:#475569;" id="adm_viewLastUpdated">-</span>
                    </div>
                    <div>
                        <strong style="display:block;font-size:14px;color:#64748b;text-transform:uppercase;margin-bottom:2px;">Updated By</strong>
                        <span style="font-size:14.5px;color:#475569;" id="adm_viewUpdatedBy">-</span>
                    </div>
                </div>
            </div>

            <!-- Assigned Fuel Pumps / Nozzles (Synced with Fuel Management) -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px;margin-bottom:20px;width:100%;box-sizing:border-box;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;border-bottom:1px solid #e2e8f0;padding-bottom:8px;">
                    <h4 style="margin:0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-gas-pump" style="color:#002F6C;"></i> Assigned Pumps &amp; Nozzles (Fuel Management)
                    </h4>
                    <span id="adm_view_pump_count_badge" style="background:#e0f2fe;color:#0369a1;padding:2px 10px;border-radius:10px;font-size:11.5px;font-weight:700;">
                        0 Pumps Assigned
                    </span>
                </div>
                <div id="adm_view_pumps_container" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:8px;">
                    <div style="grid-column:1/-1;color:#64748b;font-size:13px;font-style:italic;padding:6px 0;"><i class="fas fa-spinner fa-spin"></i> Loading assigned pumps...</div>
                </div>
            </div>

            <!-- Price Change History -->
            <div style="margin-bottom:20px;width:100%;box-sizing:border-box;">
                <h4 style="margin:0 0 10px 0;font-size:14px;color:#002F6C;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-history" style="color:#002F6C;"></i> Price Change History
                </h4>
                <div class="modal-table-wrap" style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;width:100%;box-sizing:border-box;">
                    <table style="width:100% !important;min-width:100% !important;table-layout:fixed;border-collapse:collapse;font-size:13.5px;">
                        <colgroup>
                            <col style="width:22%;">
                            <col style="width:14%;">
                            <col style="width:27%;">
                            <col style="width:24%;">
                            <col style="width:13%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:13px;letter-spacing:0.3px;">
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Effective Date</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Price</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Requested By</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Approved By</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="adm_priceHistoryBody">
                            <tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No price history available</td></tr>
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
                    <table style="width:100% !important;min-width:100% !important;table-layout:fixed;border-collapse:collapse;font-size:13.5px;">
                        <colgroup>
                            <col style="width:20%;">
                            <col style="width:20%;">
                            <col style="width:20%;">
                            <col style="width:20%;">
                            <col style="width:20%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:13px;letter-spacing:0.3px;">
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Date</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Field Changed</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Old Value</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">New Value</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="adm_configHistoryBody">
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
                    <table style="width:100% !important;min-width:100% !important;table-layout:fixed;border-collapse:collapse;font-size:13.5px;">
                        <colgroup>
                            <col style="width:20%;">
                            <col style="width:16%;">
                            <col style="width:16%;">
                            <col style="width:32%;">
                            <col style="width:16%;">
                        </colgroup>
                        <thead>
                            <tr style="background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:13px;letter-spacing:0.3px;">
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Date</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Old Status</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">New Status</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Reason</th>
                                <th style="padding:10px 12px;text-align:left;border-bottom:1.5px solid #cbd5e1;white-space:nowrap;">Changed By</th>
                            </tr>
                        </thead>
                        <tbody id="adm_statusHistoryBody">
                            <tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;">No status history recorded yet</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div style="display:flex;justify-content:flex-end;padding:14px 24px;border-top:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0;gap:10px;">
            <button type="button" id="adm_viewFuelEditBtn" onclick="editFuelFromViewAdmin()" style="background:#002F6C !important;color:#ffffff !important;border:none !important;padding:8px 20px;border-radius:6px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-edit"></i> Edit Product
            </button>
            <button type="button" onclick="closeViewFuelModalAdmin()" style="background:transparent !important;color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:8px 20px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Admin Edit Fuel Modal (Direct Update - Option A) -->
<div id="editPriceModalAdmin" style="display:none;position:fixed;top:0;left:250px;right:0;bottom:0;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:12px 16px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:12px;width:94%;max-width:690px;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:auto;overflow:hidden;">
    <div style="background:linear-gradient(135deg,#002F6C,#004494);padding:11px 20px;display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:15.5px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:8px;letter-spacing:0.3px;">
        <i class="fas fa-edit" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:16px;"></i>
        <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">EDIT FUEL PRODUCT</span>
      </h3>
    </div>
    <form method="POST" action="admin_set_prices.php" style="padding:12px 20px 12px 20px;" onsubmit="return validateEditFuelForm();">
      <input type="hidden" name="action" value="admin_edit_fuel_direct">
      <input type="hidden" name="active_tab" value="fuel">
      <input type="hidden" id="aef_fuel_id" name="id">

      <!-- Row 1: UGT Number + Fuel Name -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:7px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">UGT Number</label>
          <input type="text" id="aef_ugt_no" name="ugt_no" style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;color:#002F70;font-weight:800;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Fuel Name</label>
          <input type="text" id="aef_fuel_name" name="fuel_name" required style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;color:#0f172a;font-weight:700;box-sizing:border-box;" onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
        </div>
      </div>

      <!-- Row 2: Price Per Liter + Tank Capacity -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:7px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Price / Liter (&#8369;) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="aef_price" name="price" step="0.01" min="0" required style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;box-sizing:border-box;" oninput="sanitizeDecimalInput(this)">
          <div id="aef_price_notice" style="display:none;margin-top:3px;background:#fef3c7;border:1px solid #f59e0b;border-radius:5px;padding:3px 8px;align-items:center;gap:6px;">
              <i class="fas fa-lock" style="color:#92400e;font-size:11.5px;"></i>
              <span style="font-size:11.5px;color:#92400e;font-weight:700;">PRICE LOCKED &mdash; A pending price request exists.</span>
          </div>
          <small style="font-size:11px;color:#16a34a;display:block;margin-top:2px;font-weight:600;"><i class="fas fa-check-circle"></i> Direct Admin Edit: Updates price immediately.</small>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Tank Capacity (L) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="aef_capacity" name="capacity" step="1" min="0" required style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;box-sizing:border-box;" oninput="sanitizeDecimalInput(this)">
        </div>
      </div>

      <!-- Row 3: Critical Level + Reorder Level -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:7px;">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Critical Level (L) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="aef_critical" name="critical_level" step="1" min="0" required style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;box-sizing:border-box;" oninput="sanitizeDecimalInput(this)">
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin-bottom:2px;">Reorder Level (L) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="aef_reorder" name="reorder_level" step="1" min="0" required style="width:100%;padding:5px 10px;border:1.5px solid #d1d5db;border-radius:6px;font-size:13.5px;box-sizing:border-box;" oninput="sanitizeDecimalInput(this)">
        </div>
      </div>

      <!-- Row 4: Status -->
      <div style="margin-bottom:7px;display:flex;align-items:center;gap:16px;">
        <label style="font-size:12px;font-weight:700;color:#334155;text-transform:uppercase;margin:0;">Status <span style="color:#dc2626;">*</span>:</label>
        <div style="display:flex;gap:16px;align-items:center;">
          <label style="display:flex;align-items:center;gap:5px;font-size:13.5px;cursor:pointer;font-weight:600;color:#166534;">
            <input type="radio" id="aef_status_active" name="status" value="active" checked style="accent-color:#16a34a;"> Active
          </label>
          <label style="display:flex;align-items:center;gap:5px;font-size:13.5px;cursor:pointer;font-weight:600;color:#991b1b;">
            <input type="radio" id="aef_status_inactive" name="status" value="inactive" style="accent-color:#dc2626;"> Inactive
          </label>
        </div>
      </div>

      <!-- Row 5: Dynamic Pump Configuration Card for this Fuel Product -->
      <div style="margin-bottom:9px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;">
          <span style="font-size:11.5px;font-weight:800;color:#002F6C;text-transform:uppercase;letter-spacing:0.3px;display:flex;align-items:center;gap:5px;">
            <i class="fas fa-gas-pump" style="color:#002F6C;"></i> PUMP &amp; NOZZLE CONFIGURATION
          </span>
          <span id="aef_pump_count_badge" style="background:#e0f2fe;color:#0369a1;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;">
            0 Nozzles / Pumps
          </span>
        </div>
        <div id="aef_pumps_container" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:6px;max-height:120px;overflow-y:auto;padding-right:2px;">
          <div style="color:#64748b;font-size:12px;font-style:italic;padding:3px 0;"><i class="fas fa-spinner fa-spin"></i> Loading pump configuration...</div>
        </div>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #e2e8f0;padding-top:10px;">
        <button type="button" onclick="closeEditPriceModalAdmin()" style="background:#f1f5f9 !important;color:#00264D !important;border:1px solid #cbd5e1 !important;padding:6px 16px;border-radius:6px;font-size:13.5px;font-weight:700;cursor:pointer;transition:all 0.2s;"><i class="fas fa-times-circle"></i> Cancel</button>
        <button type="submit" style="background:#002F6C !important;color:#ffffff !important;border:none;padding:6px 20px;border-radius:6px;font-size:13.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;"><i class="fas fa-save" style="color:#ffffff !important;"></i> Save &amp; Apply Immediately</button>
      </div>
    </form>
  </div>
</div>

<!-- Admin Reject Price Request Modal -->
<div id="rejectPriceModalAdmin" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:480px;box-shadow:0 20px 50px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
    <div style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:18px 22px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-times-circle" style="font-size:22px;color:#fff;"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:800;color:#fff;">Reject Price Change Request</h3>
        <p style="margin:2px 0 0 0;font-size:14px;color:rgba(255,255,255,.8);">This action will notify the manager of the rejection.</p>
      </div>
    </div>
    <form method="POST" action="admin_set_prices.php" style="padding:20px 22px;">
      <input type="hidden" name="action" value="reject_price">
      <input type="hidden" id="adminRejectActiveTab" name="active_tab" value="fuel">
      <input type="hidden" id="adminRejectApprovalId" name="approval_id">
      <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
        <strong style="color:#991b1b;display:block;font-size:15.5px;margin-bottom:6px;" id="adminRejectProdName">Fuel Product</strong>
        <div style="font-size:15.5px;color:#475569;display:flex;gap:16px;flex-wrap:wrap;">
          <span>Current Price: <strong style="color:#334155;" id="adminRejectOldPrice">&#8369;0.00</strong></span>
          <span style="color:#94a3b8;">&#8594;</span>
          <span>Requested: <strong style="color:#dc2626;" id="adminRejectNewPrice">&#8369;0.00</strong></span>
        </div>
      </div>
      <div style="margin-bottom:18px;">
        <label style="display:block;font-size:14px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Rejection Reason <span style="color:#dc2626;">*</span></label>
        <textarea name="remarks" id="adminRejectRemarks" rows="3" style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15.5px;box-sizing:border-box;resize:vertical;transition:border-color .2s;" placeholder="Please provide reason for rejecting this price request..." onfocus="this.style.borderColor='#dc2626'" onblur="this.style.borderColor='#d1d5db'"></textarea>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeRejectPriceModalAdmin()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel</button>
        <button type="submit" style="background:#dc2626 !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 20px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<!-- Admin Approve Price Request Modal -->
<div id="approvePriceModalAdmin" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:460px;box-shadow:0 20px 50px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
    <div style="background:linear-gradient(135deg,#16a34a,#15803d);padding:18px 22px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-check-circle" style="font-size:22px;color:#fff;"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:800;color:#fff;">Approve Price Change Request</h3>
        <p style="margin:2px 0 0 0;font-size:14px;color:rgba(255,255,255,.8);">This will immediately apply the new price.</p>
      </div>
    </div>
    <form method="POST" action="admin_set_prices.php" style="padding:20px 22px;">
      <input type="hidden" name="action" value="approve_price">
      <input type="hidden" id="adminApproveActiveTab" name="active_tab" value="fuel">
      <input type="hidden" id="adminApproveApprovalId" name="approval_id">
      <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
        <strong style="color:#166534;display:block;font-size:15.5px;margin-bottom:8px;" id="adminApproveProdName">Fuel Product</strong>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;font-size:14.5px;">
          <div style="background:#fff;border-radius:7px;padding:8px 10px;border:1px solid #d1fae5;">
            <span style="display:block;font-size:15.5px;font-weight:700;color:#15803d;text-transform:uppercase;margin-bottom:3px;">Current Price</span>
            <span style="font-weight:700;color:#334155;font-size:15.5px;" id="adminApproveOldPrice">&#8369;0.00</span>
          </div>
          <div style="background:#fff;border-radius:7px;padding:8px 10px;border:1px solid #d1fae5;">
            <span style="display:block;font-size:15.5px;font-weight:700;color:#15803d;text-transform:uppercase;margin-bottom:3px;">New Price</span>
            <span style="font-weight:800;color:#002F6C;font-size:14px;" id="adminApproveNewPrice">&#8369;0.00</span>
          </div>
          <div style="background:#fff;border-radius:7px;padding:8px 10px;border:1px solid #d1fae5;">
            <span style="display:block;font-size:15.5px;font-weight:700;color:#15803d;text-transform:uppercase;margin-bottom:3px;">Difference</span>
            <span style="font-weight:700;font-size:15.5px;" id="adminApproveDiff">&#8369;0.00</span>
          </div>
        </div>
      </div>
      <p style="font-size:14.5px;color:#64748b;margin:0 0 18px 0;"><i class="fas fa-info-circle" style="color:#16a34a;"></i> Once approved, the new price will take effect immediately for all future transactions.</p>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeApprovePriceModalAdmin()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel</button>
        <button type="submit" style="background:#16a34a !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 22px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-check" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm Approval</button>
      </div>
    </form>
  </div>
</div>

<!-- Deactivate / Activate Fuel Status Confirmation Modal -->
<div id="toggleFuelStatusModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:440px;box-shadow:0 20px 50px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
    <div id="toggleFuelStatusHeader" style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:18px 22px;display:flex;align-items:center;gap:14px;">
      <div id="toggleFuelStatusIcon" style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-ban" style="font-size:22px;color:#fff;"></i>
      </div>
      <div>
        <h3 id="toggleFuelStatusTitle" style="margin:0;font-size:15px;font-weight:800;color:#fff;">Deactivate Fuel Product</h3>
        <p style="margin:2px 0 0 0;font-size:14px;color:rgba(255,255,255,.8);">Please confirm this action.</p>
      </div>
    </div>
    <div style="padding:20px 22px;">
      <input type="hidden" id="toggleFuelStatusId">
      <input type="hidden" id="toggleFuelStatusValue">
      <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
        <div style="font-size:15.5px;color:#475569;line-height:1.6;" id="toggleFuelStatusDesc">
          Are you sure you want to change the status of <strong id="toggleFuelStatusName" style="color:#0f172a;">this fuel</strong>?
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeToggleFuelStatusModal()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel</button>
        <button type="button" id="toggleFuelStatusConfirmBtn" onclick="confirmToggleFuelStatus()" style="background:#dc2626;color:#ffffff;border:none;padding:9px 22px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-ban" style="color:#ffffff !important;"></i> Confirm Deactivation</button>
      </div>
    </div>
  </div>
</div>

<!-- Deactivate / Activate Service Status Confirmation Modal -->
<div id="toggleServiceStatusModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:440px;box-shadow:0 20px 50px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
    <div id="toggleServiceStatusHeader" style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:18px 22px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i id="toggleServiceHeaderIcon" class="fas fa-ban" style="font-size:22px;color:#fff;"></i>
      </div>
      <div>
        <h3 id="toggleServiceStatusTitle" style="margin:0;font-size:15px;font-weight:800;color:#fff;">Deactivate Service</h3>
        <p style="margin:2px 0 0 0;font-size:14px;color:rgba(255,255,255,.8);">Please confirm this action.</p>
      </div>
    </div>
    <div style="padding:20px 22px;">
      <input type="hidden" id="toggleServiceStatusId">
      <input type="hidden" id="toggleServiceStatusValue">
      <input type="hidden" id="toggleServiceStatusNameHolder">
      <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
        <div style="font-size:15.5px;color:#475569;line-height:1.6;" id="toggleServiceStatusDesc">
          Are you sure you want to change the status of <strong id="toggleServiceStatusName" style="color:#0f172a;">this service</strong>?
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeToggleServiceStatusModal()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel</button>
        <button type="button" id="toggleServiceStatusConfirmBtn" onclick="confirmAdminToggleService()" style="background:#dc2626 !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 22px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i id="toggleServiceBtnIcon" class="fas fa-ban" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm Deactivation</button>
      </div>
    </div>
  </div>
</div>

<!-- Restore Service Fees Confirmation Modal -->
<div id="restoreServiceFeesModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:90%;max-width:440px;box-shadow:0 20px 50px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
    <div style="background:linear-gradient(135deg,#d97706,#b45309);padding:18px 22px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="fas fa-undo" style="font-size:22px;color:#fff;"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:15px;font-weight:800;color:#fff;">Restore Previous Fees</h3>
        <p style="margin:2px 0 0 0;font-size:14px;color:rgba(255,255,255,.8);">Revert service fees to previous values.</p>
      </div>
    </div>
    <div style="padding:20px 22px;">
      <input type="hidden" id="restoreSvcId">
      <input type="hidden" id="restoreOldSvcFee">
      <input type="hidden" id="restoreOldLabFee">
      <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
        <div style="font-size:15.5px;color:#475569;line-height:1.6;" id="restoreSvcDesc">
          Are you sure you want to restore previous fees?
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" onclick="closeRestoreServiceFeesModal()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel</button>
        <button type="button" onclick="confirmRestoreServiceFees()" style="background:#d97706 !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none;padding:9px 22px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-undo" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> Confirm Restoration</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     ADMIN ADD PRODUCT MODALS (FUEL, MERCHANDISE, SERVICE, CONFIRMATION)
     ══════════════════════════════════════════════════════════════════════════ -->

<!-- Add Fuel Product Modal (Landscape Layout) -->
<div id="addProductModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;padding:20px 20px 60px;box-sizing:border-box;">
    <div style="background:#fff;border-radius:12px;width:92%;max-width:760px;max-height:calc(100vh - 110px);display:flex;flex-direction:column;box-shadow:0 16px 48px rgba(0,0,0,.35);margin:auto;overflow:hidden;animation:adminModalPopIn .2s ease-out;">
        <!-- Modal Header -->
        <div style="flex-shrink:0;background:linear-gradient(135deg,#002F6C,#004494);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;">
            <h3 style="margin:0;font-size:17px;font-weight:800;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;display:flex;align-items:center;gap:10px;letter-spacing:0.3px;">
                <i class="fas fa-plus-circle" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;font-size:18px;"></i>
                <span style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;">ADD FUEL PRODUCT</span>
            </h3>
        </div>
        <!-- Modal Form Body (Landscape 2-Column Grid with Scroll) -->
        <form id="addProductForm" action="javascript:void(0);" method="POST" style="padding:16px 24px 18px;overflow-y:auto;flex:1 1 auto;display:flex;flex-direction:column;">
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
                    <input type="number" id="newCriticalLevel" step="1" min="0" required
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

<!-- Add Merchandise Modal -->
<div id="addMerchandiseModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;width:90%;max-width:650px;max-height:92vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.3);animation:adminModalPopIn .2s ease-out;">
    <div style="background:linear-gradient(135deg,#002F6C,#004494);border-radius:12px 12px 0 0;padding:18px 22px;display:flex;align-items:center;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-plus-circle"></i> ADD NEW MERCHANDISE PRODUCT
      </h3>
    </div>
    <form id="addMerchandiseForm" action="javascript:void(0);" method="POST" style="padding:22px;">
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
          <label style="display:block;font-size:14px;font-weight:700;color:#002F6C;text-transform:uppercase;margin-bottom:4px;">Default Selling Price (₱) <span style="color:#dc2626;">*</span></label>
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

<!-- Reusable Confirmation Modal -->
<div id="confirmationModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;width:100vw;height:100vh;background:rgba(15,23,42,0.65);backdrop-filter:blur(3px);z-index:99999;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;">
    <div style="background:#ffffff;border-radius:16px;width:100%;max-width:440px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);display:flex;flex-direction:column;overflow:hidden;animation:adminModalPopIn .2s ease-out;border:1px solid rgba(226,232,240,0.8);margin:0;max-height:calc(100vh - 48px);">
        <!-- Header -->
        <div id="confirmActionHeader" style="background:linear-gradient(135deg,#dc2626,#b91c1c);padding:18px 22px;display:flex;align-items:center;gap:14px;border-radius:15px 15px 0 0;flex-shrink:0;">
            <div style="width:42px;height:42px;background:rgba(255,255,255,0.18);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i id="confirmActionIcon" class="fas fa-ban" style="color:#ffffff;font-size:20px;"></i>
            </div>
            <div style="flex:1;min-width:0;">
                <h3 style="margin:0;font-size:16px;font-weight:800;color:#ffffff;line-height:1.3;" id="confirmActionTitle">Deactivate Merchandise Product</h3>
                <p style="margin:3px 0 0 0;font-size:13px;color:rgba(255,255,255,0.85);font-weight:500;" id="confirmActionSubtitle">Please confirm this action.</p>
            </div>
        </div>
        
        <!-- Body -->
        <div style="padding:22px 24px;background:#ffffff;display:flex;flex-direction:column;gap:18px;">
            <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:18px 20px;">
                <div style="font-size:15px;color:#334155;line-height:1.6;" id="confirmActionMessage">
                    Are you sure you want to proceed?
                </div>
            </div>
            
            <!-- Footer -->
            <div style="display:flex;justify-content:flex-end;gap:10px;align-items:center;">
                <button type="button" onclick="closeConfirmModal()" style="background:#f1f5f9 !important;color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;border:1.5px solid #cbd5e1 !important;padding:9px 18px;border-radius:8px;font-size:14px;font-weight:700 !important;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;">
                    <i class="fas fa-times-circle" style="color:#1e293b !important;-webkit-text-fill-color:#1e293b !important;"></i> Cancel
                </button>
                <button type="button" id="confirmActionBtn" onclick="confirmModalAction()" style="background:#dc2626 !important;color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;border:none !important;padding:9px 20px;border-radius:8px;font-size:14px;font-weight:700 !important;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 6px rgba(220,38,38,0.35);transition:all .15s;">
                    <i id="confirmActionBtnIcon" class="fas fa-ban" style="color:#ffffff !important;-webkit-text-fill-color:#ffffff !important;"></i> <span id="confirmActionBtnLabel">Confirm Deactivation</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Service Modal -->
<div id="addServiceModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:9999;align-items:center;justify-content:center;padding:30px 16px;box-sizing:border-box;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:580px;max-height:calc(85vh - 20px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,0.35);margin:auto;animation:adminModalPopIn 0.25s ease-out;">
    <!-- Header -->
    <div style="flex-shrink:0;background:linear-gradient(135deg,#002F6C,#0052A5);border-radius:14px 14px 0 0;padding:16px 22px;display:flex;align-items:center;">
      <h3 style="margin:0;font-size:16px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;">
        <i class="fas fa-plus-circle"></i> Add New Service
      </h3>
    </div>
    <!-- Form Body -->
    <form id="addServiceForm" action="javascript:void(0);" method="POST" style="flex:1 1 auto;overflow-y:auto;padding:22px;display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Service Name <span style="color:#dc2626;">*</span></label>
            <input type="text" id="addSvcName" required placeholder="e.g. Change Oil - Mineral"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15.5px;font-weight:500;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
              oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\(\)\/\,\.\&]/g, '');">
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Category <span style="color:#dc2626;">*</span></label>
            <div style="position:relative;" id="addSvcCatContainer">
              <input type="text" id="addSvcCategory" required autocomplete="off"
                placeholder="Select or type category..."
                style="width:100%;padding:10px 36px 10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15px;font-weight:500;box-sizing:border-box;background:#fff;"
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
            <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Service Fee (₱) <span style="color:#dc2626;">*</span></label>
            <input type="number" id="addSvcServiceFee" step="0.01" min="0" required placeholder="0.00"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15.5px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"
                           oninput="sanitizeDecimalInput(this)">
            <small style="color:#94a3b8;font-size:14px;">Parts/materials fee</small>
          </div>
          <div>
            <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Labor Fee (₱) <span style="color:#dc2626;">*</span></label>
            <input type="number" id="addSvcLaborFee" step="0.01" min="0" required placeholder="0.00"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15.5px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
            <small style="color:#94a3b8;font-size:14px;">Mechanic labor fee</small>
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Required Mechanics <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
            <input type="number" id="addSvcMechanics" min="1" max="10"
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15.5px;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'">
          </div>
          <div style="grid-column:1/-1;">
            <label style="display:block;font-size:14px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:5px;">Description <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
            <textarea id="addSvcDescription" rows="2" placeholder="Brief description of the service..."
              style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:15.5px;resize:vertical;box-sizing:border-box;"
              onfocus="this.style.borderColor='#002F6C'" onblur="this.style.borderColor='#d1d5db'"></textarea>
          </div>
        </div>
      </div>
      <!-- Footer Actions -->
      <div style="display:flex;gap:10px;justify-content:flex-end;padding-top:14px;border-top:1px solid #e2e8f0;margin-top:auto;">
        <button type="button" onclick="closeAddServiceModal()" style="background:#f1f5f9 !important;color:#0f172a !important;border:1px solid #cbd5e1 !important;padding:10px 20px;border-radius:8px;font-size:15.5px;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="submit" style="background:#002F6C;color:#fff;border:none;padding:10px 24px;border-radius:8px;font-size:15.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
          <i class="fas fa-check-circle"></i> Add Service
        </button>
      </div>
    </form>
  </div>
</div>

<style>
@keyframes adminModalPopIn {
    from { opacity:0; transform:scale(0.93) translateY(-10px); }
    to   { opacity:1; transform:scale(1) translateY(0); }
}
/* ── Force white text on ALL modal headers ── */
[id*="Modal"] .modal-header h3,
[id*="Modal"] .modal-header h4,
[id*="Modal"] > div > div:first-child h3,
[id*="Modal"] > div > div:first-child h4,
[id*="Modal"] > div > div:first-child h3 *,
[id*="Modal"] > div > div:first-child h4 *,
[id*="modal"] .modal-header h3,
[id*="modal"] .modal-header h4,
[id*="modal"] > div > div:first-child h3,
[id*="modal"] > div > div:first-child h4,
[id*="modal"] > div > div:first-child h3 *,
[id*="modal"] > div > div:first-child h4 * {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}
/* ── Force modal header title to left-align ── */
[id*="Modal"] > div > div:first-child,
[id*="modal"] > div > div:first-child {
    justify-content: flex-start !important;
    text-align: left !important;
}
/* ── Prevent table cell data overlap in modals ── */
[id*="Modal"] table td,
[id*="modal"] table td {
    overflow-wrap: break-word;
    word-break: break-word;
    vertical-align: top;
    padding: 7px 10px;
}
[id*="Modal"] table th,
[id*="modal"] table th {
    vertical-align: middle;
    padding: 8px 10px;
    text-align: left;
}

/* ── Center modal forms in the content layout (excluding sidebar navigation) ── */
@media (min-width: 992px) {
    div.admin-layout-modal,
    div[id$="Modal"]:not(#confirmationModal):not(#approveConfirmModal) {
        left: 250px !important;
        width: calc(100% - 250px) !important;
        right: 0 !important;
        bottom: 40px !important;
        box-sizing: border-box !important;
        justify-content: center !important;
    }
    body.sidebar-collapsed div.admin-layout-modal,
    body.sidebar-collapsed div[id$="Modal"]:not(#confirmationModal):not(#approveConfirmModal) {
        left: 70px !important;
        width: calc(100% - 70px) !important;
        right: 0 !important;
        bottom: 40px !important;
    }
}
@media (max-width: 991px) {
    div.admin-layout-modal,
    div[id$="Modal"]:not(#confirmationModal):not(#approveConfirmModal) {
        left: 0 !important;
        width: 100% !important;
        right: 0 !important;
        bottom: 40px !important;
        box-sizing: border-box !important;
        justify-content: center !important;
    }
}
</style>

<script>
// Dynamic sync to ensure modal overlays strictly match the main layout area (excluding sidebar)
function syncAdminModalLayout() {
    var mainEl = document.querySelector('.main');
    if (!mainEl) return;
    var isDesktop = window.innerWidth >= 992;
    var leftVal = isDesktop ? (mainEl.offsetLeft || 250) : 0;
    var widthVal = isDesktop ? ('calc(100% - ' + leftVal + 'px)') : '100%';
    
    var modalOverlays = [
        'viewFuelModalAdmin', 'viewAdminMerchModal', 'viewAdminServiceModal',
        'viewAdminBatchesModal', 'adminEditProductModal', 'adminEditFuelModal',
        'adminEditServiceModal', 'addProductModal', 'addMerchandiseModal',
        'addServiceModal', 'priceHistoryModal', 'viewRequestModal',
        'rejectModal', 'approveConfirmModal', 'rejectReasonModal',
        'editPriceModalAdmin', 'rejectPriceModalAdmin', 'approvePriceModalAdmin',
        'toggleFuelStatusModal', 'toggleServiceStatusModal', 'restoreServiceFeesModal'
        /* confirmationModal intentionally excluded — it must cover the full viewport */
    ];
    modalOverlays.forEach(function(id) {
        var m = document.getElementById(id);
        if (!m || !m.style) return;
        m.style.setProperty('left', leftVal + 'px', 'important');
        m.style.setProperty('width', widthVal, 'important');
        m.style.setProperty('right', '0px', 'important');
        m.style.setProperty('bottom', '40px', 'important');
        m.style.setProperty('top', '70px', 'important');
    });
}
window.addEventListener('resize', syncAdminModalLayout);
window.addEventListener('load', syncAdminModalLayout);
document.addEventListener('DOMContentLoaded', function() {
    syncAdminModalLayout();

    // Check for flash toast from previous action (e.g. Add Fuel/Merchandise/Service)
    try {
        var flashToastRaw = sessionStorage.getItem('admin_flash_toast');
        if (flashToastRaw) {
            sessionStorage.removeItem('admin_flash_toast');
            var flashToast = JSON.parse(flashToastRaw);
            if (flashToast && flashToast.message) {
                setTimeout(function() {
                    showCustomAlert(flashToast.message, flashToast.type || 'success');
                }, 200);
            }
        }
    } catch(e) {}

    // Auto-open Merchandise Details Modal & scroll into view when navigated from Global Search
    var urlParams = new URLSearchParams(window.location.search);
    var autoOpenPid = urlParams.get('product_id') || urlParams.get('pid');
    var autoOpenSearch = urlParams.get('search_query') || urlParams.get('search');
    var autoOpenFlag = urlParams.get('auto_open') === '1' || !!autoOpenPid;
    var tab = urlParams.get('tab');

    if ((autoOpenFlag || autoOpenPid || autoOpenSearch) && (tab === 'merch' || !tab)) {
        // Strip auto-open params from URL immediately so refresh won't re-trigger
        (function() {
            var clean = new URLSearchParams(window.location.search);
            ['auto_open','product_id','pid','search_query','search'].forEach(function(k){ clean.delete(k); });
            var newUrl = window.location.pathname + (clean.toString() ? '?' + clean.toString() : '');
            history.replaceState(null, '', newUrl);
        })();

        setTimeout(function() {
            var searchInput = document.getElementById('adminSearchInput');
            if (searchInput && autoOpenSearch && !searchInput.value) {
                searchInput.value = autoOpenSearch;
                if (typeof filterAdminMerchTable === 'function') filterAdminMerchTable();
            }

            var targetRow = null;
            if (autoOpenPid) {
                targetRow = document.querySelector('#adminMerchBody tr.admin-merch-row[data-id="' + autoOpenPid + '"]');
            }
            if (!targetRow && autoOpenSearch) {
                var sLower = autoOpenSearch.toLowerCase().trim();
                var allRows = document.querySelectorAll('#adminMerchBody tr.admin-merch-row');
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
                targetRow.style.display = '';
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
</script>
</div> <!-- /.main-content -->

<?php include __DIR__ . '/../partials/footer.php'; ?>
