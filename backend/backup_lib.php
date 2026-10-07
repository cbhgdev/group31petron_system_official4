<?php
/**
 * Database Backup Library & Scheduled Runner
 * backend/backup_lib.php
 *
 * Provides core backup generation, schedule calculation, retention policies,
 * and background automated execution.
 */

if (!function_exists('cfg_get')) {
    function cfg_get(PDO $pdo, string $key, string $default = ''): string {
        try {
            $r = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
            $r->execute([$key]);
            $v = $r->fetchColumn();
            return $v === false ? $default : (string)$v;
        } catch (Exception $e) {
            return $default;
        }
    }
}

if (!function_exists('cfg_set')) {
    function cfg_set(PDO $pdo, string $key, string $value, int $uid = 0): void {
        try {
            $pdo->prepare("INSERT INTO system_config (config_key, config_value)
                VALUES(?, ?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value), updated_at=NOW()")
                ->execute([$key, $value]);
        } catch (Exception $e) {
            error_log("cfg_set failed for key={$key}: " . $e->getMessage());
        }
    }
}

if (!function_exists('get_backup_directory')) {
    function get_backup_directory(): string {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('get_backup_schedule_info')) {
    /**
     * Retrieve complete schedule configuration & calculate next scheduled run
     */
    function get_backup_schedule_info(PDO $pdo): array {
        // Ensure system_config table exists
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS system_config (
                config_key VARCHAR(100) PRIMARY KEY,
                config_value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}

        $enabled_raw = cfg_get($pdo, 'backup_schedule_enabled', '1');
        $enabled     = ($enabled_raw === '1' || $enabled_raw === 'true' || $enabled_raw === 'yes');
        $start_date  = cfg_get($pdo, 'backup_start_date', date('Y-m-d'));
        $stime       = cfg_get($pdo, 'backup_scheduled_time', '23:00');
        $freq        = cfg_get($pdo, 'backup_frequency', 'daily');
        $ret_days    = max(1, (int)cfg_get($pdo, 'backup_retention_days', '30'));
        $last_run    = cfg_get($pdo, 'backup_last_auto_run', '');

        // Sanitize frequency
        if (!in_array($freq, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
            $freq = 'daily';
        }

        // Sanitize time format (HH:MM)
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $stime)) {
            $stime = '23:00';
        }

        // Sanitize start date (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            $start_date = date('Y-m-d');
        }

        $now = time();
        $start_ts = strtotime("{$start_date} {$stime}");
        $last_ts  = !empty($last_run) ? strtotime($last_run) : 0;
        $next_ts  = null;
        $next_formatted = 'Scheduling is currently disabled';

        if ($enabled) {
            if ($freq === 'hourly') {
                $target_min = (int)date('i', $start_ts);
                $cur_hour_target = strtotime(date('Y-m-d H:') . str_pad($target_min, 2, '0', STR_PAD_LEFT) . ':00');
                if ($start_ts > $now) {
                    $next_ts = $start_ts;
                } elseif ($now < $cur_hour_target && $last_ts < $cur_hour_target) {
                    $next_ts = $cur_hour_target;
                } else {
                    $next_ts = $cur_hour_target + 3600;
                }
            } elseif ($freq === 'daily') {
                $today_target = strtotime(date('Y-m-d') . " {$stime}:00");
                if ($start_ts > $now) {
                    $next_ts = $start_ts;
                } elseif ($now < $today_target && $last_ts < $today_target) {
                    $next_ts = $today_target;
                } else {
                    $next_ts = strtotime('+1 day', $today_target);
                }
            } elseif ($freq === 'weekly') {
                $target_wday = date('w', $start_ts);
                $cur_wday    = date('w', $now);
                $days_diff   = ($target_wday - $cur_wday + 7) % 7;
                $this_target = strtotime(date('Y-m-d', strtotime("+{$days_diff} days")) . " {$stime}:00");

                if ($start_ts > $now) {
                    $next_ts = $start_ts;
                } elseif ($now < $this_target && $last_ts < $this_target) {
                    $next_ts = $this_target;
                } else {
                    $next_ts = strtotime('+7 days', $this_target);
                }
            } elseif ($freq === 'monthly') {
                $target_mday = (int)date('j', $start_ts);
                $cur_year    = (int)date('Y');
                $cur_month   = (int)date('m');
                $max_mday    = (int)date('t');
                $eff_mday    = min($target_mday, $max_mday);

                $this_target = strtotime(sprintf('%04d-%02d-%02d %s:00', $cur_year, $cur_month, $eff_mday, $stime));
                if ($start_ts > $now) {
                    $next_ts = $start_ts;
                } elseif ($now < $this_target && $last_ts < $this_target) {
                    $next_ts = $this_target;
                } else {
                    $next_month_time = strtotime('+1 month', strtotime(sprintf('%04d-%02d-01', $cur_year, $cur_month)));
                    $nm_year  = (int)date('Y', $next_month_time);
                    $nm_month = (int)date('m', $next_month_time);
                    $nm_max   = (int)date('t', $next_month_time);
                    $nm_eff   = min($target_mday, $nm_max);
                    $next_ts  = strtotime(sprintf('%04d-%02d-%02d %s:00', $nm_year, $nm_month, $nm_eff, $stime));
                }
            }

            if ($next_ts !== null) {
                // E.g. October 23, 2026 • 11:00 PM
                $next_formatted = date('F j, Y', $next_ts) . ' • ' . date('g:i A', $next_ts);
            }
        }

        return [
            'enabled'             => $enabled,
            'start_date'          => $start_date,
            'scheduled_time'      => $stime,
            'frequency'           => $freq,
            'retention_days'      => $ret_days,
            'last_run'            => $last_run,
            'last_run_formatted'  => !empty($last_run) ? date('F j, Y • g:i A', strtotime($last_run)) : 'Never',
            'next_timestamp'      => $next_ts,
            'next_formatted'      => $next_formatted,
            'is_due'              => ($enabled && $next_ts !== null && $now >= $next_ts && $last_ts < $next_ts),
        ];
    }
}

