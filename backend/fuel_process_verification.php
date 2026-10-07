<?php
/**
 * Backend Processing: Fuel Verification Actions
 * Handles all manager/admin verification and approval actions
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if user is logged in
require_login();
$me = current_user();

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'verify_reading':
            handleVerifyReading();
            break;
            
        case 'verify_delivery':
            handleVerifyDelivery();
            break;
            
        case 'approve_adjustment':
            handleApproveAdjustment();
            break;
            
        case 'investigate_variance':
            handleInvestigateVariance();
            break;
            
        case 'approve_variance':
            handleApproveVariance();
            break;
            
        default:
            throw new Exception('Invalid action specified');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
exit;

/**
 * Handle pump reading verification by manager
 */
function handleVerifyReading() {
    global $pdo, $me, $response;
    
    // Check role permission
    if (!in_array(strtolower($me['role']), ['manager', 'admin', 'superadmin'])) {
        throw new Exception('Only managers, admins, or superadmins can verify readings');
    }
    
    $id = $_POST['id'] ?? 0;
    $status = $_POST['status'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    
    if (!$id || !in_array($status, ['Verified', 'Rejected'])) {
        throw new Exception('Invalid parameters provided');
    }
    
    if ($status === 'Rejected' && !$rejection_reason) {
        throw new Exception('Rejection reason is required');
    }
    
    // Build notes
    $manager_notes = "[Manager Verification by {$me['name']}]\n";
    if ($status === 'Rejected') {
        $manager_notes .= "REJECTED: $rejection_reason\n";
    }
    if ($notes) {
        $manager_notes .= "Notes: $notes\n";
    }
    $manager_notes .= "Date: " . date('Y-m-d H:i:s') . "\n";
    
    // Update reading
    $pdo->beginTransaction();
    
    try {
        // Update the reading status
        $stmt = $pdo->prepare("
            UPDATE fuel_daily_readings 
            SET status = ?, notes = CONCAT(COALESCE(notes,''), ?, '\n')
            WHERE id = ? AND station_id = ? AND status = 'Pending'
        ");
        $stmt->execute([$status, $manager_notes, $id, user_station_id()]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Reading not found or already processed');
        }
        
        // DEDUCT STOCK ON VERIFICATION
        if ($status === 'Verified') {
            // Get reading details with fuel type info
            $stmt = $pdo->prepare("
                SELECT dr.sales_liters, dr.pump_id, fp.fuel_type_id, ft.name as fuel_type_name
                FROM fuel_daily_readings dr
                JOIN fuel_pumps fp ON dr.pump_id = fp.id
                JOIN fuel_types ft ON fp.fuel_type_id = ft.id
                WHERE dr.id = ?
            ");
            $stmt->execute([$id]);
            $reading = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($reading && $reading['sales_liters'] > 0) {
                require_once __DIR__ . '/inventory_automation.php';
                
                // Check if stock already deducted for this reading
                $stmt = $pdo->prepare("
                    SELECT id FROM inventory_transactions 
                    WHERE reference_type = 'fuel_daily_readings' AND reference_id = ? AND transaction_type = 'pump_reading'
                ");
                $stmt->execute([$id]);
                if ($stmt->fetch()) {
                    throw new Exception('Stock already deducted for this reading');
                }
                
                // Get current stock level
                $stock_check = getCurrentStock($pdo, user_station_id(), $reading['fuel_type_id']);
                
                // BLOCK if insufficient stock
                if ($stock_check['stock_level'] < $reading['sales_liters']) {
                    $pdo->rollBack();
                    throw new Exception(sprintf(
                        'Insufficient stock for %s. Available: %.2f L, Required: %.2f L. Please verify fuel delivery before approving.',
                        $reading['fuel_type_name'],
                        $stock_check['stock_level'],
                        $reading['sales_liters']
                    ));
                }
                
                // Deduct stock (negative quantity)
                $result = recordStockMovement(
                    $pdo,
                    user_station_id(),
                    $reading['fuel_type_id'],
                    -$reading['sales_liters'],
                    'pump_reading',
                    'fuel_daily_readings',
                    $id,
                    $me['id'],
                    "Stock deducted after shift reading verification - Sales: {$reading['sales_liters']} L"
                );
                
                if (!$result['success']) {
                    $pdo->rollBack();
                    throw new Exception('Failed to deduct stock: ' . $result['message']);
                }
                
                // Add stock deduction info to response
                $response['stock_deducted'] = true;
                $response['stock_before'] = $result['stock_before'];
                $response['stock_after'] = $result['stock_after'];
                $response['fuel_type'] = $reading['fuel_type_name'];
                
                // BUGFIX: ALSO DEDUCT FROM `fuel_inventory` TO REFLECT IN TANK CONFIGURATIONS
                try {
                    $sales_liters = $reading['sales_liters'];
                    $fuel_type_name = $reading['fuel_type_name'];
                    // We don't have tank info from reading easily, so deduct from the first active tank matching the fuel type
                    // Using ORDER BY id ASC to deduct from oldest tank config, or we can just subtract generically.
                    $stmtFI = $pdo->prepare("UPDATE fuel_inventory SET current_level = COALESCE(current_level, 0) - ?, current_stock = COALESCE(current_stock, 0) - ? WHERE station_id = ? AND (LOWER(fuel_type) = LOWER(?) OR LOWER(fuel_type) LIKE ?)");
                    $stmtFI->execute([$sales_liters, $sales_liters, user_station_id(), $fuel_type_name, '%' . $fuel_type_name . '%']);
                } catch (Exception $e) {
                    // Ignore schema mismatch errors
                }
            }
        }
        
        // Log the activity
        log_activity($pdo, $me['id'], 
            'Manager ' . ($status === 'Verified' ? 'Verified' : 'Rejected') . ' Reading', 
            "Reading ID: $id - $status" . ($rejection_reason ? " ($rejection_reason)" : ''),
            'fuel_management'
        );
        
        $pdo->commit();
        
        $response['success'] = true;
        $response['message'] = "Reading $status successfully";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Handle delivery verification by manager
 */
function handleVerifyDelivery() {
    global $pdo, $me, $response;
    
    // Check role permission (allow manager, admin, superadmin)
    if (!in_array(strtolower($me['role']), ['manager', 'admin', 'superadmin'])) {
        throw new Exception('Only managers, admins, or superadmins can verify deliveries');
    }
    
    $id = $_POST['id'] ?? 0;
    $status = $_POST['status'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $actual_liters = floatval($_POST['actual_liters'] ?? 0);
    $quality = $_POST['quality'] ?? 'Good';
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    $station_id = $_POST['station_id'] ?? user_station_id();
    
    if (!$id || !in_array($status, ['Verified', 'Rejected'])) {
        throw new Exception('Invalid parameters provided');
    }
    
    if ($status === 'Rejected' && !$rejection_reason) {
        throw new Exception('Rejection reason is required');
    }
    
    if ($status === 'Verified' && $actual_liters <= 0) {
        throw new Exception('Valid actual liters amount is required');
    }
    
    // Build verification notes
    $manager_notes = "";
    if ($status === 'Rejected') {
        $manager_notes = "[REJECTED] Reason: " . $rejection_reason;
        if (!empty($notes)) {
            $manager_notes .= "\nNotes: " . $notes;
        }
        $manager_notes .= "\nReviewed by: " . $me['name'] . " on " . date('Y-m-d H:i:s');
    } else {
        $manager_notes = "[VERIFIED] Verified by " . $me['name'] . " on " . date('Y-m-d H:i:s');
        $manager_notes .= "\nActual amount: $actual_liters liters";
        $manager_notes .= "\nQuality assessment: $quality";
        if (!empty($notes)) {
            $manager_notes .= "\nNotes: " . $notes;
        }
    }
    
    // Get current delivery details first
    $stmt = $pdo->prepare("SELECT * FROM fuel_deliveries WHERE id = ?");
    $stmt->execute([$id]);
    $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$delivery) {
        throw new Exception('Delivery not found or access denied');
    }
    
    if (!in_array($delivery['status'], ['Pending', 'Pending Review', 'Encoded'])) {
        throw new Exception('This delivery has already been processed.');
    }
    
    $stockAdded = false;
    $stockBefore = null;
    $stockAfter = null;
    
    // ADD STOCK ON VERIFICATION
    if ($status === 'Verified') {
        // Get fuel_type from fuel_types table - handle both ID and name
        $fuel_type = null;
        $fuel_type_id = null;
        $fuel_type_name = null;
        
        // First try to find by ID if numeric
        if (is_numeric($delivery['fuel_type'])) {
            $stmt = $pdo->prepare("SELECT id, name FROM fuel_types WHERE id = ?");
            $stmt->execute([$delivery['fuel_type']]);
            $fuel_type = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // If not found by ID, try to find by name
        if (!$fuel_type) {
            $stmt = $pdo->prepare("SELECT id, name FROM fuel_types WHERE name = ? OR name LIKE ?");
            $stmt->execute([$delivery['fuel_type'], '%' . $delivery['fuel_type'] . '%']);
            $fuel_type = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // If still not found, create the fuel type
        if (!$fuel_type) {
            $stmt = $pdo->prepare("INSERT INTO fuel_types (name, description) VALUES (?, ?)");
            $stmt->execute([$delivery['fuel_type'], 'Auto-created from delivery verification']);
            $fuel_type_id = $pdo->lastInsertId();
            $fuel_type_name = $delivery['fuel_type'];
        } else {
            $fuel_type_id = $fuel_type['id'];
            $fuel_type_name = $fuel_type['name'];
        }
        
        // Update the delivery record to use the correct fuel_type ID
        $stmt = $pdo->prepare("UPDATE fuel_deliveries SET fuel_type = ? WHERE id = ?");
        $stmt->execute([$fuel_type_id, $id]);
        
        // Check if stock already added for this delivery (prevent duplicates)
        $stmt = $pdo->prepare("
            SELECT id FROM inventory_transactions 
            WHERE reference_type = 'fuel_deliveries' AND reference_id = ? AND transaction_type = 'delivery_verified'
        ");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            // Get current stock level by fuel type name from products table
            $stmt = $pdo->prepare("
                SELECT si.id as inventory_id, si.stock_level
                FROM station_inventory si
                INNER JOIN products p ON si.product_id = p.id
                WHERE si.station_id = ? 
                  AND p.name = ? 
                  AND p.type_id = (SELECT id FROM product_types WHERE name = 'fuel')
            ");
            $stmt->execute([$station_id, $fuel_type_name]);
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($inventory) {
                $stockBefore = $inventory['stock_level'];
                $stockAfter = $stockBefore + $actual_liters;
                
                // Update stock level
                $stmt = $pdo->prepare("UPDATE station_inventory SET stock_level = ? WHERE id = ?");
                $stmt->execute([$stockAfter, $inventory['inventory_id']]);
                
                // Record transaction
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_transactions 
                    (station_id, product_id, transaction_type, quantity, reference_type, reference_id, created_by, notes, created_at)
                    VALUES (?, (SELECT product_id FROM station_inventory WHERE id = ?), 'delivery_verified', ?, 'fuel_deliveries', ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $station_id, 
                    $inventory['inventory_id'],
                    $actual_liters,
                    $id,
                    $me['id'],
                    "Stock added from verified delivery - {$actual_liters} L of {$fuel_type_name}"
                ]);
                
                $stockAdded = true;
            }
        }
    }
    
    // Update the delivery status
    $stmt = $pdo->prepare("UPDATE fuel_deliveries SET status = ?, verified_by = ?, verified_at = NOW(), delivery_liters = ?, notes = CONCAT(COALESCE(notes, ''), '\n', ?) WHERE id = ?");
    $stmt->execute([$status, $me['id'], $status === 'Verified' ? $actual_liters : $delivery['delivery_liters'], $manager_notes, $id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('No changes made.');
    }
    
    // BUGFIX: Update `fuel_inventory` table to reflect stock in tank configs!
    if ($status === 'Verified') {
        $tank = $delivery['tank_assigned'] ?? '';
        $updated = false;
        
        if ($tank) {
            // Try updating by UGT/tank
            try {
                $stmtFI = $pdo->prepare("UPDATE fuel_inventory SET current_level = COALESCE(current_level, 0) + ?, current_stock = COALESCE(current_stock, 0) + ? WHERE station_id = ? AND LOWER(ugt_no) = LOWER(?)");
                $stmtFI->execute([$actual_liters, $actual_liters, $station_id, $tank]);
                if ($stmtFI->rowCount() > 0) $updated = true;
            } catch (Exception $e) {}
            
            if (!$updated) {
                try {
                    $stmtFI2 = $pdo->prepare("UPDATE fuel_inventory SET current_level = COALESCE(current_level, 0) + ?, current_stock = COALESCE(current_stock, 0) + ? WHERE station_id = ? AND LOWER(tank) = LOWER(?)");
                    $stmtFI2->execute([$actual_liters, $actual_liters, $station_id, $tank]);
                    if ($stmtFI2->rowCount() > 0) $updated = true;
                } catch (Exception $e) {}
            }
        }
        
        if (!$updated) {
            try {
                // Fallback by fuel type
                $stmtFI3 = $pdo->prepare("UPDATE fuel_inventory SET current_level = COALESCE(current_level, 0) + ?, current_stock = COALESCE(current_stock, 0) + ? WHERE station_id = ? AND (LOWER(fuel_type) = LOWER(?) OR LOWER(fuel_type) LIKE ?)");
                $stmtFI3->execute([$actual_liters, $actual_liters, $station_id, $fuel_type_name, '%' . $fuel_type_name . '%']);
            } catch (Exception $e) {}
        }
    }
    
    // Log the activity
    log_activity($pdo, $me['id'], 
        'Manager ' . ($status === 'Verified' ? 'Verified' : 'Rejected') . ' Delivery', 
        "Delivery ID: $id - $status" . 
        ($status === 'Verified' ? " ($actual_liters liters)" : '') .
        ($rejection_reason ? " ($rejection_reason)" : ''),
        'fuel_management'
    );
    
    $response['success'] = true;
    $response['message'] = "Delivery $status successfully";
    
    if ($stockAdded) {
        $response['stock_added'] = true;
        $response['stock_before'] = $stockBefore;
        $response['stock_after'] = $stockAfter;
        $response['fuel_type'] = $fuel_type_name;
        $response['message'] .= ". Stock updated: " . number_format($stockBefore, 2) . "L → " . number_format($stockAfter, 2) . "L";
    }
}

/**
 * Handle adjustment approval by manager
 */
function handleApproveAdjustment() {
    global $pdo, $me, $response;
    
    // Check role permission
    if (!in_array(strtolower($me['role']), ['manager', 'admin', 'superadmin'])) {
        throw new Exception('Only managers, admins, or superadmins can approve adjustments');
    }
    
    $id = $_POST['id'] ?? 0;
    $status = $_POST['status'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $approved_liters = floatval($_POST['approved_liters'] ?? 0);
    $priority = $_POST['priority'] ?? 'Normal';
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    
    // Debug logging
    error_log("Adjustment Approval Request: ID=$id, Status=$status, Reason=$rejection_reason");
    
    if (!$id || !in_array($status, ['Approved', 'Rejected'])) {
        throw new Exception('Invalid parameters provided');
    }
    
    if ($status === 'Rejected' && !$rejection_reason) {
        throw new Exception('Rejection reason is required');
    }
    
    if ($status === 'Approved' && $approved_liters <= 0) {
        throw new Exception('Valid approved amount is required');
    }
    
    // Build approval notes
    $manager_notes = "[Manager Approval by {$me['name']}]\n";
    if ($status === 'Rejected') {
        $manager_notes .= "REJECTED: $rejection_reason\n";
    } else {
        $manager_notes .= "APPROVED: Amount: $approved_liters liters\n";
        $manager_notes .= "Priority: $priority\n";
    }
    if ($notes) {
        $manager_notes .= "Notes: $notes\n";
    }
    $manager_notes .= "Date: " . date('Y-m-d H:i:s') . "\n";
    
    $pdo->beginTransaction();
    
    try {
        // Update adjustment with approved amount and approval details
        $stmt = $pdo->prepare("
            UPDATE fuel_adjustments 
            SET status = ?, approved_by = ?, approved_at = NOW(),
                liters = ?,
                notes = CONCAT(COALESCE(notes,''), ?, '\n')
            WHERE id = ? AND station_id = ? AND status = 'Pending'
        ");
        
        $params = [
            $status, 
            $me['id'], 
            $status === 'Approved' ? $approved_liters : null,
            $manager_notes, 
            $id, 
            user_station_id()
        ];
        
        error_log("SQL Params: " . json_encode($params));
        
        $stmt->execute($params);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Adjustment not found or already processed');
        }
        
        error_log("Adjustment updated successfully. Rows affected: " . $stmt->rowCount());
        
        // Log the activity
        log_activity($pdo, $me['id'], 
            'Manager ' . ($status === 'Approved' ? 'Approved' : 'Rejected') . ' Adjustment', 
            "Adjustment ID: $id - $status" . 
            ($status === 'Approved' ? " ($approved_liters liters, $priority priority)" : '') .
            ($rejection_reason ? " ($rejection_reason)" : ''),
            'fuel_management'
        );
        
        $pdo->commit();
        
        $response['success'] = true;
        $response['message'] = "Adjustment $status successfully";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Adjustment approval error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Handle variance investigation by admin
 */
function handleInvestigateVariance() {
    global $pdo, $me, $response;
    
    // Check role permission
    if (!in_array(strtolower($me['role']), ['manager', 'admin', 'superadmin'])) {
        throw new Exception('Only managers, admins, or superadmins can investigate variances');
    }
    
    $id = $_POST['id'] ?? 0;
    $status = $_POST['status'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $root_cause = $_POST['root_cause'] ?? '';
    $priority = $_POST['priority'] ?? 'Normal';
    $corrective_actions = $_POST['corrective_actions'] ?? '';
    
    if (!$id || !in_array($status, ['Under Investigation', 'Resolved'])) {
        throw new Exception('Invalid parameters provided');
    }
    
    if (!$notes) {
        throw new Exception('Investigation notes are required');
    }
    
    // Build investigation notes
    $investigation_notes = "[Investigation by {$me['name']}]\n";
    $investigation_notes .= "Status: $status\n";
    if ($root_cause) {
        $investigation_notes .= "Root Cause: $root_cause\n";
    }
    $investigation_notes .= "Priority: $priority\n";
    $investigation_notes .= "Investigation Notes:\n$notes\n";
    if ($corrective_actions) {
        $investigation_notes .= "Corrective Actions:\n$corrective_actions\n";
    }
    $investigation_notes .= "Date: " . date('Y-m-d H:i:s') . "\n";
    
    $pdo->beginTransaction();
    
    try {
        // Update variance report
        $stmt = $pdo->prepare("
            UPDATE fuel_variance_reports 
            SET status = ?, investigated_by = ?, 
                resolution_notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $me['id'], $investigation_notes, $id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Variance report not found');
        }
        
        // Log the activity
        log_activity($pdo, $me['id'], 
            'Variance Investigation Updated', 
            "Variance ID: $id - Status: $status" . 
            ($root_cause ? " (Root Cause: $root_cause)" : ''),
            'fuel_management'
        );
        
        $pdo->commit();
        
        $response['success'] = true;
        $response['message'] = "Variance investigation updated successfully";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Handle variance approval/closing by admin
 */
function handleApproveVariance() {
    global $pdo, $me, $response;
    
    // Check role permission
    if (!in_array($me['role'], ['admin', 'superadmin'])) {
        throw new Exception('Only administrators can approve/close variance reports');
    }
    
    $id = $_POST['id'] ?? 0;
    $status = $_POST['status'] ?? 'Resolved';
    $notes = $_POST['notes'] ?? '';
    
    if (!$id) {
        throw new Exception('Invalid variance report ID');
    }
    
    if ($status !== 'Resolved') {
        throw new Exception('Invalid status for approval');
    }
    
    // Build approval notes
    $approval_notes = "[Approved/Closed by {$me['name']}]\n";
    $approval_notes .= "Status: $status\n";
    if ($notes) {
        $approval_notes .= "Notes: $notes\n";
    }
    $approval_notes .= "Date: " . date('Y-m-d H:i:s') . "\n";
    
    $pdo->beginTransaction();
    
    try {
        // Get current variance report details
        $stmt = $pdo->prepare("SELECT * FROM fuel_variance_reports WHERE id = ?");
        $stmt->execute([$id]);
        $variance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$variance) {
            throw new Exception('Variance report not found');
        }
        
        // Check access for non-superadmin
        if ($me['role'] !== 'superadmin' && $variance['station_id'] != user_station_id()) {
            throw new Exception('Access denied for this station\'s variance report');
        }
        
        // Update variance report
        $stmt = $pdo->prepare("
            UPDATE fuel_variance_reports 
            SET status = ?, investigated_by = ?, 
                resolution_notes = CONCAT(COALESCE(resolution_notes,''), ?, '\n'),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $me['id'], $approval_notes, $id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('No changes made to variance report');
        }
        
        // Log the activity
        log_activity($pdo, $me['id'], 
            'Variance Report Approved/Closed', 
            "Variance ID: $id - Status: $status",
            'fuel_management'
        );
        
        $pdo->commit();
        
        $response['success'] = true;
        $response['message'] = "Variance report approved and closed successfully";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
?>