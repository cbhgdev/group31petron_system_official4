<?php
$page_id = 'system_settings';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me   = current_user();
$role = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? ''));

if (!in_array($role, ['superadmin', 'developer'])) {
    header('Location: super_admin_dashboard.php');
    exit;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT DEFAULT 0 COMMENT '0 for global, or specific station ID',
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            category VARCHAR(50) DEFAULT 'general',
            updated_by INT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_station_key (station_id, setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log("System Settings table creation failed: " . $e->getMessage());
}

// Migration: add missing columns to existing tables
try {
    $cols = $pdo->query("SHOW COLUMNS FROM system_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('station_id', $cols)) {
        $pdo->exec("ALTER TABLE system_settings ADD COLUMN station_id INT DEFAULT 0 COMMENT '0 for global' AFTER id");
    }
    if (!in_array('category', $cols)) {
        $pdo->exec("ALTER TABLE system_settings ADD COLUMN category VARCHAR(50) DEFAULT 'general' AFTER setting_value");
    }
    // Rebuild unique key if station_id was just added
    $indexes = $pdo->query("SHOW INDEX FROM system_settings WHERE Key_name = 'idx_station_key'")->fetchAll();
    if (empty($indexes)) {
        try { $pdo->exec("ALTER TABLE system_settings ADD UNIQUE KEY idx_station_key (station_id, setting_key)"); } catch(Exception $e2) {}
    }
} catch (Exception $e) {
    error_log("System Settings migration failed: " . $e->getMessage());
}

$stations = [];
try {
    $stmt = $pdo->query("SELECT id, name, address, status FROM stations ORDER BY name ASC");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stations = [];
}

// ── AJAX JSON POLLING ENDPOINT FOR SUPERADMIN SYSTEM SETTINGS ────────────────
if (isset($_GET['ajax_sss']) && $_GET['ajax_sss'] == '1') {
    header('Content-Type: application/json');
    $stId = (int)($_GET['station_id'] ?? 0);
    $settingCount = 0;
    try {
        $stStmt = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE station_id = ?");
        $stStmt->execute([$stId]);
        $settingCount = (int)$stStmt->fetchColumn();
    } catch (Exception $e) {}

    echo json_encode([
        'success'        => true,
        'stations_count' => count($stations ?? []),
        'settings_count' => $settingCount,
        'current_station'=> $stId
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
?>
<style>
:root {
    --primary-color: #002F6C;
    --surface: #ffffff;
    --page-bg: #f4f6fb;
    --text-primary: #1f2937;
    --text-secondary: #6b7280;
    --border-color: #e5e7eb;
    --radius-card: 14px;
    --shadow-card: 0 2px 12px rgba(0,0,0,0.05);
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

/* ── Logo button overrides — Elder Friendly ── */
#btn_upload_logo,
#btn_upload_logo:link,
#btn_upload_logo:visited {
    background: transparent !important;
    background-color: transparent !important;
    color: #002F6C !important;
    border: 2px solid #002F6C !important;
    padding: 10px 20px !important;
    border-radius: 8px !important;
    font-size: 14.5px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    white-space: nowrap !important;
    flex-shrink: 0 !important;
    box-shadow: none !important;
    text-decoration: none !important;
}
#btn_upload_logo:hover {
    background: rgba(0,47,108,0.07) !important;
}

#btn_remove_logo,
#btn_remove_logo:link,
#btn_remove_logo:visited {
    background: transparent !important;
    background-color: transparent !important;
    color: #dc2626 !important;
    border: 2px solid #dc2626 !important;
    padding: 8px 16px !important;
    border-radius: 8px !important;
    font-size: 13.5px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    box-shadow: none !important;
    text-decoration: none !important;
}
#btn_remove_logo:hover {
    background: rgba(220,38,38,0.07) !important;
}

.ss-wrapper {
    display: block;
    min-height: calc(100vh - 120px);
    background: var(--page-bg);
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
    box-sizing: border-box !important;
}

.ss-content {
    padding: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
    box-sizing: border-box !important;
}

.ss-panel-header {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin-top: 0 !important;
    margin-bottom: 25px !important;
    padding: 0 !important;
    border: none !important;
    width: 100%;
}

.ss-panel-header h1 {
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

.ss-panel-header p {
    color: #666 !important;
    font-size: 15px !important;
    margin: 4px 0 0 !important;
}

.ss-card {
    background: #ffffff !important;
    border-radius: 14px !important;
    box-shadow: 0 2px 12px rgba(0,0,0,0.05) !important;
    padding: 24px 28px !important;
    margin-bottom: 24px !important;
    border: 1px solid #eaeaea !important;
}

.ss-card-title {
    font-size: 18px !important;
    font-weight: 700 !important;
    color: #002f70 !important;
    margin: 0 0 20px !important;
    padding-bottom: 12px !important;
    border-bottom: 2px solid #f1f5f9 !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
}

.ss-card-title i {
    color: #002f70 !important;
    font-size: 18px !important;
}

.ss-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.ss-grid-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 20px;
}

.ss-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.ss-form-group {
    margin-bottom: 18px;
}

.ss-form-group label {
    display: block;
    font-size: 14px !important;
    font-weight: 600 !important;
    color: #444 !important;
    margin-bottom: 6px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
}

.ss-form-control {
    width: 100% !important;
    padding: 12px 14px !important;
    border: 1.5px solid #ddd !important;
    border-radius: 10px !important;
    font-size: 15px !important;
    color: #1a1a1a !important;
    background: #ffffff !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
    box-sizing: border-box !important;
    font-family: inherit !important;
}

.ss-form-control:focus {
    outline: none !important;
    border-color: #002F6C !important;
    box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1) !important;
}

.ss-toggle-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px !important;
    border: 1.5px solid #e2e8f0 !important;
    border-radius: 10px !important;
    background: #f9fafb;
}

.ss-toggle-label {
    font-size: 14.5px !important;
    font-weight: 600 !important;
    color: #374151 !important;
}

.ss-switch {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 26px;
}

.ss-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.ss-slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #cbd5e1;
    transition: .2s;
    border-radius: 26px;
}

.ss-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .2s;
    border-radius: 50%;
}

input:checked + .ss-slider {
    background-color: #16a34a;
}

input:checked + .ss-slider:before {
    transform: translateX(22px);
}

/* Action Footer Bar at bottom of data */
.ss-action-bar {
    position: static !important;
    margin-top: 25px !important;
    margin-bottom: 25px !important;
    padding: 10px 0 !important;
    display: flex !important;
    justify-content: flex-end !important;
    align-items: center !important;
    gap: 12px !important;
    background: transparent !important;
    background-color: transparent !important;
    border-top: none !important;
    border: none !important;
    box-shadow: none !important;
    z-index: 10 !important;
    pointer-events: auto !important;
}
.ss-action-bar button,
.ss-action-bar .ss-btn {
    pointer-events: auto !important;
}

.ss-btn {
    padding: 12px 24px !important;
    border-radius: 8px !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    background: transparent !important;
    background-color: transparent !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    transition: all 0.18s ease !important;
    letter-spacing: 0.3px !important;
    white-space: nowrap !important;
    text-decoration: none !important;
    line-height: 1.3 !important;
}

.ss-btn-primary {
    color: #002F6C !important;
    border: 2px solid #002F6C !important;
    background: transparent !important;
    background-color: transparent !important;
}

.ss-btn-primary:hover {
    background: rgba(0, 47, 108, 0.08) !important;
    color: #002F6C !important;
}

.ss-btn-secondary {
    color: #dc2626 !important;
    border: 2px solid #dc2626 !important;
    background: transparent !important;
    background-color: transparent !important;
}

.ss-btn-secondary:hover {
    background: rgba(220, 38, 38, 0.08) !important;
    color: #dc2626 !important;
}

.ss-btn-light {
    color: #374151 !important;
    border: 2px solid #9ca3af !important;
    background: transparent !important;
    background-color: transparent !important;
}

.ss-btn-light:hover {
    background: rgba(156, 163, 175, 0.10) !important;
    color: #374151 !important;
}

