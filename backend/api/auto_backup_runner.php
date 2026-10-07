<?php
/**
 * Automated Database Backup Runner Endpoint
 * backend/api/auto_backup_runner.php
 *
 * Runs scheduled backups automatically in background. Can be triggered by:
 * 1. System-wide AJAX heartbeats (e.g. live_sync.js, database_management.php)
 * 2. Windows Task Scheduler / Cron: php auto_backup_runner.php
 * 3. Browser fetch requests
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../backup_lib.php';

$is_cli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));

if (!$is_cli) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

$force = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');
$check_only = isset($_GET['check_only']) && ($_GET['check_only'] === '1');

$info = get_backup_schedule_info($pdo);
$ran  = false;
$msg  = '';

if (!$info['enabled'] && !$force) {
    $msg = 'Automatic backup schedule is disabled.';
} elseif ($force) {
    $backup_dir = get_backup_directory();
    $res = execute_database_backup($pdo, $backup_dir, null, 'Automated (Manual Trigger)');
    apply_backup_retention_policy($pdo, (int)$info['retention_days']);
    cfg_set($pdo, 'backup_last_auto_run', date('Y-m-d H:i:s'), 0);
    $ran = true;
    $msg = "Backup forced successfully: {$res['filename']}";
} elseif ($check_only) {
    $msg = $info['is_due'] ? 'Backup is currently due.' : 'Backup is not yet due.';
} else {
    if (check_and_run_scheduled_backup($pdo)) {
        $ran = true;
        $msg = 'Scheduled automated backup completed successfully.';
    } else {
        $msg = 'Backup schedule checked; not due yet.';
    }
}

// Re-fetch updated schedule info
$updated_info = get_backup_schedule_info($pdo);

// Get latest backup
$latest = null;
try {
    $stmt = $pdo->query("SELECT id, backup_name, backup_size, status, created_at FROM database_backups ORDER BY created_at DESC LIMIT 1");
    $latest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}

$response = [
    'success'       => true,
    'ran'           => $ran,
    'message'       => $msg,
    'schedule'      => $updated_info,
    'latest_backup' => $latest,
    'server_time'   => date('Y-m-d H:i:s'),
];

if ($is_cli) {
    echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
    if ($ran) {
        echo "Ran: YES. File: " . ($latest['backup_name'] ?? 'u261539219_petrondbs.sql') . "\n";
    }
} else {
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
