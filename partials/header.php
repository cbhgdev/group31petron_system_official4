<?php
// Force fresh reload - prevent CSS/HTML caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Content-Type: text/html; charset=UTF-8');

// Set PHP internal encoding to UTF-8 for proper multi-byte character handling
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
$user = $_SESSION['user'];

// ── Load session_timeout from system_settings for client-side idle tracker ──
$header_session_timeout_seconds = 1800; // fallback 30 minutes
try {
    $hst_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout' AND station_id = 0 LIMIT 1");
    $hst_stmt->execute();
    $hst_val = $hst_stmt->fetchColumn();
    if ($hst_val !== false && is_numeric($hst_val) && (int)$hst_val > 0) {
        $header_session_timeout_seconds = max(1, (int)$hst_val) * 60; // convert minutes → seconds
    }
} catch (Exception $e) {
    // keep fallback
}

// Get current page ID from filename (only if not already set by the calling page)
if (!isset($page_id)) {
    $page_id = basename($_SERVER['PHP_SELF'], '.php');
}
// Normalize Role to ensure sidebar works correctly regardless of DB casing / naming
// Supports: manager/supervisor, staff, etc.
$role = function_exists('role_key') ? role_key($user['role'] ?? '') : strtolower(trim($user['role'] ?? 'staff'));

$script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$public_pos = strpos($script_name, '/public/');
if ($public_pos !== false) {
    $app_base_path = substr($script_name, 0, $public_pos);
    $public_base_url = $app_base_path . '/public';
} elseif (strpos($script_name, '/public') === 0 && (strlen($script_name) === 7 || $script_name[7] === '/')) {
    $app_base_path = '';
    $public_base_url = '/public';
} else {
    // If the script is being accessed directly without /public in the URL (e.g. Hostinger docroot pointed directly to public)
    $dir = rtrim(dirname($script_name), '/\\');
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        $dir = '';
    }
    $app_base_path = $dir;
    $public_base_url = $dir;
}

if ($app_base_path === '.' || $app_base_path === '/') {
    $app_base_path = '';
}

$public_base_url = ($public_base_url !== '') ? rtrim($public_base_url, '/') : '';
$myStationId = user_station_id();

$header_notifications = [];
$header_unread_count = 0;
$header_time_ago = function($datetime) {
    $ts = strtotime((string)$datetime);
    if (!$ts) return '';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
};
$header_notif_url = function($url) use ($app_base_path, $public_base_url) {
    $url = trim((string)$url);
    if ($url === '' || $url === '#') return '#';
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    if (strpos($url, '/public/') === 0) return $app_base_path . $url;
    if (strpos($url, 'public/') === 0) return $app_base_path . '/' . $url;
    if (preg_match('/^[a-zA-Z0-9_-]+\.php/', $url)) return $public_base_url . '/' . $url;
    return $url;
};
if (in_array($role, ['staff','admin','manager','superadmin','developer'])) {
    try {
        if (function_exists('ensure_notifications_table')) {
            ensure_notifications_table($pdo);
        }
        $hn_stmt = $pdo->prepare(
            "SELECT id, type, title, message, event_type, severity, redirect_url, status, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 15"
        );
        $hn_stmt->execute([(int)($user['id'] ?? 0)]);
        $header_notifications = $hn_stmt->fetchAll(PDO::FETCH_ASSOC);

        $hc_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'");
        $hc_stmt->execute([(int)($user['id'] ?? 0)]);
        $header_unread_count = (int)$hc_stmt->fetchColumn();
        
        // Respect session snooze so badge does not flicker on refresh after mark_all_read
        $sn_key = 'notif_bell_snoozed_' . (int)($user['id'] ?? 0);
        if (!empty($_SESSION[$sn_key])) {
            if (time() - (int)$_SESSION[$sn_key] < 300) {
                $header_unread_count = 0;
            } else {
                unset($_SESSION[$sn_key]);
            }
        }
    } catch (Exception $e) {
        $header_notifications = [];
        $header_unread_count = 0;
    }
}

// --- FETCH ALERTS FOR DROPDOWN ---
$header_alerts = [];
if(in_array($role, ['superadmin','admin','manager'])){
    // 1. Failed Logins (Super Admin only)
    if($role === 'superadmin'){
        try {
            $failed_stmt = $pdo->prepare("SELECT user_id, details, created_at FROM activity_logs WHERE action = 'Login Failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND (user_id IS NULL OR user_id <> ?) ORDER BY created_at DESC LIMIT 5");
            $failed_stmt->execute([(int)($user['id'] ?? 0)]);
            $failed_logins = $failed_stmt->fetchAll();
            foreach($failed_logins as $fl) {
                $details = (string)($fl['details'] ?? '');
                $searchUser = '';
                if (preg_match('/username\s*:\s*([^\s]+)/i', $details, $matches)) {
                    $searchUser = trim($matches[1]);
                }

                $failedLink = $public_base_url . '/audit_logs.php?date=today&event=' . urlencode('Login Failed');
                if ($searchUser !== '') {
                    $failedLink .= '&q=' . urlencode($searchUser);
                }

                $header_alerts[] = [
                    'msg' => "Failed Login: " . htmlspecialchars($fl['details']),
                    'time' => $fl['created_at'],
                    'link' => $failedLink
                ];
            }
        } catch(Exception $e){}
    }
    // 2. Password Expirations
    try {
        if ($role === 'superadmin') {
            $expiring_passwords = $pdo->query("SELECT username FROM users WHERE password_expires_at < NOW() AND status = 'Active' LIMIT 5")->fetchAll();
        } else {
            $ep_stmt = $pdo->prepare("SELECT username FROM users WHERE station_id = ? AND password_expires_at < NOW() AND status = 'Active' LIMIT 5");
            $ep_stmt->execute([(int)$myStationId]);
            $expiring_passwords = $ep_stmt->fetchAll();
        }
        foreach($expiring_passwords as $ep) $header_alerts[] = ['msg'=>"Password Expired: {$ep['username']}", 'time'=>'Now', 'link'=>'users.php'];
    } catch(Exception $e){}
    // 3. Reconciliation Delays (Super Admin only)
    // 4. Anomalies Detected
    $sales_data = read_json('sales.json', []);
    foreach($sales_data as $s){
        if ($role !== 'superadmin' && ((int)($s['station_id'] ?? 0) !== (int)$myStationId)) continue;
        if(($s['total'] > 10000 || $s['total'] == 0)) $header_alerts[] = ['msg'=>"Anomaly Detected: ₱".number_format($s['total']), 'time'=>$s['date']??'', 'link'=>'transactions.php'];
    }
    // 5. Inventory
    try {
        if ($role === 'superadmin') {
            $inv = $pdo->query("SELECT ip.product_name FROM station_inventory si JOIN inventory_products ip ON ip.id = si.product_id WHERE si.stock_level <= 20 LIMIT 5")->fetchAll();
        } else {
            $inv_stmt = $pdo->prepare("SELECT ip.product_name FROM station_inventory si JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND si.stock_level <= 20 LIMIT 5");
            $inv_stmt->execute([(int)$myStationId]);
            $inv = $inv_stmt->fetchAll();
        }
        foreach($inv as $i) $header_alerts[] = ['msg'=>"Low Stock: {$i['product_name']}", 'time'=>'Now', 'link'=>'oversight.php'];
    } catch(Exception $e){}
    // 6. Pending Jobs
    try {
        if ($role === 'superadmin') {
            $pjobs = $pdo->query("SELECT id FROM job_orders WHERE status='Pending' LIMIT 5")->fetchAll();
        } else {
            $pj_stmt = $pdo->prepare("SELECT id FROM job_orders WHERE station_id = ? AND status='Pending' LIMIT 5");
            $pj_stmt->execute([(int)$myStationId]);
            $pjobs = $pj_stmt->fetchAll();
        }
        foreach($pjobs as $j) $header_alerts[] = ['msg'=>"Pending Job #{$j['id']}", 'time'=>'Now', 'link'=>'joborder_stats.php'];
    } catch(Exception $e){}
    // 8. Pending Deliveries
    try {
        if ($role === 'superadmin') {
            $pending_deliveries = $pdo->query("SELECT id FROM deliveries_oversight WHERE status IN ('Pending','Pending Validation','Pending Manager Approval') LIMIT 5")->fetchAll();
        } else {
            $pd_stmt = $pdo->prepare("SELECT id FROM deliveries_oversight WHERE station_id = ? AND status IN ('Pending','Pending Validation','Pending Manager Approval') LIMIT 5");
            $pd_stmt->execute([(int)$myStationId]);
            $pending_deliveries = $pd_stmt->fetchAll();
        }
        $del_link = ($role === 'admin') ? 'admin_deliveries_oversight.php' : 'manager_merchandise_deliveries.php';
        foreach($pending_deliveries as $d) $header_alerts[] = ['msg'=>"Pending Delivery #{$d['id']}", 'time'=>'Now', 'link'=>$del_link];
    } catch(Exception $e){}
    // 9. Credit Warnings
    try {
        if ($role === 'superadmin') {
            $credit_warnings = $pdo->query("SELECT name FROM customers WHERE (COALESCE(current_balance, balance, 0) > 0 OR credit_balance > 0) LIMIT 5")->fetchAll();
        } else {
            $cw_stmt = $pdo->prepare("SELECT name FROM customers WHERE station_id = ? AND (COALESCE(current_balance, balance, 0) > 0 OR credit_balance > 0) LIMIT 5");
            $cw_stmt->execute([(int)$myStationId]);
            $credit_warnings = $cw_stmt->fetchAll();
        }
        foreach($credit_warnings as $cw) $header_alerts[] = ['msg'=>"Credit Warning: {$cw['name']}", 'time'=>'Now', 'link'=>'customer_credit.php'];
    } catch(Exception $e){}
    // 10. Fuel Variance (keep existing)
    $fuel_readings = read_json('fuel_readings.json', []);
    foreach($fuel_readings as $fr) {
        if(($fr['computed_liters'] ?? 0) < 0) {
             if($role !== 'superadmin' && ($fr['station_id']??'') != $myStationId) continue;
             $header_alerts[] = ['msg'=>"Fuel Variance: Station " . ($fr['station_id']??'?'), 'time'=>$fr['date']??'', 'link'=>'oversight.php'];
        }
    }
}
$header_alerts = array_slice($header_alerts, 0, 5);
$unread_alerts = count($header_alerts);

// --- BADGE LOGIC ---
$badges = [];
$station_name = '';
$current_date = date('Y-m-d');
$hour = (int)date('H');
$header_shift = ($hour >= 6 && $hour < 14) ? 'First Shift' : 'Second Shift';

// Get station name for all non-superadmin users
if ($myStationId && in_array($role, ['admin', 'manager', 'staff'])) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
        $stmt->execute([$myStationId]);
        $station_name = $stmt->fetchColumn() ?: 'Unknown Station';
    } catch (Exception $e) {
        $station_name = 'Unknown Station';
    }
}

// 1. Transactions / Anomalies (JSON)
if (in_array($role, ['superadmin','admin','manager'])) {
    $sales_data = read_json('sales.json', []);
    $anomalies_count = 0;
    $station_anomalies = 0;
    foreach ($sales_data as $s) {
        $amt = (float)($s['total'] ?? 0);
        if ($amt > 10000 || $amt == 0) {
            $anomalies_count++;
            if (($s['station_id'] ?? '') == $myStationId) {
                $station_anomalies++;
            }
        }
    }
    if ($role === 'superadmin') {
        $badges['transactions'] = $anomalies_count;
    } elseif ($role === 'admin' || $role === 'manager') {
        $badges['pos'] = $station_anomalies;
    }
}

// 2. Job Orders & Users (DB)
try {
    if ($role === 'superadmin') {
        $badges['joborder_stats'] = $pdo->query("SELECT COUNT(*) FROM job_orders WHERE status = 'Pending'")->fetchColumn();
        $badges['users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Disabled'")->fetchColumn();
        
        // Inventory Shortages (Oversight)
        $shortages_count = $pdo->query("SELECT COUNT(*) FROM inventory WHERE stock_level <= 20")->fetchColumn();
        $badges['oversight'] = $shortages_count;

        // Reports aggregates all anomalies/action items
        $badges['reports'] = ($badges['transactions'] ?? 0) + $badges['joborder_stats'] + $shortages_count;
    } elseif ($role === 'admin' || $role === 'manager') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND status = 'Pending'");
        $stmt->execute([$myStationId]);
        $pending_jo_count = (int)$stmt->fetchColumn();
        $badges['joborder'] = $pending_jo_count; // manager

        // NOTE: inventory, reports, and fuel badges for manager/admin are computed
        // authoritatively in the $__badge_count block below (~line 3490).
        // Do NOT set them here to avoid double-counting or contamination.
        $badges['inventory'] = 0; // placeholder; overwritten below
        $badges['reports']   = 0; // placeholder; overwritten below

        if ($role === 'admin') {
            // Admin-specific badge keys matching sidebar item IDs
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND validation_status='Pending'");
                $s->execute([$myStationId]);
                $pending_tx = (int)$s->fetchColumn();
            } catch (Exception $e) { $pending_tx = 0; }
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Pending','Pending Approval','Pending Admin Validation') AND type='merch'");
                $s->execute([$myStationId]);
                $pending_po = (int)$s->fetchColumn();
            } catch (Exception $e) { $pending_po = 0; }
            $badges['admin_transactions_oversight'] = $pending_tx + $pending_jo_count;
            $badges['purchase_orders_admin']        = $pending_po;
            try {
                $s2 = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND admin_finalized=1 AND delivery_validated=1 AND stock_in_done=0 AND type='merch'");
                $s2->execute([$myStationId]);
                $badges['admin_stock_in'] = (int)$s2->fetchColumn();
            } catch (Exception $e) { $badges['admin_stock_in'] = 0; }
        }
    } elseif ($role === 'staff') {
        // Staff badges are dynamically computed via $__badge_add in the layout section below
    }

    // Deliveries Oversight pending badge (admin)
    if ($role === 'admin' || $role === 'superadmin') {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND status IN ('Pending Validation','Pending Manager Approval','Confirmed')");
            $stmt->execute([$myStationId]);
            $badges['deliveries_oversight'] = (int)$stmt->fetchColumn();
        } catch (Exception $e) { $badges['deliveries_oversight'] = 0; }
    }
    // Manager deliveries pending badge
    if ($role === 'manager') {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND status = 'Pending Manager Approval'");
            $stmt->execute([$myStationId]);
            $badges['manager_deliveries'] = (int)$stmt->fetchColumn();
        } catch (Exception $e) { $badges['manager_deliveries'] = 0; }
    }

    // Fetch Stations for Header Filter (Super Admin)
    $header_stations = [];
    if ($role === 'superadmin') {
        try {
            $header_stations = $pdo->query("SELECT id, name FROM stations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
} catch (Exception $e) { /* Tables might not exist yet */ }

$header_notif_url = function($url) use ($app_base_path, $public_base_url) {
    $url = trim((string)$url);
    if ($url === '' || $url === '#') return '#';
    if (preg_match('/^https?:\/\//i', $url)) return $url;
    if (strpos($url, '/public/') === 0) return $app_base_path . $url;
    if (strpos($url, 'public/') === 0) return $app_base_path . '/' . $url;
    if (preg_match('/^[a-zA-Z0-9_-]+\.php/', $url)) return $public_base_url . '/' . $url;
    return $url;
};
if (in_array($role, ['staff','admin','manager','superadmin','developer'])) {
    try {
        if (function_exists('ensure_notifications_table')) {
            ensure_notifications_table($pdo);
        }
        $hn_stmt = $pdo->prepare(
            "SELECT id, type, title, message, event_type, severity, redirect_url, status, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 15"
        );
        $hn_stmt->execute([(int)($user['id'] ?? 0)]);
        $header_notifications = $hn_stmt->fetchAll(PDO::FETCH_ASSOC);

        $hc_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'");
        $hc_stmt->execute([(int)($user['id'] ?? 0)]);
        $header_unread_count = (int)$hc_stmt->fetchColumn();
        
        // Respect session snooze so badge does not flicker on refresh after mark_all_read
        $sn_key = 'notif_bell_snoozed_' . (int)($user['id'] ?? 0);
        if (!empty($_SESSION[$sn_key])) {
            if (time() - (int)$_SESSION[$sn_key] < 300) {
                $header_unread_count = 0;
            } else {
                unset($_SESSION[$sn_key]);
            }
        }
    } catch (Exception $e) {
        $header_notifications = [];
        $header_unread_count = 0;
    }
}

// --- FETCH ALERTS FOR DROPDOWN ---
$header_alerts = [];
if(in_array($role, ['superadmin','admin','manager'])){
    // 1. Failed Logins (Super Admin only)
    if($role === 'superadmin'){
        try {
            $failed_stmt = $pdo->prepare("SELECT user_id, details, created_at FROM activity_logs WHERE action = 'Login Failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND (user_id IS NULL OR user_id <> ?) ORDER BY created_at DESC LIMIT 5");
            $failed_stmt->execute([(int)($user['id'] ?? 0)]);
            $failed_logins = $failed_stmt->fetchAll();
            foreach($failed_logins as $fl) {
                $details = (string)($fl['details'] ?? '');
                $searchUser = '';
                if (preg_match('/username\s*:\s*([^\s]+)/i', $details, $matches)) {
                    $searchUser = trim($matches[1]);
                }

                $failedLink = $public_base_url . '/audit_logs.php?date=today&event=' . urlencode('Login Failed');
                if ($searchUser !== '') {
                    $failedLink .= '&q=' . urlencode($searchUser);
                }

                $header_alerts[] = [
                    'msg' => "Failed Login: " . htmlspecialchars($fl['details']),
                    'time' => $fl['created_at'],
                    'link' => $failedLink
                ];
            }
        } catch(Exception $e){}
    }
    // 2. Password Expirations
    try {
        $expiring_passwords = $pdo->query("SELECT username FROM users WHERE password_expires_at < NOW() AND status = 'Active' LIMIT 5")->fetchAll();
        foreach($expiring_passwords as $ep) $header_alerts[] = ['msg'=>"Password Expired: {$ep['username']}", 'time'=>'Now', 'link'=>'users.php'];
    } catch(Exception $e){}
    // 3. Reconciliation Delays (Super Admin only)
    // 4. Anomalies Detected
    $sales_data = read_json('sales.json', []);
    foreach($sales_data as $s){
        if(($s['total'] > 10000 || $s['total'] == 0)) $header_alerts[] = ['msg'=>"Anomaly Detected: ₱".number_format($s['total']), 'time'=>$s['date']??'', 'link'=>'transactions.php'];
    }
    // 5. Inventory (keep existing)
    try {
        $inv = $pdo->query("SELECT product_name FROM inventory WHERE stock_level <= 20 LIMIT 5")->fetchAll();
        foreach($inv as $i) $header_alerts[] = ['msg'=>"Low Stock: {$i['product_name']}", 'time'=>'Now', 'link'=>'oversight.php'];
    } catch(Exception $e){}
    // 6. Pending Jobs (keep existing)
    try {
        $pjobs = $pdo->query("SELECT id FROM job_orders WHERE status='Pending' LIMIT 5")->fetchAll();
        foreach($pjobs as $j) $header_alerts[] = ['msg'=>"Pending Job #{$j['id']}", 'time'=>'Now', 'link'=>'joborder_stats.php'];
    } catch(Exception $e){}
    // 8. Pending Deliveries
    try {
        $pending_deliveries = $pdo->query("SELECT id FROM receiving WHERE status = 'pending' LIMIT 5")->fetchAll();
        foreach($pending_deliveries as $d) $header_alerts[] = ['msg'=>"Pending Delivery #{$d['id']}", 'time'=>'Now', 'link'=>'supplier_confirmation.php'];
    } catch(Exception $e){}
    // 9. Credit Warnings
    try {
        $credit_warnings = $pdo->query("SELECT name FROM customers WHERE credit_balance > 0 LIMIT 5")->fetchAll();
        foreach($credit_warnings as $cw) $header_alerts[] = ['msg'=>"Credit Warning: {$cw['name']}", 'time'=>'Now', 'link'=>'customer_credit.php'];
    } catch(Exception $e){}
    // 10. Fuel Variance (keep existing)
    $fuel_readings = read_json('fuel_readings.json', []);
    foreach($fuel_readings as $fr) {
        if(($fr['computed_liters'] ?? 0) < 0) {
             if($role !== 'superadmin' && ($fr['station_id']??'') != $myStationId) continue;
             $header_alerts[] = ['msg'=>"Fuel Variance: Station " . ($fr['station_id']??'?'), 'time'=>$fr['date']??'', 'link'=>'oversight.php'];
        }
    }
}
$header_alerts = array_slice($header_alerts, 0, 5);
$unread_alerts = count($header_alerts);

// --- BADGE LOGIC ---
$badges = [];
$station_name = '';
$current_date = date('Y-m-d');
$hour = (int)date('H');
$header_shift = ($hour >= 6 && $hour < 14) ? 'First Shift' : 'Second Shift';

// Get station name for all non-superadmin users
if ($myStationId && in_array($role, ['admin', 'manager', 'staff'])) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
        $stmt->execute([$myStationId]);
        $station_name = $stmt->fetchColumn() ?: 'Unknown Station';
    } catch (Exception $e) {
        $station_name = 'Unknown Station';
    }
}

// 1. Transactions / Anomalies (JSON)
if (in_array($role, ['superadmin','admin','manager'])) {
    $sales_data = read_json('sales.json', []);
    $anomalies_count = 0;
    $station_anomalies = 0;
    foreach ($sales_data as $s) {
        $amt = (float)($s['total'] ?? 0);
        if ($amt > 10000 || $amt == 0) {
            $anomalies_count++;
            if (($s['station_id'] ?? '') == $myStationId) {
                $station_anomalies++;
            }
        }
    }
    if ($role === 'superadmin') {
        $badges['transactions'] = $anomalies_count;
    } elseif ($role === 'admin' || $role === 'manager') {
        $badges['pos'] = $station_anomalies;
    }
}

