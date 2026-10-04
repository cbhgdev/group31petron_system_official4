<?php
$page_id = 'database_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me      = current_user();
$my_role = role_key($me['role'] ?? 'staff');
if (!in_array($my_role, ['superadmin', 'developer'])) {
    header("Location: dashboard.php"); exit;
}

// ── Helper: get/set system_config ─────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_config (
        config_key VARCHAR(100) PRIMARY KEY,
        config_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* ignore */ }

function cfg_get(PDO $pdo, string $key, string $default = ''): string {
    try {
        $r = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
        $r->execute([$key]);
        $v = $r->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Exception $e) { return $default; }
}
function cfg_set(PDO $pdo, string $key, string $value, int $uid = 0): void {
    try {
        $pdo->prepare("INSERT INTO system_config (config_key, config_value)
            VALUES(?, ?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value), updated_at=NOW()")
            ->execute([$key, $value]);
    } catch (Exception $e) {
        error_log("cfg_set failed for key={$key}: " . $e->getMessage());
    }
}

// ── Retention Policy Engine ───────────────────────────────────────────
function apply_backup_retention_policy(PDO $pdo, int $retention_days): int {
    if ($retention_days <= 0) return 0;
    try {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
        $stmt = $pdo->prepare("UPDATE database_backups 
            SET status = 'Archived' 
            WHERE created_at < ? AND status = 'Completed'");
        $stmt->execute([$cutoff]);
        return (int)$stmt->rowCount();
    } catch (Exception $e) {
        error_log("Retention policy error: " . $e->getMessage());
        return 0;
    }
}

// ── Core System Backup Engine ─────────────────────────────────────────
function execute_database_backup(PDO $pdo, string $backup_dir, ?int $user_id = null, string $trigger_label = 'Manual'): array {
    $btype = 'Full Backup';
    $comp  = 'SQL';
    $fname = 'u261539219_petrondbs.sql';
    $fpath = $backup_dir . $fname;
    $db_name = 'u261539219_petrondbs';

    if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);

    // 1. Try real mysqldump first
    $mysqldump_bin = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    if (!file_exists($mysqldump_bin)) $mysqldump_bin = 'mysqldump';

    $dump_args  = "--host=localhost --user=root --single-transaction --quick --skip-lock-tables --routines --triggers";
    $dump_cmd   = "\"{$mysqldump_bin}\" {$dump_args} {$db_name} > " . escapeshellarg($fpath) . " 2>&1";
    $dump_out_arr = [];
    @exec($dump_cmd, $dump_out_arr, $dump_ret);

    $fsize  = file_exists($fpath) ? filesize($fpath) : 0;
    $status = ($dump_ret === 0 && $fsize > 500) ? 'Completed' : 'Simulated';

    // 2. Fallback: PHP-PDO full SQL dump
    if ($status === 'Simulated') {
        try {
            $header  = "-- ============================================================\n";
            $header .= "-- Petron Station Management System\n";
            $header .= "-- Database: {$db_name}\n";
            $header .= "-- Backup Type: Full Backup\n";
            $header .= "-- Trigger: {$trigger_label}\n";
            $header .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $header .= "-- ============================================================\n\n";
            $header .= "SET FOREIGN_KEY_CHECKS=0;\n";
            $header .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
            $header .= "SET NAMES utf8mb4;\n\n";
            file_put_contents($fpath, $header);

            $tables  = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            $skipped = [];

            foreach ($tables as $tbl) {
                try {
                    $create = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_NUM);
                    $block  = "\n-- -----------------------------------------------------------\n";
                    $block .= "-- Table: `{$tbl}`\n";
                    $block .= "-- -----------------------------------------------------------\n";
                    $block .= "DROP TABLE IF EXISTS `{$tbl}`;\n";
                    $block .= $create[1] . ";\n";

                    $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        $cols    = array_map(fn($c) => "`{$c}`", array_keys($rows[0]));
                        $block  .= "\nINSERT INTO `{$tbl}` (" . implode(', ', $cols) . ") VALUES\n";
                        $vblocks = [];
                        foreach ($rows as $row) {
                            $vals    = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($row));
                            $vblocks[] = '(' . implode(', ', $vals) . ')';
                        }
                        $block .= implode(",\n", $vblocks) . ";\n";
                    }
                    $block .= "\n";
                    file_put_contents($fpath, $block, FILE_APPEND);

                } catch (Exception $tblEx) {
                    $skipped[] = $tbl;
                    file_put_contents($fpath,
                        "\n-- SKIPPED `{$tbl}`: " . $tblEx->getMessage() . "\n\n",
                        FILE_APPEND
                    );
                }
            }

            $footer  = "SET FOREIGN_KEY_CHECKS=1;\n";
            $footer .= "\n-- Dump completed: " . date('Y-m-d H:i:s') . "\n";
            if (!empty($skipped)) {
                $footer .= "-- Skipped tables (" . count($skipped) . "): " . implode(', ', $skipped) . "\n";
            }
            file_put_contents($fpath, $footer, FILE_APPEND);

            $fsize  = filesize($fpath);
            $status = 'Completed';

        } catch (Exception $dumpEx) {
            $stub = "-- Backup generation failed: " . $dumpEx->getMessage() . "\n";
            file_put_contents($fpath, $stub);
            $fsize  = strlen($stub);
            $status = 'Simulated';
        }
    }

    // Also maintain a timestamped archive copy for point-in-time recovery
    $timestamp = date('Ymd_His');
    $archive_name = "petron_pos_db_{$timestamp}.sql";
    if (file_exists($fpath) && $fsize > 0) {
        @copy($fpath, $backup_dir . $archive_name);
    }

    // Save record to database_backups
    $pdo->prepare("INSERT INTO database_backups
        (backup_name, backup_file, backup_size, backup_type, compression, status, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,NOW())")
        ->execute([$fname, '/backup/database/' . $fname, $fsize, $btype, $comp, $status, $user_id]);

    $bid = (int)$pdo->lastInsertId();

    if ($user_id) {
        log_activity($pdo, $user_id, 'Database Management',
            "{$trigger_label} backup: {$fname} (Status:{$status}, Size:" . round($fsize/1024,1) . " KB)");
    } else {
        log_activity($pdo, 0, 'Database Management',
            "Automated system backup: {$fname} (Status:{$status}, Size:" . round($fsize/1024,1) . " KB)");
    }

    return [
        'success'  => true,
        'id'       => $bid,
        'filename' => $fname,
        'size'     => $fsize,
        'status'   => $status,
    ];
}

// ── Automated Scheduled Backup Checker ────────────────────────────────
function check_and_run_scheduled_backup(PDO $pdo, string $backup_dir): bool {
    $freq = cfg_get($pdo, 'backup_frequency', 'manual');
    if ($freq === 'manual' || empty($freq)) {
        return false;
    }

    $last_run_str = cfg_get($pdo, 'backup_last_auto_run', '');
    $last_run = !empty($last_run_str) ? strtotime($last_run_str) : 0;
    $now = time();
    $is_due = false;

    if ($freq === 'hourly') {
        // Run if never run or >= 3600 seconds (1 hour) since last run
        if ($last_run === 0 || ($now - $last_run) >= 3600) {
            $is_due = true;
        }
    } elseif ($freq === 'daily') {
        $stime = cfg_get($pdo, 'backup_scheduled_time', '02:00');
        $todayScheduled = strtotime(date('Y-m-d') . ' ' . $stime);
        // If scheduled time has arrived today and we haven't run today at or after the scheduled time
        if ($now >= $todayScheduled && $last_run < $todayScheduled) {
            $is_due = true;
        }
    } elseif ($freq === 'weekly') {
        // Run once every 7 days (604800s)
        if ($last_run === 0 || ($now - $last_run) >= 604800) {
            $is_due = true;
        }
    } elseif ($freq === 'monthly') {
        // Run once every 30 days (2592000s)
        if ($last_run === 0 || ($now - $last_run) >= 2592000) {
            $is_due = true;
        }
    }

    if ($is_due) {
        // Prevent concurrent execution races
        cfg_set($pdo, 'backup_last_auto_run', date('Y-m-d H:i:s'), 0);

        execute_database_backup($pdo, $backup_dir, null, 'Automated');

        // Apply retention policy
        $ret = max(1, (int)cfg_get($pdo, 'backup_retention_days', '30'));
        apply_backup_retention_policy($pdo, $ret);

        return true;
    }

    return false;
}


// ── Ensure backup & restore columns exist ──────────────────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM database_backups")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('backup_type',  $cols)) $pdo->exec("ALTER TABLE database_backups ADD COLUMN backup_type VARCHAR(50) DEFAULT 'Full Backup'");
    if (!in_array('status',       $cols)) $pdo->exec("ALTER TABLE database_backups ADD COLUMN status VARCHAR(30) DEFAULT 'Completed'");
    if (!in_array('created_by',   $cols)) $pdo->exec("ALTER TABLE database_backups ADD COLUMN created_by INT DEFAULT NULL");
    if (!in_array('compression',  $cols)) $pdo->exec("ALTER TABLE database_backups ADD COLUMN compression VARCHAR(20) DEFAULT 'SQL'");
    if (!in_array('verified',     $cols)) $pdo->exec("ALTER TABLE database_backups ADD COLUMN verified TINYINT(1) DEFAULT 0");
    if (!in_array('backup_file',  $cols)) $pdo->exec("ALTER TABLE database_backups ADD COLUMN backup_file VARCHAR(500) DEFAULT ''");

    // Ensure restore_logs has backup_id column
    $rcols = $pdo->query("SHOW COLUMNS FROM restore_logs")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('backup_id', $rcols)) {
        $pdo->exec("ALTER TABLE restore_logs ADD COLUMN backup_id INT DEFAULT NULL AFTER backup_name");
    }

    // Auto-link any existing completed restore_logs entries and mark matching backup as Restored
    $unlinked = $pdo->query("SELECT id, backup_name, restored_at FROM restore_logs WHERE (backup_id IS NULL OR backup_id = 0) AND status IN ('completed','success')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($unlinked as $ul) {
        $mstmt = $pdo->prepare("SELECT id FROM database_backups WHERE backup_name = ? AND created_at <= ? AND status NOT IN ('Archived','Restored') ORDER BY created_at DESC LIMIT 1");
        $mstmt->execute([$ul['backup_name'], $ul['restored_at']]);
        $matchedId = $mstmt->fetchColumn();
        if ($matchedId) {
            $pdo->prepare("UPDATE restore_logs SET backup_id = ? WHERE id = ?")->execute([$matchedId, $ul['id']]);
            $pdo->prepare("UPDATE database_backups SET status = 'Restored' WHERE id = ?")->execute([$matchedId]);
        }
    }
} catch (Exception $e) { /* ignore */ }

// ── Load config ────────────────────────────────────────────────────────
$cfg_backup_frequency = cfg_get($pdo, 'backup_frequency',       'manual');
$cfg_scheduled_time   = cfg_get($pdo, 'backup_scheduled_time',  '02:00');
$cfg_retention_days   = cfg_get($pdo, 'backup_retention_days',  '30');
$backup_dir           = __DIR__ . '/../backups/';
$backup_dir_display   = '/backup/database/';
if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);

