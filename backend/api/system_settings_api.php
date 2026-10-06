<?php
/**
 * System Settings API - Final Developer Architecture
 * Supports: General, Regional, Appearance, Security, Notification, and Report Settings.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

require_login();
$me   = current_user();
$role = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? ''));

if (!in_array($role, ['superadmin', 'developer'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$raw_input  = file_get_contents('php://input');
$json_input = json_decode($raw_input, true) ?? [];

$action     = $_GET['action']     ?? $_POST['action']     ?? $json_input['action']     ?? '';
$station_id = intval($_GET['station_id'] ?? $_POST['station_id'] ?? $json_input['station_id'] ?? 0);

// Auto-migration: ensure required columns exist
try {
    $cols = $pdo->query("SHOW COLUMNS FROM system_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('station_id', $cols)) {
        $pdo->exec("ALTER TABLE system_settings ADD COLUMN station_id INT DEFAULT 0 AFTER id");
    }
    if (!in_array('category', $cols)) {
        $pdo->exec("ALTER TABLE system_settings ADD COLUMN category VARCHAR(50) DEFAULT 'general' AFTER setting_value");
    }
    $indexes = $pdo->query("SHOW INDEX FROM system_settings WHERE Key_name = 'idx_station_key'")->fetchAll();
    if (empty($indexes)) {
        try { $pdo->exec("ALTER TABLE system_settings ADD UNIQUE KEY idx_station_key (station_id, setting_key)"); } catch(Exception $e2) {}
    }
} catch (Exception $eMig) {
    error_log("system_settings_api migration: " . $eMig->getMessage());
}

function upsertSetting(PDO $pdo, string $key, string $value, string $category, int $station_id, int $updated_by): void {
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, category, station_id, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            category      = VALUES(category),
            updated_by    = VALUES(updated_by),
            updated_at    = NOW()
    ");
    $stmt->execute([$key, $value, $category, $station_id, $updated_by]);
}

try {
    switch ($action) {

        /* -------------------------------------------------------------------
           1. UPLOAD LOGO
        ------------------------------------------------------------------- */
        case 'upload_logo':
            if (!isset($_FILES['logo'])) {
                throw new Exception('No logo file uploaded');
            }
            $file    = $_FILES['logo'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'];
            if (!in_array($ext, $allowed_exts)) {
                throw new Exception('Invalid file extension. Only JPG, PNG, GIF, WebP, SVG, ICO allowed.');
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                throw new Exception('File size exceeds 5MB limit');
            }

            $upload_dir = __DIR__ . '/../../uploads/logos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = 'logo_' . ($station_id > 0 ? "station_{$station_id}_" : 'global_') . time() . '.' . $ext;
            $filepath = $upload_dir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new Exception('Failed to save uploaded file');
            }

            $relative_path = '../uploads/logos/' . $filename;
            upsertSetting($pdo, 'company_logo', $relative_path, 'general', $station_id, $me['id']);
            upsertSetting($pdo, 'logo', $relative_path, 'general', $station_id, $me['id']);
            upsertSetting($pdo, 'system_logo', $relative_path, 'general', $station_id, $me['id']);

            if ($station_id === 0) {
                // If global logo is updated, update all stations that had 'none' so the new global logo applies system-wide
                $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW(), updated_by = ? WHERE setting_key IN ('company_logo','logo','system_logo') AND setting_value = 'none'")->execute([$relative_path, $me['id']]);
            }

            echo json_encode(['success' => true, 'message' => 'Company logo uploaded successfully', 'logo_url' => $relative_path]);
            break;

        /* -------------------------------------------------------------------
           2. REMOVE LOGO
        ------------------------------------------------------------------- */
        case 'remove_logo':
            // Fetch stored logo path (may be keyed as 'company_logo', 'logo', or 'system_logo')
            $logoPath = null;
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key IN ('company_logo','logo','system_logo') AND station_id = ? AND setting_value IS NOT NULL AND setting_value != '' AND setting_value != 'none' LIMIT 1");
            $stmt->execute([$station_id]);
            $logoPath = $stmt->fetchColumn();

            if ($logoPath && strpos($logoPath, '../assets/') === false) {
                // Build absolute path — stored value like '../uploads/logos/logo_global_123.png'
                $abs_path = realpath(__DIR__ . '/../../' . ltrim(str_replace('../', '', $logoPath), '/'));
                if ($abs_path && file_exists($abs_path)) {
                    @unlink($abs_path);
                }
            }

            // Set all key variants explicitly to 'none'
            upsertSetting($pdo, 'company_logo', 'none', 'general', $station_id, $me['id']);
            upsertSetting($pdo, 'logo', 'none', 'general', $station_id, $me['id']);
            upsertSetting($pdo, 'system_logo', 'none', 'general', $station_id, $me['id']);

            // If global (station 0), ensure all station overrides are also updated to 'none' so all users across all stations see it removed
            if ($station_id === 0) {
                $pdo->prepare("UPDATE system_settings SET setting_value = 'none', updated_at = NOW(), updated_by = ? WHERE setting_key IN ('company_logo','logo','system_logo')")->execute([$me['id']]);
            }

            echo json_encode(['success' => true, 'message' => 'Company logo removed successfully', 'logo_url' => '']);
            break;

        /* -------------------------------------------------------------------
           3. SAVE ALL SYSTEM SETTINGS
        ------------------------------------------------------------------- */
        case 'save_all_settings':
            $settings = $json_input['settings'] ?? [];
            if (empty($settings)) {
                throw new Exception('No settings data provided');
            }

            $categoryMap = [
                'system_name'                  => 'general',
                'company_logo'                 => 'general',
                'system_version'               => 'general',
                'timezone'                     => 'regional',
                'date_format'                  => 'regional',
                'time_format'                  => 'regional',
                'currency_symbol'              => 'regional',
                'theme'                        => 'appearance',
                'system_accent_color'          => 'appearance',
                'sidebar_color'                => 'appearance',
                'color_sidebar'                => 'appearance',
                'nav_active_color'             => 'appearance',
                'color_primary'                => 'appearance',
                'color_button'                 => 'appearance',
                'sidebar_mode'                 => 'appearance',
                'dashboard_auto_refresh'       => 'appearance',
                'auto_refresh_interval'        => 'appearance',
                'session_timeout'              => 'security',
                'min_password_length'          => 'security',
                'max_login_attempts'           => 'security',
                'require_uppercase'            => 'security',
                'require_numbers'              => 'security',
                'require_special_chars'        => 'security',
                'banner_duration'              => 'notification',
                'enable_system_notifications'  => 'notification',
                'enable_error_notifications'   => 'notification',
                'default_paper_size'           => 'reports',
                'default_orientation'          => 'reports',
                'show_company_logo_reports'    => 'reports',
                'show_report_footer'           => 'reports',
                'maintenance_mode'             => 'maintenance',
                'system_status'                => 'maintenance',
                'last_system_update'           => 'maintenance',
                'maintenance_message'          => 'maintenance',
                'maintenance_end_time'         => 'maintenance',
                'maintenance_duration_minutes' => 'maintenance',
            ];

            foreach ($settings as $key => $value) {
                $category = $categoryMap[$key] ?? 'general';
                $valStr   = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
                if ($key === 'dashboard_auto_refresh' || $key === 'auto_refresh_interval') {
                    $sec = max(5, min(300, (int)$value));
                    $valStr = (string)$sec;
                    upsertSetting($pdo, 'dashboard_auto_refresh', $valStr, 'appearance', $station_id, $me['id']);
                    upsertSetting($pdo, 'auto_refresh_interval', $valStr, 'appearance', $station_id, $me['id']);
                    continue;
                }
                if ($key === 'system_status' && $valStr !== 'Maintenance') {
                    $valStr = 'Online';
                }
                if ($key === 'session_timeout') {
                    $valStr = (string)max(1, (int)$value);
                }
                if ($key === 'banner_duration') {
                    $valStr = (string)max(1, min(60, (int)$value));
                }
                if ($key === 'default_paper_size') {
                    $valStr = (strtoupper($valStr) === 'LETTER') ? 'Letter' : 'A4';
                }
                if ($key === 'default_orientation') {
                    $valStr = (strtolower($valStr) === 'landscape') ? 'Landscape' : 'Portrait';
                }
                if (in_array($key, ['enable_system_notifications', 'enable_error_notifications', 'show_company_logo_reports', 'show_report_footer'], true)) {
                    $valStr = ($valStr === '1' || $valStr === 1 || $valStr === true || $valStr === 'true') ? '1' : '0';
                }
                upsertSetting($pdo, $key, $valStr, $category, $station_id, $me['id']);
            }

            // Sync global security, notification, appearance, and report settings across all stations when updated globally
            if ($station_id === 0) {
                $globalSyncKeys = [
                    'dashboard_auto_refresh', 'auto_refresh_interval',
                    'session_timeout', 'min_password_length', 'max_login_attempts',
                    'require_uppercase', 'require_numbers', 'require_special_chars',
                    'banner_duration', 'enable_system_notifications', 'enable_error_notifications',
                    'default_paper_size', 'default_orientation', 'show_company_logo_reports', 'show_report_footer'
                ];
                foreach ($globalSyncKeys as $sk) {
                    if (isset($settings[$sk])) {
                        $sVal = is_bool($settings[$sk]) ? ($settings[$sk] ? '1' : '0') : (string)$settings[$sk];
                        if ($sk === 'dashboard_auto_refresh' || $sk === 'auto_refresh_interval') $sVal = (string)max(5, min(300, (int)$sVal));
                        if ($sk === 'session_timeout') $sVal = (string)max(1, (int)$sVal);
                        if ($sk === 'min_password_length') $sVal = (string)max(4, (int)$sVal);
                        if ($sk === 'max_login_attempts') $sVal = (string)max(1, (int)$sVal);
                        if ($sk === 'banner_duration') $sVal = (string)max(1, min(60, (int)$sVal));
                        if ($sk === 'default_paper_size') $sVal = (strtoupper($sVal) === 'LETTER') ? 'Letter' : 'A4';
                        if ($sk === 'default_orientation') $sVal = (strtolower($sVal) === 'landscape') ? 'Landscape' : 'Portrait';
                        if (in_array($sk, ['enable_system_notifications', 'enable_error_notifications', 'show_company_logo_reports', 'show_report_footer'], true)) {
                            $sVal = ($sVal === '1' || $sVal === 1 || $sVal === true || $sVal === 'true') ? '1' : '0';
                        }
                        $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW(), updated_by = ? WHERE setting_key = ?")->execute([$sVal, $me['id'], $sk]);
                    }
                }
            }

            // Sync company_logo → 'logo' and 'system_logo' keys (read by partials/header.php and reports)
            if (isset($settings['company_logo']) && $settings['company_logo'] !== '') {
                $logoVal = $settings['company_logo'];
                upsertSetting($pdo, 'logo', $logoVal, 'general', $station_id, $me['id']);
                upsertSetting($pdo, 'system_logo', $logoVal, 'general', $station_id, $me['id']);
                if ($station_id === 0 && $logoVal === 'none') {
                    $pdo->prepare("UPDATE system_settings SET setting_value = 'none', updated_at = NOW(), updated_by = ? WHERE setting_key IN ('company_logo','logo','system_logo')")->execute([$me['id']]);
                }
            }

            // Sync sidebar_color → 'color_sidebar' (read by partials/header.php for sidebar background)
            if (isset($settings['sidebar_color']) && $settings['sidebar_color'] !== '') {
                upsertSetting($pdo, 'color_sidebar', $settings['sidebar_color'], 'appearance', $station_id, $me['id']);
            }

            // Sync system_accent_color → 'color_primary' & 'color_button'
            if (isset($settings['system_accent_color']) && $settings['system_accent_color'] !== '') {
                upsertSetting($pdo, 'color_primary', $settings['system_accent_color'], 'appearance', $station_id, $me['id']);
                upsertSetting($pdo, 'color_button', $settings['system_accent_color'], 'appearance', $station_id, $me['id']);
            }

            echo json_encode(['success' => true, 'message' => 'System settings saved successfully']);
            break;

        /* -------------------------------------------------------------------
           4. GET ALL SYSTEM SETTINGS
        ------------------------------------------------------------------- */
        case 'get_settings':
            $all_settings = [];

            // Load global settings first
            $stmt0 = $pdo->prepare("SELECT setting_key, setting_value, category FROM system_settings WHERE station_id = 0");
            $stmt0->execute();
            foreach ($stmt0->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $all_settings[$r['setting_key']] = $r['setting_value'];
            }

            // Overlay station specific if applicable
            if ($station_id > 0) {
                $stmtS = $pdo->prepare("SELECT setting_key, setting_value, category FROM system_settings WHERE station_id = ?");
                $stmtS->execute([$station_id]);
                foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ($r['setting_value'] !== null && $r['setting_value'] !== '') {
                        $all_settings[$r['setting_key']] = $r['setting_value'];
                    }
                }
            }

            // System defaults
            $defaults = [
                'system_name'                  => 'Petron Station Management System',
                'company_logo'                 => '../assets/img/Petron Logo.png',
                'system_version'               => 'v1.0.0',
                'timezone'                     => 'Asia/Manila (UTC+8)',
                'date_format'                  => 'YYYY-MM-DD',
                'time_format'                  => '12H',
                'currency_symbol'              => 'PHP (₱)',
                'theme'                        => 'Light',
                'system_accent_color'          => '#002F6C',
                'sidebar_color'                => '#00264D',
                'color_sidebar'                => '#00264D',
                'nav_active_color'             => '#E30613',
                'sidebar_mode'                 => 'Expanded',
                'dashboard_auto_refresh'       => '10',
                'session_timeout'              => '30',
                'min_password_length'          => '8',
                'max_login_attempts'           => '5',
                'require_uppercase'            => '1',
                'require_numbers'              => '1',
                'require_special_chars'        => '1',
                'banner_duration'              => '5',
                'enable_system_notifications'  => '1',
                'enable_error_notifications'   => '1',
                'default_paper_size'           => 'A4',
                'default_orientation'          => 'Portrait',
                'show_company_logo_reports'    => '1',
                'show_report_footer'           => '1',
                'maintenance_mode'             => '0',
                'system_status'                => 'Online',
                'last_system_update'           => '2026-08-06 22:30:00',
            ];

            $result = array_merge($defaults, $all_settings);
            if (isset($result['company_logo']) && ($result['company_logo'] === 'none' || $result['company_logo'] === '')) {
                $result['company_logo'] = '';
                $result['logo'] = '';
            }

            echo json_encode(['success' => true, 'settings' => $result]);
            break;

        /* -------------------------------------------------------------------
           5. RESTORE DEFAULTS
        ------------------------------------------------------------------- */
        case 'restore_defaults':
            if ($station_id > 0) {
                $stmt = $pdo->prepare("DELETE FROM system_settings WHERE station_id = ?");
                $stmt->execute([$station_id]);
            } else {
                $pdo->exec("DELETE FROM system_settings WHERE station_id = 0");

                $defaults = [
                    ['system_name',                  'Petron Station Management System', 'general'],
                    ['company_logo',                 '../assets/img/Petron Logo.png', 'general'],
                    ['system_version',               'v1.0.0', 'general'],
                    ['timezone',                     'Asia/Manila (UTC+8)', 'regional'],
                    ['date_format',                  'YYYY-MM-DD', 'regional'],
                    ['time_format',                  '12H', 'regional'],
                    ['currency_symbol',              'PHP (₱)', 'regional'],
                    ['theme',                        'Light', 'appearance'],
                    ['system_accent_color',          '#002F6C', 'appearance'],
                    ['sidebar_mode',                 'Expanded', 'appearance'],
                    ['dashboard_auto_refresh',       '10', 'appearance'],
                    ['session_timeout',              '30', 'security'],
                    ['min_password_length',          '8', 'security'],
                    ['max_login_attempts',           '5', 'security'],
                    ['require_uppercase',            '1', 'security'],
                    ['require_numbers',              '1', 'security'],
                    ['require_special_chars',        '1', 'security'],
                    ['banner_duration',              '5', 'notification'],
                    ['enable_system_notifications',  '1', 'notification'],
                    ['enable_error_notifications',   '1', 'notification'],
                    ['default_paper_size',           'A4', 'reports'],
                    ['default_orientation',          'Portrait', 'reports'],
                    ['show_company_logo_reports',    '1', 'reports'],
                    ['show_report_footer',           '1', 'reports'],
                    ['maintenance_mode',             '0', 'maintenance'],
                    ['system_status',                'Online', 'maintenance'],
                    ['last_system_update',           '2026-08-06 22:30:00', 'maintenance'],
                    ['maintenance_message',          'The system is currently undergoing scheduled maintenance to improve performance and stability. Please check back shortly.', 'maintenance'],
                    ['maintenance_end_time',         '', 'maintenance'],
                    ['maintenance_duration_minutes', '60', 'maintenance'],
                ];

                foreach ($defaults as [$k, $v, $c]) {
                    upsertSetting($pdo, $k, $v, $c, 0, $me['id']);
                }
            }

            echo json_encode(['success' => true, 'message' => 'System settings restored to defaults successfully']);
            break;

        default:
            throw new Exception("Invalid action: '{$action}'");
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