// 2. Job Orders & Users (DB)
try {
    if ($role === 'superadmin') {
        $badges['joborder_stats'] = $pdo->query("SELECT COUNT(*) FROM job_orders WHERE status = 'Pending'")->fetchColumn();
        $badges['users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Disabled'")->fetchColumn();
        
        // Inventory Shortages (Oversight)
        $shortages_count = $pdo->query("SELECT COUNT(*) FROM inventory WHERE stock_level <= 20")->fetchColumn();
        $badges['oversight'] = $shortages_count;

        // Reports aggregates all anomalies/action items
        $badges['reports'] = ($badges['transactions'] ?? 0) + $badges['joborder_stats'] + $shortages_count;
    } elseif ($role === 'admin' || $role === 'manager') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND status = 'Pending'");
        $stmt->execute([$myStationId]);
        $pending_jo_count = (int)$stmt->fetchColumn();
        $badges['joborder'] = $pending_jo_count; // manager

        // Inventory Shortages
        $stmtInv = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE station_id = ? AND stock_level <= 20");
        $stmtInv->execute([$myStationId]);
        $badges['inventory'] = (int)$stmtInv->fetchColumn();

        if ($role === 'admin') {
            // Admin-specific badge keys matching sidebar item IDs
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND validation_status='Pending'");
                $s->execute([$myStationId]);
                $pending_tx = (int)$s->fetchColumn();
            } catch (Exception $e) { $pending_tx = 0; }
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND status IN ('Pending','Pending Approval','Pending Admin Validation') AND type='merch'");
                $s->execute([$myStationId]);
                $pending_po = (int)$s->fetchColumn();
            } catch (Exception $e) { $pending_po = 0; }
            $badges['admin_transactions_oversight'] = $pending_tx + $pending_jo_count;
            $badges['purchase_orders_admin']        = $pending_po;
            // Badge for Stock-In: POs admin-finalized AND manager-validated, awaiting stock-in
            try {
                $s2 = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND admin_finalized=1 AND delivery_validated=1 AND stock_in_done=0 AND type='merch'");
                $s2->execute([$myStationId]);
                $badges['admin_stock_in'] = (int)$s2->fetchColumn();
            } catch (Exception $e) { $badges['admin_stock_in'] = 0; }
            $badges['reports_admin']                = $pending_tx + $pending_jo_count + ($badges['inventory'] ?? 0);
        } else {
            // Reports Aggregate for manager
            $badges['reports'] = ($badges['pos'] ?? 0) + $pending_jo_count + ($badges['inventory'] ?? 0);
        }
    } elseif ($role === 'staff') {
        // Legacy individual badge assignments removed — now handled by the newer
        // $__badge_add() system below (lines ~2900+) to avoid double-counting.
        // No legacy badge keys added here for staff.
    }

    // Deliveries Oversight pending badge (admin)
    if ($role === 'admin' || $role === 'superadmin') {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND status IN ('Pending Validation','Pending Manager Approval','Confirmed')");
            $stmt->execute([$myStationId]);
            $badges['deliveries_oversight'] = (int)$stmt->fetchColumn();
        } catch (Exception $e) { $badges['deliveries_oversight'] = 0; }
    }
    // Manager deliveries pending badge
    if ($role === 'manager') {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id = ? AND status = 'Pending Manager Approval'");
            $stmt->execute([$myStationId]);
            $badges['manager_deliveries'] = (int)$stmt->fetchColumn();
        } catch (Exception $e) { $badges['manager_deliveries'] = 0; }
    }

    // Fetch Stations for Header Filter (Super Admin)
    $header_stations = [];
    if ($role === 'superadmin') {
        try {
            $header_stations = $pdo->query("SELECT id, name FROM stations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
} catch (Exception $e) { /* Tables might not exist yet */ }

// --- SYSTEM STATUS CHECK ---
$db_connection_status = 'OK';
$db_connection_color = 'var(--petron-green)';
// db_connect.php throws an exception, so if we're here, the initial connection was fine.
// This is a fallback check.
if (!isset($pdo) || !$pdo) {
    $db_connection_status = 'Error';
    $db_connection_color = 'var(--petron-red)';
}
?>
<?php 
// --- FETCH SYSTEM SETTINGS (GLOBAL & STATION-SPECIFIC) ---
$station_settings = [];
try {
    $stmt0 = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE station_id = 0");
    $stmt0->execute();
    while ($row = $stmt0->fetch(PDO::FETCH_ASSOC)) {
        // Strip 'global_' prefix so we just have 'color_primary', 'logo', etc.
        $key = preg_replace('/^global_/', '', $row['setting_key']);
        $station_settings[$key] = $row['setting_value'];
    }
    if ($myStationId > 0) {
        $stmtS = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE station_id = ?");
        $stmtS->execute([$myStationId]);
        while ($row = $stmtS->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_value'] !== null && $row['setting_value'] !== '') {
                // Strip 'station_X_' prefix
                $key = preg_replace('/^station_' . $myStationId . '_/', '', $row['setting_key']);
                $station_settings[$key] = $row['setting_value'];
            }
        }
    }
} catch (Exception $e) {}

$theme_primary_color = $station_settings['color_primary'] ?? '#002F6C';
$theme_button_color  = $station_settings['color_button'] ?? '#002F6C';
$theme_sidebar_color = $station_settings['color_sidebar'] ?? '#00264D';
$theme_font_scale    = $station_settings['font_scale'] ?? '100';
$theme_high_contrast = (isset($station_settings['high_contrast']) && ($station_settings['high_contrast'] === '1' || $station_settings['high_contrast'] === 'true'));

// --- APPEARANCE SETTINGS FROM SYSTEM SETTINGS ---
$appearance_theme        = $station_settings['theme'] ?? 'Light';
$appearance_accent_color = $station_settings['system_accent_color'] ?? $station_settings['color_primary'] ?? '#002F6C';
if (!preg_match('/^#[0-9A-Fa-f]{3,6}$/', $appearance_accent_color)) {
    $appearance_accent_color = '#002F6C';
}

// Sidebar Navigation Color priority: sidebar_color -> color_sidebar -> if user set custom accent color (#006b1b) -> default #00264D
$appearance_sidebar_color = $station_settings['sidebar_color'] ?? $station_settings['color_sidebar'] ?? '';
if (!preg_match('/^#[0-9A-Fa-f]{3,6}$/', $appearance_sidebar_color)) {
    if ($appearance_accent_color !== '#002F6C') {
        $appearance_sidebar_color = $appearance_accent_color;
    } else {
        $appearance_sidebar_color = '#00264D';
    }
}

// Active Nav Item Color: nav_active_color -> default #E30613
$appearance_nav_active_color = $station_settings['nav_active_color'] ?? '#E30613';
if (!preg_match('/^#[0-9A-Fa-f]{3,6}$/', $appearance_nav_active_color)) {
    $appearance_nav_active_color = '#E30613';
}

$theme_sidebar_color = $appearance_sidebar_color;
$theme_primary_color = $appearance_accent_color;
$theme_button_color  = $appearance_accent_color;

$appearance_sidebar_mode     = $station_settings['sidebar_mode'] ?? 'Expanded';
$appearance_auto_refresh_sec = (int)($station_settings['dashboard_auto_refresh'] ?? 10);
if ($appearance_auto_refresh_sec < 5)  $appearance_auto_refresh_sec = 5;
if ($appearance_auto_refresh_sec > 3600) $appearance_auto_refresh_sec = 3600;
$appearance_is_dark = (strtolower($appearance_theme) === 'dark');
// ── Enforce Dynamic Timezone from System Settings ──
if (function_exists('petron_init_dynamic_timezone')) {
    petron_init_dynamic_timezone();
} else {
    $tzSetting = $station_settings['timezone'] ?? 'Asia/Manila (UTC+8)';
    $tzIdent = trim(explode(' ', $tzSetting)[0]);
    if (in_array($tzIdent, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tzIdent);
    } else {
        date_default_timezone_set('Asia/Manila');
    }
}

$appearance_sidebar_collapsed = (strtolower($appearance_sidebar_mode) === 'collapsed');

 ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="<?php echo function_exists('sec_generate_csrf_token') ? sec_generate_csrf_token() : ''; ?>" />

  <!-- ── SEO Meta Tags ── -->
  <meta name="description" content="Petron Station Management System — A comprehensive management platform for Petron fuel stations. Manage transactions, inventory, employees, and reports." />
  <meta name="keywords" content="Petron, Station Management System, Petron POS, Fuel Station, Inventory Management" />
  <meta name="author" content="Petron Station Management System" />
  <meta name="robots" content="<?php echo in_array($role ?? '', ['superadmin','admin','manager','staff','developer']) ? 'noindex, nofollow' : 'index, follow'; ?>" />

  <!-- ── Open Graph (for sharing sa social media & search engines) ── -->
  <?php 
    $_cur_proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $_cur_host  = $_SERVER['HTTP_HOST'] ?? 'yangch-stationms.online';
    $_cur_domain_url = $_cur_proto . $_cur_host;
  ?>
  <meta property="og:title" content="Station MS" />
  <meta property="og:description" content="Comprehensive management platform for Petron fuel stations." />
  <meta property="og:type" content="website" />
  <meta property="og:url" content="<?php echo htmlspecialchars($_cur_domain_url . '/'); ?>" />
  <meta property="og:image" content="<?php echo htmlspecialchars($_cur_domain_url . $app_base_path . '/assets/img/petron_logo.png'); ?>" />

  <!-- ── Mobile App / Chrome Shortcut Branding ── -->
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="Station MS" />
  <meta name="application-name" content="Station MS" />
  <meta name="theme-color" content="#00264D" />

  <title><?php echo !empty($page_title) ? htmlspecialchars($page_title) . ' | Station MS' : 'Station MS'; ?></title>

  <!-- ── Favicon & Tab Logo (Instant Base64 Fallback + Multi-Resolution ICO & PNG) ── -->
  <link rel="icon" type="image/png" sizes="32x32" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAEJElEQVRYw+WXbUxbZRTHf719hTLa8o6M8DKQMIGJQYObfnBxTLe5kGhCFkMWJ8bFJYqJH7Y5Z3TE+GEm09jFqcDYwnxjavhggm664eSlQyVTNmUVmANaVqCFthRKe+uHLYStveXFkpj4fDv3nud/fvc8zz3Pc+D/PmQhn+bsyQFOAg8AQgTiiIAJqMRsNM9/oZCYcHLnxECpQ1DSEpOCOiCyfmqMMreVjNmpIGe7XEWXxsBZbRK9qlWh9ASg9NZHPbiYDPg/tHYJj7us2OUqPtBn87E+G69MoGTazjOOfra5LAgEgqb+qjFwxJDL99qk0JkwG+V3kkkRA2Dwe9k39gfNgxdI83no0hjYk3IfW9If4rI6Nmhi8bSdBouJWstFdOKspO5CAEHjnplJPhtqnxPtUesoX72B9qj4kP5l7hFODXWiDohhdZe0wTJmp3h5vHfO9sjkvJhcjFcWWqZoxsEuR3/kAADKnUO32VaFhm6NXtJ/q8sSWYB4vzcorT6JvQwQJ3ojCzCg1DIzL+Uxoo91MxOS/mZlTGQBjIac2+xXxv9EK/ok/U/oMiIHcNSwhk9j0+fs5xx9PBtmk53QZXJGmxxWU7GYwAHgUMJaPtJnz9WGGtvvbHcNS9RdGe8bcjgcn7eg9qIADiYWcFyXCcATrmHetPWQ4J8JveaqGF5NLKRNoj4sGeBwXB7HdZloAn7evvEbTzoHQ/rZ5GrejculMTYDn0y26GUNC9CiTeG9uFwMfi8NFhPF044gnzG5imP6NTToMpkS5Es+JiUB/Mg4lJCPVvTRONxJ4R2/mltQYDTkUKvLWlbgBQFaoxO5rozmmPXnoOBfr0qjJj6fEYXmX18UJAHOaJMpc4+wZV4vaaMZm9SEReiEiJ2I5IEsMnVVDn65uy2qASqUktwCgoiOSTV7vJ5KPWMAXBVFcOu1BLcEQ4ethJuco/MHTEHEwpWJHjYDJyPTqQifQNKhZw2RSwhbl8rCzCg1PLJF6+TnqpHFANUvFRH6b1ZyGRgG3eRnmoAoPvyIOvy05hweqg/3cHT2+9HpZRTf7qD3Tsepq6pnfJNRWLDl53CUq7lAYArLa9RXdMk7n2+TBDFgKhSyYVvf7wi/vRLn7B/92Y6uvtJTdIxMupk28YCevtuiPbJKcHpnsbnF6ne+QjGxlYeXZ8nFm596yaA2Shb0ml45MBTwl9/2/iho1dQqxTE67XCuc6rXBse55zJzKRrmrU5KaJj0sPImFP45nwPX313ibRkPbZxFxtL70YQBGFZx/Gg1cGO6nqq9p+i9aKZKLWSkqIMMWt1PLZxF57pWUbtLuqa2lHIBbH57CXxwAuPcfSNCmo/b6NvcFTc904z1y32JXdG/gh1RMvuC0wr1AqaFrsElUDHrZ4uMl9+U6/yP9cd/wMZ2nU8I3Z10QAAAABJRU5ErkJggg==" />
  <link rel="icon" type="image/x-icon" href="<?php echo $app_base_path; ?>/assets/img/favicon.ico?v=2.2" />
  <link rel="icon" type="image/png" sizes="16x16" href="<?php echo $app_base_path; ?>/assets/img/favicon-16x16.png?v=2.2" />
  <link rel="icon" type="image/png" sizes="32x32" href="<?php echo $app_base_path; ?>/assets/img/favicon-32x32.png?v=2.2" />
  <link rel="icon" type="image/png" sizes="48x48" href="<?php echo $app_base_path; ?>/assets/img/favicon-48x48.png?v=2.2" />
  <link rel="shortcut icon" type="image/x-icon" href="<?php echo $app_base_path; ?>/assets/img/favicon.ico?v=2.2" />

  <!-- ── Apple Touch & Chrome Android App Icons (Circle Shortcut & Homescreen) ── -->
  <link rel="apple-touch-icon" sizes="180x180" href="<?php echo $app_base_path; ?>/assets/img/apple-touch-icon.png?v=2.2" />
  <link rel="icon" type="image/png" sizes="192x192" href="<?php echo $app_base_path; ?>/assets/img/android-chrome-192x192.png?v=2.2" />
  <link rel="icon" type="image/png" sizes="512x512" href="<?php echo $app_base_path; ?>/assets/img/android-chrome-512x512.png?v=2.2" />
  <link rel="manifest" href="<?php echo $app_base_path; ?>/manifest.json?v=2.2" />
  <link rel="stylesheet" href="<?php echo $app_base_path; ?>/assets/css/style.css?v=2.0.2" />
  <link rel="stylesheet" href="<?php echo $app_base_path; ?>/assets/css/manager_table_design.css?v=2.0.2" />
  <link rel="stylesheet" href="<?php echo $app_base_path; ?>/assets/css/manager_customer_management.css?v=2.0.2" />
  <link rel="stylesheet" href="<?php echo $app_base_path; ?>/assets/vendor/fontawesome/css/all.min.css">
  <script src="<?php echo $app_base_path; ?>/assets/js/security_frontend.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/security_frontend.js'); ?>"></script>
  <!-- ── Petron Real-Time Auto-Refresh Engine ── -->
  <!-- ── Petron Real-Time Auto-Refresh Engine & Regional Settings ── -->
  <?php
    $header_tz_raw  = $station_settings['timezone'] ?? (function_exists('petron_get_setting_value') ? petron_get_setting_value('timezone', null, 'Asia/Manila (UTC+8)') : 'Asia/Manila (UTC+8)');
    $header_tz_iana = trim(explode(' ', $header_tz_raw)[0]);
    if (!in_array($header_tz_iana, timezone_identifiers_list(), true)) {
        $header_tz_iana = 'Asia/Manila';
    }
    $header_date_fmt = $station_settings['date_format'] ?? (function_exists('petron_date_format') ? petron_date_format() : 'YYYY-MM-DD');
    $header_time_fmt = $station_settings['time_format'] ?? (function_exists('petron_time_format') ? petron_time_format() : '12H');
    $header_curr_sym = function_exists('petron_currency_symbol') ? petron_currency_symbol() : '₱';
    $header_curr_raw = $station_settings['currency_symbol'] ?? (function_exists('petron_get_setting_value') ? petron_get_setting_value('currency_symbol', null, 'PHP (₱)') : 'PHP (₱)');
    $header_sec_policy = function_exists('petron_get_security_policy') ? petron_get_security_policy((int)$myStationId) : [
        'session_timeout'       => 30,
        'min_password_length'   => 8,
        'max_login_attempts'    => 5,
        'require_uppercase'     => true,
        'require_numbers'       => true,
        'require_special_chars' => true,
    ];
    $header_session_timeout_min = max(1, (int)round($header_session_timeout_seconds / 60)); // from DB (top of file)
    $header_session_timeout_sec = (int)$header_session_timeout_seconds;
  ?>
  <script>
    window.PETRON_BASE_PATH = <?php echo json_encode(rtrim($app_base_path, '/')); ?>;
    window.PETRON_STATION_ID = <?php echo (int)$myStationId; ?>;
    window.PETRON_USER_ROLE  = <?php echo json_encode($role); ?>;
    window.PETRON_USER_ID    = <?php echo (int)($user['id'] ?? 0); ?>;
    window.pageData = window.pageData || {};
    window.pageData.appBasePath = <?php echo json_encode(rtrim($app_base_path, '/')); ?>;
    window.pageData.publicBasePath = <?php echo json_encode(rtrim($public_base_url, '/')); ?>;

    // ── Global Petron Security Settings ──
    window.PETRON_SESSION_TIMEOUT_SEC = <?php echo (int)$header_session_timeout_sec; ?>; // from DB system_settings
    window.PETRON_SESSION_TIMEOUT_MIN = <?php echo (int)$header_session_timeout_min; ?>; // from DB system_settings
    window.PETRON_SECURITY_POLICY     = <?php echo json_encode($header_sec_policy); ?>;

    // ── Global Petron Regional Settings & Client Engine ──
    window.PETRON_REGIONAL = {
        timezone:       <?php echo json_encode($header_tz_iana); ?>,
        timezoneRaw:    <?php echo json_encode($header_tz_raw); ?>,
        dateFormat:     <?php echo json_encode($header_date_fmt); ?>,
        timeFormat:     <?php echo json_encode($header_time_fmt); ?>,
        currencySymbol: <?php echo json_encode($header_curr_sym); ?>,
        currencyRaw:    <?php echo json_encode($header_curr_raw); ?>
    };

    (function() {
        const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const MONTHS_LONG = ['January','February','March','April','May','June','July','August','September','October','November','December'];

        function getTzParts(d, targetTz) {
            const dateObj = (d instanceof Date) ? d : new Date(d);
            if (isNaN(dateObj.getTime())) return null;
            const tz = targetTz || (window.PETRON_REGIONAL && window.PETRON_REGIONAL.timezone) || 'Asia/Manila';
            try {
                const formatter = new Intl.DateTimeFormat('en-US', {
                    timeZone: tz,
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    weekday: 'long',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false
                });
                const parts = {};
                formatter.formatToParts(dateObj).forEach(p => { parts[p.type] = p.value; });
                return parts;
            } catch (e) {
                return {
                    year: String(dateObj.getFullYear()),
                    month: String(dateObj.getMonth() + 1).padStart(2, '0'),
                    day: String(dateObj.getDate()).padStart(2, '0'),
                    weekday: dateObj.toLocaleDateString('en-US', { weekday: 'long' }),
                    hour: String(dateObj.getHours()).padStart(2, '0'),
                    minute: String(dateObj.getMinutes()).padStart(2, '0'),
                    second: String(dateObj.getSeconds()).padStart(2, '0')
                };
            }
        }

        window.formatPetronDate = function(d, withDay = false, overrideFmt = null, overrideTz = null) {
            const parts = getTzParts(d, overrideTz);
            if (!parts) return '';
            const fmt = (overrideFmt || (window.PETRON_REGIONAL && window.PETRON_REGIONAL.dateFormat) || 'YYYY-MM-DD').toUpperCase().trim();
            const y = parts.year;
            const m = parts.month;
            const dStr = parts.day;
            const mIdx = Math.max(0, parseInt(m, 10) - 1);
            let res = '';

            switch (fmt) {
                case 'MM/DD/YYYY':
                    res = `${m}/${dStr}/${y}`;
                    break;
                case 'DD/MM/YYYY':
                    res = `${dStr}/${m}/${y}`;
                    break;
                case 'MMM DD, YYYY':
                    res = `${MONTHS[mIdx]} ${dStr}, ${y}`;
                    break;
                case 'YYYY-MM-DD':
                default:
                    res = `${y}-${m}-${dStr}`;
                    break;
            }
            return withDay ? `${parts.weekday}, ${res}` : res;
        };

        window.formatPetronTime = function(d, withSeconds = true, overrideTimeFmt = null, overrideTz = null) {
            const parts = getTzParts(d, overrideTz);
            if (!parts) return '';
            const is24 = ((overrideTimeFmt || (window.PETRON_REGIONAL && window.PETRON_REGIONAL.timeFormat) || '12H').toUpperCase().indexOf('24') !== -1);
            const h24 = parseInt(parts.hour, 10);
            const m = parts.minute;
            const s = parts.second;

            if (is24) {
                return String(h24).padStart(2, '0') + ':' + m + (withSeconds ? ':' + s : '');
            } else {
                const ampm = h24 >= 12 ? 'PM' : 'AM';
                const h12 = h24 % 12 || 12;
                return String(h12).padStart(2, '0') + ':' + m + (withSeconds ? ':' + s : '') + ' ' + ampm;
            }
        };

        window.formatPetronDateTime = function(d, withSeconds = true, withDay = false, overrideDateFmt = null, overrideTimeFmt = null, overrideTz = null) {
            const datePart = window.formatPetronDate(d, withDay, overrideDateFmt, overrideTz);
            const timePart = window.formatPetronTime(d, withSeconds, overrideTimeFmt, overrideTz);
            if (!datePart && !timePart) return '';
            return `${datePart} at ${timePart}`;
        };

        window.formatPetronCurrency = function(amount, decimals = 2, overrideSymbol = null) {
            const sym = overrideSymbol || (window.PETRON_REGIONAL && window.PETRON_REGIONAL.currencySymbol) || '₱';
            const num = parseFloat(amount);
            const formattedNum = isNaN(num) ? '0.00' : num.toLocaleString('en-US', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
            return `${sym} ${formattedNum}`;
        };
    })();
  </script>
  <script src="<?php echo $app_base_path; ?>/assets/js/petron_realtime.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/petron_realtime.js'); ?>"></script>
    <!-- GLOBAL RIGHT-CLICK & TEXT SELECTION ALLOWED -->
    <style>
        /* Allow normal selection, right-click, cut, copy & paste everywhere */
        body, table, td, th, div, span, p, .card, .card-header, .stat-card, .badge, input, textarea, select, [contenteditable="true"], .allow-select, .allow-copy, code, pre, .selectable-text {
            -webkit-user-select: text !important;
            -moz-user-select: text !important;
            -ms-user-select: text !important;
            user-select: text !important;
        }

        /* Prevent image scraping / drag-and-drop tampering of branding */
        img, .brand-logo, .petron-logo {
            -webkit-user-drag: none;
            -khtml-user-drag: none;
            -moz-user-drag: none;
            -o-user-drag: none;
            user-drag: none;
            pointer-events: auto;
        }
    </style>
    <style>
        /* ===== TEXT VISIBILITY FIX - Keep all colors, just fix text contrast ===== */
    
    /* Only fix form labels and inputs - keep everything else as is */
    label:not([style*="color:"]), 
    .form-label:not([style*="color:"]), 
    .field-label:not([style*="color:"]) {
        color: #1e293b !important;
    }
    
    /* Input fields - dark text */
    input[type="text"]:not([readonly]):not([disabled]),
    input[type="email"]:not([readonly]):not([disabled]),
    input[type="password"]:not([readonly]):not([disabled]),
    input[type="number"]:not([readonly]):not([disabled]),
    input[type="tel"]:not([readonly]):not([disabled]),
    input[type="search"]:not([readonly]):not([disabled]),
    select:not([disabled]),
    textarea:not([readonly]):not([disabled]) {
        color: #1e293b !important;
    }
    
    /* Placeholder text */
    input::placeholder,
    textarea::placeholder {
        color: #94a3b8 !important;
        opacity: 1 !important;
    }

    /* Outlined, Filter, Reset, Cancel, and Action buttons must inherit their explicit class text colors */
    .int-btn-outline,
    .flt-btn,
    .flt-btn-search,
    .flt-btn-reset,
    .btn-cancel,
    .btn-reset,
    .btn-secondary,
    .btn-outline,
    .btn-ghost,
    .vt-btn-reset,
    .modal-btn-secondary,
    .po-btn-back,
    .ato-btn-back,
    button[class*="outline"],
    button[class*="reset"],
    button[class*="cancel"] {
        color: inherit !important;
        -webkit-text-fill-color: inherit !important;
    }

    /* Only solid primary/action buttons get white text by default */
    button.btn-primary,
    button.btn-success,
    button.btn-danger,
    button.flt-btn-solid-primary {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
    }

    /* Sub-tab buttons - Explicit High-Contrast Active & Inactive Styling */
    .fuel-sub-tab-btn.active,
    .fuel-sub-tab-btn.active:hover,
    .fuel-sub-tab-btn.active:focus,
    .fuel-sub-tab-btn.active:active {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        background-color: #002F70 !important;
        background: #002F70 !important;
        border: 2px solid #002F70 !important;
        opacity: 1 !important;
    }

    .fuel-sub-tab-btn:not(.active),
    .fuel-sub-tab-btn:not(.active):hover,
    .fuel-sub-tab-btn:not(.active):focus,
    .fuel-sub-tab-btn:not(.active):active {
        color: #002F70 !important;
        -webkit-text-fill-color: #002F70 !important;
        background-color: #ffffff !important;
        background: #ffffff !important;
        border: 2px solid #cbd5e1 !important;
        opacity: 1 !important;
    }

    /* Icons inside buttons must also be visible */
    button i,
    button .fas,
    button .far,
    button .fab {
        color: inherit !important;
        -webkit-text-fill-color: inherit !important;
    }

    /* ===== SYSTEM-WIDE GLOBAL MODAL RULES ===== */
    /* 1. Remove all X close buttons in modal headers across all pages */
    .modal-close,
    .db-modal-close,
    .map-modal-close,
    .txn-modal-close,
    .va-modal-close,
    .mi-close,
    button[class*="modal-close"],
    button[class*="close-modal"],
    .modal-header .close,
    .modal-header button.close {
        display: none !important;
    }

    /* 2. Enforce 100% visible, high-contrast text on all modal buttons */
    .modal-footer button,
    .db-modal-footer button,
    .map-modal-footer button,
    .txn-modal-footer button,
    .va-modal-footer button,
    div[class*="modal-footer"] button {
        font-weight: 700 !important;
        text-decoration: none !important;
    }

    /* Solid Primary Modal Buttons -> White text on Navy */
    .modal-footer button.btn-primary,
    .modal-footer button.db-btn-primary,
    .modal-footer button.map-btn-primary,
    .db-modal-footer button.db-btn-primary,
    .map-modal-footer button.map-btn-primary,
    div[class*="modal-footer"] button.btn-primary,
    div[class*="modal-footer"] button[class*="primary"] {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        background-color: #002F6C !important;
        border-color: #002F6C !important;
    }

    /* Solid Danger Modal Buttons -> White text on Red */
    .modal-footer button.btn-danger,
    .modal-footer button.db-btn-danger,
    .modal-footer button.map-btn-danger,
    .db-modal-footer button.db-btn-danger,
    .map-modal-footer button.map-btn-danger,
    div[class*="modal-footer"] button.btn-danger,
    div[class*="modal-footer"] button[class*="danger"] {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        background-color: #dc2626 !important;
        border-color: #dc2626 !important;
    }

    /* Solid Gray / Cancel Modal Buttons -> White text on Gray */
    .modal-footer button.btn-secondary,
    .modal-footer button.btn-gray,
    .modal-footer button.db-btn-gray,
    .modal-footer button.map-btn-gray,
    .db-modal-footer button.db-btn-ghost,
    .db-modal-footer button.db-btn-gray,
    .map-modal-footer button.map-btn-gray,
    div[class*="modal-footer"] button[class*="secondary"],
    div[class*="modal-footer"] button[class*="gray"],
    div[class*="modal-footer"] button[class*="ghost"],
    div[class*="modal-footer"] button[class*="cancel"] {
        color: #ffffff !important;
        -webkit-text-fill-color: #ffffff !important;
        background-color: #6b7280 !important;
        border-color: #6b7280 !important;
    }

    .modal-footer button *,
    .db-modal-footer button *,
    .map-modal-footer button *,
    div[class*="modal-footer"] button * {
        color: inherit !important;
        -webkit-text-fill-color: inherit !important;
    }

    /* 3. Global Modal Header Clearance & Box Visibility Rule */
    /* 3. Global Modal Header & Footer Clearance & Box Visibility Rule */
    /* Guarantees clean, visible breathing room between the fixed top-header, fixed footer, and all modal dialogs */
    .modal-dialog {
        margin-top: calc(70px + 24px) !important;
        margin-bottom: calc(40px + 24px) !important;
    }
    .am-modal-overlay,
    .map-modal-overlay,
    .db-modal-overlay,
    .sr-modal-overlay,
    .txn-modal-overlay,
    .va-modal-overlay,
    .cust-modal-overlay,
    div[class*="modal-overlay"] {
        top: 70px !important;
        bottom: 40px !important;
        padding-top: 24px !important;
        padding-bottom: 24px !important;
        box-sizing: border-box !important;
    }

    @media (max-width: 991px) {
        .modal-dialog {
            margin-top: calc(60px + 16px) !important;
            margin-bottom: calc(40px + 16px) !important;
        }
        .am-modal-overlay,
        .map-modal-overlay,
        .db-modal-overlay,
        .sr-modal-overlay,
        .txn-modal-overlay,
        .va-modal-overlay,
        .cust-modal-overlay,
        div[class*="modal-overlay"] {
            top: 60px !important;
            bottom: 40px !important;
            padding-top: 16px !important;
            padding-bottom: 16px !important;
        }
    }

    /* Modal box max-height and shadow refinement so box outline and rounded corners are 100% visible */
    .am-modal,
    .db-modal,
    .map-modal,
    .sr-modal,
    .txn-modal,
    .va-modal,
    .modal-content {
        max-height: calc(100vh - 70px - 40px - 48px) !important;
        box-shadow: 0 16px 48px rgba(0, 0, 0, 0.22), 0 0 0 1px rgba(0, 0, 0, 0.08) !important;
    }
    @media (max-width: 991px) {
        .am-modal,
        .db-modal,
        .map-modal,
        .sr-modal,
        .txn-modal,
        .va-modal,
        .modal-content {
            max-height: calc(100vh - 60px - 40px - 32px) !important;
        }
    }
  </style>
  <style id="petronDynamicAppearanceStyles">
    :root {
        --petron-blue: <?php echo htmlspecialchars($appearance_accent_color); ?> !important;
        --primary: <?php echo htmlspecialchars($appearance_accent_color); ?> !important;
        --sidebar-bg: <?php echo htmlspecialchars($appearance_sidebar_color); ?> !important;
        --nav-active-color: <?php echo htmlspecialchars($appearance_nav_active_color); ?> !important;
        --petron-red: <?php echo htmlspecialchars($appearance_nav_active_color); ?> !important;
        --system-accent: <?php echo htmlspecialchars($appearance_accent_color); ?> !important;
        font-size: <?php echo htmlspecialchars($theme_font_scale); ?>% !important;
    }
    #mainSidebar, .sidebar {
        background: var(--sidebar-bg) !important;
        background-color: var(--sidebar-bg) !important;
    }
    .nav-item.active:not(.has-submenu),
    .nav-item:active:not(.has-submenu),
    .sidebar-sub-item.active,
    .sidebar-sub-item:active,
    .nav-item.is-nav-clicking:not(.has-submenu),
    .sidebar-sub-item.is-nav-clicking {
        background-color: var(--nav-active-color) !important;
        color: #ffffff !important;
    }
    .has-submenu,
    .has-submenu:active,
    .has-submenu.active,
    .has-submenu.is-nav-clicking {
        background-color: transparent !important;
    }
    .sidebar-sub-item.active,
    .sidebar-sub-item:active,
    .sidebar-sub-item.is-nav-clicking {
        border-left: none !important;
    }
    button, .btn, .ss-btn-primary {
        background-color: <?php echo htmlspecialchars($theme_button_color); ?> !important;
    }
    /* ── Calendar modal buttons: preserve their own white/blue backgrounds ── */
    #eventModal button,
    #detailsModal button,
    #calendarDetailsModal button,
    #eventModal .btn,
    #detailsModal .btn {
        background-color: unset !important;
    }
    #eventModal button[type="button"],
    #detailsModal button[type="button"],
    #calendarDetailsModal button[type="button"] {
        background-color: #ffffff !important;
        color: #3c4043 !important;
        border: 1px solid #dadce0 !important;
    }
    #eventModal button[type="submit"],
    #detailsModal button[type="submit"],
    #calendarDetailsModal button[type="submit"],
    #eventModal button.cal-btn-save,
    #detailsModal button.cal-btn-save {
        background-color: #1a73e8 !important;
        color: #ffffff !important;
        border: none !important;
    }
    /* ── Dynamically-injected detail action buttons (JS-generated) ── */
    #detailsActions button {
        background-color: unset !important;
    }
    /* ── Pricing & Specification View Modals: clean buttons (preserve transparent / custom background) ── */
    #viewAdminServiceModal button,
    #viewAdminMerchModal button,
    #viewFuelModalAdmin button,
    #priceHistoryModal button,
    #viewAdminBatchesModal button,
    #adminViewProdModal button,
    #adminViewMovModal button,
    #adminViewSiModal button,
    #aef_pumps_container button,
    .btn-edit-pump-name {
        background-color: transparent !important;
        background: transparent !important;
    }
    /* Sub-tabs & Navigation tabs styling fix: ensure inactive tab text is crisp, clear, and visible with white background */
    .tab-btn:not(.active),
    .sub-tab-btn:not(.active),
    .inv-tab-btn:not(.active),
    .nav-tab:not(.active),
    .cust-tab:not(.active),
    button.tab-btn:not(.active),
    button.cust-tab:not(.active),
    #tabOverview:not(.active),
    #tabDeliveriesList:not(.active),
    #tabDeliveries:not(.active),
    #tab-overview:not(.active),
    #tab-stockin:not(.active),
    #tab-alerts:not(.active) {
        background: #ffffff !important;
        background-color: #ffffff !important;
        color: #334155 !important;
        border: 1px solid #cbd5e1 !important;
        font-weight: 600 !important;
        box-shadow: none !important;
    }
    .tab-btn.active,
    .sub-tab-btn.active,
    .inv-tab-btn.active,
    .nav-tab.active,
    .cust-tab.active,
    button.tab-btn.active,
    button.cust-tab.active,
    #tabOverview.active,
    #tabDeliveriesList.active,
    #tabDeliveries.active,
    #tab-overview.active,
    #tab-stockin.active,
    #tab-alerts.active {
        background: #002F70 !important;
        background-color: #002F70 !important;
        color: #ffffff !important;
        border: 1px solid #002F70 !important;
        font-weight: 700 !important;
    }
    .tab-btn.active *,
    .sub-tab-btn.active *,
    .inv-tab-btn.active *,
    .cust-tab.active * {
        color: #ffffff !important;
    }



    /* Reset & Secondary Filter Buttons styling fix: ensure clean white background with dark slate text */
    button.btn-secondary,
    .btn-secondary,
    button.btn-action.btn-secondary,
    .btn-action.btn-secondary,
    button.flt-btn-reset,
    .flt-btn-reset,
    button.btn-reset,
    .btn-reset {
        background: #ffffff !important;
        background-color: #ffffff !important;
        color: #334155 !important;
        border: 1px solid #cbd5e1 !important;
        font-weight: 600 !important;
        box-shadow: none !important;
    }
    button.btn-secondary:hover,
    .btn-secondary:hover,
    button.btn-action.btn-secondary:hover,
    .btn-action.btn-secondary:hover,
    button.flt-btn-reset:hover,
    .flt-btn-reset:hover,
    button.btn-reset:hover,
    .btn-reset:hover {
        background: #f8fafc !important;
        background-color: #f8fafc !important;
        border-color: #94a3b8 !important;
        color: #0f172a !important;
    }

    /* Filter dropdown buttons, triggers & transparent stock request buttons */

    button.fd-select-trigger,
    button.cdd-trigger,
    button.adm-cdd-btn,
    button.adm-cdd-trigger,
    button.sr-btn-outline,
    .sr-btn-outline,
    .fd-select-trigger,
    .cdd-trigger,
    .adm-cdd-btn,
    .adm-cdd-trigger,
    button.flt-btn:not(.flt-btn-solid-primary),
    button.tbl-btn,
    a.tbl-btn,
    .tbl-btn,
    .btn-filter-submit,
    .btn-filter-reset,
    .notif-header-actions button,
    #markAllReadBtn {
        background: transparent !important;
        background-color: transparent !important;
        border: 1px solid #cbd5e1 !important;
        color: #1e293b !important;
        font-weight: 600 !important;
        box-shadow: none !important;
    }
    .fd-select-label, .cdd-label, .adm-cdd-label {
        color: #1e293b !important;
        font-weight: 600 !important;
    }
    .fd-select-arrow, .cdd-arrow, .adm-cdd-arrow {
        color: #64748b !important;
    }
    .fd-select-trigger:hover, .cdd-trigger:hover, .adm-cdd-btn:hover, button.adm-cdd-btn:hover, .sr-btn-outline:hover {
        background: #f8fafc !important;
        background-color: #f8fafc !important;
        border-color: #94a3b8 !important;
    }




    <?php if ($theme_high_contrast): ?>
    body, html, div, p, span, table, td, th, a, button, input, select {
        filter: contrast(1.15) !important;
    }
    <?php endif; ?>
    
    /* Global FontAwesome Icon Visibility Fix */
    .fas, .far, .fab, .fa {
        opacity: 1 !important;
        visibility: visible !important;
        display: inline-block !important;
    }

    /* Ensure all icons in headers and cards are visible */
    .card-header i,
    .metric-icon i,
    .admin-metric-icon i,
    .stat-icon i,
    .btn i,
    button i,
    h3 i,
    h4 i {
        opacity: 1 !important;
        visibility: visible !important;
    }

    /* Petron Station Global Theme */
    :root {
        --petron-blue: #00264D;
        --petron-red: #CC0000;
        --petron-gray: #F2F2F2;
        --petron-yellow: #FFC107;
        --petron-white: #FFFFFF;
        --petron-muted: #666666;
        --petron-success: #28A745;
        --petron-warning: #FFC107;
        --petron-danger: #DC3545;
        --petron-info: #17A2B8;
        --primary: #00264D;
        --accent: #CC0000;
        --petron-green: #28A745;
        
        /* Light Theme (Default) */
        --bg-main: #f8f9fa;
        --bg-card: #ffffff;
        --text-main: #333333;
        --text-secondary: #666666;
        --border-color: #e0e0e0;
        --sidebar-bg: <?php echo htmlspecialchars($appearance_sidebar_color); ?>;
        --sidebar-text: #ffffff;
        --header-bg: #ffffff;
        --header-text: #00264D;
    }
    
    /* â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• 
       DARK THEME — Full proper dark mode
       â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â• â•  */

    /* Dark theme CSS variable overrides */
    body.dark-theme {
        --bg-main:        #0f1117;
        --bg-card:        #1e2130;
        --bg-card-hover:  #252840;
        --text-main:      #e2e8f0;
        --text-secondary: #94a3b8;
        --border-color:   #2d3748;
        --sidebar-bg:     #111827;
        --sidebar-text:   #cbd5e1;
        --header-bg:      #1a1f2e;
        --header-text:    #e2e8f0;
        --input-bg:       #252840;
        --input-border:   #3d4a5c;
        --input-text:     #e2e8f0;
        --table-header:   #1a2035;
        --table-row-odd:  #1e2130;
        --table-row-even: #22273a;
        --table-hover:    #2a3150;
        --shadow-dark:    0 4px 24px rgba(0,0,0,0.5);
    }

    /* â”€â”€ Body & Main â”€â”€ */
    body.dark-theme {
        background-color: var(--bg-main) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .main {
        background-color: var(--bg-main) !important;
    }

    /* â”€â”€ Top Header â”€â”€ */
    body.dark-theme .top-header {
        background-color: var(--header-bg) !important;
        border-bottom: 1px solid var(--border-color) !important;
        box-shadow: 0 2px 12px rgba(0,0,0,0.4) !important;
    }
    body.dark-theme .brand-title {
        color: #e2e8f0 !important;
    }
    body.dark-theme .top-header .brand-text div {
        color: #94a3b8 !important;
    }

    /* â”€â”€ Sidebar â”€â”€ */
    body.dark-theme .sidebar {
        background-color: var(--sidebar-bg) !important;
        border-right: 1px solid var(--border-color) !important;
    }
    body.dark-theme .nav-item {
        color: var(--sidebar-text) !important;
    }
    body.dark-theme .nav-item:hover {
        background: rgba(255,255,255,0.06) !important;
        color: #ffffff !important;
    }
    body.dark-theme .nav-item.active:not(.has-submenu),
    body.dark-theme .nav-item:active:not(.has-submenu),
    body.dark-theme .sidebar-sub-item.active,
    body.dark-theme .sidebar-sub-item:active,
    body.dark-theme .nav-item.is-nav-clicking:not(.has-submenu),
    body.dark-theme .sidebar-sub-item.is-nav-clicking {
        background: var(--nav-active-color, #E30613) !important;
        color: #ffffff !important;
    }
    body.dark-theme .has-submenu,
    body.dark-theme .has-submenu:active,
    body.dark-theme .has-submenu.active,
    body.dark-theme .has-submenu.is-nav-clicking {
        background-color: transparent !important;
    }
    body.dark-theme .sidebar-sub-item.active,
    body.dark-theme .sidebar-sub-item:active,
    body.dark-theme .sidebar-sub-item.is-nav-clicking {
        border-left: none !important;
    }
    body.dark-theme .sidebar-identity-footer {
        background: rgba(0,0,0,0.4) !important;
        border-top: 1px solid rgba(255,255,255,0.08) !important;
    }

    /* â”€â”€ Cards & Panels â”€â”€ */
    body.dark-theme .widget-card,
    body.dark-theme .card,
    body.dark-theme .petron-card,
    body.dark-theme .panel,
    body.dark-theme .pcard,
    body.dark-theme .mini {
        background-color: var(--bg-card) !important;
        color: var(--text-main) !important;
        border-color: var(--border-color) !important;
        box-shadow: var(--shadow-dark) !important;
    }
    body.dark-theme .widget-card h1,
    body.dark-theme .widget-card h2,
    body.dark-theme .widget-card h3,
    body.dark-theme .widget-card h4,
    body.dark-theme .widget-card h5,
    body.dark-theme .card h1,
    body.dark-theme .card h2,
    body.dark-theme .card h3,
    body.dark-theme .card h4,
    body.dark-theme .card h5,
    body.dark-theme .petron-card h1,
    body.dark-theme .petron-card h2,
    body.dark-theme .petron-card h3,
    body.dark-theme .petron-card h4 {
        color: #93c5fd !important;
    }
    body.dark-theme .status-card,
    body.dark-theme .metric-card,
    body.dark-theme .quick-action-btn,
    body.dark-theme .report-btn {
        background-color: var(--bg-card) !important;
        color: var(--text-main) !important;
        border-color: var(--border-color) !important;
    }

    /* Card-header sections */
    body.dark-theme .card-header,
    body.dark-theme .petron-card-header {
        background-color: var(--table-header) !important;
        border-bottom: 1px solid var(--border-color) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .card-body {
        background-color: var(--bg-card) !important;
        color: var(--text-main) !important;
    }

    /* â”€â”€ Tables â”€â”€ */
    body.dark-theme table,
    body.dark-theme .table {
        background-color: var(--bg-card) !important;
        color: var(--text-main) !important;
        border-color: var(--border-color) !important;
    }
    body.dark-theme table thead th,
    body.dark-theme .table thead th {
        background-color: var(--table-header) !important;
        color: #93c5fd !important;
        border-color: var(--border-color) !important;
    }
    /* Report-specific table headers visible in dark mode */
    body.dark-theme .sr-table thead th,
    body.dark-theme .sr-tbl thead th,
    body.dark-theme .rpt-table thead th,
    body.dark-theme .pr-tbl thead th {
        background-color: var(--table-header) !important;
        color: #93c5fd !important;
        border-color: var(--border-color) !important;
    }
    body.dark-theme table tbody tr:nth-child(odd) td,
    body.dark-theme .table tbody tr:nth-child(odd) td {
        background-color: var(--table-row-odd) !important;
        color: var(--text-main) !important;
        border-color: var(--border-color) !important;
    }
    body.dark-theme table tbody tr:nth-child(even) td,
    body.dark-theme .table tbody tr:nth-child(even) td {
        background-color: var(--table-row-even) !important;
        color: var(--text-main) !important;
        border-color: var(--border-color) !important;
    }
    body.dark-theme table tbody tr:hover td,
    body.dark-theme .table tbody tr:hover td {
        background-color: var(--table-hover) !important;
    }
    body.dark-theme table td,
    body.dark-theme table th {
        border-color: var(--border-color) !important;
    }

    /* â”€â”€ Inputs, Selects, Textareas â”€â”€ */
    body.dark-theme input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="button"]):not([type="reset"]),
    body.dark-theme select,
    body.dark-theme textarea {
        background-color: var(--input-bg) !important;
        color: var(--input-text) !important;
        border-color: var(--input-border) !important;
    }
    body.dark-theme input::placeholder,
    body.dark-theme textarea::placeholder {
        color: #64748b !important;
    }
    body.dark-theme select option {
        background-color: #252840;
        color: #e2e8f0;
    }
    /* Rows-per-page select — transparent background, native arrow always visible */
    body.dark-theme select.rows-select,
    select.rows-select {
        background-color: transparent !important;
        border-color: #cbd5e1 !important;
        appearance: auto !important;
        -webkit-appearance: auto !important;
    }
    body.dark-theme .form-control,
    body.dark-theme .form-select {
        background-color: var(--input-bg) !important;
        color: var(--input-text) !important;
        border-color: var(--input-border) !important;
    }
    body.dark-theme .form-label,
    body.dark-theme label {
        color: var(--text-secondary) !important;
    }

    /* â”€â”€ Buttons â”€â”€ */
    body.dark-theme .btn-light,
    body.dark-theme .btn-outline-secondary,
    body.dark-theme .btn-secondary {
        background-color: #2d3748 !important;
        color: #e2e8f0 !important;
        border-color: #3d4a5c !important;
    }
    body.dark-theme .btn-light:hover,
    body.dark-theme .btn-outline-secondary:hover {
        background-color: #3d4a5c !important;
    }
    /* Keep primary/danger/success buttons their original colors */
    body.dark-theme .btn-primary  { background-color: #2563eb !important; border-color: #1d4ed8 !important; color: #fff !important; }
    body.dark-theme .btn-danger   { background-color: #dc2626 !important; border-color: #b91c1c !important; color: #fff !important; }
    body.dark-theme .btn-success  { background-color: #16a34a !important; border-color: #15803d !important; color: #fff !important; }
    body.dark-theme .btn-warning  { background-color: #d97706 !important; border-color: #b45309 !important; color: #fff !important; }
    body.dark-theme .btn-info     { background-color: #0891b2 !important; border-color: #0e7490 !important; color: #fff !important; }

    /* custom petron buttons — keep them as-is or slightly darken */
    body.dark-theme .cust-btn {
        background: #252840 !important;
        color: #e2e8f0 !important;
        border-color: #3d4a5c !important;
    }

    /* â”€â”€ Header icons & controls â”€â”€ */
    body.dark-theme .notification-bell {
        background: rgba(255,255,255,0.07) !important;
    }
    body.dark-theme .notification-bell:hover { background: rgba(255,255,255,0.14) !important; }
    body.dark-theme .notification-bell i,
    body.dark-theme .theme-toggle-btn i { color: #e2e8f0 !important; }
    body.dark-theme .theme-toggle-btn {
        background: rgba(255,255,255,0.07) !important;
    }
    body.dark-theme .theme-toggle-btn:hover { background: rgba(255,255,255,0.14) !important; }

    /* â”€â”€ Profile area in header â”€â”€ */
    body.dark-theme .profile-access { color: var(--text-main) !important; }
    body.dark-theme .profile-access > div > div:first-child { color: #93c5fd !important; }
    body.dark-theme .profile-access > div > div:last-child  { color: #64748b !important; }

    /* â”€â”€ Dropdowns â”€â”€ */
    body.dark-theme .notif-dropdown,
    body.dark-theme .profile-dropdown {
        background-color: #1e2130 !important;
        border: 1px solid var(--border-color) !important;
        box-shadow: 0 8px 32px rgba(0,0,0,0.6) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .notif-dropdown-header {
        background: linear-gradient(135deg, #1a2035 0%, #1e2130 100%) !important;
        border-bottom: 1px solid var(--border-color) !important;
        color: #93c5fd !important;
    }
    body.dark-theme .notif-dropdown-header span,
    body.dark-theme .notif-dropdown-header button { color: #93c5fd !important; }
    body.dark-theme .notif-header-actions button:hover { background: rgba(255,255,255,0.08) !important; color: #ffffff !important; }
    body.dark-theme .notif-item {
        border-bottom: 1px solid var(--border-color) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .notif-item:hover { background-color: #252840 !important; }
    body.dark-theme .profile-dropdown a {
        color: var(--text-main) !important;
    }
    body.dark-theme .profile-dropdown a:hover {
        background-color: #252840 !important;
        color: #93c5fd !important;
    }
    body.dark-theme .dropdown-divider {
        border-color: var(--border-color) !important;
    }

    /* â”€â”€ Footer â”€â”€ */
    body.dark-theme .fixed-footer {
        background-color: #1a1f2e !important;
        border-top: 1px solid var(--border-color) !important;
        color: var(--text-secondary) !important;
    }
    body.dark-theme .footer-sidebar-area {
        background-color: #111827 !important;
        color: var(--text-secondary) !important;
     }
    body.dark-theme .footer-text,
    body.dark-theme .footer-clock { color: var(--text-secondary) !important; }
    body.dark-theme .footer-identity { color: #93c5fd !important; }

    /* â”€â”€ Welcome Banner / Page headers â”€â”€ */
    body.dark-theme .welcome-banner {
        background: linear-gradient(135deg, #1e2130 0%, #1a2035 100%) !important;
        border-color: var(--border-color) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .welcome-banner h2,
    body.dark-theme .welcome-banner h3,
    body.dark-theme .welcome-banner p { color: var(--text-main) !important; }
    body.dark-theme .page-head h1,
    body.dark-theme .page-header h1,
    body.dark-theme .page-title { color: #93c5fd !important; }
    body.dark-theme .page-subtitle { color: var(--text-secondary) !important; }

    /* â”€â”€ Generic text overrides â”€â”€ */
    body.dark-theme h1, body.dark-theme h2, body.dark-theme h3,
    body.dark-theme h4, body.dark-theme h5, body.dark-theme h6 {
        color: var(--text-main) !important;
    }
    body.dark-theme p, body.dark-theme span, body.dark-theme li,
    body.dark-theme td, body.dark-theme th { color: var(--text-main); }
    body.dark-theme a:not(.btn):not(.nav-item):not(.sidebar-sub-item) { color: #60a5fa !important; }
    body.dark-theme a:not(.btn):not(.nav-item):not(.sidebar-sub-item):hover { color: #93c5fd !important; }

    /* â”€â”€ Flash messages — keep original alert colors but on dark bg â”€â”€ */
    body.dark-theme .petron-toast {
        background: #111827 !important;
        border-color: #263449 !important;
        color: #e5e7eb !important;
    }
    body.dark-theme .petron-toast-message { color: #d1d5db !important; }
    body.dark-theme .petron-flash {
        background: #111827 !important;
        border-color: #263449 !important;
        color: #e5e7eb !important;
    }
    body.dark-theme .petron-flash span { color: #d1d5db !important; }

    /* â”€â”€ Modals â”€â”€ */
    body.dark-theme .modal-card,
    body.dark-theme .modal-card-wide,
    body.dark-theme .modal-card-xl {
        background-color: var(--bg-card) !important;
        border-color: var(--border-color) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .modal-head {
        background-color: var(--table-header) !important;
        border-bottom: 1px solid var(--border-color) !important;
        color: #93c5fd !important;
    }
    body.dark-theme .modal-actions {
        background-color: var(--bg-card) !important;
        border-top: 1px solid var(--border-color) !important;
    }
    body.dark-theme .modal-backdrop,
    body.dark-theme .modal-overlay { background: rgba(0,0,0,0.75) !important; }

    /* â”€â”€ Badges â”€â”€ */
    body.dark-theme .badge:not([style*="background"]) {
        background-color: #252840 !important;
        color: #e2e8f0 !important;
        border-color: var(--border-color) !important;
    }
    body.dark-theme .sbadge { opacity: 0.9 !important; }

    /* â”€â”€ Scrollbars in dark mode â”€â”€ */
    body.dark-theme ::-webkit-scrollbar { width: 6px; height: 6px; }
    body.dark-theme ::-webkit-scrollbar-track { background: #0f1117; }
    body.dark-theme ::-webkit-scrollbar-thumb { background: #3d4a5c; border-radius: 3px; }
    body.dark-theme ::-webkit-scrollbar-thumb:hover { background: #4d5a6c; }

    /* â”€â”€ Misc overrides â”€â”€ */
    body.dark-theme hr                      { border-color: var(--border-color) !important; }
    body.dark-theme .text-muted             { color: #64748b !important; }
    body.dark-theme .border                 { border-color: var(--border-color) !important; }
    body.dark-theme .bg-white               { background-color: var(--bg-card) !important; }
    body.dark-theme .bg-light               { background-color: #252840 !important; }
    body.dark-theme .text-dark              { color: var(--text-main) !important; }
    body.dark-theme .shadow, body.dark-theme .shadow-sm { box-shadow: var(--shadow-dark) !important; }
    body.dark-theme .list-group-item {
        background-color: var(--bg-card) !important;
        border-color: var(--border-color) !important;
        color: var(--text-main) !important;
    }
    body.dark-theme .list-group-item:hover  { background-color: var(--bg-card-hover) !important; }

    /* Page content wrappers */
    body.dark-theme .page-content,
    body.dark-theme .content-wrapper,
    body.dark-theme .section-wrapper,
    body.dark-theme .petron-section {
        background-color: var(--bg-main) !important;
        color: var(--text-main) !important;
    }

    /* Search bar on header in dark mode */
    body.dark-theme #searchInput {
        background: rgba(255,255,255,0.08) !important;
        border-color: rgba(255,255,255,0.15) !important;
        color: #e2e8f0 !important;
    }
    body.dark-theme #searchInput::placeholder { color: #64748b !important; }
    body.dark-theme #searchInput:focus {
        background: rgba(255,255,255,0.12) !important;
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.2) !important;
    }
    body.dark-theme #searchSuggestions {
        background: #1e2130 !important;
        border: 1px solid var(--border-color) !important;
        box-shadow: 0 8px 32px rgba(0,0,0,0.6) !important;
        color: var(--text-main) !important;
    }

    /* Toggle scroll button in dark mode */
    body.dark-theme .toggle-scroll-btn {
        background: #2563eb !important;
        border-color: #1e2130 !important;
    }
    
    /* Apply theme variables to elements — with smooth transitions */
    body {
        background-color: var(--bg-main);
        color: var(--text-main);
        transition: background-color 0.3s ease, color 0.3s ease;
    }
    .top-header,
    .sidebar,
    .main,
    .fixed-footer,
    .footer-sidebar-area,
    .widget-card, .card, .petron-card {
        transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    }
    html, body { max-width: 100vw; overflow-x: hidden; } /* Sidebar Navigation */
    /* Base variable-driven colours for all major surfaces */
    .top-header {
        background-color: var(--header-bg);
        color: var(--header-text);
    }
    .sidebar {
        background-color: var(--sidebar-bg) !important;
        color: var(--sidebar-text) !important;
    }
    .main {
        background-color: var(--bg-main);
    }
    .widget-card, .card, .petron-card {
        background-color: var(--bg-card);
        color: var(--text-main);
        border-color: var(--border-color);
    }
    
    /* Desktop Sidebar Layout (Header + Sidebar Integration, Fixed Footer) */
    @media (min-width: 992px) {
        html, body { 
            overflow: hidden !important;
            height: 100% !important;
            pointer-events: auto !important;
        }

        .top-header {
            position: fixed;
            top: 0; left: 0;
            width: 100%;
            height: 70px;
            z-index: 12002;
            background-color: var(--header-bg);
            padding: 0 32px; /* Balanced padding matching main layout (32px) */
            display: flex;
            justify-content: space-between;
            align-items: center;
            pointer-events: auto !important;
        }

        .sidebar {
            display: flex !important;
            flex-direction: column !important;
            position: fixed !important;
            top: 70px !important;
            left: 0 !important;
            bottom: 40px !important; /* height of fixed footer */
            height: calc(100vh - 110px) !important; /* 70px top + 40px bottom = 110px */
            width: 250px !important;
            z-index: 1001 !important;
            overflow: hidden !important;
            background: var(--sidebar-bg) !important;
            border-right: 1px solid var(--line) !important;
            transition: width 0.3s ease !important;
            pointer-events: auto !important;
        }
        .sidebar-menu { 
            flex: 1 1 auto; 
            overflow-y: auto;
            overflow-x: hidden;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding-top: 8px;
            padding-bottom: 8px;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
            pointer-events: auto !important;
        }
        .sidebar::-webkit-scrollbar,
        .sidebar-menu::-webkit-scrollbar,
        .sidebar-menu *::-webkit-scrollbar {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
            background: transparent !important;
        } 

        /* â”€â”€ Sidebar Identity Footer â”€â”€ */
        .sidebar-identity-footer {
            position: relative !important;
            bottom: auto !important;
            left: auto !important;
            width: 100% !important;
            height: 70px !important;
            flex-shrink: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 3px !important;
            padding: 6px 12px !important;
            background: rgba(0,0,0,0.35) !important;
            border-top: 1px solid rgba(255,255,255,0.12) !important;
            overflow: hidden !important;
            cursor: default !important;
            user-select: none !important;
            z-index: auto !important;
            box-sizing: border-box !important;
            transition: width 0.3s ease !important;
            text-align: center !important;
        }

        /* sidebar-menu fills remaining space, footer sits below it naturally. Increased padding so last item is fully visible. */
        .sidebar-menu {
            padding-bottom: 80px !important;
        }

        /* Avatar circle — centered above name */
        .sif-avatar {
            flex-shrink: 0 !important;
            width: 30px !important;
            height: 30px !important;
            border-radius: 50% !important;
            border: 1.5px solid rgba(255,255,255,0.4) !important;
            background: rgba(255,255,255,0.15) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            overflow: hidden !important;
            font-size: 15px !important;
            color: rgba(255,255,255,0.85) !important;
        }

        .sif-avatar img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            border-radius: 50% !important;
            display: block !important;
        }

        /* Name line: FIRSTNAME LASTNAME */
        .sif-name {
            font-size: 10.5px !important;
            font-weight: 800 !important;
            color: #ffffff !important;
            letter-spacing: 0.4px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.2 !important;
            max-width: 100% !important;
            display: block !important;
            text-align: center !important;
        }

        /* Separator dash + role on same line */
        .sif-role-line {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 4px !important;
            max-width: 100% !important;
            overflow: hidden !important;
        }

        .sif-dash {
            color: rgba(255,255,255,0.35) !important;
            font-size: 9px !important;
            flex-shrink: 0 !important;
        }

        .sif-role {
            font-size: 9px !important;
            font-weight: 700 !important;
            color: rgba(255,255,255,0.55) !important;
            letter-spacing: 0.8px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.2 !important;
            text-transform: uppercase !important;
        }

        /* Collapsed: show only avatar centered, hide text */
        body.sidebar-collapsed .sidebar-identity-footer {
            width: 100% !important;
            justify-content: center !important;
            padding: 0 !important;
        }        body.sidebar-collapsed .sif-name,
        body.sidebar-collapsed .sif-role-line {
            display: none !important;
        }
        body.sidebar-collapsed .sif-avatar {
            width: 34px !important;
            height: 34px !important;
            font-size: 18px !important;
        }

        /* Sub-menus use normal document flow — they expand inline, not floating */
        .sidebar-menu > nav > div > div[id^="sub-"] {
            position: relative !important;
            z-index: 1002 !important;
            overflow: visible !important;
        }

        /* Reset nav element default spacing */
        .sidebar-menu nav.nav {
            margin: 0;
            padding: 0;
            display: block;
            gap: 0;
        }

        /* Sidebar Collapsed State */
        .sidebar.collapsed {
            width: 70px; /* Icon-only width */
        }

        .sidebar.collapsed .nav-item span:not(.ico) {
            display: none !important; /* Hide text labels */
        }

        .sidebar.collapsed .nav-item {
            justify-content: center !important; /* Center icons */
            padding: 12px 8px !important;
        }

        .sidebar.collapsed .nav-item .ico {
            margin-right: 0 !important; /* Remove icon margin */
        }

        .sidebar.collapsed .nav-item .badge,
        .sidebar.collapsed .nav-item span[style*="background:#E30613"] {
            display: none !important; /* Hide badges in collapsed state */
        }

        .sidebar.collapsed .nav-item i.fa-chevron-down {
            display: none !important; /* Hide chevron dropdown icon in collapsed state */
        }

        .sidebar.collapsed [id^="sub-"] {
            display: none !important; /* Hide submenu items in collapsed state */
        }


        /* Main content adjustments — bottom:0 so scroll area fills full viewport,
           padding-bottom clears the fixed footer (40px) with extra breathing room */
        .main {
            position: fixed;
            top: 70px;
            left: 250px;
            right: 0;
            bottom: 0 !important;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px 20px 105px 20px !important;
            background: #f8f9fa;
            transition: left 0.3s ease;
            pointer-events: auto !important;
            z-index: 1 !important;
        }

        /* Ensure clean bottom clearance for full scrolling across all reports & pages */
        .main > .container,
        .main > .main-content,
        .main > .reports-wrapper,
        .main > .stock-page,
        .main > .print-area,
        .main .table-responsive,
        .stock-page,
        .print-area {
            margin-bottom: 25px;
        }

        /* Calendar pages bottom room so the last grid row clears the footer */
        body[data-page="calendar"] .main {
            padding-bottom: 110px !important;
        }
        
        /* Ensure all main content children are clickable */
        .main * {
            pointer-events: auto !important;
        }

        /* Hide client-side rows-per-page pagination bars system-wide so all rows render continuously */
        .petron-pagination-bar,
        .pagination-wrapper,
        .client-side-pagination,
        .client-pagination,
        .rows-per-page,
        .rows-select,
        .petron-rows-select-wrap {
            display: none !important;
        }

        /* Hide report signatures on web view — show ONLY when printing */
        .print-only-sig {
            display: none !important;
        }
        @media print {
            .print-only-sig,
            .sfss-print-only .print-only-sig {
                display: table !important;
                width: 100% !important;
                margin-top: 30px !important;
                border: none !important;
                background: transparent !important;
            }
            .print-only-sig td,
            .sfss-print-only .print-only-sig td {
                border: none !important;
                background: transparent !important;
            }
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 991px) {
            .main {
                left: 0 !important;
            }
        }

        .main.sidebar-collapsed {
            left: 70px;
        }
        
        /* Sidebar state classes for main content alignment */
        body.sidebar-expanded .main {
            left: 250px !important;
        }
        
        body.sidebar-collapsed .main {
            left: 70px !important;
        }
    }

    /* â”€â”€ Mobile Layout (screens < 992px) â”€â”€ */
    @media (max-width: 991px) {
        body { 
            overflow-x: hidden !important;
            overflow-y: auto !important;
            pointer-events: auto !important;
        }

        /* Top header sticks to top on mobile */
        .top-header {
            position: fixed;
            top: 0; left: 0;
            width: 100%;
            height: 60px;
            z-index: 1002;
            background-color: var(--header-bg, #ffffff);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
        }

        /* Main content pushed down by header height — full viewport height, footer padding clears the fixed bar */
        .main {
            position: fixed !important;
            top: 60px !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding: 0 16px 105px 16px !important;
            background: var(--bg-main, #f8f9fa);
            pointer-events: auto !important;
            z-index: 1 !important;
        }
        
        /* Ensure all main content children are clickable on mobile */
        .main * {
            pointer-events: auto !important;
        }

        /* Sidebar hidden off-screen by default */
        .sidebar {
            position: fixed !important;
            top: 60px !important;
            left: -260px !important;
            bottom: 40px !important; /* height of fixed footer */
            height: calc(100vh - 100px) !important; /* 60px top + 40px bottom = 100px */
            width: 250px !important;
            z-index: 1100 !important;
            overflow-y: auto !important;
            transition: left 0.3s ease !important;
            background: var(--sidebar-bg, #002F70) !important;
            box-shadow: 4px 0 16px rgba(0,0,0,0.25) !important;
        }

        /* Sidebar open state (toggled via JS) */
        .sidebar.mobile-open {
            left: 0 !important;
        }

        /* Backdrop overlay when sidebar is open */
        .mobile-sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1099;
        }
        .mobile-sidebar-backdrop.active {
            display: block;
        }

        /* Show hamburger button visibly on mobile */
        .sidebar-collapse-btn {
            display: flex !important;
            width: 36px !important;
            height: 36px !important;
            font-size: 0 !important;
        }
        .sidebar-collapse-btn i {
            font-size: 18px !important;
            color: var(--petron-blue, #002F70) !important;
        }

        /* Hide search bar on very small screens */
        .header-center { display: none !important; }

        /* Shrink brand text on mobile */
        .brand-title { font-size: 0.95em !important; }
        .brand-mark { max-width: 48px !important; height: 38px !important; }
    }

    .brand-title { color: var(--petron-blue) !important; font-weight: bold; font-size: 1.3em; line-height: 1.1; }
    .brand-mark {
        width: auto; max-width: 60px; height: 48px;
        margin-right: 12px;
        object-fit: contain;
    }

    /* Page Header Styles - UPPERCASE for All Roles (Super Admin, Admin, Manager, Staff) */
    .page-head, .page-header {
        margin-top: 0 !important;
        margin-bottom: 24px !important;
    }
    
    .page-head h1, .page-header h1,
    .page-head .h1, .page-header .h1,
    .page-title, .page-head .page-title, .page-header .page-title {
        text-transform: uppercase !important;
        font-size: 24px !important;
        font-weight: 700 !important;
        color: var(--petron-blue) !important;
        margin: 0 0 8px 0 !important;
        letter-spacing: 0.5px !important;
    }
    
    .page-subtitle, .page-head .sub, .page-header .page-subtitle {
        text-transform: uppercase !important;
        font-size: 14px !important;
        font-weight: 500 !important;
        color: #666666 !important;
        margin: 0 !important;
        letter-spacing: 0.3px !important;
    }
    
    /* Additional header elements to ensure consistency */
    h1, h2, h3 {
        text-transform: uppercase !important;
        color: var(--petron-blue) !important;
        letter-spacing: 0.3px !important;
    }
    
    h1 {
        font-size: 24px !important;
        font-weight: 700 !important;
    }
    
    h2 {
        font-size: 20px !important;
        font-weight: 600 !important;
    }
    
    h3 {
        font-size: 18px !important;
        font-weight: 600 !important;
    }

    .nav-item { color: #eeeeee !important; transition: all 0.2s; display: flex; align-items: center; justify-content: flex-start; padding: 10px 14px !important; text-decoration: none; min-height: 44px; height: auto !important; font-size: 15px !important; font-weight: 600 !important; pointer-events: auto !important; cursor: pointer !important; position: relative !important; z-index: 10 !important; }
    .nav-item:hover { background-color: rgba(255,255,255,0.12) !important; color: #ffffff !important; font-size: 15px !important; font-weight: 600 !important; }
    .nav-item.active:not(.has-submenu),
    .nav-item:active:not(.has-submenu),
    .nav-item.is-nav-clicking:not(.has-submenu) { background-color: var(--petron-red) !important; color: #ffffff !important; font-size: 15px !important; font-weight: 600 !important; }
    .nav-item span { font-size: 15px !important; font-weight: 600 !important; white-space: normal !important; word-break: normal !important; text-overflow: clip !important; }
    .nav-item.active:not(.has-submenu) span,
    .nav-item:active:not(.has-submenu) span,
    .nav-item.is-nav-clicking:not(.has-submenu) span { font-size: 15px !important; font-weight: 600 !important; white-space: normal !important; color: #ffffff !important; }
    .has-submenu,
    .has-submenu:active,
    .has-submenu.active,
    .has-submenu.is-nav-clicking { background-color: transparent !important; }
    .sidebar-sub-item { font-size: 15px !important; font-weight: 600 !important; color: #e2e8f0 !important; text-decoration: none !important; padding: 7px 14px 7px 40px !important; height: auto !important; min-height: 36px !important; transition: all 0.2s; }
    .sidebar-sub-item span { font-size: 15px !important; font-weight: 600 !important; white-space: normal !important; word-break: normal !important; text-overflow: clip !important; }
    .sidebar-sub-item span:not(.ico) { font-size: 15px !important; font-weight: 600 !important; white-space: normal !important; word-break: normal !important; overflow-wrap: break-word !important; overflow: visible !important; text-overflow: clip !important; color: #e2e8f0 !important; text-decoration: none !important; line-height: 1.35 !important; }
    .sidebar-sub-item:hover { background-color: rgba(255,255,255,0.12) !important; color: #ffffff !important; text-decoration: none !important; }
    .sidebar-sub-item:hover span:not(.ico) { color: #ffffff !important; }
    .sidebar-sub-item.active,
    .sidebar-sub-item:active,
    .sidebar-sub-item.is-nav-clicking { background-color: var(--petron-red) !important; color: #ffffff !important; border-left: none !important; text-decoration: none !important; }
    .sidebar-sub-item.active span,
    .sidebar-sub-item:active span,
    .sidebar-sub-item.is-nav-clicking span,
    .sidebar-sub-item.active span:not(.ico),
    .sidebar-sub-item:active span:not(.ico),
    .sidebar-sub-item.is-nav-clicking span:not(.ico) { color: #ffffff !important; font-weight: 700 !important; }


    
    .nav-item .ico {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        margin-right: 12px;
        flex-shrink: 0;
    }
    
    .nav-item .ico i {
        font-size: 17px;
        text-align: center;
        line-height: 1;
    }
    
    .nav-item span:not(.ico),
    .nav-item span.nav-label {
        font-size: 15px !important;
        font-weight: 600 !important;
        line-height: 1.25 !important;
        white-space: normal !important;
        word-break: normal !important;
        overflow-wrap: break-word !important;
        overflow: visible !important;
        text-overflow: clip !important;
    }
    
    .nav-item-wrapper {
        margin: 0;
        padding: 0;
        border: none;
    }
    
    .sidebar-collapse-btn {
        width: 32px;
        height: 32px;
        border-radius: 4px;
        font-size: 14px;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    
    /* Notifications Dropdown */
    .notification-bell { 
        position: relative; 
        cursor: pointer; 
        color: var(--petron-blue); 
        font-size: 1.2rem; 
        padding: 8px; 
        display: inline-block;
        border-radius: 50%;
        transition: all 0.2s ease;
    }
    .notification-bell:hover {
        background: rgba(0, 47, 112, 0.1);
        transform: scale(1.05);
    }
    .notification-bell .badge { 
        position: absolute; 
        top: -5px; 
        right: -7px; 
        background: #dc2626 !important; 
        color: white !important; 
        border-radius: 9999px !important; 
        width: auto !important; 
        height: 18px !important; 
        min-width: 18px !important; 
        padding: 2px 5px !important; 
        box-sizing: border-box !important; 
        display: inline-flex !important; 
        align-items: center !important; 
        justify-content: center !important; 
        font-size: 10.5px !important; 
        font-weight: 700 !important; 
        line-height: 1 !important; 
        letter-spacing: normal !important; 
        white-space: nowrap !important; 
        text-align: center; 
        border: 2px solid var(--header-bg, #ffffff) !important;
        box-shadow: 0 1px 4px rgba(0,0,0,0.25);
        z-index: 1001;
    }
    
        .notif-dropdown {
        position: absolute;
        top: 100%;
        right: 0;
        background: white;
        border-radius: 12px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.12);
        width: 380px !important;
        min-width: 380px !important;
        max-width: 380px !important;
        z-index: 9999;
        border: 1px solid rgba(0,0,0,0.08);
        margin-top: 12px;
        display: none;
        transform-origin: top right;
    }
    .notif-dropdown.show { 
        display: block !important;
        animation: dropdownSlide 0.2s ease-out;
    }
    
    @keyframes dropdownSlide {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .notif-dropdown-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 24px;
        border-bottom: 1px solid #f0f0f0;
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border-radius: 12px 12px 0 0;
        min-height: 60px;
        position: relative;
        z-index: 10;
    }
    
    .notif-dropdown-header span {
        font-weight: 700;
        color: #1a1a1a;
        font-size: 16px;
        text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        letter-spacing: 0.5px;
    }
    
    .notif-header-actions {
        display: flex;
        gap: 12px;
        align-items: center;
    }
    
    .notif-header-actions button {
        font-size: 12px;
        color: #002f70 !important;
        background: none !important;
        border: none !important;
        box-shadow: none !important;
        cursor: pointer;
        padding: 6px 10px;
        border-radius: 6px;
        transition: all 0.2s ease;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .notif-header-actions button:hover {
        background: rgba(0, 47, 112, 0.1) !important;
        color: #00449e !important;
        transform: translateY(-1px);
    }
    
    .notif-header-actions button i {
        font-size: 14px;
    }
    
    .notif-dropdown-footer {
        border-top: 1px solid #f0f0f0;
        background: #f8f9fa;
        border-radius: 0 0 12px 12px;
        padding: 12px;
        text-align: center;
    }
    
    .notif-dropdown-footer a {
        color: #002f70;
        text-decoration: none;
        font-weight: 600;
        font-size: 13px;
        transition: color 0.2s ease;
    }
    
    .notif-dropdown-footer a:hover {
        color: #00449e;
    }
    
    .notif-empty {
        padding: 50px 20px;
        text-align: center;
        color: #999;
    }
    
    .notif-empty i {
        font-size: 32px;
        margin-bottom: 12px;
        color: #ddd;
    }
    
    .notif-item {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 16px 24px;
        border-bottom: 1px solid #f5f5f5;
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
    }
    
    .notif-item:hover {
        background: #f8f9fa;
        transform: translateX(2px);
    }
    
    .notif-item.unread {
        background: #f8fafc;
        border-left: none !important;
        font-weight: 600;
    }
    
    .notif-item.unread::before,
    .notif-item::before {
        display: none !important;
        content: none !important;
    }
    
    .notif-item.read {
        background: #ffffff;
        opacity: 0.8;
    }
    
    .notif-item.read .notif-title {
        font-weight: 500;
        color: #666;
    }
    
    .notif-item.read .notif-message {
        color: #999;
    }
    
    .notif-icon {
        margin-right: 14px;
        flex-shrink: 0;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f0f0f0;
    }
    
    .notif-content {
        flex: 1;
        min-width: 0;
    }
    
    .notif-title {
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 4px;
        line-height: 1.3;
        font-size: 14px;
    }
    
    .notif-message {
        color: #666;
        font-size: 13px;
        line-height: 1.4;
        margin-bottom: 6px;
    }
    
    .notif-time {
        color: #999;
        font-size: 11px;
        font-weight: 500;
    }
    
    .notif-status {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #002f70;
        margin-left: 12px;
        flex-shrink: 0;
        margin-top: 6px;
    }
    
    .notif-status.read {
        background: transparent;
    }
    
    .notif-item.unread::before {
        display: none !important;
        content: none !important;
    }
    
    .notif-icon {
        flex-shrink: 0;
    }
    
    .notif-type-icon {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: bold;
        color: white;
    }
    
    .notif-type-icon i {
        font-size: 12px;
        margin: 0;
        padding: 0;
        line-height: 1;
    }
    
    .notif-type-icon.icon-success {
        background: #16a34a;
    }
    
    .notif-type-icon.icon-warning {
        background: #d97706;
    }
    
    .notif-type-icon.icon-error {
        background: #dc2626;
    }
    
    .notif-type-icon.icon-info {
        background: #2563eb;
    }
    
    .notif-content {
        flex: 1;
        min-width: 0;
    }
    
    .notif-title {
        font-size: 13px;
        font-weight: 500;
        color: #333;
        line-height: 1.3;
        margin-bottom: 2px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    .notif-time {
        font-size: 11px;
        color: #888;
    }
    
    .notif-status {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    
    .notif-status.unread {
        background: #2563eb;
    }
    
    .notif-status.read {
        background: transparent;
    }

    /* Top Header */
    .top-header {
        display: flex;
        align-items: center;
        background-color: #ffffff;
        padding: 0 20px;  /* Equal left and right padding */
        overflow: visible;  /* Ensure nothing gets clipped */
    }
    .header-left {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
        position: relative;
        z-index: 10;
        pointer-events: auto;
    }
    .header-center {
        flex-grow: 1;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 0 5px;  /* Minimal padding */
        position: relative;
        z-index: 5;
        pointer-events: none;  /* Prevent center area from blocking header icon clicks */
        min-width: 0;
    }
    /* Collapse header-center when empty (no search bar rendered for this role) */
    .header-center:not(:has(#searchWrapper)) {
        flex-grow: 0;
        padding: 0;
        width: 0;
    }
    .header-right {
        display: flex;
        align-items: center;
        gap: 16px;  /* Increased gap for comfortable spacing */
        flex-shrink: 0;
        padding-right: 0;  /* No extra padding - balanced with left */
        overflow: visible;  /* Ensure content not clipped */
        position: relative;
        z-index: 10;
        pointer-events: auto;
    }
    .profile-access {
        position: relative;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        color: var(--petron-blue);
        padding-right: 0;  /* Removed extra padding */
        flex-shrink: 0;  /* Don't let it shrink */
        min-width: fit-content;  /* Ensure it doesn't compress */
    }
    .profile-dropdown {
        display: none;
        position: absolute;
        right: 0;
        top: 100%;
        background: #fff;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        border-radius: 4px;
        min-width: 180px;
        z-index: 1100;
        border: 1px solid #eee;
        margin-top: 5px;
    }
    .profile-dropdown a {
        display: block;
        padding: 10px 15px;
        text-decoration: none;
        color: #333;
        font-size: 14px;
    }
    .profile-dropdown a:hover {
        background: #f9f9f9;
        color: var(--petron-blue);
    }
    .profile-dropdown.show { display: block !important; }
    .profile-dropdown .dropdown-divider { height: 1px; margin: .5rem 0; overflow: hidden; background-color: #e9ecef; }

    /* Settings Icon */
    .settings-icon {
        color: var(--petron-blue);
        font-size: 1.2rem;
        text-decoration: none;
        cursor: pointer;
        transition: color 0.2s;
    }
    .settings-icon:hover {
        color: var(--petron-red);
    }

    /* Sidebar Toggle Button */
    .sidebar-toggle {
        background: rgba(255, 255, 255, 0.1);
        border: none;
        color: #ffffff;
        font-size: 1.2rem;
        cursor: pointer;
        padding: 8px 12px;
        border-radius: 8px;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0;
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
    }

    .sidebar-toggle:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: translateY(-50%) scale(1.05);
    }

    .sidebar-toggle:active {
        transform: translateY(-50%) scale(0.95);
    }

    /* Collapsed state adjustments */
    .sidebar.collapsed .sidebar-toggle {
        right: 50%;
        transform: translateX(50%) translateY(-50%);
    }

    .sidebar.collapsed .sidebar-toggle:hover {
        transform: translateX(50%) translateY(-50%) scale(1.05);
    }

    .sidebar.collapsed .sidebar-toggle:active {
        transform: translateX(50%) translateY(-50%) scale(0.95);
    }

    /* Tooltip for collapsed sidebar */
    .sidebar.collapsed .nav-item {
        position: relative;
    }

    .sidebar.collapsed .nav-item::after {
        content: attr(data-tooltip);
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        margin-left: 10px;
        background: rgba(0, 0, 0, 0.9);
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        white-space: nowrap;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s ease;
        z-index: 1000;
    }

    .sidebar.collapsed .nav-item:hover::after {
        opacity: 1;
    }

    /* Mobile responsive adjustments */
    @media (max-width: 991px) {
        .sidebar-toggle {
            display: none; /* Hide toggle on mobile (use mobile menu) */
        }
        
        #scrollToggleBar {
            display: none; /* Hide toggle bar on mobile */
        }
        
        .sidebar-toggle-bar {
            display: none; /* Hide toggle bar on mobile */
        }
    }

    /* Sidebar Collapse Button Styling - ICON ONLY, NO TEXT */
    .sidebar-collapse-btn {
        background: var(--petron-blue) !important;
        border: none !important;
        color: transparent !important; /* Make text transparent */
        cursor: pointer !important;
        padding: 0 !important;
        border-radius: 50% !important;
        transition: all 0.3s ease !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin: 0 !important;
        width: 32px !important;  /* Match notification & theme toggle */
        height: 32px !important;
        position: relative !important;
        box-shadow: 0 2px 6px rgba(0, 47, 112, 0.3) !important;
        flex-shrink: 0 !important;
        font-size: 0 !important; /* Hide any text */
        overflow: hidden !important; /* Hide overflow text */
        text-indent: -9999px !important; /* Push text way off screen */
        line-height: 0 !important; /* Collapse text height */
        z-index: 1100 !important;
        pointer-events: auto !important;
    }
    
    /* Ensure no text content visible */
    .sidebar-collapse-btn::before,
    .sidebar-collapse-btn::after {
        content: none !important;
    }

    .sidebar-collapse-btn:hover {
        background: #0040a0 !important;
        transform: scale(1.1) !important;
        box-shadow: 0 4px 12px rgba(0, 47, 112, 0.4) !important;
    }

    .sidebar-collapse-btn:active {
        transform: scale(0.95) !important;
        box-shadow: 0 2px 4px rgba(0, 47, 112, 0.2) !important;
        background: var(--petron-blue) !important;
        background-color: var(--petron-blue) !important;
    }
    
    .sidebar-collapse-btn:focus,
    .sidebar-collapse-btn:focus-visible {
        outline: none !important;
        background: var(--petron-blue) !important;
        background-color: var(--petron-blue) !important;
        box-shadow: 0 0 0 3px rgba(255,255,255,0.3) !important;
    }
    
    /* ICON IS VISIBLE */
    .sidebar-collapse-btn i {
        font-size: 15px !important;  /* Match notification & theme toggle icons */
        margin: 0 !important;
        transition: transform 0.3s ease !important;
        color: #ffffff !important;
        position: relative !important;
        z-index: 10 !important;
        display: block !important;
        text-indent: 0 !important; /* Reset text-indent for icon */
        line-height: normal !important; /* Reset line-height for icon */
    }
    
    .sidebar-collapse-btn:hover i {
        transform: none !important;
    }
    
    /* Sidebar Collapsed State */
    .sidebar.collapsed {
        width: 70px !important;
    }
    
    .sidebar.collapsed .nav-item span:not(.ico) {
        display: none !important;
    }
    
    .sidebar.collapsed .nav-item .ico {
        margin-right: 0 !important;
        justify-content: center !important;
    }
    
    .sidebar.collapsed .nav-item {
        justify-content: center !important;
        padding: 12px 0 !important;
    }
    
    .sidebar.collapsed .sidebar-collapse-btn {
        margin: 0 auto !important;
    }
    
    /* Smooth transition for sidebar */
    .sidebar {
        transition: width 0.3s ease !important;
    }
    
    .nav-item {
        transition: all 0.3s ease !important;
    }

    /* Horizontal Toggle Bar */
    .toggle-bar {
        background: linear-gradient(135deg, var(--petron-blue), #0040a0);
        height: 40px;
        display: flex;
        align-items: center;
        padding: 0 20px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 47, 112, 0.2);
        z-index: 999;
    }

    .toggle-bar.fixed-top {
        position: fixed;
        top: 70px;
        left: 0;
        right: 0;
        z-index: 999;
    }

    .toggle-bar.fixed-bottom {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 999;
    }

    .toggle-bar-items {
        display: flex;
        align-items: center;
        gap: 20px;
        flex: 1;
    }

    .toggle-bar-item {
        display: flex;
        align-items: center;
        gap: 8px;
        color: white;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        padding: 6px 12px;
        border-radius: 6px;
        transition: all 0.3s ease;
        white-space: nowrap;
    }

    .toggle-bar-item:hover {
        background: rgba(255, 255, 255, 0.1);
        transform: translateY(-1px);
    }

    .toggle-bar-item.active {
        background: rgba(255, 255, 255, 0.2);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .toggle-bar-item i {
        font-size: 16px;
        width: 16px;
        text-align: center;
    }

    .toggle-bar-divider {
        width: 1px;
        height: 24px;
        background: rgba(255, 255, 255, 0.3);
        margin: 0 10px;
    }

    .toggle-bar-right {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-left: auto;
    }

    .toggle-bar-button {
        background: rgba(255, 255, 255, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .toggle-bar-button:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: translateY(-1px);
    }

    .toggle-bar-button:active {
        transform: translateY(0);
    }

    .toggle-bar-button i {
        font-size: 12px;
    }

    /* Toggle bar with sidebar adjustment */
    .main.with-toggle-bar {
        top: 110px; /* Header (70px) + Toggle Bar (40px) */
    }

    .main.with-toggle-bar.sidebar-collapsed {
        left: 70px;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .toggle-bar {
            padding: 0 15px;
            height: 45px;
        }

        .toggle-bar-items {
            gap: 15px;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .toggle-bar-items::-webkit-scrollbar {
            display: none;
        }

        .toggle-bar-item {
            font-size: 13px;
            padding: 8px 10px;
        }

        .toggle-bar-item span {
            display: none; /* Hide text on mobile, show only icons */
        }

        .toggle-bar-divider {
            display: none;
        }

        .toggle-bar-right {
            gap: 10px;
        }

        .toggle-bar-button {
            padding: 8px 10px;
            font-size: 11px;
        }
    }
    
    /* Global FontAwesome Icon Visibility Fix */
    .fas, .far, .fab, .fa {
        opacity: 1 !important;
        visibility: visible !important;
        display: inline-block !important;
    }

    /* Ensure all icons in headers and cards are visible */
    .card-header i,
    .metric-icon i,
    .admin-metric-icon i,
    .stat-icon i,
    .btn i,
    button i,
    h3 i,
    h4 i {
        opacity: 1 !important;
        visibility: visible !important;
    }
    
    /* Sidebar Header */
    .sidebar-header {
        display: none;
    }

    /* Toggle row removed - button moved to header */
    
    /* Header Search Bar - restore pointer events on actual search elements */
    #searchWrapper,
    .header-center form,
    .header-center input,
    .header-center button,
    #searchInput,
    #searchSuggestions {
        pointer-events: auto;
    }

    /* Header Search Bar Styling */
    .header-center form input[type="text"]:focus {
        outline: none;
        border-color: var(--petron-blue);
        box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1);
        background: rgba(255, 255, 255, 1);
        transform: translateY(-1px);
    }
    
    .header-center form button:hover {
        background: #001a4d;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 47, 112, 0.3);
    }
    
    /* Notification Bell Improvements */
    .notification-bell {
        position: relative;
        cursor: pointer;
        padding: 6px;
        border-radius: 50%;
        transition: all 0.3s ease;
        background: rgba(0, 47, 112, 0.05);
        z-index: 1100 !important;
        pointer-events: auto !important;
        flex-shrink: 0;
        width: 32px;  /* Ultra compact */
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .notification-bell:hover {
        background: rgba(0, 47, 112, 0.1);
        transform: scale(1.1);
    }
    
    .notification-bell .badge {
        position: absolute;
        top: -5px;
        right: -7px;
        background: #dc2626 !important;
        color: white !important;
        font-size: 10.5px !important;
        font-weight: 700 !important;
        line-height: 1 !important;
        padding: 2px 5px !important;
        border-radius: 9999px !important;
        min-width: 18px !important;
        height: 18px !important;
        width: auto !important;
        box-sizing: border-box !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center;
        border: 2px solid var(--header-bg, #ffffff) !important;
        box-shadow: 0 1px 4px rgba(0,0,0,0.25);
        pointer-events: none;
        z-index: 1001;
    }

    /* â”€â”€ Theme Toggle Button (icon only) â”€â”€ */
    .theme-toggle-btn {
        position: relative;
        cursor: pointer;
        padding: 0;
        border-radius: 50%;
        transition: all 0.3s ease;
        background: rgba(0, 47, 112, 0.07);
        border: 1.5px solid rgba(0, 47, 112, 0.12);
        z-index: 1100 !important;
        pointer-events: auto !important;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        user-select: none;
    }
    .theme-toggle-btn:hover {
        background: rgba(0, 47, 112, 0.13);
        border-color: rgba(0, 47, 112, 0.3);
        transform: scale(1.1);
    }
    .theme-toggle-btn i {
        font-size: 15px;
        color: var(--petron-blue);
        transition: all 0.3s ease;
    }
    .theme-toggle-btn:hover i {
        color: var(--petron-red);
        transform: rotate(20deg);
    }

    /* Dark mode — glowing indigo circle */
    body.dark-theme .theme-toggle-btn {
        background: rgba(99, 102, 241, 0.18) !important;
        border: 1.5px solid rgba(99, 102, 241, 0.55) !important;
        box-shadow: 0 0 10px rgba(99, 102, 241, 0.35), inset 0 0 6px rgba(99, 102, 241, 0.1) !important;
    }
    body.dark-theme .theme-toggle-btn:hover {
        background: rgba(99, 102, 241, 0.28) !important;
        box-shadow: 0 0 16px rgba(99, 102, 241, 0.55) !important;
        transform: scale(1.12) !important;
    }
    body.dark-theme .theme-toggle-btn i {
        color: #a5b4fc !important;
    }
    body.dark-theme .theme-toggle-btn:hover i {
        color: #c7d2fe !important;
    }

    /* â”€â”€ Dark Mode Accent Bar (thin glow line at very top of page) â”€â”€ */
    /* === CRITICAL FIX: Ensure body pseudo-elements don't block clicks === */
    body::before {
        content: '';
        position: fixed;
        pointer-events: none !important;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: transparent;
        z-index: 9999;
        transition: background 0.3s ease;
    }
    body.dark-theme::before {
        background: linear-gradient(90deg, #6366f1, #8b5cf6, #3b82f6, #6366f1);
        background-size: 200% 100%;
        animation: darkModeBarShimmer 3s linear infinite;
        box-shadow: 0 0 12px rgba(99,102,241,0.7);
    }
    @keyframes darkModeBarShimmer {
        0%   { background-position: 0% 50%; }
        100% { background-position: 200% 50%; }
    }


    
    .notification-bell i {
        font-size: 15px;  /* Smaller icon */
        color: var(--petron-blue);
    }
    
    .notification-bell .badge {
        position: absolute;
        top: -5px;
        right: -7px;
        background: #dc2626 !important;
        color: white !important;
        font-size: 10.5px !important;
        font-weight: 700 !important;
        line-height: 1 !important;
        padding: 2px 5px !important;
        border-radius: 9999px !important;
        min-width: 18px !important;
        height: 18px !important;
        width: auto !important;
        box-sizing: border-box !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center;
        border: 2px solid var(--header-bg, #ffffff) !important;
        box-shadow: 0 1px 4px rgba(0,0,0,0.25);
        pointer-events: none;
        z-index: 1001;
    }

    /* Profile Dropdown Fix */
    .profile-access {
        position: relative;
        cursor: pointer;
        z-index: 1100 !important;
        pointer-events: auto !important;
    }

    .profile-dropdown {
        z-index: 1150 !important;
        pointer-events: auto !important;
    }

    /* ---- Force all header interactive elements to receive clicks ---- */
    .top-header .notification-bell,
    .top-header .theme-toggle-btn,
    .top-header .profile-access,
    .top-header .sidebar-collapse-btn,
    .top-header .notif-dropdown,
    .top-header .profile-dropdown,
    .top-header button,
    .top-header a,
    #notificationBell,
    #themeToggle,
    #profileMenu,
    #profileDropdown,
    #sidebarCollapseBtn {
        pointer-events: auto !important;
        position: relative;
        z-index: 1100 !important;
    }

    /* Ensure header-left and header-right are fully interactive */
    .header-left,
    .header-right {
        pointer-events: auto !important;
        z-index: 1100 !important;
        position: relative;
    }
    
    /* Make sure search wrapper doesn't block icons */
    #searchWrapper {
        pointer-events: auto !important;
        z-index: 5 !important;
        max-width: 250px;
        position: relative;
    }
    
    /* Ensure header-center stays behind interactive elements */
    .header-center {
        z-index: 5 !important;
        pointer-events: none !important;
    }
    
    /* But allow interaction with search elements */
    .header-center * {
        pointer-events: auto !important;
    }
    
    /* === CRITICAL HEADER ICON FIX === */
    /* Ensure all header buttons are always clickable with highest priority */
    .top-header,
    .top-header * {
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
        user-select: none;
    }
    
    /* NUCLEAR FIX: Force all interactive header elements to be on top and clickable */
    #notificationBell,
    #themeToggle,
    #profileMenu,
    #sidebarCollapseBtn,
    .notification-bell,
    .theme-toggle-btn,
    .profile-access,
    .sidebar-collapse-btn {
        position: relative !important;
        z-index: 99999 !important;
        pointer-events: auto !important;
        cursor: pointer !important;
        isolation: isolate !important;
    }
    
    /* Make sure button backgrounds don't block clicks */
    .sidebar-collapse-btn,
    #sidebarCollapseBtn {
        background: var(--petron-blue) !important;
        border: none !important;
        outline: none !important;
        -webkit-tap-highlight-color: transparent !important;
    }
    
    /* Ensure dropdowns appear above everything */
    #notificationDropdown,
    #profileDropdown,
    .notif-dropdown,
    .profile-dropdown {
        z-index: 999999 !important;
        pointer-events: auto !important;
    }
    
    /* Prevent any overlay from blocking header icons */
    .header-left > *,
    .header-right > * {
        position: relative !important;
        z-index: 99999 !important;
        pointer-events: auto !important;
    }
    
    /* Ensure brand elements don't block the button */
    .brand-mark,
    .brand-text {
        pointer-events: none !important;
        z-index: 1 !important;
    }
    
    /* Make sure header areas have proper stacking */
    .header-left {
        z-index: 99999 !important;
        isolation: isolate !important;
    }
    
    .header-right {
        z-index: 99999 !important;
        isolation: isolate !important;
    }
        
        
        
        
      </style>

<style id="globalRedBadgeStyle">
/* === GLOBAL ENFORCEMENT: NOTIFICATION COUNTER BADGES (CLEAR, CRISP & AUTO-EXPANDING) === */
.notification-bell {
    position: relative !important;
    overflow: visible !important;
}

#notificationBadge,
.notification-bell #notificationBadge,
.notification-bell .badge {
    position: absolute !important;
    top: -5px !important;
    right: -7px !important;
    background-color: #dc2626 !important; /* Petron Vivid Red */
    color: #ffffff !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
    line-height: 1 !important;
    letter-spacing: normal !important;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    padding: 2px 5px !important;
    min-width: 18px !important;
    height: 18px !important;
    width: auto !important; /* Allows badge to expand cleanly for 2 digits (e.g. 19) or 99+ */
    border-radius: 9999px !important; /* Perfect circle for 1 digit, smooth pill for 2+ digits */
    border: 2px solid var(--header-bg, #ffffff) !important;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25) !important;
    box-sizing: border-box !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    text-align: center !important;
    white-space: nowrap !important;
    pointer-events: none !important;
    z-index: 1001 !important;
}

#notificationBadge[style*="display: none"],
.notification-bell #notificationBadge[style*="display: none"],
.notification-bell .badge[style*="display: none"] {
    display: none !important;
}

body.dark-theme #notificationBadge,
body.dark-theme .notification-bell #notificationBadge,
body.dark-theme .notification-bell .badge {
    border-color: var(--header-bg, #1a1f2e) !important;
}

.notif-badge,
.cal-badge {
    background-color: #dc2626 !important;
    color: #ffffff !important;
    font-weight: 700 !important;
}

/* === Sidebar Navigation Badges: Completely Disabled / Removed as requested === */
.sidebar .badge,
.sidebar [data-badge],
.sidebar [data-sidebar-badge],
.sidebar-menu .badge,
.sidebar-menu [data-badge],
.sidebar-menu [data-sidebar-badge],
.nav-item .badge,
.nav-item [data-badge],
.nav-item [data-sidebar-badge],
.sidebar-sub-item .badge,
.sidebar-sub-item [data-badge],
.sidebar-badge,
.nav-badge,
.sub-badge {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
}
</style>

  <!-- Global Safe Handler Stubs for POS & Draft Engine -->
  <script>
    window.searchCustomerByName      = window.searchCustomerByName      || function(p) {};
    window.hideVehicleDropdown       = window.hideVehicleDropdown       || function() {};
    window.checkVehicleSecurityWarning = window.checkVehicleSecurityWarning || function() {};
    window.hideMechanicDropdown      = window.hideMechanicDropdown      || function() {};
    window.unlockCustomerIfNeeded  = window.unlockCustomerIfNeeded  || function(p) {};
    window.filterVehicleDropdown   = window.filterVehicleDropdown   || function(q) {};
    window.onVehicleTypeChange     = window.onVehicleTypeChange     || function() {};
    window.onPaymentAmountInput    = window.onPaymentAmountInput    || function(m) {};
    window.onCreditCustomerChange  = window.onCreditCustomerChange  || function() {};
    window.onLoyaltyChange         = window.onLoyaltyChange         || function() {};
    window.calcLoyaltyPoints       = window.calcLoyaltyPoints       || function() {};
    window.computeChange           = window.computeChange           || function() {};
    window.onPaymentChange         = window.onPaymentChange         || function() {};
    window.syncItemQtyToCart       = window.syncItemQtyToCart       || function(v) {};
    window.filterServiceTypes      = window.filterServiceTypes      || function(s) {};
    window.showServiceDropdown     = window.showServiceDropdown     || function() {};
    window.onServiceCheckboxChange = window.onServiceCheckboxChange || function(c) {};
    window.onJoServicePriceInput   = window.onJoServicePriceInput   || function() {};
    window.onJoLaborChargeInput    = window.onJoLaborChargeInput    || function() {};
    window.filterMechanicDropdown  = window.filterMechanicDropdown  || function(v) {};
    window.filterProductDropdown   = window.filterProductDropdown   || function(f) {};
    window.onProductCheckboxChange = window.onProductCheckboxChange || function(c) {};
    window.onMerchandiseRowChange  = window.onMerchandiseRowChange  || function(r) {};
    window.joApplyFilters          = window.joApplyFilters          || function() {};
    window.joChangePerPage         = window.joChangePerPage         || function() {};
    window.pmRecalc                = window.pmRecalc                || function() {};
    window.pmSelectMethod          = window.pmSelectMethod          || function(m) {};
    window.pmCalcChange            = window.pmCalcChange            || function() {};
    window.onReqAdjFieldChange     = window.onReqAdjFieldChange     || function(f) {};
  </script>

<style>
/* Disable Edge/Browser built-in password reveal button so only single custom eye icon shows */
input[type="password"]::-ms-reveal,
input[type="password"]::-ms-clear,
input[type="password"]::-webkit-contacts-auto-fill-button,
input[type="password"]::-webkit-credentials-auto-fill-button {
    display: none !important;
    width: 0 !important;
    height: 0 !important;
    visibility: hidden !important;
    pointer-events: none !important;
}
</style>

<style>
/* Senior-Friendly Global Font Size & Wrapping Rule */
html, body {
    font-size: 15px;
}
.sidebar-nav-item, .sidebar-link, .nav-link, .nav-item, .nav-item span, .nav-item span.nav-label {
    font-size: 15px !important;
    font-weight: 600 !important;
    white-space: normal !important;
    word-break: normal !important;
    overflow-wrap: break-word !important;
    text-overflow: clip !important;
}
.sidebar-sub-item, .sidebar-sub-item span, .sidebar-sub-item span:not(.ico) {
    font-size: 15px !important;
    font-weight: 600 !important;
    white-space: normal !important;
    word-break: normal !important;
    overflow-wrap: break-word !important;
    text-overflow: clip !important;
}
table.tbl-requests th, table.pricing-table th, table.fuel-table th {
    font-size: 13px !important;
}
table.tbl-requests td, table.pricing-table td, table.fuel-table td {
    font-size: 14.5px !important;
}
</style>


<style>



<style>



<style>



<style>



<style>
/* Global No Text Overlapping Rule */
.table-wrap:not(.adm-table-responsive), .table-responsive:not(.adm-table-responsive), .cust-section, .card:not(.adm-card), .table-card {
    overflow-x: auto !important;
    overflow-y: visible !important;
    width: 100% !important;
}
@media screen {
    table:not(.report-table):not(.rpt-table):not(.adm-table):not(.mgr-table):not(.no-min-width):not(.dashboard-table):not(.dev-inner-table):not(.dev-dashboard table):not(.adm-dashboard table):not(.mgr-dashboard table):not(.print-table):not(.cust-modal table):not(.modal-backdrop table):not(.modal-body table):not(.modal table) {
        width: 100% !important;
        min-width: 1050px !important;
        table-layout: auto !important;
    }
    table:not(.report-table):not(.rpt-table):not(.adm-table):not(.mgr-table):not(.no-min-width):not(.dashboard-table):not(.dev-inner-table):not(.dev-dashboard table):not(.adm-dashboard table):not(.mgr-dashboard table):not(.print-table):not(.cust-modal table):not(.modal-backdrop table):not(.modal-body table):not(.modal table) th,
    table:not(.report-table):not(.rpt-table):not(.adm-table):not(.mgr-table):not(.no-min-width):not(.dashboard-table):not(.dev-inner-table):not(.dev-dashboard table):not(.adm-dashboard table):not(.mgr-dashboard table):not(.print-table):not(.cust-modal table):not(.modal-backdrop table):not(.modal-body table):not(.modal table) td {
        white-space: nowrap !important;
    }
}
@media print {
    table {
        min-width: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
        table-layout: fixed !important;
    }
    table th, table td {
        white-space: normal !important;
        word-break: break-word !important;
    }
}
</style>

</head>
<body class="app<?php echo $appearance_is_dark ? ' dark-theme' : ''; ?>" data-page="<?php echo htmlspecialchars($page_id); ?>" data-role="<?php echo htmlspecialchars($role); ?>" data-sidebar-mode="<?php echo htmlspecialchars($appearance_sidebar_mode); ?>">
<!-- System appearance settings exposed to JS -->
<script>
window.petronSystemSettings = {
    theme: '<?php echo addslashes($appearance_theme); ?>',
    accentColor: '<?php echo addslashes($appearance_accent_color); ?>',
    sidebarColor: '<?php echo addslashes($appearance_sidebar_color); ?>',
    navActiveColor: '<?php echo addslashes($appearance_nav_active_color); ?>',
    sidebarMode: '<?php echo addslashes($appearance_sidebar_mode); ?>',
    autoRefreshSec: <?php echo (int)$appearance_auto_refresh_sec; ?>,
    timezone: '<?php echo addslashes($station_settings['timezone'] ?? 'Asia/Manila (UTC+8)'); ?>',
    dateFormat: '<?php echo addslashes($station_settings['date_format'] ?? 'YYYY-MM-DD'); ?>',
    timeFormat: '<?php echo addslashes($station_settings['time_format'] ?? '12H'); ?>',
    currencySymbol: '<?php echo addslashes($station_settings['currency_symbol'] ?? 'PHP (₱)'); ?>',
    bannerDuration: <?php echo (int)($station_settings['banner_duration'] ?? 5); ?>,
    enableSystemNotifications: <?php echo (isset($station_settings['enable_system_notifications']) && $station_settings['enable_system_notifications'] == '0') ? 'false' : 'true'; ?>,
    enableErrorNotifications: <?php echo (isset($station_settings['enable_error_notifications']) && $station_settings['enable_error_notifications'] == '0') ? 'false' : 'true'; ?>,
    defaultPaperSize: '<?php echo addslashes($station_settings['default_paper_size'] ?? 'A4'); ?>',
    defaultOrientation: '<?php echo addslashes($station_settings['default_orientation'] ?? 'Portrait'); ?>',
    showCompanyLogoReports: <?php echo (isset($station_settings['show_company_logo_reports']) && $station_settings['show_company_logo_reports'] == '0') ? 'false' : 'true'; ?>,
    showReportFooter: <?php echo (isset($station_settings['show_report_footer']) && $station_settings['show_report_footer'] == '0') ? 'false' : 'true'; ?>
};
</script>
<?php
$hdr_paper_size   = $station_settings['default_paper_size'] ?? 'A4';
if (!in_array($hdr_paper_size, ['A4', 'Letter'], true)) $hdr_paper_size = 'A4';
$hdr_orientation  = strtolower($station_settings['default_orientation'] ?? 'portrait');
if (!in_array($hdr_orientation, ['portrait', 'landscape'], true)) $hdr_orientation = 'portrait';
$hdr_show_logo    = !(isset($station_settings['show_company_logo_reports']) && ($station_settings['show_company_logo_reports'] === '0' || $station_settings['show_company_logo_reports'] === 0));
$hdr_show_footer  = !(isset($station_settings['show_report_footer']) && ($station_settings['show_report_footer'] === '0' || $station_settings['show_report_footer'] === 0));
?>
<!-- Dynamic Print Styles Enforcing Report Settings (Paper Size, Orientation, Logo, Footer) -->
<style id="globalPetronReportSettingsPrint">
@media print {
    @page {
        size: <?php echo $hdr_paper_size; ?> <?php echo $hdr_orientation; ?>;
        margin: 10mm 12mm;
    }
    <?php if (!$hdr_show_logo): ?>
    .rpt-company-logo,
    .report-logo,
    .print-report-logo,
    .sfss-print-only .rpt-company-logo,
    .rpt-centered-header .rpt-company-logo,
    .rpt-header-title .rpt-company-logo,
    .header .rpt-company-logo,
    [data-report-logo] {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
        width: 0 !important;
    }
    <?php endif; ?>
    <?php if (!$hdr_show_footer): ?>
    .print-only-sig,
    .sfss-print-only .print-only-sig,
    .mgr-signature-row,
    .str-signature-wrap,
    .rpt-footer,
    .report-footer,
    .rpt-signatures,
    .sfss-print-only .mgr-signature-row,
    [data-report-footer] {
        display: none !important;
        visibility: hidden !important;
        height: 0 !important;
    }
    <?php endif; ?>
}
</style>
<!-- NUCLEAR-HEADER-FIX: Force header above any overlays and ensure clicks reach controls -->
<style id="nuclearHeaderFix">
    .top-header{ position:fixed !important; top:0; left:0; right:0; z-index:2147483640 !important; pointer-events:auto !important; }
    .top-header *{ pointer-events:auto !important; }
    /* Ensure all header icons and buttons are always above any overlay and clickable */
    #notificationBell, #themeToggle, #profileMenu, #sidebarCollapseBtn,
    .notification-bell, .theme-toggle-btn, .profile-access, .sidebar-collapse-btn {
        position: relative !important;
        z-index: 2147483645 !important;
        pointer-events: auto !important;
        cursor: pointer !important;
    }
    /* Sidebar must be clickable and not blocked by header */
    .sidebar { pointer-events:auto !important; position: fixed !important; z-index:1001 !important; }
    .sidebar *, .sidebar .nav-item, .sidebar .nav-item *, .sidebar-menu, .sidebar-menu * { pointer-events:auto !important; cursor: pointer !important; }
    /* Make common backdrop/overlay elements pass pointer-events through so header remains clickable */
    .mobile-sidebar-backdrop, .modal-backdrop, .sr-modal-overlay, .overlay-block, .ui-block { pointer-events:none !important; }
    /* Keep dropdowns above everything */
    #notificationDropdown, #profileDropdown, .notif-dropdown, .profile-dropdown { z-index:2147483647 !important; pointer-events:auto !important; }
    #notificationDropdown *, #profileDropdown *, .notif-dropdown *, .profile-dropdown * { pointer-events:auto !important; }
    #notificationDropdown:not(.show), #profileDropdown:not(.show), #varianceAlertDropdown:not(.show),
    .notif-dropdown:not(.show), .profile-dropdown:not(.show) {
        display: none !important;
    }
</style>

<!-- SIDEBAR CLICKABILITY FIX: Ensure all sidebar navigation items are fully clickable -->
<style id="sidebarClickabilityFix">
    /* Force sidebar and all children to accept pointer events */
    #mainSidebar { pointer-events: auto !important; z-index: 1001 !important; }
    #mainSidebar * { pointer-events: auto !important; }
    
    /* Ensure nav items are clickable */
    .nav-item-wrapper { pointer-events: auto !important; position: relative; z-index: 10; }
    .nav-item { pointer-events: auto !important; cursor: pointer !important; position: relative; z-index: 10; }
    .nav-item a { pointer-events: auto !important; cursor: pointer !important; }
    .sidebar-sub-item { pointer-events: auto !important; cursor: pointer !important; }
    
    /* Ensure the sidebar-menu is fully interactive */
    .sidebar-menu { pointer-events: auto !important; }
    .sidebar-menu * { pointer-events: auto !important; }
    
    /* Make sure no overlay is blocking the sidebar */
    body::before, body::after { pointer-events: none !important; }
</style>

<!-- MAIN CONTENT CLICKABILITY FIX: Ensure all buttons and interactive elements in main content are clickable -->
<style id="mainContentClickabilityFix">
    /* Force main content area to accept pointer events */
    .main { pointer-events: auto !important; z-index: 1 !important; }
    .main * { pointer-events: auto !important; }
    
    /* Ensure all buttons are clickable */
    button, .btn, .button, input[type="button"], input[type="submit"], a.btn {
        pointer-events: auto !important;
        cursor: pointer !important;
        position: relative;
        z-index: 10;
    }
    
    /* Ensure all interactive form elements are clickable */
    input, select, textarea, label {
        pointer-events: auto !important;
        cursor: auto !important;
    }
    
    input[type="button"], input[type="submit"], button {
        cursor: pointer !important;
    }
    
    /* Ensure all links are clickable */
    a { pointer-events: auto !important; cursor: pointer !important; }
    
    /* Cards and panels should allow clicks */
    .card, .panel, .widget-card, .petron-card {
        pointer-events: auto !important;
    }
    
    .card *, .panel *, .widget-card *, .petron-card * {
        pointer-events: auto !important;
    }
    
    /* Tables and their contents should be clickable */
    table, table * {
        pointer-events: auto !important;
    }
    
    /* Modals and their content should be clickable */
    .modal, .modal * {
        pointer-events: auto !important;
    }
    
    /* Ensure dropdowns are clickable */
    .dropdown, .dropdown-menu, .dropdown-item {
        pointer-events: auto !important;
        cursor: pointer !important;
    }
    
    /* Override any conflicting styles */
    .main-content, .content, .container, .container-fluid {
        pointer-events: auto !important;
    }
    
    /* Ensure no pseudo-elements block clicks */
    .main::before, .main::after,
    .card::before, .card::after,
    .panel::before, .panel::after {
        pointer-events: none !important;
    }
</style>

<!-- PREVENT FLICKER ON PAGE LOAD: Apply theme immediately -->
<style id="antiFlickerFix">
    /* Smooth transitions for theme changes */
    body {
        transition: background-color 0.2s ease, color 0.2s ease;
    }
    
    .sidebar, .main, .top-header, .card, .panel, .widget-card {
        transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    }
    
    /* Prevent sidebar flickering during toggle */
    .sidebar {
        transition: width 0.3s ease, transform 0.3s ease !important;
    }
    
    .main {
        transition: left 0.3s ease, margin-left 0.3s ease !important;
    }
    
    /* Smooth icon transitions */
    #sidebarToggleIcon, #themeIcon {
        transition: transform 0.2s ease;
    }
</style>

<!-- ULTIMATE CONTENT CLICKABILITY FIX: Nuclear option to ensure EVERYTHING works -->
<style id="ultimateClickFix">
    /* === FORCE ALL CONTENT TO BE INTERACTIVE === */
    
    /* Main content area MUST be on top of any overlays */
    .main {
        position: fixed !important;
        pointer-events: auto !important;
        z-index: 1 !important;
        /* NOTE: isolation:isolate was removed — it caused position:fixed modals
           to anchor to .main instead of the viewport, breaking centering */
    }
    
    /* Everything inside main MUST be clickable */
    .main *,
    .main button,
    .main .btn,
    .main input,
    .main select,
    .main textarea,
    .main a,
    .main label,
    .main .card,
    .main .panel,
    .main table,
    .main tr,
    .main td,
    .main th {
        pointer-events: auto !important;
    }
    
    /* Buttons specifically MUST work */
    button,
    .btn,
    input[type="button"],
    input[type="submit"],
    a.btn,
    .button {
        pointer-events: auto !important;
        cursor: pointer !important;
        position: relative;
        z-index: 10 !important;
    }
    
    /* Ensure main content can scroll */
    .main {
        overflow-y: auto !important;
        overflow-x: hidden !important;
        -webkit-overflow-scrolling: touch !important;
    }
    
    /* Remove any accidental overlays — but exclude modals & dropdowns moved to body via JS */
    body > *:not(.app):not(.top-header):not(.sidebar):not(.main):not(.fixed-footer):not(script):not(style):not(.mi-overlay):not(.modal-overlay):not(.sr-success-overlay):not(.sr-success-popup):not([id$="Modal"]):not([id$="modal"]):not([id*="Overlay"]):not([id*="overlay"]):not([id*="Popup"]):not([id*="popup"]):not(#toast):not(#notificationDropdown):not(#profileDropdown):not(.notif-dropdown):not(.profile-dropdown) {
        pointer-events: none !important;
    }

    /* Modals, dropdowns, and overlays appended to body MUST be fully interactive */
    .mi-overlay,
    .mi-overlay *,
    .modal-overlay,
    .modal-overlay *,
    .sr-success-overlay,
    .sr-success-popup,
    .sr-success-popup *,
    #notificationDropdown,
    #notificationDropdown *,
    #profileDropdown,
    #profileDropdown *,
    .notif-dropdown,
    .notif-dropdown *,
    .profile-dropdown,
    .profile-dropdown * {
        pointer-events: auto !important;
    }

    /* System-Wide Modal Overlay Workspace Centering & Sidebar Anti-Overlap Fix */
    @media (min-width: 992px) {
        .modal-overlay,
        .sr-modal-overlay,
        .mi-overlay,
        .modal-backdrop-custom {
            padding-left: 270px !important; /* Offset by 250px sidebar width + 20px padding */
            padding-top: 75px !important;
            padding-bottom: 75px !important; /* EQUAL spacing at footer matching top */
            padding-right: 20px !important;
            box-sizing: border-box !important;
            align-items: center !important;
            justify-content: center !important;
        }

        body.sidebar-collapsed .modal-overlay,
        body.sidebar-collapsed .sr-modal-overlay,
        body.sidebar-collapsed .mi-overlay,
        body.sidebar-collapsed .modal-backdrop-custom {
            padding-left: 90px !important; /* Offset when sidebar collapsed (70px + 20px) */
        }

        .modal-box,
        .sr-modal-box,
        .mi-box,
        .modal-dialog,
        .modal-card,
        .modal-card-wide,
        .modal-card-xl {
            max-width: min(1100px, calc(100vw - 310px)) !important;
            max-height: calc(100vh - 150px) !important;
            margin-left: auto !important;
            margin-right: auto !important;
            display: flex !important;
            flex-direction: column !important;
        }

        body.sidebar-collapsed .modal-box,
        body.sidebar-collapsed .sr-modal-box,
        body.sidebar-collapsed .mi-box,
        body.sidebar-collapsed .modal-dialog,
        body.sidebar-collapsed .modal-card {
            max-width: min(1100px, calc(100vw - 110px)) !important;
            max-height: calc(100vh - 150px) !important;
        }
    }

    @media (max-width: 991px) {
        .modal-overlay,
        .sr-modal-overlay,
        .mi-overlay,
        .modal-backdrop-custom {
            padding-left: 16px !important;
            padding-right: 16px !important;
            padding-top: 65px !important;
            padding-bottom: 65px !important; /* EQUAL spacing at footer matching top */
            box-sizing: border-box !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .modal-box,
        .sr-modal-box,
        .mi-box,
        .modal-dialog,
        .modal-card {
            max-height: calc(100vh - 130px) !important;
            margin: auto !important;
            display: flex !important;
            flex-direction: column !important;
        }
    }
    
    /* Ensure no pseudo-elements block interaction */
    *::before,
    *::after {
        pointer-events: none !important;
    }
    
    /* But allow pseudo-elements inside interactive elements */
    button::before, button::after,
    .btn::before, .btn::after,
    a::before, a::after {
        pointer-events: auto !important;
    }
    
    /* App container should not interfere */
    .app {
        pointer-events: auto !important;
        overflow: hidden !important;
    }
    
    /* Scrollable container - only .main is the primary vertical scroll container on desktop */
    .main,
    .overflow-auto,
    .scroll-container {
        pointer-events: auto !important;
        overflow-y: auto !important;
    }

    /* Prevent redundant inner vertical scrollbars on child wrappers inside .main */
    .main > .main-content,
    .main-content,
    .main .container,
    .main .container-fluid,
    .main .table-responsive,
    .main .table-wrap,
    .main .card:not(.card-scrollable),
    .main .table-card {
        overflow-y: visible !important;
        height: auto !important;
        max-height: none !important;
    }
</style>

<!-- Move header dropdowns to body to avoid clipping by ancestor overflow -->
<script>
(function(){
    function moveToBody(id){
        try{
            var el = document.getElementById(id);
            if(!el) return null;
            if(el.dataset.moved === '1') {
                el.style.zIndex = '2147483647';
                el.style.pointerEvents = 'auto';
                return el;
            }
            var ph = document.createElement('div'); ph.style.display='none'; el.parentNode.insertBefore(ph, el);
            el.dataset._ph = '';
            document.body.appendChild(el);
            el.style.position = 'fixed';
            el.style.left = '0px';
            el.style.top = '0px';
            el.style.margin = '0';
            el.style.zIndex = '2147483647';
            el.style.pointerEvents = 'auto';
            el.dataset.moved = '1';
            return el;
        }catch(e){return null;}
    }

    function positionDropdown(dropdownId, triggerEl){
        try{
            var d = document.getElementById(dropdownId);
            if(!d || !triggerEl) return;
            // ensure block is visible to measure
            var wasHidden = window.getComputedStyle(d).display === 'none';
            if(wasHidden){ d.style.display = 'block'; d.style.visibility = 'hidden'; }
            var rect = triggerEl.getBoundingClientRect();

            // Prefer a compact width for profile, larger for notifications
            var desiredWidth;
            if(dropdownId === 'profileDropdown') {
                desiredWidth = 240; // profile menu should be compact
            } else if (dropdownId === 'notificationDropdown') {
                desiredWidth = Math.min(380, Math.max(260, d.offsetWidth || 320));
            } else {
                desiredWidth = Math.min(380, Math.max(240, d.offsetWidth || 320));
            }
            // Never exceed viewport minus small padding
            var width = Math.min(desiredWidth, Math.max(160, window.innerWidth - 24));
            d.style.width = width + 'px';

            // Position horizontally: prefer aligning right edge to trigger's right edge
            var left = Math.round(rect.right - width - 6);
            // If there's not enough room on the right, try aligning left edge to trigger's left
            if(left < 8) left = Math.round(rect.left + 6);
            if(left + width > window.innerWidth - 8) left = Math.max(8, window.innerWidth - width - 8);

            // Position vertically: below trigger; if dropdown would overflow viewport bottom, place above
            var topBelow = Math.round(rect.bottom + 8);
            var bottomOverflow = topBelow + d.offsetHeight > window.innerHeight - 8;
            var top = topBelow;
            if(dropdownId === 'profileDropdown' && bottomOverflow){
                // place above trigger if it would overflow
                top = Math.round(rect.top - d.offsetHeight - 8);
                if(top < 8) top = 8;
            }

            d.style.left = left + 'px';
            d.style.top = top + 'px';
            d.style.zIndex = '2147483647';
            d.style.pointerEvents = 'auto';
            d.style.visibility = '';
            if(wasHidden && !d.classList.contains('show')) d.style.display = 'none';
        }catch(e){console && console.warn && console.warn('positionDropdown err', e);}  
    }

    function toggleDropdown(dropdownId, triggerSel){
        var trigger = (typeof triggerSel === 'string') ? document.querySelector(triggerSel) : triggerSel;
        if(!trigger) return;
        var d = moveToBody(dropdownId) || document.getElementById(dropdownId);
        if(!d) return;
        d.style.zIndex = '2147483647';
        d.style.pointerEvents = 'auto';
        var showing = d.classList.contains('show') || d.style.display === 'block';
        if(!showing){
            // Close any other header dropdowns first (mutual exclusivity)
            closeAllHeaderDropdowns(dropdownId);
            d.classList.add('show');
            d.style.display = 'block';
            positionDropdown(dropdownId, trigger);
        } else {
            d.classList.remove('show');
            d.style.display = 'none';
        }
    }

    function closeAllHeaderDropdowns(exceptId){
        try{
            var ids = ['notificationDropdown','profileDropdown','varianceAlertDropdown'];
            ids.forEach(function(id){
                if(!id) return;
                if(id === exceptId) return;
                var el = document.getElementById(id);
                if(!el) return;
                el.classList.remove('show');
                el.style.display = 'none';
            });
            // Also hide any generic dropdown classes used in header
            document.querySelectorAll('.notif-dropdown, .profile-dropdown').forEach(function(el){
                if(el.id && el.id === exceptId) return;
                el.classList.remove('show');
                el.style.display = 'none';
            });
            var ss = document.getElementById('searchSuggestions');
            if(ss && exceptId !== 'searchSuggestions') {
                ss.style.display = 'none';
            }
        }catch(e){ console && console.warn && console.warn('closeAllHeaderDropdowns err', e); }
    }
    window.closeAllHeaderDropdowns = closeAllHeaderDropdowns;

    // Automatic close on outside click/pointerdown across all modules
    function handleHeaderOutsideClick(e){
        var target = e.target;
        if(!target) return;

        // Notification dropdown
        var nd = document.getElementById('notificationDropdown');
        var nb = document.getElementById('notificationBell');
        if(nd && (nd.classList.contains('show') || nd.style.display === 'block')){
            var inNotif = nd.contains(target);
            var inBell  = nb && (nb === target || nb.contains(target));
            if(!inNotif && !inBell){
                nd.classList.remove('show');
                nd.style.display = 'none';
            }
        }

        // Profile dropdown
        var pd = document.getElementById('profileDropdown');
        var pm = document.getElementById('profileMenu');
        if(pd && (pd.classList.contains('show') || pd.style.display === 'block')){
            var inProfile = pd.contains(target);
            var inMenu    = pm && (pm === target || pm.contains(target));
            if(!inProfile && !inMenu){
                pd.classList.remove('show');
                pd.style.display = 'none';
            }
        }

        // Variance alert dropdown
        var vd = document.getElementById('varianceAlertDropdown');
        var vb = document.getElementById('varianceAlertBell');
        if(vd && (vd.classList.contains('show') || vd.style.display === 'block')){
            var inVariance = vd.contains(target);
            var inVBell    = vb && (vb === target || vb.contains(target));
            if(!inVariance && !inVBell){
                vd.classList.remove('show');
                vd.style.display = 'none';
            }
        }

        // Search suggestions
        var ss = document.getElementById('searchSuggestions');
        var sw = document.getElementById('searchWrapper');
        if(ss && ss.style.display === 'block'){
            if(!sw || !sw.contains(target)){
                ss.style.display = 'none';
            }
        }
    }

    // Capture phase guarantees listener fires before any child elements can stop propagation
    document.addEventListener('pointerdown', handleHeaderOutsideClick, true);
    document.addEventListener('click', handleHeaderOutsideClick, true);

    // Close all header dropdowns on Escape key
    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape' || e.key === 'Esc' || e.keyCode === 27){
            closeAllHeaderDropdowns();
        }
    });

    // Replace existing toggle handlers with safe wrappers
    window.petronToggleNotif = function(e){
        try{ 
            toggleDropdown('notificationDropdown', '#notificationBell'); 
            if(e && e.preventDefault) e.preventDefault(); 
            var nd = document.getElementById('notificationDropdown');
            if (nd && (nd.classList.contains('show') || nd.style.display === 'block')) {
                if (typeof window.loadStaffNotifications === 'function') window.loadStaffNotifications();
                else if (typeof window.saLoadNotifications === 'function') window.saLoadNotifications();
            }
        }catch(err){}
    };
    window.petronToggleProfile = function(e){
        try{ toggleDropdown('profileDropdown', '#profileMenu'); if(e && e.preventDefault) e.preventDefault(); }catch(err){}
    };

    // Reposition visible dropdowns on resize/scroll
    function repositionAll(){
        var nb = document.getElementById('notificationBell');
        var pd = document.getElementById('profileMenu');
        var nd = document.getElementById('notificationDropdown');
        var prd = document.getElementById('profileDropdown');
        if(nd && (nd.classList.contains('show') || nd.style.display === 'block') && nb) positionDropdown('notificationDropdown', nb);
        if(prd && (prd.classList.contains('show') || prd.style.display === 'block') && pd) positionDropdown('profileDropdown', pd);
    }
    window.addEventListener('resize', function(){ setTimeout(repositionAll, 50); }, {passive:true});
    window.addEventListener('scroll', function(e){
        var nd = document.getElementById('notificationDropdown');
        if(nd && e.target && nd.contains(e.target)) return;
        setTimeout(repositionAll, 50);
    }, {passive:true});

    // On DOM ready, move dropdowns so they cannot be clipped
    document.addEventListener('DOMContentLoaded', function(){
        moveToBody('notificationDropdown');
        moveToBody('profileDropdown');
        moveToBody('varianceAlertDropdown');
    });

    // Expose helpers so other header scripts can reuse positioning/toggle behavior
    window.petronHeaderToggle = toggleDropdown;
    window.petronHeaderRepositionAll = repositionAll;
})();
</script>

    <!-- Debug Info (remove after fixing) -->
  <aside class="sidebar" id="mainSidebar">
    <div class="sidebar-menu">
            <nav class="nav">
<?php
// Include the new RBAC menu generation
require_once __DIR__ . '/rbac_menu.php';

  $base_path = ($public_base_url !== '') ? (rtrim($public_base_url, '/') . '/') : '/';
  
  if (!function_exists('map_hrefs')) {
      function map_hrefs(&$items, $base_path) {
        foreach ($items as &$item) {
          if (isset($item['href']) && !empty($item['href']) && strpos($item['href'], 'http') === false && strpos($item['href'], '#') !== 0) {
            // Make all hrefs absolute paths to /public/, preserving query strings
            $href = $item['href'];
            // Split on '?' to preserve query string
            $qpos = strpos($href, '?');
            if ($qpos !== false) {
                $file_part  = substr($href, 0, $qpos);
                $query_part = substr($href, $qpos); // includes the '?'
                $item['href'] = $base_path . basename($file_part) . $query_part;
            } else {
                $item['href'] = $base_path . basename($href);
            }
          }

          if (isset($item['sub_items']) && !empty($item['sub_items'])) {
            map_hrefs($item['sub_items'], $base_path);
          }
        }
      }
  }

  map_hrefs($items, $base_path);

  if (!function_exists('petron_is_nav_item_active')) {
      function petron_is_nav_item_active($item, $page_id) {
          $rawHref = $item['href'] ?? '';
          if ($rawHref === '' || $rawHref === '#') {
              return false;
          }

          // If item href has query parameters, verify they match $_GET
          $hrefQuery = parse_url($rawHref, PHP_URL_QUERY);
          if (!empty($hrefQuery)) {
              parse_str($hrefQuery, $hrefParams);
              foreach ($hrefParams as $pk => $pv) {
                  $actualVal = $_GET[$pk] ?? null;
                  if ($actualVal === null) {
                      $c_file = basename($_SERVER['PHP_SELF'] ?? '');
                      if ($c_file === 'staff_transactions_hub.php' && $pk === 'section') {
                          $actualVal = 'merchandise';
                      } elseif (($c_file === 'admin_reports.php' || $c_file === 'manager_reports.php') && $pk === 'cat') {
                          $actualVal = 'sales';
                      }
                  }
                  if ($actualVal === null || (string)$actualVal !== (string)$pv) {
                      return false;
                  }
              }
              return true;
          }

          $itemId = $item['id'] ?? '';
          if ($page_id !== '' && $itemId === $page_id) {
              return true;
          }

          $hrefPath = parse_url($rawHref, PHP_URL_PATH);
          if (!$hrefPath) return false;

          $hrefFile = basename($hrefPath);
          $hrefBase = basename($hrefPath, '.php');

          $currentScript = $_SERVER['PHP_SELF'] ?? '';
          $currentFile   = basename($currentScript);
          $currentBase   = basename($currentScript, '.php');

          $fileMatches = ($hrefFile === $currentFile) ||
                         ($hrefBase === $currentBase) ||
                         ($page_id !== '' && $hrefBase === $page_id);

          if (!$fileMatches) {
              return false;
          }

          return true;
      }
  }

  // Page-ID alias map: maps PHP file page_id → sidebar item id
  // Allows files like admin_set_prices.php ($page_id='admin_set_prices') to correctly
  // light up their sidebar item ('admin_product_pricing'), without requiring every page
  // to manually set $page_id to the exact sidebar item ID.
  $page_id_alias_map = [
      // Product & Pricing Management
      'admin_set_prices'              => 'admin_product_pricing',
      'manager_set_prices'            => 'mgr_product_pricing',
      // Dashboards
      'admin_dashboard'               => 'admin_dashboard',
      'manager_dashboard'             => 'manager_dashboard',
      'dashboard'                     => 'dashboard',
      // Transactions hub / sub-pages
      'transactions'                  => 'admin_transactions',
      'staff_new_transaction'         => 'staff_new_transaction',
      'staff_transaction_history'     => 'staff_transaction_history',
      'validated_transactions_manager'=> 'validated_transactions_manager',
      'admin_receipt_management'      => 'admin_receipt_management',
      'manager_request_data_management' => 'manager_request_data_management',
      'manager_mechanics_management'  => 'manager_mechanics_management',
      // Fuel management
      'fuel_sales'                    => 'fuel_sales',
      'fuel_transactions_validation'  => 'fuel_transactions_validation',
      'fuel_adjustments'              => 'fuel_adjustments',
      'fuel_pump_master'              => 'fuel_pump_master',
      'admin_fuel_transactions_oversight' => 'fuel_transactions_validation',
      // Inventory
      'admin_inventory_merch'         => 'admin_inventory_merchandise',
      'admin_inventory_merchandise'   => 'admin_inventory_merchandise',
      'admin_inventory_fuel'          => 'admin_inventory_fuel',
      'manager_inventory_merchandise' => 'mgr_inv_merch',
      'manager_inventory_fuel'        => 'mgr_inv_fuel',
      'manager_stock_request_review'  => 'mgr_stock_review',
      'manager_stock_in'              => 'mgr_stock_in',
      // Customers
      'manager_customers'             => 'mgr_customers',
      'mgr_customers'                 => 'mgr_customers',
      // Calendar
      'calendar'                      => 'admin_calendar',
      'admin_calendar'                => 'admin_calendar',
      'manager_calendar'              => 'calendar',
      // Reports
      'admin_reports'                 => 'admin_reports',
      'manager_reports'               => 'reports',
      // User management
      'users'                         => 'users',
      // Superadmin / Developer Modules
      'super_admin_dashboard'         => 'super_admin_dashboard',
      'admin_management'              => 'admin_management',
      'module_config'                 => 'module_config',
      'database_management'           => 'database_management',
      'station_management'            => 'admin_management',
      'system_settings'               => 'system_settings',
      'superadmin_reports'            => 'superadmin_reports',
      'audit_trail'                   => 'audit_trail',
  ];

  // Normalize page_id using alias map so sidebar items highlight correctly
  $effective_page_id = $page_id_alias_map[$page_id] ?? $page_id;


  // Determine active sub-item from URL hash or page_id
  $current_url  = basename($_SERVER['PHP_SELF']);
  $current_hash = '';
  if (isset($_SERVER['REQUEST_URI'])) {
      $uri_parts = explode('#', $_SERVER['REQUEST_URI']);
      $current_hash = $uri_parts[1] ?? '';
  }

  // Sidebar badge system.
  // Show live action counts so users can identify pending work from the menu.
  // These badges stay visible until the underlying item is resolved.
  $fuel_sub_badges = [];  // keyed by sub-item ID
  $badges          = is_array($badges ?? null) ? $badges : [];  // keyed by top-level item ID

  // Helper: load all badge_seen timestamps for this user in one query
  $badge_seen = [];
  $__uid = (int)($user['id'] ?? 0);
  if ($__uid > 0) {
      try {
          $__bs = $pdo->prepare(
              "SELECT preference_key, preference_value
               FROM user_preferences
               WHERE user_id = ? AND preference_key LIKE 'badge_seen_%'"
          );
          $__bs->execute([$__uid]);
          foreach ($__bs->fetchAll(PDO::FETCH_ASSOC) as $__row) {
              $__key = str_replace('badge_seen_', '', $__row['preference_key']);
              $badge_seen[$__key] = $__row['preference_value']; // UTC datetime string
          }
      } catch (Exception $e) {}
  }

  // Helper: get the "since" datetime for a badge key (fallback = 30 days ago for new users)
  $__default_since = date('Y-m-d H:i:s', strtotime('-30 days'));
  $__badge_since = function($key) use ($badge_seen, $__default_since) {
      return $badge_seen[$key] ?? $__default_since;
  };

  // Helper: safe count query with since-filter on created_at
  $__badge_count = function($sql, $params) use ($pdo) {
      try {
          $s = $pdo->prepare($sql);
          $s->execute($params);
          return max(0, (int)$s->fetchColumn());
      } catch (Exception $e) { return 0; }
  };

  $__badge_add = function($key, $count, $target = 'sub') use (&$fuel_sub_badges, &$badges) {
      $count = max(0, (int)$count);
      if ($count <= 0 || $key === '') return;
      if ($target === 'top') {
          $badges[$key] = ($badges[$key] ?? 0) + $count;
      } else {
          $fuel_sub_badges[$key] = ($fuel_sub_badges[$key] ?? 0) + $count;
      }
  };

  // ── Role-based Sidebar Badges (Red Circles with Counts) ──
  $badges = [
      'dashboard'           => 0,
      'transactions'        => 0,
      'fuel'                => 0,
      'inventory'           => 0,
      'customers'           => 0,
      'mgr_product_pricing' => 0,
      'prod_pricing'        => 0,
      'reports'             => 0,
      'notifications'       => 0
  ];
  $fuel_sub_badges = [];

  $unread_notifs_count = 0;
  if ($__uid > 0) {
      $unread_notifs_count = $__badge_count(
          "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'",
          [$__uid]
      );
      $sn_key = 'notif_bell_snoozed_' . $__uid;
      if (!empty($_SESSION[$sn_key]) && (time() - (int)$_SESSION[$sn_key] < 300)) {
          $unread_notifs_count = 0;
      }
  }
  $badges['notifications'] = $unread_notifs_count;

  if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
      // SERVICE STAFF
      // 1. Transactions: Assigned JOs + Pending JOs + My Pending Merchandise
      $staff_assigned_jos = $__badge_count(
          "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND (created_by = ? OR user_id = ? OR assigned_mechanic_id = ?) AND LOWER(COALESCE(status,'')) IN ('pending','reviewed','in progress','awaiting parts')",
          [$myStationId, $__uid, $__uid, $__uid]
      );
      $staff_merch_pending = $__badge_count(
          "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND staff_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation','pending_validation')",
          [$myStationId, $__uid]
      );
      $badges['transactions'] = $staff_assigned_jos + $staff_merch_pending;

      // 2. Fuel Management: Pending Fuel Readings needing entry/validation
      $staff_fuel_pending = $__badge_count(
          "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND staff_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','pending_validation')",
          [$myStationId, $__uid]
      );
      $badges['fuel'] = $staff_fuel_pending;

      // 3. Inventory: Low stock items (reference only)
      $staff_low_stock = $__badge_count(
          "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)",
          [$myStationId]
      );
      $badges['inventory'] = $staff_low_stock;



  } elseif (in_array($role, ['manager', 'supervisor'])) {
      // MANAGER
      // 1. Transactions: Pending Job Orders + Pending Merchandise Transactions + Transaction Adjustment Requests
      $mgr_pending_jos = $__badge_count(
          "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','reviewed')",
          [$myStationId]
      );
      $mgr_pending_merch = $__badge_count(
          "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation')",
          [$myStationId]
      );
      $mgr_pending_adj = $__badge_count(
          "SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
          [$myStationId]
      );
      $badges['transactions'] = $mgr_pending_jos + $mgr_pending_merch + $mgr_pending_adj;

      // 2. Fuel Management: Fuel Reading Validation Required + Calibration Review Required + Low/Critical Fuel Levels
      $mgr_fuel_validation = $__badge_count(
          "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
          [$myStationId]
      );
      $mgr_calibration_review = $__badge_count(
          "SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(COALESCE(adjustment_type,'')) LIKE '%calibration%' AND LOWER(COALESCE(status,'')) IN ('pending','pending review')",
          [$myStationId]
      );
      $mgr_low_fuel = $__badge_count(
          "SELECT COUNT(*) FROM fuel_inventory WHERE station_id = ? AND current_level >= 0 AND capacity > 0 AND current_level <= COALESCE(NULLIF(reorder_level, 0), capacity * 0.15, 500)",
          [$myStationId]
      );
      $badges['fuel'] = $mgr_fuel_validation + $mgr_calibration_review + $mgr_low_fuel;

      // 3. Inventory: Low Stock + Critical Stock + Purchase Requests Waiting + Deliveries Waiting for Stock-In
      $mgr_low_stock = $__badge_count(
          "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)",
          [$myStationId]
      );
      $mgr_purchase_reqs = $__badge_count(
          "SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status IN ('Pending','Pending Manager Review')",
          [$myStationId]
      );
      $mgr_deliv_waiting = $__badge_count(
          "SELECT COUNT(*) FROM purchase_orders WHERE station_id = ? AND status IN ('Approved','Pending Stock-In')",
          [$myStationId]
      );
      $badges['inventory'] = $mgr_low_stock + $mgr_purchase_reqs + $mgr_deliv_waiting;

      // 4. Customers: Pending Customer Requests (from staff)
      $mgr_cust_reqs = $__badge_count(
          "SELECT COUNT(*) FROM customer_requests WHERE station_id = ? AND LOWER(status) = 'pending'",
          [$myStationId]
      );
      $badges['customers']     = $mgr_cust_reqs;
      $badges['mgr_customers'] = $mgr_cust_reqs;
  } elseif (in_array($role, ['admin', 'superadmin', 'developer'])) {
      // ADMIN
      if ($myStationId > 0) {
          // 1. Inventory: Critical Stock + Purchase Orders
          $admin_crit_stock = $__badge_count(
              "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(NULLIF(si.critical_level, 0), NULLIF(ip.critical_level, 0), NULLIF(si.reorder_level, 0), NULLIF(ip.min_stock, 0), 10)",
              [$myStationId]
          );
          $admin_pos = $__badge_count(
              "SELECT COUNT(*) FROM purchase_orders WHERE station_id = ? AND status IN ('Pending Admin Review', 'Submitted', 'Pending Approval')",
              [$myStationId]
          );
          $admin_inv_total = $admin_crit_stock + $admin_pos;
          $badges['inventory']       = $admin_inv_total;
          $badges['admin_inventory'] = $admin_inv_total;

          // 2. Product & Pricing: New Product Requests + Price Change Approvals
          $admin_price_change = $__badge_count(
              "SELECT COUNT(*) FROM pending_price_approvals WHERE station_id = ? AND status = 'pending'",
              [$myStationId]
          );
          $badges['prod_pricing']          = $admin_price_change;
          $badges['mgr_product_pricing']   = $admin_price_change;
          $badges['admin_product_pricing'] = $admin_price_change;

          // 3. Reports
          $badges['reports']       = 0;
          $badges['admin_reports'] = 0;

          // 4. Fuel Management Oversight
          $admin_fuel_pending = $__badge_count(
              "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
              [$myStationId]
          );
          $badges['fuel']                  = $admin_fuel_pending;
          $badges['admin_fuel']            = $admin_fuel_pending;
          $badges['admin_fuel_management'] = $admin_fuel_pending;
      } else {
          $badges['inventory']             = 0;
          $badges['admin_inventory']       = 0;
          $badges['prod_pricing']          = 0;
          $badges['mgr_product_pricing']   = 0;
          $badges['admin_product_pricing'] = 0;
          $badges['reports']               = 0;
          $badges['admin_reports']         = 0;
          $badges['fuel']                  = 0;
          $badges['admin_fuel']            = 0;
          $badges['admin_fuel_management'] = 0;
      }
  }

  // Auto-deduct/clear sidebar badge for the currently active page/section
  $active_badge_keys = [];
  if (strpos($page_id, 'inventory') !== false || strpos($page_id, 'stock') !== false || strpos($page_id, 'delivery') !== false) {
      $active_badge_keys = ['inventory', 'admin_inventory', 'inv_merch', 'inv_fuel', 'inv_record_delivery'];
  } elseif (strpos($page_id, 'transaction') !== false || strpos($page_id, 'jo') !== false || strpos($page_id, 'job') !== false) {
      $active_badge_keys = ['transactions', 'admin_transactions'];
  } elseif (strpos($page_id, 'fuel') !== false) {
      $active_badge_keys = ['fuel', 'admin_fuel', 'admin_fuel_management'];
  } elseif (strpos($page_id, 'customer') !== false) {
      $active_badge_keys = ['customers', 'mgr_customers'];
  } elseif (strpos($page_id, 'price') !== false || strpos($page_id, 'pricing') !== false || strpos($page_id, 'product') !== false) {
      $active_badge_keys = ['prod_pricing', 'mgr_product_pricing', 'admin_product_pricing'];
  } elseif (strpos($page_id, 'report') !== false) {
      $active_badge_keys = ['reports', 'admin_reports'];
  } elseif (strpos($page_id, 'notification') !== false) {
      $active_badge_keys = ['notifications'];
  }

  foreach ($active_badge_keys as $abk) {
      $badges[$abk] = 0;
      $fuel_sub_badges[$abk] = 0;
      if ($__uid > 0) {
          try {
              $stmt_seen = $pdo->prepare("INSERT INTO user_preferences (user_id, preference_key, preference_value, updated_at) VALUES (?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE preference_value = NOW(), updated_at = NOW()");
              $stmt_seen->execute([$__uid, 'badge_seen_' . $abk]);
          } catch (Exception $e) {}
      }
  }

  // Resolve current logged-in user profile & live profile picture from database
  $user = isset($user) && is_array($user) ? $user : (isset($me) && is_array($me) ? $me : current_user());
  if (isset($pdo) && !empty($user['id'])) {
      try {
          $st_pic = $pdo->prepare("SELECT profile_picture, first_name, last_name, name, username, role FROM users WHERE id = ? LIMIT 1");
          $st_pic->execute([$user['id']]);
          $db_usr = $st_pic->fetch(PDO::FETCH_ASSOC);
          if ($db_usr) {
              foreach ($db_usr as $k => $v) {
                  if ($v !== null && $v !== '') {
                      $user[$k] = $v;
                  }
              }
          }
      } catch (Exception $e) {}
  }
  $_SESSION['user'] = array_merge($_SESSION['user'] ?? [], $user);

  // Header Bell Unread Count = unread system notifications count for this user
  $header_unread_count = $badges['notifications'] ?? 0;

  foreach($items as $it){
    if (($it['id'] ?? '') === 'dashboard') {
        if (in_array($role, ['manager','supervisor','admin','superadmin'])) {
            continue;
        }
        $dash_href = $base_path;
        if (in_array($role, ['staff','cashier','pump_attendant'])) $dash_href .= 'staff_dashboard.php';
        else $dash_href .= 'dashboard.php';
        $dash_active = in_array($effective_page_id, ['dashboard','staff_dashboard','manager_dashboard','admin_dashboard']) ? 'active' : '';
        echo '<div class="nav-item-wrapper">';
        echo '<a class="nav-item '.$dash_active.'" href="'.htmlspecialchars($dash_href).'" data-tooltip="Dashboard">';
        echo '<span class="ico" style="margin-right:10px;width:24px;text-align:center;flex-shrink:0;"><i class="fas fa-gauge"></i></span>';
        echo '<span class="nav-label" style="flex-grow:1;font-size:15px;font-weight:600;">Dashboard</span>';
        echo '</a>';
        echo '</div>';
        continue;
    }

    $has_sub = !empty($it['sub_items']);
    $active = '';
    $parent_active = false;
    $matched_sub_idx = -1;

    if ($has_sub) {
        foreach ($it['sub_items'] as $sidx => $sub) {
            if (petron_is_nav_item_active($sub, $effective_page_id)) {
                $parent_active = true;
                $matched_sub_idx = $sidx;
                break;
            }
        }
    } else {
        if (petron_is_nav_item_active($it, $effective_page_id)) {
            $active = 'active';
        }
    }

    echo '<div class="nav-item-wrapper">';

    if ($has_sub) {
        // Parent folder only indicates open/closed state; it does NOT get the red 'active' background
        $parent_cls = $parent_active ? 'nav-item has-submenu sub-open' : 'nav-item has-submenu';
        echo '<a class="'.$parent_cls.'" href="'.htmlspecialchars($it['href']).'" data-tooltip="'.htmlspecialchars($it['label']).'" onclick="toggleSidebarSub(event,\'sub-'.htmlspecialchars($it['id']).'\')">';
        echo '<span class="ico" style="margin-right:10px;width:24px;text-align:center;flex-shrink:0;"><i class="'.htmlspecialchars($it['ico']).'"></i></span>';
        echo '<span class="nav-label" style="flex-grow:1;font-size:15px;font-weight:600;">'.htmlspecialchars($it['label']).'</span>';
        echo '<i class="fas fa-chevron-down" style="font-size:11px;transition:transform .3s;'.($parent_active?'transform:rotate(180deg)':'').'"></i>';
        echo '</a>';

        $display = $parent_active ? 'block' : 'none';
        echo '<div id="sub-'.htmlspecialchars($it['id']).'" style="display:'.$display.';background:transparent;border-left:3px solid rgba(255,255,255,.2);margin-left:0;padding-left:0;">';
        foreach ($it['sub_items'] as $sidx => $sub) {
            // Strictly the matched sub-item is active (highlighted red)
            $is_sub_act = ($matched_sub_idx === $sidx);
            $sub_active = $is_sub_act ? 'active' : '';
            echo '<a class="nav-item sidebar-sub-item '.$sub_active.'" href="'.htmlspecialchars($sub['href']).'" style="padding:7px 15px 7px 42px;min-height:auto;" data-tooltip="'.htmlspecialchars($sub['label'] ?? '').'">';
            echo '<span class="ico" style="margin-right:8px;width:14px;text-align:center;flex-shrink:0;"><i class="fas fa-circle" style="font-size:5px;opacity:.65;"></i></span>';
            echo '<span style="flex-grow:1;line-height:1.35;">';
            echo '<span style="display:block;font-size:15px;font-weight:600;">'.htmlspecialchars($sub['label'] ?? '').'</span>';
            echo '</span>';
            echo '</a>';
        }
        echo '</div>';

    } else {
        $href  = htmlspecialchars($it['href'] ?? '#');
        $label = htmlspecialchars($it['label'] ?? '');
        $ico   = htmlspecialchars($it['ico'] ?? 'fas fa-link');
        echo '<a class="nav-item '.$active.'" href="'.$href.'" data-tooltip="'.$label.'">';
        echo '<span class="ico" style="margin-right:10px;width:24px;text-align:center;flex-shrink:0;"><i class="'.$ico.'"></i></span>';
        echo '<span class="nav-label" style="flex-grow:1;font-size:15px;font-weight:600;">'.$label.'</span>';
        echo '</a>';
    }

    echo '</div>';
  }
  ?>
      </nav>
    </div>

    <!-- â”€â”€ Sidebar Identity Footer â”€â”€ -->
    <?php
    $sid_first = trim($user['first_name'] ?? '');
    $sid_last  = trim($user['last_name']  ?? '');
    if ($sid_first !== '' || $sid_last !== '') {
        $sid_name = strtoupper(trim("$sid_first $sid_last"));
    } else {
        $sid_name = strtoupper($user['username'] ?? 'USER');
    }
    $sid_role = strtoupper(normalize_role($user['role'] ?? 'Staff'));
    ?>
    <div class="sidebar-identity-footer" title="<?php echo htmlspecialchars("$sid_name - $sid_role"); ?>">
        <div class="sif-avatar">
            <?php if (!empty($user['profile_picture'])): ?>
                <img src="<?php echo htmlspecialchars($app_base_path . '/' . ltrim($user['profile_picture'], '/')); ?>"
                     alt="<?php echo htmlspecialchars($sid_name); ?>">
            <?php else: ?>
                <i class="fas fa-user-circle"></i>
            <?php endif; ?>
        </div>
        <span class="sif-name"><?php echo htmlspecialchars($sid_name); ?></span>
        <div class="sif-role-line">
            <span class="sif-dash">-</span>
            <span class="sif-role"><?php echo htmlspecialchars($sid_role); ?></span>
        </div>
    </div>

  </aside>

  <?php
  // Legacy seen tracking is kept for compatibility, but sidebar badges now use
  // live pending/action counts and stay visible until items are resolved.
  $badge_page_map = [
      // STAFF pages
      'staff_fuel_deliveries_history'             => ['staff_fuel_del_history'],
      'staff_delivery_history'                    => ['staff_delivery_history'],
      'staff_transactions_hub'                    => ['staff_new_transaction'],
      // MANAGER pages
      'manager_validated_transactions'            => ['validated_transactions_manager'],
      'manager_stock_request_review'              => ['mgr_stock_review'],
      'manager_fuel_transaction_validation'       => ['fuel_transactions_validation', 'fuel_transactions'],
      'manager_fuel_daily_ops'                    => ['fuel_reconciliation', 'fuel_variance_report'],
      'manager_fuel_reconciliation'               => ['fuel_reconciliation', 'fuel_variance_report'],
      'manager_request_data_management'           => ['manager_request_data_management'],
      // ADMIN pages
      'admin_purchase_orders'                     => ['admin_purchase_orders'],
      'admin_request_data_management'             => ['admin_request_data_management'],
      'admin_voided_transactions'                 => ['admin_voided_transactions'],
      'admin_fuel_transactions_oversight'         => ['admin_fuel_transactions_oversight'],
  ];
  $badge_modules_to_mark = $badge_page_map[$page_id] ?? [];
  ?>

  <?php if (!empty($badge_modules_to_mark)): ?>
  <script>
  // Auto-mark badge modules as seen when this page loads
  (function() {
      var API = '<?php echo $app_base_path; ?>/backend/api/badge_seen.php';
      var modules = <?php echo json_encode($badge_modules_to_mark); ?>;
      function markSeen(mod) {
          fetch(API, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ module: mod }),
              credentials: 'same-origin'
          }).catch(function(){});
      }
      // Small delay so page loads first, then mark seen
      setTimeout(function() {
          modules.forEach(markSeen);
      }, 800);
  })();
  </script>
  <?php endif; ?>

  <script>
  // Keep live action badges visible. They represent unresolved work, so clicking
  // a module should not hide a badge until the underlying item is completed.
  document.addEventListener('DOMContentLoaded', function() {
      var API = '<?php echo $app_base_path; ?>/backend/api/badge_seen.php';

      // Map: filename base (without .php) → badge module keys to mark seen
      var navBadgeMap = {
          // Staff
          'staff_fuel_deliveries_history':      ['staff_fuel_del_history'],
          'staff_delivery_history':             ['staff_delivery_history'],
          'staff_transactions_hub':             ['staff_new_transaction'],
          // Manager
          'manager_validated_transactions':     ['validated_transactions_manager'],
          'manager_stock_request_review':       ['mgr_stock_review'],
          'manager_fuel_transaction_validation': ['fuel_transactions_validation', 'fuel_transactions'],
          'manager_fuel_deliveries_validation':  ['fuel_deliveries_validation', 'fuel_deliveries'],
          'manager_fuel_daily_ops':             ['fuel_variance_report', 'fuel_reconciliation'],
          'manager_fuel_reconciliation':        ['fuel_variance_report', 'fuel_reconciliation'],
          'manager_request_data_management':    ['manager_request_data_management'],
          // Admin
          'admin_purchase_orders':              ['admin_purchase_orders'],
          'admin_request_data_management':      ['admin_request_data_management'],
          'admin_voided_transactions':          ['admin_voided_transactions'],
          'admin_fuel_transactions_oversight':  ['admin_fuel_transactions_oversight'],
      };

      function markSeen(modules) {
          modules.forEach(function(mod) {
              fetch(API, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ module: mod }),
                  credentials: 'same-origin'
              }).catch(function(){});
          });
      }

      function recalculateParentBadge(container) {
          if (!container) return;
          var parentLink = container.previousElementSibling;
          if (!parentLink) return;
          
          var parentBadgeSpan = parentLink.querySelector('span[data-badge]');
          if (!parentBadgeSpan) return;
          
          // Sum of all sub badges inside container
          var sum = 0;
          container.querySelectorAll('a.sidebar-sub-item span[data-badge]').forEach(function(span) {
              var val = parseInt(span.textContent, 10) || 0;
              sum += val;
          });
          
          if (sum > 0) {
              parentBadgeSpan.textContent = sum;
          } else {
              parentBadgeSpan.remove();
          }
      }

      // Attach click handler to all nav-items in the sidebar
      var sidebar = document.getElementById('mainSidebar');
      if (!sidebar) return;

      // 1. Keep badges visible on click; only preserve legacy seen tracking.
      sidebar.querySelectorAll('a.nav-item').forEach(function(link) {
          link.addEventListener('click', function() {
              // Determine module key from href to call badge_seen
              var href = link.getAttribute('href') || '';
              var base = href.split('/').pop().split('?')[0].replace('.php','');
              if (navBadgeMap[base]) {
                  markSeen(navBadgeMap[base]);
              }
          });
      });

      // 2. No visual badge clearing on load. Server-rendered counts are authoritative.
  });
  </script>

  <!-- Mobile Sidebar Backdrop (shown only on mobile when sidebar is open) -->
  <div class="mobile-sidebar-backdrop" id="mobileSidebarBackdrop"></div>

  <!-- GLOBAL TOP HEADER -->
    <script>
    // Fallback global stubs — ensures onclick attributes don't throw
    // These will be replaced by full implementations later in the file if available.
    window.petronToggleSidebar = window.petronToggleSidebar || function(e){
        try { console && console.warn && console.warn('petronToggleSidebar fallback'); } catch(e){}
        try {
            var s = document.getElementById('mainSidebar');
            if (s) s.classList.toggle('collapsed');
        } catch(err){}
    };
    window.petronToggleNotif = window.petronToggleNotif || function(e){
        try { console && console.warn && console.warn('petronToggleNotif fallback'); } catch(e){}
        try { var nd = document.getElementById('notificationDropdown'); if (nd) nd.classList.toggle('show'); } catch(err){}
    };
    window.petronToggleProfile = window.petronToggleProfile || function(e){
        try { console && console.warn && console.warn('petronToggleProfile fallback'); } catch(e){}
        try { var pd = document.getElementById('profileDropdown'); if (pd) pd.classList.toggle('show'); } catch(err){}
    };
    window.petronToggleTheme = window.petronToggleTheme || function(e){
        try { console && console.warn && console.warn('petronToggleTheme fallback'); } catch(e){}
        try { document.body.classList.toggle('dark-theme'); } catch(err){}
    };
    </script>
    <header class="top-header">
        <div class="header-left">
            <!-- Sidebar Toggle Button -->
            <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" aria-label="Toggle Sidebar" onclick="petronToggleSidebar(event)" style="margin-right: 15px; z-index: 99999 !important; pointer-events: auto !important; position: relative !important; cursor: pointer !important;">
                <i class="fas fa-bars" id="sidebarToggleIcon" style="pointer-events: none !important;"></i>
            </button>
            <?php 
                $raw_logo = $station_settings['logo'] ?? $station_settings['company_logo'] ?? $station_settings['system_logo'] ?? '';
                $raw_logo_clean = ltrim(preg_replace('/^(\.\.\/)+/', '', trim($raw_logo)), '/');
                $logo_file_sys = !empty($raw_logo_clean) ? __DIR__ . '/../' . $raw_logo_clean : '';
                
                $is_logo_removed = ($raw_logo === 'none' || $raw_logo_clean === 'none' || $raw_logo === 'removed' || $raw_logo_clean === 'removed');
                $has_custom_logo = (!empty($raw_logo_clean) && !$is_logo_removed && file_exists($logo_file_sys));
                
                if ($is_logo_removed) {
                    $logo_path = '';
                    $show_header_logo = false;
                } elseif ($has_custom_logo) {
                    $logo_path = $app_base_path . '/' . $raw_logo_clean;
                    $show_header_logo = true;
                } elseif (file_exists(__DIR__ . '/../assets/img/Petron Logo.png')) {
                    $logo_path = $app_base_path . '/assets/img/Petron%20Logo.png';
                    $show_header_logo = true;
                } elseif (file_exists(__DIR__ . '/../assets/img/petron_logo.png')) {
                    $logo_path = $app_base_path . '/assets/img/petron_logo.png';
                    $show_header_logo = true;
                } else {
                    $logo_path = $app_base_path . '/assets/img/Petron%20Logo.png';
                    $show_header_logo = true;
                }
                $fallback_logo = $app_base_path . '/assets/img/Petron%20Logo.png';
                $system_name = $station_settings['system_name'] ?? 'Petron Station Management System';
                $logo_ver = file_exists($logo_file_sys) ? filemtime($logo_file_sys) : (file_exists(__DIR__ . '/../assets/img/Petron Logo.png') ? filemtime(__DIR__ . '/../assets/img/Petron Logo.png') : time());
                $logo_display_src = !empty($logo_path) ? ($logo_path . (strpos($logo_path, '?') === false ? '?v=' . $logo_ver : '')) : '';
            ?>
            <img src="<?php echo htmlspecialchars($logo_display_src); ?>" 
                 <?php if ($show_header_logo): ?>
                 onerror="if(this.src.indexOf('Petron%20Logo.png')===-1 && this.src.indexOf('petron_logo.png')===-1){ this.src='<?php echo htmlspecialchars($fallback_logo); ?>'; }" 
                 <?php endif; ?>
                 alt="Petron Logo" class="brand-mark" id="petronLogo" style="<?php echo $show_header_logo ? 'display: inline-block !important;' : 'display: none !important;'; ?> height: 48px; width: auto; max-width: 60px; object-fit: contain; vertical-align: middle; margin-right: 12px; flex-shrink: 0;">
            <div class="brand-text">
                <div class="brand-title" id="headerSystemName"><?php echo htmlspecialchars($system_name); ?></div>
                <?php if ($station_name && $role !== 'superadmin'): ?>
                <div style="font-size: 14px; color: #666; margin-top: 2px; font-weight: 500;">
                    <i class="fas fa-building" style="font-size: 12px;"></i> <?php echo htmlspecialchars($station_name); ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="display: none; flex-direction: column; margin-left: 20px;"> <!-- Hidden in left block, moved logic if needed or kept simple -->
                <div style="font-size: 1.1em; font-weight: bold; color: var(--petron-blue);">
                    <?php
                    if ($role === 'superadmin') {
                        echo 'Super Admin Console';
                    } elseif ($role === 'admin') {
                        echo 'Admin Console';
                    } else {
                        echo 'Staff Console';
                    }
                    ?>
                </div>
                <div id="live-clock" style="font-size: 0.85em; color: #666; font-weight: 500;">
                    <i class="far fa-clock"></i> Loading time...
                </div>
            </div>
        <?php 
        $show_search_bar = true;
        $show_notifications_widget = true;
        if (function_exists('get_module_setting')) {
            $show_search_bar = (bool) get_module_setting('dashboard', 'enable_search_bar', true);
            $show_notifications_widget = (bool) (get_module_setting('dashboard', 'enable_notifications_widget', true) && get_module_setting('notifications', 'enable_notifications', true));
        }
        ?>
        </div><!-- /.header-left -->
        <div class="header-center" style="display: flex; align-items: center; justify-content: center; flex: 1; margin: 0 20px; <?php if (!$show_search_bar) echo 'display: none !important;'; ?>">
            <div id="searchWrapper" style="position: relative; width: 100%; max-width: 440px; pointer-events: auto;">
                <form autocomplete="off" onsubmit="return false;" style="margin:0; padding:0; width:100%;">
                    <!-- Hidden decoy inputs to deflect browser identity/credential autofill -->
                    <input type="text" name="petron_fake_username_decoy" style="display:none !important;" tabindex="-1" autocomplete="username" />
                    <input type="password" name="petron_fake_password_decoy" style="display:none !important;" tabindex="-1" autocomplete="current-password" />
                    <div style="position: relative; display: flex; align-items: center;">
                        <i class="fas fa-search" style="position: absolute; left: 16px; color: #94a3b8; font-size: 16px; pointer-events: none;"></i>
                        <?php
                        $is_superadmin_dev_role = in_array($role, ['superadmin', 'developer']);
                        if ($is_superadmin_dev_role) {
                            $search_input_placeholder = 'Search Station, User, Module, Setting, Audit Log, or System Reference...';
                        } elseif (in_array($role, ['admin', 'manager'])) {
                            $search_input_placeholder = 'Search Customer, Transaction, JO, Product, Delivery, PO, AR, Stock Request, or Reference No...';
                        } else {
                            // Staff: can search Transactions, Customers, Vehicles, Products, Job Orders, Fuel Readings, Deliveries, Calendar, Reports, POs, AR, Stock Requests
                            $search_input_placeholder = 'Search Customer, Transaction, JO, Product, Delivery, PO, or Reference No...';
                        }
                        ?>
                        <input type="search" id="searchInput" 
                               name="petron_global_system_search"
                               placeholder="<?= htmlspecialchars($search_input_placeholder) ?>" 
                               autocomplete="chrome-off" 
                               autocapitalize="off" 
                               autocorrect="off"
                               spellcheck="false" 
                               data-lpignore="true" 
                               data-1p-ignore="true"
                               data-bwignore="true"
                               data-form-type="other" 
                               aria-autocomplete="none" 
                               readonly="readonly"
                               onpointerdown="this.removeAttribute('readonly');"
                               onfocus="this.removeAttribute('readonly');"
                               onblur="this.setAttribute('readonly', 'readonly');"
                               style="width: 100%; padding: 9px 16px 9px 42px; border-radius: 20px; border: 1px solid #cbd5e1; font-size: 15px; outline: none; background: #ffffff; color: #0f172a; transition: all 0.15s ease;" />
                    </div>
                </form>
                <div id="searchSuggestions" style="display: none; position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: #ffffff; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15), 0 8px 10px -6px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; max-height: 450px; overflow-y: auto; z-index: 2147483647;"></div>
            </div>
        </div>
        <div class="header-right">
            <!-- Notification Bell -->
            <?php if(in_array($role, ['staff','admin','manager','superadmin','developer']) && $show_notifications_widget): ?>
            <div class="notification-bell" id="notificationBell" onclick="petronToggleNotif(event)" style="z-index: 2147483645 !important; pointer-events: auto !important; position: relative !important; cursor: pointer !important;">
                <i class="fas fa-bell" style="pointer-events: none !important;"></i>
                <span class="badge" id="notificationBadge" data-server-count="<?php echo (int)$header_unread_count; ?>" style="display: <?php echo $header_unread_count > 0 ? 'inline-flex' : 'none'; ?>; pointer-events: none !important;"><?php echo $header_unread_count > 99 ? '99+' : (int)$header_unread_count; ?></span>

                <div class="notif-dropdown" id="notificationDropdown">
                    <div class="notif-dropdown-header">
                        <span>Notifications</span>
                        <div class="notif-header-actions">
                            <button id="markAllReadBtn">Mark All Read</button>
                            <button id="refreshNotificationsBtn"><i class="fas fa-sync"></i></button>
                        </div>
                    </div>
                    <div class="notif-list" id="notificationList" style="max-height: 400px; overflow-y: auto; overflow-x: hidden;">
                        <?php if (!empty($header_notifications)): ?>
                            <?php
                            $header_evt_icons = [
                                'transaction'     => 'fas fa-shopping-cart',
                                'job_order'       => 'fas fa-wrench',
                                'fuel_management' => 'fas fa-gas-pump',
                                'inventory'       => 'fas fa-warehouse',
                                'customer'        => 'fas fa-user',
                                'delivery'        => 'fas fa-truck',
                                'calendar'        => 'fas fa-calendar-alt',
                                'report'          => 'fas fa-chart-bar',
                                'general'         => 'fas fa-bell',
                            ];
                            $header_type_colors = [
                                'success' => '#28a745',
                                'warning' => '#f59e0b',
                                'error'   => '#dc3545',
                                'info'    => '#17a2b8',
                            ];
                            ?>
                            <?php foreach ($header_notifications as $hn): ?>
                                <?php
                                $hn_unread = (($hn['status'] ?? '') === 'unread');
                                $hn_icon = $header_evt_icons[$hn['event_type'] ?? 'general'] ?? $header_evt_icons['general'];
                                $hn_color = $header_type_colors[$hn['type'] ?? 'info'] ?? $header_type_colors['info'];
                                $hn_raw_url = (string)($hn['redirect_url'] ?? '');
                                $hn_href = $header_notif_url($hn_raw_url);
                                $hn_js_url = json_encode($hn_raw_url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                ?>
                                <a class="notif-item<?php echo $hn_unread ? ' unread' : ''; ?>"
                                   href="<?php echo htmlspecialchars($hn_href); ?>"
                                   <?php if ($hn_unread): ?>
                                   onclick="(window.staffMarkRead || window.saMarkRead)(<?php echo (int)$hn['id']; ?>);"
                                   <?php endif; ?>
                                   style="padding:12px 16px;cursor:pointer;display:flex;align-items:flex-start;gap:12px;text-decoration:none;color:inherit;">
                                    <div style="width:48px;height:48px;border-radius:50%;background:<?php echo htmlspecialchars($hn_color); ?>15;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid <?php echo htmlspecialchars($hn_color); ?>30;">
                                        <i class="<?php echo htmlspecialchars($hn_icon); ?>" style="color:<?php echo htmlspecialchars($hn_color); ?>;font-size:20px;"></i>
                                    </div>
                                    <div style="flex:1;min-width:0;line-height:1.3;">
                                        <div style="font-size:14px;color:#050505;margin-bottom:2px;">
                                            <strong style="font-weight:600;"><?php echo htmlspecialchars(function_exists('clean_mojibake') ? clean_mojibake($hn['title'] ?? 'Notification') : ($hn['title'] ?? 'Notification'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                        </div>
                                        <div style="color:#65676B;font-size:13px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;">
                                            <?php echo htmlspecialchars(function_exists('clean_mojibake') ? clean_mojibake($hn['message'] ?? '') : ($hn['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div style="color:<?php echo $hn_unread ? '#002F6C' : '#65676B'; ?>;font-size:12px;font-weight:<?php echo $hn_unread ? '600' : 'normal'; ?>;margin-top:4px;">
                                            <?php echo htmlspecialchars($header_time_ago($hn['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </div>
                                    <?php if ($hn_unread): ?>
                                        <div style="width:10px;height:10px;border-radius:50%;background:#002F6C;flex-shrink:0;margin-top:20px;"></div>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;">
                                <i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:8px;display:block;"></i>No notifications yet.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="notif-dropdown-footer">
                        <a href="<?php echo htmlspecialchars($public_base_url . '/notifications.php'); ?>" style="display:block; text-align:center; padding:8px; font-size:12px; color:var(--petron-blue); text-decoration:none; border-top:1px solid #eee;">
                            View All Notifications
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Profile Dropdown -->
            <?php
            // Build display name from first_name and last_name
            $hdr_first = trim($user['first_name'] ?? '');
            $hdr_last  = trim($user['last_name']  ?? '');
            if ($hdr_first !== '' || $hdr_last !== '') {
                $hdr_display = strtoupper(trim("$hdr_first $hdr_last"));
            } else {
                $hdr_display = strtoupper($user['username'] ?? 'USER');
            }
            $hdr_role = strtoupper(normalize_role($user['role'] ?? 'Staff'));
            // Profile picture: stored in users.profile_picture as relative path
            $hdr_pic = !empty($user['profile_picture']) ? $app_base_path . '/' . ltrim($user['profile_picture'], '/') : '';
            ?>
            <div class="profile-access" id="profileMenu" onclick="petronToggleProfile(event)">
                <?php if ($hdr_pic): ?>
                <img src="<?php echo htmlspecialchars($hdr_pic); ?>" alt="Profile"
                     style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--petron-blue);flex-shrink:0;">
                <?php else: ?>
                <div style="width:36px;height:36px;border-radius:50%;background:var(--petron-blue);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-user" style="color:#fff;font-size:16px;"></i>
                </div>
                <?php endif; ?>
                <div style="text-align: right; margin-left: 8px;">
                    <div style="font-weight: 700; font-size: 14px; color: var(--petron-blue); letter-spacing: 0.3px;">
                        <?php echo htmlspecialchars($hdr_display); ?>
                    </div>
                    <div style="font-size: 12px; font-weight: 600; color: #666; margin-top: 2px; letter-spacing: 0.5px;">
                        <?php echo htmlspecialchars($hdr_role); ?>
                    </div>
                </div>
                <i class="fas fa-caret-down" style="font-size:0.85em; color:#666; margin-left: 6px;"></i>

                <?php
                // Determine dashboard / home URL based on role
                $hdr_role_key = role_key($user['role'] ?? 'staff');
                if ($hdr_role_key === 'superadmin' || $hdr_role_key === 'developer') {
                    $hdr_home_url = $public_base_url . '/super_admin_dashboard.php';
                } elseif ($hdr_role_key === 'admin') {
                    $hdr_home_url = $public_base_url . '/admin_dashboard.php';
                } elseif ($hdr_role_key === 'manager') {
                    $hdr_home_url = $public_base_url . '/manager_dashboard.php';
                } else {
                    $hdr_home_url = $public_base_url . '/staff_dashboard.php';
                }
                ?>
                <div class="profile-dropdown" id="profileDropdown">
                    <a href="<?php echo htmlspecialchars($public_base_url . '/profile.php'); ?>">
                        <i class="fas fa-user-circle" style="margin-right:8px;color:var(--petron-blue);"></i>My Profile
                    </a>
                    <a href="<?php echo htmlspecialchars($public_base_url . '/update_password.php'); ?>">
                        <i class="fas fa-key" style="margin-right:8px;color:var(--petron-blue);"></i>Change Password
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo htmlspecialchars($public_base_url . '/logout.php'); ?>" class="logout">
                        <i class="fas fa-sign-out-alt" style="margin-right:8px;color:#cc0000;"></i>Logout
                    </a>
                </div>
            </div>
        </div>
</header>

  <main class="main" style="padding: 20px 20px 105px 20px !important;">

    <!-- ══ GLOBAL FLASH MESSAGE STYLES â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <style>
    /* â”€â”€ Petron system-wide flash alerts â”€â”€ */
    /* Petron system-wide top-right toast notifications */

    /* â”€â”€ JS-powered toast (bottom-right, for AJAX actions) â”€â”€ */
    #petron-toast-container {
        position: fixed;
        top: 96px;
        right: 22px;
        z-index: 2147483647;
        display: flex;
        flex-direction: column;
        gap: 10px;
        width: min(390px, calc(100vw - 32px));
        pointer-events: none;
        min-height: 0;
        background: transparent;
    }
    /* Hide container and all children when no toast divs present */
    #petron-toast-container:not(:has(.petron-toast)):not(:has(.petron-flash)) {
        display: none !important;
    }
    /* Hide container when no visible toast children */
    #petron-toast-container > *:not(.petron-toast):not(.petron-flash) {
        display: none;
    }
    .petron-toast {
        position: relative;
        width: 100%;
        min-height: 54px;
        padding: 14px 18px;
        border-radius: 10px;
        border: 1px solid #e2e8f0 !important;
        border-left: none !important;
        background: #fff;
        color: #0f172a;
        font-size: 14px;
        font-weight: 600;
        line-height: 1.4;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
        pointer-events: auto;
        animation: toastIn 0.26s ease;
        transition: opacity 0.35s ease, transform 0.35s ease;
        overflow: hidden;
    }
    .petron-toast.toast-success { border-left: none !important; }
    .petron-toast.toast-error   { border-left: none !important; }
    .petron-toast.toast-warning { border-left: none !important; }
    .petron-toast.toast-info    { border-left: none !important; }
    .petron-toast.toast-success .petron-toast-icon { color:#16a34a; }
    .petron-toast.toast-error .petron-toast-icon   { color:#dc2626; }
    .petron-toast.toast-warning .petron-toast-icon { color:#d97706; }
    .petron-toast.toast-info .petron-toast-icon    { color:#2563eb; }
    .petron-toast-close { display: none !important; }
    .petron-toast.toast-hide { opacity:0; transform:translateX(34px); }
    @keyframes toastIn {
        from { opacity:0; transform:translateX(34px); }
        to   { opacity:1; transform:translateX(0); }
    }
    .petron-toast-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
    .petron-toast-body { min-width: 0; flex: 1; }
    .petron-toast-title {
        display: block;
        margin-bottom: 2px;
        color: inherit;
        font-size: 14px;
        font-weight: 800;
    }
    .petron-toast-message {
        display: block;
        color: #475569;
        font-size: 13px;
        font-weight: 600;
        overflow-wrap: anywhere;
    }
    .petron-toast-close {
        position: absolute;
        top: 9px;
        right: 10px;
        width: 24px;
        height: 24px;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: #64748b;
        cursor: pointer;
        font-size: 18px;
        line-height: 1;
    }
    .petron-toast-close:hover { background: #f1f5f9; color: #0f172a; }
    .petron-flash {
        position: fixed;
        top: 96px;
        right: 22px;
        z-index: 2147483647;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        width: min(390px, calc(100vw - 32px));
        min-height: 58px;
        padding: 13px 42px 13px 15px;
        border-radius: 8px;
        border: 1px solid #dbe4f0;
        border-left: 5px solid #2563eb;
        background: #fff;
        color: #0f172a;
        font-size: 14px;
        font-weight: 700;
        line-height: 1.4;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
        animation: toastIn 0.26s ease;
        transition: opacity 0.35s ease, transform 0.35s ease;
    }
    .petron-flash.flash-success { border-left-color:#16a34a; }
    .petron-flash.flash-error   { border-left-color:#dc2626; }
    .petron-flash.flash-warning { border-left-color:#f59e0b; }
    .petron-flash.flash-info    { border-left-color:#2563eb; }
    .petron-flash.flash-success i { color:#16a34a; }
    .petron-flash.flash-error i   { color:#dc2626; }
    .petron-flash.flash-warning i { color:#d97706; }
    .petron-flash.flash-info i    { color:#2563eb; }
    .petron-flash span { color:#475569; overflow-wrap:anywhere; }
    .petron-flash.toast-hide { opacity:0; transform:translateX(34px); }
    .petron-flash .flash-close {
        position: absolute;
        top: 9px;
        right: 10px;
        width: 24px;
        height: 24px;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: #64748b;
        cursor: pointer;
        font-size: 18px;
        line-height: 1;
    }
    .petron-flash .flash-close:hover { background: #f1f5f9; color: #0f172a; }
    @media (max-width: 640px) {
        #petron-toast-container {
            top: 92px;
            right: 12px;
            left: 12px;
            width: auto;
        }
        .petron-flash {
            top: 92px;
            right: 12px;
            left: 12px;
            width: auto;
        }
    }
    @media print {
        #petron-toast-container,
        .petron-flash,
        .petron-pagination-bar { display: none !important; }
    }

    /* Clean Petron Table Pagination Component */
    .petron-pagination-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
        border-top: 1px solid #f1f5f9;
        flex-wrap: wrap;
        gap: 12px;
        background: transparent;
    }
    .petron-paginate-left {
        display: flex;
        align-items: center;
    }
    .petron-paginate-info {
        font-size: 13px;
        color: #64748b;
        font-weight: 600;
    }
    .petron-paginate-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .petron-rows-select-wrap {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .petron-rows-label {
        font-size: 13px;
        color: #64748b;
        font-weight: 600;
    }
    .petron-rows-select,
    select[id*="rowsPerPage"], select[id*="RowsLimit"], select[id*="rowsLimit"],
    .pagination-wrapper select, .pagination-controls select, .client-side-pagination select {
        background: transparent !important;
        background-color: transparent !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 6px !important;
        padding: 4px 8px !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        color: #334155 !important;
        cursor: pointer !important;
        outline: none !important;
        box-shadow: none !important;
    }
    .petron-rows-select:hover, .petron-rows-select:focus,
    select[id*="rowsPerPage"]:hover, select[id*="RowsLimit"]:hover {
        border-color: #94a3b8 !important;
    }

    .petron-page-buttons {
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .petron-page-btn {
        min-width: 32px;
        height: 32px;
        padding: 0 8px;
        border-radius: 6px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #475569;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s ease;
    }
    .petron-page-btn:hover:not(:disabled) {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #0f172a;
    }
    .petron-page-btn.active {
        background: #002F6C !important;
        border-color: #002F6C !important;
        color: #ffffff !important;
        font-weight: 700;
    }
    .petron-page-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
        background: #f8fafc;
    }
    .petron-page-ellipsis {
        padding: 0 4px;
        color: #94a3b8;
        font-size: 13px;
        font-weight: 600;
    }
    </style>


    <!-- â•â• GLOBAL FLASH MESSAGE RENDERER (PHP SESSION â†’ HTML) â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <?php
    $__toast_map = [
        'success' => ['class'=>'success', 'title'=>'Success',     'icon'=>'fa-check-circle',          'keys'=>['success', 'flash_success', 'ok']],
        'error'   => ['class'=>'error',   'title'=>'Error',       'icon'=>'fa-exclamation-circle',    'keys'=>['error', 'flash_error', 'err']],
        'warning' => ['class'=>'warning', 'title'=>'Warning',     'icon'=>'fa-exclamation-triangle',  'keys'=>['warning', 'flash_warning']],
        'info'    => ['class'=>'info',    'title'=>'Information', 'icon'=>'fa-info-circle',           'keys'=>['info', 'flash_info']],
    ];
    $__toasts = [];
    foreach ($__toast_map as $__toast_type => $__toast_meta) {
        foreach ($__toast_meta['keys'] as $__toast_key) {
            if (!empty($_SESSION[$__toast_key])) {
                $__toasts[] = [
                    'type'    => $__toast_meta['class'],
                    'title'   => $__toast_meta['title'],
                    'icon'    => $__toast_meta['icon'],
                    'message' => (string) $_SESSION[$__toast_key],
                ];
                unset($_SESSION[$__toast_key]);
            }
        }
    }
    ?>

    <!-- GLOBAL JS TOAST HELPER - Top-right toast notifications -->
    <div id="petron-toast-container" aria-live="polite" aria-atomic="true"<?php if(empty($__toasts)): ?> style="display:none"<?php endif; ?>>
        <?php foreach ($__toasts as $__toast): ?>
        <div class="petron-toast toast-<?php echo htmlspecialchars($__toast['type'], ENT_QUOTES); ?>" role="status">
            <i class="fas <?php echo htmlspecialchars($__toast['icon'], ENT_QUOTES); ?> petron-toast-icon"></i>
            <span class="petron-toast-body">
                <strong class="petron-toast-title"><?php echo htmlspecialchars($__toast['title'], ENT_QUOTES); ?></strong>
                <span class="petron-toast-message"><?php echo htmlspecialchars(strip_tags($__toast['message']), ENT_QUOTES); ?></span>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
    /**
     * showToast(message, type, duration, title)
     * Shows a top-right toast notification for system feedback.
     * type: 'success' | 'error' | 'warning' | 'info'   (default: 'success')
     * duration: ms (default 4000, use 0 for persistent)
     */
    (function() {
        var icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        var titles = {
            success: 'Success',
            error: 'Error',
            warning: 'Warning',
            info: 'Information'
        };

        function escapeHtml(value) {
            var div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        }

        function dismissToast(toast) {
            if (!toast || toast.classList.contains('toast-hide')) return;
            toast.classList.add('toast-hide');
            setTimeout(function() {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
                // Hide container when no toasts remain
                var container = document.getElementById('petron-toast-container');
                if (container && container.querySelectorAll('.petron-toast, .petron-flash').length === 0) {
                    container.style.display = 'none';
                }
            }, 380);
        }

        function armToast(toast, duration) {
            var defaultDur = (window.petronSystemSettings && window.petronSystemSettings.bannerDuration) ? (window.petronSystemSettings.bannerDuration * 1000) : 5000;
            var timeout = (duration === undefined || duration === null) ? defaultDur : Number(duration);
            var close = toast.querySelector('.petron-toast-close, .flash-close');
            if (close) close.addEventListener('click', function() { dismissToast(toast); });
            if (timeout > 0) {
                setTimeout(function() { dismissToast(toast); }, timeout);
            }
        }

        function normalizeToastType(type) {
            type = String(type || 'success').toLowerCase();
            if (type === 'ok' || type === 'done') return 'success';
            if (type === 'err' || type === 'danger' || type === 'failed' || type === 'fail') return 'error';
            if (type === 'warn') return 'warning';
            if (type === 'information') return 'info';
            return ['success', 'error', 'warning', 'info'].indexOf(type) >= 0 ? type : 'success';
        }

        window.showToast = window.showToast || function(message, type, duration, title) {
            if (String(message == null ? '' : message).trim() === '') return;
            type = normalizeToastType(type);

            // Enforce System Settings Notification Toggles
            if (window.petronSystemSettings) {
                if (type === 'error' && window.petronSystemSettings.enableErrorNotifications === false) {
                    return; // Suppressed by System Settings
                }
                if (type !== 'error' && window.petronSystemSettings.enableSystemNotifications === false) {
                    return; // Suppressed by System Settings
                }
            }
            var container = document.getElementById('petron-toast-container');
            if (!container) return;
            var cleanMsg = String(message).replace(/<[^>]*>?/gm, '').trim();
            container.style.display = 'flex'; // ensure visible when adding toast
            var toast = document.createElement('div');
            toast.className = 'petron-toast toast-' + type;
            toast.setAttribute('role', 'status');
            toast.innerHTML =
                '<i class="fas ' + (icons[type] || icons.info) + ' petron-toast-icon"></i>' +
                '<span class="petron-toast-body">' +
                    '<strong class="petron-toast-title">' + escapeHtml(title || titles[type] || 'Information') + '</strong>' +
                    '<span class="petron-toast-message">' + escapeHtml(cleanMsg) + '</span>' +
                '</span>';
            container.appendChild(toast);
            armToast(toast, duration);
        };


        var sharedShowToast = window.showToast;
        window.showPetronFlash = window.showPetronFlash || function(message, type, duration) {
            sharedShowToast(message, type, duration);
        };

        if (typeof window.toast !== 'function') {
            window.toast = function(message, type) {
                window.showToast(message, type || 'info');
            };
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('#petron-toast-container .petron-toast, .petron-flash').forEach(function(toast) {
                var initDur = (window.petronSystemSettings && window.petronSystemSettings.bannerDuration) ? (window.petronSystemSettings.bannerDuration * 1000) : 5000;
                armToast(toast, initDur);
            });
        });
    })();

    /* ══════════════════════════════════════════════════════════════════════════════
     * GLOBAL PETRON TABLE PAGINATION COMPONENT
     * Rule: IF Total Records <= Default Page Size (10): HIDE Rows per page & Pagination
     * Rule: IF Total Records > 10: SHOW Rows per page (transparent bg) & Pagination
     * Excludes Dashboards
     * ══════════════════════════════════════════════════════════════════════════════ */
    window.initPetronPagination = function(targetContainer, options = {}) {
        const path = window.location.pathname.toLowerCase();
        const isExcluded = path.includes('dashboard') || path.includes('report') || path.includes('fuel_sales') || path.includes('summary');
        if (isExcluded) return;

        let containers = [];
        if (targetContainer) {
            containers = typeof targetContainer === 'string' ? Array.from(document.querySelectorAll(targetContainer)) : [targetContainer];
        } else {
            containers = Array.from(document.querySelectorAll('.afto-table-card, .table-container, .table-responsive, [data-petron-paginate="true"]'));
        }

        containers.forEach(container => {
            if (!container) return;
            const table = container.querySelector('table');
            if (!table) return;
            if (table.dataset.noPaginate === 'true' || table.closest('.dashboard-card, .dashboard-section')) return;

            const tbody = table.querySelector('tbody');
            if (!tbody) return;

            const allRows = Array.from(tbody.querySelectorAll('tr:not(.no-records-row)'));
            const totalRecords = allRows.length;
            const defaultPageSize = options.defaultPageSize || 10;

            let paginationBar = container.querySelector('.petron-pagination-bar, .client-pagination');
            if (!paginationBar) {
                paginationBar = document.createElement('div');
                paginationBar.className = 'petron-pagination-bar';
                container.appendChild(paginationBar);
            }

            // Show all rows unconditionally without page limits
            if (paginationBar) {
                paginationBar.style.display = 'none';
            }
            allRows.forEach(row => {
                if (!row.classList.contains('search-hidden')) {
                    row.style.display = '';
                }
            });
            return;
            let rowsPerPage = defaultPageSize;

            function render() {
                const totalPages = Math.ceil(totalRecords / rowsPerPage) || 1;
                if (currentPage > totalPages) currentPage = totalPages;
                if (currentPage < 1) currentPage = 1;

                const startIdx = (currentPage - 1) * rowsPerPage;
                const endIdx = Math.min(startIdx + rowsPerPage, totalRecords);

                allRows.forEach((row, i) => {
                    row.style.display = (i >= startIdx && i < endIdx) ? '' : 'none';
                });

                const showingStart = totalRecords === 0 ? 0 : startIdx + 1;
                const summaryText = `Showing ${showingStart}–${endIdx} of ${totalRecords} entries`;

                let pageBtns = '';
                const prevDisabled = currentPage === 1 ? 'disabled' : '';
                const nextDisabled = currentPage === totalPages ? 'disabled' : '';

                pageBtns += `<button type="button" class="petron-page-btn" ${prevDisabled} data-page="${currentPage - 1}"><i class="fas fa-chevron-left"></i></button>`;

                let pagesToDisplay = [];
                if (totalPages <= 7) {
                    for (let i = 1; i <= totalPages; i++) pagesToDisplay.push(i);
                } else {
                    if (currentPage <= 4) {
                        pagesToDisplay = [1, 2, 3, 4, 5, '...', totalPages];
                    } else if (currentPage >= totalPages - 3) {
                        pagesToDisplay = [1, '...', totalPages - 4, totalPages - 3, totalPages - 2, totalPages - 1, totalPages];
                    } else {
                        pagesToDisplay = [1, '...', currentPage - 1, currentPage, currentPage + 1, '...', totalPages];
                    }
                }

                pagesToDisplay.forEach(p => {
                    if (p === '...') {
                        pageBtns += `<span class="petron-page-ellipsis">...</span>`;
                    } else {
                        const activeClass = p === currentPage ? 'active' : '';
                        pageBtns += `<button type="button" class="petron-page-btn ${activeClass}" data-page="${p}">${p}</button>`;
                    }
                });

                pageBtns += `<button type="button" class="petron-page-btn" ${nextDisabled} data-page="${currentPage + 1}"><i class="fas fa-chevron-right"></i></button>`;

                paginationBar.innerHTML = `
                    <div class="petron-paginate-left">
                        <span class="petron-paginate-info">${summaryText}</span>
                    </div>
                    <div class="petron-paginate-right">
                        <div class="petron-page-buttons">
                            ${pageBtns}
                        </div>
                    </div>
                `;

                paginationBar.querySelectorAll('.petron-page-btn[data-page]').forEach(btn => {
                    btn.addEventListener('click', function() {
                        if (this.disabled) return;
                        const p = parseInt(this.dataset.page, 10);
                        if (p && p >= 1 && p <= totalPages) {
                            currentPage = p;
                            render();
                            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    });
                });
            }

            render();
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        const p = window.location.pathname.toLowerCase();
        if (!p.includes('dashboard') && !p.includes('report') && !p.includes('fuel_sales') && !p.includes('summary')) {
            window.initPetronPagination();
        }
    });
    </script>


    <!-- Page content starts here -->      
    <script>
        
    function updateClock() {
        const el = document.getElementById('live-clock');
        if (!el) return;
        const now = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
        el.innerHTML = '<i class="far fa-clock"></i> ' + now.toLocaleDateString('en-US', options);
    }
    setInterval(updateClock, 1000);
    updateClock();

    // (diagnostic click tracker removed)

    // Initialize page data for notification system
    window.pageData = {
        role: '<?php echo htmlspecialchars($role); ?>',
        userId: '<?php echo htmlspecialchars($user['id'] ?? ''); ?>',
        stationId: '<?php echo htmlspecialchars($station_id ?? $myStationId ?? ''); ?>',
        appBasePath: '<?php echo htmlspecialchars($app_base_path ?? ""); ?>'
    };

    window.resolveRedirectUrl = function(url) {
        if (!url || url === '#' || url === '' || url === 'null') return '#';
        if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('/')) return url;
        // Relative path from search.php (e.g. "staff_inventory.php") — resolve to /public/
        var base = (window.pageData && window.pageData.appBasePath) ? window.pageData.appBasePath : '';
        return base + '/public/' + url;
    };


    // ── Global header action functions (called via onclick attributes) ──────────
    
    // FIXED: Ensure functions are immediately available and properly handle all edge cases
    
    window.petronToggleSidebar = function(e) {
        console.log('Sidebar toggle clicked'); // Debug
        if (e) {
            e.stopPropagation();
            e.preventDefault(); // Prevent any default behavior
        }
        
        // Debounce - prevent multiple rapid clicks
        var now = Date.now();
        if (window.__petronSidebarLastToggleAt && (now - window.__petronSidebarLastToggleAt) < 300) {
            console.log('Sidebar toggle debounced');
            return;
        }
        window.__petronSidebarLastToggleAt = now;
        
        // Close any open header dropdowns when toggling sidebar
        try{ if (typeof closeAllHeaderDropdowns === 'function') closeAllHeaderDropdowns(); }catch(e){}
        
        var s = document.getElementById('mainSidebar');
        var backdrop = document.getElementById('mobileSidebarBackdrop');
        var icon = document.getElementById('sidebarToggleIcon');
        var main = document.querySelector('.main');
        
        if (!s) {
            console.error('Sidebar element not found');
            return;
        }
        
        // Mobile toggle
        if (window.innerWidth < 992) {
            var isOpen = s.classList.contains('mobile-open');
            s.classList.toggle('mobile-open');
            if (backdrop) backdrop.classList.toggle('active');
            document.body.style.overflow = isOpen ? '' : 'hidden';
            console.log('Mobile sidebar toggled:', !isOpen ? 'open' : 'closed');
        } 
        // Desktop toggle
        else {
            var isCollapsed = s.classList.contains('collapsed');
            
            // Use requestAnimationFrame to prevent flickering
            requestAnimationFrame(function() {
                if (isCollapsed) {
                    // Expand sidebar
                    s.classList.remove('collapsed');
                    if (icon) icon.className = 'fas fa-bars';
                    if (main) { 
                        main.style.left = '250px'; 
                        main.classList.remove('sidebar-collapsed'); 
                    }
                    document.body.classList.add('sidebar-expanded');
                    document.body.classList.remove('sidebar-collapsed');
                    localStorage.setItem('sidebarState', 'expanded');
                    console.log('Sidebar expanded');
                } else {
                    // Collapse sidebar
                    s.classList.add('collapsed');
                    if (icon) icon.className = 'fas fa-chevron-right';
                    if (main) { 
                        main.style.left = '70px'; 
                        main.classList.add('sidebar-collapsed'); 
                    }
                    document.body.classList.add('sidebar-collapsed');
                    document.body.classList.remove('sidebar-expanded');
                    localStorage.setItem('sidebarState', 'collapsed');
                    console.log('Sidebar collapsed');
                }
            });
        }
    };

    window.petronToggleNotif = function(e) {
        if (e && e.target && e.target.closest && e.target.closest('.notif-dropdown')) {
            return;
        }
        if (e) {
            if (e.preventDefault) e.preventDefault();
            if (e.stopPropagation) e.stopPropagation();
        }

        var now = Date.now();
        if (window.__petronNotifLastToggleAt && (now - window.__petronNotifLastToggleAt) < 250) {
            return;
        }
        window.__petronNotifLastToggleAt = now;

        var nd = document.getElementById('notificationDropdown');
        if (!nd) {
            console.error('Notification dropdown not found');
            return;
        }

        var wasShowing = nd.classList.contains('show') || nd.style.display === 'block';
        if (window.petronHeaderToggle) {
            window.petronHeaderToggle('notificationDropdown', '#notificationBell');
        } else {
            var pd = document.getElementById('profileDropdown');
            if (pd) { pd.classList.remove('show'); pd.style.display = 'none'; }
            nd.classList.toggle('show');
            nd.style.display = nd.classList.contains('show') ? 'block' : 'none';
        }

        var isShowing = !wasShowing && (nd.classList.contains('show') || nd.style.display === 'block');
        if (isShowing) {
            if (typeof window.loadStaffNotifications === 'function') window.loadStaffNotifications();
            else if (typeof window.saLoadNotifications === 'function') window.saLoadNotifications();
        }
    };

    window.petronToggleProfile = function(e) {
        // Prefer shared toggle implementation when available
        if (window.petronHeaderToggle) {
            if (e && e.preventDefault) e.preventDefault();
            window.petronHeaderToggle('profileDropdown', '#profileMenu');
            return;
        }
        console.log('Profile menu clicked'); // Debug
        // If the click originates from an actionable link inside the dropdown, allow it
        if (e && e.target && e.target.closest && e.target.closest('.profile-dropdown')) {
            return; // allow link navigation
        }
        if (e) {
            e.stopPropagation();
        }
        var nd = document.getElementById('notificationDropdown');
        var pd = document.getElementById('profileDropdown');
        if (nd) { nd.classList.remove('show'); nd.style.display = 'none'; }
        if (!pd) {
            console.error('Profile dropdown not found');
            return;
        }
        pd.classList.toggle('show');
        pd.style.display = pd.classList.contains('show') ? 'block' : 'none';
        console.log('Profile dropdown is now:', pd.classList.contains('show') ? 'visible' : 'hidden');
    };

    window.petronToggleTheme = function(e) {
        console.log('Theme toggle clicked'); // Debug
        if (e) {
            e.stopPropagation();
            e.preventDefault(); // Prevent any default behavior
        }
        
        // Debounce - prevent multiple rapid clicks
        var now = Date.now();
        if (window.__petronThemeLastToggleAt && (now - window.__petronThemeLastToggleAt) < 300) {
            console.log('Theme toggle debounced');
            return;
        }
        window.__petronThemeLastToggleAt = now;
        
        // Close header dropdowns when switching theme
        try{ if (typeof closeAllHeaderDropdowns === 'function') closeAllHeaderDropdowns(); }catch(e){}
        
        var isDark = document.body.classList.contains('dark-theme');
        var icon = document.getElementById('themeIcon');
        var btn  = document.getElementById('themeToggle');
        
        // Use requestAnimationFrame to prevent flickering
        requestAnimationFrame(function() {
            if (isDark) {
                // Switch to Light Mode
                document.body.classList.remove('dark-theme');
                if (icon) icon.className = 'fas fa-moon';
                if (btn)  btn.title = 'Switch to Dark Mode';
                localStorage.setItem('petronTheme', 'light');
                console.log('Switched to Light Mode - saved to localStorage');
            } else {
                // Switch to Dark Mode
                document.body.classList.add('dark-theme');
                if (icon) icon.className = 'fas fa-sun';
                if (btn)  btn.title = 'Switch to Light Mode';
                localStorage.setItem('petronTheme', 'dark');
                console.log('Switched to Dark Mode - saved to localStorage');
            }
        });
    };

    // Close dropdowns on outside click (capture phase so no stopPropagation can prevent closing)
    function petronHandleBodyOutsideClick(e) {
        var target = e.target;
        if (!target) return;

        // Notification dropdown
        var nd = document.getElementById('notificationDropdown');
        var nb = document.getElementById('notificationBell');
        if (nd && (nd.classList.contains('show') || nd.style.display === 'block')) {
            var inNotif = nd.contains(target);
            var inBell  = nb && (nb === target || nb.contains(target));
            if (!inNotif && !inBell) {
                nd.classList.remove('show');
                nd.style.display = 'none';
            }
        }

        // Profile dropdown
        var pd = document.getElementById('profileDropdown');
        var pm = document.getElementById('profileMenu');
        if (pd && (pd.classList.contains('show') || pd.style.display === 'block')) {
            var inProfile = pd.contains(target);
            var inMenu    = pm && (pm === target || pm.contains(target));
            if (!inProfile && !inMenu) {
                pd.classList.remove('show');
                pd.style.display = 'none';
            }
        }

        // Variance alert dropdown
        var vd = document.getElementById('varianceAlertDropdown');
        var vb = document.getElementById('varianceAlertBell');
        if (vd && (vd.classList.contains('show') || vd.style.display === 'block')) {
            var inVariance = vd.contains(target);
            var inVBell    = vb && (vb === target || vb.contains(target));
            if (!inVariance && !inVBell) {
                vd.classList.remove('show');
                vd.style.display = 'none';
            }
        }

        // Search suggestions
        var ss = document.getElementById('searchSuggestions');
        var sw = document.getElementById('searchWrapper');
        if (ss && ss.style.display === 'block') {
            if (!sw || !sw.contains(target)) {
                ss.style.display = 'none';
            }
        }
    }
    document.addEventListener('pointerdown', petronHandleBodyOutsideClick, true);
    document.addEventListener('click', petronHandleBodyOutsideClick, true);


    // Apply theme: system DB setting is authoritative — always sync localStorage to match
    (function() {
        var dbTheme = (window.petronSystemSettings && window.petronSystemSettings.theme)
            ? window.petronSystemSettings.theme.toLowerCase()
            : 'light';
        // Use DB setting; localStorage is only for same-session toggle (reset on each load)
        var savedTheme = localStorage.getItem('petronTheme');
        // If user has NOT manually toggled this session, use DB value; always sync:
        var effectiveTheme = dbTheme; // DB takes precedence
        localStorage.setItem('petronTheme', effectiveTheme === 'dark' ? 'dark' : 'light');
        console.log('Initializing theme from system settings (DB):', effectiveTheme);

        if (effectiveTheme === 'dark') {
            document.body.classList.add('dark-theme');
            setTimeout(function() {
                var icon = document.getElementById('themeIcon');
                if (icon) icon.className = 'fas fa-sun';
                var btn = document.getElementById('themeToggle');
                if (btn) btn.title = 'Switch to Light Mode';
            }, 0);
        } else {
            document.body.classList.remove('dark-theme');
            setTimeout(function() {
                var icon = document.getElementById('themeIcon');
                if (icon) icon.className = 'fas fa-moon';
                var btn = document.getElementById('themeToggle');
                if (btn) btn.title = 'Switch to Dark Mode';
            }, 0);
        }
    })();

    // Init sidebar state + mobile backdrop on DOMContentLoaded
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Header initialized - adding event listeners');
        
        // ADD BACKUP EVENT LISTENERS FOR ALL HEADER BUTTONS
        // These will fire even if onclick attributes fail
        
        var sidebarBtn = document.getElementById('sidebarCollapseBtn');
        if (sidebarBtn) {
            sidebarBtn.addEventListener('click', function(e) {
                console.log('Sidebar button clicked (event listener)');
                petronToggleSidebar(e);
            });
        }
        
        var notifBell = document.getElementById('notificationBell');
        if (notifBell) {
            notifBell.addEventListener('click', function(e) {
                console.log('Notification bell clicked (event listener)');
                petronToggleNotif(e);
            });
        }

        var varianceBell = document.getElementById('varianceAlertBell');
        if (varianceBell) {
            varianceBell.addEventListener('click', function(e){
                try{ if (typeof toggleDropdown === 'function') { toggleDropdown('varianceAlertDropdown', '#varianceAlertBell'); if (e && e.preventDefault) e.preventDefault(); } }
                catch(err){ console.error('varianceBell handler err', err); }
            });
        }
        
        var themeToggle = document.getElementById('themeToggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', function(e) {
                console.log('Theme toggle clicked (event listener)');
                petronToggleTheme(e);
            });
        }
        
        var profileMenu = document.getElementById('profileMenu');
        if (profileMenu) {
            profileMenu.addEventListener('click', function(e) {
                console.log('Profile menu clicked (event listener)');
                petronToggleProfile(e);
            });
        }
        
        // Restore sidebar state (desktop only) — DB system setting is authoritative
        if (window.innerWidth >= 992) {
            var dbSidebarMode = (window.petronSystemSettings && window.petronSystemSettings.sidebarMode)
                ? window.petronSystemSettings.sidebarMode.toLowerCase()
                : 'expanded';
            // DB setting always wins; sync localStorage to match
            var sidebarEffective = dbSidebarMode;
            localStorage.setItem('sidebarState', sidebarEffective);

            if (sidebarEffective === 'collapsed') {
                var s    = document.getElementById('mainSidebar');
                var icon = document.getElementById('sidebarToggleIcon');
                var main = document.querySelector('.main');
                if (s) s.classList.add('collapsed');
                if (icon) icon.className = 'fas fa-chevron-right';
                if (main) { main.style.left = '70px'; main.classList.add('sidebar-collapsed'); }
                document.body.classList.add('sidebar-collapsed');
            } else {
                var s    = document.getElementById('mainSidebar');
                var icon = document.getElementById('sidebarToggleIcon');
                var main = document.querySelector('.main');
                if (s) s.classList.remove('collapsed');
                if (icon) icon.className = 'fas fa-chevron-left';
                if (main) { main.style.left = ''; main.classList.remove('sidebar-collapsed'); }
                document.body.classList.remove('sidebar-collapsed');
            }
        }

        // Mobile backdrop click
        var backdrop = document.getElementById('mobileSidebarBackdrop');
        if (backdrop) {
            backdrop.addEventListener('click', function() {
                var s = document.getElementById('mainSidebar');
                if (s) s.classList.remove('mobile-open');
                backdrop.classList.remove('active');
                document.body.style.overflow = '';
            });
        }

        // Close mobile sidebar on resize
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 992) {
                var s = document.getElementById('mainSidebar');
                var bd = document.getElementById('mobileSidebarBackdrop');
                if (s) s.classList.remove('mobile-open');
                if (bd) bd.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Robust capture-phase listener to ensure header controls respond in all modules
        document.addEventListener('click', function(e) {
            try {
                var c = e.target;
                if (!c) return;
                var sb = c.closest && c.closest('#sidebarCollapseBtn, .sidebar-collapse-btn');
                if (sb) { petronToggleSidebar(e); return; }
                var nb = c.closest && c.closest('#notificationBell, .notification-bell');
                if (nb) { petronToggleNotif(e); return; }
                var tt = c.closest && c.closest('#themeToggle, .theme-toggle-btn');
                if (tt) { petronToggleTheme(e); return; }
                var pm = c.closest && c.closest('#profileMenu, .profile-access');
                if (pm) { petronToggleProfile(e); return; }
            } catch (err) {
                console.error('Header click listener error', err);
            }
        }, true);

        // Sidebar badge auto-deduct/clear on click
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a.nav-item, a.sidebar-sub-item');
            if (!link) return;
            const badge = link.querySelector('[data-badge], [data-sidebar-badge]');
            if (badge) {
                badge.style.display = 'none';
                badge.textContent = '';
            }
            const badgeAttr = link.querySelector('[data-sidebar-badge]')?.getAttribute('data-sidebar-badge');
            if (badgeAttr) {
                const _searchBase = (window.pageData && window.pageData.appBasePath) ? window.pageData.appBasePath : '';
                fetch(_searchBase + '/backend/api/notifications_api.php?action=mark_badge_seen&key=' + encodeURIComponent(badgeAttr), { credentials: 'same-origin' }).catch(()=>{});
            }
        });

        // Hash-based active sidebar link
        var hash = window.location.hash;
        if (hash) {
            document.querySelectorAll('.nav-item[href$="' + hash + '"]').forEach(function(el) {
                el.classList.add('active');
                var parent = el.closest('[id^="sub-"]');
                if (parent) parent.style.display = 'block';
            });
        }

        // Instant visual feedback: add is-nav-clicking on click for red highlight before page load
        document.addEventListener('click', function(e) {
            var link = e.target.closest('a.nav-item, a.sidebar-sub-item');
            if (!link) return;
            // Skip sub-menu toggles (has-submenu items don't navigate the page)
            if (link.classList.contains('has-submenu')) return;
            var href = link.getAttribute('href');
            if (!href || href === '#' || href === '') return;

            // Remove active and is-nav-clicking from ALL sidebar items so ONLY the clicked item is highlighted red
            document.querySelectorAll('.sidebar .nav-item, .sidebar .sidebar-sub-item').forEach(function(el) {
                el.classList.remove('is-nav-clicking');
                el.classList.remove('active');
            });

            // Add to clicked item ONLY
            link.classList.add('active');
            link.classList.add('is-nav-clicking');
        }, false);

        console.log('Header navigation fully initialized and ready');
    });
        
        // ── Global Search Autocomplete ────────────────────────────────────────────────────────
        (function () {
            var searchInput       = document.getElementById('searchInput');
            var searchSuggestions = document.getElementById('searchSuggestions');
            if (!searchInput || !searchSuggestions) return;

            // Clear any browser autofill (e.g. email) on initialization, focus, click, and change
            function isEmailValue(val) {
                if (!val) return false;
                var str = String(val).trim().toLowerCase();
                return str.indexOf('@') !== -1 || str.indexOf('.com') !== -1 || str.indexOf('.edu') !== -1 || str.indexOf('.ph') !== -1 || str.indexOf('.net') !== -1 || str.indexOf('.org') !== -1 || /\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/.test(str);
            }

            function cleanAutofill() {
                if (searchInput && isEmailValue(searchInput.value)) {
                    searchInput.value = '';
                    if (searchSuggestions) {
                        searchSuggestions.style.display = 'none';
                        searchSuggestions.innerHTML = '';
                    }
                }
            }
            cleanAutofill();
            setTimeout(cleanAutofill, 50);
            setTimeout(cleanAutofill, 150);
            setTimeout(cleanAutofill, 300);
            setTimeout(cleanAutofill, 500);
            setTimeout(cleanAutofill, 1000);
            setTimeout(cleanAutofill, 2000);
            window.addEventListener('load', cleanAutofill);
            searchInput.addEventListener('focus', function() {
                this.removeAttribute('readonly');
                cleanAutofill();
            });
            searchInput.addEventListener('click', function() {
                this.removeAttribute('readonly');
                cleanAutofill();
            });
            searchInput.addEventListener('pointerdown', function() {
                this.removeAttribute('readonly');
            });
            searchInput.addEventListener('change', cleanAutofill);
            // Continuous watchdog for browser autofill injections
            setInterval(cleanAutofill, 250);

            // Icon + colour per result type
            var TYPE_META = {
                'Station'        : { icon: 'fas fa-gas-pump',             color: '#002F6C' },
                'User'           : { icon: 'fas fa-user-shield',          color: '#7c3aed' },
                'Module'         : { icon: 'fas fa-cubes',                color: '#0284c7' },
                'Setting'        : { icon: 'fas fa-sliders-h',            color: '#f59e0b' },
                'System Report'  : { icon: 'fas fa-file-alt',             color: '#10b981' },
                'Audit Log'      : { icon: 'fas fa-history',              color: '#0891b2' },
                'System Alert'   : { icon: 'fas fa-exclamation-triangle', color: '#ef4444' },
                'Transaction'    : { icon: 'fas fa-shopping-cart',        color: '#3b82f6' },
                'Customer'       : { icon: 'fas fa-user',                 color: '#10b981' },
                'Vehicle'        : { icon: 'fas fa-car',                  color: '#0284c7' },
                'Product'        : { icon: 'fas fa-box',                  color: '#f59e0b' },
                'Job Order'      : { icon: 'fas fa-wrench',               color: '#8b5cf6' },
                'Delivery'       : { icon: 'fas fa-truck',                color: '#ef4444' },
                'Calendar'       : { icon: 'fas fa-calendar-alt',         color: '#06b6d4' },
                'Report'         : { icon: 'fas fa-chart-bar',            color: '#64748b' },
                'Purchase Order' : { icon: 'fas fa-file-invoice',         color: '#0369a1' },
                'Stock Request'  : { icon: 'fas fa-boxes',                color: '#d97706' },
                'Request/Approval': { icon: 'fas fa-clipboard-check',     color: '#7c3aed' },
                'AR / Credit'    : { icon: 'fas fa-file-invoice-dollar',  color: '#b45309' },
                'Employee'       : { icon: 'fas fa-id-badge',             color: '#7c3aed' },
                'Mechanic'       : { icon: 'fas fa-tools',                color: '#059669' },
                'Admin'          : { icon: 'fas fa-user-shield',          color: '#7c3aed' },
                'System Log'     : { icon: 'fas fa-server',               color: '#dc2626' },
                'Security'       : { icon: 'fas fa-shield-alt',           color: '#b91c1c' },
                'Audit Trail'    : { icon: 'fas fa-history',              color: '#0891b2' },
                'Product Mgmt'   : { icon: 'fas fa-tags',                 color: '#e11d48' },
                'Fuel Management': { icon: 'fas fa-gas-pump',             color: '#f97316' },
                'Fuel Reading'   : { icon: 'fas fa-tachometer-alt',       color: '#ea580c' }
            };

            // ── Resolve a relative link from search.php to an absolute URL ──
            function resolveLink(link) {
                if (!link || link === '#') return '#';
                if (link.indexOf('http://') === 0 || link.indexOf('https://') === 0 || link.indexOf('/') === 0) {
                    return link;
                }
                var base = (window.pageData && window.pageData.appBasePath) ? window.pageData.appBasePath : '';
                return base + '/public/' + link;
            }
            // Expose globally
            window.resolveRedirectUrl = resolveLink;

            // ── State ──
            var debounceTimer;
            var flatResults = [];
            var activeIndex = -1;

            // ── Keyboard active-item highlight helpers ──
            function setActive(index) {
                clearActive();
                activeIndex = index;
                var rows = searchSuggestions.querySelectorAll('a[data-search-row]');
                for (var i = 0; i < rows.length; i++) {
                    if (parseInt(rows[i].getAttribute('data-search-row')) === index) {
                        rows[i].style.background = '#f0f6ff';
                    }
                }
            }
            function clearActive() {
                activeIndex = -1;
                var rows = searchSuggestions.querySelectorAll('a[data-search-row]');
                for (var i = 0; i < rows.length; i++) { rows[i].style.background = ''; }
            }

            // ── Escape HTML to prevent XSS in innerHTML ──
            function esc(str) {
                return String(str).replace(/[&<>"']/g, function(c) {
                    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                });
            }

            // ── Render suggestions ──
            function renderSuggestions(data, query) {
                searchSuggestions.innerHTML = '';
                flatResults = [];
                activeIndex = -1;

                if (!data || data.length === 0) {
                    searchSuggestions.innerHTML =
                        '<div style="padding:20px 16px;color:#94a3b8;font-size:13px;text-align:center;">' +
                        '<i class="fas fa-inbox" style="display:block;font-size:22px;margin-bottom:6px;"></i>' +
                        'No results found.</div>';
                    searchSuggestions.style.display = 'block';
                    return;
                }

                // Group by type
                var grouped = {};
                var typeOrder = [];
                data.forEach(function(item) {
                    if (!grouped[item.type]) { grouped[item.type] = []; typeOrder.push(item.type); }
                    grouped[item.type].push(item);
                });

                typeOrder.forEach(function(type) {
                    var meta  = TYPE_META[type] || { icon: 'fas fa-circle', color: '#64748b' };
                    var color = meta.color;

                    // Group header
                    var hdr = document.createElement('div');
                    hdr.style.cssText =
                        'padding:6px 14px 4px;font-size:11px;font-weight:700;text-transform:uppercase;' +
                        'letter-spacing:.6px;color:#94a3b8;background:#f8fafc;' +
                        'border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:6px;';
                    hdr.innerHTML = '<i class="' + meta.icon + '" style="color:' + color + ';font-size:10px;"></i>' + esc(type) + 's';
                    searchSuggestions.appendChild(hdr);

                    grouped[type].forEach(function(item) {
                        var rowIndex = flatResults.length;
                        flatResults.push(item);

                        var row = document.createElement('a');
                        row.href = resolveLink(item.link);
                        row.setAttribute('data-search-row', rowIndex);
                        row.style.cssText =
                            'display:flex;align-items:center;gap:10px;padding:9px 14px;' +
                            'text-decoration:none;color:inherit;border-bottom:1px solid #f8fafc;' +
                            'transition:background .12s;cursor:pointer;';
                        (function(ri) {
                            row.addEventListener('mouseenter', function() { setActive(ri); });
                            row.addEventListener('mouseleave', function() { clearActive(); });
                            row.addEventListener('click', function(e) {
                                e.preventDefault();
                                var dest = resolveLink(item.link);
                                if (dest && dest !== '#') window.location.href = dest;
                            });
                        })(rowIndex);
                        row.innerHTML =
                            '<div style="width:30px;height:30px;border-radius:8px;background:' + color + '18;' +
                            'display:flex;align-items:center;justify-content:center;flex-shrink:0;">' +
                            '<i class="' + meta.icon + '" style="color:' + color + ';font-size:12px;"></i></div>' +
                            '<div style="flex:1;min-width:0;">' +
                            '<div style="font-size:13px;font-weight:600;color:#1e293b;' +
                            'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(item.title) + '</div>' +
                            '<div style="font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;' +
                            'text-overflow:ellipsis;">' + esc(item.subtitle) + '</div></div>' +
                            '<i class="fas fa-chevron-right" style="color:#cbd5e1;font-size:10px;flex-shrink:0;"></i>';
                        searchSuggestions.appendChild(row);
                    });
                });

                searchSuggestions.style.display = 'block';
            }

            // ── Input event: debounced fetch ──
            searchInput.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                var query = this.value.trim();
                if (isEmailValue(query)) {
                    this.value = '';
                    searchSuggestions.style.display = 'none';
                    return;
                }
                if (query.length < 2) {
                    searchSuggestions.style.display = 'none';
                    return;
                }
                searchSuggestions.innerHTML =
                    '<div style="padding:14px 16px;color:#94a3b8;font-size:13px;text-align:center;">' +
                    '<i class="fas fa-spinner fa-spin"></i> Searching&hellip;</div>';
                searchSuggestions.style.display = 'block';

                var capturedQuery = query;
                debounceTimer = setTimeout(function() {
                    var _base = (window.pageData && window.pageData.appBasePath) ? window.pageData.appBasePath : '';
                    fetch(_base + '/public/search.php?q=' + encodeURIComponent(capturedQuery) + '&ajax=1')
                        .then(function(r) { return r.json(); })
                        .then(function(data) { renderSuggestions(data, capturedQuery); })
                        .catch(function() { searchSuggestions.style.display = 'none'; });
                }, 280);
            });

            // ── Keyboard: Enter / Escape / Up / Down ──
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    if (searchSuggestions.style.display !== 'none') {
                        // Navigate to highlighted row
                        if (activeIndex >= 0 && flatResults[activeIndex]) {
                            var dest = resolveLink(flatResults[activeIndex].link);
                            if (dest && dest !== '#') { window.location.href = dest; return; }
                        }
                        // Navigate to first result
                        if (flatResults.length > 0) {
                            var dest2 = resolveLink(flatResults[0].link);
                            if (dest2 && dest2 !== '#') { window.location.href = dest2; return; }
                        }
                    }
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (flatResults.length > 0) setActive(Math.min(activeIndex + 1, flatResults.length - 1));
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (flatResults.length > 0) setActive(Math.max(activeIndex - 1, 0));
                } else if (e.key === 'Escape') {
                    searchSuggestions.style.display = 'none';
                    clearActive();
                    this.blur();
                }
            });

            // ── Hide dropdown on outside click ──
            document.addEventListener('click', function(e) {
                var wrapper = document.getElementById('searchWrapper');
                if (wrapper && !wrapper.contains(e.target)) {
                    searchSuggestions.style.display = 'none';
                    clearActive();
                }
            });
        })();

        // â”€â”€ Notification System â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // SuperAdmin/Developer: real system-level alerts from DB via AJAX.
        // Manager: deeper approval/oversight notifications.
        // Staff/Admin: operational notifications.
        <?php
        $is_superadmin_role = in_array($role, ['superadmin', 'developer']);
        $is_admin_role      = ($role === 'admin');
        $is_manager_role    = ($role === 'manager');
        $notif_generator    = $is_superadmin_role
            ? '/backend/api/superadmin_notification_generator.php'
            : ($is_admin_role
                ? '/backend/api/admin_notification_generator.php'
                : ($is_manager_role
                    ? '/backend/api/manager_notification_generator.php'
                    : '/backend/api/staff_notification_generator.php'));
        ?>

        <?php if ($is_superadmin_role): ?>
        // â”€â”€ SuperAdmin: Real DB-driven notifications â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        (function () {
            'use strict';

            const getAppBasePath = function() {
                if (window.pageData && typeof window.pageData.appBasePath === 'string') {
                    return window.pageData.appBasePath.replace(/\/+$/, '');
                }
                const path = window.location.pathname;
                const pubIdx = path.indexOf('/public/');
                if (pubIdx !== -1) return path.substring(0, pubIdx);
                const backIdx = path.indexOf('/backend/');
                if (backIdx !== -1) return path.substring(0, backIdx);
                const lastSlash = path.lastIndexOf('/');
                if (lastSlash > 0) {
                    const dir = path.substring(0, lastSlash);
                    return dir.replace(/\/public\/?$/, '').replace(/\/backend\/?$/, '');
                }
                return '';
            };
            const resolveApiPath = function(path) {
                if (!path) return path;
                if (path.startsWith('http://') || path.startsWith('https://')) return path;
                const cleanPath = path.startsWith('/') ? path : '/' + path;
                const bp = getAppBasePath();
                return bp ? bp + cleanPath : cleanPath;
            };
            const API_LIST = resolveApiPath('/backend/api/notifications_api.php');
            const API_GEN  = resolveApiPath('/backend/api/superadmin_notification_generator.php');

            // Severity â†’ colour mapping
            const SEV_COLOR = {
                critical : '#dc3545',
                high     : '#fd7e14',
                medium   : '#ffc107',
                low      : '#28a745',
                info     : '#0d6efd'
            };
            const TYPE_ICON = {
                failed_login          : 'fas fa-shield-alt',
                unauthorized_access   : 'fas fa-user-slash',
                account_lockout       : 'fas fa-user-lock',
                system_error          : 'fas fa-exclamation-triangle',
                database_error        : 'fas fa-database',
                mass_delete           : 'fas fa-trash-alt',
                export_logs           : 'fas fa-file-export',
                pos_import_failure    : 'fas fa-file-import',
                integration_change    : 'fas fa-plug',
                security_report       : 'fas fa-shield-alt',
                config_change         : 'fas fa-sliders-h',
                settings_change       : 'fas fa-cog',
                unauthorized_settings : 'fas fa-ban',
                general               : 'fas fa-info-circle'
            };

            function timeAgo(ts) {
                const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
                if (diff < 60)   return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
                if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
                return Math.floor(diff / 86400) + ' days ago';
            }

            function cleanMojibake(str) {
                if (!str) return '';
                return String(str)
                    .replace(/ΓÇö|ГÇÖ|ΓÇÖ|ΓÇô|â€”|â€“/g, '—')
                    .replace(/Γé▒|â‚±/g, '₱')
                    .replace(/â€™|ΓÇÿ/g, "'")
                    .replace(/â€œ|â€|ΓÇ£|ΓÇ¥/g, '"');
            }

            function renderNotifications(list) {
                const el = document.getElementById('notificationList');
                if (!el) return;
                if (window.petronSystemSettings && window.petronSystemSettings.enableSystemNotifications === false) {
                    el.innerHTML = '<div style="padding:22px;text-align:center;color:#64748b;font-size:13px;"><i class="fas fa-bell-slash" style="font-size:24px;display:block;margin-bottom:8px;color:#94a3b8;"></i>System notifications are currently disabled in Settings.</div>';
                    return;
                }
                if (!list || list.length === 0) {
                    el.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:13px;"><i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:8px;color:#28a745;"></i>No new system alerts.</div>';
                    return;
                }
                el.innerHTML = list.map(n => {
                    const icon  = TYPE_ICON[n.event_type] || TYPE_ICON.general;
                    const color = SEV_COLOR[n.severity]   || SEV_COLOR.info;
                    const rawUrl = n.redirect_url || '';
                    const targetUrl = (rawUrl && rawUrl !== '#' && rawUrl !== 'null') 
                        ? (window.resolveRedirectUrl ? window.resolveRedirectUrl(rawUrl) : rawUrl)
                        : 'javascript:void(0)';
                    const unread = n.status === 'unread';
                    const hoverClass = unread ? 'notif-item unread' : 'notif-item';
                    const title = cleanMojibake(n.title || 'Notification').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    const msg   = cleanMojibake(n.message || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    return `<a href="${targetUrl}" class="${hoverClass}"
                                 style="padding:12px 16px;cursor:pointer;display:flex;align-items:flex-start;gap:12px;text-decoration:none !important;color:inherit;"
                                 onclick="saMarkRead(${n.id})">
                        <div style="width:40px;height:40px;border-radius:50%;background:${color}18;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid ${color}30;margin-top:2px;">
                            <i class="${icon}" style="color:${color};font-size:16px;"></i>
                        </div>
                        <div style="flex:1;min-width:0;line-height:1.3;">
                            <div style="font-size:13.5px;color:#050505;margin-bottom:2px;">
                                <strong style="font-weight:${unread ? '700' : '600'};">${title}</strong>
                            </div>
                            <div style="color:#65676B;font-size:12.5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;">
                                ${msg}
                            </div>
                            <div style="color:${unread ? '#002F6C' : '#65676B'};font-size:11.5px;font-weight:${unread ? '600' : 'normal'};margin-top:4px;display:flex;align-items:center;gap:6px;">
                                <span style="padding:1px 6px;border-radius:10px;background:${color}18;color:${color};font-weight:700;text-transform:uppercase;font-size:9px;">${n.severity || 'info'}</span>
                                ${timeAgo(n.created_at)}
                            </div>
                        </div>
                        ${unread ? '<div style="width:10px;height:10px;border-radius:50%;background:#002F6C;flex-shrink:0;margin-top:16px;"></div>' : ''}
                    </a>`;
                }).join('');
            }

            function updateSidebarBadges(categoryCounts) {
                // Disabled as requested: notifications are strictly in the header notification bell badge
                return;
            }

            function updateBadge(count, categoryCounts) {
                const badge = document.getElementById('notificationBadge');
                if (badge) {
                    if (window.petronSystemSettings && window.petronSystemSettings.enableSystemNotifications === false) {
                        badge.style.display = 'none';
                        return;
                    }
                    if (count > 0) {
                        badge.textContent = count > 99 ? '99+' : count;
                        badge.style.display = 'inline-flex';
                        badge.style.background = '#dc2626';
                    } else {
                        badge.style.display = 'none';
                    }
                }
                if (categoryCounts) updateSidebarBadges(categoryCounts);
            }

            async function loadNotifications() {
                const el = document.getElementById('notificationList');
                if (el) el.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
                try {
                    const res  = await fetch(API_LIST + '?action=list&limit=15&status=all', { credentials: 'same-origin', cache: 'no-store' });
                    if (!res.ok) {
                        // API unreachable — show empty state instead of error
                        if (el) el.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:13px;"><i class="fas fa-bell-slash" style="font-size:22px;display:block;margin-bottom:8px;"></i>No notifications.</div>';
                        return;
                    }
                    const data = await res.json();
                    if (data.success) {
                        renderNotifications(data.notifications || []);
                        updateBadge(data.bell_unread_count ?? data.unread_count ?? 0, data.category_counts);
                    } else {
                        if (el) el.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:13px;"><i class="fas fa-bell-slash" style="font-size:22px;display:block;margin-bottom:8px;"></i>No notifications.</div>';
                    }
                } catch (e) {
                    if (el) el.innerHTML = '<div style="padding:20px;text-align:center;color:#888;font-size:13px;"><i class="fas fa-bell-slash" style="font-size:22px;display:block;margin-bottom:8px;"></i>No notifications available.</div>';
                }
            }

            async function fetchUnreadCount() {
                try {
                    const res  = await fetch(API_LIST + '?action=unread_count', { credentials: 'same-origin', cache: 'no-store' });
                    const data = await res.json();
                    if (data.success) {
                        updateBadge(data.bell_unread_count ?? data.unread_count ?? 0, data.category_counts);
                    }
                } catch (e) {}
            }

            async function generateAndRefresh() {
                try {
                    await fetch(API_GEN, { credentials: 'same-origin', cache: 'no-store' });
                } catch (e) {}
                await fetchUnreadCount();
            }

            // Expose mark-read globally so onclick works
            window.saMarkRead = function(id, redirectUrl) {
                if (!id) return;
                // ── Immediately decrement badge (optimistic UI) ─────────────
                (function() {
                    const badge = document.getElementById('notificationBadge');
                    if (badge && badge.style.display !== 'none') {
                        let cur = parseInt(badge.textContent.replace(/\D/g, ''), 10) || 0;
                        cur = Math.max(0, cur - 1);
                        if (cur > 0) {
                            badge.textContent = cur > 99 ? '99+' : cur;
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                })();
                try {
                    const fd = new FormData();
                    fd.append('notification_id', id);
                    fetch(API_LIST + '?action=mark_read', { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(r => r.ok ? r.json() : null)
                        .then(data => {
                            if (data && data.success) {
                                updateBadge(data.bell_unread_count ?? 0, data.category_counts);
                            }
                        })
                        .catch(() => {});
                } catch (e) {}

            };

            // Mark all read button
            const markAllBtn = document.getElementById('markAllReadBtn');
            if (markAllBtn) {
                markAllBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    try {
                        const res = await fetch(API_LIST + '?action=mark_all_read', { method: 'POST', credentials: 'same-origin' });
                        const data = await res.json();
                        if (data && data.success) {
                            updateBadge(0, data.category_counts); // zero bell immediately
                        }
                    } catch (e) {}
                    loadNotifications();
                });
            }

            // Refresh button
            const refreshBtn = document.getElementById('refreshNotificationsBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    await generateAndRefresh();
                    loadNotifications();
                });
            }

            // Load on bell open (Expose globally for the toggle listener)
            window.saLoadNotifications = function() {
                generateAndRefresh();
                loadNotifications();
            };

            // Direct notifications (run generator on page load)
            generateAndRefresh();
        })();

        <?php else: ?>
        // â”€â”€ Staff / Manager / Admin: Dynamic DB-driven notifications â”€â”€â”€â”€â”€â”€â”€â”€â”€
        (function () {
            'use strict';

            const getAppBasePath = function() {
                if (window.pageData && typeof window.pageData.appBasePath === 'string') {
                    return window.pageData.appBasePath.replace(/\/+$/, '');
                }
                const path = window.location.pathname;
                const pubIdx = path.indexOf('/public/');
                if (pubIdx !== -1) return path.substring(0, pubIdx);
                const backIdx = path.indexOf('/backend/');
                if (backIdx !== -1) return path.substring(0, backIdx);
                const lastSlash = path.lastIndexOf('/');
                if (lastSlash > 0) {
                    const dir = path.substring(0, lastSlash);
                    return dir.replace(/\/public\/?$/, '').replace(/\/backend\/?$/, '');
                }
                return '';
            };
            const resolveApiPath = function(path) {
                if (!path) return path;
                if (path.startsWith('http://') || path.startsWith('https://')) return path;
                const cleanPath = path.startsWith('/') ? path : '/' + path;
                const bp = getAppBasePath();
                return bp ? bp + cleanPath : cleanPath;
            };
            const API_LIST = resolveApiPath('/backend/api/notifications_api.php');
            const API_GEN  = resolveApiPath('<?php echo $notif_generator; ?>');

            // Inject styles for hover effects
            if (!document.getElementById('notifStyles')) {
                const style = document.createElement('style');
                style.id = 'notifStyles';
                style.innerHTML = `
                    .notif-item { background: #fff; transition: background 0.2s; border-radius: 8px; margin: 0 8px; }
                    .notif-item:hover { background: #f0f2f5; }
                    .notif-item.unread { background: #eef3f8; }
                    .notif-item.unread:hover { background: #e4ebf3; }
                    #notificationList { padding-bottom: 8px; padding-top: 8px; }
                `;
                document.head.appendChild(style);
            }

            // Event type → icon mapping
            const EVT_ICON = {
                transaction             : 'fas fa-shopping-cart',
                merchandise             : 'fas fa-shopping-cart',
                merchandise_transaction : 'fas fa-shopping-cart',
                job_order               : 'fas fa-wrench',
                combined                : 'fas fa-tools',
                fuel_management         : 'fas fa-gas-pump',
                fuel_transaction        : 'fas fa-gas-pump',
                inventory               : 'fas fa-warehouse',
                customer                : 'fas fa-user',
                customer_request        : 'fas fa-user-plus',
                delivery                : 'fas fa-truck',
                calendar                : 'fas fa-calendar-alt',
                report                  : 'fas fa-chart-bar',
                general                 : 'fas fa-bell'
            };

            const TYPE_COLOR = {
                success : '#28a745',
                warning : '#f59e0b',
                error   : '#dc3545',
                info    : '#17a2b8'
            };

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function (ch) {
                    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
                });
            }

            function cleanMojibake(str) {
                if (!str) return '';
                return String(str)
                    .replace(/ΓÇö|ГÇÖ|ΓÇÖ|ΓÇô|â€”|â€“/g, '—')
                    .replace(/Γé▒|â‚±/g, '₱')
                    .replace(/â€™|ΓÇÿ/g, "'")
                    .replace(/â€œ|â€ |ΓÇ£|ΓÇ¥/g, '"');
            }

            function escapeJsString(value) {
                return String(value || '')
                    .replace(/\\/g, '\\\\')
                    .replace(/'/g, "\\'")
                    .replace(/\r?\n/g, ' ');
            }

            function isNotificationDropdownOpen() {
                const nd = document.getElementById('notificationDropdown');
                return !!(nd && (nd.classList.contains('show') || nd.style.display === 'block'));
            }

            function notificationListNeedsRefresh() {
                const el = document.getElementById('notificationList');
                if (!el) return false;
                const text = (el.textContent || '').trim();
                return !text || text.indexOf('Loading notifications') !== -1 || text.indexOf('Loading') !== -1 || text.indexOf('Could not load') !== -1;
            }

            function timeAgo(dateStr) {
                if (!dateStr) return '';
                const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
                if (diff < 60)    return diff + 's ago';
                if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            }

            function updateBadge(count, categoryCounts) {
                const badge = document.getElementById('notificationBadge');
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count > 99 ? '99+' : count;
                        badge.style.display = 'inline-flex';
                    } else {
                        badge.style.display = 'none';
                    }
                }
                if (categoryCounts) {
                    updateSidebarDrawerBadges(categoryCounts);
                }
            }

            function updateSidebarDrawerBadges(categoryCounts) {
                // Disabled as requested: notifications are strictly in the header notification bell badge
                return;
            }

            // ── Load & render notifications (fast — no generator wait) ────────
            async function loadNotifications() {
                const el = document.getElementById('notificationList');
                if (!el) return;
                el.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
                try {
                    const ctrl = new AbortController();
                    const tid  = setTimeout(() => ctrl.abort(), 8000); // 8s timeout
                    const res  = await fetch(API_LIST + '?action=list&limit=8&status=all', {
                        signal: ctrl.signal,
                        credentials: 'same-origin',
                        cache: 'no-store'
                    });
                    clearTimeout(tid);
                    // Do NOT throw on non-ok — gracefully show empty state
                    if (!res.ok) {
                        el.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;"><i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:8px;display:block;"></i>No notifications yet.</div>';
                        return;
                    }
                    const data = await res.json();

                    if (data.success && data.notifications && data.notifications.length > 0) {
                        let html = '';
                        data.notifications.forEach(function (n) {
                            const icon   = EVT_ICON[n.event_type] || EVT_ICON.general;
                            const color  = TYPE_COLOR[n.type]     || '#17a2b8';
                            const unread = n.status === 'unread';
                            const bg     = unread ? 'rgba(0,47,108,0.04)' : 'transparent';
                            const rawUrl = n.redirect_url || '';
                            let targetUrl = (rawUrl && rawUrl !== '#' && rawUrl !== 'null') 
                                ? (window.resolveRedirectUrl ? window.resolveRedirectUrl(rawUrl) : rawUrl)
                                : 'javascript:void(0)';
                            if (targetUrl.indexOf('staff_requests.php') !== -1) {
                                targetUrl = 'staff_transactions_hub.php?section=merchandise';
                            }
                            const title  = escapeHtml(cleanMojibake(n.title || 'Notification'));
                            const msg    = escapeHtml(cleanMojibake(n.message || ''));
                            const ago    = escapeHtml(n.time_ago || timeAgo(n.created_at));
                            const hoverClass = unread ? 'notif-item unread' : 'notif-item';
                            const onclickAttr = unread ? `onclick="staffMarkRead(${n.id})"` : '';
                            html += `<a href="${targetUrl}" class="${hoverClass}" style="padding:12px 16px;cursor:pointer;display:flex;align-items:flex-start;gap:12px;text-decoration:none !important;"
                                          ${onclickAttr}>
                                        <div style="width:48px;height:48px;border-radius:50%;background:${color}15;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid ${color}30;">
                                            <i class="${icon}" style="color:${color};font-size:20px;"></i>
                                        </div>
                                        <div style="flex:1;min-width:0;line-height:1.3;">
                                            <div style="font-size:14px;color:#050505;margin-bottom:2px;">
                                                <strong style="font-weight:600;">${title}</strong>
                                            </div>
                                            <div style="color:#65676B;font-size:13px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;">
                                                ${msg}
                                            </div>
                                            <div style="color:${unread ? '#002F6C' : '#65676B'};font-size:12px;font-weight:${unread ? '600' : 'normal'};margin-top:4px;">
                                                ${ago}
                                            </div>
                                        </div>
                                        ${unread ? '<div style="width:10px;height:10px;border-radius:50%;background:#002F6C;flex-shrink:0;margin-top:20px;"></div>' : ''}
                                    </a>`;
                        });
                        el.innerHTML = html;
                        updateBadge(data.unread_count || 0, data.category_counts);
                    } else if (data.success) {
                        el.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;"><i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:8px;display:block;"></i>No notifications yet.</div>';
                        updateBadge(0, data.category_counts);
                    } else {
                        el.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;"><i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:8px;display:block;"></i>No notifications yet.</div>';
                    }
                } catch (e) {
                    const el2 = document.getElementById('notificationList');
                    if (el2) el2.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;"><i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:8px;display:block;"></i>No notifications yet.</div>';
                }
            }

            // ── Mark one notification as read ─────────────────────────────────
            window.staffMarkRead = function (id) {
                if (id) {
                    // ── Immediately decrement badge (optimistic UI) ─────────
                    (function() {
                        const badge = document.getElementById('notificationBadge');
                        if (badge && badge.style.display !== 'none') {
                            let cur = parseInt(badge.textContent.replace(/\D/g, ''), 10) || 0;
                            cur = Math.max(0, cur - 1);
                            if (cur > 0) {
                                badge.textContent = cur > 99 ? '99+' : cur;
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                        const item = document.querySelector('[onclick*="staffMarkRead(' + id + ')"]');
                        if (item) {
                            item.classList.remove('unread');
                            item.style.backgroundColor = 'transparent';
                            const dot = item.querySelector('div[style*="border-radius:50%"][style*="margin-top:20px"]');
                            if (dot) dot.remove();
                        }
                    })();
                    try {
                        const fd = new FormData();
                        fd.append('notification_id', id);
                        fetch(API_LIST + '?action=mark_read', { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(r => r.ok ? r.json() : null)
                            .then(data => {
                                if (data && data.success) {
                                    // Sync both header bell + sidebar badges with server truth
                                    updateBadge(data.bell_unread_count ?? 0, data.category_counts);
                                }
                            })
                            .catch(() => {});
                    } catch (e) {}
                }
            };

            // ── Fetch unread count only (lightweight) ─────────────────────────
            async function fetchUnreadCount() {
                try {
                    const ctrl = new AbortController();
                    const tid  = setTimeout(() => ctrl.abort(), 5000);
                    const res  = await fetch(API_LIST + '?action=unread_count', {
                        signal: ctrl.signal,
                        credentials: 'same-origin',
                        cache: 'no-store'
                    });
                    clearTimeout(tid);
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();
                    if (data.success) {
                        const bellCount = (typeof data.bell_unread_count !== 'undefined')
                            ? data.bell_unread_count
                            : (data.unread_count || 0);
                        updateBadge(bellCount, data.category_counts);
                    }
                } catch (e) {}
            }

            // ── Run generator silently in background (fire-and-forget) ────────
            function runGeneratorBackground() {
                const ctrl = new AbortController();
                const tid = setTimeout(() => ctrl.abort(), 8000);
                return fetch(API_GEN, {
                        signal: ctrl.signal,
                        credentials: 'same-origin',
                        cache: 'no-store'
                    })
                    .then(r => {
                        clearTimeout(tid);
                        // Do NOT throw on non-ok (403/500 on live server) — treat generator as optional
                        if (!r.ok) {
                            // Generator unavailable — still load notifications from DB directly
                            fetchUnreadCount();
                            loadNotifications();
                            return null;
                        }
                        return r.json();
                    })
                    .then(d => {
                        if (!d) return; // already handled above
                        fetchUnreadCount();
                        loadNotifications();
                    })
                    .catch(() => {
                        clearTimeout(tid);
                        // On any error (network, abort, etc.) — always try to load notifications
                        fetchUnreadCount();
                        loadNotifications();
                    });
            }


            // ── Mark all read ──────────────────────────────────────────────────
            const markAllBtn = document.getElementById('markAllReadBtn');
            if (markAllBtn) {
                markAllBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    try {
                        const res = await fetch(API_LIST + '?action=mark_all_read', { method: 'POST', credentials: 'same-origin' });
                        const data = await res.json();
                        if (data && data.success) {
                            updateBadge(0, data.category_counts);
                        }
                    } catch (e) {}
                    loadNotifications();
                });
            }

            // â”€â”€ Refresh button â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            const refreshBtn = document.getElementById('refreshNotificationsBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', async function (e) {
                    e.stopPropagation();
                    await runGeneratorBackground();
                    loadNotifications();
                });
            }

            // Expose globally for the toggle listener
            window.loadStaffNotifications = function() {
                runGeneratorBackground();
                loadNotifications();
            };
            window.petronLoadNotifications = window.loadStaffNotifications;

            // ── Direct notifications (run generator on page load) ──
            runGeneratorBackground();
            setInterval(function() {
                runGeneratorBackground();
            }, 10000);
        })();
        <?php endif; ?>
    </script>

    <script>
    /* â”€â”€ GLOBAL: Sidebar Sub-menu Toggle â”€â”€ */
    function toggleSidebarSub(e, subId) {
        e.preventDefault();
        e.stopPropagation();
        
        // If sidebar is collapsed, expand it first
        const mainSidebar = document.getElementById('mainSidebar');
        const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
        const mainContent = document.querySelector('.main');
        
        if (mainSidebar && mainSidebar.classList.contains('collapsed')) {
            mainSidebar.classList.remove('collapsed');
            if (sidebarToggleIcon) sidebarToggleIcon.className = 'fas fa-bars';
            localStorage.setItem('sidebarState', 'expanded');
            
            if (mainContent) {
                mainContent.style.left = '250px';
                mainContent.style.marginLeft = '';
                mainContent.classList.remove('sidebar-collapsed');
                document.body.classList.add('sidebar-expanded');
                document.body.classList.remove('sidebar-collapsed');
            }
        }

        const sub = document.getElementById(subId);
        if (!sub) return;

        const isOpen = sub.style.display !== 'none';

        // Close all other open sub-menus (accordion — no overlap)
        document.querySelectorAll('[id^="sub-"]').forEach(function(other) {
            if (other.id !== subId) {
                other.style.display = 'none';
                // Reset chevron for closed menus
                const otherLink = document.querySelector('a[onclick*="' + other.id + '"]');
                if (otherLink) {
                    const otherChevron = otherLink.querySelector('.fa-chevron-down');
                    if (otherChevron) otherChevron.style.transform = 'rotate(0deg)';
                    otherLink.classList.remove('sub-open');
                }
            }
        });

        // Toggle the clicked sub-menu
        sub.style.display = isOpen ? 'none' : 'block';

        // Rotate chevron
        const chevron = e.currentTarget.querySelector('.fa-chevron-down');
        if (chevron) {
            chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
            chevron.style.transition = 'transform 0.3s ease';
        }

        // Active state on parent
        e.currentTarget.classList.toggle('sub-open', !isOpen);
    }

    // On page load: clear old localStorage submenu states so nothing auto-opens
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[id^="sub-"]').forEach(function(sub) {
            localStorage.removeItem('submenu_' + sub.id);
        });
    });
    </script>

<!-- SYSTEM-WIDE TOAST & BROWSER ALERT OVERRIDE ENGINE -->
<style id="globalToastSystemStyles">
.global-toast-stack {
    position: fixed !important;
    top: 85px !important;
    right: 24px !important;
    z-index: 2147483647 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 10px !important;
    pointer-events: none !important;
    max-width: 440px !important;
    width: calc(100vw - 48px) !important;
}
.global-toast {
    pointer-events: auto !important;
    min-width: 280px !important;
    background: #16a34a !important;
    color: #ffffff !important;
    padding: 14px 44px 14px 18px !important;
    border-radius: 10px !important;
    box-shadow: 0 12px 36px rgba(0, 0, 0, 0.28), 0 4px 12px rgba(0, 0, 0, 0.15) !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    position: relative !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    line-height: 1.45 !important;
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    animation: globalToastIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
    transition: opacity 0.3s ease, transform 0.3s ease !important;
}
.global-toast.error, .global-toast.danger {
    background: #dc2626 !important;
}
.global-toast.warning {
    background: #d97706 !important;
}
.global-toast.info {
    background: #0284c7 !important;
}
.global-toast-close {
    position: absolute !important;
    top: 50% !important;
    right: 12px !important;
    transform: translateY(-50%) !important;
    background: none !important;
    border: none !important;
    color: #ffffff !important;
    font-size: 22px !important;
    line-height: 1 !important;
    cursor: pointer !important;
    opacity: 0.85 !important;
    padding: 4px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}
.global-toast-close:hover {
    opacity: 1 !important;
}
@keyframes globalToastIn {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
</style>

<div class="global-toast-stack" id="globalToastStack"></div>

<script id="globalToastSystemScript">
(function() {
    let globalToastTimer = null;

    // ── Global Toast Notification Generator ─────────────────────────────────────
    // Enforces single-banner display (no stacking/overlapping) positioned safely below header
    window.showGlobalToast = function(msg, type = 'success') {
        if (!msg) return;
        let stack = document.getElementById('globalToastStack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'globalToastStack';
            stack.className = 'global-toast-stack';
            document.body.appendChild(stack);
        }

        // Clean up any pending timer
        if (globalToastTimer) {
            clearTimeout(globalToastTimer);
            globalToastTimer = null;
        }

        // Single banner rule: instantly clear any existing banners in stack or page-specific containers
        stack.innerHTML = '';
        const adminContainer = document.getElementById('adminToastContainer');
        if (adminContainer) adminContainer.innerHTML = '';
        const rightContainer = document.getElementById('rightToastContainer');
        if (rightContainer) rightContainer.innerHTML = '';
        
        const typeKey = String(type || 'success').toLowerCase();
        let iconHtml = '<i class="fas fa-check-circle" style="font-size:17px;flex-shrink:0;"></i>';
        if (typeKey === 'error' || typeKey === 'danger') {
            iconHtml = '<i class="fas fa-exclamation-circle" style="font-size:17px;flex-shrink:0;"></i>';
        } else if (typeKey === 'warning') {
            iconHtml = '<i class="fas fa-exclamation-triangle" style="font-size:17px;flex-shrink:0;"></i>';
        } else if (typeKey === 'info') {
            iconHtml = '<i class="fas fa-info-circle" style="font-size:17px;flex-shrink:0;"></i>';
        }

        const toast = document.createElement('div');
        toast.className = 'global-toast ' + typeKey;
        toast.innerHTML = `${iconHtml}<span style="flex:1;min-width:0;word-break:break-word;">${msg}</span><button type="button" class="global-toast-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>`;
        stack.appendChild(toast);

        globalToastTimer = setTimeout(() => {
            if (toast.parentElement) {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(20px)';
                setTimeout(() => toast.remove(), 300);
            }
        }, 4500);
    };

    // ── SYSTEM-WIDE OVERRIDE OF NATIVE BROWSER ALERT & CONFIRM ─────────────────
    // Replaces browser "localhost says" dialogs with smooth top-right toast banners
    window.alert = function(msg) {
        if (msg === undefined || msg === null) return;
        const text = String(msg);
        const isError = /error|failed|invalid|cannot|unable|wrong|denied|required|warning|greater than|less than/i.test(text);
        window.showGlobalToast(text, isError ? 'error' : 'success');
    };

    window.confirm = function(msg) {
        // Intercept native confirm calls system-wide so no "localhost says" popups appear
        return true;
    };

    // ── AUTOMATIC ODOMETER READING (COMMAS + KM SUFFIX) ──────────────────────
    window.formatOdometerInput = function(input) {
        if (!input) return;
        let val = input.value || '';
        let rawDigits = val.replace(/[^0-9]/g, '');
        if (!rawDigits) {
            input.value = '';
            return;
        }
        if (rawDigits.length > 8) rawDigits = rawDigits.slice(0, 8);
        let num = parseInt(rawDigits, 10);
        let formatted = num.toLocaleString('en-US') + ' km';
        input.value = formatted;

        let targetPos = (num.toLocaleString('en-US')).length;
        try {
            if (input.setSelectionRange && document.activeElement === input) {
                input.setSelectionRange(targetPos, targetPos);
            }
        } catch(e) {}
    };

    // ── AUTOMATIC ENGINE NUMBER (UPPERCASE + HYPHENS/DASHES) ─────────────────
    window.formatEngineNumberInput = function(input) {
        if (!input) return;
        let val = (input.value || '').toUpperCase();
        val = val.replace(/[^A-Z0-9-]/g, '');
        
        // Auto-insert dashes if user types without dashes (e.g. 1NZFE1234567 -> 1NZ-FE-1234567)
        let clean = val.replace(/-/g, '');
        if (clean.length > 0 && !val.includes('-')) {
            if (clean.length > 5) {
                val = clean.slice(0, 3) + '-' + clean.slice(3, 5) + '-' + clean.slice(5);
            } else if (clean.length > 3) {
                val = clean.slice(0, 3) + '-' + clean.slice(3);
            }
        }
        
        val = val.replace(/-+/g, '-');
        input.value = val;

        if (typeof checkVehicleSecurityWarning === 'function') {
            checkVehicleSecurityWarning();
        }
    };


    // ── AUTOMATIC PESO FORMATTING (COMMAS + 2 DECIMAL PLACES) ───────────────
    window.formatPesoInput = function(input) {
        if (!input) return;
        let val = input.value || '';
        // Strip everything except digits and dot
        let raw = val.replace(/[^0-9.]/g, '');
        // Allow only one decimal point
        let parts = raw.split('.');
        if (parts.length > 2) raw = parts[0] + '.' + parts.slice(1).join('');
        // Limit to 2 decimal places while typing (only if decimal entered)
        if (raw.includes('.')) {
            let [intPart, decPart] = raw.split('.');
            if (decPart.length > 2) decPart = decPart.slice(0, 2);
            // Format integer part with commas
            let formatted = parseInt(intPart || '0', 10).toLocaleString('en-US');
            input.value = formatted + '.' + decPart;
        } else {
            if (raw === '' || raw === '0') {
                input.value = raw;
                return;
            }
            let formatted = parseInt(raw, 10).toLocaleString('en-US');
            input.value = formatted;
        }
    };

    // Global listener to auto-format odometer, engine number, and peso fields system-wide
    document.addEventListener('input', function(e) {
        const t = e.target;
        if (!t) return;
        if (t.id === 'joOdometer' || (t.classList && t.classList.contains('v-odometer')) || t.name === 'odometer') {
            window.formatOdometerInput(t);
        }
        if (t.id === 'joEngineNumber' || (t.classList && t.classList.contains('v-engine')) || t.name === 'engine_number' || t.name === 'engine_no') {
            window.formatEngineNumberInput(t);
        }
        if (t.classList && t.classList.contains('peso-input')) {
            window.formatPesoInput(t);
        }
    });
})();
</script>



<?php
// ── Maintenance Mode Check for Header ──
$global_maint_active = false;
$global_maint_end_time = '';
$global_maint_msg = '';
try {
    $hm_stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('maintenance_mode', 'maintenance_end_time', 'maintenance_message') AND station_id = 0");
    $hm_stmt->execute();
    $hm_rows = $hm_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($hm_rows['maintenance_mode']) && ($hm_rows['maintenance_mode'] === '1' || $hm_rows['maintenance_mode'] == 1)) {
        $global_maint_active = true;
    }
    $global_maint_end_time = $hm_rows['maintenance_end_time'] ?? '';
    $global_maint_msg = $hm_rows['maintenance_message'] ?? 'Maintenance active';
} catch (Exception $e) {}
?>

<?php if ($global_maint_active && in_array($role, ['superadmin', 'developer'])): ?>
<!-- Sticky Maintenance Banner for Superadmin -->
<div id="superadminMaintenanceRibbon" style="background: linear-gradient(90deg, #b45309 0%, #d97706 100%); color: #ffffff; padding: 9px 18px; font-size: 12.5px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 999999; box-shadow: 0 2px 10px rgba(0,0,0,0.18);">
    <div style="display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-tools" style="color: #fef3c7; font-size: 15px;"></i>
        <span><strong>MAINTENANCE MODE ACTIVE:</strong> Non-superadmin users are locked out of the system.</span>
    </div>
    <div style="display: flex; align-items: center; gap: 14px;">
        <?php if (!empty($global_maint_end_time)): ?>
        <div style="background: rgba(0,0,0,0.25); padding: 3px 10px; border-radius: 6px; font-family: monospace; font-size: 12px;">
            <i class="fas fa-stopwatch" style="margin-right:4px;"></i> <span id="saHeaderTimer" data-endtime="<?= htmlspecialchars($global_maint_end_time) ?>">Calculating...</span>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function() {
    const timer = document.getElementById('saHeaderTimer');
    if (!timer) return;
    const endStr = timer.getAttribute('data-endtime');
    if (!endStr) return;
    function update() {
        const target = new Date(endStr.replace(/-/g, '/'));
        const now = new Date();
        const diff = target.getTime() - now.getTime();
        if (diff <= 0) {
            timer.textContent = 'Expired';
            return;
        }
        const h = Math.floor(diff / (1000 * 60 * 60));
        const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const s = Math.floor((diff % (1000 * 60)) / 1000);
        timer.textContent = String(h).padStart(2,'0') + 'h ' + String(m).padStart(2,'0') + 'm ' + String(s).padStart(2,'0') + 's';
    }
    update();
    setInterval(update, 1000);
})();
</script>
<?php endif; ?>

<script>
// ── Client-Side Session Idle Timeout Tracker (Non-superadmin/developer roles only) ──
(function initSessionIdleTracker() {
    // Superadmin and Developer are exempt — they manage the system and should never be timed out
    const CURRENT_ROLE = (window.PETRON_USER_ROLE || '').toLowerCase().trim();
    if (CURRENT_ROLE === 'superadmin' || CURRENT_ROLE === 'developer') return;

    const TIMEOUT_SEC       = <?= (int)$header_session_timeout_seconds ?>;  // from DB setting
    const TIMEOUT_MS        = TIMEOUT_SEC * 1000;
    // Show warning before timeout: 60s if >= 2 mins, or half of timeout if < 2 mins (e.g. 30s for 1 min)
    const WARNING_BEFORE_MS = TIMEOUT_MS >= 120000 ? 60000 : Math.max(10000, Math.floor(TIMEOUT_MS / 2));
    const loginUrl          = '<?= htmlspecialchars($public_base_url) ?>/login.php?timeout=1';
    const keepaliveUrl      = '<?= htmlspecialchars($public_base_url) ?>/api_session_keepalive.php';


    let idleTimer         = null;
    let warnTimer         = null;
    let warningShown      = false;
    let countdownInterval = null;
    let lastActivityTime  = Date.now();

    function getStorageTime() {
        try {
            return parseInt(localStorage.getItem('petron_last_activity') || '0', 10);
        } catch(e) {
            return 0;
        }
    }

    function setStorageTime(time) {
        try {
            localStorage.setItem('petron_last_activity', String(time));
        } catch(e) {}
    }

    function resetTimers(syncStorage = true) {
        lastActivityTime = Date.now();
        if (syncStorage) {
            setStorageTime(lastActivityTime);
        }

        clearTimeout(idleTimer);
        clearTimeout(warnTimer);
        if (countdownInterval) clearInterval(countdownInterval);
        warningShown = false;

        // Remove warning modal if user becomes active again
        const existingWarn = document.getElementById('sessionTimeoutWarning');
        if (existingWarn) existingWarn.remove();

        // Show warning before actual timeout
        const warnAt = TIMEOUT_MS - WARNING_BEFORE_MS;
        if (warnAt > 0) {
            warnTimer = setTimeout(showWarning, warnAt);
        }

        // Force redirect on actual timeout
        idleTimer = setTimeout(() => {
            // Check if user was active in another tab recently
            const storageTime = getStorageTime();
            const elapsed = Date.now() - Math.max(lastActivityTime, storageTime);
            if (elapsed < TIMEOUT_MS) {
                // User was active in another tab, reset timers
                resetTimers(false);
                return;
            }
            try { localStorage.removeItem('petron_last_activity'); } catch(e) {}
            window.location.replace(loginUrl);
        }, TIMEOUT_MS);
    }

    function showWarning() {
        if (warningShown || document.getElementById('sessionTimeoutWarning')) return;
        warningShown = true;

        const remainSec = Math.ceil(WARNING_BEFORE_MS / 1000);
        const overlay = document.createElement('div');
        overlay.id = 'sessionTimeoutWarning';
        overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,10,40,0.78); z-index:99999999; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(6px);';
        overlay.innerHTML = `
            <div style="background:#ffffff; border-radius:18px; padding:28px 26px; max-width:420px; width:90%; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.4); border:2px solid #f59e0b; animation:fadeInScale 0.25s ease-out;">
                <div style="background:#fef3c7; color:#d97706; width:60px; height:60px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:26px; margin:0 auto 16px; box-shadow:0 0 20px rgba(245,158,11,0.35);">
                    <i class="fas fa-clock"></i>
                </div>
                <h3 style="font-size:17px; font-weight:800; color:#002F6C; margin:0 0 8px; text-transform:uppercase; letter-spacing:0.5px;">Session Expiring Soon</h3>
                <p style="font-size:13.5px; color:#475569; line-height:1.6; margin:0 0 18px;">
                    Your session will expire in <span id="stCountdown" style="font-weight:800; color:#d97706; font-size:15px; font-family:monospace;">${remainSec}</span> second(s) due to inactivity.
                </p>
                <div style="display:flex; gap:10px; justify-content:center;">
                    <button type="button" onclick="window.stayLoggedIn && window.stayLoggedIn();"
                        style="flex:1; background:linear-gradient(135deg,#002F6C,#0050b3); color:#fff; border:none; border-radius:10px; padding:11px 20px; font-size:14px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 14px rgba(0,47,108,0.35);">
                        <i class="fas fa-check-circle"></i> Stay Logged In
                    </button>
                    <button type="button" onclick="window.location.replace('${loginUrl}');"
                        style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:10px; padding:11px 16px; font-size:13.5px; font-weight:600; cursor:pointer;">
                        Log Out Now
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        // Countdown inside warning
        let secs = remainSec;
        const countEl = overlay.querySelector('#stCountdown');
        if (countdownInterval) clearInterval(countdownInterval);
        countdownInterval = setInterval(() => {
            secs--;
            if (countEl) countEl.textContent = Math.max(0, secs);
            if (secs <= 0) {
                clearInterval(countdownInterval);
                try { localStorage.removeItem('petron_last_activity'); } catch(e) {}
                window.location.replace(loginUrl);
            }
        }, 1000);
    }

    // Keepalive function when user clicks "Stay Logged In"
    window.stayLoggedIn = function() {
        const warnModal = document.getElementById('sessionTimeoutWarning');
        if (warnModal) warnModal.remove();
        if (countdownInterval) clearInterval(countdownInterval);
        warningShown = false;
        
        // Ping keepalive endpoint to extend PHP session on server
        fetch(keepaliveUrl, { method: 'GET', cache: 'no-store' }).catch(() => {});
        resetTimers(true);
    };

    // Expose reset function globally
    window.sessionIdleReset = resetTimers;

    // Cross-tab synchronization via localStorage
    window.addEventListener('storage', function(e) {
        if (e.key === 'petron_last_activity') {
            resetTimers(false);
        }
    });

    // Track user activity events (throttled to avoid performance hits)
    let lastThrottled = 0;
    function onUserInteraction() {
        const now = Date.now();
        if (now - lastThrottled > 2000) { // update at most every 2 seconds
            lastThrottled = now;
            resetTimers(true);
        }
    }

    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart', 'pointerdown'].forEach(evt => {
        document.addEventListener(evt, onUserInteraction, { passive: true });
    });

    // Initialize
    resetTimers(true);
})();
</script>

<script>
// ── Real-Time Auto-Logout Polling for Regular Users during Maintenance ──
(function initMaintenanceHeartbeat() {
    const userRole = <?= json_encode($role) ?>;
    // Only poll for regular users (admin, manager, staff)
    if (userRole === 'superadmin' || userRole === 'developer') return;

    const checkUrl = '<?= htmlspecialchars($public_base_url) ?>/../backend/api/maintenance_status.php';

    function checkStatus() {
        fetch(checkUrl, { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (data && data.maintenance_mode) {
                    // Maintenance mode activated! Show alert and redirect immediately
                    showMaintenanceKickoutModal();
                }
            })
            .catch(() => {});
    }

    function showMaintenanceKickoutModal() {
        if (document.getElementById('maintKickoutOverlay')) return;
        const overlay = document.createElement('div');
        overlay.id = 'maintKickoutOverlay';
        overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,10,30,0.85); z-index:9999999; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(8px);';
        overlay.innerHTML = `
            <div style="background:#ffffff; border-radius:18px; padding:32px 28px; max-width:440px; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.4); border:2px solid #f59e0b;">
                <div style="background:#fef3c7; color:#d97706; width:64px; height:64px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:28px; margin:0 auto 18px;">
                    <i class="fas fa-tools fa-spin" style="--fa-animation-duration:4s;"></i>
                </div>
                <h3 style="font-size:18px; font-weight:800; color:#002F6C; margin:0 0 10px; text-transform:uppercase;">System Maintenance Mode</h3>
                <p style="font-size:13.5px; color:#475569; line-height:1.6; margin:0 0 20px;">
                    The system is currently undergoing scheduled maintenance. You are being logged out now.
                </p>
                <div style="font-size:13px; font-weight:700; color:#d97706; display:flex; align-items:center; justify-content:center; gap:8px;">
                    <i class="fas fa-circle-notch fa-spin"></i> Redirecting to login page...
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        setTimeout(() => {
            window.location.href = '<?= htmlspecialchars($public_base_url) ?>/login.php?maintenance=1';
        }, 1800);
    }

    // Check every 10 seconds
    setInterval(checkStatus, 10000);
})();
</script>
<!-- Node.js Real-time Service Integration (Graceful check to prevent console connection errors when service is stopped) -->
<?php
$node_online = false;
if (isset($_SESSION['nodejs_active']) && (time() - ($_SESSION['nodejs_last_check'] ?? 0) < 30)) {
    $node_online = (bool)$_SESSION['nodejs_active'];
} else {
    $fp = @fsockopen('127.0.0.1', 3000, $errno, $errstr, 0.03);
    $node_online = (bool)$fp;
    if ($fp) { fclose($fp); }
    $_SESSION['nodejs_active'] = $node_online;
    $_SESSION['nodejs_last_check'] = time();
}
?>
<?php if ($node_online): ?>
<script src="<?= htmlspecialchars($public_base_url) ?>/js/nodejs_realtime.js"></script>
<?php endif; ?>
<!-- ── PetronRealtime Bridge: wires the global engine to existing page functions ── -->
<script>
(function() {
    'use strict';
    if (typeof PetronRealtime === 'undefined') return;

    // Wire notifications handler to existing system functions.
    // The existing notification system inside the IIFE (fetchUnreadCount,
    // loadNotifications) already runs on its own schedule. PetronRealtime
    // provides the cross-module trigger() so any save handler can
    // immediately push an update without waiting for the next interval.
    PetronRealtime.on('notifications', function(data) {
        var badge = document.getElementById('notificationBadge');
        if (badge) {
            var count = parseInt(data.unread) || 0;
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'inline-flex';
            } else {
                badge.style.display = 'none';
            }
        }
        var nd = document.getElementById('notificationDropdown');
        if (nd && (nd.classList.contains('show') || nd.style.display === 'block')) {
            if (typeof window.loadStaffNotifications === 'function') window.loadStaffNotifications();
            else if (typeof window.petronLoadNotifications === 'function') window.petronLoadNotifications();
        }
    });

    // Sidebar badge counts.
    PetronRealtime.on('badges', function(data) {
        var b = data.badges || {};
        Object.keys(b).forEach(function(key) {
            var count = parseInt(b[key]) || 0;
            ['[data-badge-key="' + key + '"]', '#badge-' + key].forEach(function(sel) {
                document.querySelectorAll(sel).forEach(function(el) {
                    el.textContent = count > 0 ? (count > 99 ? '99+' : count) : '';
                    el.style.display = count > 0 ? 'inline-flex' : 'none';
                });
            });
        });
    });

    // Dashboard KPI cards: update elements with data-kpi="<key>" attribute.
    PetronRealtime.on('dashboard', function(data) {
        if (!data || !data.kpi) return;
        Object.keys(data.kpi).forEach(function(key) {
            document.querySelectorAll('[data-kpi="' + key + '"]').forEach(function(el) {
                var val = data.kpi[key];
                if (key.indexOf('total') !== -1 || key.indexOf('amount') !== -1 || key.indexOf('ar') !== -1) {
                    el.textContent = '\u20b1' + parseFloat(val || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
                } else {
                    el.textContent = val;
                }
            });
        });
        document.dispatchEvent(new CustomEvent('petron:dashboard', { detail: data }));
    });

    // Dispatch DOM custom events for each module so individual pages can listen.
    ['transactions','job_orders','inventory','fuel','purchase_orders',
     'customers','calendar','approvals','stock_requests'].forEach(function(mod) {
        PetronRealtime.on(mod, function(data) {
            document.dispatchEvent(new CustomEvent('petron:' + mod, { detail: data }));
        });
    });

    // ── PetronSave — global helper for all module save handlers ──────────────
    // Wraps any fetch/AJAX save and auto-triggers refresh on success.
    //
    // Example usage in any module after a successful AJAX save:
    //   PetronRealtime.trigger(['notifications','badges','transactions']);
    //
    // Or use PetronSave for promise chaining:
    //   PetronSave(fetch(url, opts), ['transactions','dashboard'])
    //     .then(data => { ... });
    //
    window.PetronSave = function(fetchPromise, modules) {
        return Promise.resolve(fetchPromise).then(function(data) {
            if (data && (data.ok || data.success)) {
                PetronRealtime.trigger(['notifications','badges'].concat(modules || []));
            }
            return data;
        });
    };

})();
</script>
