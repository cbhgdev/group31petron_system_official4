<?php
/**
 * admin_receipt_management_handler.php
 * AJAX handler for Receipt Management module.
 *
 * Actions:
 *   save_template       — Save receipt_config for the station
 *   request_correction  — Log a receipt correction request
 *   resolve_correction  — Admin approves or rejects a correction request
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

header('Content-Type: application/json');

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$user_id    = (int) ($me['id'] ?? ($_SESSION['user_id'] ?? 0));

$can_edit_template   = in_array($role, ['admin', 'owner', 'superadmin', 'developer'], true);
$can_approve         = in_array($role, ['admin', 'owner', 'superadmin', 'developer'], true);
$has_access          = in_array($role, ['admin', 'owner', 'superadmin', 'developer'], true);

if (!$has_access) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$action = trim($_POST['action'] ?? '');

// ── Helpers ───────────────────────────────────────────────────────────────────
function safe_str(string $key, int $maxlen = 500): string {
    $v = trim($_POST[$key] ?? '');
    return mb_substr($v, 0, $maxlen, 'UTF-8');
}
function safe_int_flag(string $key): int {
    $v = trim($_POST[$key] ?? '0');
    return $v === '1' ? 1 : 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: save_template
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'save_template') {
    if (!$can_edit_template) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Admin/Owner only.']);
        exit;
    }

    $receipt_title          = safe_str('receipt_title', 100);
    $receipt_number_prefix  = safe_str('receipt_number_prefix', 30);
    $station_header         = safe_str('station_header', 255);
    $branch_name            = safe_str('branch_name', 150);
    $station_address        = safe_str('station_address', 500);
    $station_contact        = safe_str('station_contact', 100);
    $station_vat_tin        = safe_str('station_vat_tin', 100);
    $atp_no                 = safe_str('atp_no', 100);
    $min_serial             = safe_str('min_serial', 100);
    $footer_title           = safe_str('footer_title', 150);
    $footer_message         = safe_str('footer_message', 2000);
    $terms_notes            = safe_str('terms_notes', 2000);
    $paper_size             = safe_str('paper_size', 20);
    $show_vat               = safe_int_flag('show_vat');
    $show_payment_details   = safe_int_flag('show_payment_details');
    $show_cashier           = safe_int_flag('show_cashier');
    $show_customer          = safe_int_flag('show_customer');
    $show_jo_details        = safe_int_flag('show_jo_details');
    $show_qr                = safe_int_flag('show_qr');

    // Validate
    if ($receipt_title === '') $receipt_title = 'SALES INVOICE';
    if ($receipt_number_prefix === '') $receipt_number_prefix = 'RCP-';
    $allowed_paper = ['thermal_80mm', 'thermal_58mm', 'a4', 'letter'];
    if (!in_array($paper_size, $allowed_paper, true)) $paper_size = 'thermal_80mm';

    try {
        // Ensure table and columns exist
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
            'branch_name' => "VARCHAR(150) DEFAULT ''",
            'atp_no' => "VARCHAR(100) DEFAULT ''",
            'min_serial' => "VARCHAR(100) DEFAULT ''",
            'footer_title' => "VARCHAR(150) DEFAULT 'Official Sales Invoice / Receipt'",
            'show_jo_details' => "TINYINT(1) NOT NULL DEFAULT 1",
            'show_qr' => "TINYINT(1) NOT NULL DEFAULT 1",
        ];
        foreach ($cols_needed as $col => $definition) {
            try {
                $pdo->exec("ALTER TABLE receipt_config ADD COLUMN IF NOT EXISTS $col $definition");
            } catch (Throwable $e) {
                try { $pdo->exec("ALTER TABLE receipt_config ADD COLUMN $col $definition"); } catch (Throwable $e2) {}
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO receipt_config
                (station_id, receipt_title, receipt_number_prefix, station_header, branch_name,
                 station_address, station_contact, station_vat_tin, atp_no, min_serial,
                 footer_title, footer_message, terms_notes,
                 show_vat, show_payment_details, show_cashier, show_customer, show_jo_details, show_qr,
                 paper_size, updated_by, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                receipt_title          = VALUES(receipt_title),
                receipt_number_prefix  = VALUES(receipt_number_prefix),
                station_header         = VALUES(station_header),
                branch_name            = VALUES(branch_name),
                station_address        = VALUES(station_address),
                station_contact        = VALUES(station_contact),
                station_vat_tin        = VALUES(station_vat_tin),
                atp_no                 = VALUES(atp_no),
                min_serial             = VALUES(min_serial),
                footer_title           = VALUES(footer_title),
                footer_message         = VALUES(footer_message),
                terms_notes            = VALUES(terms_notes),
                show_vat               = VALUES(show_vat),
                show_payment_details   = VALUES(show_payment_details),
                show_cashier           = VALUES(show_cashier),
                show_customer          = VALUES(show_customer),
                show_jo_details        = VALUES(show_jo_details),
                show_qr                = VALUES(show_qr),
                paper_size             = VALUES(paper_size),
                updated_by             = VALUES(updated_by),
                updated_at             = NOW()
        ");
        $stmt->execute([
            $station_id, $receipt_title, $receipt_number_prefix,
            $station_header, $branch_name, $station_address, $station_contact,
            $station_vat_tin, $atp_no, $min_serial,
            $footer_title, $footer_message, $terms_notes,
            $show_vat, $show_payment_details, $show_cashier, $show_customer,
            $show_jo_details, $show_qr,
            $paper_size, $user_id
        ]);

        // Logo upload
        $logo_url = null;
        if (!empty($_FILES['receipt_logo']['name']) && $_FILES['receipt_logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['receipt_logo'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            if (in_array($ext, $allowed, true) && $file['size'] <= 5 * 1024 * 1024) {
                $upload_dir = __DIR__ . '/../uploads/logos/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $fname = 'receipt_logo_' . ($station_id > 0 ? "stn_{$station_id}_" : 'global_') . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $upload_dir . $fname)) {
                    $logo_rel = 'uploads/logos/' . $fname;
                    $upd_logo = $pdo->prepare("UPDATE receipt_config SET logo_path = ? WHERE station_id = ?");
                    $upd_logo->execute([$logo_rel, $station_id]);
                    $logo_url = '/group31petron_system_official4/' . $logo_rel;
                }
            }
        }

        // Audit log
        try {
            $al = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?,?,?,NOW())");
            $al->execute([$user_id, 'Receipt Config Updated', "Station $station_id: receipt template configuration saved by user $user_id."]);
        } catch (Throwable $e) {}

        echo json_encode(['success' => true, 'message' => 'Receipt template saved successfully.', 'logo_url' => $logo_url]);
    } catch (Exception $e) {
        error_log('Receipt config save error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: request_correction
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'request_correction') {
    $transaction_id   = safe_str('transaction_id', 100);
    $transaction_type = safe_str('transaction_type', 50);
    $correction_type  = safe_str('correction_type', 50);
    $reason           = safe_str('reason', 2000);

    if (empty($transaction_id) || empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'Transaction ID and reason are required.']);
        exit;
    }

    $allowed_corr_types = ['reissue', 'annotation', 'void_reissue'];
    if (!in_array($correction_type, $allowed_corr_types, true)) $correction_type = 'reissue';

    $allowed_txn_types = ['merchandise', 'job_order', 'combined', 'fuel'];
    if (!in_array($transaction_type, $allowed_txn_types, true)) $transaction_type = 'merchandise';

    try {
        // Ensure table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS receipt_corrections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                station_id INT NOT NULL DEFAULT 0,
                transaction_id VARCHAR(100) NOT NULL,
                transaction_type VARCHAR(50) NOT NULL DEFAULT 'merchandise',
                original_receipt_data LONGTEXT DEFAULT NULL,
                reason TEXT NOT NULL,
                correction_type VARCHAR(50) NOT NULL DEFAULT 'reissue',
                requested_by INT NOT NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                approved_by INT DEFAULT NULL,
                approved_at DATETIME DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                admin_notes TEXT DEFAULT NULL,
                reissued_reference VARCHAR(100) DEFAULT NULL,
                INDEX idx_station (station_id),
                INDEX idx_txn (transaction_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Check for duplicate pending request
        $chk = $pdo->prepare("
            SELECT COUNT(*) FROM receipt_corrections
            WHERE station_id = ? AND transaction_id = ? AND status = 'pending'
        ");
        $chk->execute([$station_id, $transaction_id]);
        if ((int)$chk->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Naay pending correction request na para sa transaction na ni.']);
            exit;
        }

        $ins = $pdo->prepare("
            INSERT INTO receipt_corrections
                (station_id, transaction_id, transaction_type, reason, correction_type, requested_by, requested_at, status)
            VALUES (?,?,?,?,?,?,NOW(),'pending')
        ");
        $ins->execute([$station_id, $transaction_id, $transaction_type, $reason, $correction_type, $user_id]);

        // Audit log
        try {
            $al = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?,?,?,NOW())");
            $al->execute([$user_id, 'Receipt Correction Requested', "Correction requested for transaction $transaction_id (type: $correction_type). Reason: $reason"]);
        } catch (Throwable $e) {}

        echo json_encode(['success' => true, 'message' => 'Correction request submitted. Admin will review.']);
    } catch (Exception $e) {
        error_log('Correction request error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: resolve_correction
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'resolve_correction') {
    if (!$can_approve) {
        echo json_encode(['success' => false, 'message' => 'Permission denied. Admin/Owner only.']);
        exit;
    }

    $correction_id = (int)($_POST['correction_id'] ?? 0);
    $resolution    = trim($_POST['resolution'] ?? '');

    if ($correction_id <= 0 || !in_array($resolution, ['approved', 'rejected'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        exit;
    }

    try {
        $upd = $pdo->prepare("
            UPDATE receipt_corrections
            SET status = ?, approved_by = ?, approved_at = NOW()
            WHERE id = ? AND station_id = ? AND status = 'pending'
        ");
        $upd->execute([$resolution, $user_id, $correction_id, $station_id]);

        if ($upd->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'Correction request not found or already resolved.']);
            exit;
        }

        // Audit log
        try {
            $al = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?,?,?,NOW())");
            $al->execute([$user_id, 'Receipt Correction ' . ucfirst($resolution), "Correction request #$correction_id was $resolution by user $user_id."]);
        } catch (Throwable $e) {}

        echo json_encode(['success' => true, 'message' => "Correction request #$correction_id $resolution successfully."]);
    } catch (Exception $e) {
        error_log('Correction resolve error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
    exit;
}

// ── Unknown action ─────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
