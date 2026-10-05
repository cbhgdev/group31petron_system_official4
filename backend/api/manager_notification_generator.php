<?php
/**
 * Manager Notification Generator
 * backend/api/manager_notification_generator.php
 *
 * Generates real-time oversight alerts for the manager dashboard.
 * Schema-verified against the actual database structure.
 *
 * Notification Sources:
 *  1. Fuel Transactions   -  fuel_transactions (Pending Validation)
 *  2. Job Orders          -  job_orders (awaiting validation/completed)
 *  3. Fuel Management     -  fuel_inventory (low tanks <= 20%)
 *  4. Fuel Readings       -  fuel_daily_readings (negative variance)
 *  5. Inventory           -  station_inventory (low stock)
 *  6. Stock Requests      -  stock_requests (flagged/pending)
 *  7. Customers           -  customers (pending validation)
 *  8. Deliveries          -  deliveries_oversight (awaiting manager action)
 *  9. Calendar            -  calendar_events (today's events)
 * 10. Reports             -  activity_logs (weekly/monthly reports)
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me      = current_user();
$role    = role_key($me['role'] ?? '');
$user_id = (int)($me['id'] ?? 0);

if ($role !== 'manager') {
    echo json_encode(['ok' => false, 'error' => 'Manager only']); exit;
}

$station_id = (int)(user_station_id() ?? 0);
if ($station_id <= 0) {
    echo json_encode(['ok' => true, 'generated' => 0, 'message' => 'No station assigned']); exit;
}
$sw = $station_id ? $station_id : 0;

// ------------------------------------------------------------- Ensure notifications table exists -------------------------------------------------------------
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        type         ENUM('success','warning','error','info') NOT NULL DEFAULT 'info',
        title        VARCHAR(255) NOT NULL,
        message      TEXT NOT NULL,
        event_type   VARCHAR(80) NOT NULL DEFAULT 'general',
        severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
        source_key   VARCHAR(200) NULL,
        redirect_url VARCHAR(500) NULL,
        status       ENUM('unread','read') NOT NULL DEFAULT 'unread',
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at      TIMESTAMP NULL,
        INDEX idx_user_status (user_id, status),
        INDEX idx_event_type  (event_type),
        INDEX idx_source_key  (source_key),
        INDEX idx_created_at  (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$generated = 0;

// Keep older unread notifications aligned with current sidebar routes.
try {
    $pdo->prepare(
        "UPDATE notifications
         SET redirect_url='manager_stock_request_review.php?tab=pending_requests'
         WHERE user_id=? AND redirect_url='manager_inventory_stock_requests.php'"
    )->execute([$user_id]);
} catch (Exception $e) {}

// Auto-clean any existing mojibake characters in notifications table
try {
    $pdo->exec("
        UPDATE notifications
        SET title = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(title, ' - ', ' - '), ' - ', ' - '), ' - ', ' - '), ' - ', ' - '), '  ', ' '),
            message = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(message, ' - ', ' - '), ' - ', ' - '), ' - ', ' - '), ' - ', ' - '), '  ', ' ')
        WHERE title LIKE '%Ã%' OR message LIKE '%Ã%' OR title LIKE '%â%' OR message LIKE '%â%'
    ");
} catch (Exception $e) {}

function mgr_push(
    PDO    $pdo,
    int    $user_id,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = ''
): int {
    if (function_exists('clean_mojibake')) {
        $title = clean_mojibake($title);
        $message = clean_mojibake($message);
    }
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO notifications
            (user_id, type, event_type, severity, title, message, source_key, redirect_url, status)
         SELECT ?, ?, ?, ?, ?, ?, ?, ?, 'unread'
         FROM DUAL
         WHERE NOT EXISTS (
             SELECT 1 FROM notifications
             WHERE user_id = ? AND source_key = ?
         )"
    );
    $stmt->execute([
        $user_id, $type, $event_type, $severity, $title, $message,
        $source_key, $redirect_url,
        $user_id, $source_key
    ]);
    return $stmt->rowCount();
}

// ------------------------------------------------------------
// 1. FUEL TRANSACTIONS  -  Single Consolidated Notification Per Shift (Pending Validation)
// ------------------------------------------------------------
try {
    $s = $sw ? "AND ft.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT ft.station_id,
                DATE(ft.transaction_date) AS tx_date,
                ft.shift_period,
                COUNT(*) AS total_readings,
                MAX(ft.transaction_date) AS max_date,
                COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.name, u.username, 'Staff') AS staff_name
         FROM fuel_transactions ft
         LEFT JOIN users u ON u.id = ft.staff_id
         WHERE ft.status IN ('Pending Validation','pending_validation','Pending','pending','READINGS_SUBMITTED','CLOSING_COMPLETED')
           AND ft.transaction_date >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           {$s}
         GROUP BY ft.station_id, DATE(ft.transaction_date), ft.shift_period
         ORDER BY max_date DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $staff   = $r['staff_name'] ?? 'Staff';
        $readCnt = (int)($r['total_readings'] ?? 1);
        $shift   = !empty($r['shift_period']) ? (ucfirst($r['shift_period']) . ' Shift') : 'First Shift';
        $dateStr = date('M d, Y', strtotime($r['tx_date']));
        $key     = 'mgr_fuel_shift_' . $r['station_id'] . '_' . date('Ymd', strtotime($r['tx_date'])) . '_' . strtolower($r['shift_period'] ?? 'shift1');

        $generated += mgr_push($pdo, $user_id, 'info', 'transaction', 'high',
            "New Fuel Reading  -  Pending Validation",
            "{$staff} submitted {$readCnt} Fuel Readings for {$dateStr} ({$shift}). Ready for manager validation.",
            $key, 'manager_fuel_transaction_validation.php'
        );
    }
} catch (Exception $e) {}

// ------------------------------------------------------------
// 2. JOB ORDERS  -  awaiting validation, completed, cancelled
//    Column verified: job_order_id, status, validation_status, customer_name, service_type, created_by
// ------------------------------------------------------------
try {
    $s = $sw ? "AND jo.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT jo.id, jo.job_order_id, jo.status, jo.validation_status,
                jo.customer_name, jo.service_type, jo.updated_at,
                u.name AS staff_name
         FROM job_orders jo
         LEFT JOIN users u ON u.id = jo.created_by
         WHERE jo.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           {$s}
         ORDER BY jo.updated_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $jo_num = $r['job_order_id'] ?? ('#' . $r['id']);
        $status = $r['status'] ?? '';
        $val    = $r['validation_status'] ?? '';
        $staff  = $r['staff_name'] ?? 'Staff';
        $cust   = $r['customer_name'] ?? 'Customer';
        $svc    = $r['service_type'] ?? 'Service';

        if (in_array(strtolower($val), ['pending validation', 'pending'])) {
            $key = 'mgr_jo_validation_' . $r['id'];
            $generated += mgr_push($pdo, $user_id, 'warning', 'job_order', 'high',
                "Job Order {$jo_num} Awaiting Validation",
                "Job Order {$jo_num} ({$svc}) for {$cust} is awaiting your validation.",
                $key, 'manager_validated_transactions.php?type=job_order'
            );
        } elseif (in_array(strtolower($status), ['completed', 'done', 'finished'])) {
            $key = 'mgr_jo_completed_' . $r['id'] . '_' . date('Ymd');
            $generated += mgr_push($pdo, $user_id, 'success', 'job_order', 'low',
                "Job Order {$jo_num} Completed",
                "Staff {$staff} completed Job Order {$jo_num} ({$svc}) for {$cust}.",
                $key, 'manager_validated_transactions.php?type=job_order'
            );
        } elseif (in_array(strtolower($status), ['cancelled', 'rejected', 'canceled'])) {
            $key = 'mgr_jo_cancelled_' . $r['id'];
            $generated += mgr_push($pdo, $user_id, 'error', 'job_order', 'medium',
                "Job Order {$jo_num} {$status}",
                "Job Order {$jo_num} for {$cust} was {$status}.",
                $key, 'manager_validated_transactions.php?type=job_order'
            );
        }
    }
} catch (Exception $e) {}

// ------------------------------------------------------------
// 3. FUEL MANAGEMENT  -  low fuel inventory (<= 20%)
//    Column verified: fuel_type, current_level, capacity, station_id
// ------------------------------------------------------------
try {
    $s = $sw ? "AND fi.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT fi.id, fi.fuel_type, fi.current_level, fi.capacity
         FROM fuel_inventory fi
         WHERE fi.current_level >= 0
           AND fi.capacity > 0
           AND fi.current_level <= (fi.capacity * 0.20)
           {$s}
         ORDER BY (fi.current_level / fi.capacity) ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $pct  = $r['capacity'] > 0 ? round(($r['current_level'] / $r['capacity']) * 100) : 0;
        $sev  = $pct <= 5 ? 'critical' : ($pct <= 10 ? 'high' : 'medium');
        $type = $pct <= 5 ? 'error' : 'warning';
        $key  = 'mgr_fuel_low_' . $r['id'] . '_' . date('Ymd');
        $generated += mgr_push($pdo, $user_id, $type, 'fuel_management', $sev,
            "Low Fuel Alert: {$r['fuel_type']}",
            "{$r['fuel_type']} is at {$pct}% capacity ({$r['current_level']}L remaining). Refill needed.",
            $key, 'manager_inventory_fuel.php'
        );
    }
} catch (Exception $e) {}

// ------------------------------------------------------------
// 4. FUEL DAILY READINGS  -  negative variance / invalid readings
//    Column verified: fuel_type, pump_id, reading_date (no pump_number)
// ------------------------------------------------------------
try {
    $s = $sw ? "AND station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT id, fuel_type, pump_id, reading_date,
                (current_reading - previous_reading - liters_sold) AS variance
         FROM fuel_daily_readings
         WHERE reading_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
           AND (current_reading - previous_reading - liters_sold) < 0
           {$s}
         ORDER BY reading_date DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $pump     = $r['pump_id'] ?? $r['id'];
        $variance = number_format((float)$r['variance'], 2);
        $key      = 'mgr_fuel_invalid_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'error', 'fuel_management', 'high',
            "Invalid Meter Reading  -  {$r['fuel_type']} Pump #{$pump}",
            "{$r['fuel_type']} Pump #{$pump} has a variance of {$variance}L on {$r['reading_date']}. Verify reading.",
            $key, 'manager_fuel_transaction_validation.php'
        );
    }
} catch (Exception $e) {}

// ------------------------------------------------------------
// Auto-purge stale notifications for items whose stock level is OK
try {
    if ($sw) {
        $pdo->prepare("
            DELETE n FROM notifications n
            INNER JOIN station_inventory si ON (n.source_key LIKE CONCAT('mgr_inv_low_', si.product_id, '%') OR n.title LIKE '%Low Stock%' OR n.title LIKE '%Inventory Alert%')
            INNER JOIN inventory_products ip ON ip.id = si.product_id
            WHERE n.user_id = ?
              AND si.station_id = ?
              AND si.stock_level > COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)
              AND LOWER(COALESCE(ip.category, '')) NOT IN ('fuel', 'fuels')
        ")->execute([$user_id, $sw]);
    }
} catch (Exception $e) {}

// 5. INVENTORY  -  low stock alerts (station_inventory)
//    Column verified: si.reorder_level (station_inventory), ip.min_stock (inventory_products)
// ------------------------------------------------------------
try {
    if ($sw) {
        $stmt = $pdo->prepare(
            "SELECT ip.id, ip.product_name, ip.sku, si.stock_level,
                    COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level
             FROM station_inventory si
             INNER JOIN inventory_products ip ON ip.id = si.product_id
             WHERE si.station_id = ?
               AND si.stock_level >= 0
               AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 10)
               AND LOWER(ip.category) NOT IN ('fuel', 'fuels')
             ORDER BY si.stock_level ASC LIMIT 15"
        );
        $stmt->execute([$sw]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = [];
    }

    foreach ($rows as $r) {
        $stock = (int)($r['stock_level'] ?? 0);
        $code  = $r['sku'] ?? ('ID-' . $r['id']);
        $sev   = $stock <= 0 ? 'critical' : ($stock <= 5 ? 'high' : 'medium');
        $type  = $stock <= 0 ? 'error' : 'warning';
        $label = $stock <= 0 ? 'Out of stock' : "Low stock ({$stock} remaining)";
        $key   = 'mgr_inv_low_' . $r['id'] . '_' . date('Ymd');
        $generated += mgr_push($pdo, $user_id, $type, 'inventory', $sev,
            "Inventory Alert: {$r['product_name']}",
            "{$r['product_name']} ({$code})  -  {$label}.",
            $key, 'manager_inventory_merchandise.php'
        );
    }
} catch (Exception $e) {}

// ------------------------------------------------------------
// 6. STOCK REQUESTS  -  pending/flagged
//    Column verified: stock_requests.status, staff_id, station_id
// ------------------------------------------------------------
try {
    $s = $sw ? "AND sr.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT sr.id, sr.item_name, sr.status, sr.created_at,
                u.name AS staff_name
         FROM stock_requests sr
         LEFT JOIN users u ON u.id = sr.staff_id
         WHERE sr.status IN ('pending','Pending','Pending Manager Review')
           AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           {$s}
         ORDER BY sr.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $staff = $r['staff_name'] ?? 'Staff';
        $key   = 'mgr_stock_req_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'warning', 'inventory', 'medium',
            "Stock Request Pending: {$r['item_name']}",
            "Staff {$staff} has a pending stock request for {$r['item_name']} (Request #{$r['id']}).",
            $key, 'manager_stock_request_review.php?tab=pending_requests'
        );
    }
} catch (Exception $e) {}

// ------------------------------------------------------------
// 7. CUSTOMERS  -  pending validation (7d)
//    Column verified: customers.status
// ------------------------------------------------------------
try {
    $s = $sw ? "AND cr.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT cr.id, cr.first_name, cr.middle_name, cr.last_name, cr.station_id, cr.requested_by, cr.created_at,
                TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS staff_name,
                u.username AS staff_username
         FROM customer_requests cr
         LEFT JOIN users u ON u.id = cr.requested_by
         WHERE LOWER(cr.status) = 'pending'
           AND cr.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
           {$s}
         ORDER BY cr.created_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $staff = trim($r['staff_name'] ?? '') ?: ($r['staff_username'] ?? 'Staff');
        $cust  = trim(($r['first_name'] ?? '') . ' ' . (!empty($r['middle_name']) ? $r['middle_name'] . ' ' : '') . ($r['last_name'] ?? ''));
        if (!$cust) $cust = 'Customer #' . $r['id'];
        $key   = "cust_req_sub_{$r['station_id']}_{$r['id']}_m{$user_id}";
        $generated += mgr_push($pdo, $user_id, 'info', 'customer_request', 'medium',
            "New Customer Registration Request",
            "Staff {$staff} submitted a new customer registration request for {$cust}. Review the request for approval.", // ------------------------------------------------------------- pending validation.",
            $key, 'manager_customers.php?tab=pending&id=' . $r['id']
        );
    }
} catch (Exception $e) {}

// ------------------------------------------------------------
// 8. DELIVERIES  -  awaiting manager action (72h)
//    Column verified: status, supplier, delivery_date, station_id
// ------------------------------------------------------------
try {
    $s = $sw ? "AND do2.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT do2.id, do2.status, do2.supplier, do2.delivery_date,
                do2.delivery_type, do2.updated_at
         FROM deliveries_oversight do2
         WHERE do2.updated_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
           {$s}
         ORDER BY do2.updated_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $status   = $r['status'] ?? '';
        $supplier = $r['supplier'] ?? 'Supplier';
        $dt       = $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : 'TBD';

        if (in_array($status, ['Pending Manager Approval', 'Pending Manager Confirmation'])) {
            $key = 'mgr_del_action_' . $r['id'] . '_' . md5($status);
            $generated += mgr_push($pdo, $user_id, 'warning', 'delivery', 'high',
                "Delivery #{$r['id']} Awaiting Your Action",
                "Delivery #{$r['id']} from {$supplier} is awaiting your action ({$status}).",
                $key, 'manager_merchandise_deliveries.php'
            );
        } elseif (in_array($status, ['Discrepancy', 'Flagged', 'Delayed'])) {
            $key = 'mgr_del_issue_' . $r['id'] . '_' . md5($status);
            $generated += mgr_push($pdo, $user_id, 'error', 'delivery', 'high',
                "Delivery #{$r['id']}  -  {$status}",
                "Delivery #{$r['id']} from {$supplier} is {$status}  -  awaiting manager action.",
                $key, 'manager_merchandise_deliveries.php'
            );
        } elseif (in_array($status, ['En Route', 'In Transit', 'Dispatched', 'Expected Delivery'])) {
            $key = 'mgr_del_enroute_' . $r['id'];
            $generated += mgr_push($pdo, $user_id, 'info', 'delivery', 'low',
                "Delivery #{$r['id']}  -  {$status}",
                "Delivery #{$r['id']} from {$supplier} is {$status}. Expected: {$dt}.",
                $key, 'manager_merchandise_deliveries.php'
            );
        }
    }
} catch (Exception $e) {}

// ------------------------------------------------------------
// 9. CALENDAR  -  today's events
//    Column verified: event_date, event_time, event_type, status
// ------------------------------------------------------------
try {
    $s = $sw ? "AND (ce.station_id = {$sw} OR ce.station_id IS NULL)" : '';
    $rows = $pdo->query(
        "SELECT ce.id, ce.event_date, ce.event_time, ce.event_type,
                ce.work_description, ce.status, u.name AS assigned_name
         FROM calendar_events ce
         LEFT JOIN users u ON u.id = ce.staff_assigned
         WHERE ce.event_date = CURDATE()
           {$s}
         ORDER BY ce.event_time ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $time  = $r['event_time'] ? date('h:i A', strtotime($r['event_time'])) : 'All Day';
        $title = $r['work_description'] ?: ucfirst($r['event_type'] ?? 'Event');
        $who   = $r['assigned_name'] ? "  -  {$r['assigned_name']}" : '';
        $key   = 'mgr_cal_today_' . $r['id'] . '_' . date('Ymd');
        $generated += mgr_push($pdo, $user_id, 'info', 'calendar', 'low',
            "Schedule: {$title}",
            "Schedule entry: {$title}{$who} at {$time} today.",
            $key, 'manager_calendar.php'
        );
    }
} catch (Exception $e) {}

// ------------------------------------------------------------
// 10. REPORTS  -  weekly/monthly reports ready
// ------------------------------------------------------------
try {
    $s = $sw ? "AND (al.station_id = {$sw} OR al.station_id IS NULL)" : '';
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Weekly%Report%'
             OR al.action LIKE '%Monthly%Report%'
             OR al.action LIKE '%Report%Generated%'
             OR al.action LIKE '%Sales%Report%'
             OR al.action LIKE '%Daily%Summary%'
             OR al.details LIKE '%report%ready%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           {$s}
         ORDER BY al.created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $ts  = date('M d, Y h:i A', strtotime($r['created_at']));
        $key = 'mgr_report_' . $r['id'];
        $generated += mgr_push($pdo, $user_id, 'success', 'report', 'low',
            $r['action'],
            mb_strimwidth($r['details'] ?? $r['action'], 0, 120, '...') . "  -  ready at {$ts}.",
            $key, 'manager_reports.php'
        );
    }
} catch (Exception $e) {}

// ------------------------------------------------------------- Cleanup old read notifications ( 14 days) -------------------------------------------------------------
try {
    $stmt = $pdo->prepare(
        "DELETE FROM notifications
         WHERE user_id = ? AND status = 'read'
           AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"
    );
    $stmt->execute([$user_id]);
} catch (Exception $e) {}


// -------------------------------------------------------------Cleanup stale stock request notifications -------------------------
// Remove mgr_stock_req_* notifications where the stock_request is no longer Pending
try {
    $pdo->prepare(
        "DELETE n FROM notifications n
         INNER JOIN stock_requests sr
             ON sr.id = CAST(SUBSTRING(n.source_key, LENGTH('mgr_stock_req_') + 1) AS UNSIGNED)
         WHERE n.user_id = ?
           AND n.source_key LIKE 'mgr_stock_req_%'
           AND sr.status NOT IN ('Pending', 'pending', 'Pending Manager Review')"
    )->execute([$user_id]);
} catch (Exception $e) {}

// -------------------------------------------------------------Cleanup old fuel low alerts (keep only today's) ----------------------
try {
    $pdo->prepare(
        "DELETE FROM notifications
         WHERE user_id = ?
           AND source_key LIKE 'mgr_fuel_low_%'
           AND source_key NOT LIKE '%" . date('Ymd') . "'"
    )->execute([$user_id]);
} catch (Exception $e) {}

// -------------------------------------------------------------Cleanup old fuel shift notifications (>2 days) -----------------------
try {
    $pdo->prepare(
        "DELETE FROM notifications
         WHERE user_id = ?
           AND source_key LIKE 'mgr_fuel_shift_%'
           AND created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)"
    )->execute([$user_id]);
} catch (Exception $e) {}

echo json_encode(['ok' => true, 'generated' => $generated]);
