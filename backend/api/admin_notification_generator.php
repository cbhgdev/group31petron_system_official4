<?php
/**
 * Admin Notification Generator
 * backend/api/admin_notification_generator.php
 *
 * Scans real operational tables and inserts dynamic notifications into the
 * notifications table for the currently logged-in Admin user.
 *
 * Called via AJAX from the header on every page load for Admin role.
 * Uses source_key to prevent duplicate/stale notifications.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me      = current_user();
$role    = role_key($me['role'] ?? '');
$user_id = (int)($me['id'] ?? 0);

if ($role !== 'admin') {
    echo json_encode(['ok' => false, 'error' => 'Admin only']); exit;
}

$station_id = (int)(user_station_id() ?? 0);
if ($station_id <= 0) {
    echo json_encode(['ok' => true, 'generated' => 0, 'message' => 'No station assigned']); exit;
}

// ── Ensure notifications table exists ────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        type         ENUM('success','warning','error','info') NOT NULL DEFAULT 'info',
        title        VARCHAR(255) NOT NULL DEFAULT '',
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

//Keep older unread notifications aligned with current module routes.
try {
    $redirect_fixes = [
        'admin_transactions_oversight.php' => 'manager_validated_transactions.php',
        'purchase_orders.php'             => 'manager_stock_request_review.php',
        'admin_procurement_reports.php?section=po' => 'manager_stock_request_review.php',
        'inventory.php'                   => 'admin_inventory_merchandise.php',
        'admin_fuel_transactions_oversight.php' => 'admin_inventory_fuel.php?tab=adjustments',
        'admin_audit_trail.php'           => 'admin_reports.php?cat=audit',
    ];
    $fix_stmt = $pdo->prepare("UPDATE notifications SET redirect_url=? WHERE user_id=? AND redirect_url=?");
    foreach ($redirect_fixes as $old_url => $new_url) {
        $fix_stmt->execute([$new_url, $user_id, $old_url]);
    }
    // Clean up phantom daily date-keyed notifications
    $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND (source_key LIKE 'fuel_txns_recent_%' OR title = 'Fuel Transactions Oversight')")->execute([$user_id]);
    $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND source_key LIKE 'no_shifts_%' AND source_key != ?")->execute([$user_id, "no_shifts_{$station_id}"]);
    $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND source_key LIKE 'mgr_actions_%' AND source_key != ?")->execute([$user_id, "mgr_actions_{$station_id}"]);
    $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND source_key LIKE 'suspicious_audit_%' AND source_key != ?")->execute([$user_id, "suspicious_audit_{$station_id}"]);
} catch (Exception $e) {}

/**
 * Upsert a notification:
 * - If source_key already exists for this user AND status='unread' -> skip (already notified)
 * - If source_key exists but status='read' -> keep read
 * - If source_key doesn't exist -> insert
 */
function upsert_notif(PDO $pdo, int $user_id, array $data): int {
    $key = $data['source_key'] ?? null;
    if ($key) {
        $existing = $pdo->prepare(
            "SELECT id, status, message FROM notifications WHERE user_id=? AND source_key=? ORDER BY created_at DESC LIMIT 1"
        );
        $existing->execute([$user_id, $key]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // User already read it → keep it read, never re-open as unread!
            if ($row['status'] === 'read') return 0;
            $m_db   = trim((string)($row['message'] ?? ''));
            $m_data = trim((string)($data['message'] ?? ''));
            // Already unread with same message → skip (no change)
            if ($row['status'] === 'unread' && $m_db === $m_data) return 0;
            // Unread with updated count/message → update text
            $upd = $pdo->prepare(
                "UPDATE notifications SET message=?, type=?, severity=?, created_at=NOW()
                 WHERE id=?"
            );
            $upd->execute([$data['message'], $data['type'], $data['severity'], $row['id']]);
            return 0;
        }
    }

    $ins = $pdo->prepare(
        "INSERT INTO notifications (user_id, recipient_role, type, title, message, event_type, severity, source_key, redirect_url, status)
         VALUES (?, 'admin', ?, ?, ?, ?, ?, ?, ?, 'unread')"
    );
    $ins->execute([
        $user_id,
        $data['type']        ?? 'info',
        $data['title']       ?? 'Alert',
        $data['message']     ?? '',
        $data['event_type']  ?? 'general',
        $data['severity']    ?? 'medium',
        $data['source_key']  ?? null,
        $data['redirect_url'] ?? null,
    ]);
    return 1;
}

