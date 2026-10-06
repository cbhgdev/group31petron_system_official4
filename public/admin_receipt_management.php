<?php
/**
 * ADMIN ONLY — Receipt Management
 * Dedicated Receipt Template / Configuration Module for Admin & Station Authority.
 * Configures receipt headers, station details, logo, title, prefix, paper size,
 * footer message, terms/notes, and field visibility with real-time live preview.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_id = 'admin_receipt_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$user_id    = (int) ($me['id'] ?? ($_SESSION['user_id'] ?? 0));

if (!in_array($role, ['admin', 'owner', 'superadmin', 'developer'], true)) {
    header('Location: dashboard.php');
    exit;
}

$can_edit_template = in_array($role, ['admin', 'owner', 'superadmin', 'developer'], true);
$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

// ── Ensure receipt_config table and columns exist ───────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS receipt_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT NOT NULL DEFAULT 0,
            receipt_title VARCHAR(100) NOT NULL DEFAULT 'SALES INVOICE',
            receipt_number_prefix VARCHAR(30) NOT NULL DEFAULT 'RCP-',
            station_header VARCHAR(255) DEFAULT '',
            branch_name VARCHAR(150) DEFAULT '',
            station_address VARCHAR(500) DEFAULT '',
            station_contact VARCHAR(100) DEFAULT '',
            station_vat_tin VARCHAR(100) DEFAULT '',
            atp_no VARCHAR(100) DEFAULT '',
            min_serial VARCHAR(100) DEFAULT '',
            logo_path VARCHAR(500) DEFAULT '',
            footer_title VARCHAR(150) DEFAULT '',
            footer_message TEXT DEFAULT '',
            terms_notes TEXT DEFAULT '',
            show_vat TINYINT(1) NOT NULL DEFAULT 1,
            show_payment_details TINYINT(1) NOT NULL DEFAULT 1,
            show_cashier TINYINT(1) NOT NULL DEFAULT 1,
            show_customer TINYINT(1) NOT NULL DEFAULT 1,
            show_jo_details TINYINT(1) NOT NULL DEFAULT 1,
            show_qr TINYINT(1) NOT NULL DEFAULT 1,
            paper_size VARCHAR(20) NOT NULL DEFAULT 'thermal_80mm',
            updated_by INT DEFAULT NULL,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_receipt_station (station_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $cols_needed = [
        'branch_name'     => "VARCHAR(150) DEFAULT ''",
        'atp_no'          => "VARCHAR(100) DEFAULT ''",
        'min_serial'      => "VARCHAR(100) DEFAULT ''",
        'footer_title'    => "VARCHAR(150) DEFAULT 'Official Sales Invoice / Receipt'",
        'show_jo_details' => "TINYINT(1) NOT NULL DEFAULT 1",
        'show_qr'         => "TINYINT(1) NOT NULL DEFAULT 1"
    ];
    foreach ($cols_needed as $col => $definition) {
        try {
            $pdo->exec("ALTER TABLE receipt_config ADD COLUMN IF NOT EXISTS $col $definition");
        } catch (Throwable $e) {
            try { $pdo->exec("ALTER TABLE receipt_config ADD COLUMN $col $definition"); } catch (Throwable $e2) {}
        }
    }

    // Clean legacy default/seed dummy values in receipt_config so newly created or unconfigured stations start with clean blank fields
    try {
        $pdo->exec("
            UPDATE receipt_config
            SET atp_no = ''
            WHERE atp_no = 'BIR-ATP-2026-00984712'
        ");
        $pdo->exec("
            UPDATE receipt_config
            SET min_serial = ''
            WHERE min_serial = 'MIN-2026-009812'
        ");
        $pdo->exec("
            UPDATE receipt_config
            SET station_header = ''
            WHERE station_header IN ('PETRON STATION MANAGEMENT SYSTEM', 'PETRON CORPORATION')
        ");
        $pdo->exec("
            UPDATE receipt_config
            SET station_address = ''
            WHERE station_address LIKE '%Vamenta Blvd%'
        ");
        $pdo->exec("
            UPDATE receipt_config
            SET station_vat_tin = ''
            WHERE station_vat_tin = '248-719-305-00000'
        ");
    } catch (Throwable $e) {}
} catch (Exception $e) {}

// ── Load current receipt config from DB ────────────────────────────────────
$config = [];
try {
    $cs = $pdo->prepare("SELECT * FROM receipt_config WHERE station_id = ? LIMIT 1");
    $cs->execute([$station_id]);
    $config = $cs->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// ── Fetch assigned station details ─────────────────────────────────────────
$station_info = [];
if ($station_id > 0) {
    try {
        $ss = $pdo->prepare("SELECT * FROM stations WHERE id = ? LIMIT 1");
        $ss->execute([$station_id]);
        $station_info = $ss->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}
}

// Only load saved configuration for THIS station from receipt_config.
// For a new station or other station with no saved receipt config,
// all station & BIR fields remain completely blank so the admin can manually input them.
$val_header        = $config['station_header']        ?? '';
$val_branch        = $config['branch_name']           ?? '';
$val_address       = $config['station_address']       ?? '';
$val_contact       = $config['station_contact']       ?? '';
$val_vat_tin       = $config['station_vat_tin']       ?? '';
$val_atp_no        = $config['atp_no']                ?? '';
$val_min_serial    = $config['min_serial']            ?? '';

// For a new station or unconfigured station, ensure all station & BIR header fields start completely blank for manual input
if (in_array(trim($val_header), ['PETRON STATION MANAGEMENT SYSTEM', 'PETRON CORPORATION'], true)) {
    $val_header = '';
}
if (stripos($val_address, 'Vamenta Blvd') !== false) {
    $val_address = '';
}
if (trim($val_vat_tin) === '248-719-305-00000') {
    $val_vat_tin = '';
}
if (trim($val_atp_no) === 'BIR-ATP-2026-00984712') {
    $val_atp_no = '';
}
if (trim($val_min_serial) === 'MIN-2026-009812') {
    $val_min_serial = '';
}
$val_title         = !empty($config['receipt_title'])         ? $config['receipt_title']         : 'SALES INVOICE';
$val_prefix        = !empty($config['receipt_number_prefix']) ? $config['receipt_number_prefix'] : 'RCP-';
$val_footer_title  = !empty($config['footer_title'])          ? $config['footer_title']          : 'Official Sales Invoice / Receipt';
$val_footer_msg    = isset($config['footer_message']) && $config['footer_message'] !== '' ? $config['footer_message'] : 'Thank you for your purchase!';
$val_terms         = $config['terms_notes'] ?? '';
$val_paper_size    = $config['paper_size'] ?? 'thermal_80mm';

$val_show_vat      = isset($config['show_vat']) ? (int)$config['show_vat'] : 1;
$val_show_pay      = isset($config['show_payment_details']) ? (int)$config['show_payment_details'] : 1;
$val_show_cashier  = isset($config['show_cashier']) ? (int)$config['show_cashier'] : 1;
$val_show_customer = isset($config['show_customer']) ? (int)$config['show_customer'] : 1;
$val_show_jo       = isset($config['show_jo_details']) ? (int)$config['show_jo_details'] : 1;
$val_show_qr       = isset($config['show_qr']) ? (int)$config['show_qr'] : 1;

$current_logo = !empty($config['logo_path']) ? $config['logo_path'] : (function_exists('get_system_logo_url') ? get_system_logo_url($station_id) : 'assets/img/Petron Logo.png');

// Build the correct public URL for images — works on both localhost subdirectory & live server docroot
$script_name_rm = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$public_pos_rm  = strpos($script_name_rm, '/public/');
if ($public_pos_rm !== false) {
    $app_base_rm = substr($script_name_rm, 0, $public_pos_rm); // e.g. /group31petron_system_official4
} else {
    $dir_rm = rtrim(dirname($script_name_rm), '/\\');
    $app_base_rm = ($dir_rm === '/' || $dir_rm === '.' || $dir_rm === '') ? '' : $dir_rm;
}
// $logo_url_base = e.g. "/group31petron_system_official4" on localhost, "" on live server
$logo_url_base = rtrim($app_base_rm, '/');

require_once __DIR__ . '/../partials/header.php';
?>

<style>
.rm-header { padding: 0 0 18px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #e2e8f0; }
.rm-header h1 { font-size: 22px; font-weight: 700; margin: 0; color: #0f172a; display: flex; align-items: center; gap: 12px; }

.rm-section { padding: 20px 0; }

.rm-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,.03); }
.rm-card h3 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0 0 16px 0;
              padding-bottom: 10px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 9px; }
.rm-card h3 i { color: #003d7a; }

.rm-form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.rm-form-group { display: flex; flex-direction: column; gap: 5px; }
.rm-form-group label { font-size: 13px; font-weight: 600; color: #374151; }
.rm-form-group input, .rm-form-group select, .rm-form-group textarea {
    border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 11px; font-size: 13.5px;
    color: #1e293b; outline: none; transition: border-color .2s; }
.rm-form-group input:focus, .rm-form-group select:focus, .rm-form-group textarea:focus {
    border-color: #003d7a; box-shadow: 0 0 0 2px rgba(0,61,122,.1); }
.rm-form-group textarea { min-height: 65px; resize: vertical; }

.rm-toggle-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.rm-toggle-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px;
                 display: flex; align-items: flex-start; gap: 10px; cursor: pointer; transition: all .2s; }
.rm-toggle-box:hover { border-color: #cbd5e1; background: #f1f5f9; }
.rm-toggle-box input[type="checkbox"] { width: 17px; height: 17px; margin-top: 2px; cursor: pointer; }
.rm-toggle-content { display: flex; flex-direction: column; }
.rm-toggle-title { font-size: 13px; font-weight: 600; color: #1e293b; }
.rm-toggle-sub { font-size: 11.5px; color: #64748b; margin-top: 2px; }

.rm-btn { display: inline-flex; align-items: center; gap: 7px; padding: 10px 22px;
          border-radius: 7px; font-size: 14px; font-weight: 700; cursor: pointer;
          transition: all .2s; }
.rm-btn-primary { background: #ffffff !important; color: #002F70 !important; border: 1.5px solid #002F70 !important; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.rm-btn-primary:hover { background: #eff6ff !important; border-color: #002F70 !important; }
.rm-btn-secondary { background: #ffffff !important; color: #475569 !important; border: 1.5px solid #cbd5e1 !important; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.rm-btn-secondary:hover { background: #f8fafc !important; border-color: #94a3b8 !important; }

/* Live Receipt Preview — Exact Match to Thermal Receipt */
.rm-preview-wrap { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 10px;
                   padding: 20px 14px; display: flex; justify-content: center; }
