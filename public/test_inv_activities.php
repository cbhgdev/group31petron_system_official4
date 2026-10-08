<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/db_connect.php';

$user_id = 9;
$station_id = 1253;
$date_start = '2026-10-01';
$date_end = '2026-10-08';
$raw = [];

// 1. Deliveries Oversight
try {
    $sql = "
        SELECT 
            do.id,
            do.created_at AS datetime,
            'Inventory' AS module,
            'Record Delivery' AS activity,
            COALESCE(NULLIF(do.delivery_ref,''), CONCAT('DLV-', do.id)) AS ref_no,
            'Success' AS status,
            COALESCE(do.received_shift,'') AS shift_period,
            CONCAT(IF(do.delivery_type='fuel','Fuel: ','Product: '), COALESCE(do.product,'N/A'), ' | Qty: ', FORMAT(COALESCE(do.actual_quantity, do.quantity, 0), IF(do.delivery_type='fuel',2,0)), ' ', COALESCE(do.unit,''), ' | DR: ', COALESCE(do.dr_number,'N/A'), IF(do.sales_invoice_no IS NOT NULL AND do.sales_invoice_no != '', CONCAT(' | Inv: ', do.sales_invoice_no), ''), ' | Supplier: ', COALESCE(do.supplier,'Petron Corporation')) AS details
        FROM deliveries_oversight do
        WHERE do.station_id = :sid AND (do.encoded_by = :uid1 OR LOWER(COALESCE(do.received_by_name,'')) LIKE '%judy%')
          AND DATE(do.created_at) BETWEEN :dstart AND :dend
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['sid' => $station_id, 'uid1' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "deliveries_oversight count: " . count($rows) . "\n";
    $raw = array_merge($raw, $rows);
} catch (Exception $e) { echo "deliveries_oversight error: " . $e->getMessage() . "\n"; }

// 2. Stock Requests
try {
    $sql = "
        SELECT 
            sr.id,
            sr.created_at AS datetime,
            'Inventory' AS module,
            'Stock Request' AS activity,
            COALESCE(NULLIF(sr.request_no,''), CONCAT('SR-', sr.id)) AS ref_no,
            CASE 
                WHEN LOWER(COALESCE(sr.status,'')) IN ('rejected','cancelled','canceled') THEN 'Cancelled'
                WHEN LOWER(COALESCE(sr.status,'')) IN ('approved','completed','fulfilled','success','purchase order generated') THEN 'Success'
                ELSE 'Pending'
            END AS status,
            '' AS shift_period,
            CONCAT(
                'Item: ', COALESCE(sr.item_name,'N/A'),
                IF(sr.item_sku IS NOT NULL AND sr.item_sku != '', CONCAT(' (SKU: ', sr.item_sku, ')'), ''),
                ' | Qty: ', FORMAT(COALESCE(NULLIF(sr.requested_quantity,0), sr.approved_quantity, 0), 0),
                IF(sr.approved_price IS NOT NULL AND sr.approved_price > 0, CONCAT(' | Price: ₱', FORMAT(sr.approved_price, 2)), ''),
                IF(sr.status IS NOT NULL AND sr.status != '', CONCAT(' | Status: ', sr.status), ''),
                IF(sr.remarks IS NOT NULL AND sr.remarks != '', CONCAT(' | Remarks: ', sr.remarks), '')
            ) AS details
        FROM stock_requests sr
        WHERE sr.station_id = :sid AND sr.staff_id = :uid AND DATE(sr.created_at) BETWEEN :dstart AND :dend
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['sid' => $station_id, 'uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "stock_requests count: " . count($rows) . "\n";
    $raw = array_merge($raw, $rows);
} catch (Exception $e) { echo "stock_requests error: " . $e->getMessage() . "\n"; }

// 3. Activity Logs
try {
    $sql = "
        SELECT 
            act.id,
            act.created_at AS datetime,
            CASE 
                WHEN LOWER(COALESCE(act.action,'')) LIKE '%fuel delivery%' THEN 'Inventory'
                WHEN LOWER(COALESCE(act.action,'')) LIKE '%fuel%' THEN 'Fuel Management'
                WHEN LOWER(COALESCE(act.action,'')) LIKE '%delivery%' OR LOWER(COALESCE(act.action,'')) LIKE '%deliver%' OR LOWER(COALESCE(act.action,'')) LIKE '%stock%' OR LOWER(COALESCE(act.action,'')) LIKE '%invent%' THEN 'Inventory'
                WHEN LOWER(COALESCE(act.action,'')) LIKE '%job%' THEN 'Job Orders'
                WHEN LOWER(COALESCE(act.action,'')) LIKE '%draft%' THEN 'Drafts'
                WHEN LOWER(COALESCE(act.action,'')) LIKE '%customer%' THEN 'Customers'
                ELSE 'Sales'
            END AS module,
            CASE 
                WHEN LOWER(COALESCE(act.action,'')) LIKE '%deliver%' THEN 'Record Delivery'
                WHEN LOWER(COALESCE(act.action,'')) LIKE '%stock request%' THEN 'Stock Request'
                ELSE COALESCE(act.action, 'Action')
            END AS activity,
            CONCAT('ACT-', act.id) AS ref_no,
            'Success' AS status,
            '' AS shift_period,
            COALESCE(act.details,'') AS details
        FROM activity_logs act
        WHERE act.user_id = :uid 
          AND DATE(act.created_at) BETWEEN :dstart AND :dend
          AND LOWER(COALESCE(act.action,'')) NOT LIKE '%login%'
          AND LOWER(COALESCE(act.action,'')) NOT LIKE '%logout%'
          AND LOWER(COALESCE(act.action,'')) NOT LIKE '%logged%'
          AND LOWER(COALESCE(act.action,'')) NOT LIKE '%sign%'
          AND LOWER(COALESCE(act.action,'')) NOT LIKE '%clock%'
          AND LOWER(COALESCE(act.action,'')) NOT LIKE '%timeout%'
          AND LOWER(COALESCE(act.action,'')) NOT LIKE '%session%'
          AND LOWER(COALESCE(act.action,'')) NOT LIKE '%password%'
          AND LOWER(COALESCE(act.action,'')) NOT LIKE '%otp%'
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['uid' => $user_id, 'dstart' => $date_start, 'dend' => $date_end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "activity_logs operational count: " . count($rows) . "\n";
    $raw = array_merge($raw, $rows);
} catch (Exception $e) { echo "activity_logs error: " . $e->getMessage() . "\n"; }

echo "\n--- RAW INVENTORY ITEMS ---\n";
foreach ($raw as $i => $item) {
    if ($item['module'] === 'Inventory') {
        echo "#$i [{$item['datetime']}] [{$item['module']}] [{$item['activity']}] [{$item['ref_no']}] [{$item['status']}] {$item['details']}\n";
    }
}
