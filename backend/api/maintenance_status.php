<?php
/**
 * Public Maintenance Status API Endpoint
 * Accessible without login so login pages, client heartbeats, and apps can check maintenance state.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

try {
    // 1. Run automated check: if timer has expired, immediately conclude maintenance mode in DB!
    if (function_exists('check_and_auto_conclude_maintenance')) {
        check_and_auto_conclude_maintenance($pdo);
    }

    // 2. If client triggered auto_conclude parameter (e.g. countdown hit 0)
    if (!empty($_GET['auto_conclude']) || !empty($_POST['auto_conclude'])) {
        if (function_exists('force_conclude_maintenance_mode')) {
            force_conclude_maintenance_mode($pdo, 'Timer concluded via client trigger');
        }
    }

    $stmt = $pdo->prepare("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE setting_key IN ('maintenance_mode', 'system_status', 'maintenance_message', 'maintenance_end_time', 'last_system_update') 
          AND station_id = 0
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $is_maintenance = (!empty($rows['maintenance_mode']) && in_array(trim((string)$rows['maintenance_mode']), ['1', 'true', 1], true));
    $end_time = trim((string)($rows['maintenance_end_time'] ?? ''));

    // Secondary safety check: if maintenance mode is still 1 but end_time is in the past, disable it right now!
    if ($is_maintenance && !empty($end_time)) {
        $end_ts = strtotime($end_time);
        if ($end_ts !== false && $end_ts > 0 && time() >= $end_ts) {
            if (function_exists('force_conclude_maintenance_mode')) {
                force_conclude_maintenance_mode($pdo, 'Scheduled target time reached');
            }
            $is_maintenance = false;
            $end_time = '';
        }
    }

    $status = $is_maintenance ? ($rows['system_status'] ?? 'Maintenance') : 'Online';
    $message = !empty($rows['maintenance_message']) ? $rows['maintenance_message'] : 'The system is currently undergoing scheduled maintenance to improve performance and stability. Please check back shortly.';
    $last_update = $rows['last_system_update'] ?? date('Y-m-d H:i:s');

    $remaining_seconds = 0;
    if ($is_maintenance && !empty($end_time)) {
        $end_ts = strtotime($end_time);
        $now_ts = time();
        if ($end_ts && $end_ts > $now_ts) {
            $remaining_seconds = $end_ts - $now_ts;
        }
    }

    echo json_encode([
        'success'           => true,
        'maintenance_mode'  => $is_maintenance,
        'system_status'     => $status,
        'message'           => $message,
        'end_time'          => $end_time,
        'server_time'       => date('Y-m-d H:i:s'),
        'remaining_seconds' => $remaining_seconds
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success'          => false,
        'maintenance_mode' => false,
        'message'          => $e->getMessage()
    ]);
}