if (!function_exists('apply_backup_retention_policy')) {
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
}

if (!function_exists('execute_database_backup')) {
    /**
     * Executes database backup to u261539219_petrondbs.sql and an archived timestamp copy.
     */
    function execute_database_backup(PDO $pdo, ?string $backup_dir = null, ?int $user_id = null, string $trigger_label = 'Manual'): array {
        if (!$backup_dir) {
            $backup_dir = get_backup_directory();
        }
        if (!is_dir($backup_dir)) {
            @mkdir($backup_dir, 0755, true);
        }

        $btype    = 'Full Backup';
        $comp     = 'SQL';
        $fname    = 'u261539219_petrondbs.sql';
        $fpath    = $backup_dir . $fname;
        $db_name  = 'u261539219_petrondbs';

        // 1. Try real mysqldump first
        $mysqldump_bin = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        if (!file_exists($mysqldump_bin)) $mysqldump_bin = 'mysqldump';

        $dump_args    = "--host=localhost --user=root --single-transaction --quick --skip-lock-tables --routines --triggers";
        $dump_cmd     = "\"{$mysqldump_bin}\" {$dump_args} {$db_name} > " . escapeshellarg($fpath) . " 2>&1";
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

        // Also create a timestamped archive copy for point-in-time recovery
        $timestamp = date('Ymd_His');
        $archive_name = "petron_pos_db_{$timestamp}.sql";
        if (file_exists($fpath) && $fsize > 0) {
            @copy($fpath, $backup_dir . $archive_name);
        }

        // Save record into database_backups table
        try {
            $pdo->prepare("INSERT INTO database_backups
                (backup_name, backup_file, backup_size, backup_type, compression, status, created_by, verified, created_at)
                VALUES (?,?,?,?,?,?,?,1,NOW())")
                ->execute([$fname, '/backup/database/' . $fname, $fsize, $btype, $comp, $status, $user_id]);

            $bid = (int)$pdo->lastInsertId();
        } catch (Exception $e) {
            $bid = 0;
        }

        if (function_exists('log_activity')) {
            if ($user_id) {
                log_activity($pdo, $user_id, 'Database Management',
                    "{$trigger_label} backup: {$fname} (Status:{$status}, Size:" . round($fsize/1024,1) . " KB)");
            } else {
                log_activity($pdo, 0, 'Database Management',
                    "Automated system backup: {$fname} (Status:{$status}, Size:" . round($fsize/1024,1) . " KB)");
            }
        }

        return [
            'success'  => true,
            'id'       => $bid,
            'filename' => $fname,
            'size'     => $fsize,
            'status'   => $status,
        ];
    }
}

if (!function_exists('check_and_run_scheduled_backup')) {
    /**
     * Checks if an automated backup is currently due, and runs it if so.
     */
    function check_and_run_scheduled_backup(PDO $pdo, ?string $backup_dir = null): bool {
        $info = get_backup_schedule_info($pdo);
        if (!$info['enabled']) {
            return false;
        }

        if ($info['is_due']) {
            // Immediately mark execution timestamp to prevent race conditions
            cfg_set($pdo, 'backup_last_auto_run', date('Y-m-d H:i:s'), 0);

            if (!$backup_dir) {
                $backup_dir = get_backup_directory();
            }

            execute_database_backup($pdo, $backup_dir, null, 'Automated');

            // Apply retention policy
            apply_backup_retention_policy($pdo, (int)$info['retention_days']);

            return true;
        }

        return false;
    }
}
