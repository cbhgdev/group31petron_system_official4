<?php
/**
 * Staff Notification Generator
 * backend/api/staff_notification_generator.php
 *
 * Scans real operational tables and inserts dynamic notifications into the
 * notifications table for the currently logged-in staff/manager/admin user.
 *
 * Called via AJAX from the header on every page load for non-superadmin roles.
 * Uses source_key to prevent duplicate notifications.
 *
 * Notification Sources (schema-verified):
 *  1. Fuel Transactions      — fuel_transactions (Pending Validation)
 *  2. Job Orders             — job_orders (pending/in-progress/completed)
 *  3. Fuel Management        — fuel_inventory (low tanks ≤ 20%)
 *  4. Inventory              — station_inventory + inventory_products (low stock)
 *  5. Customers              — customers (pending validation)
 *  6. Deliveries             — deliveries_oversight (status updates 48h)
 *  7. Reports                — activity_logs (daily summaries)
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me      = current_user();
$role    = role_key($me['role'] ?? '');
$user_id = (int)($me['id'] ?? 0);

// Only for operational roles (not superadmin — they have their own generator)
$allowed_roles = ['staff', 'manager', 'admin'];
if (!in_array($role, $allowed_roles)) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit;
}

$station_id = (int)(user_station_id() ?? 0);
if ($station_id <= 0) {
    echo json_encode(['ok' => true, 'generated' => 0, 'message' => 'No station assigned']); exit;
}
$sw = $station_id ? $station_id : 0;

// ── Ensure notifications table exists ────────────────────────
try { ensure_notifications_table($pdo); } catch (Exception $e) {}

// Auto-cleanup legacy staff_requests.php file and redirect URLs
try {
    $stale_file = __DIR__ . '/../../public/staff_requests.php';
    if (file_exists($stale_file)) {
        @unlink($stale_file);
    }
    $pdo->exec("UPDATE notifications SET redirect_url = 'staff_transactions_hub.php?section=merchandise' WHERE redirect_url LIKE '%staff_requests.php%'");
    $pdo->exec("UPDATE notifications SET redirect_url = 'staff_inventory_fuel.php' WHERE (redirect_url LIKE '%staff_fuel_deliveries.php%' OR redirect_url LIKE '%staff_record_delivery.php%tab=fuel%') OR (redirect_url LIKE '%staff_record_delivery.php%' AND (title LIKE '%Fuel%' OR event_type IN ('fuel','fuel_stock_in','fuel_delivery') OR message LIKE '%Fuel%'))");
    $pdo->exec("UPDATE notifications SET redirect_url = 'staff_inventory_merchandise.php' WHERE redirect_url LIKE '%staff_record_delivery.php%' OR (event_type IN ('stock_in','merchandise_stock_in','delivery') AND (redirect_url LIKE '%staff_fuel_deliveries.php%' OR redirect_url LIKE '%staff_record_delivery.php%'))");
} catch (Exception $e) {}

$generated = 0;

/**
 * Insert a notification for the current user.
 * source_key prevents duplicates — same event is never inserted twice.
 */
