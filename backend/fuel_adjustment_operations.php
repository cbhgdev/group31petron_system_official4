<?php
/**
 * FUEL ADJUSTMENT OPERATIONS
 * 
 * Handles fuel adjustment workflow:
 * 1. Staff requests adjustment with reason
 * 2. Manager approves or rejects
 * 3. If approved, updates fuel_inventory stock
 * 4. Creates immutable audit trail
 * 
 * Adjustment Types: Variance, Loss, Reconciliation, Measurement Error, Spillage, Contamination, etc.
 */

// Include the fuel audit logging module
require_once __DIR__ . '/fuel_audit_logging.php';

class FuelAdjustmentOperations {
    private $pdo;
    private $user;
    
    public function __construct($pdo, $user) {
        $this->pdo = $pdo;
        $this->user = $user;
    }
    
    /**
     * STEP 1: Staff requests adjustment
     * Status: Pending
     */
    public function request_adjustment($station_id, $product_id, $adjustment_date, $adjustment_type, $liters, $reason, $notes) {
        try {
            // Validate
            if (!is_numeric($liters) || $liters == 0) {
                return ['success' => false, 'message' => '✗ Liters must be non-zero number'];
            }
            
            if (strlen($reason) < 10) {
                return ['success' => false, 'message' => '✗ Reason must be at least 10 characters'];
            }
            
            // Find fuel product
            $stmt = $this->pdo->prepare("
                SELECT id FROM fuel_inventory 
                WHERE station_id = ? AND product_id = ?
            ");
            $stmt->execute([$station_id, $product_id]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$inv) {
                return ['success' => false, 'message' => '✗ Fuel inventory record not found'];
            }
            
            // Insert adjustment request
            $stmt = $this->pdo->prepare("
                INSERT INTO fuel_adjustments (
                    station_id, product_id, adjustment_date, adjustment_type, 
                    liters, reason, notes, user_id, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
            ");
            
            $stmt->execute([
                $station_id,
                $product_id,
                $adjustment_date,
                $adjustment_type,
                $liters,
                $reason,
                $notes,
                $this->user['id']
            ]);
            
            $adjustment_id = $this->pdo->lastInsertId();
            
            // Log to activity logs
             log_activity(
                 $this->pdo,
                 $this->user['id'],
                 'Adjustment Requested',
                 "Requested fuel adjustment: {$adjustment_type} ({$liters}L) - {$reason}",
                 'fuel_management'
             );
             
             // Log to fuel_inventory_logs via audit logging module
             log_fuel_inventory_action(
                 $this->pdo,
                 $this->user['id'],
                 'adjustment_requested',
                 'fuel_adjustment',
                 $adjustment_id,
                 $station_id,
                 $product_id,
                 [
                     'adjustment_type' => $adjustment_type,
                     'liters' => $liters,
                     'reason' => $reason,
                     'notes' => $notes,
                     'status' => 'Pending'
                 ]
             );
             
             return [
                 'success' => true,
                 'adjustment_id' => $adjustment_id,
                 'message' => "✓ Adjustment request submitted successfully. ID: {$adjustment_id}"
             ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "✗ Error requesting adjustment: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * STEP 2: Manager approves adjustment
     * Status: Pending → Approved
     * Triggers stock update
     */
    public function approve_adjustment($adjustment_id, $manager_id, $approval_reason) {
        try {
            // Fetch adjustment
            $stmt = $this->pdo->prepare("
                SELECT * FROM fuel_adjustments WHERE id = ?
            ");
            $stmt->execute([$adjustment_id]);
            $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$adjustment) {
                return ['success' => false, 'message' => '✗ Adjustment not found'];
            }
            
            if ($adjustment['status'] !== 'Pending') {
                return ['success' => false, 'message' => "✗ Adjustment status is {$adjustment['status']}, cannot approve"];
            }
            
            // BEGIN TRANSACTION
            $this->pdo->beginTransaction();
            
            try {
                // Get current stock before update
                $stmt = $this->pdo->prepare("
                    SELECT stock_level FROM fuel_inventory 
                    WHERE station_id = ? AND product_id = ?
                ");
                $stmt->execute([$adjustment['station_id'], $adjustment['product_id']]);
                $current_inv = $stmt->fetch(PDO::FETCH_ASSOC);
                $quantity_before = $current_inv['stock_level'] ?? 0;
                
                // UPDATE fuel_inventory - ADD adjustment liters (can be positive or negative)
                $stmt = $this->pdo->prepare("
                    UPDATE fuel_inventory 
                    SET stock_level = stock_level + ? 
                    WHERE station_id = ? AND product_id = ?
                ");
                
                $stmt->execute([
                    $adjustment['liters'],
                    $adjustment['station_id'],
                    $adjustment['product_id']
                ]);
                
                // Get new stock level
                $stmt = $this->pdo->prepare("
                    SELECT stock_level FROM fuel_inventory 
                    WHERE station_id = ? AND product_id = ?
                ");
                $stmt->execute([$adjustment['station_id'], $adjustment['product_id']]);
                $updated_inv = $stmt->fetch(PDO::FETCH_ASSOC);
                $quantity_after = $updated_inv['stock_level'] ?? 0;
                
                // Update adjustment status to Approved
                $stmt = $this->pdo->prepare("
                    UPDATE fuel_adjustments 
                    SET status = 'Approved', 
                        approved_by = ?, 
                        approved_at = NOW(),
                        approval_reason = ?
                    WHERE id = ?
                ");
                 
                 $stmt->execute([$manager_id, $approval_reason, $adjustment_id]);
                 
                 // Log to fuel_inventory_logs via audit logging module with stock details
                 log_fuel_inventory_action(
                     $this->pdo,
                     $manager_id,
                     'adjustment_approved',
                     'fuel_adjustment',
                     $adjustment_id,
                     $adjustment['station_id'],
                     $adjustment['product_id'],
                     [
                         'adjustment_type' => $adjustment['adjustment_type'],
                         'liters' => $adjustment['liters'],
                         'approval_reason' => $approval_reason,
                         'quantity_before' => $quantity_before,
                         'quantity_after' => $quantity_after,
                         'quantity_change' => $adjustment['liters'],
                         'status' => 'Approved'
                     ]
                 );
                 
                 // Log to activity logs
                 log_activity(
                     $this->pdo,
                     $manager_id,
                     'Adjustment Approved',
                     "Approved fuel adjustment ID {$adjustment_id}: {$adjustment['adjustment_type']} ({$adjustment['liters']}L). Stock: {$quantity_before}L → {$quantity_after}L",
                     'fuel_management'
                 );
                 
                 // Send notification to requesting staff or staff role
                 try {
                     $req_staff_id = (int)($adjustment['user_id'] ?? 0);
                     $target_notif_url = "staff_inventory_fuel.php?tab=overview";
                     $actor_name = $this->user['name'] ?? $this->user['username'] ?? 'Manager';
                     if ($req_staff_id > 0) {
                         notify_staff_action_result(
                             $this->pdo,
                             $req_staff_id,
                             'Fuel Adjustment',
                             'Approved',
                             "Fuel Adjustment #{$adjustment_id}",
                             $actor_name,
                             'Manager',
                             "Approved fuel adjustment ({$adjustment['adjustment_type']}: {$adjustment['liters']}L). Stock: {$quantity_before}L → {$quantity_after}L",
                             'fuel_adjustment',
                             $adjustment_id,
                             $target_notif_url
                         );
                     } else {
                         create_role_notification(
                             $this->pdo,
                             'staff',
                             'success',
                             'Fuel Adjustment Approved',
                             "Manager approved fuel adjustment #{$adjustment_id} ({$adjustment['adjustment_type']}: {$adjustment['liters']}L).",
                             null,
                             $target_notif_url
                         );
                     }
                 } catch (Exception $e_notif) {}
                 
                 $this->pdo->commit();
                 
                 return [
                     'success' => true,
                     'message' => "✓ Adjustment approved and stock updated: {$quantity_before}L → {$quantity_after}L",
                     'quantity_before' => $quantity_before,
                     'quantity_after' => $quantity_after
                 ];
                
            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "✗ Error approving adjustment: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * STEP 2: Manager rejects adjustment
     * Status: Pending → Rejected
     * NO stock update
     */
    public function reject_adjustment($adjustment_id, $manager_id, $rejection_reason) {
        try {
            // Fetch adjustment
            $stmt = $this->pdo->prepare("
                SELECT * FROM fuel_adjustments WHERE id = ?
            ");
            $stmt->execute([$adjustment_id]);
            $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$adjustment) {
                return ['success' => false, 'message' => '✗ Adjustment not found'];
            }
            
            if ($adjustment['status'] !== 'Pending') {
                return ['success' => false, 'message' => "✗ Adjustment status is {$adjustment['status']}, cannot reject"];
            }
            
            // Update adjustment status to Rejected
            $stmt = $this->pdo->prepare("
                UPDATE fuel_adjustments 
                SET status = 'Rejected', 
                    approved_by = ?, 
                    approved_at = NOW(),
                    approval_reason = ?
                WHERE id = ?
            ");
             
             $stmt->execute([$manager_id, $rejection_reason, $adjustment_id]);
             
             // Log to fuel_inventory_logs via audit logging module
             log_fuel_inventory_action(
                 $this->pdo,
                 $manager_id,
                 'adjustment_rejected',
                 'fuel_adjustment',
                 $adjustment_id,
                 $adjustment['station_id'],
                 $adjustment['product_id'],
                 [
                     'adjustment_type' => $adjustment['adjustment_type'],
                     'liters' => $adjustment['liters'],
                     'rejection_reason' => $rejection_reason,
                     'status' => 'Rejected'
                 ]
             );
             
             // Log to activity logs
             log_activity(
                 $this->pdo,
                 $manager_id,
                 'Adjustment Rejected',
                 "Rejected fuel adjustment ID {$adjustment_id}: {$rejection_reason}",
                 'fuel_management'
             );
             
             // Send notification to requesting staff or staff role
             try {
                 $req_staff_id = (int)($adjustment['user_id'] ?? 0);
                 $target_notif_url = "staff_inventory_fuel.php?tab=overview";
                 $actor_name = $this->user['name'] ?? $this->user['username'] ?? 'Manager';
                 if ($req_staff_id > 0) {
                     notify_staff_action_result(
                         $this->pdo,
                         $req_staff_id,
                         'Fuel Adjustment',
                         'Rejected',
                         "Fuel Adjustment #{$adjustment_id}",
                         $actor_name,
                         'Manager',
                         "Reason: {$rejection_reason}",
                         'fuel_adjustment',
                         $adjustment_id,
                         $target_notif_url
                     );
                 } else {
                     create_role_notification(
                         $this->pdo,
                         'staff',
                         'warning',
                         'Fuel Adjustment Rejected',
                         "Fuel adjustment #{$adjustment_id} was rejected by Manager. Reason: {$rejection_reason}",
                         null,
                         $target_notif_url
                     );
                 }
             } catch (Exception $e_notif) {}
             
             return [
                 'success' => true,
                 'message' => "✓ Adjustment rejected successfully"
             ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => "✗ Error rejecting adjustment: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get pending adjustments for manager review
     */
    public function get_pending_adjustments($station_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    fa.*,
                    p.name as fuel_name,
                    u.name as requested_by_name
                FROM fuel_adjustments fa
                JOIN products p ON fa.product_id = p.id
                LEFT JOIN users u ON fa.user_id = u.id
                WHERE fa.station_id = ? AND fa.status = 'Pending'
                ORDER BY fa.created_at DESC
            ");
            
            $stmt->execute([$station_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get adjustment history (approved/rejected)
     */
    public function get_adjustment_history($station_id, $limit = 50) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    fa.*,
                    p.name as fuel_name,
                    u_requested.name as requested_by_name,
                    u_approved.name as approved_by_name
                FROM fuel_adjustments fa
                JOIN products p ON fa.product_id = p.id
                LEFT JOIN users u_requested ON fa.user_id = u_requested.id
                LEFT JOIN users u_approved ON fa.approved_by = u_approved.id
                WHERE fa.station_id = ? AND fa.status IN ('Approved', 'Rejected')
                ORDER BY fa.approved_at DESC, fa.created_at DESC
                LIMIT ?
            ");
            
            $stmt->execute([$station_id, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}
?>