function adm_count(PDO $pdo, string $sql, array $p = []): int {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return (int)$s->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// Station clause helper for admin queries (admin is strictly scoped to their assigned station)
$stn_sql  = "station_id = ? AND ";
$stn_p    = [$station_id];

// ════════════════════════════════════════════════════════════
// 1. FUEL ADJUSTMENTS REQUIRING ADMIN APPROVAL
// ════════════════════════════════════════════════════════════
$fuel_adj_pending = adm_count($pdo,
    "SELECT COUNT(*) FROM fuel_adjustments WHERE {$stn_sql}LOWER(COALESCE(status,'')) IN ('pending admin approv','pending admin approval','pending admin review','pending validation','pending')",
    $stn_p);
if ($fuel_adj_pending > 0) {
    $generated += upsert_notif($pdo, $user_id, [
        'type'        => 'warning',
        'title'       => 'Fuel Adjustments Awaiting Approval',
        'message'     => "{$fuel_adj_pending} physical tank dip / fuel reading adjustment(s) require Admin review and approval.",
        'event_type'  => 'fuel_adjustment',
        'severity'    => 'high',
        'source_key'  => "fuel_adj_pending_{$station_id}",
        'redirect_url'=> 'admin_inventory_fuel.php?tab=adjustments',
    ]);
} else {
    try {
        $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND source_key=?")->execute([$user_id, "fuel_adj_pending_{$station_id}"]);
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════════
// 2. DELIVERIES AWAITING ADMIN OVERSIGHT
// ════════════════════════════════════════════════════════════
$pending_admin_del = adm_count($pdo,
    "SELECT COUNT(*) FROM deliveries_oversight WHERE {$stn_sql}status='Pending Admin Oversight'",
    $stn_p);
if ($pending_admin_del > 0) {
    $generated += upsert_notif($pdo, $user_id, [
        'type'        => 'warning',
        'title'       => 'Deliveries Awaiting Your Oversight',
        'message'     => "{$pending_admin_del} delivery(ies) have been validated by Manager and are now awaiting your final oversight.",
        'event_type'  => 'delivery',
        'severity'    => 'high',
        'source_key'  => "admin_del_oversight_{$station_id}",
        'redirect_url'=> 'manager_stock_in.php',
    ]);
} else {
    try {
        $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND source_key=?")->execute([$user_id, "admin_del_oversight_{$station_id}"]);
    } catch (Exception $e) {}
}

// ── 3b. Flagged Deliveries ────────────────────────────────
$flagged_del = adm_count($pdo,
    "SELECT COUNT(*) FROM deliveries_oversight WHERE {$stn_sql}status IN ('Flagged','Discrepancy')",
    $stn_p);
if ($flagged_del > 0) {
    $generated += upsert_notif($pdo, $user_id, [
        'type'        => 'error',
        'title'       => 'Flagged Deliveries',
        'message'     => "{$flagged_del} delivery(ies) flagged with discrepancies. Immediate review required.",
        'event_type'  => 'delivery',
        'severity'    => 'critical',
        'source_key'  => "flagged_del_{$station_id}",
        'redirect_url'=> 'manager_stock_in.php',
    ]);
} else {
    try {
        $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND source_key=?")->execute([$user_id, "flagged_del_{$station_id}"]);
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════════
// 5. PENDING PURCHASE ORDERS
// ════════════════════════════════════════════════════════════
$pending_po = adm_count($pdo,
    "SELECT COUNT(*) FROM purchase_orders WHERE {$stn_sql}status IN ('Pending','Pending Approval','Pending Admin Validation')",
    $stn_p);
if ($pending_po > 0) {
    $generated += upsert_notif($pdo, $user_id, [
        'type'        => 'warning',
        'title'       => 'Pending Purchase Orders',
        'message'     => "{$pending_po} purchase order(s) awaiting admin validation.",
        'event_type'  => 'purchase_order',
        'severity'    => 'medium',
        'source_key'  => "pending_po_{$station_id}",
        'redirect_url'=> 'manager_stock_request_review.php',
    ]);
} else {
    try {
        $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND source_key=?")->execute([$user_id, "pending_po_{$station_id}"]);
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════════
// 7. FUEL VARIANCE ALERTS
// ════════════════════════════════════════════════════════════
$variance_open = adm_count($pdo,
    "SELECT COUNT(*) FROM variance_alerts WHERE {$stn_sql}status='open'",
    $stn_p);
if ($variance_open > 0) {
    $var_liters = 0;
    try {
        $vs = $pdo->prepare("SELECT COALESCE(SUM(ABS(variance_liters)),0) FROM fuel_variance_reports WHERE {$stn_sql}DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
        $vs->execute($stn_p);
        $var_liters = (float)$vs->fetchColumn();
    } catch (Exception $e) {}
    $generated += upsert_notif($pdo, $user_id, [
        'type'        => 'error',
        'title'       => 'Fuel Variance Alerts',
        'message'     => "{$variance_open} open variance alert(s). Total discrepancy: ".number_format($var_liters,2)."L. Pump vs sales vs deliveries mismatch detected.",
        'event_type'  => 'report',
        'severity'    => 'critical',
        'source_key'  => "variance_open_{$station_id}",
        'redirect_url'=> 'admin_reports.php?tab=variance',
    ]);
} else {
    try {
        $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND source_key=?")->execute([$user_id, "variance_open_{$station_id}"]);
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════════
// 8. ACCOUNTS RECEIVABLE OUTSTANDING
// ════════════════════════════════════════════════════════════
$ar_overdue = adm_count($pdo,
    "SELECT COUNT(*) FROM customers WHERE {$stn_sql}COALESCE(current_balance, balance, 0) > 0",
    $stn_p);
if ($ar_overdue > 0) {
    $ar_total = 0;
    try {
        $as = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(current_balance, balance, 0)),0) FROM customers WHERE {$stn_sql}COALESCE(current_balance, balance, 0) > 0");
        $as->execute($stn_p);
        $ar_total = (float)$as->fetchColumn();
    } catch (Exception $e) {}
    $generated += upsert_notif($pdo, $user_id, [
        'type'        => 'warning',
        'title'       => 'Accounts Receivable Outstanding',
        'message'     => "{$ar_overdue} customer(s) with outstanding balance. Total: &#8369;".number_format($ar_total,2).".",
        'event_type'  => 'customer',
        'severity'    => 'high',
        'source_key'  => "ar_overdue_{$station_id}",
        'redirect_url'=> 'admin_reports.php?tab=receivable',
    ]);
} else {
    try {
        $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND source_key=?")->execute([$user_id, "ar_overdue_{$station_id}"]);
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════════
// 6b. PENDING PRICE CHANGE APPROVALS
// ════════════════════════════════════════════════════════════
$pending_prices = adm_count($pdo,
    "SELECT COUNT(*) FROM pending_price_approvals WHERE station_id = ? AND status = 'pending'",
    [$station_id]);
if ($pending_prices > 0) {
    $generated += upsert_notif($pdo, $user_id, [
        'type'        => 'warning',
        'title'       => 'Pending Price Change Approvals',
        'message'     => "{$pending_prices} price update request(s) submitted by Manager awaiting your review and approval.",
        'event_type'  => 'report',
        'severity'    => 'high',
        'source_key'  => "pending_price_appr_{$station_id}",
        'redirect_url'=> 'admin_set_prices.php',
    ]);
} else {
    try {
        $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND source_key=?")
            ->execute([$user_id, "pending_price_appr_{$station_id}"]);
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════════
// 7. STAFF SHIFT ISSUES (No Active Shifts Today)
// ════════════════════════════════════════════════════════════
$active_shifts = adm_count($pdo,
    "SELECT COUNT(*) FROM labor_sessions WHERE station_id=? AND DATE(start_time)=CURDATE()",
    [$station_id]);

$staff_audit_today = 0;
try {
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs al
         JOIN users u ON u.id = al.user_id
         WHERE u.station_id = ?
           AND LOWER(u.role) = 'staff'
           AND DATE(al.created_at) = CURDATE()"
    );
    $s->execute([$station_id]);
    $staff_audit_today = (int)$s->fetchColumn();
} catch (Exception $e) {}

$staff_logins_today = 0;
try {
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM user_sessions us
         JOIN users u ON u.id = us.user_id
         WHERE u.station_id = ?
           AND LOWER(u.role) = 'staff'
           AND DATE(us.login_time) = CURDATE()"
    );
    $s->execute([$station_id]);
    $staff_logins_today = (int)$s->fetchColumn();
} catch (Exception $e) {}

$txn_today   = adm_count($pdo,
    "SELECT COUNT(*) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE()",
    [$station_id]);
$merch_today = adm_count($pdo,
    "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE()",
    [$station_id]);

$has_staff_activity = ($active_shifts > 0) || ($staff_audit_today > 0) || ($staff_logins_today > 0) || ($txn_today > 0) || ($merch_today > 0);

if (!$has_staff_activity) {
    $generated += upsert_notif($pdo, $user_id, [
        'type'        => 'info',
        'title'       => 'No Active Shifts Today',
        'message'     => "No staff shifts logged for today. Check attendance and scheduling.",
        'event_type'  => 'general',
        'severity'    => 'low',
        'source_key'  => "no_shifts_{$station_id}",
        'redirect_url'=> 'users.php',
    ]);
} else {
    try {
        $del = $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND (source_key = ? OR source_key LIKE 'no_shifts_%' OR (title = 'No Active Shifts Today' AND DATE(created_at) = CURDATE()))");
        $del->execute([$user_id, "no_shifts_{$station_id}"]);
    } catch (Exception $e) {}
}

// ════════════════════════════════════════════════════════════
// 8. MANAGER ACTIONS (24h)
// ════════════════════════════════════════════════════════════
try {
    $mgr_actions = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs al
         JOIN users u ON u.id = al.user_id
         WHERE u.station_id=? AND LOWER(u.role) IN ('manager','supervisor')
         AND al.action_type IN ('Approved','Rejected','Adjusted')
         AND al.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $mgr_actions->execute([$station_id]);
    $mgr_cnt = (int)$mgr_actions->fetchColumn();
    if ($mgr_cnt > 0) {
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => 'info',
            'title'       => 'Manager Activity (24h)',
            'message'     => "{$mgr_cnt} manager action(s) in the last 24 hours (approvals, rejections, adjustments). Review audit trail.",
            'event_type'  => 'report',
            'severity'    => 'low',
            'source_key'  => "mgr_actions_{$station_id}",
            'redirect_url'=> 'admin_reports.php?cat=audit',
        ]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 9. SUSPICIOUS AUDIT ACTIONS (48h)
// ════════════════════════════════════════════════════════════
try {
    $suspicious = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs al
         JOIN users u ON u.id = al.user_id
         WHERE u.station_id=? AND al.action_type IN ('Delete','Bulk Delete','Override','Force Approve')
         AND al.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)"
    );
    $suspicious->execute([$station_id]);
    $sus_cnt = (int)$suspicious->fetchColumn();
    if ($sus_cnt > 0) {
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => 'error',
            'title'       => 'Suspicious Audit Actions Detected',
            'message'     => "{$sus_cnt} unusual action(s) logged in the last 48 hours (deletions, overrides, force approvals). Review immediately.",
            'event_type'  => 'report',
            'severity'    => 'critical',
            'source_key'  => "suspicious_audit_{$station_id}",
            'redirect_url'=> 'admin_reports.php?cat=audit',
        ]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 10. LOW INVENTORY (Summary + Per-Product Alerts like Manager & Staff)
// ════════════════════════════════════════════════════════════
try {
    // Auto-purge stale per-item low stock alerts whose stock level is now OK
    $pdo->prepare("
        DELETE n FROM notifications n
        INNER JOIN station_inventory si ON (
            n.source_key = CONCAT('inv_low_', si.product_id, '_adm_', ?)
            OR n.source_key = CONCAT('low_stock_', si.product_id, '_adm_', ?)
            OR n.source_key = CONCAT('mgr_inv_low_', si.product_id)
        )
        INNER JOIN inventory_products ip ON ip.id = si.product_id
        WHERE n.user_id = ?
          AND si.station_id = ?
          AND si.stock_level > COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)
          AND LOWER(COALESCE(ip.category, '')) NOT IN ('fuel', 'fuels')
    ")->execute([$user_id, $user_id, $user_id, $station_id]);

    $low_stmt = $pdo->prepare(
        "SELECT ip.id, ip.product_name, ip.sku, si.stock_level,
                COALESCE(si.reorder_level, ip.min_stock, 10) AS reorder_level
         FROM station_inventory si
         INNER JOIN inventory_products ip ON ip.id = si.product_id
         WHERE si.station_id = ?
           AND si.stock_level >= 0
           AND si.stock_level <= COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)
           AND LOWER(COALESCE(ip.category, '')) NOT IN ('fuel', 'fuels')
         ORDER BY si.stock_level ASC LIMIT 15"
    );
    $low_stmt->execute([$station_id]);
    $low_rows = $low_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($low_rows as $r) {
        $stock = (int)($r['stock_level'] ?? 0);
        $code  = !empty(trim((string)($r['sku'] ?? ''))) ? trim((string)$r['sku']) : ('ID-' . $r['id']);
        $sev   = $stock <= 0 ? 'critical' : ($stock <= 5 ? 'high' : 'medium');
        $type  = $stock <= 0 ? 'error' : 'warning';
        $label = $stock <= 0 ? 'Out of stock' : "Low stock ({$stock} remaining)";
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => $type,
            'title'       => "Inventory Alert: {$r['product_name']}",
            'message'     => "{$r['product_name']} ({$code}) - {$label}.",
            'event_type'  => 'inventory',
            'severity'    => $sev,
            'source_key'  => "inv_low_{$r['id']}_adm_{$user_id}",
            'redirect_url'=> 'admin_inventory_merchandise.php',
        ]);
    }
    if (empty($low_rows)) {
        $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND (source_key = ? OR source_key LIKE ?)")
            ->execute([$user_id, "low_inv_{$station_id}", "low_inv_{$station_id}%"]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 11. FUEL TRANSACTIONS — Shift Consolidated & Individual Pending/Failed (Manager + Staff parity)
// ════════════════════════════════════════════════════════════
try {
    $ft_shifts = $pdo->prepare(
        "SELECT ft.station_id,
                DATE(ft.transaction_date) AS tx_date,
                ft.shift_period,
                COUNT(*) AS total_readings,
                MAX(ft.transaction_date) AS max_date,
                COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.name, u.username, 'Staff') AS staff_name
         FROM fuel_transactions ft
         LEFT JOIN users u ON u.id = ft.staff_id
         WHERE ft.station_id = ?
           AND ft.status IN ('Pending Validation','pending_validation','Pending','pending','READINGS_SUBMITTED','CLOSING_COMPLETED')
           AND ft.transaction_date >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         GROUP BY ft.station_id, DATE(ft.transaction_date), ft.shift_period
         ORDER BY max_date DESC LIMIT 10"
    );
    $ft_shifts->execute([$station_id]);
    foreach ($ft_shifts->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $staff   = $r['staff_name'] ?? 'Staff';
        $readCnt = (int)($r['total_readings'] ?? 1);
        $shift   = !empty($r['shift_period']) ? (ucfirst($r['shift_period']) . ' Shift') : 'First Shift';
        $dateStr = date('M d, Y', strtotime($r['tx_date']));
        $key     = 'fuel_shift_' . $r['station_id'] . '_' . date('Ymd', strtotime($r['tx_date'])) . '_' . strtolower($r['shift_period'] ?? 'shift1') . '_adm_' . $user_id;
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => 'info',
            'title'       => 'New Fuel Reading - Pending Validation',
            'message'     => "{$staff} submitted {$readCnt} Fuel Readings for {$dateStr} ({$shift}). Ready for validation.",
            'event_type'  => 'fuel_transaction',
            'severity'    => 'high',
            'source_key'  => $key,
            'redirect_url'=> 'manager_fuel_transaction_validation.php?status_filter=pending',
        ]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 12. JOB ORDERS — Awaiting Validation, Completed, Cancelled (Manager + Staff parity)
// ════════════════════════════════════════════════════════════
try {
    $jo_stmt = $pdo->prepare(
        "SELECT jo.id, jo.job_order_id, jo.status, jo.validation_status,
                jo.customer_name, jo.service_type, jo.updated_at,
                COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.name, u.username, 'Staff') AS staff_name
         FROM job_orders jo
         LEFT JOIN users u ON u.id = jo.created_by
         WHERE jo.station_id = ?
           AND jo.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         ORDER BY jo.updated_at DESC LIMIT 20"
    );
    $jo_stmt->execute([$station_id]);
    foreach ($jo_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $jo_num = $r['job_order_id'] ?? ('#' . $r['id']);
        $status = $r['status'] ?? '';
        $val    = $r['validation_status'] ?? '';
        $staff  = $r['staff_name'] ?? 'Staff';
        $cust   = $r['customer_name'] ?? 'Customer';
        $svc    = $r['service_type'] ?? 'Service';

        if (in_array(strtolower($val), ['pending validation', 'pending']) || in_array(strtolower($status), ['pending validation', 'pending'])) {
            $generated += upsert_notif($pdo, $user_id, [
                'type'        => 'warning',
                'title'       => "Job Order {$jo_num} Awaiting Validation",
                'message'     => "Job Order {$jo_num} ({$svc}) for {$cust} is awaiting validation.",
                'event_type'  => 'job_order',
                'severity'    => 'high',
                'source_key'  => "jo_validation_{$r['id']}_adm_{$user_id}",
                'redirect_url'=> 'manager_validated_transactions.php?type=job_order',
            ]);
        } elseif (in_array(strtolower($status), ['completed', 'done', 'finished', 'paid', 'released'])) {
            $generated += upsert_notif($pdo, $user_id, [
                'type'        => 'success',
                'title'       => "Job Order {$jo_num} Completed",
                'message'     => "Staff {$staff} completed Job Order {$jo_num} ({$svc}) for {$cust}.",
                'event_type'  => 'job_order',
                'severity'    => 'low',
                'source_key'  => "jo_completed_{$r['id']}_adm_{$user_id}",
                'redirect_url'=> 'manager_validated_transactions.php?type=job_order',
            ]);
        } elseif (in_array(strtolower($status), ['cancelled', 'rejected', 'canceled'])) {
            $generated += upsert_notif($pdo, $user_id, [
                'type'        => 'error',
                'title'       => "Job Order {$jo_num} {$status}",
                'message'     => "Job Order {$jo_num} for {$cust} was {$status}.",
                'event_type'  => 'job_order',
                'severity'    => 'medium',
                'source_key'  => "jo_cancelled_{$r['id']}_adm_{$user_id}",
                'redirect_url'=> 'manager_validated_transactions.php?type=job_order',
            ]);
        }
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 13. FUEL MANAGEMENT — Low Fuel Inventory (<= 20%) & Invalid Meter Readings
// ════════════════════════════════════════════════════════════
try {
    // Purge restored fuel low alerts (> 20% capacity)
    $pdo->prepare("
        DELETE n FROM notifications n
        INNER JOIN fuel_inventory fi ON (
            n.source_key = CONCAT('fuel_low_', fi.id, '_adm_', ?)
            OR n.source_key = CONCAT('mgr_fuel_low_', fi.id)
        )
        WHERE n.user_id = ?
          AND fi.station_id = ?
          AND fi.current_level > (fi.capacity * 0.20)
    ")->execute([$user_id, $user_id, $station_id]);

    $fi_stmt = $pdo->prepare(
        "SELECT fi.id, fi.fuel_type, fi.current_level, fi.capacity
         FROM fuel_inventory fi
         WHERE fi.station_id = ?
           AND fi.current_level >= 0
           AND fi.capacity > 0
           AND fi.current_level <= (fi.capacity * 0.20)
         ORDER BY (fi.current_level / fi.capacity) ASC LIMIT 10"
    );
    $fi_stmt->execute([$station_id]);
    foreach ($fi_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pct  = $r['capacity'] > 0 ? round(($r['current_level'] / $r['capacity']) * 100) : 0;
        $sev  = $pct <= 5 ? 'critical' : ($pct <= 10 ? 'high' : 'medium');
        $type = $pct <= 5 ? 'error' : 'warning';
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => $type,
            'title'       => "Low Fuel Alert: {$r['fuel_type']}",
            'message'     => "{$r['fuel_type']} is at {$pct}% capacity ({$r['current_level']}L remaining). Refill needed.",
            'event_type'  => 'fuel_management',
            'severity'    => $sev,
            'source_key'  => "fuel_low_{$r['id']}_adm_{$user_id}",
            'redirect_url'=> 'admin_inventory_fuel.php',
        ]);
    }

    // Negative variance / invalid meter readings
    $fdr_stmt = $pdo->prepare(
        "SELECT id, fuel_type, pump_id, reading_date,
                (current_reading - previous_reading - liters_sold) AS variance
         FROM fuel_daily_readings
         WHERE station_id = ?
           AND reading_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
           AND (current_reading - previous_reading - liters_sold) < 0
         ORDER BY reading_date DESC LIMIT 10"
    );
    $fdr_stmt->execute([$station_id]);
    foreach ($fdr_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pump     = $r['pump_id'] ?? $r['id'];
        $variance = number_format((float)$r['variance'], 2);
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => 'error',
            'title'       => "Invalid Meter Reading - {$r['fuel_type']} Pump #{$pump}",
            'message'     => "{$r['fuel_type']} Pump #{$pump} has a variance of {$variance}L on {$r['reading_date']}. Verify reading.",
            'event_type'  => 'fuel_management',
            'severity'    => 'high',
            'source_key'  => "fuel_invalid_{$r['id']}_adm_{$user_id}",
            'redirect_url'=> 'manager_fuel_transaction_validation.php',
        ]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 14. STOCK REQUESTS — Pending Review (Merchandise & Fuel)
// ════════════════════════════════════════════════════════════
try {
    $sr_stmt = $pdo->prepare(
        "SELECT sr.id, sr.item_name, sr.status, sr.created_at,
                COALESCE(NULLIF(CONCAT(u.first_name, ' ', u.last_name), ' '), u.name, u.username, 'Staff') AS staff_name
         FROM stock_requests sr
         LEFT JOIN users u ON u.id = sr.staff_id
         WHERE sr.station_id = ?
           AND sr.status IN ('pending','Pending','Pending Manager Review')
           AND sr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY sr.created_at DESC LIMIT 15"
    );
    $sr_stmt->execute([$station_id]);
    foreach ($sr_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $staff = $r['staff_name'] ?? 'Staff';
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => 'warning',
            'title'       => "Stock Request Pending: {$r['item_name']}",
            'message'     => "Staff {$staff} has a pending stock request for {$r['item_name']} (Request #{$r['id']}).",
            'event_type'  => 'stock_request',
            'severity'    => 'medium',
            'source_key'  => "stock_req_{$r['id']}_adm_{$user_id}",
            'redirect_url'=> 'manager_stock_request_review.php?tab=pending_requests',
        ]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 15. CUSTOMER REGISTRATION REQUESTS & CUSTOMERS PENDING VALIDATION
// ════════════════════════════════════════════════════════════
try {
    $cust_reqs = $pdo->prepare(
        "SELECT cr.id, cr.first_name, cr.middle_name, cr.last_name, cr.station_id, cr.requested_by, cr.created_at,
                TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS staff_name,
                u.username AS staff_username
         FROM customer_requests cr
         LEFT JOIN users u ON u.id = cr.requested_by
         WHERE cr.station_id = ?
           AND LOWER(cr.status) = 'pending'
           AND cr.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
         ORDER BY cr.created_at DESC LIMIT 20"
    );
    $cust_reqs->execute([$station_id]);
    $rows = $cust_reqs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $staff = trim($r['staff_name'] ?? '') ?: ($r['staff_username'] ?? 'Staff');
        $cust  = trim(($r['first_name'] ?? '') . ' ' . (!empty($r['middle_name']) ? $r['middle_name'] . ' ' : '') . ($r['last_name'] ?? ''));
        if (!$cust) $cust = 'Customer #' . $r['id'];
        $key   = "cust_req_sub_{$station_id}_{$r['id']}_adm_{$user_id}";
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => 'info',
            'title'       => 'New Customer Registration Request',
            'message'     => "Staff {$staff} submitted a new customer registration request for {$cust}. Review the request for approval.",
            'event_type'  => 'customer_request',
            'severity'    => 'medium',
            'source_key'  => $key,
            'redirect_url'=> 'manager_customers.php?tab=pending&id=' . $r['id'],
        ]);
    }

    // Customers pending validation
    $c_pend = $pdo->prepare(
        "SELECT id, name, status, created_at
         FROM customers
         WHERE station_id = ?
           AND status IN ('pending','Pending','pending_validation','Pending Validation')
           AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY created_at DESC LIMIT 10"
    );
    $c_pend->execute([$station_id]);
    foreach ($c_pend->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cust = $r['name'] ?? ('Customer #' . $r['id']);
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => 'info',
            'title'       => 'Customer Pending Validation',
            'message'     => "Customer {$cust} uploaded ID - pending validation.",
            'event_type'  => 'customer',
            'severity'    => 'low',
            'source_key'  => "customer_pending_{$r['id']}_adm_{$user_id}",
            'redirect_url'=> 'manager_customers.php',
        ]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 16. DELIVERIES — All Status Updates (Manager + Staff parity)
// ════════════════════════════════════════════════════════════
try {
    $del_stmt = $pdo->prepare(
        "SELECT do2.id, do2.status, do2.supplier, do2.delivery_date,
                do2.delivery_type, do2.updated_at
         FROM deliveries_oversight do2
         WHERE do2.station_id = ?
           AND do2.updated_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
         ORDER BY do2.updated_at DESC LIMIT 20"
    );
    $del_stmt->execute([$station_id]);
    foreach ($del_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $status   = $r['status'] ?? '';
        $supplier = $r['supplier'] ?? 'Supplier';
        $dt       = $r['delivery_date'] ? date('M d, Y', strtotime($r['delivery_date'])) : 'TBD';

        if (in_array($status, ['Pending Manager Approval', 'Pending Manager Confirmation', 'Pending Verification'])) {
            $key = 'del_action_' . $r['id'] . '_' . md5($status) . '_adm_' . $user_id;
            $generated += upsert_notif($pdo, $user_id, [
                'type'        => 'warning',
                'title'       => "Delivery #{$r['id']} Awaiting Action",
                'message'     => "Delivery #{$r['id']} from {$supplier} is awaiting action ({$status}).",
                'event_type'  => 'delivery',
                'severity'    => 'high',
                'source_key'  => $key,
                'redirect_url'=> 'manager_stock_in.php',
            ]);
        } elseif (in_array($status, ['Discrepancy', 'Flagged', 'Delayed'])) {
            $key = 'del_issue_' . $r['id'] . '_' . md5($status) . '_adm_' . $user_id;
            $generated += upsert_notif($pdo, $user_id, [
                'type'        => 'error',
                'title'       => "Delivery #{$r['id']} - {$status}",
                'message'     => "Delivery #{$r['id']} from {$supplier} is {$status} - review required.",
                'event_type'  => 'delivery',
                'severity'    => 'high',
                'source_key'  => $key,
                'redirect_url'=> 'manager_stock_in.php',
            ]);
        } elseif (in_array($status, ['Confirmed', 'Validated', 'Stock-In Complete'])) {
            $key = 'delivery_' . $r['id'] . '_' . md5($status) . '_adm_' . $user_id;
            $generated += upsert_notif($pdo, $user_id, [
                'type'        => 'success',
                'title'       => "Delivery #{$r['id']} - {$status}",
                'message'     => "Delivery #{$r['id']} from {$supplier} is now {$status}.",
                'event_type'  => 'delivery',
                'severity'    => 'low',
                'source_key'  => $key,
                'redirect_url'=> 'manager_stock_in.php',
            ]);
        } elseif (in_array($status, ['En Route', 'In Transit', 'Dispatched', 'Expected Delivery'])) {
            $key = 'del_enroute_' . $r['id'] . '_adm_' . $user_id;
            $generated += upsert_notif($pdo, $user_id, [
                'type'        => 'info',
                'title'       => "Delivery #{$r['id']} - {$status}",
                'message'     => "Delivery #{$r['id']} from {$supplier} is {$status}. Expected: {$dt}.",
                'event_type'  => 'delivery',
                'severity'    => 'low',
                'source_key'  => $key,
                'redirect_url'=> 'manager_stock_in.php',
            ]);
        }
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 17. CALENDAR — Today's Schedule Events
// ════════════════════════════════════════════════════════════
try {
    $cal_stmt = $pdo->prepare(
        "SELECT ce.id, ce.event_date, ce.event_time, ce.event_type,
                ce.work_description, ce.status, u.name AS assigned_name
         FROM calendar_events ce
         LEFT JOIN users u ON u.id = ce.staff_assigned
         WHERE ce.event_date = CURDATE()
           AND (ce.station_id = ? OR ce.station_id IS NULL)
         ORDER BY ce.event_time ASC LIMIT 10"
    );
    $cal_stmt->execute([$station_id]);
    foreach ($cal_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $time  = $r['event_time'] ? date('h:i A', strtotime($r['event_time'])) : 'All Day';
        $title = $r['work_description'] ?: ucfirst($r['event_type'] ?? 'Event');
        $who   = $r['assigned_name'] ? " - {$r['assigned_name']}" : '';
        $key   = 'cal_today_' . $r['id'] . '_' . date('Ymd') . '_adm_' . $user_id;
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => 'info',
            'title'       => "Schedule: {$title}",
            'message'     => "Schedule entry: {$title}{$who} at {$time} today.",
            'event_type'  => 'calendar',
            'severity'    => 'low',
            'source_key'  => $key,
            'redirect_url'=> 'admin_calendar.php',
        ]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 18. REPORTS — Daily/Weekly/Monthly Reports Ready
// ════════════════════════════════════════════════════════════
try {
    $rpt_stmt = $pdo->prepare(
        "SELECT al.id, al.action, al.details, al.created_at
         FROM activity_logs al
         WHERE (al.station_id = ? OR al.station_id IS NULL)
           AND (al.action LIKE '%Weekly%Report%'
             OR al.action LIKE '%Monthly%Report%'
             OR al.action LIKE '%Report%Generated%'
             OR al.action LIKE '%report_generated%'
             OR al.action LIKE '%Sales%Report%'
             OR al.action LIKE '%Daily%Summary%'
             OR al.details LIKE '%report%ready%'
             OR al.details LIKE '%daily summary%')
           AND al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY al.created_at DESC LIMIT 10"
    );
    $rpt_stmt->execute([$station_id]);
    foreach ($rpt_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ts  = date('M d, Y h:i A', strtotime($r['created_at']));
        $key = 'report_' . $r['id'] . '_adm_' . $user_id;
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => 'success',
            'title'       => $r['action'] ?: 'Operational Report Ready',
            'message'     => mb_strimwidth($r['details'] ?? $r['action'], 0, 120, '...') . " - ready at {$ts}.",
            'event_type'  => 'report',
            'severity'    => 'low',
            'source_key'  => $key,
            'redirect_url'=> 'admin_reports.php',
        ]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 19. SYNC ALL STATION MANAGER & STAFF NOTIFICATIONS TO ADMIN
// ════════════════════════════════════════════════════════════
if (function_exists('sync_station_notifications_to_admin')) {
    $generated += sync_station_notifications_to_admin($pdo, $user_id, $station_id);
}

// ── Cleanup stale stock request notifications ─────────────────
try {
    $pdo->prepare(
        "DELETE n FROM notifications n
         INNER JOIN stock_requests sr
             ON (n.source_key = CONCAT('stock_req_', sr.id, '_adm_', ?) OR n.source_key = CONCAT('mgr_stock_req_', sr.id, '_adm_', ?))
         WHERE n.user_id = ?
           AND sr.status NOT IN ('Pending', 'pending', 'Pending Manager Review')"
    )->execute([$user_id, $user_id, $user_id]);
} catch (Exception $e) {}

// ── Cleanup old read notifications (> 14 days) ─────────────────
try {
    $stmt = $pdo->prepare(
        "DELETE FROM notifications
         WHERE user_id = ? AND status = 'read'
           AND created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"
    );
    $stmt->execute([$user_id]);
} catch (Exception $e) {}

echo json_encode(['ok' => true, 'generated' => $generated]);

