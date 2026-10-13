<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$user = current_user();
$station_id = user_station_id();
$job_id = (int)($_GET['job_id'] ?? 0);

// Fetch job order details
$job = null;
if ($job_id > 0) {
    $stmt = $pdo->prepare("
        SELECT j.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
               u.name as mechanic_name, sc.name as service_category
        FROM job_orders j
        LEFT JOIN customers c ON j.customer_id = c.id
        LEFT JOIN users u ON j.assigned_mechanic_id = u.id
        LEFT JOIN service_categories sc ON j.service_category_id = sc.id
        WHERE j.id = ? AND j.station_id = ?
    ");
    $stmt->execute([$job_id, $station_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$job) {
    die('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Job order not found.</p></div>');
}

// Fetch job order items/parts used
$items = [];
try {
    $stmt = $pdo->prepare("
        SELECT joi.*, p.name as part_name, p.unit_price as part_price
        FROM job_order_items joi
        LEFT JOIN products p ON joi.product_id = p.id
        WHERE joi.job_order_id = ?
        ORDER BY joi.created_at DESC
    ");
    $stmt->execute([$job_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist
}

// Calculate totals
$parts_total = 0;
$labor_total = $job['labor_cost'] ?? 0;
foreach ($items as $item) {
    $parts_total += ($item['quantity'] * ($item['unit_price'] ?? $item['part_price'] ?? 0));
}
$grand_total = $parts_total + $labor_total + ($job['total_cost'] ?? 0);

function getStatusColor($status) {
    switch($status) {
        case 'Completed': return '#dcfce7';
        case 'In Progress': return '#fef3c7';
        case 'Pending': return '#dbeafe';
        case 'Cancelled': return '#fee2e2';
        default: return '#f1f5f9';
    }
}

function getStatusTextColor($status) {
    switch($status) {
        case 'Completed': return '#15803d';
        case 'In Progress': return '#92400e';
        case 'Pending': return '#1e40af';
        case 'Cancelled': return '#dc2626';
        default: return '#64748b';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Order Details - JO-<?php echo str_pad($job['id'], 4, '0', STR_PAD_LEFT); ?></title>
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
            max-width: 900px;
            max-height: 90vh;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .modal-header {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
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
        .modal-body { 
            padding: 24px; 
            overflow-y: auto;
            flex: 1;
        }
        .job-header {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .job-number {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }
        .job-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .info-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
        }
        .info-card h4 {
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
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
        .description-box {
            background: #fef3c7;
            border-left: 3px solid #f59e0b;
            padding: 16px;
            border-radius: 0 8px 8px 0;
            margin-bottom: 20px;
        }
        .description-title { font-weight: 600; color: #92400e; font-size: 13px; margin-bottom: 8px; }
        .description-content { font-size: 13px; color: #78350f; line-height: 1.5; }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            padding: 12px 8px;
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
        }
        .items-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }
        .items-table tr:hover td {
            background-color: #f8fafc;
        }
        .cost-summary {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .cost-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .cost-row.grand-total {
            font-weight: 700;
            font-size: 16px;
            color: #0f172a;
            padding-top: 8px;
            border-top: 2px solid #0ea5e9;
        }
        .empty-state { 
            text-align: center; 
            padding: 40px; 
            color: #64748b; 
        }
        .empty-state i { 
            font-size: 48px; 
            color: #ef4444; 
            margin-bottom: 16px; 
        }
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
            margin-left: 8px;
        }
        .btn-secondary { background: #f1f5f9; color: #64748b; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="fas fa-wrench"></i> Job Order Details - JO-<?php echo str_pad($job['id'], 4, '0', STR_PAD_LEFT); ?></h3>
            <button class="close-btn" onclick="window.close()">&times;</button>
        </div>
        
        <div class="modal-body">
            <div class="job-header">
                <div class="job-number">Job Order #<?php echo str_pad($job['id'], 4, '0', STR_PAD_LEFT); ?></div>
                <div class="job-status" style="background: <?php echo getStatusColor($job['status']); ?>; color: <?php echo getStatusTextColor($job['status']); ?>;">
                    <?php echo htmlspecialchars($job['status']); ?>
                </div>
            </div>
            
            <div class="info-grid">
                <div class="info-card">
                    <h4><i class="fas fa-user"></i> Customer Information</h4>
                    <div class="info-row">
                        <span class="info-label">Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($job['customer_name'] ?? 'Walk-in'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($job['customer_phone'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Address</span>
                        <span class="info-value"><?php echo htmlspecialchars($job['customer_address'] ?? 'N/A'); ?></span>
                    </div>
                </div>
                
                <div class="info-card">
                    <h4><i class="fas fa-wrench"></i> Service Information</h4>
                    <div class="info-row">
                        <span class="info-label">Category</span>
                        <span class="info-value"><?php echo htmlspecialchars($job['service_category'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Assigned Mechanic</span>
                        <span class="info-value"><?php echo htmlspecialchars($job['mechanic_name'] ?? 'Unassigned'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Estimated Duration</span>
                        <span class="info-value"><?php echo (!empty($job['estimated_duration']) && (int)$job['estimated_duration'] > 0) ? htmlspecialchars($job['estimated_duration']) . ' mins' : '—'; ?></span>
                    </div>
                </div>
                
                <div class="info-card">
                    <h4><i class="fas fa-clock"></i> Timestamps</h4>
                    <div class="info-row">
                        <span class="info-label">Created</span>
                        <span class="info-value"><?php echo date('M d, Y h:i A', strtotime($job['created_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Started</span>
                        <span class="info-value"><?php echo $job['started_at'] ? date('M d, h:i A', strtotime($job['started_at'])) : 'Not started'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Completed</span>
                        <span class="info-value"><?php echo $job['completed_at'] ? date('M d, h:i A', strtotime($job['completed_at'])) : 'Not completed'; ?></span>
                    </div>
                </div>
            </div>
            
            <?php if ($job['service_description']): ?>
                <div class="description-box">
                    <div class="description-title">Service Description</div>
                    <div class="description-content"><?php echo nl2br(htmlspecialchars($job['service_description'])); ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($job['notes']): ?>
                <div class="description-box" style="background: #dbeafe; border-color: #3b82f6;">
                    <div class="description-title" style="color: #1e40af;">Notes</div>
                    <div class="description-content" style="color: #1e3a8a;"><?php echo nl2br(htmlspecialchars($job['notes'])); ?></div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($items)): ?>
                <h4 style="margin-bottom: 12px; font-size: 16px; color: #0f172a;">Parts Used</h4>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Part Name</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['part_name'] ?? 'Unknown Part'); ?></td>
                                <td><?php echo number_format($item['quantity'], 2); ?></td>
                                <td>₱<?php echo number_format($item['unit_price'] ?? $item['part_price'] ?? 0, 2); ?></td>
                                <td>₱<?php echo number_format($item['quantity'] * ($item['unit_price'] ?? $item['part_price'] ?? 0), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <div class="cost-summary">
                <h4 style="margin-bottom: 12px; font-size: 16px; color: #0f172a;">Cost Breakdown</h4>
                <?php if (!empty($items)): ?>
                    <div class="cost-row">
                        <span>Parts Cost</span>
                        <span>₱<?php echo number_format($parts_total, 2); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($job['labor_cost'] > 0): ?>
                    <div class="cost-row">
                        <span>Tip</span>
                        <span>₱<?php echo number_format($job['labor_cost'], 2); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($job['total_cost'] > 0): ?>
                    <div class="cost-row">
                        <span>Service Cost</span>
                        <span>₱<?php echo number_format($job['total_cost'], 2); ?></span>
                    </div>
                <?php endif; ?>
                <div class="cost-row grand-total">
                    <span>Grand Total</span>
                    <span>₱<?php echo number_format($grand_total, 2); ?></span>
                </div>
            </div>
        </div>
        
        <div class="modal-footer">
            <button class="btn btn-primary" onclick="printDetails()">
                <i class="fas fa-print"></i> Print
            </button>
            <button class="btn btn-secondary" onclick="window.close()">Close</button>
        </div>
    </div>

    <script>
        function printDetails() {
            window.print();
        }
    </script>
</body>
</html>