.rm-preview-receipt { background: #fff; width: 300px; padding: 18px 16px; font-size: 10.5px;
                       font-family: 'Courier New', Courier, monospace; border: 1px dashed #94a3b8;
                       border-radius: 4px; line-height: 1.5; box-shadow: 0 4px 16px rgba(0,0,0,.08); color: #111; }
.rm-preview-receipt .pr-center { text-align: center; }
.rm-preview-receipt .pr-bold { font-weight: 700; }
.rm-preview-receipt .pr-hr { border-top: 1px dashed #888; margin: 7px 0; }
.rm-preview-receipt .pr-double { border-top: 3px double #111; margin: 8px 0; }
.rm-preview-receipt .pr-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 2px; gap: 6px; font-size: 10px; }
.rm-preview-receipt .pr-key { color: #555; flex-shrink: 0; }
.rm-preview-receipt .pr-val { text-align: right; word-break: break-word; }
.rm-preview-receipt .pr-sec-lbl { font-size: 8.5px; font-weight: 700; letter-spacing: 1.1px; text-transform: uppercase; color: #002F70; margin: 6px 0 3px; }
.rm-preview-receipt .pr-title { font-size: 13px; font-weight: 900; text-align: center; letter-spacing: 1px; margin: 5px 0 2px; color: #111; }
.rm-preview-receipt .pr-sub { font-size: 9px; color: #555; text-align: center; margin-bottom: 3px; }

/* ══ Top-Right Notification Banner (Plain & Clean) ══ */
.rm-banner {
    position: fixed !important;
    top: 85px !important;
    right: 28px !important;
    z-index: 2147483647 !important;
    display: none;
    align-items: center;
    gap: 14px;
    min-width: 320px;
    max-width: 440px;
    padding: 13px 18px;
    border-radius: 8px;
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.10), 0 2px 6px rgba(0, 0, 0, 0.06);
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    cursor: pointer;
    animation: rmSlideInRight 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes rmSlideInRight {
    from { opacity: 0; transform: translateX(50px); }
    to   { opacity: 1; transform: translateX(0); }
}
.rm-banner-icon {
    font-size: 22px;
    color: #475569;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.rm-banner-content {
    flex: 1;
    line-height: 1.35;
}
.rm-banner-title {
    font-size: 13.5px;
    font-weight: 700;
    letter-spacing: 0.3px;
    color: #16a34a !important;
}
.rm-banner-desc {
    font-size: 12px;
    color: #64748b !important;
    margin-top: 2px;
}
</style>

<!-- Top-Right Success Notification Banner (Clean & Plain) -->
<div id="rmSuccessBanner" class="rm-banner" onclick="hideBanner()" title="Click to dismiss">
    <div class="rm-banner-icon"><i class="fas fa-check-circle" style="color:#16a34a !important;font-size:22px;"></i></div>
    <div class="rm-banner-content">
        <div class="rm-banner-title" id="rmBannerTitle" style="color:#16a34a !important;font-weight:700;font-size:13.5px;">SUCCESSFULLY CHANGED</div>
        <div class="rm-banner-desc" id="rmBannerDesc" style="color:#64748b !important;font-size:12px;margin-top:2px;">Receipt template configuration has been updated successfully.</div>
    </div>
</div>

<div class="rm-header">
    <h1 style="margin:0; font-size:22px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:12px;">
        <i class="fas fa-file-invoice" style="font-size:24px; color:#003d7a;"></i>
        <span>RECEIPT MANAGEMENT</span>
    </h1>
</div>

<div class="rm-section">

    <form id="frmTemplate" onsubmit="saveTemplate(event)">
    <div style="display: grid; grid-template-columns: 1fr 320px; gap: 22px; align-items: start;">

        <!-- LEFT: Config Fields -->
        <div>

            <!-- 1. Station & BIR Header Information -->
            <div class="rm-card">
                <h3><i class="fas fa-building"></i> Station & Header Details</h3>
                <div class="rm-form-grid">
                    <div class="rm-form-group" style="grid-column: span 2;">
                        <label>Station / Company Name (Main Header)</label>
                        <input type="text" name="station_header" id="f_station_header"
                               value="<?= $h($val_header) ?>"
                               placeholder="e.g. PETRON CORPORATION or Station Name" <?= !$can_edit_template ? 'readonly' : '' ?>>
                    </div>
                    <input type="hidden" name="branch_name" id="f_branch_name" value="<?= $h($val_branch) ?>">
                    <div class="rm-form-group" style="grid-column: span 2;">
                        <label>Registered Station Address</label>
                        <input type="text" name="station_address" id="f_station_address"
                               value="<?= $h($val_address) ?>"
                               placeholder="Enter registered station address (e.g. Street, Barangay, City, Province)" <?= !$can_edit_template ? 'readonly' : '' ?>>
                    </div>
                    <div class="rm-form-group">
                        <label>VAT Reg. TIN</label>
                        <input type="text" name="station_vat_tin" id="f_station_vat_tin"
                               value="<?= $h($val_vat_tin) ?>"
                               placeholder="Enter VAT Reg. TIN (e.g. 000-000-000-00000)" <?= !$can_edit_template ? 'readonly' : '' ?>>
                    </div>
                    <div class="rm-form-group">
                        <label>BIR ATP / Accreditation No.</label>
                        <input type="text" name="atp_no" id="f_atp_no"
                               value="<?= $h($val_atp_no) ?>"
                               placeholder="Enter BIR ATP No. (e.g. BIR-ATP-YYYY-XXXXXXXX)" <?= !$can_edit_template ? 'readonly' : '' ?>>
                    </div>
                    <div class="rm-form-group">
                        <label>Machine Serial No. (MIN)</label>
                        <input type="text" name="min_serial" id="f_min_serial"
                               value="<?= $h($val_min_serial) ?>"
                               placeholder="Enter MIN Serial No. (e.g. MIN-YYYY-XXXXXX)" <?= !$can_edit_template ? 'readonly' : '' ?>>
                    </div>
                    <div class="rm-form-group">
                        <label>Paper Size / Layout</label>
                        <select name="paper_size" id="f_paper_size" <?= !$can_edit_template ? 'disabled' : '' ?>>
                            <option value="thermal_80mm" <?= $val_paper_size === 'thermal_80mm' ? 'selected' : '' ?>>Thermal POS 80mm (Standard)</option>
                            <option value="thermal_58mm" <?= $val_paper_size === 'thermal_58mm' ? 'selected' : '' ?>>Thermal POS 58mm (Compact)</option>
                            <option value="a4" <?= $val_paper_size === 'a4' ? 'selected' : '' ?>>A4 Document (PDF Invoice)</option>
                            <option value="letter" <?= $val_paper_size === 'letter' ? 'selected' : '' ?>>Letter Document (PDF)</option>
                        </select>
                    </div>
                    <div class="rm-form-group" style="grid-column: span 2;">
                        <label>Receipt Logo (Station Branding)</label>
                        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                            <img id="pv_logo_img" src="<?= $h($logo_url_base . '/' . ltrim($current_logo, '/')) ?>"
                                 alt="Logo Preview" style="max-height:46px;max-width:130px;border:1px solid #e2e8f0;padding:4px;border-radius:6px;background:#fff;object-fit:contain;"
                                 onerror="this.src='<?= $h($logo_url_base) ?>/assets/img/Petron Logo.png'">
                            <?php if ($can_edit_template): ?>
                            <div style="flex:1;min-width:200px;">
                                <input type="file" name="receipt_logo" id="f_receipt_logo" accept="image/*" onchange="previewLogo(event)" style="font-size:12px;">
                                <div style="font-size:11px;color:#94a3b8;margin-top:2px;">PNG, JPG, or SVG. Visible on thermal printout and PDF.</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Receipt Identity & Numbering -->
            <div class="rm-card">
                <h3><i class="fas fa-file-invoice"></i> Receipt Identity & Title</h3>
                <div class="rm-form-grid">
                    <div class="rm-form-group">
                        <label>Receipt Title</label>
                        <input type="text" name="receipt_title" id="f_receipt_title"
                               value="<?= $h($val_title) ?>"
                               placeholder="e.g. SALES INVOICE" <?= !$can_edit_template ? 'readonly' : '' ?>>
                    </div>
                    <div class="rm-form-group">
                        <label>Receipt Number Prefix</label>
                        <input type="text" name="receipt_number_prefix" id="f_receipt_number_prefix"
                               value="<?= $h($val_prefix) ?>"
                               placeholder="e.g. RCP- or OR-" <?= !$can_edit_template ? 'readonly' : '' ?>>
                    </div>
                </div>
            </div>

            <!-- 3. Receipt Sections & Field Toggles -->
            <div class="rm-card">
                <h3><i class="fas fa-sliders-h"></i> Receipt Sections Display</h3>
                <div class="rm-toggle-grid">
                    <label class="rm-toggle-box" for="f_show_customer">
                        <input type="checkbox" name="show_customer" id="f_show_customer" value="1"
                               <?= $val_show_customer ? 'checked' : '' ?> <?= !$can_edit_template ? 'disabled' : '' ?>>
                        <div class="rm-toggle-content">
                            <span class="rm-toggle-title">Customer Name</span>
                            <span class="rm-toggle-sub">Print customer name or Walk-in</span>
                        </div>
                    </label>
                    <label class="rm-toggle-box" for="f_show_cashier">
                        <input type="checkbox" name="show_cashier" id="f_show_cashier" value="1"
                               <?= $val_show_cashier ? 'checked' : '' ?> <?= !$can_edit_template ? 'disabled' : '' ?>>
                        <div class="rm-toggle-content">
                            <span class="rm-toggle-title">Cashier / Shift Info</span>
                            <span class="rm-toggle-sub">Print staff username & assigned shift</span>
                        </div>
                    </label>
                    <label class="rm-toggle-box" for="f_show_vat">
                        <input type="checkbox" name="show_vat" id="f_show_vat" value="1"
                               <?= $val_show_vat ? 'checked' : '' ?> <?= !$can_edit_template ? 'disabled' : '' ?>>
                        <div class="rm-toggle-content">
                            <span class="rm-toggle-title">Tax / VAT Breakdown</span>
                            <span class="rm-toggle-sub">Vatable sales & VAT (12%) amounts</span>
                        </div>
                    </label>
                    <label class="rm-toggle-box" for="f_show_payment_details">
                        <input type="checkbox" name="show_payment_details" id="f_show_payment_details" value="1"
                               <?= $val_show_pay ? 'checked' : '' ?> <?= !$can_edit_template ? 'disabled' : '' ?>>
                        <div class="rm-toggle-content">
                            <span class="rm-toggle-title">Payment Breakdown</span>
                            <span class="rm-toggle-sub">Payment method, tendered & change</span>
                        </div>
                    </label>
                    </div>
            </div>

            <!-- 4. Footer & Legal Disclaimers -->
            <div class="rm-card">
                <h3><i class="fas fa-align-left"></i> Footer & BIR Disclaimers</h3>
                <div class="rm-form-grid">
                    <div class="rm-form-group" style="grid-column: span 2;">
                        <label>Footer Heading Title</label>
                        <input type="text" name="footer_title" id="f_footer_title"
                               value="<?= $h($val_footer_title) ?>"
                               placeholder="e.g. Official Sales Invoice / Receipt" <?= !$can_edit_template ? 'readonly' : '' ?>>
                    </div>
                    <div class="rm-form-group" style="grid-column: span 2;">
                        <label>Customer Greeting / Closing Message</label>
                        <textarea name="footer_message" id="f_footer_message"
                                  placeholder="Thank you for your purchase!" <?= !$can_edit_template ? 'readonly' : '' ?>><?= $h($val_footer_msg) ?></textarea>
                    </div>
                    <div class="rm-form-group" style="grid-column: span 2;">
                        <label>Terms / Notes / Return Policy</label>
                        <textarea name="terms_notes" id="f_terms_notes"
                                  placeholder="e.g. Items once sold cannot be returned. Fuels non-refundable." <?= !$can_edit_template ? 'readonly' : '' ?>><?= $h($val_terms) ?></textarea>
                    </div>
                </div>
            </div>

            <?php if ($can_edit_template): ?>
            <div style="display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:20px;">
                <button type="button" class="rm-btn rm-btn-secondary" onclick="resetForm()" style="background:#ffffff !important;color:#475569 !important;border:1.5px solid #cbd5e1 !important;padding:10px 22px;border-radius:7px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                    <i class="fas fa-undo" style="color:#64748b !important;"></i> Reset
                </button>
                <button type="submit" class="rm-btn rm-btn-primary" style="background:#ffffff !important;color:#002F70 !important;border:1.5px solid #002F70 !important;padding:10px 24px;border-radius:7px;font-size:14px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                    <i class="fas fa-save" style="color:#002F70 !important;"></i> Save Receipt Configuration
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Live Preview (Sticky) -->
        <div>
            <div class="rm-card" style="position:sticky;top:80px;">
                <h3><i class="fas fa-print"></i> Live Preview</h3>
                <div class="rm-preview-wrap">
                    <div class="rm-preview-receipt" id="livePreview">
                        <!-- Centered Header Branding -->
                        <div class="pr-center" style="margin-bottom:8px;">
                            <img id="pv_preview_logo" src="<?= $h($logo_url_base . '/' . ltrim($current_logo, '/')) ?>"
                                 style="max-height:48px;max-width:120px;object-fit:contain;" alt="Logo"
                                 onerror="this.style.display='none'">
                        </div>
                        <div class="pr-center pr-bold" id="pv_header" style="font-size:11.5px;color:#002F70;letter-spacing:0.4px;"></div>
                        <div class="pr-center" id="pv_address" style="font-size:9px;color:#555;margin-top:2px;line-height:1.35;"></div>
                        <div class="pr-center" id="pv_vat_tin" style="font-size:9px;color:#555;margin-top:1px;"></div>
                        <div class="pr-center" id="pv_atp_no"  style="font-size:9px;color:#555;"></div>
                        <div class="pr-center" id="pv_min_serial" style="font-size:9px;color:#555;"></div>
                        
                        <div class="pr-double"></div>
                        <div class="pr-title" id="pv_title">SALES INVOICE</div>
                        <div class="pr-sub">Official Merchandise &amp; Service Invoice</div>
                        <div class="pr-hr"></div>

                        <!-- TRANSACTION DETAILS (Configurable) -->
                        <div class="pr-sec-lbl">TRANSACTION DETAILS</div>
                        <div class="pr-row"><span class="pr-key">OR / Invoice No</span><span class="pr-val pr-bold" id="pv_prefix">RCP-2026-000001</span></div>
                        <div class="pr-row"><span class="pr-key">Date &amp; Time</span><span class="pr-val"><?= date('F j, Y h:i A') ?></span></div>
                        <div class="pr-row" id="pv_customer_row"><span class="pr-key">Customer Name</span><span class="pr-val pr-bold">Walk-in Customer</span></div>
                        <div class="pr-row" id="pv_cashier_row"><span class="pr-key">Staff / Shift</span><span class="pr-val">Staff (Morning Shift)</span></div>

                        <!-- TAX BREAKDOWN (Configurable Toggle) -->
                        <div id="pv_vat_box">
                            <div class="pr-hr"></div>
                            <div class="pr-sec-lbl">TAX BREAKDOWN</div>
                            <div class="pr-row"><span class="pr-key">Vatable Sales</span><span class="pr-val">₱0.00</span></div>
                            <div class="pr-row"><span class="pr-key">VAT (12%)</span><span class="pr-val">₱0.00</span></div>
                            <div class="pr-row"><span class="pr-key">Zero-Rated Sales</span><span class="pr-val">₱0.00</span></div>
                            <div class="pr-row"><span class="pr-key">VAT-Exempt Sales</span><span class="pr-val">₱0.00</span></div>
                        </div>

                        <div class="pr-double"></div>
                        <div class="pr-row pr-bold" style="font-size:13px;padding:2px 0;"><span>GRAND TOTAL</span><span>₱0.00</span></div>

                        <!-- TOTALS & PAYMENT (Configurable Toggle) -->
                        <div id="pv_pay_box">
                            <div class="pr-hr"></div>
                            <div class="pr-sec-lbl">TOTALS &amp; PAYMENT</div>
                            <div class="pr-row"><span class="pr-key">Payment Method</span><span class="pr-val pr-bold">CASH</span></div>
                            <div class="pr-row"><span class="pr-key">Amount Tendered</span><span class="pr-val">₱0.00</span></div>
                            <div class="pr-row"><span class="pr-key">Change</span><span class="pr-val">₱0.00</span></div>
                        </div>

                        <!-- FOOTER & BIR DISCLAIMERS -->
                        <div class="pr-hr"></div>
                        <div class="pr-center pr-bold" id="pv_footer_title" style="font-size:10px;color:#0f172a;margin-bottom:2px;">Official Sales Invoice / Receipt</div>
                        <div class="pr-center" style="font-size:8.5px;color:#555;margin-bottom:1px;">
                            <span id="pv_foot_tin"></span> &nbsp;|&nbsp; <span>VAT Reg: Registered</span>
                        </div>
                        <div class="pr-center" id="pv_foot_atp" style="font-size:8.5px;color:#555;margin-bottom:1px;"></div>
                        <div class="pr-center" id="pv_foot_min" style="font-size:8.5px;color:#555;margin-bottom:1px;"></div>
                        <div class="pr-center pr-bold" id="pv_footer_msg" style="font-size:9.5px;color:#002F70;margin:4px 0 2px;">Thank you for your purchase!</div>
                        <div class="pr-center" id="pv_terms" style="font-size:8px;color:#64748b;margin-top:2px;line-height:1.3;"></div>
                        <div class="pr-center" style="font-size:7.5px;color:#94a3b8;margin-top:6px;">
                            Printed: <?= date('M j, Y h:i A') ?> &nbsp;|&nbsp; <span id="pv_foot_prefix">RCP-2026-000001</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>

    </div><!-- end grid -->
    </form>
</div>

<script>
// ── Live preview update ──────────────────────────────────────────────────────
function liveUpdate() {
    const g = id => document.getElementById(id)?.value ?? '';
    
    const headerVal = g('f_station_header');
    const headerEl  = document.getElementById('pv_header');
    if (headerEl) {
        if (headerVal) {
            headerEl.textContent = headerVal;
            headerEl.style.color = '#002F70';
            headerEl.style.fontStyle = 'normal';
        } else {
            headerEl.textContent = '[Station / Company Name]';
            headerEl.style.color = '#94a3b8';
            headerEl.style.fontStyle = 'italic';
        }
    }
    
    const addrVal = g('f_station_address');
    const addrEl  = document.getElementById('pv_address');
    if (addrEl) {
        addrEl.textContent   = addrVal;
        addrEl.style.display = addrVal ? '' : 'none';
    }
    
    const tinVal = g('f_station_vat_tin');
    const tinEl  = document.getElementById('pv_vat_tin');
    if (tinEl) {
        tinEl.textContent   = tinVal ? 'VAT Reg TIN: ' + tinVal : '';
        tinEl.style.display = tinVal ? '' : 'none';
    }
    const footTin = document.getElementById('pv_foot_tin');
    if (footTin) {
        footTin.textContent = tinVal ? 'TIN: ' + tinVal : '';
        if (footTin.parentElement) {
            footTin.parentElement.style.display = tinVal ? '' : 'none';
        }
    }
    
    const atpVal = g('f_atp_no');
    const atpEl  = document.getElementById('pv_atp_no');
    if (atpEl) {
        atpEl.textContent   = atpVal ? 'ATP No.: ' + atpVal : '';
        atpEl.style.display = atpVal ? '' : 'none';
    }
    const footAtp = document.getElementById('pv_foot_atp');
    if (footAtp) {
        footAtp.textContent   = atpVal ? 'ATP No.: ' + atpVal : '';
        footAtp.style.display = atpVal ? '' : 'none';
    }
    
    const minVal = g('f_min_serial');
    const minEl  = document.getElementById('pv_min_serial');
    if (minEl) {
        minEl.textContent   = minVal ? 'MIN: ' + minVal : '';
        minEl.style.display = minVal ? '' : 'none';
    }
    const footMin = document.getElementById('pv_foot_min');
    if (footMin) {
        footMin.textContent = minVal ? 'MIN: ' + minVal : '';
        footMin.style.display = minVal ? '' : 'none';
    }
    
    document.getElementById('pv_title').textContent        = g('f_receipt_title') || 'SALES INVOICE';
    
    const pfx = g('f_receipt_number_prefix') || 'RCP-';
    document.getElementById('pv_prefix').textContent       = pfx + '2026-000001';
    const footPfx = document.getElementById('pv_foot_prefix');
    if (footPfx) footPfx.textContent = pfx + '2026-000001';
    
    document.getElementById('pv_footer_title').textContent = g('f_footer_title') || 'Official Sales Invoice / Receipt';
    document.getElementById('pv_footer_msg').textContent   = g('f_footer_message') || 'Thank you for your purchase!';
    document.getElementById('pv_terms').textContent        = g('f_terms_notes') || '';

    // Toggles
    const showVat  = document.getElementById('f_show_vat')?.checked;
    const showPay  = document.getElementById('f_show_payment_details')?.checked;
    const showCash = document.getElementById('f_show_cashier')?.checked;
    const showCust = document.getElementById('f_show_customer')?.checked;
    

    const vatBox  = document.getElementById('pv_vat_box');
    const payBox  = document.getElementById('pv_pay_box');
    const cashRow = document.getElementById('pv_cashier_row');
    const custRow = document.getElementById('pv_customer_row');
    

    if (vatBox)  vatBox.style.display  = showVat  ? '' : 'none';
    if (payBox)  payBox.style.display  = showPay  ? '' : 'none';
    if (cashRow) cashRow.style.display = showCash ? '' : 'none';
    if (custRow) custRow.style.display = showCust ? '' : 'none';
    
}

// Attach live preview listeners
document.addEventListener('DOMContentLoaded', function() {
    const ids = [
        'f_station_header','f_station_address',
        'f_station_vat_tin','f_atp_no','f_min_serial','f_receipt_title',
        'f_receipt_number_prefix','f_footer_title','f_footer_message','f_terms_notes',
        'f_show_vat','f_show_payment_details','f_show_cashier','f_show_customer'];
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', liveUpdate);
        if (el) el.addEventListener('change', liveUpdate);
    });
    liveUpdate(); // initial call
});

function previewLogo(event) {
    const file = event.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const pv1 = document.getElementById('pv_logo_img');
            const pv2 = document.getElementById('pv_preview_logo');
            if (pv1) { pv1.src = e.target.result; pv1.style.display = 'inline-block'; }
            if (pv2) { pv2.src = e.target.result; pv2.style.display = 'inline-block'; }
        };
        reader.readAsDataURL(file);
    }
}

// ── Save template ────────────────────────────────────────────────────────────
function saveTemplate(e) {
    e.preventDefault();
    const btn = document.querySelector('button[type="submit"]');
    const oldHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    }

    const form = document.getElementById('frmTemplate');
    const data = new FormData(form);
    data.append('action', 'save_template');
    
    // Checkboxes: explicitly send 0 if unchecked
    ['show_vat','show_payment_details','show_cashier','show_customer'].forEach(k => {
        if (!form.elements[k]?.checked) data.set(k, '0');
    });
    
    fetch('admin_receipt_management_handler.php', { method: 'POST', body: data })
        .then(r => r.json())
        .then(resp => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
            if (resp.logo_url) {
                const pv1 = document.getElementById('pv_logo_img');
                const pv2 = document.getElementById('pv_preview_logo');
                if (pv1) { pv1.src = resp.logo_url; pv1.style.display = 'inline-block'; }
                if (pv2) { pv2.src = resp.logo_url; pv2.style.display = 'inline-block'; }
            }
            if (resp.success) {
                showSuccessBanner('SUCCESSFULLY CHANGED', 'Receipt template configuration has been updated successfully.');
            } else {
                showErrorBanner('FAILED TO SAVE', resp.message || 'An error occurred while saving.');
            }
        }).catch(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
            showErrorBanner('NETWORK ERROR', 'Please try again.');
        });
}

