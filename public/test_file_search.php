<?php
require_once __DIR__ . '/db_connect.php';

$station_id = 1253;
$sc_where = ($station_id > 0) ? " (station_id = ? OR station_id IS NULL OR station_id = 0) AND " : " ";
$sc_params = ($station_id > 0) ? [$station_id] : [];

// 1. Merchandise POs
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT po.po_number)
    FROM purchase_orders po
    WHERE (po.station_id = ? OR po.station_id IS NULL OR po.station_id = 0)
      AND (LOWER(COALESCE(po.status, 'approved')) IN ('admin finalized', 'approved', 'pending delivery', 'pending admin validation', 'forwarded to admin', 'approved po', 'official', 'expected delivery', 'pos generated', 'pending', 'submitted', 'draft', 'pending manager review', 'purchase order generated', 'po generated', 'order generated', 'finalized', 'active') OR po.status IS NULL)
      AND (po.type IS NULL OR po.type = '' OR LOWER(po.type) IN ('merch', 'merchandise', 'item', 'goods'))
");
$stmt->execute([$station_id]);
$pending_pos = (int)$stmt->fetchColumn();

// 2. Deliveries Today
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref) 
    FROM deliveries_oversight 
    WHERE {$sc_where} delivery_type = 'merchandise' AND DATE(COALESCE(delivery_date, created_at)) = CURDATE() AND status NOT IN ('Cancelled', 'Rejected')
");
$stmt->execute($sc_params);
$deliveries_today = (int)$stmt->fetchColumn();

// 3. Pending Stock-In (Accurate count: excluding any delivery whose delivery_ref or source_ref is already Stock-In Complete or PO completed)
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT d.delivery_ref) 
    FROM deliveries_oversight d
    WHERE {$sc_where} d.delivery_type = 'merchandise' 
      AND d.status IN ('Pending Stock-In', 'Ready for Stock-In', 'Validated', 'Verified', 'Received', 'Pending Manager Approval')
      AND d.status NOT IN ('Stock-In Complete', 'Stocked-In', 'Completed', 'Confirmed', 'Closed', 'Cancelled', 'Rejected')
      AND d.delivery_ref NOT IN (
          SELECT d2.delivery_ref 
          FROM deliveries_oversight d2 
          WHERE (d2.station_id = ? OR d2.station_id IS NULL OR d2.station_id = 0)
            AND d2.delivery_type = 'merchandise' 
            AND d2.status IN ('Stock-In Complete', 'Stocked-In', 'Completed', 'Confirmed', 'Closed')
      )
      AND (d.source_ref IS NULL OR d.source_ref = '' OR d.source_ref NOT IN (
          SELECT d3.source_ref 
          FROM deliveries_oversight d3 
          WHERE (d3.station_id = ? OR d3.station_id IS NULL OR d3.station_id = 0)
            AND d3.delivery_type = 'merchandise' 
            AND d3.status IN ('Stock-In Complete', 'Stocked-In', 'Completed', 'Confirmed', 'Closed')
      ))
      AND (d.source_ref IS NULL OR d.source_ref = '' OR d.source_ref NOT IN (
          SELECT po.po_number 
          FROM purchase_orders po 
          WHERE (po.station_id = ? OR po.station_id IS NULL OR po.station_id = 0)
            AND (po.status = 'Completed' OR po.stock_in_done = 1)
      ))
");
$stmt->execute(array_merge($sc_params, [$station_id, $station_id, $station_id]));
$pending_stock_in = (int)$stmt->fetchColumn();

// 4. Completed Deliveries
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT delivery_ref) 
    FROM deliveries_oversight 
    WHERE {$sc_where} delivery_type = 'merchandise' AND status IN ('Stock-In Complete', 'Stocked-In', 'Completed', 'Confirmed', 'Closed')
");
$stmt->execute($sc_params);
$completed_deliveries = (int)$stmt->fetchColumn();

echo "=== MERCHANDISE METRICS ===\n";
echo "Pending Deliveries: " . $pending_pos . "\n";
echo "Deliveries Received Today: " . $deliveries_today . "\n";
echo "Pending Stock-In: " . $pending_stock_in . "\n";
echo "Completed Deliveries: " . $completed_deliveries . "\n";
