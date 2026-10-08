<?php
/**
 * Backend API: Fuel Stock Adjustments & Workflow Engine
 * Handled actions:
 *   - request_adjustment (Manager creates adjustment request after tank dip variance)
 *   - approve_adjustment (Admin approves adjustment request & updates UGT current volume)
 *   - reject_adjustment (Admin rejects adjustment request, inventory unchanged)
 *   - get_adjustments (Fetch pending/history adjustments for UI)
 *   - get_movements (Fetch complete Fuel Inventory Movement History & Audit Trail)
 */
error_reporting(0);
@ini_set('display_errors', '0');
ob_start();

header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();
$action     = $_GET['action'] ?? $_POST['action'] ?? '';
$method     = $_SERVER['REQUEST_METHOD'];

// Ensure supporting tables exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_adjustments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL,
        adjustment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fuel_type VARCHAR(100) NOT NULL,
        fuel_type_id INT DEFAULT NULL,
        ugt_no VARCHAR(50) DEFAULT NULL,
        adjustment_type VARCHAR(100) NOT NULL,
        liters DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        adjustment_direction VARCHAR(20) NOT NULL DEFAULT 'Decrease',
        previous_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        new_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        variance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        reason VARCHAR(255) NOT NULL,
        notes TEXT DEFAULT NULL,
        user_id INT NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Pending Admin Approval',
        approved_by INT DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_fa_station (station_id),
        INDEX idx_fa_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add missing columns if any
    $cols = array_column($pdo->query("SHOW COLUMNS FROM fuel_adjustments")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('ugt_no', $cols)) { $pdo->exec("ALTER TABLE fuel_adjustments ADD COLUMN ugt_no VARCHAR(50) DEFAULT NULL"); }
    if (!in_array('adjustment_direction', $cols)) { $pdo->exec("ALTER TABLE fuel_adjustments ADD COLUMN adjustment_direction VARCHAR(20) NOT NULL DEFAULT 'Decrease'"); }
    if (!in_array('variance', $cols)) { $pdo->exec("ALTER TABLE fuel_adjustments ADD COLUMN variance DECIMAL(10,2) NOT NULL DEFAULT 0.00"); }
} catch (Exception $e) {}