if (!function_exists('push_notif')) {
function push_notif(
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
}

// ════════════════════════════════════════════════════════════
// 1. FUEL TRANSACTIONS — Pending Validation (last 48h)
//    Column verified: status, transaction_id, fuel_type, station_id
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND ft.station_id = {$sw}" : '';
    // Staff: own transactions; manager/admin: all station transactions
    $u = ($role === 'staff') ? "AND ft.staff_id = {$user_id}" : '';

    $rows = $pdo->query(
        "SELECT ft.id, ft.transaction_id, ft.status, ft.fuel_type,
                ft.transaction_date, ft.liters_sold, u.name AS staff_name
         FROM fuel_transactions ft
         LEFT JOIN users u ON u.id = ft.staff_id
         WHERE ft.transaction_date >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           AND ft.status IN ('Pending Validation','pending_validation','Pending','pending','Failed','failed')
           {$s} {$u}
         ORDER BY ft.transaction_date DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $txn    = $r['transaction_id'] ?? ('#' . $r['id']);
        $status = $r['status'];
        $liters = $r['liters_sold'] ? number_format($r['liters_sold'], 2) . 'L' : '';
        $ts     = date('M d, H:i', strtotime($r['transaction_date']));
        $type   = in_array(strtolower($status), ['failed']) ? 'error' : 'warning';
        $sev    = in_array(strtolower($status), ['failed']) ? 'high' : 'medium';
        $key    = 'txn_fuel_' . $r['id'];
        $generated += push_notif(
            $pdo, $user_id, $type, 'transaction',
            $sev,
            "Fuel Transaction #{$r['id']} — {$status}",
            "Fuel Transaction #{$r['id']} ({$r['fuel_type']} {$liters}) is {$status} at {$ts}.",
            $key,
            'staff_transactions_hub.php?section=fuel&fuel_tab=readings'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 2. JOB ORDERS — recent status changes (48h)
//    Column verified: job_order_id, status, validation_status,
//                     customer_name, service_type, created_by, user_id
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND jo.station_id = {$sw}" : '';
    // For staff: jobs they created or are assigned to
    $u = ($role === 'staff')
        ? "AND (jo.created_by = {$user_id} OR jo.user_id = {$user_id})"
        : '';

    $rows = $pdo->query(
        "SELECT jo.id, jo.job_order_id, jo.status, jo.validation_status,
                jo.customer_name, jo.service_type, jo.created_at, jo.updated_at
         FROM job_orders jo
         WHERE jo.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           {$s} {$u}
         ORDER BY jo.created_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $jo_num  = $r['job_order_id'] ?? ('#' . $r['id']);
        $status  = $r['status'] ?? 'Unknown';
        $val_st  = $r['validation_status'] ?? '';
        $display = $val_st && $val_st !== $status ? "{$status} / {$val_st}" : $status;
        $cust    = $r['customer_name'] ?? 'Customer';
        $svc     = $r['service_type'] ?? 'Service';

        $type = 'info'; $sev = 'low';
        if (in_array(strtolower($status), ['pending validation', 'pending'])) {
            $type = 'warning'; $sev = 'medium';
        } elseif (in_array(strtolower($status), ['completed', 'done', 'paid', 'released'])) {
            $type = 'success'; $sev = 'low';
        } elseif (in_array(strtolower($status), ['cancelled', 'rejected', 'canceled'])) {
            $type = 'error'; $sev = 'high';
        }

        $key = 'jo_status_' . $r['id'] . '_' . md5($display);
        $generated += push_notif(
            $pdo, $user_id, $type, 'job_order',
            $sev,
            "Job Order {$jo_num} — {$display}",
            "Job Order {$jo_num} ({$svc}) for {$cust} is now {$display}.",
            $key,
            'staff_transactions_hub.php?section=merchandise&active_tab=tracker'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 3. FUEL MANAGEMENT — low fuel inventory (≤ 20%)
//    Column verified: fuel_type, current_level, capacity, station_id
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND fi.station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT fi.id, fi.fuel_type, fi.current_level, fi.capacity, fi.station_id
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
        $key  = 'fuel_low_' . $r['id'] . '_' . date('Ymd');
        $generated += push_notif(
            $pdo, $user_id, $type, 'fuel_management',
            $sev,
            "Low Fuel Alert: {$r['fuel_type']}",
            "{$r['fuel_type']} is at {$pct}% capacity ({$r['current_level']}L remaining). Refill needed.",
            $key,
            'staff_inventory_fuel.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 4. INVENTORY — low stock alerts
//    Column verified: si.reorder_level (station_inventory), ip.min_stock (inventory_products)
// ════════════════════════════════════════════════════════════
try {
    if ($sw) {
        $stmt = $pdo->prepare(
            "SELECT ip.id, ip.product_name, ip.sku, si.stock_level,
                    COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level
             FROM station_inventory si
             INNER JOIN inventory_products ip ON ip.id = si.product_id
             WHERE si.stock_level <= COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10) AND si.station_id = ?
               AND si.stock_level >= 0
               AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 10)
               AND LOWER(COALESCE(ip.category, '')) NOT IN ('fuel', 'fuels')
             ORDER BY si.stock_level ASC LIMIT 15"
        );
        $stmt->execute([$sw]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query(
            "SELECT id, product_name, sku, COALESCE(stock_quantity, stock, 0) AS stock_level,
                    COALESCE(min_stock, 10) AS reorder_level
             FROM inventory_products
             WHERE COALESCE(stock_quantity, stock, 0) <= COALESCE(min_stock, 10)
               AND LOWER(COALESCE(category, '')) NOT IN ('fuel', 'fuels')
             ORDER BY stock_level ASC LIMIT 15"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as $r) {
        $stock = (int)($r['stock_level'] ?? 0);
        $code  = !empty(trim((string)($r['sku'] ?? ''))) ? trim((string)$r['sku']) : ('ID-' . $r['id']);
        $sev   = $stock <= 0 ? 'critical' : ($stock <= 5 ? 'high' : 'medium');
        $type  = $stock <= 0 ? 'error' : 'warning';
        $label = $stock <= 0 ? 'Out of stock' : "Low stock ({$stock} remaining)";
        $key   = 'low_stock_' . $r['id'] . '_' . date('Ymd');
        $generated += push_notif(
            $pdo, $user_id, $type, 'inventory',
            $sev,
            "Low Stock Alert: {$r['product_name']}",
            "{$label}: {$r['product_name']} ({$code}).",
            $key,
            'staff_inventory_merchandise.php'
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 5. CUSTOMERS — pending validation (7d)
//    Column verified: status (values: 'active', 'pending', etc.)
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND station_id = {$sw}" : '';
    $rows = $pdo->query(
        "SELECT id, name, status, created_at
         FROM customers
         WHERE status IN ('pending','Pending','pending_validation','Pending Validation')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           {$s}
         ORDER BY created_at DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $cust = $r['name'] ?? ('Customer #' . $r['id']);
        $key  = 'customer_pending_' . $r['id'];
        $generated += push_notif(
            $pdo, $user_id, 'info', 'customer',
            'low',
            "Customer Pending Validation",
            "Customer {$cust} uploaded ID — pending validation.",
            $key,
            'staff_customer_list.php'
        );
    }
} catch (Exception $e) {}

try {
    if ($role === 'staff') {
        $req_stmt = $pdo->prepare(
            "SELECT id, first_name, middle_name, last_name, status, manager_remarks, created_at, updated_at
             FROM customer_requests
             WHERE requested_by = ?
               AND status IN ('approved', 'rejected')
               AND (updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))
             ORDER BY id DESC LIMIT 15"
        );
        $req_stmt->execute([$user_id]);
        $req_rows = $req_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($req_rows as $cr) {
            $req_num = 'CR-' . str_pad($cr['id'], 5, '0', STR_PAD_LEFT);
            $cust_name = trim($cr['first_name'] . ' ' . (!empty($cr['middle_name']) ? $cr['middle_name'] . ' ' : '') . $cr['last_name']);
            if (strtolower($cr['status']) === 'approved') {
                $key = "cust_req_app_{$cr['id']}_s{$user_id}";
                $generated += push_notif(
                    $pdo, $user_id, 'success', 'customer_request', 'medium',
                    "Customer Registration Request Approved",
                    "Customer registration request {$req_num} for {$cust_name} has been approved.",
                    $key,
                    "staff_transactions_hub.php?section=merchandise"
                );
            } elseif (strtolower($cr['status']) === 'rejected') {
                $key = "cust_req_rej_{$cr['id']}_s{$user_id}";
                $reason = !empty($cr['manager_remarks']) ? " Reason: {$cr['manager_remarks']}" : " Reason: Requirements not met.";
                $generated += push_notif(
                    $pdo, $user_id, 'error', 'customer_request', 'medium',
                    "Customer Registration Request Rejected",
                    "Customer registration request {$req_num} was rejected.{$reason}",
                    $key,
                    "staff_transactions_hub.php?section=merchandise"
                );
            }
        }
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 6. DELIVERIES — recent status updates (48h)
//    Column verified: status, supplier, delivery_date, delivery_type, updated_at
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND do2.station_id = {$sw}" : '';
    // For staff: only deliveries they encoded
    $u = ($role === 'staff') ? "AND do2.encoded_by = {$user_id}" : '';

    $rows = $pdo->query(
        "SELECT do2.id, do2.status, do2.supplier, do2.delivery_date,
                do2.delivery_type, do2.updated_at
         FROM deliveries_oversight do2
         WHERE do2.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
           {$s} {$u}
         ORDER BY do2.updated_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $status      = $r['status'] ?? 'Unknown';
        $supplier    = $r['supplier'] ?? 'Supplier';
        $dt          = $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : 'TBD';
        $deliv_type  = strtolower($r['delivery_type'] ?? '');

        // Route to correct staff inventory module based on delivery type
        $is_fuel_delivery = (strpos($deliv_type, 'fuel') !== false || stripos($status, 'fuel') !== false);
        $redirect_page = $is_fuel_delivery
            ? 'staff_inventory_fuel.php'
            : 'staff_inventory_merchandise.php';

        $type = 'info'; $sev = 'low';
        if (in_array($status, ['Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Verification'])) {
            $type = 'warning'; $sev = 'medium';
        } elseif (in_array($status, ['Discrepancy', 'Flagged', 'Delayed'])) {
            $type = 'error'; $sev = 'high';
        } elseif (in_array($status, ['Confirmed', 'Validated', 'Stock-In Complete'])) {
            $type = 'success'; $sev = 'low';
        } elseif (in_array($status, ['En Route', 'In Transit', 'Dispatched', 'Expected Delivery'])) {
            $type = 'info'; $sev = 'low';
        }

        $key = 'delivery_' . $r['id'] . '_' . md5($status);
        $generated += push_notif(
            $pdo, $user_id, $type, 'delivery',
            $sev,
            "Delivery #{$r['id']} — {$status}",
            "Delivery #{$r['id']} from {$supplier} is now {$status}. Expected: {$dt}.",
            $key,
            $redirect_page
        );
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 7. REPORTS — daily transaction summary
//    Source: activity_logs
// ════════════════════════════════════════════════════════════
try {
    $s = $sw ? "AND (al.station_id = {$sw} OR al.station_id IS NULL)" : '';
    $rows = $pdo->query(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.action LIKE '%Daily%Summary%' OR al.action LIKE '%Report%Generated%'
                OR al.action LIKE '%report_generated%' OR al.details LIKE '%daily summary%')
           AND DATE(al.created_at) = CURDATE()
           {$s}
         ORDER BY al.created_at DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $ts  = date('h:i A', strtotime($r['created_at']));
        $key = 'report_daily_' . $r['id'];
        $generated += push_notif(
            $pdo, $user_id, 'success', 'report',
            'low',
            "Daily Transaction Summary Ready",
            "Daily transaction summary ready at {$ts}.",
            $key,
            'staff_fuel_sales_summary.php'
        );
    }
} catch (Exception $e) {}

// ── Clean up old read notifications for this user (> 14 days) ─
try {
    $stmt = $pdo->prepare(
        "DELETE FROM notifications
         WHERE user_id = ? AND status = 'read'
           AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"
    );
    $stmt->execute([$user_id]);
} catch (Exception $e) {}

// ── Fix any stale notifications with wrong redirect URLs ─────
try {
    $bad_urls = [
        'staff_inventory.php',                         // old — now staff_inventory_merchandise.php
        'staff_transactions_hub.php?section=fuel',     // too generic — replaced by specific fuel pages
    ];
    foreach ($bad_urls as $bad) {
        $pdo->prepare(
            "DELETE FROM notifications
             WHERE user_id = ? AND redirect_url = ? AND status = 'unread'"
        )->execute([$user_id, $bad]);
    }
} catch (Exception $e) {}

echo json_encode(['ok' => true, 'generated' => $generated]);