/* Custom Virtual Station Combobox - Elder Friendly 15px */
.am-combo { position: relative; }
.am-combo-input {
    width: 100% !important;
    padding: 12px 14px !important;
    border: 1.5px solid #ddd !important;
    border-radius: 10px !important;
    font-size: 15px !important;
    background: #fff !important;
    color: #1a1a1a !important;
    box-sizing: border-box !important;
    outline: none !important;
}
.am-combo-input:focus {
    border-color: #002F6C !important;
    box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1) !important;
}
.am-combo-arrow {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    pointer-events: none;
    font-size: 13px !important;
}
.am-combo-clear {
    position: absolute;
    right: 34px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    padding: 4px;
    display: none;
}
.am-combo-dropdown {
    position: absolute;
    top: 100%;
    left: 0; right: 0;
    margin-top: 4px;
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.12);
    z-index: 9999;
    display: none;
    max-height: 280px;
    overflow-y: auto;
}
.am-combo-item {
    padding: 12px 16px !important;
    font-size: 14.5px !important;
    cursor: pointer;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
}
.am-combo-item:hover, .am-combo-item.selected {
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 600;
}
</style>

<div class="ss-wrapper">
    <div class="ss-content">

        <!-- Panel Header -->
        <div class="ss-panel-header">
            <h1><i class="fas fa-sliders-h" style="margin-right:8px;"></i>System Settings</h1>
        </div>

        <!-- Station Selection -->
        <div class="ss-card">
            <div class="ss-card-title">
                <i class="fas fa-map-marker-alt"></i> Station Selection
            </div>
            <div style="display: flex; align-items: center; gap: 15px;">
                <label style="font-weight: 700; color: #374151; font-size: 14px; text-transform: uppercase; letter-spacing: 0.3px;">
                    Scope Settings For:
                </label>
                <div class="am-combo" id="ss_station_combo" style="width: 420px;">
                    <input type="text" class="am-combo-input" id="ss_station_display"
                           placeholder="Type to search stations..." autocomplete="off">
                    <button type="button" class="am-combo-clear" id="ss_station_clear" tabindex="-1" title="Clear">
                        <i class="fas fa-times"></i>
                    </button>
                    <i class="fas fa-chevron-down am-combo-arrow"></i>
                    <input type="hidden" id="ss_station_val" value="0">
                    <div class="am-combo-dropdown" id="ss_station_dropdown">
                        <div class="am-combo-list" id="ss_station_list"></div>
                    </div>
                </div>
            </div>
        </div>

        <form id="systemSettingsForm" onsubmit="return false;">

            <!-- General Settings -->
            <div class="ss-card" id="section_general">
                <div class="ss-card-title">
                    <i class="fas fa-sliders-h"></i> General Settings
                </div>
                <div class="ss-grid-3">
                    <div class="ss-form-group">
                        <label for="ss_system_name">System Name</label>
                        <input type="text" id="ss_system_name" class="ss-form-control"
                               value="Petron Station Management System" placeholder="Enter System Name">
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_logo_input">Company Logo</label>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <input type="file" id="ss_logo_input" accept="image/*" class="ss-form-control" style="padding:6px 10px;">
                            <button type="button" id="btn_upload_logo" onclick="uploadLogo()"><i class="fas fa-upload"></i> Upload</button>
                        </div>
                        <div id="logo_preview_container" style="margin-top:8px; display:flex; align-items:center; gap:12px;">
                            <img id="logo_preview_img" src="" alt="Company Logo" style="height:36px; border-radius:4px; border:1px solid #e2e8f0; padding:2px; background:#fff; display:none;">
                            <span id="no_logo_placeholder" style="font-size:13.5px; color:#64748b; font-style:italic;">No custom logo uploaded (Default/Removed)</span>
                            <button type="button" id="btn_remove_logo" onclick="removeLogo()" style="display:none;"><i class="fas fa-trash-alt"></i> Remove Logo</button>
                        </div>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_system_version">System Version</label>
                        <input type="text" id="ss_system_version" class="ss-form-control"
                               value="v1.0.0" readonly style="background:#f8fafc; font-family:monospace;">
                    </div>
                </div>
            </div>

            <!-- Regional Settings -->
            <div class="ss-card" id="section_regional">
                <div class="ss-card-title">
                    <i class="fas fa-globe"></i> Regional Settings
                </div>
                <div class="ss-grid-2">
                    <div class="ss-form-group">
                        <label for="ss_timezone"><i class="fas fa-map-marked-alt" style="color:#002F6C; margin-right:4px;"></i> Timezone</label>
                        <select id="ss_timezone" class="ss-form-control" onchange="onRegionalSettingChange()">
                            <option value="Asia/Manila (UTC+8)">Asia/Manila (UTC+8)</option>
                            <option value="UTC">UTC</option>
                            <option value="Asia/Singapore (UTC+8)">Asia/Singapore (UTC+8)</option>
                            <option value="America/New_York (UTC-5)">America/New_York (UTC-5)</option>
                            <option value="Europe/London (UTC+0)">Europe/London (UTC+0)</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_date_format"><i class="fas fa-calendar-alt" style="color:#002F6C; margin-right:4px;"></i> Date Format</label>
                        <select id="ss_date_format" class="ss-form-control" onchange="onRegionalSettingChange()">
                            <option value="YYYY-MM-DD">YYYY-MM-DD</option>
                            <option value="MM/DD/YYYY">MM/DD/YYYY</option>
                            <option value="DD/MM/YYYY">DD/MM/YYYY</option>
                            <option value="MMM DD, YYYY">MMM DD, YYYY</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_time_format"><i class="fas fa-clock" style="color:#002F6C; margin-right:4px;"></i> Time Format</label>
                        <select id="ss_time_format" class="ss-form-control" onchange="onRegionalSettingChange()">
                            <option value="12H">12H (12-Hour)</option>
                            <option value="24H">24H (24-Hour)</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_currency_symbol"><i class="fas fa-coins" style="color:#002F6C; margin-right:4px;"></i> Currency Symbol</label>
                        <select id="ss_currency_symbol" class="ss-form-control" onchange="onRegionalSettingChange()">
                            <option value="PHP (₱)">PHP (₱)</option>
                            <option value="USD ($)">USD ($)</option>
                            <option value="EUR (€)">EUR (€)</option>
                            <option value="JPY (¥)">JPY (¥)</option>
                            <option value="GBP (£)">GBP (£)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Appearance & Navigation Customization -->
            <div class="ss-card" id="section_appearance">
                <div class="ss-card-title">
                    <i class="fas fa-paint-brush"></i> Appearance &amp; Navigation Themes
                </div>

                <!-- Color Palettes / Presets -->
                <div style="margin-bottom: 22px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 14px 18px;">
                    <div style="font-size: 13.5px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-palette" style="color: #002F6C;"></i> Quick Color Presets (Click to preview &amp; apply):
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;" id="appearancePresets">
                        <button type="button" class="btn" onclick="applyColorPreset('#00264D', '#E30613', '#002F6C')" style="background:#fff !important; color:#1e293b !important; border:1.5px solid #cbd5e1 !important; border-radius:8px !important; padding:8px 14px !important; font-size:13.5px !important; font-weight:600 !important; cursor:pointer !important; display:inline-flex !important; align-items:center !important; gap:8px !important; box-shadow:0 1px 3px rgba(0,0,0,0.05) !important;">
                            <span style="width:16px; height:16px; border-radius:50%; background:#00264D; display:inline-block; border:1px solid #999;"></span>
                            Petron Classic (Navy / Red)
                        </button>
                        <button type="button" class="btn" onclick="applyColorPreset('#006b1b', '#16a34a', '#006b1b')" style="background:#fff !important; color:#1e293b !important; border:1.5px solid #cbd5e1 !important; border-radius:8px !important; padding:8px 14px !important; font-size:13.5px !important; font-weight:600 !important; cursor:pointer !important; display:inline-flex !important; align-items:center !important; gap:8px !important; box-shadow:0 1px 3px rgba(0,0,0,0.05) !important;">
                            <span style="width:16px; height:16px; border-radius:50%; background:#006b1b; display:inline-block; border:1px solid #999;"></span>
                            Forest Green (Emerald)
                        </button>
                        <button type="button" class="btn" onclick="applyColorPreset('#1e293b', '#2563eb', '#1d4ed8')" style="background:#fff !important; color:#1e293b !important; border:1.5px solid #cbd5e1 !important; border-radius:8px !important; padding:8px 14px !important; font-size:13.5px !important; font-weight:600 !important; cursor:pointer !important; display:inline-flex !important; align-items:center !important; gap:8px !important; box-shadow:0 1px 3px rgba(0,0,0,0.05) !important;">
                            <span style="width:16px; height:16px; border-radius:50%; background:#1e293b; display:inline-block; border:1px solid #999;"></span>
                            Slate Charcoal (Blue)
                        </button>
                        <button type="button" class="btn" onclick="applyColorPreset('#1e1b4b', '#7c3aed', '#6366f1')" style="background:#fff !important; color:#1e293b !important; border:1.5px solid #cbd5e1 !important; border-radius:8px !important; padding:8px 14px !important; font-size:13.5px !important; font-weight:600 !important; cursor:pointer !important; display:inline-flex !important; align-items:center !important; gap:8px !important; box-shadow:0 1px 3px rgba(0,0,0,0.05) !important;">
                            <span style="width:16px; height:16px; border-radius:50%; background:#1e1b4b; display:inline-block; border:1px solid #999;"></span>
                            Royal Indigo (Purple)
                        </button>
                    </div>
                </div>

                <!-- Row 1: Navigation Color Customization -->
                <div class="ss-grid-3">
                    <div class="ss-form-group">
                        <label for="ss_sidebar_color">Sidebar Navigation Color</label>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <input type="color" id="ss_sidebar_color" value="#00264D" style="padding:2px 4px; height:46px; width:54px; cursor:pointer; border:1.5px solid #cbd5e1; border-radius:8px; background:#fff;" oninput="onSidebarColorChange(this.value)">
                            <input type="text" id="ss_sidebar_color_hex" class="ss-form-control" value="#00264D" readonly style="background:#f8fafc; font-family:monospace; text-align:center; font-weight:700; font-size:15px;">
                        </div>
                        <small style="color:#64748b; font-size:12.5px; margin-top:5px; display:block;">Background color of the sidebar navigation bar.</small>
                    </div>

                    <div class="ss-form-group">
                        <label for="ss_nav_active_color">Active Navigation Item Color</label>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <input type="color" id="ss_nav_active_color" value="#E30613" style="padding:2px 4px; height:46px; width:54px; cursor:pointer; border:1.5px solid #cbd5e1; border-radius:8px; background:#fff;" oninput="onNavActiveColorChange(this.value)">
                            <input type="text" id="ss_nav_active_color_hex" class="ss-form-control" value="#E30613" readonly style="background:#f8fafc; font-family:monospace; text-align:center; font-weight:700; font-size:15px;">
                        </div>
                        <small style="color:#64748b; font-size:12.5px; margin-top:5px; display:block;">Highlight pill color for the active menu page.</small>
                    </div>

                    <div class="ss-form-group">
                        <label for="ss_accent_color">System Accent Color</label>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <input type="color" id="ss_accent_color" value="#002F6C" style="padding:2px 4px; height:46px; width:54px; cursor:pointer; border:1.5px solid #cbd5e1; border-radius:8px; background:#fff;" oninput="onAccentColorChange(this.value)">
                            <input type="text" id="ss_accent_color_hex" class="ss-form-control" value="#002F6C" readonly style="background:#f8fafc; font-family:monospace; text-align:center; font-weight:700; font-size:15px;">
                        </div>
                        <small style="color:#64748b; font-size:12.5px; margin-top:5px; display:block;">Primary buttons, badges, and system headers.</small>
                    </div>
                </div>

                <!-- Row 2: Layout, Theme & Refresh -->
                <div class="ss-grid-3" style="margin-top:10px;">
                    <div class="ss-form-group">
                        <label for="ss_theme">Theme</label>
                        <select id="ss_theme" class="ss-form-control" onchange="onThemeChange(this.value)">
                            <option value="Light">Light</option>
                            <option value="Dark">Dark</option>
                        </select>
                        <small style="color:#64748b; font-size:12.5px; margin-top:5px; display:block;">Toggle between Light and Dark interface modes.</small>
                    </div>

                    <div class="ss-form-group">
                        <label for="ss_sidebar_mode">Sidebar Mode</label>
                        <select id="ss_sidebar_mode" class="ss-form-control" onchange="onSidebarModeChange(this.value)">
                            <option value="Expanded">Expanded</option>
                            <option value="Collapsed">Collapsed</option>
                        </select>
                        <small style="color:#64748b; font-size:12.5px; margin-top:5px; display:block;">Default state of the navigation menu on load.</small>
                    </div>

                    <div class="ss-form-group">
                        <label for="ss_dashboard_auto_refresh">Auto Refresh Interval (seconds)</label>
                        <input type="number" id="ss_dashboard_auto_refresh" class="ss-form-control" value="10" min="5" max="300" title="Auto refresh interval in seconds (default 10s)">
                        <small style="color:#64748b; font-size:12.5px; margin-top:5px; display:block;">Real-time background data sync rate (5s - 300s).</small>
                    </div>
                </div>
            </div>

            <!-- Security -->
            <div class="ss-card" id="section_security">
                <div class="ss-card-title">
                    <i class="fas fa-shield-alt"></i> Security
                </div>
                <div class="ss-grid-3" style="margin-bottom:16px;">
                    <div class="ss-form-group">
                        <label for="ss_session_timeout">Session Timeout (minutes) <small style="color:#64748b; font-weight:400;">(min: 1)</small></label>
                        <input type="number" id="ss_session_timeout" class="ss-form-control" value="30" min="1" max="1440" title="Global session timeout in minutes (min: 1). Inactive users across all roles and pages will be automatically logged out after this duration.">
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_min_password_length">Minimum Password Length</label>
                        <input type="number" id="ss_min_password_length" class="ss-form-control" value="8" min="4" max="64">
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_max_login_attempts">Maximum Login Attempts</label>
                        <input type="number" id="ss_max_login_attempts" class="ss-form-control" value="5" min="3" max="20">
                    </div>
                </div>
                <div class="ss-grid-3">
                    <div class="ss-toggle-wrapper">
                        <span class="ss-toggle-label">Require Uppercase</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_require_uppercase" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                    <div class="ss-toggle-wrapper">
                        <span class="ss-toggle-label">Require Numbers</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_require_numbers" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                    <div class="ss-toggle-wrapper">
                        <span class="ss-toggle-label">Require Special Characters</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_require_special_chars" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Notification Settings -->
            <div class="ss-card" id="section_notification">
                <div class="ss-card-title">
                    <i class="fas fa-bell"></i> Notification Settings
                </div>
                <div class="ss-grid-3">
                    <div class="ss-form-group">
                        <label for="ss_banner_duration">Success Banner Duration (seconds)</label>
                        <input type="number" id="ss_banner_duration" name="banner_duration" class="ss-form-control" value="5" min="1" max="30">
                    </div>
                    <div class="ss-toggle-wrapper" style="align-self:end; margin-bottom:16px;">
                        <span class="ss-toggle-label">Enable System Notifications</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_enable_system_notifications" name="enable_system_notifications" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                    <div class="ss-toggle-wrapper" style="align-self:end; margin-bottom:16px;">
                        <span class="ss-toggle-label">Enable Error Notifications</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_enable_error_notifications" name="enable_error_notifications" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Report Settings -->
            <div class="ss-card" id="section_reports">
                <div class="ss-card-title">
                    <i class="fas fa-file-alt"></i> Report Settings
                </div>
                <div class="ss-grid-2" style="margin-bottom:16px;">
                    <div class="ss-form-group">
                        <label for="ss_default_paper_size">Default Paper Size</label>
                        <select id="ss_default_paper_size" name="default_paper_size" class="ss-form-control">
                            <option value="A4">A4</option>
                            <option value="Letter">Letter</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_default_orientation">Default Orientation</label>
                        <select id="ss_default_orientation" name="default_orientation" class="ss-form-control">
                            <option value="Portrait">Portrait</option>
                            <option value="Landscape">Landscape</option>
                        </select>
                    </div>
                </div>
                <div class="ss-grid-2">
                    <div class="ss-toggle-wrapper">
                        <span class="ss-toggle-label">Show Company Logo on Reports</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_show_company_logo_reports" name="show_company_logo_reports" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                    <div class="ss-toggle-wrapper">
                        <span class="ss-toggle-label">Show Report Footer</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_show_report_footer" name="show_report_footer" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

                        <!-- Maintenance Settings (Enhanced with Timer & Message) -->
            <div class="ss-card" id="section_maintenance">
                <div class="ss-card-title">
                    <i class="fas fa-tools"></i> Maintenance Settings
                </div>
                
                <div class="ss-grid-3" style="margin-bottom: 16px;">
                    <div class="ss-toggle-wrapper" style="align-self:center;">
                        <span class="ss-toggle-label">Maintenance Mode</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_maintenance_mode" onchange="onMaintenanceModeChange(this.checked)">
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_system_status">System Status</label>
                        <select id="ss_system_status" class="ss-form-control" onchange="onSystemStatusChange(this.value)">
                            <option value="Online" style="color:#16a34a; font-weight:700;">Online</option>
                            <option value="Maintenance" style="color:#d97706; font-weight:700;">Maintenance</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_last_system_update">Last System Update</label>
                        <input type="text" id="ss_last_system_update" class="ss-form-control" value="2026-08-06 22:30:00" readonly style="background:#f8fafc; font-family:monospace;">
                    </div>
                </div>

                <!-- Timer & Expected End Time -->
                <div class="ss-form-group" style="margin-bottom: 16px;">
                    <label for="ss_maintenance_end_time" style="display:flex; justify-content:space-between; align-items:center;">
                        <span><i class="fas fa-clock" style="color:#002F6C; margin-right:4px;"></i> Estimated Maintenance Completion Time (Timer)</span>
                        <span id="maintTimerBadge" style="font-size:13px; font-weight:700; color:#d97706; text-transform:none;">Set target date & time or use quick presets below</span>
                    </label>
                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <input type="datetime-local" id="ss_maintenance_end_time" class="ss-form-control" style="width:260px;" onchange="updateMaintenanceTimerPreview()">
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <button type="button" class="ss-btn ss-btn-light" style="padding:8px 14px; font-size:13.5px; font-weight:600;" onclick="addMaintenanceMinutes(15)">+15 Mins</button>
                            <button type="button" class="ss-btn ss-btn-light" style="padding:8px 14px; font-size:13.5px; font-weight:600;" onclick="addMaintenanceMinutes(30)">+30 Mins</button>
                            <button type="button" class="ss-btn ss-btn-light" style="padding:8px 14px; font-size:13.5px; font-weight:600;" onclick="addMaintenanceMinutes(60)">+1 Hour</button>
                            <button type="button" class="ss-btn ss-btn-light" style="padding:8px 14px; font-size:13.5px; font-weight:600;" onclick="addMaintenanceMinutes(120)">+2 Hours</button>
                            <button type="button" class="ss-btn ss-btn-light" style="padding:8px 14px; font-size:13.5px; font-weight:600;" onclick="addMaintenanceMinutes(240)">+4 Hours</button>
                            <button type="button" class="ss-btn ss-btn-light" style="padding:8px 14px; font-size:13.5px; font-weight:600; color:#dc2626;" onclick="clearMaintenanceTimer()">Clear Timer</button>
                        </div>
                    </div>

                    <!-- Live Countdown Preview Box -->
                    <div id="maintTimerPreviewBox" style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:12px 16px; margin-top:10px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <i class="fas fa-stopwatch" style="font-size:22px; color:#d97706;"></i>
                            <div>
                                <div style="font-size:12.5px; font-weight:700; color:#b45309; text-transform:uppercase; letter-spacing:0.4px;">Live Countdown Timer Preview</div>
                                <div id="maintCountdownPreview" style="font-size:17px; font-weight:800; color:#92400e; font-family:monospace;">No timer configured</div>
                            </div>
                        </div>
                        <div id="maintTargetTimePreview" style="font-size:13.5px; font-weight:600; color:#78350f;">Target: None</div>
                    </div>
                </div>

                <!-- Custom Banner Message -->
                <div class="ss-form-group" style="margin-bottom: 14px;">
                    <label for="ss_maintenance_message"><i class="fas fa-bullhorn" style="color:#002F6C; margin-right:4px;"></i> Maintenance Banner Message (Displayed to users on login screen)</label>
                    <textarea id="ss_maintenance_message" class="ss-form-control" rows="3" placeholder="Enter message displayed to users on the maintenance banner..." style="resize:vertical;">The system is currently undergoing scheduled maintenance to improve performance and stability. Please check back shortly.</textarea>
                </div>

                
            </div>

            <!-- Action Footer Bar (at bottom of form data) -->
            <div class="ss-action-bar">
                <button type="button" class="ss-btn ss-btn-secondary" onclick="restoreDefaultSettings()">
                    <i class="fas fa-undo-alt"></i> Restore Default
                </button>
                <button type="button" class="ss-btn ss-btn-light" onclick="cancelSettingsChanges()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="ss-btn ss-btn-primary" onclick="saveAllSystemSettings()">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </div>

        </form>
    </div>
</div>

<!-- Toast Container -->
<div id="toastNotification" style="display:none; position:fixed; top:72px; right:20px; z-index:999999; width:380px; background:#ffffff; border-radius:10px; box-shadow:0 8px 32px rgba(0,0,0,0.22); animation:toastSlideIn 0.35s cubic-bezier(0.16,1,0.3,1);">
    <div style="display:flex; align-items:flex-start; gap:12px; padding:16px;">
        <div id="toastIconBg" style="flex-shrink:0; background:#dcfce7; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
            <i id="toastIcon" class="fas fa-check-circle" style="color:#16a34a; font-size:18px;"></i>
        </div>
        <div style="flex:1; min-width:0;">
            <div id="toastTitle" style="font-size:15px; font-weight:700; color:#15803d; margin-bottom:3px;">Success</div>
            <div id="toastMessage" style="font-size:14px; color:#374151; line-height:1.55; font-weight:400;">Settings saved successfully.</div>
        </div>
    </div>
</div>

<style>
@keyframes toastSlideIn {
    from { opacity:0; transform:translateX(110%); }
    to   { opacity:1; transform:translateX(0); }
}
</style>

<script>
const STATION_DATA = <?php echo json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']], $stations), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const API_URL = '../backend/api/system_settings_api.php';
// PHP-generated paths — always accurate regardless of JS relative path resolution
const DEFAULT_LOGO_URL = <?php 
    // Use the currently loaded logo from DB, or fall back to the real default file
    $current_logo_db = null;
    try {
        $logoStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key IN ('logo','company_logo') AND station_id = 0 AND setting_value IS NOT NULL AND setting_value != '' LIMIT 1");
        $logoStmt->execute();
        $current_logo_db = $logoStmt->fetchColumn();
    } catch(Exception $e) {}
    // Real default logo — verified to exist on disk
    echo json_encode('../assets/img/Petron Logo.png');
?>;
const CURRENT_LOGO_URL = <?php echo json_encode($current_logo_db ?: '../assets/img/Petron Logo.png'); ?>;
let loadedSettings = {};

function showToast(title, message, isError = false) {
    // Check error notification setting
    const errorToggle = document.getElementById('ss_enable_error_notifications');
    const errorNotifEnabled = errorToggle ? errorToggle.checked : (window.petronSystemSettings?.enableErrorNotifications ?? true);
    if (isError && !errorNotifEnabled) {
        return; // Suppressed by Error Notifications toggle
    }

    const el = document.getElementById('toastNotification');
    if (!el) return;
    const tTitle = document.getElementById('toastTitle');
    const tMsg = document.getElementById('toastMessage');
    const tIcon = document.getElementById('toastIcon');
    const tBg = document.getElementById('toastIconBg');

    tTitle.textContent = title;
    tMsg.textContent = message;

    if (isError) {
        tTitle.style.color = '#dc2626';
        tBg.style.background = '#fee2e2';
        tIcon.className = 'fas fa-exclamation-circle';
        tIcon.style.color = '#dc2626';
    } else {
        tTitle.style.color = '#15803d';
        tBg.style.background = '#dcfce7';
        tIcon.className = 'fas fa-check-circle';
        tIcon.style.color = '#16a34a';
    }

    el.style.display = 'block';
    el.style.opacity = '1';
    el.style.transform = 'translateX(0)';

    // Dynamic duration from user settings
    const durInput = document.getElementById('ss_banner_duration');
    const durSec = Math.max(1, parseInt(durInput ? durInput.value : (loadedSettings.banner_duration || window.petronSystemSettings?.bannerDuration || 5), 10));
    const timeoutMs = durSec * 1000;

    if (window.petronToastTimer) clearTimeout(window.petronToastTimer);
    window.petronToastTimer = setTimeout(() => {
        el.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateX(110%)';
        setTimeout(() => { el.style.display = 'none'; }, 320);
    }, timeoutMs);
}

// Station Combo Box Init
(function initStationCombo() {
    const combo   = document.getElementById('ss_station_combo');
    const list    = document.getElementById('ss_station_list');
    const display = document.getElementById('ss_station_display');
    const hidden  = document.getElementById('ss_station_val');
    const clear   = document.getElementById('ss_station_clear');
    const dropdown = document.getElementById('ss_station_dropdown');

    if (!combo || !display || !list || !hidden || !clear || !dropdown) return;

    let currentVal = '0';

    function render(q) {
        const rawQ = (q || '').trim();
        // If query matches current selected label exactly, treat search filter as empty so all options show
        const isSelectedLabel = (currentVal === '0' && rawQ === 'Global (All Stations)') || 
                                (STATION_DATA.some(s => String(s.id) === currentVal && s.name === rawQ));
        const lq = isSelectedLabel ? '' : rawQ.toLowerCase();
        list.innerHTML = '';

        if (!lq || 'global (all stations)'.includes(lq)) {
            const itemAll = document.createElement('div');
            itemAll.className = 'am-combo-item' + (currentVal === '0' ? ' selected' : '');
            itemAll.textContent = 'Global (All Stations)';
            itemAll.onclick = () => select('0', 'Global (All Stations)');
            list.appendChild(itemAll);
        }

        const filtered = STATION_DATA.filter(s => !lq || s.name.toLowerCase().includes(lq)).slice(0, 100);
        filtered.forEach(s => {
            const item = document.createElement('div');
            item.className = 'am-combo-item' + (currentVal === String(s.id) ? ' selected' : '');
            item.textContent = s.name;
            item.onclick = () => select(String(s.id), s.name);
            list.appendChild(item);
        });
    }

    function select(id, name) {
        currentVal = id;
        hidden.value = id;
        display.value = name;
        dropdown.style.display = 'none';
        clear.style.display = (id === '0') ? 'none' : 'block';
        loadSystemSettings(id);
    }

    display.onfocus = () => { render(display.value); dropdown.style.display = 'block'; display.select(); };
    display.onclick = () => { render(display.value); dropdown.style.display = 'block'; };
    display.oninput = () => { render(display.value); dropdown.style.display = 'block'; };

    clear.onclick = (e) => {
        e.stopPropagation();
        select('0', 'Global (All Stations)');
    };

    document.addEventListener('click', (e) => {
        if (!combo.contains(e.target)) dropdown.style.display = 'none';
    });

    display.value = 'Global (All Stations)';
})();

async function loadSystemSettings(stationId = '0') {
    try {
        const res = await fetch(`${API_URL}?action=get_settings&station_id=${stationId}`);
        const data = await res.json();
        if (data.success && data.settings && typeof data.settings === 'object') {
            loadedSettings = data.settings;
            populateFormFields(data.settings);
        } else if (!data.success) {
            showToast('Load Error', data.message || 'Failed to load settings.', true);
        }
    } catch (e) {
        console.error('Failed to load system settings:', e);
        showToast('Load Error', 'Could not connect to server.', true);
    }
}

function populateFormFields(s) {
    document.getElementById('ss_system_name').value = s.system_name || 'Petron Station Management System';
    document.getElementById('ss_system_version').value = s.system_version || 'v1.0.0';
    document.getElementById('ss_timezone').value = s.timezone || 'Asia/Manila (UTC+8)';
    document.getElementById('ss_date_format').value = s.date_format || 'YYYY-MM-DD';
    document.getElementById('ss_time_format').value = s.time_format || '12H';
    document.getElementById('ss_currency_symbol').value = s.currency_symbol || 'PHP (₱)';
    document.getElementById('ss_theme').value = s.theme || 'Light';

    // Sidebar Navigation Color (priority: sidebar_color -> color_sidebar -> if user set custom accent color (#006b1b) -> default #00264D)
    let sbCol = s.sidebar_color || s.color_sidebar || '';
    if (!sbCol && s.system_accent_color && s.system_accent_color !== '#002F6C') {
        sbCol = s.system_accent_color;
    }
    sbCol = sbCol || '#00264D';
    document.getElementById('ss_sidebar_color').value = sbCol;
    document.getElementById('ss_sidebar_color_hex').value = sbCol.toUpperCase();
    onSidebarColorChange(sbCol);

    // Active Navigation Item Color (priority: nav_active_color -> default #E30613)
    const navActiveCol = s.nav_active_color || '#E30613';
    document.getElementById('ss_nav_active_color').value = navActiveCol;
    document.getElementById('ss_nav_active_color_hex').value = navActiveCol.toUpperCase();
    onNavActiveColorChange(navActiveCol);

    // System Accent Color
    const accentCol = s.system_accent_color || '#002F6C';
    document.getElementById('ss_accent_color').value = accentCol;
    document.getElementById('ss_accent_color_hex').value = accentCol.toUpperCase();
    onAccentColorChange(accentCol);

    document.getElementById('ss_sidebar_mode').value = s.sidebar_mode || 'Expanded';
    document.getElementById('ss_dashboard_auto_refresh').value = s.dashboard_auto_refresh || s.auto_refresh_interval || '10';
    document.getElementById('ss_session_timeout').value = s.session_timeout || '30';
    document.getElementById('ss_min_password_length').value = s.min_password_length || '8';
    document.getElementById('ss_max_login_attempts').value = s.max_login_attempts || '5';
    document.getElementById('ss_require_uppercase').checked = s.require_uppercase == '1';
    document.getElementById('ss_require_numbers').checked = s.require_numbers == '1';
    document.getElementById('ss_require_special_chars').checked = s.require_special_chars == '1';
    document.getElementById('ss_banner_duration').value = s.banner_duration || '5';
    document.getElementById('ss_enable_system_notifications').checked = (s.enable_system_notifications === '1' || s.enable_system_notifications === 1 || s.enable_system_notifications === true || s.enable_system_notifications === undefined);
    document.getElementById('ss_enable_error_notifications').checked = (s.enable_error_notifications === '1' || s.enable_error_notifications === 1 || s.enable_error_notifications === true || s.enable_error_notifications === undefined);
    document.getElementById('ss_default_paper_size').value = s.default_paper_size || 'A4';
    document.getElementById('ss_default_orientation').value = s.default_orientation || 'Portrait';
    document.getElementById('ss_show_company_logo_reports').checked = (s.show_company_logo_reports === '1' || s.show_company_logo_reports === 1 || s.show_company_logo_reports === true || s.show_company_logo_reports === undefined);
    document.getElementById('ss_show_report_footer').checked = (s.show_report_footer === '1' || s.show_report_footer === 1 || s.show_report_footer === true || s.show_report_footer === undefined);
    document.getElementById('ss_maintenance_mode').checked = (s.maintenance_mode == '1');
    if (document.getElementById('ss_maintenance_message')) {
        document.getElementById('ss_maintenance_message').value = s.maintenance_message || 'The system is currently undergoing scheduled maintenance to improve performance and stability. Please check back shortly.';
    }
    if (document.getElementById('ss_maintenance_end_time')) {
        document.getElementById('ss_maintenance_end_time').value = s.maintenance_end_time ? s.maintenance_end_time.replace(' ', 'T').substring(0, 16) : '';
        updateMaintenanceTimerPreview();
    }
    // Sync system_status select (Online or Maintenance only)
    const statusSel = document.getElementById('ss_system_status');
    if (statusSel) {
        statusSel.value = (s.system_status === 'Maintenance' || s.maintenance_mode == '1') ? 'Maintenance' : 'Online';
    }
    document.getElementById('ss_last_system_update').value = s.last_system_update || '2026-08-06 22:30:00';

    // Update logo preview — show preview & remove button only if custom logo is set
    const hasLogo = (s.company_logo && s.company_logo !== '' && s.company_logo !== 'none');

    const logoImg = document.getElementById('logo_preview_img');
    const noLogoTxt = document.getElementById('no_logo_placeholder');
    const btnRemove = document.getElementById('btn_remove_logo');

    if (logoImg) {
        if (hasLogo) {
            let logoSrc = s.company_logo;
            if (!logoSrc.startsWith('http') && !logoSrc.startsWith('/') && !logoSrc.startsWith('../')) {
                logoSrc = '../' + logoSrc;
            }
            logoImg.src = logoSrc;
            logoImg.style.display = 'inline-block';
            if (noLogoTxt) noLogoTxt.style.display = 'none';
            if (btnRemove) btnRemove.style.display = 'inline-flex';
        } else {
            logoImg.src = '';
            logoImg.style.display = 'none';
            if (noLogoTxt) noLogoTxt.style.display = 'inline-block';
            if (btnRemove) btnRemove.style.display = 'none';
        }
    }

    // Also sync header logo live on every settings load
    const headerLogo = document.getElementById('petronLogo');
    if (headerLogo) {
        if (hasLogo) {
            let headerLogoSrc = s.company_logo;
            if (!headerLogoSrc.startsWith('http') && !headerLogoSrc.startsWith('/') && !headerLogoSrc.startsWith('../')) {
                headerLogoSrc = '../' + headerLogoSrc;
            }
            headerLogo.src = headerLogoSrc + '?t=' + Date.now();
            headerLogo.style.display = 'inline-block';
        } else {
            headerLogo.src = '';
            headerLogo.style.setProperty('display', 'none', 'important');
        }
    }

    const headerName = document.getElementById('headerSystemName');
    if (headerName && s.system_name) headerName.textContent = s.system_name;

    // Synchronize Live Regional Preview and active clock
    if (typeof onRegionalSettingChange === 'function') {
        onRegionalSettingChange();
    }
}

// ── Live-update header logo and system name without page reload ────────────
function updateHeaderBranding(logoUrl, systemName) {
    const headerLogo = document.getElementById('petronLogo');
    if (headerLogo) {
        if (logoUrl && logoUrl !== 'none' && logoUrl !== '') {
            let src = logoUrl;
            if (!src.startsWith('http') && !src.startsWith('/') && !src.startsWith('../')) {
                src = '../' + src;
            }
            headerLogo.src = src;
            headerLogo.style.display = 'inline-block';
        } else {
            headerLogo.src = '';
            headerLogo.style.setProperty('display', 'none', 'important');
        }
    }
    const headerName = document.getElementById('headerSystemName');
    if (headerName && systemName) {
        headerName.textContent = systemName;
    }
    // Also update page <title>
    if (systemName) {
        document.title = systemName + ' — System Settings';
    }
}

async function uploadLogo() {
    const fileInput = document.getElementById('ss_logo_input');
    if (!fileInput.files || !fileInput.files[0]) {
        showToast('Logo Upload', 'Please select an image file first.', true);
        return;
    }
    const stationId = document.getElementById('ss_station_val').value || '0';
    const formData = new FormData();
    formData.append('action', 'upload_logo');
    formData.append('station_id', stationId);
    formData.append('logo', fileInput.files[0]);

    try {
        const res = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            let logoUrl = data.logo_url;
            if (!logoUrl.startsWith('http') && !logoUrl.startsWith('/') && !logoUrl.startsWith('../')) {
                logoUrl = '../' + logoUrl;
            }
            // Update settings page preview
            const logoImg = document.getElementById('logo_preview_img');
            const noLogoTxt = document.getElementById('no_logo_placeholder');
            const btnRemove = document.getElementById('btn_remove_logo');

            if (logoImg) {
                logoImg.src = logoUrl;
                logoImg.style.display = 'inline-block';
            }
            if (noLogoTxt) noLogoTxt.style.display = 'none';
            if (btnRemove) btnRemove.style.display = 'inline-flex';

            // Live-update header logo immediately with cache-busting & show it
            const headerLogo = document.getElementById('petronLogo');
            if (headerLogo) {
                headerLogo.src = logoUrl + '?t=' + Date.now();
                headerLogo.style.display = 'inline-block';
            }
            showToast('Logo Uploaded', data.message || 'Company logo uploaded successfully.');
            // Reload to sync loadedSettings
            loadSystemSettings(stationId);
        } else {
            showToast('Upload Error', data.message || 'Failed to upload logo.', true);
        }
    } catch (e) {
        showToast('Upload Error', 'Failed to communicate with server.', true);
    }
}

async function removeLogo() {
    const stationId = document.getElementById('ss_station_val').value || '0';

    if (!confirm('Are you sure you want to remove the company logo? This cannot be undone.')) return;

    try {
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remove_logo', station_id: parseInt(stationId) })
        });
        const data = await res.json();
        if (data.success) {
            // Immediately hide preview and remove button
            const logoImg = document.getElementById('logo_preview_img');
            const noLogoTxt = document.getElementById('no_logo_placeholder');
            const btnRemove = document.getElementById('btn_remove_logo');

            if (logoImg) {
                logoImg.src = '';
                logoImg.style.display = 'none';
            }
            if (noLogoTxt) noLogoTxt.style.display = 'inline-block';
            if (btnRemove) btnRemove.style.display = 'none';

            // Live-update header logo: IMMEDIATELY HIDE/REMOVE IT from header!
            const headerLogo = document.getElementById('petronLogo');
            if (headerLogo) {
                headerLogo.src = '';
                headerLogo.style.setProperty('display', 'none', 'important');
            }

            // Clear from in-memory loadedSettings so auto-refresh doesn't restore old logo
            if (loadedSettings) { loadedSettings.company_logo = 'none'; loadedSettings.logo = 'none'; }

            // Reset file input
            const fileInput = document.getElementById('ss_logo_input');
            if (fileInput) fileInput.value = '';

            showToast('Logo Removed', data.message || 'Company logo removed successfully.');

            // Reload settings from server to confirm removal
            loadSystemSettings(stationId);
        } else {
            showToast('Error', data.message || 'Failed to remove logo.', true);
        }
    } catch (e) {
        showToast('Error', 'Failed to remove logo.', true);
    }
}

// ── Maintenance Mode ↔ System Status sync ─────────────────────────────────

// ── Maintenance Timer & Presets Helper Functions ─────────────────────────
function updateMaintenanceTimerPreview() {
    const endInput = document.getElementById('ss_maintenance_end_time');
    const previewEl = document.getElementById('maintCountdownPreview');
    const targetEl = document.getElementById('maintTargetTimePreview');
    if (!endInput || !previewEl || !targetEl) return;

    const val = endInput.value;
    if (!val) {
        previewEl.textContent = 'No timer configured';
        targetEl.textContent = 'Target: None';
        return;
    }

    const targetDate = new Date(val);
    if (isNaN(targetDate.getTime())) {
        previewEl.textContent = 'Invalid date';
        targetEl.textContent = '';
        return;
    }

    const now = new Date();
    const diff = targetDate.getTime() - now.getTime();

    const options = { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true };
    targetEl.textContent = 'Target: ' + targetDate.toLocaleString('en-US', options);

    if (diff <= 0) {
        previewEl.textContent = 'Timer Expired (Concluding shortly)';
        previewEl.style.color = '#dc2626';
    } else {
        const hours = Math.floor(diff / (1000 * 60 * 60));
        const mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const secs = Math.floor((diff % (1000 * 60)) / 1000);
        previewEl.textContent = String(hours).padStart(2, '0') + 'h ' + String(mins).padStart(2, '0') + 'm ' + String(secs).padStart(2, '0') + 's remaining';
        previewEl.style.color = '#92400e';
    }
}

function addMaintenanceMinutes(minutes) {
    const endInput = document.getElementById('ss_maintenance_end_time');
    if (!endInput) return;
    const now = new Date();
    now.setMinutes(now.getMinutes() + minutes);

    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const mins = String(now.getMinutes()).padStart(2, '0');

    endInput.value = `${year}-${month}-${day}T${hours}:${mins}`;
    updateMaintenanceTimerPreview();
}

function clearMaintenanceTimer() {
    const endInput = document.getElementById('ss_maintenance_end_time');
    if (endInput) {
        endInput.value = '';
        updateMaintenanceTimerPreview();
    }
}

setInterval(updateMaintenanceTimerPreview, 1000);

function onMaintenanceModeChange(isChecked) {
    const statusSel = document.getElementById('ss_system_status');
    if (!statusSel) return;
    statusSel.value = isChecked ? 'Maintenance' : 'Online';
    if (!isChecked && typeof clearMaintenanceTimer === 'function') {
        clearMaintenanceTimer();
    }
}

function onSystemStatusChange(val) {
    const maintToggle = document.getElementById('ss_maintenance_mode');
    if (!maintToggle) return;
    // Auto-sync maintenance toggle with status
    maintToggle.checked = (val === 'Maintenance');
    if (val !== 'Maintenance' && typeof clearMaintenanceTimer === 'function') {
        clearMaintenanceTimer();
    }
}

async function saveAllSystemSettings() {
    const stationId = document.getElementById('ss_station_val').value || '0';

    // Basic numeric validation
    const numericFields = [
        { id: 'ss_session_timeout',        label: 'Session Timeout',          min: 1,  max: 1440 },
        { id: 'ss_min_password_length',    label: 'Min Password Length',      min: 4,  max: 64   },
        { id: 'ss_max_login_attempts',     label: 'Max Login Attempts',       min: 3,  max: 20   },
        { id: 'ss_dashboard_auto_refresh', label: 'Dashboard Auto Refresh',   min: 5,  max: 3600 },
        { id: 'ss_banner_duration',        label: 'Banner Duration',          min: 1,  max: 60   },
    ];
    for (const f of numericFields) {
        const val = parseInt(document.getElementById(f.id).value);
        if (isNaN(val) || val < f.min || val > f.max) {
            showToast('Validation Error', `${f.label} must be a number between ${f.min} and ${f.max}.`, true);
            document.getElementById(f.id).focus();
            return;
        }
    }

    // Auto-stamp current timestamp for Last System Update
    const now = new Date();
    const nowStr = now.getFullYear() + '-'
        + String(now.getMonth()+1).padStart(2,'0') + '-'
        + String(now.getDate()).padStart(2,'0') + ' '
        + String(now.getHours()).padStart(2,'0') + ':'
        + String(now.getMinutes()).padStart(2,'0') + ':'
        + String(now.getSeconds()).padStart(2,'0');
    document.getElementById('ss_last_system_update').value = nowStr;

    const payload = {
        action: 'save_all_settings',
        station_id: parseInt(stationId),
        settings: {
            system_name: document.getElementById('ss_system_name').value.trim(),
            system_version: document.getElementById('ss_system_version').value.trim(),
            timezone: document.getElementById('ss_timezone').value,
            date_format: document.getElementById('ss_date_format').value,
            time_format: document.getElementById('ss_time_format').value,
            currency_symbol: document.getElementById('ss_currency_symbol').value,
            theme: document.getElementById('ss_theme').value,
            sidebar_color: document.getElementById('ss_sidebar_color').value,
            color_sidebar: document.getElementById('ss_sidebar_color').value,
            nav_active_color: document.getElementById('ss_nav_active_color').value,
            system_accent_color: document.getElementById('ss_accent_color').value,
            color_primary: document.getElementById('ss_accent_color').value,
            color_button: document.getElementById('ss_accent_color').value,
            sidebar_mode: document.getElementById('ss_sidebar_mode').value,
            dashboard_auto_refresh: document.getElementById('ss_dashboard_auto_refresh').value,
            auto_refresh_interval: document.getElementById('ss_dashboard_auto_refresh').value,
            session_timeout: document.getElementById('ss_session_timeout').value,
            min_password_length: document.getElementById('ss_min_password_length').value,
            max_login_attempts: document.getElementById('ss_max_login_attempts').value,
            require_uppercase: document.getElementById('ss_require_uppercase').checked ? '1' : '0',
            require_numbers: document.getElementById('ss_require_numbers').checked ? '1' : '0',
            require_special_chars: document.getElementById('ss_require_special_chars').checked ? '1' : '0',
            banner_duration: document.getElementById('ss_banner_duration').value,
            enable_system_notifications: document.getElementById('ss_enable_system_notifications').checked ? '1' : '0',
            enable_error_notifications: document.getElementById('ss_enable_error_notifications').checked ? '1' : '0',
            default_paper_size: document.getElementById('ss_default_paper_size').value,
            default_orientation: document.getElementById('ss_default_orientation').value,
            show_company_logo_reports: document.getElementById('ss_show_company_logo_reports').checked ? '1' : '0',
            show_report_footer: document.getElementById('ss_show_report_footer').checked ? '1' : '0',
            maintenance_mode: document.getElementById('ss_maintenance_mode').checked ? '1' : '0',
            system_status: document.getElementById('ss_system_status').value,
            last_system_update: nowStr,
            maintenance_message: document.getElementById('ss_maintenance_message') ? document.getElementById('ss_maintenance_message').value.trim() : '',
            maintenance_end_time: document.getElementById('ss_maintenance_mode').checked && document.getElementById('ss_maintenance_end_time') && document.getElementById('ss_maintenance_end_time').value ? document.getElementById('ss_maintenance_end_time').value.replace('T', ' ') + ':00' : '',
        }
    };

    try {
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            // Live-update header branding immediately
            const savedName = payload.settings.system_name;
            const savedLogo = loadedSettings.company_logo || document.getElementById('logo_preview_img').src;
            updateHeaderBranding(savedLogo, savedName);

            // Sync localStorage
            localStorage.setItem('petronTheme', payload.settings.theme.toLowerCase());
            localStorage.setItem('sidebarState', payload.settings.sidebar_mode.toLowerCase());

            // Apply live appearance & regional settings to DOM immediately
            onSidebarColorChange(payload.settings.sidebar_color);
            onNavActiveColorChange(payload.settings.nav_active_color);
            onAccentColorChange(payload.settings.system_accent_color);
            onThemeChange(payload.settings.theme);
            if (typeof onRegionalSettingChange === 'function') {
                onRegionalSettingChange();
            }

            // Sync live session timeout and security policy immediately in active window
            if (payload.settings.session_timeout) {
                const sTimeoutMin = Math.max(1, parseInt(payload.settings.session_timeout, 10));
                window.PETRON_SESSION_TIMEOUT_MIN = sTimeoutMin;
                window.PETRON_SESSION_TIMEOUT_SEC = sTimeoutMin * 60;
            }
            window.PETRON_SECURITY_POLICY = {
                session_timeout:       parseInt(payload.settings.session_timeout, 10),
                min_password_length:   parseInt(payload.settings.min_password_length, 10),
                max_login_attempts:    parseInt(payload.settings.max_login_attempts, 10),
                require_uppercase:     payload.settings.require_uppercase === '1',
                require_numbers:       payload.settings.require_numbers === '1',
                require_special_chars: payload.settings.require_special_chars === '1'
            };
            if (typeof updateSecurityPolicyPreview === 'function') {
                updateSecurityPolicyPreview();
            }

            // Live sync notification & report settings in window.petronSystemSettings
            if (window.petronSystemSettings) {
                window.petronSystemSettings.bannerDuration = parseInt(payload.settings.banner_duration, 10);
                window.petronSystemSettings.enableSystemNotifications = (payload.settings.enable_system_notifications === '1');
                window.petronSystemSettings.enableErrorNotifications = (payload.settings.enable_error_notifications === '1');
                window.petronSystemSettings.defaultPaperSize = payload.settings.default_paper_size;
                window.petronSystemSettings.defaultOrientation = payload.settings.default_orientation;
                window.petronSystemSettings.showCompanyLogoReports = (payload.settings.show_company_logo_reports === '1');
                window.petronSystemSettings.showReportFooter = (payload.settings.show_report_footer === '1');
            }

            // Live sync auto-refresh interval immediately
            if (payload.settings.dashboard_auto_refresh) {
                const refreshSec = Math.max(5, parseInt(payload.settings.dashboard_auto_refresh, 10) || 10);
                window.PETRON_AUTO_REFRESH_SECONDS = refreshSec;
                window.PETRON_AUTO_REFRESH_MS = refreshSec * 1000;
                if (window.PetronRealtime && typeof window.PetronRealtime.resetPollingInterval === 'function') {
                    window.PetronRealtime.resetPollingInterval(window.PETRON_AUTO_REFRESH_MS);
                }
            }

            showToast('Settings Saved', 'System, notification & report settings saved successfully.');
            loadSystemSettings(stationId);
        } else {
            showToast('Save Error', data.message || 'Failed to save system settings.', true);
        }
    } catch (e) {
        showToast('Save Error', 'Failed to send request to server.', true);
    }
}

async function restoreDefaultSettings() {
    const stationId = document.getElementById('ss_station_val').value || '0';
    const scopeLabel = stationId === '0' ? 'Global (All Stations)' : `Station #${stationId}`;
    if (!confirm(`Restore all settings to factory defaults for: ${scopeLabel}?\n\nThis will overwrite all current settings.`)) return;
    try {
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'restore_defaults', station_id: parseInt(stationId) })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Defaults Restored', 'System settings restored to default values successfully.');
            loadSystemSettings(stationId);
        } else {
            showToast('Restore Error', data.message || 'Failed to restore settings.', true);
        }
    } catch (e) {
        showToast('Restore Error', 'Failed to communicate with server.', true);
    }
}

function cancelSettingsChanges() {
    populateFormFields(loadedSettings);
    showToast('Changes Cancelled', 'Form reset back to saved settings.');
}

// Initial load on page ready
document.addEventListener('DOMContentLoaded', () => {
    loadSystemSettings('0');
});
</script>

<script>
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
function autoRefreshSuperadminSystemSettings() {
    const openModal = Array.from(document.querySelectorAll('.modal, .modal-overlay, [id*="Modal"]')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    if (openModal) return;

    if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA' || document.activeElement.tagName === 'SELECT')) {
        return;
    }

    const stationVal = document.getElementById('ss_station_val');
    const stationId = stationVal ? stationVal.value : '0';

    fetch(`${API_URL}?action=get_settings&station_id=${stationId}`)
        .then(r => r.json())
        .then(data => {
            if (data && data.success && data.settings && typeof data.settings === 'object') {
                loadedSettings = data.settings;
                populateFormFields(data.settings);
            }
        })
        .catch(() => {});
}
// ── STRICT 10-SECOND AUTO REFRESH INTERVAL ──
setInterval(autoRefreshSuperadminSystemSettings, 10000);

// ── REAL-TIME APPEARANCE PREVIEW HANDLERS ────────────────────────────────
function onSidebarColorChange(val) {
    if (!val) return;
    const input = document.getElementById('ss_sidebar_color');
    const hexInput = document.getElementById('ss_sidebar_color_hex');
    if (input && input.value !== val) input.value = val;
    if (hexInput) hexInput.value = val.toUpperCase();

    document.documentElement.style.setProperty('--sidebar-bg', val);
    const mainSidebar = document.getElementById('mainSidebar');
    if (mainSidebar) {
        mainSidebar.style.setProperty('background', val, 'important');
        mainSidebar.style.setProperty('background-color', val, 'important');
    }
    document.querySelectorAll('.sidebar, aside.sidebar').forEach(s => {
        s.style.setProperty('background', val, 'important');
        s.style.setProperty('background-color', val, 'important');
    });
}

function onNavActiveColorChange(val) {
    if (!val) return;
    const input = document.getElementById('ss_nav_active_color');
    const hexInput = document.getElementById('ss_nav_active_color_hex');
    if (input && input.value !== val) input.value = val;
    if (hexInput) hexInput.value = val.toUpperCase();

    document.documentElement.style.setProperty('--nav-active-color', val);
    document.documentElement.style.setProperty('--petron-red', val);
    document.querySelectorAll('.nav-item.active').forEach(el => {
        el.style.setProperty('background-color', val, 'important');
    });
    document.querySelectorAll('.sidebar-sub-item.active').forEach(el => {
        el.style.setProperty('border-left-color', val, 'important');
    });
}

function onAccentColorChange(val) {
    if (!val) return;
    const input = document.getElementById('ss_accent_color');
    const hexInput = document.getElementById('ss_accent_color_hex');
    if (input && input.value !== val) input.value = val;
    if (hexInput) hexInput.value = val.toUpperCase();

    document.documentElement.style.setProperty('--primary', val);
    document.documentElement.style.setProperty('--system-accent', val);
    document.documentElement.style.setProperty('--petron-blue', val);
}

function onThemeChange(val) {
    if (val === 'Dark') {
        document.body.classList.add('dark-theme');
        localStorage.setItem('petronTheme', 'dark');
    } else {
        document.body.classList.remove('dark-theme');
        localStorage.setItem('petronTheme', 'light');
    }
}

function onSidebarModeChange(val) {
    if (typeof window.petronToggleSidebar === 'function') {
        const isCollapsed = document.body.classList.contains('sidebar-collapsed');
        if (val === 'Collapsed' && !isCollapsed) {
            window.petronToggleSidebar();
        } else if (val === 'Expanded' && isCollapsed) {
            window.petronToggleSidebar();
        }
    }
}

function applyColorPreset(sidebarCol, activeCol, accentCol) {
    onSidebarColorChange(sidebarCol);
    onNavActiveColorChange(activeCol);
    onAccentColorChange(accentCol);
}

// ── REAL-TIME REGIONAL SETTINGS SYNC ─────────────────
function onRegionalSettingChange() {
    const tzSel   = document.getElementById('ss_timezone');
    const dateSel = document.getElementById('ss_date_format');
    const timeSel = document.getElementById('ss_time_format');
    const currSel = document.getElementById('ss_currency_symbol');

    if (!tzSel || !dateSel || !timeSel || !currSel) return;

    const rawTz   = tzSel.value || 'Asia/Manila (UTC+8)';
    const ianaTz  = rawTz.split(' ')[0].trim();
    const dateFmt = dateSel.value || 'YYYY-MM-DD';
    const timeFmt = timeSel.value || '12H';
    const rawCurr = currSel.value || 'PHP (₱)';
    let currSym   = '₱';
    const currMatch = rawCurr.match(/\((.*?)\)/);
    if (currMatch && currMatch[1]) {
        currSym = currMatch[1].trim();
    } else {
        currSym = rawCurr.trim();
    }

    // Sync window.PETRON_REGIONAL immediately so global formatters reflect choices instantly
    if (window.PETRON_REGIONAL) {
        window.PETRON_REGIONAL.timezone       = ianaTz;
        window.PETRON_REGIONAL.timezoneRaw    = rawTz;
        window.PETRON_REGIONAL.dateFormat     = dateFmt;
        window.PETRON_REGIONAL.timeFormat     = timeFmt;
        window.PETRON_REGIONAL.currencySymbol = currSym;
        window.PETRON_REGIONAL.currencyRaw    = rawCurr;
    }

    // Refresh footer clock immediately
    if (typeof updateFooterClock === 'function') {
        updateFooterClock();
    }
}
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
