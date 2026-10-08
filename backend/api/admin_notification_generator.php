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

// Keep older unread notifications aligned with current module routes.
try {
    $redirect_fixes = [
        'admin_transactions_oversight.php' => 'manager_validated_transactions.php',
        'purchase_orders.php'             => 'admin_procurement_reports.php?section=po',
        'inventory.php'                   => 'admin_inventory_merchandise.php',
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
 * - If source_key exists but status='read' AND message changed -> re-insert as unread
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
        'event_type'  => 'fuel_transaction',
        'severity'    => 'high',
        'source_key'  => "fuel_adj_pending_{$station_id}",
        'redirect_url'=> 'admin_fuel_transactions_oversight.php',
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
        'redirect_url'=> 'admin_deliveries_oversight.php',
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
        'redirect_url'=> 'admin_deliveries_oversight.php',
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
        'event_type'  => 'delivery',
        'severity'    => 'medium',
        'source_key'  => "pending_po_{$station_id}",
        'redirect_url'=> 'admin_procurement_reports.php?section=po',
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
//    Check multiple sources — only fire if truly NO staff
//    activity at this station today.
// ════════════════════════════════════════════════════════════

// Source 1: labor_sessions (traditional shift clock-in)
$active_shifts = adm_count($pdo,
    "SELECT COUNT(*) FROM labor_sessions WHERE station_id=? AND DATE(start_time)=CURDATE()",
    [$station_id]);

// Source 2: staff activity or logins in audit_logs today
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

// Source 3: staff actively logged in today (user_sessions)
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

// Source 4: any fuel or merchandise transactions today (proof staff are working)
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
    // If staff are active today, remove any stale "No Active Shifts Today" notifications
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
            'redirect_url'=> 'admin_audit_trail.php',
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
            'redirect_url'=> 'admin_audit_trail.php',
        ]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 10. LOW INVENTORY
// ════════════════════════════════════════════════════════════
try {
    // Auto-purge low inventory notifications if stock levels have been restored
    $pdo->prepare("
        DELETE n FROM notifications n
        INNER JOIN station_inventory si ON (n.source_key = CONCAT('low_inv_', si.station_id) OR n.source_key LIKE CONCAT('low_inv_', si.station_id, '%'))
        INNER JOIN inventory_products ip ON ip.id = si.product_id
        WHERE n.user_id = ?
          AND si.station_id = ?
          AND si.stock_level > COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)
          AND LOWER(COALESCE(ip.category, '')) NOT IN ('fuel', 'fuels')
    ")->execute([$user_id, $station_id]);

    $low_inv = $pdo->prepare(
        "SELECT COUNT(*) FROM station_inventory si
         INNER JOIN inventory_products ip ON ip.id = si.product_id
         WHERE si.stock_level <= COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10) AND si.station_id = ?
           AND si.stock_level >= 0
           AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 10)
           AND LOWER(ip.category) NOT IN ('fuel', 'fuels')"
    );
    $low_inv->execute([$station_id]);
    $low_cnt = (int)$low_inv->fetchColumn();
    if ($low_cnt > 0) {
        $generated += upsert_notif($pdo, $user_id, [
            'type'        => 'warning',
            'title'       => 'Low Inventory Alert',
            'message'     => "{$low_cnt} product(s) at or below minimum stock level. Reorder required.",
            'event_type'  => 'inventory',
            'severity'    => 'high',
            'source_key'  => "low_inv_{$station_id}",
            'redirect_url'=> 'admin_inventory_merchandise.php',
        ]);
    }
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════════
// 11. CUSTOMER REGISTRATION REQUESTS — pending approval
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
        $key   = "cust_req_sub_{$station_id}_{$r['id']}_a{$user_id}";
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
