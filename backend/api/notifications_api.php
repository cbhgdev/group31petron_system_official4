<?php
/**
 * Notifications API
 * backend/api/notifications_api.php
 *
 * Serves all roles: staff, manager, admin, superadmin, developer.
 * Each user only sees their own notifications (filtered by user_id).
 *
 * Actions (GET):
 *   ?action=list          — paginated list of notifications
 *   ?action=unread_count  — count of actionable sidebar badge items (mirrors header bell)
 *
 * Actions (POST):
 *   ?action=mark_read     — mark one notification as read (POST: notification_id)
 *   ?action=mark_all_read — mark all as read for this user
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth ──────────────────────────────────────────────────────
require_login();
$me      = current_user();
$role    = role_key($me['role'] ?? '');
$user_id = (int)($me['id'] ?? 0);

$allowed = ['staff', 'manager', 'admin', 'superadmin', 'developer'];
if (!in_array($role, $allowed) || $user_id === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// ── Ensure notifications table exists ────────────────────────
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
} catch (Throwable $e) {}

/**
 * Returns the bell unread count from the notifications table,
 * respecting the per-user 5-minute snooze set by mark_all_read.
 * The snooze expires automatically — no DB writes needed.
 */
function get_bell_count(PDO $pdo, int $user_id): int {
    // No snooze: always return the real unread count so new notifications
    // are immediately visible after "Mark All Read".
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'");
        $s->execute([$user_id]);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

if (!function_exists('time_ago')) {
function time_ago(string $datetime): string {
    $diff = max(0, time() - strtotime($datetime));
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}
}

/**
 * Category breakdown helper for sidebar drawer badges
 */
if (!function_exists('get_category_unread_counts')) {
function get_category_unread_counts(PDO $pdo, int $user_id, string $role = '', int $station_id = 0): array {
    $counts = [
        'transactions'  => 0,
        'fuel'          => 0,
        'inventory'     => 0,
        'customers'     => 0,
        'prod_pricing'  => 0,
        'reports'       => 0,
        'notifications' => 0
    ];

    $safe_count = function(string $sql, array $params = []) use ($pdo) {
        try {
            $s = $pdo->prepare($sql);
            $s->execute($params);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) { return 0; }
    };

    $counts['notifications'] = $safe_count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'", [$user_id]);

    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        // SERVICE STAFF
        $counts['transactions'] = $safe_count(
            "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND (created_by = ? OR user_id = ? OR assigned_mechanic_id = ?) AND LOWER(COALESCE(status,'')) IN ('pending','reviewed','in progress','awaiting parts')",
            [$station_id, $user_id, $user_id, $user_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND staff_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation','pending_validation')",
            [$station_id, $user_id]
        );

        $counts['fuel'] = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND staff_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','pending_validation')",
            [$station_id, $user_id]
        );

        $counts['inventory'] = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)",
            [$station_id]
        );

    } elseif (in_array($role, ['manager', 'supervisor'])) {
        // MANAGER
        $counts['transactions'] = $safe_count(
            "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','reviewed')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
            [$station_id]
        );

        $counts['fuel'] = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(COALESCE(adjustment_type,'')) LIKE '%calibration%' AND LOWER(COALESCE(status,'')) IN ('pending','pending review')",
            [$station_id]
        );

        $counts['inventory'] = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status IN ('Pending','Pending Manager Review')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM purchase_orders WHERE station_id = ? AND status IN ('Approved','Pending Stock-In')",
            [$station_id]
        );

        $counts['customers']     = $safe_count(
            "SELECT COUNT(*) FROM customer_requests WHERE station_id = ? AND LOWER(status) = 'pending'",
            [$station_id]
        );
        $counts['mgr_customers'] = $counts['customers'];

    } elseif (in_array($role, ['admin'])) {
        // ADMIN — strictly scope to station_id
        $stn_where  = "si.station_id = ? AND ";
        $stn_param  = [$station_id];
        $stn_where2 = "station_id = ? AND ";
        $stn_param2 = [$station_id];

        $admin_crit_stock = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE {$stn_where}(LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(NULLIF(si.critical_level, 0), NULLIF(ip.critical_level, 0), NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)",
            $stn_param
        );
        $admin_pos = $safe_count(
            "SELECT COUNT(*) FROM purchase_orders WHERE {$stn_where2}status IN ('Pending Admin Review', 'Submitted', 'Pending Approval')",
            $stn_param2
        );
        $admin_inv_total = $admin_crit_stock + $admin_pos;
        $counts['inventory']       = $admin_inv_total;
        $counts['admin_inventory'] = $admin_inv_total;

        $admin_price_change = $safe_count(
            "SELECT COUNT(*) FROM pending_price_approvals WHERE {$stn_where2}status = 'pending'",
            $stn_param2
        );
        $counts['prod_pricing']          = $admin_price_change;
        $counts['mgr_product_pricing']   = $admin_price_change;
        $counts['admin_product_pricing'] = $admin_price_change;

        // Reports badge = this user's own unread critical/error notifications only
        $admin_system_alerts = $safe_count(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND severity IN ('critical','error') AND status = 'unread'",
            [$user_id]
        );
        $counts['reports']       = $admin_system_alerts;
        $counts['admin_reports'] = $admin_system_alerts;

        // Fuel Management Oversight: Pending Fuel Transactions requiring admin attention
        $admin_fuel_pending = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE {$stn_where2}LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
            $stn_param2
        );
        $counts['fuel']                  = $admin_fuel_pending;
        $counts['admin_fuel']            = $admin_fuel_pending;
        $counts['admin_fuel_management'] = $admin_fuel_pending;

    } elseif (in_array($role, ['superadmin', 'developer'])) {
        // SUPERADMIN — only own system/security alert count, no operational station badges
        $sa_unread = $safe_count(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'",
            [$user_id]
        );
        $counts['notifications'] = $sa_unread;
        // All other badge counts remain 0 for superadmin — they don't manage station operations
    }

    // Respect user_preferences badge_seen timestamps so visited sections stay cleared
    try {
        $st_pref = $pdo->prepare("SELECT preference_key, preference_value FROM user_preferences WHERE user_id = ? AND preference_key LIKE 'badge_seen_%'");
        $st_pref->execute([$user_id]);
        foreach ($st_pref->fetchAll(PDO::FETCH_ASSOC) as $r_seen) {
            $k_seen = str_replace('badge_seen_', '', $r_seen['preference_key']);
            $seen_ts = strtotime($r_seen['preference_value']);
            if ($seen_ts && (time() - $seen_ts < 7200)) {
                if (isset($counts[$k_seen])) $counts[$k_seen] = 0;
            }
        }
    } catch (Throwable $e) {}

    return $counts;
}
}