function resetForm() {
    if (!confirm('Reset the form? Unsaved changes will be lost.')) return;
    document.getElementById('frmTemplate').reset();
    liveUpdate();
}

// ── Top-Right Banner Controller ──────────────────────────────────────────────
let rmBannerTimer = null;

function showSuccessBanner(title = 'SUCCESSFULLY CHANGED', desc = 'Receipt template configuration has been updated successfully.') {
    showBanner('success', title, desc);
}

function showErrorBanner(title = 'FAILED TO SAVE', desc = 'Please check the form and try again.') {
    showBanner('error', title, desc);
}

function showBanner(type, title, desc) {
    const banner  = document.getElementById('rmSuccessBanner');
    const titleEl = document.getElementById('rmBannerTitle');
    const descEl  = document.getElementById('rmBannerDesc');
    const iconEl  = banner ? banner.querySelector('.rm-banner-icon i') : null;

    if (!banner) return;

    if (rmBannerTimer) clearTimeout(rmBannerTimer);

    banner.className = 'rm-banner ' + (type === 'error' ? 'rm-banner-error' : 'rm-banner-success');
    if (iconEl) {
        iconEl.className = type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
    }
    if (titleEl) titleEl.textContent = title;
    if (descEl)  descEl.textContent  = desc;

    banner.style.display = 'flex';

    rmBannerTimer = setTimeout(() => {
        hideBanner();
    }, 4500);
}

function hideBanner() {
    const banner = document.getElementById('rmSuccessBanner');
    if (banner) {
        banner.style.display = 'none';
    }
}

// Backward compatibility alias
function showToast(msg, isErr = false) {
    if (isErr) {
        showErrorBanner('NOTICE', msg);
    } else {
        showSuccessBanner('SUCCESSFULLY CHANGED', msg);
    }
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
