<?php
$page_id = 'pump_nozzle_config';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$isSuper = ($role === 'superadmin');
$isAdmin = in_array($role, ['admin', 'superadmin']);
$isManager = in_array($role, ['manager', 'admin', 'superadmin']);

if (!$isManager) {
    header('Location: staff_dashboard.php');
    exit;
}

$user_st = user_station_id();
$station_id = ($isSuper && isset($_GET['station_id']) && (int)$_GET['station_id'] > 0)
    ? (int)$_GET['station_id']
    : (int)$user_st;

if ($station_id <= 0) {
    $station_id = 1253; // fallback default
}

// Fetch all stations for superadmin switcher
$stations_list = [];
if ($isSuper) {
    try {
        $st_stmt = $pdo->query("SELECT id, name FROM stations ORDER BY name ASC");
        $stations_list = $st_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Station Name
$current_station_name = 'Current Station';
try {
    $sn_stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
    $sn_stmt->execute([$station_id]);
    $current_station_name = $sn_stmt->fetchColumn() ?: "Station #{$station_id}";
} catch (Exception $e) {}

// POST Handlers for Add/Edit/Toggle/Delete
$flash_msg = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);

    if ($action === 'save_nozzle') {
        $nozzle_id    = (int)($_POST['nozzle_id'] ?? 0);
        $pump_name    = trim($_POST['pump_name'] ?? '');
        $nozzle_num   = trim($_POST['nozzle_number'] ?? '');
        $fuel_type_id = (int)($_POST['fuel_type_id'] ?? 0);
        $ugt_no       = trim($_POST['ugt_no'] ?? '');
        $status       = trim($_POST['status'] ?? 'Active');
        $calibration  = (float)($_POST['calibration_value'] ?? 0);

        if (!in_array($status, ['Active', 'Inactive', 'Maintenance'])) {
            $status = 'Active';
        }

        if (empty($pump_name))  $pump_name  = 'Pump 1';
        if (empty($nozzle_num)) $nozzle_num = 'Nozzle 1';

        if ($fuel_type_id <= 0) {
            $flash_msg = 'Please select a valid Fuel Type.';
            $flash_type = 'error';
        } else {
            try {
                // Get fuel name
                $fn_stmt = $pdo->prepare("SELECT name FROM fuel_types WHERE id = ? LIMIT 1");
                $fn_stmt->execute([$fuel_type_id]);
                $fuel_name = $fn_stmt->fetchColumn() ?: 'Fuel';

                // Construct a canonical dual-nozzle pump_number tag
                $p_idx_only = (int)preg_replace('/[^0-9]/', '', $pump_name) ?: 1;
                $n_idx_only = (int)preg_replace('/[^0-9]/', '', $nozzle_num) ?: 1;
                $pump_tag = strtoupper($fuel_name) . " - {$p_idx_only}-{$n_idx_only}";

                if ($nozzle_id > 0) {
                    // Update existing
                    $upd_noz = $pdo->prepare("
                        UPDATE nozzles 
                        SET pump_name = ?, nozzle_number = ?, fuel_type_id = ?, ugt_no = ?, status = ?, calibration_value = ?, updated_at = NOW()
                        WHERE id = ? AND station_id = ?
                    ");
                    $upd_noz->execute([$pump_name, $nozzle_num, $fuel_type_id, $ugt_no, $status, $calibration, $nozzle_id, $station_id]);

                    // Sync corresponding fuel_pumps record
                    $get_pid = $pdo->prepare("SELECT pump_id FROM nozzles WHERE id = ? LIMIT 1");
                    $get_pid->execute([$nozzle_id]);
                    $target_pid = (int)$get_pid->fetchColumn();

                    if ($target_pid > 0) {
                        $pdo->prepare("
                            UPDATE fuel_pumps 
                            SET pump_name = ?, nozzle_number = ?, fuel_type_id = ?, ugt_no = ?, status = ?, calibration_value = ?
                            WHERE id = ? AND station_id = ?
                        ")->execute([$pump_name, $nozzle_num, $fuel_type_id, $ugt_no, $status, $calibration, $target_pid, $station_id]);
                    }

                    $flash_msg = "Nozzle '{$pump_name} - {$nozzle_num}' updated successfully.";
                    log_activity($pdo, $me['id'], 'Update Pump Nozzle', "Updated {$pump_name} {$nozzle_num} for station {$station_id}");
                } else {
                    // Create in fuel_pumps first
                    $ins_fp = $pdo->prepare("
                        INSERT INTO fuel_pumps (station_id, pump_number, pump_name, nozzle_number, fuel_type_id, ugt_no, status, calibration_value, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $ins_fp->execute([$station_id, $pump_tag, $pump_name, $nozzle_num, $fuel_type_id, $ugt_no, $status, $calibration]);
                    $new_pump_id = (int)$pdo->lastInsertId();

                    // Create in nozzles table
                    $ins_noz = $pdo->prepare("
                        INSERT INTO nozzles (station_id, pump_id, pump_name, nozzle_number, fuel_type_id, ugt_no, status, calibration_value, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $ins_noz->execute([$station_id, $new_pump_id, $pump_name, $nozzle_num, $fuel_type_id, $ugt_no, $status, $calibration]);

                    $flash_msg = "New pump nozzle '{$pump_name} - {$nozzle_num}' added successfully.";
                    log_activity($pdo, $me['id'], 'Add Pump Nozzle', "Added {$pump_name} {$nozzle_num} ({$fuel_name}) for station {$station_id}");
                }
            } catch (Exception $e) {
                $flash_msg = 'Database error: ' . $e->getMessage();
                $flash_type = 'error';
            }
        }
    } elseif ($action === 'toggle_status') {
        $nozzle_id = (int)($_POST['nozzle_id'] ?? 0);
        $new_status = trim($_POST['status'] ?? 'Active');
        if (!in_array($new_status, ['Active', 'Inactive', 'Maintenance'])) {
            $new_status = 'Active';
        }
        try {
            $pdo->prepare("UPDATE nozzles SET status = ?, updated_at = NOW() WHERE id = ? AND station_id = ?")
                ->execute([$new_status, $nozzle_id, $station_id]);
            
            $get_pid = $pdo->prepare("SELECT pump_id FROM nozzles WHERE id = ? LIMIT 1");
            $get_pid->execute([$nozzle_id]);
            $target_pid = (int)$get_pid->fetchColumn();
            if ($target_pid > 0) {
                $pdo->prepare("UPDATE fuel_pumps SET status = ? WHERE id = ? AND station_id = ?")
                    ->execute([$new_status, $target_pid, $station_id]);
            }
            $flash_msg = "Status changed to {$new_status}.";
            log_activity($pdo, $me['id'], 'Toggle Pump Status', "Changed nozzle ID {$nozzle_id} to {$new_status} for station {$station_id}");
        } catch (Exception $e) {
            $flash_msg = 'Error toggling status: ' . $e->getMessage();
            $flash_type = 'error';
        }
    } elseif ($action === 'delete_nozzle') {
        $nozzle_id = (int)($_POST['nozzle_id'] ?? 0);
        try {
            $get_pid = $pdo->prepare("SELECT pump_id, pump_name, nozzle_number FROM nozzles WHERE id = ? AND station_id = ? LIMIT 1");
            $get_pid->execute([$nozzle_id, $station_id]);
            $noz_row = $get_pid->fetch(PDO::FETCH_ASSOC);

            if ($noz_row) {
                $target_pid = (int)$noz_row['pump_id'];

                // Check if used in transactions
                $tx_chk = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE pump_id = ?");
                $tx_chk->execute([$target_pid]);
                $tx_count = (int)$tx_chk->fetchColumn();

                if ($tx_count > 0) {
                    // Soft deactivate instead of hard delete
                    $pdo->prepare("UPDATE nozzles SET status = 'Inactive', updated_at = NOW() WHERE id = ?")->execute([$nozzle_id]);
                    $pdo->prepare("UPDATE fuel_pumps SET status = 'Inactive' WHERE id = ?")->execute([$target_pid]);
                    $flash_msg = "Nozzle has transaction history. Set to Inactive instead of deleting.";
                } else {
                    $pdo->prepare("DELETE FROM nozzles WHERE id = ? AND station_id = ?")->execute([$nozzle_id, $station_id]);
                    $pdo->prepare("DELETE FROM fuel_pumps WHERE id = ? AND station_id = ?")->execute([$target_pid, $station_id]);
                    $flash_msg = "Nozzle deleted successfully.";
                }
                log_activity($pdo, $me['id'], 'Delete Pump Nozzle', "Deleted/deactivated nozzle ID {$nozzle_id} for station {$station_id}");
            }
        } catch (Exception $e) {
            $flash_msg = 'Error deleting nozzle: ' . $e->getMessage();
            $flash_type = 'error';
        }
    }
}

// Fetch Active Fuel Products for this station
$active_fuels = [];
try {
    $f_stmt = $pdo->prepare("
        SELECT fi.fuel_type_id, fi.fuel_type, fi.ugt_no, fi.price_per_liter
        FROM fuel_inventory fi
        WHERE fi.station_id = ? AND LOWER(COALESCE(fi.status,'active')) NOT IN ('archived','deleted')
        ORDER BY CAST(REGEXP_REPLACE(fi.ugt_no, '[^0-9]', '') AS UNSIGNED) ASC, fi.id ASC
    ");
    $f_stmt->execute([$station_id]);
    $active_fuels = $f_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Fallback to fuel_types if fuel_inventory empty
if (empty($active_fuels)) {
    try {
        $ft_all = $pdo->query("SELECT id AS fuel_type_id, name AS fuel_type, '' AS ugt_no FROM fuel_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $active_fuels = $ft_all;
    } catch (Exception $e) {}
}

// Fetch Nozzles / Pumps for this station
$nozzles_list = [];
try {
    $n_stmt = $pdo->prepare("
        SELECT 
            n.id,
            n.station_id,
            n.pump_id,
            COALESCE(NULLIF(TRIM(n.pump_name), ''), NULLIF(TRIM(fp.pump_name), ''), fp.pump_number, 'Pump 1') AS pump_name,
            COALESCE(NULLIF(TRIM(n.nozzle_number), ''), NULLIF(TRIM(fp.nozzle_number), ''), 'Nozzle 1') AS nozzle_number,
            n.fuel_type_id,
            COALESCE(ft.name, fi.fuel_type, 'Fuel') AS fuel_type_name,
            COALESCE(NULLIF(TRIM(n.ugt_no), ''), NULLIF(TRIM(fp.ugt_no), ''), fi.ugt_no, 'UGT-01') AS ugt_no,
            COALESCE(n.status, fp.status, 'Active') AS status,
            COALESCE(n.calibration_value, fp.calibration_value, 0.00) AS calibration_value,
            fp.pump_number
        FROM nozzles n
        LEFT JOIN fuel_pumps fp ON fp.id = n.pump_id
        LEFT JOIN fuel_types ft ON ft.id = n.fuel_type_id
        LEFT JOIN fuel_inventory fi ON (fi.station_id = n.station_id AND fi.fuel_type_id = n.fuel_type_id)
        WHERE n.station_id = ?
        ORDER BY 
            CAST(REGEXP_REPLACE(n.pump_name, '[^0-9]', '') AS UNSIGNED) ASC,
            CAST(REGEXP_REPLACE(n.nozzle_number, '[^0-9]', '') AS UNSIGNED) ASC,
            n.id ASC
    ");
    $n_stmt->execute([$station_id]);
    $nozzles_list = $n_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// If nozzles table has no rows for this station, fallback/auto-sync from fuel_pumps
if (empty($nozzles_list)) {
    try {
        $fp_stmt = $pdo->prepare("
            SELECT 
                fp.id AS pump_id,
                fp.station_id,
                COALESCE(NULLIF(TRIM(fp.pump_name), ''), fp.pump_number, 'Pump 1') AS pump_name,
                COALESCE(NULLIF(TRIM(fp.nozzle_number), ''), 'Nozzle 1') AS nozzle_number,
                fp.fuel_type_id,
                COALESCE(ft.name, fi.fuel_type, 'Fuel') AS fuel_type_name,
                COALESCE(NULLIF(TRIM(fp.ugt_no), ''), fi.ugt_no, 'UGT-01') AS ugt_no,
                COALESCE(fp.status, 'Active') AS status,
                COALESCE(fp.calibration_value, 0.00) AS calibration_value,
                fp.pump_number
            FROM fuel_pumps fp
            LEFT JOIN fuel_types ft ON ft.id = fp.fuel_type_id
            LEFT JOIN fuel_inventory fi ON (fi.station_id = fp.station_id AND fi.fuel_type_id = fp.fuel_type_id)
            WHERE fp.station_id = ?
            ORDER BY fp.id ASC
        ");
        $fp_stmt->execute([$station_id]);
        $raw_pumps = $fp_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($raw_pumps)) {
            foreach ($raw_pumps as $rp) {
                $p_name = $rp['pump_name'];
                $n_num  = $rp['nozzle_number'];
                $pdo->prepare("
                    INSERT INTO nozzles (station_id, pump_id, pump_name, nozzle_number, fuel_type_id, ugt_no, status, calibration_value, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ")->execute([
                    $station_id,
                    $rp['pump_id'],
                    $p_name,
                    $n_num,
                    (int)$rp['fuel_type_id'],
                    $rp['ugt_no'],
                    $rp['status'],
                    (float)$rp['calibration_value']
                ]);
            }
            // Re-fetch
            $n_stmt->execute([$station_id]);
            $nozzles_list = $n_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

// KPI Stats
$total_nozzles = count($nozzles_list);
$distinct_pumps = count(array_unique(array_map(fn($r) => strtolower(trim($r['pump_name'])), $nozzles_list)));
$active_nozzles = count(array_filter($nozzles_list, fn($r) => strtolower(trim($r['status'])) === 'active'));
$inactive_nozzles = $total_nozzles - $active_nozzles;

require_once __DIR__ . '/../partials/header.php';
?>

<div class="content-wrapper" style="padding: 24px 32px; background: #f8fafc; min-height: calc(100vh - 70px);">

    <!-- Header & Breadcrumbs -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="font-size: 13px; font-weight: 600; color: #64748b; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-gas-pump" style="color:#002F6C;"></i>
                <span>Fuel Management</span>
                <i class="fas fa-chevron-right" style="font-size: 10px; color: #94a3b8;"></i>
                <span style="color: #002F6C; font-weight: 700;">Pump & Nozzle Configuration</span>
            </div>
            <h1 style="font-size: 24px; font-weight: 800; color: #002F6C; margin: 0; letter-spacing: -0.5px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-sliders-h"></i> Pump & Nozzle Configuration
            </h1>
            <p style="margin: 4px 0 0; font-size: 14px; color: #475569;">
                Configure physical pumps, dispensing nozzles, fuel types, and underground tank assignments for <strong><?= htmlspecialchars($current_station_name) ?></strong>.
            </p>
        </div>

        <div style="display: flex; gap: 12px; align-items: center;">
            <?php if ($isSuper && !empty($stations_list)): ?>
                <form method="GET" action="manager_pump_nozzle_config.php" style="margin: 0;">
                    <select name="station_id" onchange="this.form.submit()" style="padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 14px; font-weight: 600; color: #002F6C; background: #ffffff;">
                        <?php foreach ($stations_list as $st): ?>
                            <option value="<?= (int)$st['id'] ?>" <?= $st['id'] == $station_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['name']) ?> (ID: <?= $st['id'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <button type="button" onclick="openNozzleModal()" style="background: linear-gradient(135deg, #002F6C 0%, #004494 100%); color: #ffffff; border: none; padding: 10px 20px; border-radius: 7px; font-size: 14.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(0, 47, 108, 0.2);">
                <i class="fas fa-plus-circle"></i> Add Pump & Nozzle
            </button>
        </div>
    </div>

    <!-- Flash Message -->
    <?php if (!empty($flash_msg)): ?>
        <div style="margin-bottom: 20px; padding: 14px 18px; border-radius: 8px; font-size: 14.5px; font-weight: 600; display: flex; align-items: center; gap: 10px; <?= $flash_type === 'success' ? 'background:#dcfce7;color:#166534;border:1px solid #86efac;' : 'background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;' ?>">
            <i class="fas <?= $flash_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
            <span><?= htmlspecialchars($flash_msg) ?></span>
        </div>
    <?php endif; ?>

    <!-- 6-Pillar Quick Navigation Bar -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; margin-bottom: 22px; display: flex; gap: 6px; overflow-x: auto; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
        <a href="manager_set_prices.php?tab=fuel" style="padding: 8px 14px; border-radius: 6px; font-size: 13.5px; font-weight: 600; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
            <i class="fas fa-tint" style="color:#0284c7;"></i> Fuel Products
        </a>
        <a href="manager_inventory_fuel.php" style="padding: 8px 14px; border-radius: 6px; font-size: 13.5px; font-weight: 600; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
            <i class="fas fa-database" style="color:#10b981;"></i> Tank Configuration
        </a>
        <a href="manager_pump_nozzle_config.php" style="background: #002F6C; color: #ffffff; padding: 8px 16px; border-radius: 6px; font-size: 13.5px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
            <i class="fas fa-gas-pump" style="color:#ffffff;"></i> Pump & Nozzle Configuration
        </a>
        <a href="staff_transactions_hub.php?section=fuel" style="padding: 8px 14px; border-radius: 6px; font-size: 13.5px; font-weight: 600; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
            <i class="fas fa-tachometer-alt" style="color:#f59e0b;"></i> Fuel Sales
        </a>
        <a href="staff_fuel_sales_closing.php" style="padding: 8px 14px; border-radius: 6px; font-size: 13.5px; font-weight: 600; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
            <i class="fas fa-lock" style="color:#8b5cf6;"></i> Fuel Closing
        </a>
        <a href="manager_fuel_reconciliation.php" style="padding: 8px 14px; border-radius: 6px; font-size: 13.5px; font-weight: 600; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
            <i class="fas fa-calculator" style="color:#ec4899;"></i> Fuel Reconciliation
        </a>
    </div>

    <!-- KPI Metric Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="font-size: 12.5px; font-weight: 700; color: #64748b; text-transform: uppercase;">Physical Dispensers</div>
            <div style="font-size: 28px; font-weight: 800; color: #002F6C; margin-top: 6px;"><?= $distinct_pumps ?> <span style="font-size: 14px; font-weight: 600; color: #94a3b8;">Pumps</span></div>
            <div style="font-size: 12px; color: #059669; margin-top: 4px;"><i class="fas fa-check-circle"></i> Station-specific layout</div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="font-size: 12.5px; font-weight: 700; color: #64748b; text-transform: uppercase;">Total Nozzles</div>
            <div style="font-size: 28px; font-weight: 800; color: #0284c7; margin-top: 6px;"><?= $total_nozzles ?> <span style="font-size: 14px; font-weight: 600; color: #94a3b8;">Nozzles</span></div>
            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Dispensing outlets configured</div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="font-size: 12.5px; font-weight: 700; color: #64748b; text-transform: uppercase;">Active Status</div>
            <div style="font-size: 28px; font-weight: 800; color: #16a34a; margin-top: 6px;"><?= $active_nozzles ?> <span style="font-size: 14px; font-weight: 600; color: #94a3b8;">Active</span></div>
            <div style="font-size: 12px; color: #16a34a; margin-top: 4px;">Ready for sales transactions</div>
        </div>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <div style="font-size: 12.5px; font-weight: 700; color: #64748b; text-transform: uppercase;">Inactive / Maint.</div>
            <div style="font-size: 28px; font-weight: 800; color: <?= $inactive_nozzles > 0 ? '#dc2626' : '#94a3b8' ?>; margin-top: 6px;"><?= $inactive_nozzles ?></div>
            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Offline or undergoing service</div>
        </div>
    </div>

    <!-- Main Table Card -->
    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden;">
        
        <!-- Filter Toolbar -->
        <div style="padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <input type="text" id="filterSearch" placeholder="🔍 Search pump, nozzle, fuel..." onkeyup="filterTable()" style="padding: 8px 14px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; width: 220px;">

                <select id="filterFuel" onchange="filterTable()" style="padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; color: #334155;">
                    <option value="">All Fuel Types</option>
                    <?php 
                    $ft_distinct = array_unique(array_column($nozzles_list, 'fuel_type_name'));
                    foreach ($ft_distinct as $ft_name): 
                    ?>
                        <option value="<?= htmlspecialchars($ft_name) ?>"><?= htmlspecialchars($ft_name) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="filterStatus" onchange="filterTable()" style="padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; color: #334155;">
                    <option value="">All Statuses</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                    <option value="Maintenance">Maintenance</option>
                </select>
            </div>

            <div style="font-size: 13px; font-weight: 600; color: #64748b;">
                Showing <span id="visibleRowCount" style="color:#002F6C;font-weight:700;"><?= count($nozzles_list) ?></span> configured nozzles
            </div>
        </div>

        <!-- Table -->
        <div style="overflow-x: auto;">
            <table id="pumpNozzleTable" style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 12.5px; text-transform: uppercase; letter-spacing: 0.5px;">
                        <th style="padding: 14px 20px; font-weight: 800;">Pump</th>
                        <th style="padding: 14px 20px; font-weight: 800;">Nozzle</th>
                        <th style="padding: 14px 20px; font-weight: 800;">Fuel Type</th>
                        <th style="padding: 14px 20px; font-weight: 800;">Tank</th>
                        <th style="padding: 14px 20px; font-weight: 800; text-align: center;">Status</th>
                        <th style="padding: 14px 20px; font-weight: 800; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($nozzles_list)): ?>
                        <tr>
                            <td colspan="6" style="padding: 40px; text-align: center; color: #94a3b8;">
                                <i class="fas fa-gas-pump" style="font-size: 36px; margin-bottom: 12px; color: #cbd5e1; display: block;"></i>
                                <div style="font-size: 16px; font-weight: 700; color: #475569;">No pumps or nozzles configured for this station yet.</div>
                                <div style="font-size: 13.5px; margin-top: 4px;">Click "Add Pump & Nozzle" or configure pumps when adding a fuel product.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($nozzles_list as $n): 
                            $is_active = (strtolower($n['status']) === 'active');
                            $is_maint  = (strtolower($n['status']) === 'maintenance');
                            $status_bg = $is_active ? '#dcfce7' : ($is_maint ? '#fef3c7' : '#fee2e2');
                            $status_color = $is_active ? '#166534' : ($is_maint ? '#92400e' : '#991b1b');
                            $status_border = $is_active ? '#86efac' : ($is_maint ? '#fde68a' : '#fca5a5');
                        ?>
                            <tr class="nozzle-row" 
                                data-pump="<?= htmlspecialchars(strtolower($n['pump_name'])) ?>" 
                                data-nozzle="<?= htmlspecialchars(strtolower($n['nozzle_number'])) ?>" 
                                data-fuel="<?= htmlspecialchars(strtolower($n['fuel_type_name'])) ?>" 
                                data-status="<?= htmlspecialchars($n['status']) ?>"
                                style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" 
                                onmouseover="this.style.background='#f8fafc'" 
                                onmouseout="this.style.background='#ffffff'">
                                
                                <td style="padding: 14px 20px; font-weight: 800; color: #002F6C;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: #e0f2fe; color: #0369a1; font-size: 12px;">
                                            <i class="fas fa-gas-pump"></i>
                                        </span>
                                        <span><?= htmlspecialchars($n['pump_name']) ?></span>
                                    </div>
                                </td>

                                <td style="padding: 14px 20px; font-weight: 700; color: #334155;">
                                    <span style="background: #f1f5f9; color: #1e293b; padding: 4px 10px; border-radius: 6px; font-size: 13px; border: 1px solid #e2e8f0;">
                                        <?= htmlspecialchars($n['nozzle_number']) ?>
                                    </span>
                                </td>

                                <td style="padding: 14px 20px;">
                                    <span style="font-weight: 700; color: #002F6C; font-size: 14px;">
                                        <?= htmlspecialchars($n['fuel_type_name']) ?>
                                    </span>
                                </td>

                                <td style="padding: 14px 20px;">
                                    <span style="background: #e0e7ff; color: #3730a3; padding: 3px 8px; border-radius: 4px; font-size: 12.5px; font-weight: 700; border: 1px solid #c7d2fe;">
                                        <?= htmlspecialchars($n['ugt_no']) ?>
                                    </span>
                                </td>

                                <td style="padding: 14px 20px; text-align: center;">
                                    <span style="background: <?= $status_bg ?>; color: <?= $status_color ?>; border: 1px solid <?= $status_border ?>; padding: 4px 12px; border-radius: 20px; font-size: 12.5px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                                        <span style="width: 7px; height: 7px; border-radius: 50%; background: <?= $status_color ?>;"></span>
                                        <?= htmlspecialchars($n['status']) ?>
                                    </span>
                                </td>

                                <td style="padding: 14px 20px; text-align: right; white-space: nowrap;">
                                    <!-- Edit Button -->
                                    <button type="button" 
                                            onclick='openEditNozzleModal(<?= json_encode($n) ?>)'
                                            style="background: #f1f5f9; color: #002F6C; border: 1px solid #cbd5e1; padding: 6px 12px; border-radius: 5px; font-size: 12.5px; font-weight: 700; cursor: pointer; margin-right: 6px;">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>

                                    <!-- Quick Toggle Status -->
                                    <form method="POST" action="manager_pump_nozzle_config.php" style="display: inline-block; margin: 0;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="nozzle_id" value="<?= (int)$n['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $is_active ? 'Inactive' : 'Active' ?>">
                                        <button type="submit" 
                                                style="background: <?= $is_active ? '#fee2e2' : '#dcfce7' ?>; color: <?= $is_active ? '#991b1b' : '#166534' ?>; border: 1px solid <?= $is_active ? '#fca5a5' : '#86efac' ?>; padding: 6px 10px; border-radius: 5px; font-size: 12.5px; font-weight: 700; cursor: pointer;">
                                            <i class="fas <?= $is_active ? 'fa-ban' : 'fa-check' ?>"></i> <?= $is_active ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Add / Edit Pump & Nozzle -->
<div id="nozzleModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
    <div style="background: #ffffff; border-radius: 12px; width: 94%; max-width: 580px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); overflow: hidden; animation: popIn 0.2s ease-out;">
        
        <!-- Modal Header -->
        <div style="background: linear-gradient(135deg, #002F6C, #004494); padding: 16px 24px; display: flex; align-items: center; justify-content: space-between;">
            <h3 id="nozzleModalTitle" style="margin: 0; color: #ffffff; font-size: 17px; font-weight: 800; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-gas-pump"></i> <span>Add Pump & Nozzle</span>
            </h3>
            <button type="button" onclick="closeNozzleModal()" style="background: transparent; border: none; color: #ffffff; font-size: 18px; cursor: pointer;">
                &times;
            </button>
        </div>

        <!-- Modal Form -->
        <form method="POST" action="manager_pump_nozzle_config.php" style="padding: 20px 24px;">
            <input type="hidden" name="action" value="save_nozzle">
            <input type="hidden" name="nozzle_id" id="modalNozzleId" value="0">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; text-transform: uppercase; margin-bottom: 5px;">
                        Pump Name / Number <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" name="pump_name" id="modalPumpName" required placeholder="e.g. Pump 1"
                           style="width: 100%; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 14.5px; box-sizing: border-box;">
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; text-transform: uppercase; margin-bottom: 5px;">
                        Nozzle Number <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" name="nozzle_number" id="modalNozzleNumber" required placeholder="e.g. Nozzle 1"
                           style="width: 100%; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 14.5px; box-sizing: border-box;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; text-transform: uppercase; margin-bottom: 5px;">
                        Fuel Type <span style="color: #dc2626;">*</span>
                    </label>
                    <select name="fuel_type_id" id="modalFuelTypeId" required onchange="autoSelectTank(this)"
                            style="width: 100%; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 14.5px; box-sizing: border-box; background: #fff;">
                        <option value="">Select Fuel Product</option>
                        <?php foreach ($active_fuels as $af): ?>
                            <option value="<?= (int)$af['fuel_type_id'] ?>" data-ugt="<?= htmlspecialchars($af['ugt_no']) ?>">
                                <?= htmlspecialchars($af['fuel_type']) ?> (<?= htmlspecialchars($af['ugt_no']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; text-transform: uppercase; margin-bottom: 5px;">
                        Assigned Tank (UGT) <span style="color: #dc2626;">*</span>
                    </label>
                    <select name="ugt_no" id="modalUgtNo" required
                            style="width: 100%; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 14.5px; box-sizing: border-box; background: #fff;">
                        <option value="UGT-01">UGT-01 (Tank 1)</option>
                        <option value="UGT-02">UGT-02 (Tank 2)</option>
                        <option value="UGT-03">UGT-03 (Tank 3)</option>
                        <option value="UGT-04">UGT-04 (Tank 4)</option>
                        <option value="UGT-05">UGT-05 (Tank 5)</option>
                        <option value="UGT-06">UGT-06 (Tank 6)</option>
                        <option value="UGT-07">UGT-07 (Tank 7 - Kerosene)</option>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; text-transform: uppercase; margin-bottom: 5px;">
                        Status <span style="color: #dc2626;">*</span>
                    </label>
                    <select name="status" id="modalStatus" required
                            style="width: 100%; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 14.5px; box-sizing: border-box; background: #fff;">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Maintenance">Maintenance</option>
                    </select>
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; text-transform: uppercase; margin-bottom: 5px;">
                        Calibration Value (L)
                    </label>
                    <input type="number" name="calibration_value" id="modalCalibration" step="0.01" min="0" value="0.00"
                           style="width: 100%; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 14.5px; box-sizing: border-box;">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 14px;">
                <button type="button" onclick="closeNozzleModal()" style="background: #f1f5f9; color: #002F6C; border: 1px solid #cbd5e1; padding: 8px 18px; border-radius: 6px; font-weight: 700; cursor: pointer;">
                    Cancel
                </button>
                <button type="submit" style="background: #002F6C; color: #ffffff; border: none; padding: 8px 22px; border-radius: 6px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fas fa-save"></i> Save Configuration
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openNozzleModal() {
    document.getElementById('modalNozzleId').value = '0';
    document.getElementById('nozzleModalTitle').querySelector('span').textContent = 'Add Pump & Nozzle';
    document.getElementById('modalPumpName').value = 'Pump 1';
    document.getElementById('modalNozzleNumber').value = 'Nozzle 1';
    document.getElementById('modalFuelTypeId').value = '';
    document.getElementById('modalUgtNo').value = 'UGT-01';
    document.getElementById('modalStatus').value = 'Active';
    document.getElementById('modalCalibration').value = '0.00';
    document.getElementById('nozzleModal').style.display = 'flex';
}

function openEditNozzleModal(data) {
    document.getElementById('modalNozzleId').value = data.id || '0';
    document.getElementById('nozzleModalTitle').querySelector('span').textContent = 'Edit Pump & Nozzle';
    document.getElementById('modalPumpName').value = data.pump_name || 'Pump 1';
    document.getElementById('modalNozzleNumber').value = data.nozzle_number || 'Nozzle 1';
    document.getElementById('modalFuelTypeId').value = data.fuel_type_id || '';
    document.getElementById('modalUgtNo').value = data.ugt_no || 'UGT-01';
    document.getElementById('modalStatus').value = data.status || 'Active';
    document.getElementById('modalCalibration').value = parseFloat(data.calibration_value || 0).toFixed(2);
    document.getElementById('nozzleModal').style.display = 'flex';
}

function closeNozzleModal() {
    document.getElementById('nozzleModal').style.display = 'none';
}

function autoSelectTank(sel) {
    var opt = sel.options[sel.selectedIndex];
    var ugt = opt ? opt.getAttribute('data-ugt') : '';
    if (ugt) {
        var ugtSel = document.getElementById('modalUgtNo');
        if (ugtSel) {
            for (var i = 0; i < ugtSel.options.length; i++) {
                if (ugtSel.options[i].value.toLowerCase() === ugt.toLowerCase()) {
                    ugtSel.selectedIndex = i;
                    break;
                }
            }
        }
    }
}

function filterTable() {
    var search = (document.getElementById('filterSearch').value || '').toLowerCase().trim();
    var fuel   = (document.getElementById('filterFuel').value || '').toLowerCase().trim();
    var status = (document.getElementById('filterStatus').value || '').toLowerCase().trim();

    var rows = document.querySelectorAll('.nozzle-row');
    var visible = 0;

    rows.forEach(function(row) {
        var rPump   = row.getAttribute('data-pump') || '';
        var rNozzle = row.getAttribute('data-nozzle') || '';
        var rFuel   = row.getAttribute('data-fuel') || '';
        var rStatus = row.getAttribute('data-status') || '';

        var matchSearch = (!search || rPump.indexOf(search) !== -1 || rNozzle.indexOf(search) !== -1 || rFuel.indexOf(search) !== -1);
        var matchFuel   = (!fuel || rFuel === fuel);
        var matchStatus = (!status || rStatus.toLowerCase() === status);

        if (matchSearch && matchFuel && matchStatus) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    var countEl = document.getElementById('visibleRowCount');
    if (countEl) countEl.textContent = visible;
}

// Close on outside click
document.getElementById('nozzleModal').addEventListener('click', function(e) {
    if (e.target === this) closeNozzleModal();
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