try {
    switch ($action) {

        // ══════════════════════════════════════════════════════════════════════
        // ADMIN / OWNER DIRECT FUEL STOCK ADJUSTMENT (INSTANT SYSTEM UPDATE)
        // ══════════════════════════════════════════════════════════════════════
        case 'direct_adjustment':
            if ($method !== 'POST') respond(false, 'Method not allowed.');
            if (!in_array($role, ['admin', 'superadmin', 'developer'])) {
                respond(false, 'Admin access required for direct inventory adjustment.');
            }

            $fuel_type          = trim($_POST['fuel_type'] ?? '');
            $ugt_no             = trim($_POST['ugt_no'] ?? '');
            $actual_dip_volume  = (float)($_POST['actual_dip_volume'] ?? 0);
            $adjustment_type    = trim($_POST['adjustment_type'] ?? 'Physical Count / Tank Dip');
            $reason             = trim($_POST['reason'] ?? '');
            $remarks            = trim($_POST['remarks'] ?? '');

            if (empty($fuel_type)) respond(false, 'Fuel type is required.');
            if (empty($reason))    respond(false, 'Reason for adjustment is required.');
            if ($actual_dip_volume < 0) respond(false, 'Actual volume cannot be negative.');

            // Smart match tank record in fuel_inventory
            $inv = null;
            if ($ugt_no) {
                $clean_ugt = (int)preg_replace('/[^0-9]/', '', $ugt_no);
                $stmt = $pdo->prepare("
                    SELECT id, fuel_type, fuel_type_id, current_level, capacity, ugt_no
                    FROM fuel_inventory
                    WHERE station_id = ?
                      AND (LOWER(TRIM(ugt_no)) = LOWER(TRIM(?)) OR ugt_no = ? OR id = ?)
                    LIMIT 1
                ");
                $stmt->execute([$station_id, $ugt_no, $clean_ugt, $clean_ugt]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$inv) {
                $stmt = $pdo->prepare("
                    SELECT id, fuel_type, fuel_type_id, current_level, capacity, ugt_no
                    FROM fuel_inventory
                    WHERE station_id = ?
                      AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    LIMIT 1
                ");
                $stmt->execute([$station_id, $fuel_type]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$inv) {
                $stmt = $pdo->prepare("
                    SELECT id, fuel_type, fuel_type_id, current_level, capacity, ugt_no
                    FROM fuel_inventory
                    WHERE station_id = ?
                      AND LOWER(TRIM(fuel_type)) LIKE LOWER(CONCAT('%', ?, '%'))
                    LIMIT 1
                ");
                $stmt->execute([$station_id, explode(' ', $fuel_type)[0]]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$inv) {
                respond(false, 'Fuel inventory record not found for ' . htmlspecialchars($fuel_type));
            }

            $current_volume = (float)($inv['current_level'] ?? 0);
            $variance       = round($actual_dip_volume - $current_volume, 2);
            $direction      = ($variance >= 0) ? 'Increase' : 'Decrease';
            $adj_liters     = abs($variance);
            $ugt_display    = $ugt_no ?: ($inv['ugt_no'] ?? 'UGT-01');

            if ($actual_dip_volume > (float)$inv['capacity']) {
                respond(false, 'Adjusted volume (' . number_format($actual_dip_volume, 2) . ' L) exceeds tank capacity of ' . number_format((float)$inv['capacity'], 0) . ' L.');
            }

            $pdo->beginTransaction();

            // 1. Directly update UGT volume in fuel_inventory
            $pdo->prepare("
                UPDATE fuel_inventory
                SET current_level = ?, current_stock = ?, last_updated = NOW()
                WHERE id = ? AND station_id = ?
            ")->execute([$actual_dip_volume, $actual_dip_volume, $inv['id'], $station_id]);

            // 2. Record in fuel_adjustments as Approved immediately (Owner direct calibration)
            $ins = $pdo->prepare("
                INSERT INTO fuel_adjustments
                (station_id, adjustment_date, fuel_type, fuel_type_id, ugt_no,
                 adjustment_type, liters, adjustment_direction, previous_value,
                 new_value, variance, reason, notes, user_id, status, approved_by, approved_at, created_at)
                VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', ?, NOW(), NOW())
            ");
            $ins->execute([
                $station_id,
                $inv['fuel_type'],
                $inv['fuel_type_id'] ?? null,
                $ugt_display,
                $adjustment_type,
                $adj_liters,
                $direction,
                $current_volume,
                $actual_dip_volume,
                $variance,
                $reason,
                $remarks,
                $me['id'],
                $me['id']
            ]);

            $adj_id = $pdo->lastInsertId();

            // 3. Record in fuel_audit_trail
            try {
                $sign = ($variance >= 0) ? '+' : '';
                $pdo->prepare("
                    INSERT INTO fuel_audit_trail
                    (reading_id, action, before_value, after_value, stock_before, stock_after, performed_by, performed_at, notes)
                    VALUES (NULL, 'Admin Direct Tank Dip Adjustment', ?, ?, ?, ?, ?, NOW(), ?)
                ")->execute([
                    $current_volume,
                    $actual_dip_volume,
                    $current_volume,
                    $actual_dip_volume,
                    $me['id'],
                    "Admin / Owner directly calibrated {$inv['fuel_type']} ({$ugt_display}) from {$current_volume}L to {$actual_dip_volume}L (Variance: {$sign}{$variance}L). Reason: {$reason}"
                ]);
            } catch (Exception $e) {}

            $pdo->commit();

            respond(true, 'Fuel reading adjusted successfully! Current volume updated to ' . number_format($actual_dip_volume, 2) . ' L.', [
                'adjustment_id'   => $adj_id,
                'fuel_type'       => $inv['fuel_type'],
                'ugt_no'          => $ugt_display,
                'previous_volume' => $current_volume,
                'updated_volume'  => $actual_dip_volume,
                'variance'        => $variance,
                'status'          => 'Approved'
            ]);

        // ══════════════════════════════════════════════════════════════════════
        // STEP 5: MANAGER REQUESTS FUEL STOCK ADJUSTMENT
        // ══════════════════════════════════════════════════════════════════════
        case 'request_adjustment':
        case 'create_adjustment':
            if ($method !== 'POST') respond(false, 'Method not allowed.');
            if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
                respond(false, 'Manager access required to create adjustment requests.');
            }

            $fuel_type          = trim($_POST['fuel_type'] ?? '');
            $ugt_no             = trim($_POST['ugt_no'] ?? '');
            $actual_dip_volume  = (float)($_POST['actual_dip_volume'] ?? 0);
            $adjustment_type    = trim($_POST['adjustment_type'] ?? 'Physical Count / Tank Dip');
            $reason             = trim($_POST['reason'] ?? '');
            $remarks            = trim($_POST['remarks'] ?? '');

            if (empty($fuel_type)) respond(false, 'Fuel type is required.');
            if (empty($reason))    respond(false, 'Reason for adjustment is required.');

            // Smart match tank record in fuel_inventory
            $inv = null;
            if ($ugt_no) {
                $clean_ugt = (int)preg_replace('/[^0-9]/', '', $ugt_no);
                $stmt = $pdo->prepare("
                    SELECT id, fuel_type, fuel_type_id, current_level, capacity, ugt_no
                    FROM fuel_inventory
                    WHERE station_id = ?
                      AND (LOWER(TRIM(ugt_no)) = LOWER(TRIM(?)) OR ugt_no = ? OR id = ?)
                    LIMIT 1
                ");
                $stmt->execute([$station_id, $ugt_no, $clean_ugt, $clean_ugt]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$inv && $fuel_type) {
                $stmt = $pdo->prepare("
                    SELECT id, fuel_type, fuel_type_id, current_level, capacity, ugt_no
                    FROM fuel_inventory
                    WHERE station_id = ?
                      AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    LIMIT 1
                ");
                $stmt->execute([$station_id, $fuel_type]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$inv && $fuel_type) {
                $stmt = $pdo->prepare("
                    SELECT id, fuel_type, fuel_type_id, current_level, capacity, ugt_no
                    FROM fuel_inventory
                    WHERE station_id = ?
                      AND LOWER(TRIM(fuel_type)) LIKE LOWER(CONCAT('%', ?, '%'))
                    LIMIT 1
                ");
                $stmt->execute([$station_id, explode(' ', $fuel_type)[0]]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$inv) {
                respond(false, 'Fuel inventory record not found for ' . htmlspecialchars($fuel_type));
            }

            $current_volume = (float)($inv['current_level'] ?? 0);
            $variance       = round($actual_dip_volume - $current_volume, 2);

            if (abs($variance) < 0.01 && empty($_POST['forced'])) {
                respond(false, 'System Current Volume and Tank Dip Volume are equal (0 L variance). No adjustment required.');
            }

            $direction = ($variance >= 0) ? 'Increase' : 'Decrease';
            $adj_liters = abs($variance);
            if (isset($_POST['liters']) && (float)$_POST['liters'] > 0) {
                $adj_liters = (float)$_POST['liters'];
            }
            if (isset($_POST['adjustment_direction'])) {
                $direction = trim($_POST['adjustment_direction']);
            }

            $ugt_display = $ugt_no ?: ($inv['ugt_no'] ?? 'UGT-01');

            $pdo->beginTransaction();

            $ins = $pdo->prepare("
                INSERT INTO fuel_adjustments
                (station_id, adjustment_date, fuel_type, fuel_type_id, ugt_no,
                 adjustment_type, liters, adjustment_direction, previous_value,
                 new_value, variance, reason, notes, user_id, status, created_at)
                VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Admin Approval', NOW())
            ");
            $ins->execute([
                $station_id,
                $fuel_type,
                $inv['fuel_type_id'] ?? null,
                $ugt_display,
                $adjustment_type,
                $adj_liters,
                $direction,
                $current_volume,
                $actual_dip_volume,
                $variance,
                $reason,
                $remarks,
                $me['id']
            ]);

            $adj_id = $pdo->lastInsertId();

            // Record Audit Trail
            try {
                $pdo->prepare("
                    INSERT INTO fuel_audit_trail
                    (reading_id, action, before_value, after_value, stock_before, stock_after, performed_by, performed_at, notes)
                    VALUES (NULL, 'Variance Detected & Adjustment Requested', ?, ?, ?, ?, ?, NOW(), ?)
                ")->execute([
                    $current_volume,
                    $actual_dip_volume,
                    $current_volume,
                    $current_volume, // unchanged until Admin approval
                    $me['id'],
                    "Submitted {$adjustment_type} request for {$fuel_type} ({$direction} {$adj_liters}L). Variance: {$variance}L. Reason: {$reason}"
                ]);
            // Create notification for Station Admin & Superadmin users
            try {
                $adm_stmt = $pdo->prepare("
                    SELECT id FROM users
                    WHERE (station_id = ? OR role IN ('superadmin', 'developer'))
                      AND LOWER(TRIM(role)) IN ('admin', 'superadmin', 'developer')
                      AND status = 'Active'
                ");
                $adm_stmt->execute([$station_id]);
                $admins = $adm_stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($admins)) {
                    $notif_ins = $pdo->prepare("
                        INSERT INTO notifications
                        (user_id, type, title, message, event_type, severity, redirect_url, status, created_at)
                        VALUES (?, 'warning', 'Fuel Adjustments Awaiting Approval', ?, 'fuel_adjustment', 'medium', 'admin_inventory_fuel.php?tab=adjustments', 'unread', NOW())
                    ");
                    $notif_msg = "1 physical tank dip / fuel reading adjustment(s) require Admin review and approval.";
                    foreach ($admins as $adm_id) {
                        $notif_ins->execute([$adm_id, $notif_msg]);
                    }
                }
            } catch (Exception $e) {}

            $pdo->commit();

            respond(true, 'Fuel stock adjustment request submitted successfully. Pending Admin approval.', [
                'adjustment_id'        => $adj_id,
                'fuel_type'            => $fuel_type,
                'ugt_no'               => $ugt_display,
                'current_volume'       => $current_volume,
                'actual_dip_volume'    => $actual_dip_volume,
                'variance'             => $variance,
                'adjustment_direction' => $direction,
                'liters'               => $adj_liters,
                'status'               => 'Pending Admin Approval'
            ]);

        // ══════════════════════════════════════════════════════════════════════
        // STEP 6: ADMIN APPROVES FUEL STOCK ADJUSTMENT
        // ══════════════════════════════════════════════════════════════════════
        case 'approve_adjustment':
            if ($method !== 'POST') respond(false, 'Method not allowed.');
            if (!in_array($role, ['admin', 'superadmin', 'developer'])) {
                respond(false, 'Admin access required to approve stock adjustments.');
            }

            $adj_id = (int)($_POST['adjustment_id'] ?? $_POST['id'] ?? 0);
            if ($adj_id <= 0) respond(false, 'Valid Adjustment ID is required.');

            $stmt = $pdo->prepare("
                SELECT * FROM fuel_adjustments
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$adj_id, $station_id]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) respond(false, 'Adjustment request record not found.');
            if (strtolower(trim($adj['status'])) === 'approved') {
                respond(false, 'This adjustment request has already been approved.');
            }

            $fuel_type = $adj['fuel_type'];
            $direction = $adj['adjustment_direction'] ?? ($adj['variance'] >= 0 ? 'Increase' : 'Decrease');
            $liters    = (float)$adj['liters'];

            $pdo->beginTransaction();

            // Fetch current tank volume
            $inv_stmt = $pdo->prepare("
                SELECT id, current_level, capacity FROM fuel_inventory
                WHERE station_id = ?
                  AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                LIMIT 1
            ");
            $inv_stmt->execute([$station_id, $fuel_type]);
            $inv = $inv_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inv) respond(false, 'Fuel inventory record not found for ' . htmlspecialchars($fuel_type));

            $old_vol = (float)$inv['current_level'];
            if (strtolower($direction) === 'increase') {
                $new_vol = $old_vol + $liters;
            } else {
                $new_vol = max(0, $old_vol - $liters);
            }

            // 1. Update UGT Current Volume in fuel_inventory
            $pdo->prepare("
                UPDATE fuel_inventory
                SET current_level = ?, current_stock = ?, last_updated = NOW()
                WHERE id = ? AND station_id = ?
            ")->execute([$new_vol, $new_vol, $inv['id'], $station_id]);

            // 2. Mark adjustment as Approved
            $pdo->prepare("
                UPDATE fuel_adjustments
                SET status = 'Approved', approved_by = ?, approved_at = NOW(), new_value = ?
                WHERE id = ?
            ")->execute([$me['id'], $new_vol, $adj_id]);

            // 3. Log Movement & Audit Trail
            try {
                $sign = (strtolower($direction) === 'increase') ? '+' : '-';
                $pdo->prepare("
                    INSERT INTO fuel_audit_trail
                    (reading_id, action, before_value, after_value, stock_before, stock_after, performed_by, performed_at, notes)
                    VALUES (NULL, 'Fuel Stock Adjustment Approved', ?, ?, ?, ?, ?, NOW(), ?)
                ")->execute([
                    $old_vol,
                    $new_vol,
                    $old_vol,
                    $new_vol,
                    $me['id'],
                    "Admin approved {$adj['adjustment_type']} request. Liters: {$sign}{$liters}L. Remaining Volume: {$new_vol}L."
                ]);
            } catch (Exception $e) {}

            $pdo->commit();

            respond(true, 'Fuel stock adjustment approved and inventory updated successfully.', [
                'adjustment_id'   => $adj_id,
                'fuel_type'       => $fuel_type,
                'previous_volume' => $old_vol,
                'updated_volume'  => $new_vol,
                'status'          => 'Approved'
            ]);

        // ══════════════════════════════════════════════════════════════════════
        // STEP 6: ADMIN REJECTS FUEL STOCK ADJUSTMENT
        // ══════════════════════════════════════════════════════════════════════
        case 'reject_adjustment':
            if ($method !== 'POST') respond(false, 'Method not allowed.');
            if (!in_array($role, ['admin', 'superadmin', 'developer'])) {
                respond(false, 'Admin access required to reject stock adjustments.');
            }

            $adj_id        = (int)($_POST['adjustment_id'] ?? $_POST['id'] ?? 0);
            $reject_reason = trim($_POST['reason'] ?? $_POST['reject_reason'] ?? 'Rejected by Admin');

            if ($adj_id <= 0) respond(false, 'Valid Adjustment ID is required.');

            $stmt = $pdo->prepare("
                SELECT * FROM fuel_adjustments
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$adj_id, $station_id]);
            $adj = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$adj) respond(false, 'Adjustment request record not found.');

            $pdo->beginTransaction();

            $pdo->prepare("
                UPDATE fuel_adjustments
                SET status = 'Rejected', notes = CONCAT(COALESCE(notes,''), ' | Rejection Reason: ', ?), approved_by = ?, approved_at = NOW()
                WHERE id = ?
            ")->execute([$reject_reason, $me['id'], $adj_id]);

            try {
                $pdo->prepare("
                    INSERT INTO fuel_audit_trail
                    (reading_id, action, before_value, after_value, stock_before, stock_after, performed_by, performed_at, notes)
                    VALUES (NULL, 'Fuel Stock Adjustment Rejected', ?, ?, ?, ?, ?, NOW(), ?)
                ")->execute([
                    (float)$adj['previous_value'],
                    (float)$adj['previous_value'],
                    (float)$adj['previous_value'],
                    (float)$adj['previous_value'],
                    $me['id'],
                    "Admin rejected adjustment request for {$adj['fuel_type']}. Reason: {$reject_reason}. Current Volume remains unchanged."
                ]);
            } catch (Exception $e) {}

            $pdo->commit();

            respond(true, 'Fuel stock adjustment request rejected. No changes made to inventory.', [
                'adjustment_id' => $adj_id,
                'status'        => 'Rejected'
            ]);

        // ══════════════════════════════════════════════════════════════════════
        // FETCH FUEL INVENTORY MOVEMENT HISTORY & AUDIT TRAIL
        // ══════════════════════════════════════════════════════════════════════
        case 'get_movements':
            $fuel_type = trim($_GET['fuel_type'] ?? '');
            
            $movements = [];

            // 1. Deliveries (+ Liters)
            try {
                $sql1 = "
                    SELECT
                        fd.delivery_date AS event_date,
                        fd.fuel_type,
                        'Fuel Delivery' AS transaction_type,
                        CONCAT('+', FORMAT(fd.delivery_liters, 2), ' L') AS liters_display,
                        fd.delivery_liters AS liters,
                        'Manager' AS performed_by,
                        fd.status
                    FROM fuel_deliveries fd
                    WHERE fd.station_id = ?
                      AND fd.status IN ('Verified', 'Completed')
                ";
                $p1 = [$station_id];
                if ($fuel_type) { $sql1 .= " AND LOWER(fd.fuel_type) LIKE LOWER(?)"; $p1[] = '%' . $fuel_type . '%'; }
                $stmt1 = $pdo->prepare($sql1);
                $stmt1->execute($p1);
                $movements = array_merge($movements, $stmt1->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {}

            // 2. Dispensed Fuel (- Liters) from verified transactions
            try {
                $sql2 = "
                    SELECT
                        ft.transaction_date AS event_date,
                        ft.fuel_type,
                        'Fuel Dispensed' AS transaction_type,
                        CONCAT('-', FORMAT(ft.liters_sold, 2), ' L') AS liters_display,
                        -ft.liters_sold AS liters,
                        'System' AS performed_by,
                        ft.status
                    FROM fuel_transactions ft
                    WHERE ft.station_id = ?
                      AND LOWER(ft.status) IN ('approved', 'verified')
                ";
                $p2 = [$station_id];
                if ($fuel_type) { $sql2 .= " AND LOWER(ft.fuel_type) LIKE LOWER(?)"; $p2[] = '%' . $fuel_type . '%'; }
                $stmt2 = $pdo->prepare($sql2);
                $stmt2->execute($p2);
                $movements = array_merge($movements, $stmt2->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {}

            // 3. Approved Stock Adjustments (± Liters)
            try {
                $sql3 = "
                    SELECT
                        fa.created_at AS event_date,
                        fa.fuel_type,
                        'Fuel Stock Adjustment' AS transaction_type,
                        CONCAT(IF(fa.adjustment_direction = 'Increase', '+', '-'), FORMAT(fa.liters, 2), ' L') AS liters_display,
                        IF(fa.adjustment_direction = 'Increase', fa.liters, -fa.liters) AS liters,
                        'Admin' AS performed_by,
                        fa.status
                    FROM fuel_adjustments fa
                    WHERE fa.station_id = ?
                      AND LOWER(fa.status) = 'approved'
                ";
                $p3 = [$station_id];
                if ($fuel_type) { $sql3 .= " AND LOWER(fa.fuel_type) LIKE LOWER(?)"; $p3[] = '%' . $fuel_type . '%'; }
                $stmt3 = $pdo->prepare($sql3);
                $stmt3->execute($p3);
                $movements = array_merge($movements, $stmt3->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {}

            // Sort movements descending by date
            usort($movements, function($a, $b) {
                return strtotime($b['event_date']) - strtotime($a['event_date']);
            });

            // Fetch Audit Trail logs
            $audit_trail = [];
            try {
                $sql4 = "
                    SELECT fat.*, COALESCE(NULLIF(CONCAT(TRIM(u.first_name), ' ', TRIM(u.last_name)), ' '), u.username, 'System') AS performer_name
                    FROM fuel_audit_trail fat
                    LEFT JOIN users u ON fat.performed_by = u.id
                    ORDER BY fat.performed_at DESC
                    LIMIT 50
                ";
                $audit_trail = $pdo->query($sql4)->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}

            respond(true, '', [
                'movements'   => $movements,
                'audit_trail' => $audit_trail
            ]);

        default:
            respond(false, 'Invalid action requested.');
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respond(false, 'Server error: ' . $e->getMessage());
}

function respond(bool $ok, string $msg = '', array $data = []): void {
    if (ob_get_level()) ob_end_clean();
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $data));
    exit;
}
