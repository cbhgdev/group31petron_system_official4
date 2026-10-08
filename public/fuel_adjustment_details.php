<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$user = current_user();
$station_id = user_station_id();
$adjustment_id = (int)($_GET['id'] ?? 0);

$adjustment = null;
if ($adjustment_id > 0) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.name as staff_name, ap.name as approver_name
        FROM fuel_adjustments a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN users ap ON a.approved_by = ap.id
        WHERE a.id = ? AND a.station_id = ?
    ");
    $stmt->execute([$adjustment_id, $station_id]);
    $adjustment = $stmt->fetch(PDO::FETCH_ASSOC);
}

function getStatusColor($status) {
    $s = strtolower(trim((string)$status));
    if (in_array($s, ['approved', 'completed', 'success', 'verified', 'finalized', 'official', 'done'])) return '#dcfce7';
    if (in_array($s, ['pending', 'pending admin approval', 'pending admin approv', 'under review', 'for review', 'in review', 'submitted', 'draft'])) return '#fef3c7';
    if (in_array($s, ['rejected', 'cancelled', 'canceled', 'voided', 'declined'])) return '#fee2e2';
    return '#f1f5f9';
}
function getStatusTextColor($status) {
    $s = strtolower(trim((string)$status));
    if (in_array($s, ['approved', 'completed', 'success', 'verified', 'finalized', 'official', 'done'])) return '#15803d';
    if (in_array($s, ['pending', 'pending admin approval', 'pending admin approv', 'under review', 'for review', 'in review', 'submitted', 'draft'])) return '#92400e';
    if (in_array($s, ['rejected', 'cancelled', 'canceled', 'voided', 'declined'])) return '#dc2626';
    return '#64748b';
}
function getAdjustmentTypeColor($type) {
    switch($type) {
        case 'Loss': return '#fee2e2';
        case 'Gain': return '#dcfce7';
        default: return '#f1f5f9';
    }
}
function getAdjustmentTypeTextColor($type) {
    switch($type) {
        case 'Loss': return '#dc2626';
        case 'Gain': return '#15803d';
        default: return '#64748b';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fuel Adjustment Details</title>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: rgba(0,0,0,0.5); 
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-container {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 700px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .modal-header {
            background: linear-gradient(135deg, #003366, #004080);
            color: white;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { font-size: 18px; font-weight: 600; }
        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            opacity: 0.8;
        }
        .close-btn:hover { opacity: 1; }
        .modal-body { padding: 24px; }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .info-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
        }
        .info-card h4 {
            font-size: 13px;
            font-weight: 600;
            color: #003366;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .info-label { color: #64748b; }
        .info-value { font-weight: 500; color: #0f172a; }
        .status-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .volume-highlight {
            background: #eff6ff;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            margin-top: 16px;
        }
        .volume-highlight .label { font-size: 12px; color: #64748b; }
        .volume-highlight .value { font-size: 24px; font-weight: 700; color: #003366; }
        .volume-highlight.loss { background: #fef2f2; }
        .volume-highlight.loss .value { color: #dc2626; }
        .volume-highlight.gain { background: #f0fdf4; }
        .volume-highlight.gain .value { color: #15803d; }
        .notes {
            background: #fef3c7;
            border-left: 3px solid #f59e0b;
            padding: 12px;
            border-radius: 0 8px 8px 0;
            margin-top: 16px;
        }
        .notes-title { font-weight: 600; color: #92400e; font-size: 13px; margin-bottom: 4px; }
        .notes-content { font-size: 13px; color: #78350f; }
        .empty-state { text-align: center; padding: 40px; color: #64748b; }
        .empty-state i { font-size: 48px; color: #ef4444; margin-bottom: 16px; }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            text-align: right;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }
        .btn-secondary { background: #f1f5f9; color: #64748b; }
        .btn-secondary:hover { background: #e2e8f0; }
        .reason-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            margin-top: 8px;
        }
        .reason-title { font-weight: 600; color: #003366; font-size: 12px; margin-bottom: 4px; }
        .reason-text { font-size: 13px; color: #475569; }
    </style>
</head>
<body>
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="fas fa-exchange-alt"></i> Fuel Adjustment Details</h3>
            <button class="close-btn" onclick="window.close()">&times;</button>
        </div>
        
        <div class="modal-body">
            <?php if ($adjustment): ?>
                <div class="info-grid">
                    <div class="info-card">
                        <h4><i class="fas fa-calendar"></i> Adjustment Info</h4>
                        <div class="info-row">
                            <span class="info-label">Date</span>
                            <span class="info-value"><?php echo date('M d, Y', strtotime($adjustment['adjustment_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Fuel Type</span>
                            <span class="info-value"><?php echo htmlspecialchars($adjustment['fuel_type']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Status</span>
                            <span class="status-badge" style="background: <?php echo getStatusColor($adjustment['status']); ?>; color: <?php echo getStatusTextColor($adjustment['status']); ?>;">
                                <?php echo htmlspecialchars($adjustment['status']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <h4><i class="fas fa-exchange-alt"></i> Adjustment Type</h4>
                        <div class="info-row">
                            <span class="info-label">Type</span>
                            <span class="status-badge" style="background: <?php echo getAdjustmentTypeColor($adjustment['adjustment_type']); ?>; color: <?php echo getAdjustmentTypeTextColor($adjustment['adjustment_type']); ?>;">
                                <?php echo htmlspecialchars($adjustment['adjustment_type']); ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Volume</span>
                            <span class="info-value"><?php echo number_format($adjustment['liters'], 2); ?> Liters</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Priority</span>
                            <span class="info-value"><?php echo htmlspecialchars($adjustment['priority'] ?? 'Normal'); ?></span>
                        </div>
                    </div>
                    
                    <div class="info-card">
                        <h4><i class="fas fa-user"></i> Personnel</h4>
                        <div class="info-row">
                            <span class="info-label">Recorded By</span>
                            <span class="info-value"><?php echo htmlspecialchars($adjustment['staff_name'] ?? 'Unknown'); ?></span>
                        </div>
                        <?php if ($adjustment['approved_by']): ?>
                        <div class="info-row">
                            <span class="info-label">Approved By</span>
                            <span class="info-value"><?php echo htmlspecialchars($adjustment['approver_name']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="info-card">
                        <h4><i class="fas fa-clock"></i> Timestamps</h4>
                        <div class="info-row">
                            <span class="info-label">Recorded</span>
                            <span class="info-value"><?php echo $adjustment['created_at'] ? date('M d, h:i A', strtotime($adjustment['created_at'])) : 'N/A'; ?></span>
                        </div>
                        <?php if ($adjustment['approved_at']): ?>
                        <div class="info-row">
                            <span class="info-label">Approved</span>
                            <span class="info-value" style="color: #059669;"><?php echo date('M d, h:i A', strtotime($adjustment['approved_at'])); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="volume-highlight <?php echo strtolower($adjustment['adjustment_type']); ?>">
                    <div class="label">Adjustment Volume</div>
                    <div class="value">
                        <?php echo $adjustment['adjustment_type'] === 'Loss' ? '-' : '+'; ?>
                        <?php echo number_format($adjustment['liters'], 2); ?> Liters
                    </div>
                </div>
                
                <div class="reason-box">
                    <div class="reason-title">Reason for Adjustment</div>
                    <div class="reason-text"><?php echo nl2br(htmlspecialchars($adjustment['reason'])); ?></div>
                </div>
                
                <?php if ($adjustment['notes']): ?>
                <div class="notes">
                    <div class="notes-title">Additional Notes</div>
                    <div class="notes-content"><?php echo nl2br(htmlspecialchars($adjustment['notes'])); ?></div>
                </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>Adjustment not found.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="window.close()">Close</button>
        </div>
    </div>
</body>
</html>