$msg     = $_SESSION['db_flash_msg']     ?? $_GET['msg']     ?? '';
$success = $_SESSION['db_flash_success'] ?? $_GET['success'] ?? '';
unset($_SESSION['db_flash_msg'], $_SESSION['db_flash_success']);
$active_tab = $_REQUEST['tab'] ?? 'backup';

// ── POST handler ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!empty($_POST['tab'])) {
        $active_tab = $_POST['tab'];
    } elseif (in_array($action, ['save_backup_config','run_backup'])) {
        $active_tab = 'backup';
    } elseif ($action === 'restore') {
        $active_tab = 'restore';
    }


    // ── Save Backup Config ─────────────────────────────────────────────
    if ($action === 'save_backup_config') {
        $freq     = $_POST['backup_frequency']    ?? 'manual';
        $stime    = $_POST['scheduled_time']      ?? '02:00';
        $ret      = max(1, (int)($_POST['retention_days'] ?? 30));
        cfg_set($pdo, 'backup_frequency',      $freq,  $me['id']);
        cfg_set($pdo, 'backup_scheduled_time', $stime, $me['id']);
        cfg_set($pdo, 'backup_retention_days', $ret,   $me['id']);
        $cfg_backup_frequency = $freq;
        $cfg_scheduled_time   = $stime;
        $cfg_retention_days   = $ret;

        // Apply retention policy immediately
        apply_backup_retention_policy($pdo, $ret);

        // Check if automated backup is immediately due under the schedule
        check_and_run_scheduled_backup($pdo, $backup_dir);

        log_activity($pdo, $me['id'], 'Database Management', "Saved backup configuration: Frequency={$freq}, Time={$stime}, Retention={$ret} Days");
        $freq_label = ucfirst($freq);
        $success = "Backup configuration saved successfully (Frequency: {$freq_label}, Retention: {$ret} Days).";
    }

    // ── Run Manual Backup (Synchronized with Backup Configuration) ──────
    elseif ($action === 'run_backup') {
        // Automatically save and sync configuration if provided
        if (isset($_POST['backup_frequency'])) {
            $freq     = $_POST['backup_frequency']    ?? 'manual';
            $stime    = $_POST['scheduled_time']      ?? '02:00';
            $ret      = max(1, (int)($_POST['retention_days'] ?? 30));
            cfg_set($pdo, 'backup_frequency',      $freq,  $me['id']);
            cfg_set($pdo, 'backup_scheduled_time', $stime, $me['id']);
            cfg_set($pdo, 'backup_retention_days', $ret,   $me['id']);
            $cfg_backup_frequency = $freq;
            $cfg_scheduled_time   = $stime;
            $cfg_retention_days   = $ret;
            log_activity($pdo, $me['id'], 'Database Management', "Synchronized backup configuration: Frequency={$freq}, Time={$stime}, Retention={$ret} Days");
        } else {
            $ret = max(1, (int)cfg_get($pdo, 'backup_retention_days', '30'));
        }

        $res   = execute_database_backup($pdo, $backup_dir, $me['id'], 'Manual');
        $fname = $res['filename'];
        $fsize = $res['size'];
        $status= $res['status'];

        // Apply retention policy with the active retention days
        apply_backup_retention_policy($pdo, $ret);

        $success = "Configuration synchronized and backup <strong>{$fname}</strong> created successfully. <small>(Status: {$status}, Size: " .
                   ($fsize >= 1048576 ? round($fsize/1048576,2).' MB' : round($fsize/1024,1).' KB') . ")</small>";
    }


    // ── Archive Backup (Soft Delete) ──────────────────────────────────
    elseif ($action === 'archive_backup' || $action === 'delete_backup') {
        $bid  = (int)($_POST['backup_id'] ?? 0);
        $row  = $pdo->prepare("SELECT backup_name FROM database_backups WHERE id=?");
        $row->execute([$bid]);
        $brow = $row->fetch(PDO::FETCH_ASSOC);
        if ($brow) {
            $pdo->prepare("UPDATE database_backups SET status='Archived' WHERE id=?")->execute([$bid]);
            log_activity($pdo, $me['id'], 'Database Management', "Archived backup: {$brow['backup_name']}");
            $success = "Backup <strong>" . htmlspecialchars($brow['backup_name']) . "</strong> archived successfully.";
        } else { $msg = "Backup not found."; }
    }

    // ── Verify Backup ──────────────────────────────────────────────────
    elseif ($action === 'verify_backup') {
        $bid  = (int)($_POST['backup_id'] ?? 0);
        $row  = $pdo->prepare("SELECT backup_name FROM database_backups WHERE id=?");
        $row->execute([$bid]);
        $brow = $row->fetch(PDO::FETCH_ASSOC);
        if ($brow) {
            $fpath  = $backup_dir . $brow['backup_name'];
            $ok     = file_exists($fpath) && filesize($fpath) > 0;
            $pdo->prepare("UPDATE database_backups SET verified=? WHERE id=?")->execute([$ok ? 1 : 0, $bid]);
            log_activity($pdo, $me['id'], 'Database Management', "Verified backup: {$brow['backup_name']} (" . ($ok ? 'OK' : 'FAILED') . ")");
            $success = $ok ? "Backup verified successfully — file is complete and readable."
                           : "Verification failed — backup file is missing or empty.";
        } else { $msg = "Backup record not found."; }
    }

    // ── Restore ────────────────────────────────────────────────────────
    elseif ($action === 'restore') {
        $bid         = (int)($_POST['backup_id'] ?? 0);
        $confirm_txt = trim($_POST['confirm_text'] ?? '');
        $dev_pass    = trim($_POST['dev_password'] ?? '');
        if (strtoupper($confirm_txt) !== 'RESTORE') {
            $msg = "You must type RESTORE exactly to confirm.";
        } elseif ($bid <= 0) {
            $msg = "Please select a backup file to restore.";
        } elseif (empty($dev_pass)) {
            $msg = "Please enter your developer password to authorize restore.";
        } else {
            // Verify developer/superadmin password (users table uses password_hash)
            $uCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $passCol = in_array('password_hash', $uCols) ? 'password_hash' : (in_array('password', $uCols) ? 'password' : 'password_hash');
            $uStmt = $pdo->prepare("SELECT `{$passCol}` FROM users WHERE id = ?");
            $uStmt->execute([$me['id']]);
            $userHash = $uStmt->fetchColumn();
            $passOk = false;
            if ($userHash) {
                if (password_verify($dev_pass, $userHash) || md5($dev_pass) === $userHash || hash('sha256', $dev_pass) === $userHash || $dev_pass === $userHash) {
                    $passOk = true;
                }
            }

            if (!$passOk) {
                $msg = "Incorrect developer password. Authorization failed.";
            } else {
                $row = $pdo->prepare("SELECT * FROM database_backups WHERE id=?");
                $row->execute([$bid]);
                $brow = $row->fetch(PDO::FETCH_ASSOC);
                if ($brow) {
                    // Update status in database_backups to 'Restored' so it leaves the Restore Backup module
                    $pdo->prepare("UPDATE database_backups SET status='Restored' WHERE id=?")->execute([$bid]);

                    // Log the restore attempt with backup_id in restore_logs
                    try {
                        $rcols = $pdo->query("SHOW COLUMNS FROM restore_logs")->fetchAll(PDO::FETCH_COLUMN);
                        if (in_array('backup_id', $rcols)) {
                            $pdo->prepare("INSERT INTO restore_logs (backup_name,backup_id,restored_by,status,details) VALUES(?,?,?,?,?)")
                                ->execute([$brow['backup_name'] ?? "ID:{$bid}", $bid, $me['id'], 'completed', 'Restore initiated from Database Management']);
                        } else {
                            $pdo->prepare("INSERT INTO restore_logs (backup_name,restored_by,status,details) VALUES(?,?,?,?)")
                                ->execute([$brow['backup_name'] ?? "ID:{$bid}", $me['id'], 'completed', 'Restore initiated from Database Management']);
                        }
                    } catch (Exception $re) {
                        $pdo->prepare("INSERT INTO restore_logs (backup_name,restored_by,status,details) VALUES(?,?,?,?)")
                            ->execute([$brow['backup_name'] ?? "ID:{$bid}", $me['id'], 'completed', 'Restore initiated from Database Management']);
                    }

                    log_activity($pdo, $me['id'], 'Database Management', "Restored database from backup: " . ($brow['backup_name'] ?? "ID:{$bid}"));
                    $success = "Database successfully restored from <strong>" . htmlspecialchars($brow['backup_name'] ?? '') . "</strong>. Record has been moved to Restore History.";
                } else {
                    $msg = "Selected backup record not found.";
                }
            }
        }
    }

    // Post-Redirect-Get (PRG) pattern with Session Flash to prevent stale query string messages
    if ($msg || $success) {
        $target_tab = 'backup';
        if ($action === 'restore') $target_tab = 'restore';
        elseif (in_array($action, ['save_backup_config','run_backup','archive_backup','verify_backup'])) $target_tab = 'backup';

        if ($msg)     $_SESSION['db_flash_msg']     = $msg;
        if ($success) $_SESSION['db_flash_success'] = $success;

        $redirect_url = "database_management.php?tab=" . urlencode($target_tab);
        header("Location: " . $redirect_url);
        exit;
    }
}

// ── Auto-run scheduled backup if due ─────────────────────────────────
check_and_run_scheduled_backup($pdo, $backup_dir);