// ── Route ─────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET') {
        switch ($action) {

            // ── List notifications ────────────────────────────
            case 'list':
                $status    = $_GET['status'] ?? 'all';
                $category  = strtolower(trim($_GET['category'] ?? 'all'));
                $type_f    = $_GET['type'] ?? 'all';
                $sev_f     = $_GET['severity'] ?? 'all';
                $search    = trim($_GET['search'] ?? '');
                $date_from = trim($_GET['date_from'] ?? '');
                $date_to   = trim($_GET['date_to'] ?? '');
                $shift_req = trim($_GET['shift'] ?? 'all');
                $limit     = min((int)($_GET['limit'] ?? 20), 500);
                $offset    = (int)($_GET['offset'] ?? 0);

                $where  = 'WHERE n.user_id = ?';
                $params = [$user_id];

                // Shift filtering — only apply when explicitly requested (e.g. from a filter dropdown)
                if ($shift_req !== 'all' && $shift_req !== '') {
                    $where   .= ' AND (n.shift_period = ? OR n.shift_period IS NULL OR n.shift_period = "")';
                    $params[] = $shift_req;
                }

                // Category filter mapping
                if ($category !== 'all' && $category !== '') {
                    if ($category === 'fuel') {
                        $where .= " AND (n.event_type IN ('fuel_transaction','fuel_sales_closing','fuel_reading','fuel','fuel_stock_in') OR n.title LIKE '%Fuel%')";
                    } elseif ($category === 'inventory') {
                        $where .= " AND (n.event_type IN ('stock_request','purchase_order','inventory','delivery','stock_in','merchandise_stock_in','fuel_stock_in') OR n.title LIKE '%Stock%' OR n.title LIKE '%Inventory%')";
                    } elseif ($category === 'transactions') {
                        $where .= " AND (n.event_type IN ('void_request','transaction_adjustment','transaction','merchandise_transaction','job_order') OR n.title LIKE '%Void%' OR n.title LIKE '%Adjustment%' OR n.title LIKE '%Transaction%')";
                    } elseif ($category === 'approvals') {
                        $where .= " AND (n.event_type IN ('stock_request','void_request','master_data_request','fuel_transaction','fuel_reading','stock_in','merchandise_stock_in','fuel_stock_in','transaction_adjustment') OR n.title LIKE '%Approved%' OR n.title LIKE '%Validated%' OR n.title LIKE '%Pending%' OR n.title LIKE '%Review%' OR n.title LIKE '%Returned%' OR n.title LIKE '%Adjusted%' OR n.title LIKE '%Rejected%')";
                    } elseif ($category === 'master_data') {
                        $where .= " AND (n.event_type IN ('master_data_request','customer_request') OR n.title LIKE '%Master Data%')";
                    } elseif ($category === 'system') {
                        $where .= " AND (n.event_type IN ('system','system_error','security','account_lockout','unauthorized_access') OR n.title LIKE '%System%' OR n.title LIKE '%Security%')";
                    }
                }

                if ($status !== 'all' && $status !== '') {
                    $where   .= ' AND n.status = ?';
                    $params[] = $status;
                }
                if ($type_f !== 'all' && $type_f !== '') {
                    $where   .= ' AND (n.event_type = ? OR n.type = ?)';
                    $params[] = $type_f;
                    $params[] = $type_f;
                }
                if ($sev_f !== 'all' && $sev_f !== '') {
                    $where   .= ' AND n.severity = ?';
                    $params[] = $sev_f;
                }
                if ($search !== '') {
                    $where   .= ' AND (n.title LIKE ? OR n.message LIKE ?)';
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                }
                if ($date_from !== '') {
                    $where   .= ' AND DATE(n.created_at) >= ?';
                    $params[] = $date_from;
                }
                if ($date_to !== '') {
                    $where   .= ' AND DATE(n.created_at) <= ?';
                    $params[] = $date_to;
                }

                // Auto-clear stale inventory alerts if stock levels are currently OK
                try {
                    $pdo->prepare("
                        DELETE n FROM notifications n
                        INNER JOIN station_inventory si ON (n.source_key LIKE CONCAT('mgr_inv_low_', si.product_id, '%') OR n.title LIKE '%Inventory Alert%' OR n.title LIKE '%Low Stock%')
                        INNER JOIN inventory_products ip ON ip.id = si.product_id
                        WHERE n.user_id = ?
                          AND si.stock_level > COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)
                          AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels')
                    ")->execute([$user_id]);

                    $pdo->prepare("
                        DELETE n FROM notifications n
                        INNER JOIN fuel_inventory fi ON (n.source_key LIKE CONCAT('mgr_fuel_low_', fi.id, '%') OR n.title LIKE '%Low Fuel%')
                        WHERE n.user_id = ?
                          AND fi.current_level > COALESCE(NULLIF(fi.reorder_level, 0), fi.capacity * 0.15, 500)
                    ")->execute([$user_id]);
                } catch (Exception $e) {}

                $stmt = $pdo->prepare(
                    "SELECT n.id, n.type, n.title, n.message, n.event_type,
                            n.severity, n.redirect_url, n.reference_type, n.reference_id,
                            n.shift_period, n.status, n.created_at
                     FROM notifications n
                     {$where}
                     ORDER BY n.created_at DESC
                     LIMIT {$limit} OFFSET {$offset}"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as &$n) {
                    $n['title'] = function_exists('clean_mojibake') ? clean_mojibake((string)($n['title'] ?? '')) : (string)($n['title'] ?? '');
                    $n['message'] = function_exists('clean_mojibake') ? clean_mojibake((string)($n['message'] ?? '')) : (string)($n['message'] ?? '');
                    $n['time_ago'] = time_ago($n['created_at']);
                    // Dynamic sanitization & remapping for fuel adjustment notifications
                    $is_fuel_adjustment = (
                        stripos($n['title'], 'adjustment') !== false ||
                        stripos($n['title'], 'tank dip') !== false ||
                        stripos($n['message'], 'fuel reading adjustment') !== false ||
                        stripos($n['message'], 'tank dip') !== false ||
                        stripos($n['message'], 'physical dip') !== false ||
                        in_array($n['event_type'], ['fuel_adjustment', 'fuel_adjustments', 'tank_dip', 'dip_adjustment'])
                    );

                    if ($is_fuel_adjustment) {
                        if (in_array($role, ['admin', 'superadmin', 'developer'])) {
                            $n['redirect_url'] = 'admin_fuel_adjustments_oversight.php';
                        } elseif (in_array($role, ['manager', 'supervisor'])) {
                            $n['redirect_url'] = 'manager_fuel_deliveries.php';
                        } else {
                            $n['redirect_url'] = 'staff_inventory_fuel.php';
                        }
                    } elseif (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
                        $is_fuel = (stripos($n['title'], 'fuel') !== false || stripos($n['message'], 'fuel') !== false || in_array($n['event_type'], ['fuel','fuel_stock_in','fuel_delivery','fuel_management','fuel_reading']));
                        $raw_red = (string)($n['redirect_url'] ?? '');
                        if (stripos($raw_red, 'staff_fuel_deliveries.php') !== false || stripos($raw_red, 'staff_record_delivery.php') !== false) {
                            $n['redirect_url'] = $is_fuel ? 'staff_inventory_fuel.php' : 'staff_inventory_merchandise.php';
                        } elseif (in_array($n['event_type'], ['stock_in', 'merchandise_stock_in', 'fuel_stock_in', 'delivery', 'purchase_order'])) {
                            if (empty($raw_red) || $raw_red === '#' || stripos($raw_red, 'record_delivery') !== false || stripos($raw_red, 'admin_stock_confirmation') !== false) {
                                $n['redirect_url'] = $is_fuel ? 'staff_inventory_fuel.php' : 'staff_inventory_merchandise.php';
                            }
                        }
                    }

                    if (empty($n['redirect_url']) || $n['redirect_url'] === '#' || $n['redirect_url'] === 'null') {
                        if (!empty($n['reference_type'])) {
                            $n['redirect_url'] = notification_redirect_url(
                                $n['reference_type'],
                                (int)($n['reference_id'] ?? 0),
                                $role
                            );
                        } elseif (strpos($n['title'], 'Fuel Reading') !== false || strpos($n['title'], 'Fuel Transaction') !== false) {
                            $n['redirect_url'] = ($role === 'manager' || $role === 'admin') ? 'manager_fuel_transaction_validation.php' : 'staff_transactions_hub.php?section=fuel&fuel_tab=readings';
                        }
                    }
                }
                unset($n);

                // Category breakdown for sidebar badges (shared source)
                $myStationId = (int)($me['station_id'] ?? 0);
                $cat_counts  = get_category_unread_counts($pdo, $user_id, $role, $myStationId);

                // Bell count = actual unread rows in notifications table (with snooze support)
                $bell_unread_count = get_bell_count($pdo, $user_id);

                // Overall Stats breakdown for cards
                $stats_stmt = $pdo->prepare(
                    "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END) as unread,
                        SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_count,
                        SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived
                     FROM notifications WHERE user_id = ?"
                );
                $stats_stmt->execute([$user_id]);
                $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0, 'unread'=>0, 'read_count'=>0, 'archived'=>0];

                echo json_encode([
                    'success'           => true,
                    'notifications'     => $rows,
                    'unread_count'      => $bell_unread_count,
                    'bell_unread_count' => $bell_unread_count,
                    'total'             => (int)($stats['total'] ?? count($rows)),
                    'stats'             => [
                        'total'    => (int)($stats['total']      ?? 0),
                        'unread'   => (int)($stats['unread']     ?? 0),
                        'read'     => (int)($stats['read_count'] ?? 0),
                        'archived' => (int)($stats['archived']   ?? 0),
                    ],
                    'category_counts'   => $cat_counts,
                ]);
                break;

            // ── Unread count (sidebar-equivalent action badge) ─
            case 'mark_badge_seen':
                $key = trim($_GET['key'] ?? $_POST['key'] ?? '');
                if (!empty($key)) {
                    try {
                        $pref_key = 'badge_seen_' . $key;
                        $now = date('Y-m-d H:i:s');
                        $stmt = $pdo->prepare(
                            "INSERT INTO user_preferences (user_id, preference_key, preference_value, updated_at)
                             VALUES (?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value), updated_at = NOW()"
                        );
                        $stmt->execute([$user_id, $pref_key, $now]);
                    } catch (Throwable $e) {}
                }
                echo json_encode(['success' => true]);
                exit;

            case 'mark_all_read':
                $stmt = $pdo->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE user_id = ? AND status = 'unread'");
                $stmt->execute([$user_id]);
                $marked_count = $stmt->rowCount();
                unset($_SESSION['notif_bell_snoozed_' . $user_id]);
                $tot_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
                $tot_stmt->execute([$user_id]);
                $total_count = (int)$tot_stmt->fetchColumn();
                $myStationId = (int)($me['station_id'] ?? 0);
                $cat_counts_mar = get_category_unread_counts($pdo, $user_id, $role, $myStationId);
                $cat_counts_mar['notifications'] = 0;
                echo json_encode([
                    'success'           => true,
                    'marked_count'      => $marked_count,
                    'unread_count'      => 0,
                    'bell_unread_count' => 0,
                    'total_count'       => $total_count,
                    'category_counts'   => $cat_counts_mar,
                ]);
                exit;

            case 'unread_count':
                $myStationId  = (int)(user_station_id() ?? 0);
                if (!in_array($role, ['superadmin', 'developer'])) {
                    $station_param = [$myStationId];
                    $station_where = "station_id = ? AND ";
                } else {
                    $station_param = $myStationId ? [$myStationId] : [];
                    $station_where = $myStationId ? "station_id = ? AND " : "";
                }

                // Helper for safe count
                $safe_count = function(string $sql, array $params = []) use ($pdo) {
                    try {
                        $s = $pdo->prepare($sql);
                        $s->execute($params);
                        return (int)$s->fetchColumn();
                    } catch (Throwable $e) { return 0; }
                };

                $action_count = 0;

                if (in_array($role, ['admin', 'superadmin', 'developer'])) {
                    // Pending merch transactions
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM merchandise_transactions WHERE {$station_where}LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation','pending_validation')",
                        $station_param
                    );
                    // Pending fuel transactions
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_transactions WHERE {$station_where}LOWER(COALESCE(status,'')) IN ('pending','pending validation','pending_validation')",
                        $station_param
                    );
                    // Pending job orders
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM job_orders WHERE {$station_where}LOWER(COALESCE(validation_status,status,'')) IN ('pending','pending validation','pending_validation')",
                        $station_param
                    );
                    // Pending merchandise POs
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM purchase_orders WHERE {$station_where}status IN ('Pending','Pending Approval','Pending Admin Validation','Submitted') AND COALESCE(admin_finalized,0)=0",
                        $station_param
                    );
                    // Pending fuel POs
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_purchase_orders WHERE {$station_where}status IN ('Pending','Pending Approval','Pending Admin Validation','Submitted')",
                        $station_param
                    );
                    // Low merch stock
                    $low_merch_params = $myStationId ? [$myStationId] : [];
                    $low_merch_where  = $myStationId ? "si.station_id=? AND " : "";
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM station_inventory si INNER JOIN inventory_products ip ON ip.id=si.product_id WHERE {$low_merch_where}COALESCE(si.stock_level,0) <= COALESCE(si.reorder_level, ip.min_stock, 24) AND LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels')",
                        $low_merch_params
                    );
                    // Pending customers (from staff requests)
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM customer_requests WHERE {$station_where}LOWER(status) = 'pending'",
                        $station_param
                    );
                    // Pending price approvals
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM pending_price_approvals WHERE {$station_where}status='pending'",
                        $station_param
                    );
                    // Awaiting stock-in
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM purchase_orders WHERE {$station_where}admin_finalized=1 AND (COALESCE(stock_in_done,0)=0 OR status IN ('Approved','Approved PO','Admin Finalized'))",
                        $station_param
                    );
                    // Fuel adjustments pending
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_adjustments WHERE {$station_where}(LOWER(COALESCE(status,''))='pending' OR status IS NULL OR status='')",
                        $station_param
                    );

                } elseif ($role === 'manager') {
                    // Pending merch stock requests
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM stock_requests WHERE {$station_where}status IN ('Pending', 'Pending Manager Review')",
                        $station_param
                    );
                    // Pending fuel stock requests
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_stock_requests WHERE {$station_where}status IN ('Pending', 'Pending Manager Review')",
                        $station_param
                    );
                    // Pending deliveries
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM deliveries_oversight WHERE {$station_where}status IN ('Pending','Ordered','Expected Delivery')",
                        $station_param
                    );
                    // Low inventory
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM inventory WHERE {$station_where}stock_level <= 20",
                        $station_param
                    );
                    // Pending customers (from staff requests)
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM customer_requests WHERE {$station_where}LOWER(status) = 'pending'",
                        $station_param
                    );

                } elseif ($role === 'staff') {
                    // Own active job orders
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM job_orders WHERE user_id = ? AND status IN ('Pending','In Progress','Awaiting Parts')",
                        [$user_id]
                    );
                    // Pending stock-in
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM deliveries_oversight WHERE {$station_where}status='Ready for Stock-In'",
                        $station_param
                    );
                    // Own pending stock requests (merchandise + fuel)
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM stock_requests WHERE staff_id=? AND status IN ('Pending', 'Pending Manager Review')",
                        [$user_id]
                    );
                    $action_count += $safe_count(
                        "SELECT COUNT(*) FROM fuel_stock_requests WHERE staff_id=? AND status IN ('Pending', 'Pending Manager Review')",
                        [$user_id]
                    );
                }

                // Compute category counts (shared source for sidebar badges only)
                $cat_counts = get_category_unread_counts($pdo, $user_id, $role, $myStationId);

                // Bell count = actual unread rows in the notifications table for this user.
                // This keeps the bell in sync with the dropdown list — sidebar badges are separate.
                try {
                    $bell_stmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'"
                    );
                    $bell_stmt->execute([$user_id]);
                    $bell_unread_count = (int)$bell_stmt->fetchColumn();

                    $tot_stmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM notifications WHERE user_id = ?"
                    );
                    $tot_stmt->execute([$user_id]);
                    $total_count = (int)$tot_stmt->fetchColumn();
                } catch (Throwable $_e) {
                    $bell_unread_count = 0;
                    $total_count = 0;
                }

                echo json_encode([
                    'success'           => true,
                    'unread_count'      => $bell_unread_count,  // actual notification rows unread
                    'bell_unread_count' => $bell_unread_count,  // header bell = dropdown count
                    'total_count'       => $total_count,        // total notifications count
                    'category_counts'   => $cat_counts,         // per-section sidebar badges
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }

    } elseif ($method === 'POST') {
        switch ($action) {

            // ── Mark one as read ──────────────────────────────
            case 'mark_read':
                $notif_id = (int)($_POST['notification_id'] ?? 0);
                if ($notif_id > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE notifications
                         SET status = 'read', read_at = NOW()
                         WHERE id = ? AND user_id = ? AND status = 'unread'"
                    );
                    $stmt->execute([$notif_id, $user_id]);
                }
                $myStationId = (int)($me['station_id'] ?? 0);
                $cat_counts = get_category_unread_counts($pdo, $user_id, $role, $myStationId);
                $bell_unread_count = get_bell_count($pdo, $user_id);

                $tot_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
                $tot_stmt->execute([$user_id]);
                $total_count = (int)$tot_stmt->fetchColumn();

                echo json_encode([
                    'success'           => true,
                    'unread_count'      => $bell_unread_count,
                    'bell_unread_count' => $bell_unread_count,
                    'total_count'       => $total_count,
                    'category_counts'   => $cat_counts,
                ]);
                break;

            // ── Archive one notification ──────────────────────
            case 'archive':
                $notif_id = (int)($_POST['notification_id'] ?? 0);
                if ($notif_id > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE notifications
                         SET status = 'archived'
                         WHERE id = ? AND user_id = ?"
                    );
                    $stmt->execute([$notif_id, $user_id]);
                }
                $myStationId = (int)($me['station_id'] ?? 0);
                $cat_counts = get_category_unread_counts($pdo, $user_id, $role, $myStationId);
                $bell_unread_count = get_bell_count($pdo, $user_id);

                $tot_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
                $tot_stmt->execute([$user_id]);
                $total_count = (int)$tot_stmt->fetchColumn();

                echo json_encode([
                    'success'           => true,
                    'unread_count'      => $bell_unread_count,
                    'bell_unread_count' => $bell_unread_count,
                    'total_count'       => $total_count,
                    'category_counts'   => $cat_counts,
                ]);
                break;

            // ── Mark all as read ──────────────────────────────
            case 'mark_all_read':
                // Mark all notification rows as read
                $stmt = $pdo->prepare(
                    "UPDATE notifications
                     SET status = 'read', read_at = NOW()
                     WHERE user_id = ? AND status = 'unread'"
                );
                $stmt->execute([$user_id]);
                $marked_count = $stmt->rowCount();
                unset($_SESSION['notif_bell_snoozed_' . $user_id]);

                $tot_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
                $tot_stmt->execute([$user_id]);
                $total_count = (int)$tot_stmt->fetchColumn();

                $myStationId = (int)($me['station_id'] ?? 0);
                $cat_counts_mar = get_category_unread_counts($pdo, $user_id, $role, $myStationId);
                $cat_counts_mar['notifications'] = 0;

                echo json_encode([
                    'success'           => true,
                    'marked_count'      => $marked_count,
                    'unread_count'      => 0,
                    'bell_unread_count' => 0,
                    'total_count'       => $total_count,
                    'category_counts'   => $cat_counts_mar,
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }

    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log('notifications_api.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
