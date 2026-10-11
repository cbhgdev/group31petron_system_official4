<?php
/**
 * GET /backend/api/get_mechanic_status.php?mechanic_id=X
 * Returns whether a mechanic has ongoing (Pending / In Progress) job orders.
 */
require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$mechanic_raw = trim((string)($_GET['mechanic_ids'] ?? $_GET['mechanic_id'] ?? ''));
if ($mechanic_raw === '') {
    echo json_encode(['busy' => false]);
    exit;
}

$id_list = array_values(array_filter(array_map('intval', explode(',', $mechanic_raw)), fn($v) => $v > 0));
if (empty($id_list)) {
    echo json_encode(['busy' => false]);
    exit;
}

$in_placeholders = implode(',', array_fill(0, count($id_list), '?'));

try {
    // Check job_orders table
    $stmt = $pdo->prepare("
        SELECT jo.id,
               COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-', jo.id)) AS jo_ref,
               jo.status,
               COALESCE(jo.service_type, jo.service_description, 'Service') AS service_label,
               COALESCE(jo.vehicle_plate, '') AS vehicle_plate,
               jo.created_at,
               COALESCE(NULLIF(TRIM(CONCAT(COALESCE(m.first_name,''),' ',COALESCE(m.last_name,''))),''), m.name, m.username, 'Mechanic') AS mechanic_name
        FROM job_orders jo
        LEFT JOIN users m ON m.id = jo.assigned_mechanic_id
        WHERE jo.assigned_mechanic_id IN ($in_placeholders)
          AND jo.status IN ('Pending', 'Pending Validation', 'In Progress', 'Approved', 'Validated')
        ORDER BY FIELD(jo.status, 'In Progress', 'Approved', 'Validated', 'Pending', 'Pending Validation'),
                 jo.created_at DESC
        LIMIT 10
    ");
    $stmt->execute($id_list);
    $active_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also check merchandise_transactions with job_order/combined type
    $stmt2 = $pdo->prepare("
        SELECT mt.id,
               COALESCE(mt.transaction_id, CONCAT('MT-', mt.id)) AS jo_ref,
               COALESCE(mt.workflow_status, mt.validation_status, 'Pending') AS status,
               COALESCE(mt.job_order_service, 'Service') AS service_label,
               COALESCE(mt.job_order_vehicle_plate, '') AS vehicle_plate,
               mt.created_at,
               COALESCE(mt.job_order_mechanic_name, 'Mechanic') AS mechanic_name
        FROM merchandise_transactions mt
        WHERE mt.job_order_mechanic_id IN ($in_placeholders)
          AND COALESCE(mt.workflow_status, '') NOT IN ('Completed', 'Released', 'Voided', 'Rejected', 'Cancelled')
          AND mt.transaction_type IN ('job_order', 'combined')
        ORDER BY mt.created_at DESC
        LIMIT 10
    ");
    $stmt2->execute($id_list);
    $mt_jobs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $all_jobs = array_merge($active_jobs, $mt_jobs);

    if (empty($all_jobs)) {
        echo json_encode(['busy' => false]);
    } else {
        echo json_encode([
            'busy'       => true,
            'job_count'  => count($all_jobs),
            'jobs'       => array_map(fn($j) => [
                'jo_ref'        => $j['jo_ref'],
                'status'        => $j['status'],
                'service_label' => $j['service_label'],
                'vehicle_plate' => $j['vehicle_plate'],
                'created_at'    => $j['created_at'],
                'mechanic_name' => $j['mechanic_name'] ?? 'Mechanic',
            ], $all_jobs),
        ]);
    }
} catch (Exception $e) {
    // Fail open — don't block the workflow on a DB error
    echo json_encode(['busy' => false, 'error' => $e->getMessage()]);
}