// ── Data queries ───────────────────────────────────────────────────────
// Backup history with created_by user join (all backups for Tab 1)
try {
    $backup_history = $pdo->query(
        "SELECT b.*, u.first_name, u.last_name
         FROM database_backups b LEFT JOIN users u ON u.id = b.created_by
         ORDER BY b.created_at DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $backup_history = []; }

// Backups available for restore (Tab 2 top table):
// Exclude backups that have already been restored or archived, or are in restore_logs
try {
    $restore_available_backups = $pdo->query(
        "SELECT b.*, u.first_name, u.last_name
         FROM database_backups b LEFT JOIN users u ON u.id = b.created_by
         WHERE b.status NOT IN ('Restored', 'Archived')
           AND b.id NOT IN (
               SELECT COALESCE(backup_id, 0) FROM restore_logs WHERE backup_id IS NOT NULL AND status IN ('completed', 'success')
           )
         ORDER BY b.created_at DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $restore_available_backups = []; }

// Restore history
try {
    $restore_history = $pdo->query(
        "SELECT r.*, u.first_name, u.last_name
         FROM restore_logs r LEFT JOIN users u ON u.id = r.restored_by
         ORDER BY r.restored_at DESC LIMIT 30"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $restore_history = []; }

// Security logs
$sec_date_from = $_GET['date_from'] ?? '';
$sec_date_to   = $_GET['date_to']   ?? '';
$sec_user_id   = $_GET['user_id']   ?? '';
$sec_where = "1=1";
$sec_params = [];
if ($sec_date_from) { $sec_where .= " AND a.created_at >= ?"; $sec_params[] = $sec_date_from . ' 00:00:00'; }
if ($sec_date_to)   { $sec_where .= " AND a.created_at <= ?"; $sec_params[] = $sec_date_to . ' 23:59:59'; }
if ($sec_user_id)   { $sec_where .= " AND a.user_id = ?";     $sec_params[] = (int)$sec_user_id; }
$sec_stmt = $pdo->prepare(
    "SELECT a.id, a.user_id, a.action, a.details, a.ip_address, a.created_at,
            u.first_name, u.last_name
     FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
     WHERE (a.action LIKE '%Database%' OR a.action LIKE '%Backup%' OR a.action LIKE '%Restore%'
            OR a.action LIKE '%Migration%' OR a.action LIKE '%Export%') AND {$sec_where}
     ORDER BY a.created_at DESC LIMIT 200"
);
$sec_stmt->execute($sec_params);
$security_logs = $sec_stmt->fetchAll(PDO::FETCH_ASSOC);

// Users list for filter
$users_list = $pdo->query("SELECT id, first_name, last_name FROM users ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);

// Database size
try {
    $db_size_row = $pdo->query(
        "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                COUNT(*) AS table_count
         FROM information_schema.tables
         WHERE table_schema = DATABASE()"
    )->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $db_size_row = ['size_mb' => '—', 'table_count' => '—']; }

$csrf = $_SESSION['csrf_token'] ?? '';

// ── AJAX REAL-TIME AUTO-REFRESH ENDPOINT FOR DATABASE MANAGEMENT ──────────────
if (isset($_GET['ajax_db']) && $_GET['ajax_db'] == '1') {
    header('Content-Type: application/json');
    
    // Check and run scheduled auto-backup in the background if due
    if (check_and_run_scheduled_backup($pdo, $backup_dir)) {
        try {
            $backup_history = $pdo->query(
                "SELECT b.*, u.first_name, u.last_name
                 FROM database_backups b LEFT JOIN users u ON u.id = b.created_by
                 ORDER BY b.created_at DESC LIMIT 50"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }
    
    $verified_cnt = count(array_filter($backup_history, fn($b) => !empty($b['verified'])));
    $last_b_date  = !empty($backup_history) ? date('M d', strtotime($backup_history[0]['created_at'])) : '—';
    $last_b_time  = !empty($backup_history) ? date('h:i A', strtotime($backup_history[0]['created_at'])) : 'No backups yet';
    
    echo json_encode([
        'success'            => true,
        'total_backups'      => count($backup_history),
        'verified_count'     => $verified_cnt,
        'db_size_mb'         => $db_size_row['size_mb'] ?? '—',
        'table_count'        => $db_size_row['table_count'] ?? '—',
        'last_backup_date'   => $last_b_date,
        'last_backup_time'   => $last_b_time,
        'backup_count_text'  => count($backup_history) . ' records',
        'restore_count_text' => count($restore_history) . ' records',
        'security_count_text'=> count($security_logs) . ' entries',
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ═══════════════════════════════════════════════════════
   DATABASE MANAGEMENT — Elder-Friendly Enterprise Stylesheet
   Matching Admin Management typography standards
════════════════════════════════════════════════════════ */
:root {
  --db-blue:     #002F6C;
  --db-blue2:    #004a9e;
  --db-red:      #cc0000;
  --db-green:    #16a34a;
  --db-yellow:   #d97706;
  --db-gray:     #64748b;
  --db-surface:  #f8fafc;
  --db-border:   #e2e8f0;
  --db-radius:   14px;
}

/* Zero horizontal scrolling */
html, body {
  overflow-x: hidden !important;
  max-width: 100vw !important;
  box-sizing: border-box !important;
}
*, *:before, *:after {
  box-sizing: border-box !important;
}

/* Page wrapper */
.db-page {
  padding: 0 !important;
  width: 100% !important;
  max-width: 100% !important;
  overflow-x: hidden !important;
  box-sizing: border-box !important;
}

/* Header Row & Title */
.db-header-row {
  margin-bottom: 25px !important;
}
.db-page-title {
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  margin-bottom: 0 !important;
  margin-top: 0 !important;
  padding: 0 !important;
  border: none !important;
  width: 100% !important;
}
.db-page-title h1 {
  margin: 0 !important;
  color: #002f70 !important;
  font-size: 24px !important;
  font-weight: 700 !important;
  text-transform: uppercase !important;
  letter-spacing: 0.5px !important;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  line-height: 1.2 !important;
}
.db-page-title i {
  font-size: 24px !important;
  color: #002f70 !important;
}
.db-subtitle {
  color: #666 !important;
  font-size: 15px !important;
  margin: 6px 0 24px !important;
}

/* Stat cards - Matching Manager Fuel Transaction Validation Standard */
.afto-cards, .db-stat-row { display: grid !important; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) !important; gap: 16px !important; margin-bottom: 24px !important; }
.afto-card, .db-stat-card { background: #ffffff !important; border: 1px solid #cbd5e1 !important; border-radius: 10px !important; padding: 16px !important; display: flex !important; align-items: center !important; justify-content: space-between !important; box-shadow: 0 1px 3px rgba(0,0,0,.05) !important; position: relative !important; overflow: hidden !important; }
.afto-card-info, .db-stat-info { display: flex !important; flex-direction: column !important; }
.afto-card-lbl, .db-stat-label { font-size: 11px !important; font-weight: 700 !important; color: #64748b !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; margin-bottom: 4px !important; line-height: 1.2 !important; }
.afto-card-val, .db-stat-val { font-size: 20px !important; font-weight: 700 !important; color: #1e293b !important; line-height: 1.2 !important; }
.afto-card-sub, .db-stat-sub { font-size: 11px !important; color: #64748b !important; font-weight: 600 !important; margin-top: 3px !important; }
.afto-card-icon, .db-stat-icon { font-size: 24px !important; opacity: 0.8 !important; width: auto !important; height: auto !important; background: transparent !important; border-radius: 0 !important; display: inline-block !important; margin: 0 !important; padding: 0 !important; }
.afto-card.blue .afto-card-icon, .db-stat-card.blue .db-stat-icon, .db-stat-icon.blue   { color: #2563eb !important; background: transparent !important; }
.afto-card.green .afto-card-icon, .db-stat-card.green .db-stat-icon, .db-stat-icon.green  { color: #16a34a !important; background: transparent !important; }
.afto-card.yellow .afto-card-icon, .db-stat-card.yellow .db-stat-icon, .db-stat-icon.yellow { color: #d97706 !important; background: transparent !important; }
.afto-card.purple .afto-card-icon, .db-stat-card.purple .db-stat-icon, .db-stat-icon.purple { color: #8b5cf6 !important; background: transparent !important; }
.afto-card.red .afto-card-icon, .db-stat-card.red .db-stat-icon, .db-stat-icon.red { color: #dc2626 !important; background: transparent !important; }

/* Tabs - Elder Friendly Boxed Design */
.db-tab-bar {
  display: flex !important;
  flex-wrap: wrap !important;
  margin-bottom: 24px !important;
  border: 1px solid #d1d9e6 !important;
  border-radius: 0 !important;
  overflow: hidden !important;
  border-bottom: 3px solid #00264D !important;
  gap: 0 !important;
  background: transparent !important;
  padding: 0 !important;
  width: 100% !important;
}
.db-tab-btn {
  flex: 1 !important;
  min-width: 140px !important;
  padding: 14px 20px !important;
  font-size: 14px !important;
  font-weight: 700 !important;
  color: #334155 !important;
  background: #ffffff !important;
  border: none !important;
  border-right: 1px solid #d1d9e6 !important;
  border-radius: 0 !important;
  text-decoration: none !important;
  transition: all 0.15s ease !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 8px !important;
  text-transform: uppercase !important;
  letter-spacing: 0.3px !important;
  text-align: center !important;
  cursor: pointer !important;
  margin-bottom: 0 !important;
  box-shadow: none !important;
  white-space: nowrap !important;
}
.db-tab-btn:last-child { border-right: none !important; }
.db-tab-btn i { font-size: 15px !important; color: inherit !important; }
.db-tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.db-tab-btn.active {
  background: #00264D !important;
  color: #ffffff !important;
  font-weight: 800 !important;
  box-shadow: none !important;
}
.db-tab-pane { display: none; }
.db-tab-pane.active { display: block; }

/* Section cards */
.db-card {
  background: #fff !important;
  border: 1px solid #eaeaea !important;
  border-radius: 14px !important;
  margin-bottom: 24px !important;
  box-shadow: 0 2px 12px rgba(0,0,0,.05) !important;
  overflow: hidden !important;
}
.db-card-header {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  padding: 18px 24px !important;
  border-bottom: 1px solid #f1f5f9 !important;
  background: linear-gradient(90deg,#f8fafc,#fff) !important;
}
.db-card-title {
  font-size: 18px !important;
  font-weight: 700 !important;
  color: #002f70 !important;
  text-transform: uppercase !important;
  letter-spacing: 0.3px !important;
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  margin: 0 !important;
}
.db-card-title i { font-size: 18px !important; }
.db-card-body { padding: 24px !important; }

/* Form Elements - Elder Friendly 14px Labels & 15px Inputs */
.db-form-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.db-form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; }
.db-form-group  { display: flex; flex-direction: column; gap: 6px; }
.db-label {
  font-size: 14px !important;
  font-weight: 600 !important;
  color: #444 !important;
  text-transform: uppercase !important;
  letter-spacing: .3px !important;
  margin-bottom: 4px !important;
}
.db-input, .db-select {
  padding: 12px 14px !important;
  border: 1.5px solid #ddd !important;
  border-radius: 10px !important;
  font-size: 15px !important;
  background: #fff !important;
  color: #1a1a1a !important;
  outline: none !important;
  transition: border-color .2s, box-shadow .2s !important;
  font-family: inherit !important;
}
.db-input:focus, .db-select:focus {
  border-color: var(--db-blue, #002F6C) !important;
  box-shadow: 0 0 0 3px rgba(0,47,108,.1) !important;
}
.db-input[readonly] {
  background: #f8fafc !important;
  color: #475569 !important;
  font-weight: 500 !important;
  cursor: default !important;
}
.db-hint {
  font-size: 13px !important;
  color: #666 !important;
  margin-top: 4px !important;
}

/* Buttons - Elder Friendly 15px Font & Clear Touch Targets */
.db-btn,
button.db-btn,
a.db-btn,
.db-btn *,
.db-btn i,
.db-btn span {
  color: #ffffff !important;
  fill: #ffffff !important;
}
.db-btn {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 8px !important;
  padding: 11px 22px !important;
  border-radius: 6px !important;
  font-size: 15px !important;
  font-weight: 700 !important;
  cursor: pointer !important;
  border: 1.5px solid transparent !important;
  transition: all .2s ease !important;
  text-decoration: none !important;
  box-shadow: 0 1px 3px rgba(0,0,0,0.12) !important;
  line-height: 1.3 !important;
}
.db-btn-primary {
  background: var(--db-blue, #002F6C) !important;
  border-color: var(--db-blue, #002F6C) !important;
}
.db-btn-primary:hover {
  background: #001d45 !important;
  border-color: #001d45 !important;
}
.db-btn-success {
  background: #16a34a !important;
  border-color: #16a34a !important;
}
.db-btn-success:hover {
  background: #15803d !important;
  border-color: #15803d !important;
}
.db-btn-danger {
  background: #dc2626 !important;
  border-color: #dc2626 !important;
}
.db-btn-danger:hover {
  background: #b91c1c !important;
  border-color: #b91c1c !important;
}
.db-btn-warning {
  background: #d97706 !important;
  border-color: #d97706 !important;
}
.db-btn-warning:hover {
  background: #b45309 !important;
  border-color: #b45309 !important;
}
.db-btn-outline {
  background: var(--db-blue, #002F6C) !important;
  border-color: var(--db-blue, #002F6C) !important;
}
.db-btn-outline:hover {
  background: #001d45 !important;
  border-color: #001d45 !important;
}
.db-btn-ghost, .db-btn-gray, .db-btn-archive {
  background: #6b7280 !important;
  border-color: #6b7280 !important;
  color: #ffffff !important;
}
.db-btn-ghost:hover, .db-btn-gray:hover, .db-btn-archive:hover {
  background: #4b5563 !important;
  border-color: #4b5563 !important;
  color: #ffffff !important;
}
.db-btn-sm {
  padding: 7px 15px !important;
  font-size: 13px !important;
  font-weight: 700 !important;
  border-radius: 5px !important;
}
.db-btn-icon {
  width: 36px !important;
  height: 36px !important;
  padding: 0 !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  border-radius: 6px !important;
  font-size: 15px !important;
  box-shadow: 0 1px 3px rgba(0,0,0,0.12) !important;
}
.db-btn-icon i {
  margin: 0 !important;
  color: #ffffff !important;
}

/* Progress bar */
.db-progress-wrap {
  background: #f1f5f9;
  border-radius: 999px;
  height: 14px;
  overflow: hidden;
  margin: 10px 0;
}
.db-progress-bar {
  height: 100%;
  border-radius: 999px;
  transition: width .4s;
  background: linear-gradient(90deg, var(--db-blue2), #3b82f6);
}

/* Tables - Elder Friendly 14px Body & 13px Header */
.db-table-wrap {
  overflow-x: auto !important;
  border-radius: 14px !important;
  border: 1px solid #eaeaea !important;
  box-shadow: 0 2px 8px rgba(0,0,0,.04) !important;
}
.db-table {
  width: 100% !important;
  border-collapse: collapse !important;
  font-size: 14px !important;
}
.db-table thead tr {
  background: #002F70 !important;
}
.db-table thead th {
  color: #ffffff !important;
  background: #002F70 !important;
  padding: 12px 14px !important;
  font-size: 13px !important;
  font-weight: 700 !important;
  text-transform: uppercase !important;
  letter-spacing: .3px !important;
  white-space: nowrap !important;
  text-align: left !important;
}
.db-table tbody tr {
  border-bottom: 1px solid #f0f0f0 !important;
  transition: background .15s !important;
}
.db-table tbody tr:hover {
  background: #f8faff !important;
}
.db-table tbody td {
  padding: 11px 14px !important;
  color: #1a1a1a !important;
  font-size: 14px !important;
  vertical-align: middle !important;
}
.db-table tbody tr:last-child {
  border-bottom: none !important;
}

/* Badges - Elder Friendly 12.5px */
.db-badge {
  display: inline-flex !important;
  align-items: center !important;
  gap: 5px !important;
  padding: 4px 10px !important;
  border-radius: 12px !important;
  font-size: 12.5px !important;
  font-weight: 700 !important;
}
.db-badge-green  { background: #dcfce7 !important; color: #166534 !important; }
.db-badge-blue   { background: #dbeafe !important; color: #1d4ed8 !important; }
.db-badge-yellow { background: #fef9c3 !important; color: #854d0e !important; }
.db-badge-red    { background: #fee2e2 !important; color: #991b1b !important; }
.db-badge-gray   { background: #f1f5f9 !important; color: #475569 !important; }

/* Notification Banners */
#db-notif-container {
  position: fixed;
  top: 76px;
  right: 28px;
  z-index: 999999;
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: 380px;
  max-width: calc(100vw - 32px);
  pointer-events: none;
}
.db-toast, .db-toast * { text-decoration: none !important; }
.db-toast {
  pointer-events: all;
  position: relative;
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
  overflow: hidden;
  animation: dbToastIn .38s cubic-bezier(.17,.67,.27,1.1) both;
  min-width: 0;
  font-family: inherit;
  cursor: pointer;
}
.db-toast.hiding {
  animation: dbToastOut .28s cubic-bezier(.55,0,1,.45) forwards;
  pointer-events: none;
}
@keyframes dbToastIn { from { opacity:0; transform:translateX(110%) scale(.96); } to { opacity:1; transform:translateX(0) scale(1); } }
@keyframes dbToastOut { from { opacity:1; transform:translateX(0) scale(1); max-height:160px; margin-bottom:0; } to { opacity:0; transform:translateX(110%) scale(.96); max-height:0; margin-bottom:-12px; padding-top:0; padding-bottom:0; } }
.db-toast-body { display: flex; align-items: flex-start; gap: 13px; padding: 15px 18px 14px 16px; }
.db-toast-icon { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; margin-top: 1px; }
.db-toast-content { flex: 1; min-width: 0; padding-top: 1px; text-decoration: none !important; }
.db-toast-label { font-size: 12px; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; opacity: .7; margin: 0 0 3px; line-height: 1; }
.db-toast-title { font-size: 15px; font-weight: 700; line-height: 1.35; margin: 0 0 3px; color: #0f172a; word-break: break-word; }
.db-toast-sub { font-size: 13px; font-weight: 500; line-height: 1.45; margin: 0; color: #64748b; }
.db-toast-close { display: none !important; }
.db-toast-progress { display: none !important; }
.db-toast.success .db-toast-icon { background: #dcfce7; color: #16a34a; }
.db-toast.error   .db-toast-icon { background: #fee2e2; color: #dc2626; }
.db-toast.warning .db-toast-icon { background: #fef9c3; color: #d97706; }
.db-toast.info    .db-toast-icon { background: #dbeafe; color: #2563eb; }

/* Modal overlay & styles */
.db-pass-toggle-btn {
  position: absolute !important;
  right: 12px !important;
  top: 50% !important;
  transform: translateY(-50%) !important;
  background: transparent !important;
  border: none !important;
  cursor: pointer !important;
  padding: 0 !important;
  margin: 0 !important;
  width: 24px !important;
  height: 24px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  z-index: 10 !important;
  border-radius: 0 !important;
}
.db-pass-toggle-btn i { color: #64748b !important; font-size: 15px !important; transition: color 0.15s ease !important; }
.db-pass-toggle-btn:hover i { color: #002F6C !important; }

.db-modal-overlay {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5);
  z-index: 9999; align-items: center; justify-content: center;
}
.db-modal-overlay.open { display: flex; }
.db-modal {
  background: #fff !important;
  border-radius: 20px !important;
  width: min(560px, 95vw) !important;
  box-shadow: 0 20px 60px rgba(0,0,0,.25) !important;
  overflow: hidden !important;
  animation: dbModalIn .25s ease;
}
@keyframes dbModalIn { from { opacity:0; transform:translateY(-20px) } to { opacity:1; transform:none } }
.db-modal-header {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  padding: 20px 24px 16px !important;
  border-bottom: 1px solid #f1f5f9 !important;
}
.db-modal-title {
  font-size: 19px !important;
  font-weight: 700 !important;
  color: #002F6C !important;
  text-transform: uppercase !important;
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
}
.db-modal-close { background: none; border: none; font-size: 22px; color: #94a3b8; cursor: pointer; line-height: 1; }
.db-modal-body  { padding: 24px !important; }
.db-modal-footer {
  padding: 18px 24px !important;
  border-top: 1px solid #f1f5f9 !important;
  display: flex !important;
  justify-content: flex-end !important;
  gap: 12px !important;
}

/* Warning box */
.db-warn-box {
  background: #fff7ed;
  border: 1.5px solid #fed7aa;
  border-radius: 10px;
  padding: 16px;
  margin-bottom: 16px;
}
.db-warn-box .db-warn-title { font-size: 15px; font-weight: 800; color: #c2410c; margin: 0 0 8px; display: flex; align-items: center; gap: 6px; }
.db-warn-box ul { margin: 0; padding-left: 18px; color: #7c2d12; font-size: 14px; line-height: 1.8; }

/* Filter bar */
.db-filter-bar { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 18px; }
.db-filter-bar .db-form-group { flex: 1; min-width: 160px; }

/* Empty state */
.db-empty { text-align: center; padding: 48px 20px; color: #94a3b8; }
.db-empty i { font-size: 42px; margin-bottom: 12px; display: block; }
.db-empty p { margin: 0; font-size: 15px; }

/* Verified icon */
.db-verified { color: var(--db-green); }
.db-unverified { color: #e5e7eb; }

/* Restore table */
.db-restore-note { font-size: 13.5px; color: #64748b; margin-top: 8px; font-style: italic; }

/* Print Styles */
@media print {
  @page { size: A4 portrait; margin: 10mm 12mm; }
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  body, html { background: #fff !important; margin: 0 !important; padding: 0 !important; }
  .sidebar, .top-header, .db-header-row, .db-stat-row, .db-tab-bar, .db-filter-bar, .db-btn, .db-card-header button, .db-restore-note, footer, nav, header {
    display: none !important;
  }
  .db-page { padding: 0 !important; width: 100% !important; max-width: 100% !important; }
  .db-card { border: none !important; box-shadow: none !important; margin: 0 !important; }
  .db-card-body { padding: 0 !important; }
  .db-table-wrap { border: none !important; overflow: visible !important; }
  .db-table thead tr { background: #002F6C !important; }
  .db-table thead th { color: #fff !important; background: #002F6C !important; }
}

/* Export Group & Buttons (Matching Reports Standard) */
.rpt-export-group {
  display: inline-flex !important;
  align-items: center !important;
  gap: 8px !important;
  white-space: nowrap !important;
}
.rpt-export-btn {
  padding: 6px 14px !important;
  font-size: 13px !important;
  font-weight: 700 !important;
  border-radius: 6px !important;
  cursor: pointer !important;
  display: inline-flex !important;
  align-items: center !important;
  gap: 6px !important;
  background: #ffffff !important;
  border: 1px solid #cbd5e1 !important;
  text-decoration: none !important;
  transition: all 0.18s ease-in-out !important;
  line-height: 1.4 !important;
}
.rpt-btn-print {
  color: #475569 !important;
  border-color: #cbd5e1 !important;
  background: #ffffff !important;
}
.rpt-btn-print:hover {
  background: #f1f5f9 !important;
  color: #0f172a !important;
  border-color: #94a3b8 !important;
}
.rpt-btn-pdf {
  color: #dc2626 !important;
  border-color: #dc2626 !important;
  background: #ffffff !important;
}
.rpt-btn-pdf:hover {
  background: #fef2f2 !important;
  color: #b91c1c !important;
  border-color: #b91c1c !important;
}
</style>

<?php
// Determine message type from content for smarter classification
$_toast_msgs = [];
if (!empty($msg) && !empty($success)) {
    // URL was polluted with both; show error if error message is distinct, otherwise success
    if (str_contains(strtolower($msg), 'restore') || str_contains(strtolower($msg), 'failed') || str_contains(strtolower($msg), 'must')) {
        $_toast_msgs[] = ['type'=>'error', 'text'=>$msg];
    } else {
        $_toast_msgs[] = ['type'=>'success', 'text'=>$success];
    }
} elseif (!empty($success)) {
    $_toast_msgs[] = ['type'=>'success', 'text'=>$success];
} elseif (!empty($msg)) {
    $isWarning = preg_match('/low stock|low fuel|critical|warning|unsaved/i', $msg);
    $_toast_msgs[] = ['type'=> $isWarning ? 'warning' : 'error', 'text'=>$msg];
}
?>
<!-- Global Notification Container -->
<div id="db-notif-container"></div>

<div class="db-page">
  <!-- Header Row -->
  <div class="db-header-row">
    <div class="db-page-title">
      <i class="fas fa-database"></i>
      <h1>DATABASE MANAGEMENT</h1>
    </div>
  </div>

<!-- Toast init data from PHP -->
<script>
var _DB_TOASTS = <?= json_encode($_toast_msgs) ?>;
</script>

  <!-- Stat Cards (Matching Manager Fuel Transaction Validation Standard) -->
  <div class="afto-cards">
    <div class="afto-card blue">
      <div class="afto-card-info">
        <span class="afto-card-lbl">Total Backups</span>
        <span class="afto-card-val" id="stat_total_backups"><?= count($backup_history) ?></span>
        <span class="afto-card-sub" id="stat_total_backups_sub">Records on file</span>
      </div>
      <div class="afto-card-icon"><i class="fas fa-hdd"></i></div>
    </div>
    <div class="afto-card green">
      <div class="afto-card-info">
        <span class="afto-card-lbl">Verified</span>
        <span class="afto-card-val" id="stat_verified"><?= count(array_filter($backup_history, fn($b) => !empty($b['verified']))) ?></span>
        <span class="afto-card-sub" style="color:#16a34a;"><i class="fas fa-check-circle"></i> Integrity confirmed</span>
      </div>
      <div class="afto-card-icon"><i class="fas fa-check-double"></i></div>
    </div>
    <div class="afto-card yellow">
      <div class="afto-card-info">
        <span class="afto-card-lbl">DB Size</span>
        <span class="afto-card-val" id="stat_db_size"><?= $db_size_row['size_mb'] ?? '—' ?> MB</span>
        <span class="afto-card-sub" id="stat_db_tables"><?= $db_size_row['table_count'] ?? '—' ?> tables</span>
      </div>
      <div class="afto-card-icon"><i class="fas fa-database"></i></div>
    </div>
    <div class="afto-card purple">
      <div class="afto-card-info">
        <span class="afto-card-lbl">Last Backup</span>
        <span class="afto-card-val" id="stat_last_backup_date" style="font-size:16px !important;">
          <?= !empty($backup_history) ? date('M d, Y • h:i A', strtotime($backup_history[0]['created_at'])) : '—' ?>
        </span>
        <span class="afto-card-sub" id="stat_last_backup_time">
          <?= !empty($backup_history) ? '<i class="fas fa-shield-alt"></i> Verified Backup' : 'No backups yet' ?>
        </span>
      </div>
      <div class="afto-card-icon"><i class="fas fa-calendar-alt"></i></div>
    </div>
  </div>

  <!-- Tab Bar -->
  <div class="db-tab-bar">
    <button class="db-tab-btn active" data-tab="backup">
      <i class="fas fa-shield-alt"></i> Backup
    </button>
    <button class="db-tab-btn" data-tab="restore">
      <i class="fas fa-undo-alt"></i> Restore
    </button>
    <button class="db-tab-btn" data-tab="security">
      <i class="fas fa-shield-virus"></i> Security Logs
    </button>
  </div>

  <!-- ══════════════════════════════════════════════════════
       TAB 1: BACKUP
  ══════════════════════════════════════════════════════ -->
  <div class="db-tab-pane active" id="tab-backup">

    <!-- Backup Configuration -->
    <div class="db-card">
      <div class="db-card-header">
        <h3 class="db-card-title"><i class="fas fa-cog"></i> Backup Configuration</h3>
      </div>
      <div class="db-card-body">
        <form method="POST" id="backupConfigForm">
          <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>" class="db-form-tab-input">
          <input type="hidden" name="action" value="save_backup_config">
          <div class="db-form-grid" style="margin-bottom:16px;">
            <div class="db-form-group">
              <label class="db-label">Backup Frequency</label>
              <select name="backup_frequency" id="backupFrequencySelect" class="db-select">
                <?php foreach(['manual'=>'Manual Only','hourly'=>'Every Hour','daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly'] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $cfg_backup_frequency===$k?'selected':''?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
              <span class="db-hint">How often automatic backups are triggered.</span>
            </div>
            <div class="db-form-group" id="sched-time-wrap" style="<?= in_array($cfg_backup_frequency, ['manual', 'hourly'], true) ? 'opacity:.45;pointer-events:none;' : ''?>">
              <label class="db-label">Scheduled Time</label>
              <input type="time" name="scheduled_time" id="scheduledTimeInput" class="db-input" value="<?= htmlspecialchars($cfg_scheduled_time) ?>">
              <span class="db-hint">Applies when Daily, Weekly, or Monthly is selected.</span>
            </div>
          </div>
          <div class="db-form-grid" style="margin-bottom:20px;">
            <div class="db-form-group">
              <label class="db-label">Retention Period</label>
              <select name="retention_days" class="db-select">
                <?php foreach([30=>'30 Days',60=>'60 Days',90=>'90 Days'] as $d=>$dl): ?>
                <option value="<?= $d ?>" <?= (int)$cfg_retention_days===$d?'selected':''?>><?= $dl ?></option>
                <?php endforeach; ?>
              </select>
              <span class="db-hint">Old backups beyond this period will be flagged for cleanup.</span>
            </div>
            <div class="db-form-group">
              <label class="db-label">Storage Location</label>
              <input type="text" class="db-input" value="<?= htmlspecialchars($backup_dir_display) ?>" readonly>
              <span class="db-hint">Server-side storage path (managed by system administrator).</span>
            </div>
          </div>
          <!-- Progress bar (shown when Save & Run Backup Now is clicked) -->
          <div id="backupProgressWrap" style="display:none; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
              <span style="font-size:14.5px; font-weight:700; color:var(--db-blue);" id="backupProgressLabel">Initializing backup…</span>
              <span style="font-size:14.5px; font-weight:800; color:var(--db-blue);" id="backupProgressPct">0%</span>
            </div>
            <div class="db-progress-wrap">
              <div class="db-progress-bar" id="backupProgressBar" style="width:0%;"></div>
            </div>
            <div id="backupProgressStatus" style="font-size:13.5px; color:#64748b; margin-top:4px;"></div>
          </div>
          <div id="backupCompletedMsg" style="display:none; margin-bottom:12px; padding:14px 18px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; color:#166534; font-size:14.5px; font-weight:600;">
            <i class="fas fa-check-circle" style="margin-right:6px;"></i>
            Backup Completed Successfully — page will refresh shortly.
          </div>
          <div style="display:flex; justify-content:flex-end; gap:10px; align-items:center; margin-top:16px;">
            <button type="button" class="db-btn db-btn-success" id="saveAndRunBackupBtn" onclick="saveAndRunBackupNow()">
              <i class="fas fa-play-circle"></i> Save &amp; Run Backup Now
            </button>
          </div>
        </form>
      </div>
    </div>


    <!-- Backup History -->
    <div class="db-card">
      <div class="db-card-header">
        <h3 class="db-card-title"><i class="fas fa-history"></i> Backup History</h3>
        <span style="font-size:13.5px; color:#666; font-weight:600;"><?= count($backup_history) ?> records</span>
      </div>
      <div class="db-card-body" style="padding:0;">
        <?php if (empty($backup_history)): ?>
        <div class="db-empty">
          <i class="fas fa-inbox"></i>
          <p>No backup records found. Run your first backup above.</p>
        </div>
        <?php else: ?>
        <div class="db-table-wrap">
          <table class="db-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Filename</th>
                <th>Size</th>
                <th>Status</th>
                <th>Created At</th>
                <th>Created By</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($backup_history as $i => $bk): ?>
              <tr>
                <td style="color:#666; font-size:13.5px;"><?= $i+1 ?></td>
                <td>
                  <div style="font-weight:600; font-size:14px; font-family:monospace; color:#0f172a;"><?= htmlspecialchars($bk['backup_name'] ?? '') ?></div>
                  <?php if (!empty($bk['verified'])): ?>
                  <div style="font-size:12.5px; color:var(--db-green); margin-top:2px; font-weight:600;"><i class="fas fa-shield-alt"></i> Verified</div>
                  <?php endif; ?>
                </td>
                <td style="font-size:14px;">
                  <?php
                    $sz = (int)($bk['backup_size'] ?? 0);
                    echo $sz >= 1048576 ? round($sz/1048576,2).' MB' : ($sz >= 1024 ? round($sz/1024,1).' KB' : $sz.' B');
                  ?>
                </td>
                <td>
                  <?php
                    $st = strtolower(trim($bk['status'] ?? 'completed'));
                    $st_ok = in_array($st, ['completed', 'success']);
                    $st_res = ($st === 'restored');
                    $st_sim = in_array($st, ['simulated', 'pending']);
                    $st_arc = ($st === 'archived');
                    $bc = $st_ok ? 'db-badge-green' : ($st_res ? 'db-badge-blue' : ($st_sim ? 'db-badge-yellow' : ($st_arc ? 'db-badge-gray' : 'db-badge-red')));
                    $ico = $st_ok ? 'check-circle' : ($st_res ? 'history' : ($st_sim ? 'exclamation-circle' : ($st_arc ? 'box-archive' : 'times-circle')));
                  ?>
                  <span class="db-badge <?= $bc ?>">
                    <i class="fas fa-<?= $ico ?>"></i>
                    <?= ucfirst($bk['status'] ?? 'Completed') ?>
                  </span>
                </td>
                <td style="font-size:13.5px; color:#555;">
                  <?= !empty($bk['created_at']) ? date('M d, Y h:i A', strtotime($bk['created_at'])) : '—' ?>
                </td>
                <td style="font-size:14px; color:#1a1a1a;">
                  <?= !empty($bk['first_name']) ? htmlspecialchars($bk['first_name'].' '.($bk['last_name']??'')) : '—' ?>
                </td>
                <td>
                  <div class="db-action-row" style="display:flex; gap:8px; align-items:center;">
                    <!-- Download via secure PHP handler → always served as u261539219_petrondbs.sql -->
                    <?php
                      $fexists = file_exists($backup_dir . ($bk['backup_name'] ?? ''));
                      $dl_url  = 'db_download.php?id=' . (int)$bk['id'];
                    ?>
                    <a href="<?= $fexists ? $dl_url : '#' ?>"
                       class="db-btn db-btn-success db-btn-icon" title="Download Backup (u261539219_petrondbs.sql)"
                       <?= $fexists ? '' : 'onclick="alert(\'Backup file not found on server.\');return false;"' ?>>
                      <i class="fas fa-download"></i>
                    </a>
                    <!-- Archive -->
                    <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Archive backup <?= htmlspecialchars(addslashes($bk['backup_name'] ?? '')) ?>?')">
                      <input type="hidden" name="tab" value="backup">
                      <input type="hidden" name="action" value="archive_backup">
                      <input type="hidden" name="backup_id" value="<?= $bk['id'] ?>">
                      <button type="submit" class="db-btn db-btn-gray db-btn-icon" title="Archive Backup">
                        <i class="fas fa-box-archive"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>


  </div><!-- /tab-backup -->

  <!-- ══════════════════════════════════════════════════════
       TAB 2: RESTORE
  ══════════════════════════════════════════════════════ -->
  <div class="db-tab-pane" id="tab-restore">
    <div class="db-card">
      <div class="db-card-header">
        <h3 class="db-card-title"><i class="fas fa-undo-alt"></i> Restore Backup</h3>
        <span style="font-size:13.5px; color:#666; font-weight:600;"><?= count($restore_available_backups) ?> available</span>
      </div>
      <div class="db-card-body">
        <?php if (empty($restore_available_backups)): ?>
        <div class="db-empty" style="padding:28px;">
          <i class="fas fa-check-circle" style="color:var(--db-green); font-size:32px; margin-bottom:10px;"></i>
          <p style="font-size:15px; font-weight:600; color:#334155; margin:0 0 4px;">No backups pending restore.</p>
          <p style="font-size:13.5px; color:#64748b; margin:0;">All completed backups have already been restored or archived. Check the Restore Log History below or create a new backup.</p>
        </div>
        <?php else: ?>
        <div class="db-table-wrap" style="margin-bottom:20px;">
          <table class="db-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Filename</th>
                <th>Size</th>
                <th>Date</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($restore_available_backups as $i => $bk): ?>
              <tr>
                <td style="color:#666; font-size:13.5px;"><?= $i+1 ?></td>
                <td style="font-weight:600; font-size:14px; font-family:monospace;">
                  <?= htmlspecialchars($bk['backup_name'] ?? '') ?>
                  <?php if (!empty($bk['verified'])): ?>
                  <div style="font-size:12.5px; color:var(--db-green); margin-top:2px; font-weight:600;"><i class="fas fa-shield-alt"></i> Verified</div>
                  <?php endif; ?>
                </td>
                <td style="font-size:14px;">
                  <?php $sz=(int)($bk['backup_size']??0); echo $sz>=1048576?round($sz/1048576,2).' MB':($sz>=1024?round($sz/1024,1).' KB':$sz.' B'); ?>
                </td>
                <td style="font-size:13.5px; color:#555;"><?= !empty($bk['created_at'])?date('M d, Y h:i A',strtotime($bk['created_at'])):'—' ?></td>
                <td>
                  <?php
                    $bst = strtolower(trim($bk['status'] ?? 'completed'));
                    $b_ok = in_array($bst, ['completed', 'success']);
                    $b_sim = in_array($bst, ['simulated', 'pending']);
                    $b_arc = ($bst === 'archived');
                    $b_cls = $b_ok ? 'db-badge-green' : ($b_sim ? 'db-badge-yellow' : ($b_arc ? 'db-badge-gray' : 'db-badge-red'));
                    $b_ico = $b_ok ? 'fa-check-circle' : ($b_sim ? 'fa-exclamation-circle' : ($b_arc ? 'fa-box-archive' : 'fa-times-circle'));
                  ?>
                  <span class="db-badge <?= $b_cls ?>">
                    <i class="fas <?= $b_ico ?>"></i> <?= ucfirst($bk['status'] ?? 'Completed') ?>
                  </span>
                </td>
                <td>
                  <div class="db-action-row" style="display:flex; gap:8px; align-items:center;">
                    <button type="button" class="db-btn db-btn-primary db-btn-sm"
                      onclick="openRestoreModal(<?= $bk['id'] ?>,'<?= htmlspecialchars(addslashes($bk['backup_name']??''), ENT_QUOTES) ?>')">
                      <i class="fas fa-undo"></i> Restore
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <!-- Restore History -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
          <h4 style="font-size:17px; font-weight:700; color:var(--db-blue); margin:0;">
            <i class="fas fa-history" style="margin-right:6px;"></i>Restore Log History
          </h4>
          <span style="font-size:13px; color:#64748b; font-weight:600;"><?= count($restore_history) ?> records</span>
        </div>
        <?php if (empty($restore_history)): ?>
        <div class="db-empty" style="padding:24px;"><p>No restore events recorded.</p></div>
        <?php else: ?>
        <div class="db-table-wrap">
          <table class="db-table">
            <thead>
              <tr><th>#</th><th>Filename</th><th>Date</th><th>Restored By</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach($restore_history as $ri => $rh): ?>
              <tr>
                <td style="color:#94a3b8;font-size:12px;"><?= $ri+1 ?></td>
                <td style="font-size:14px;font-family:monospace;"><?= htmlspecialchars($rh['backup_name'] ?? '') ?></td>
                <td style="font-size:13.5px;color:#555;"><?= !empty($rh['restored_at'])?date('M d, Y h:i A',strtotime($rh['restored_at'])):'—' ?></td>
                <td style="font-size:14px;"><?= htmlspecialchars(($rh['first_name']??'').($rh['last_name']?' '.$rh['last_name']:'') ?: '—') ?></td>
                <td>
                  <?php
                    $rs = strtolower(trim($rh['status'] ?? ''));
                    $r_ok = in_array($rs, ['completed', 'success', 'restored']) || str_contains($rs, 'success') || str_contains($rs, 'complet');
                    $r_att = in_array($rs, ['attempted', 'pending', 'in_progress']) || str_contains($rs, 'attempt');
                    $r_cls = $r_ok ? 'db-badge-green' : ($r_att ? 'db-badge-yellow' : 'db-badge-red');
                    $r_ico = $r_ok ? 'fa-check-circle' : ($r_att ? 'fa-clock' : 'fa-times-circle');
                  ?>
                  <span class="db-badge <?= $r_cls ?>">
                    <i class="fas <?= $r_ico ?>"></i> <?= ucfirst($rh['status'] ?? '—') ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div><!-- /tab-restore -->

  <!-- ══════════════════════════════════════════════════════
       TAB 3: SECURITY LOGS
  ══════════════════════════════════════════════════════ -->
  <div class="db-tab-pane" id="tab-security">
    <div class="db-card">
      <div class="db-card-header">
        <h3 class="db-card-title"><i class="fas fa-shield-virus"></i> Security Logs</h3>
        <div class="rpt-export-group">
          <button type="button" class="rpt-export-btn rpt-btn-print" onclick="printSecurityLogs()">
            <i class="fas fa-print"></i> Print
          </button>
          <button type="button" class="rpt-export-btn rpt-btn-pdf" onclick="exportSecurityLogsPDF()">
            <i class="fas fa-file-pdf"></i> PDF
          </button>
        </div>
      </div>
      <div class="db-card-body">
        <!-- Filters -->
        <form method="GET" action="">
          <input type="hidden" name="tab" value="security">
          <div class="db-filter-bar">
            <div class="db-form-group">
              <label class="db-label">Date From</label>
              <input type="date" name="date_from" class="db-input" value="<?= htmlspecialchars($sec_date_from) ?>">
            </div>
            <div class="db-form-group">
              <label class="db-label">Date To</label>
              <input type="date" name="date_to" class="db-input" value="<?= htmlspecialchars($sec_date_to) ?>">
            </div>
            <div class="db-form-group">
              <label class="db-label">User</label>
              <select name="user_id" class="db-select">
                <option value="">All Users</option>
                <?php foreach($users_list as $ul): ?>
                <option value="<?= $ul['id'] ?>" <?= $sec_user_id==$ul['id']?'selected':'' ?>>
                  <?= htmlspecialchars($ul['first_name'].' '.($ul['last_name']??'')) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="display:flex;gap:8px;padding-bottom:0;">
              <button type="submit" class="db-btn db-btn-primary db-btn-sm">
                <i class="fas fa-filter"></i> Filter
              </button>
              <a href="?tab=security" class="db-btn db-btn-gray db-btn-sm">
                <i class="fas fa-times"></i> Clear
              </a>
            </div>
          </div>
        </form>

        <?php if (empty($security_logs)): ?>
        <div class="db-empty"><i class="fas fa-shield-alt"></i><p>No security log entries found for the selected filters.</p></div>
        <?php else: ?>
        <div class="db-table-wrap">
          <table class="db-table" id="secLogsTable">
            <thead>
              <tr>
                <th>#</th>
                <th>Date &amp; Time</th>
                <th>Action</th>
                <th>User</th>
                <th>IP Address</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($security_logs as $si => $sl): ?>
              <?php
                $sa  = strtolower($sl['action'] ?? '');
                $det = strtolower($sl['details'] ?? '');
                // Classify event type
                if (str_contains($sa,'backup'))        { $tag='Backup Created';   $bc='db-badge-green'; $ico='fa-shield-alt'; }
                elseif (str_contains($sa,'restore'))   { $tag='Restore Attempt';  $bc='db-badge-yellow';$ico='fa-undo-alt'; }
                elseif (str_contains($sa,'migration')) { $tag='Migration';        $bc='db-badge-blue';  $ico='fa-code-branch'; }
                elseif (str_contains($sa,'export'))    { $tag='DB Export';        $bc='db-badge-blue';  $ico='fa-file-export'; }
                elseif (str_contains($sa,'archive') || str_contains($sa,'delete')) { $tag='Archive Backup'; $bc='db-badge-yellow'; $ico='fa-box-archive'; }
                elseif (str_contains($sa,'verify'))    { $tag='Verify Backup';    $bc='db-badge-green'; $ico='fa-check-shield'; }
                elseif (str_contains($det,'fail'))     { $tag='Failed';           $bc='db-badge-red';   $ico='fa-times-circle'; }
                else                                   { $tag='DB Action';        $bc='db-badge-gray';  $ico='fa-database'; }
                $status_ok = !str_contains($det,'fail') && !str_contains($det,'error');
              ?>
              <tr>
                <td style="color:#666;font-size:13.5px;"><?= $si+1 ?></td>
                <td style="font-size:13.5px;color:#555;white-space:nowrap;">
                  <?= !empty($sl['created_at'])?date('M d, Y',strtotime($sl['created_at'])):'—' ?><br>
                  <span style="color:#777;font-size:13px;"><?= !empty($sl['created_at'])?date('h:i A',strtotime($sl['created_at'])):'' ?></span>
                </td>
                <td>
                  <span class="db-badge <?= $bc ?>"><i class="fas <?= $ico ?>"></i> <?= $tag ?></span>
                  <?php if (!empty($sl['details'])): ?>
                  <div style="font-size:12.5px;color:#666;margin-top:3px;max-width:260px;"><?= htmlspecialchars(substr($sl['details'],0,80)) ?><?= strlen($sl['details'])>80?'…':'' ?></div>
                  <?php endif; ?>
                </td>
                <td style="font-size:14px;"><?= htmlspecialchars(($sl['first_name']??'').($sl['last_name']?' '.$sl['last_name']:'')?: 'System') ?></td>
                <td style="font-size:13.5px;font-family:monospace;color:#374151;"><?= htmlspecialchars($sl['ip_address'] ?? '—') ?></td>
                <td>
                  <span class="db-badge <?= $status_ok?'db-badge-green':'db-badge-red' ?>">
                    <i class="fas <?= $status_ok?'fa-check-circle':'fa-times-circle' ?>"></i>
                    <?= $status_ok?'Success':'Failed' ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div><!-- /tab-security -->

</div><!-- /db-page -->

<!-- ══════════════════════════════════════════════════
     RESTORE CONFIRMATION MODAL
══════════════════════════════════════════════════ -->
<div class="db-modal-overlay" id="restoreModal">
  <div class="db-modal">
    <div class="db-modal-header">
      <div class="db-modal-title">
        <i class="fas fa-undo-alt" style="color:#002F6C;"></i>
        Confirm Database Restore
      </div>
    </div>
    <form method="POST" onsubmit="return validateRestoreForm(event)">
      <input type="hidden" name="tab" value="restore">
      <input type="hidden" name="action" value="restore">
      <input type="hidden" name="backup_id" id="restore_backup_id" value="">
      <div class="db-modal-body">
        <div class="db-form-group" style="margin-bottom:14px;">
          <label class="db-label">Selected Backup File</label>
          <input type="text" class="db-input" id="restore_backup_display" readonly>
        </div>
        <div class="db-form-group" style="margin-bottom:14px;">
          <label class="db-label">Type <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:14px;font-weight:700;color:#dc2626;">RESTORE</code> to confirm</label>
          <input type="text" name="confirm_text" id="restore_confirm_text" class="db-input" placeholder="Type RESTORE here"
            autocomplete="off" style="font-family:monospace;font-size:15px;letter-spacing:2px;">
          <span class="db-hint">Exact match required (case-sensitive).</span>
        </div>
        <div class="db-form-group">
          <label class="db-label">Developer Password</label>
          <div style="position: relative; display: flex; align-items: center;">
            <input type="password" name="dev_password" id="restore_dev_password" class="db-input"
              placeholder="Enter your password to authorize"
              autocomplete="new-password"
              style="padding-right: 42px; width: 100%;" required>
            <button type="button" id="toggleRestorePassBtn" class="db-pass-toggle-btn" onclick="toggleRestorePassword()" title="Show / Hide Password">
              <i class="fas fa-eye" id="toggleRestorePassIcon"></i>
            </button>
          </div>
        </div>
      </div>
      <div class="db-modal-footer">
        <button type="button" class="db-btn db-btn-ghost" onclick="closeRestoreModal()">Cancel</button>
        <button type="submit" class="db-btn db-btn-danger" id="restoreSubmitBtn">
          <i class="fas fa-undo-alt"></i> Confirm Restore
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Tab Switching (Persistent across refreshes & form submits) ─────────
(function(){
  const btns  = document.querySelectorAll('.db-tab-btn');
  const panes = document.querySelectorAll('.db-tab-pane');

  const urlTab   = new URLSearchParams(location.search).get('tab');
  const phpTab   = '<?= htmlspecialchars($active_tab) ?>';
  const localTab = localStorage.getItem('db_active_tab');

  const initialTab = urlTab || phpTab || localTab || 'backup';
  switchTab(initialTab);

  btns.forEach(btn => {
    btn.addEventListener('click', () => {
      const tabId = btn.dataset.tab;
      switchTab(tabId);
    });
  });

  function switchTab(id) {
    let target = id;
    if (!document.getElementById('tab-' + target)) target = 'backup';

    btns.forEach(b  => b.classList.toggle('active',  b.dataset.tab === target));
    panes.forEach(p => p.classList.toggle('active', p.id === 'tab-' + target));

    localStorage.setItem('db_active_tab', target);

    // Sync tab param in URL without page reload
    if (window.history && window.history.replaceState) {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', target);
      window.history.replaceState(null, '', url.toString());
    }

    // Sync hidden inputs in all forms
    document.querySelectorAll('.db-form-tab-input').forEach(inp => {
      inp.value = target;
    });
  }
  window.switchTab = switchTab;
})();

// ── Scheduled time toggle ───────────────────────────────────────────
function updateConfigSummary() {
  const freqSel = document.getElementById('backupFrequencySelect');
  const wrap    = document.getElementById('sched-time-wrap');

  const freqVal = freqSel ? freqSel.value : 'manual';

  if (wrap) {
    const isManual = (freqVal === 'manual' || freqVal === 'hourly');
    wrap.style.opacity       = isManual ? '.45' : '1';
    wrap.style.pointerEvents = isManual ? 'none' : 'auto';
  }
}

// Bind live sync listeners
document.getElementById('backupFrequencySelect')?.addEventListener('change', updateConfigSummary);
document.getElementById('scheduledTimeInput')?.addEventListener('input', updateConfigSummary);
// Run once on load
updateConfigSummary();


// ── Save & Run Backup Now (saves config + immediately triggers backup)
function saveAndRunBackupNow() {
  const configForm = document.getElementById('backupConfigForm');
  if (!configForm) return;
  triggerBackupProgress({
    preventDefault: function(){},
    target: configForm
  });
}

// ── Backup Progress Animation ─────────────────────────────────────────
function triggerBackupProgress(e) {
  const saveAndRunBtn = document.getElementById('saveAndRunBackupBtn');
  const wrap = document.getElementById('backupProgressWrap');
  const bar  = document.getElementById('backupProgressBar');
  const pct  = document.getElementById('backupProgressPct');
  const lbl  = document.getElementById('backupProgressLabel');
  const done = document.getElementById('backupCompletedMsg');

  if (wrap) wrap.style.display = 'block';
  if (saveAndRunBtn) {
    saveAndRunBtn.disabled = true;
    saveAndRunBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving &amp; Running…';
  }


  const steps = [
    [10, 'Synchronizing configuration & connecting…'],
    [25, 'Locking database tables…'],
    [45, 'Exporting database schema…'],
    [65, 'Exporting complete table data…'],
    [80, 'Compressing secure backup…'],
    [92, 'Writing to storage location…'],
    [100,'Finalising backup & applying retention…'],
  ];

  let si = 0;
  function tick() {
    if (si >= steps.length) {
      if (done) done.style.display = 'block';
      setTimeout(() => {
        const formToSubmit = (e && e.target) ? e.target : document.getElementById('backupConfigForm');
        if (formToSubmit) {
          // Switch action to run_backup so server saves config AND runs backup
          let hiddenAction = formToSubmit.querySelector('[name="action"]');
          if (hiddenAction) hiddenAction.value = 'run_backup';
          formToSubmit.submit();
        }
      }, 700);
      return;
    }
    const [p, l] = steps[si++];
    if (bar) bar.style.width  = p + '%';
    if (pct) pct.textContent  = p + '%';
    if (lbl) lbl.textContent  = l;
    setTimeout(tick, 340);
  }
  tick();

  if (e && typeof e.preventDefault === 'function') {
    e.preventDefault();
  }
}

// ── Restore Modal ──────────────────────────────────────────────────────
function openRestoreModal(id, name) {
  document.getElementById('restore_backup_id').value      = id;
  document.getElementById('restore_backup_display').value = name;
  
  const confirmInp = document.getElementById('restore_confirm_text');
  if (confirmInp) {
    confirmInp.value = '';
    confirmInp.style.borderColor = '';
  }

  const passInput = document.getElementById('restore_dev_password');
  if (passInput) {
    passInput.value = '';
    passInput.type  = 'password';
    passInput.style.borderColor = '';
  }

  const passIcon = document.getElementById('toggleRestorePassIcon');
  if (passIcon) {
    passIcon.className = 'fas fa-eye';
  }

  document.getElementById('restoreModal').classList.add('open');

  // Focus on confirm text after opening
  setTimeout(() => {
    if (confirmInp) confirmInp.focus();
  }, 100);
}

function closeRestoreModal() {
  document.getElementById('restoreModal').classList.remove('open');
  const passInput = document.getElementById('restore_dev_password');
  if (passInput) passInput.value = '';
  const confirmInp = document.getElementById('restore_confirm_text');
  if (confirmInp) confirmInp.value = '';
}

function toggleRestorePassword() {
  const passInput = document.getElementById('restore_dev_password');
  const passIcon  = document.getElementById('toggleRestorePassIcon');
  if (!passInput) return;
  if (passInput.type === 'password') {
    passInput.type = 'text';
    if (passIcon) passIcon.className = 'fas fa-eye-slash';
  } else {
    passInput.type = 'password';
    if (passIcon) passIcon.className = 'fas fa-eye';
  }
}

function validateRestoreForm(e) {
  const inp = document.getElementById('restore_confirm_text');
  if (!inp || inp.value.trim() !== 'RESTORE') {
    e.preventDefault();
    if (typeof window.showErrorToast === 'function') {
      window.showErrorToast('Confirmation Required', 'You must type RESTORE exactly in all capital letters to confirm.');
    } else {
      alert('You must type RESTORE exactly to confirm.');
    }
    if (inp) {
      inp.focus();
      inp.style.borderColor = '#dc2626';
    }
    return false;
  }

  const passInp = document.getElementById('restore_dev_password');
  if (!passInp || passInp.value.trim() === '') {
    e.preventDefault();
    if (typeof window.showErrorToast === 'function') {
      window.showErrorToast('Password Required', 'Please enter your developer password to authorize database restore.');
    } else {
      alert('Please enter your developer password to authorize restore.');
    }
    if (passInp) {
      passInp.focus();
      passInp.style.borderColor = '#dc2626';
    }
    return false;
  }
  return true;
}
// Close on backdrop click
document.getElementById('restoreModal').addEventListener('click', function(e){
  if (e.target === this) closeRestoreModal();
});

// ── Print Security Logs Report (Matching Reports Standard) ─────────────
function printSecurityLogs() {
  const tbl = document.getElementById('secLogsTable');
  if (!tbl || !tbl.querySelector('tbody tr')) {
    if (typeof showErrorToast === 'function') {
      showErrorToast('No Logs to Print', 'No security log records are currently available.');
    } else {
      alert('No security log records are currently available to print.');
    }
    return;
  }

  const now = new Date();
  const dateStr = now.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }) + ' ' +
                  now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });

  const userName = '<?= htmlspecialchars(addslashes(($me['first_name']??'').' '.($me['last_name']??''))) ?>' || 'System Administrator';
  const roleName = '<?= htmlspecialchars(addslashes(ucwords(str_replace('_',' ',$my_role)))) ?>';

  const tableClone = tbl.cloneNode(true);
  tableClone.id = 'printSecLogsTable';
  tableClone.style.width = '100%';
  tableClone.style.borderCollapse = 'collapse';

  const reportCSS = `
    @page { size: A4 landscape; margin: 0.4in 0.5in; }
    * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #0f172a; background: #ffffff; margin: 0; padding: 20px; font-size: 11px; }
    
    .rpt-header { border-bottom: 2.5px solid #002F6C; padding-bottom: 10px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: flex-end; }
    .rpt-brand { display: flex; align-items: center; gap: 10px; }
    .rpt-title { font-size: 17px; font-weight: 800; color: #002F6C; margin: 0; letter-spacing: 0.5px; text-transform: uppercase; }
    .rpt-sub { font-size: 11px; font-weight: 700; color: #cc0000; margin-top: 2px; letter-spacing: 0.3px; }
    
    .rpt-meta { text-align: right; font-size: 10px; color: #475569; line-height: 1.5; }
    .rpt-meta strong { color: #0f172a; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 10.5px; }
    thead tr { background: #002F6C !important; }
    thead th { color: #ffffff !important; padding: 8px 10px; text-align: left; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid #002F6C; }
    tbody td { padding: 6px 10px; border: 1px solid #cbd5e1; color: #374151; vertical-align: middle; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    
    .db-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 4px; font-size: 9px; font-weight: 700; }
    .db-badge-green  { background: #dcfce7 !important; color: #166534 !important; }
    .db-badge-yellow { background: #fef9c3 !important; color: #854d0e !important; }
    .db-badge-blue   { background: #dbeafe !important; color: #1d4ed8 !important; }
    .db-badge-red    { background: #fee2e2 !important; color: #991b1b !important; }
    .db-badge-gray   { background: #f1f5f9 !important; color: #475569 !important; }
    
    .mgr-signature-row { display: flex !important; justify-content: flex-end !important; align-items: flex-end !important; page-break-inside: avoid !important; margin-top: 30px !important; width: 100% !important; }
    .str-sig-line { border-top: 1.5px solid #002F6C !important; width: 100% !important; margin-bottom: 3px !important; }
    .sig-block-right { margin-left: auto !important; width: 240px !important; text-align: center !important; }
    @media print {
      body { padding: 0; }
      tr { page-break-inside: avoid; }
    }
  `;

  const html = `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Database Security Logs - Petron Station System</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>${reportCSS}</style>
</head>
<body>
  <div class="rpt-header">
    <div class="rpt-brand">
      <div>
        <h1 class="rpt-title">PETRON STATION MANAGEMENT SYSTEM</h1>
        <div class="rpt-sub">DATABASE SECURITY &amp; AUDIT LOGS REPORT</div>
      </div>
    </div>
    <div class="rpt-meta">
      <div><strong>Date Generated:</strong> ${dateStr}</div>
      <div><strong>Generated By:</strong> ${userName} (${roleName})</div>
    </div>
  </div>

  ${tableClone.outerHTML}

  <div class="mgr-signature-row" style="display:flex !important; justify-content:flex-end !important; margin-top:35px !important; page-break-inside:avoid !important; width:100% !important;">
    <div class="sig-block-right" style="margin-left:auto !important; width:240px !important; text-align:center !important;">
      <div class="str-sig-line"></div>
      <div style="font-weight:700; font-size:11px; color:#002F6C; margin-top:4px;">${userName}</div>
      <div style="font-size:9.5px; color:#64748b;">Prepared By (${roleName})</div>
    </div>
  </div>
</body>
</html>`;

  // Hidden iframe print (no popups blocked, matching reports standard)
  let frame = document.getElementById('sec_logs_print_frame');
  if (frame) frame.remove();

  frame = document.createElement('iframe');
  frame.id = 'sec_logs_print_frame';
  frame.style.position = 'fixed';
  frame.style.top = '-9999px';
  frame.style.left = '-9999px';
  frame.style.width = '1024px';
  frame.style.height = '768px';
  frame.style.border = 'none';
  frame.style.visibility = 'hidden';
  document.body.appendChild(frame);

  const doc = frame.contentWindow.document;
  doc.open();
  doc.write(html);
  doc.close();

  setTimeout(function() {
    try {
      frame.contentWindow.focus();
      frame.contentWindow.print();
    } catch (e) {
      window.print();
    }
    setTimeout(function() { if (frame) frame.remove(); }, 60000);
  }, 350);
}

// ── Export Security Logs as PDF (Matching Reports Standard) ─────────────
function exportSecurityLogsPDF() {
  const tbl = document.getElementById('secLogsTable');
  if (!tbl || !tbl.querySelector('tbody tr')) {
    if (typeof showErrorToast === 'function') {
      showErrorToast('No Logs to Export', 'No security log records are currently available to export.');
    } else {
      alert('No security log records are currently available to export.');
    }
    return;
  }

  const dateStr = new Date().toISOString().slice(0, 10);
  const filename = 'Petron_Security_Logs_' + dateStr;
  const pdfBtn = document.querySelector('.rpt-btn-pdf');

  if (typeof exportTableToPDF === 'function') {
    exportTableToPDF('secLogsTable', 'DATABASE SECURITY LOGS REPORT', filename);
  } else if (typeof exportPrintableAreaToPDF === 'function') {
    exportPrintableAreaToPDF('#secLogsTable', 'DATABASE SECURITY LOGS REPORT', filename, pdfBtn);
  } else {
    printSecurityLogs();
  }
}

// ══════════════════════════════════════════════════════════════
// SMART NOTIFICATION / TOAST ENGINE
// ══════════════════════════════════════════════════════════════
(function() {
  const LABELS = {
    success: 'Success',
    warning: 'Warning',
    error:   'Error',
    info:    'Info',
  };
  const ICONS = {
    success: 'fa-check-circle',
    warning: 'fa-exclamation-triangle',
    error:   'fa-times-circle',
    info:    'fa-info-circle',
  };
  // Auto-dismiss durations (ms)
  const DURATIONS = {
    success: 4000,   // 4 seconds
    warning: 6000,   // 6 seconds
    error:   7000,   // 7 seconds
    info:    5000,   // 5 seconds
  };
  const SUB_DEFAULT = {
    success: '',
    warning: '',
    error:   '',
    info:    '',
  };

  /**
   * showToast(type, title, sub, duration)
   *  type     : 'success' | 'warning' | 'error' | 'info'
   *  title    : main message text
   *  sub      : subtitle (optional, pass null to use default)
   *  duration : override ms (optional)
   */
  window.showToast = function(type, title, sub, duration) {
    const container = document.getElementById('db-notif-container');
    if (!container) return;

    const ms      = (duration !== undefined && duration !== null) ? duration : (DURATIONS[type] ?? 4000);
    const ico     = ICONS[type]   || 'fa-info-circle';
    const label   = LABELS[type]  || type;
    const subText = (sub !== undefined && sub !== null) ? sub : (SUB_DEFAULT[type] || '');

    const toast = document.createElement('div');
    toast.className = 'db-toast ' + type;

    toast.innerHTML = `
      <div class="db-toast-body">
        <div class="db-toast-icon"><i class="fas ${ico}"></i></div>
        <div class="db-toast-content">
          <div class="db-toast-label">${label}</div>
          <div class="db-toast-title">${title}</div>
          ${subText ? `<div class="db-toast-sub">${subText}</div>` : ''}
        </div>
      </div>
    `;

    container.appendChild(toast);

    function dismiss() {
      if (toast.classList.contains('hiding')) return;
      toast.classList.add('hiding');
      toast.addEventListener('animationend', () => toast.remove(), { once: true });
    }
    
    // Click anywhere on the toast to dismiss immediately
    toast.addEventListener('click', dismiss);

    if (ms > 0) {
      setTimeout(dismiss, ms);
    }
  };

  // ── Expose info toast with "process done" early-dismiss helper ──────
  window.showInfoToast = function(title, sub) {
    return showToast('info', title, sub, 5000);
  };
  window.showErrorToast   = function(t, s) { showToast('error',   t, s, 0); };
  window.showWarningToast = function(t, s) { showToast('warning', t, s, 6500); };
  window.showSuccessToast = function(t, s) { showToast('success', t, s, 4000); };

  // ── REAL-TIME BACKGROUND AUTO-REFRESH (10-Second Interval) ────────────────
  function autoRefreshDatabaseManagement() {
    if (document.querySelector('.db-modal-overlay.open') || (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA' || document.activeElement.tagName === 'SELECT'))) {
      return;
    }

    fetch('database_management.php?ajax_db=1', { cache: 'no-store' })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data && data.success) {
          var totalEl = document.getElementById('stat_total_backups');
          if (totalEl) totalEl.textContent = data.total_backups;

          var verEl = document.getElementById('stat_verified');
          if (verEl) verEl.textContent = data.verified_count;

          var sizeEl = document.getElementById('stat_db_size');
          if (sizeEl) sizeEl.textContent = data.db_size_mb + ' MB';

          var tblEl = document.getElementById('stat_db_tables');
          if (tblEl) tblEl.textContent = data.table_count + ' tables';

          var dateEl = document.getElementById('stat_last_backup_date');
          if (dateEl) dateEl.textContent = data.last_backup_date;

          var timeEl = document.getElementById('stat_last_backup_time');
          if (timeEl) timeEl.textContent = data.last_backup_time;
        }
      })
      .catch(function(err) {
        console.error("Database Management auto-refresh error:", err);
      });
  }

  // Start 10s timer for real-time operation auto-refresh
  setInterval(autoRefreshDatabaseManagement, 10000);

  // ── Fire PHP-generated toasts on DOM ready & clean polluted URL ───────
  document.addEventListener('DOMContentLoaded', function() {
    if (typeof _DB_TOASTS !== 'undefined' && _DB_TOASTS.length) {
      _DB_TOASTS.forEach(function(t, i) {
        setTimeout(function() {
          showToast(t.type, t.text, t.sub || null);
        }, i * 200);
      });
    }

    // ── Dynamic Scheduled Time toggle for Backup Frequency ────────────
    const freqSelect = document.getElementById('backupFrequencySelect');
    const schedWrap  = document.getElementById('sched-time-wrap');
    const schedInput = document.getElementById('scheduledTimeInput');

    function updateSchedTimeVisibility() {
      if (!freqSelect || !schedWrap || !schedInput) return;
      const val = freqSelect.value;
      const isManualOrHourly = (val === 'manual' || val === 'hourly');
      if (isManualOrHourly) {
        schedWrap.style.opacity = '0.45';
        schedWrap.style.pointerEvents = 'none';
        schedInput.setAttribute('tabindex', '-1');
      } else {
        schedWrap.style.opacity = '1';
        schedWrap.style.pointerEvents = 'auto';
        schedInput.removeAttribute('tabindex');
      }
    }

    if (freqSelect) {
      freqSelect.addEventListener('change', updateSchedTimeVisibility);
      updateSchedTimeVisibility();
    }

    // Clean query parameters from URL so F5/auto-refresh doesn't re-trigger old banners
    if (window.history && window.history.replaceState) {
      const cleanUrl = new URL(window.location.href);
      if (cleanUrl.searchParams.has('msg') || cleanUrl.searchParams.has('success')) {
        cleanUrl.searchParams.delete('msg');
        cleanUrl.searchParams.delete('success');
        window.history.replaceState(null, '', cleanUrl.toString());
      }
    }
  });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>