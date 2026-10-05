<?php
/**
 * Fuel Readings API
 */
// Buffer ALL output from the very start — prevents any PHP notices/warnings
// from corrupting the JSON response
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Start session exactly the same way login.php does
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';

// ── Fuel display name helper function ────────────────────────
function clean_fuel_display_name($fuel_type) {
    $name = trim((string)$fuel_type);
    // Remove pump/nozzle numbers pattern like "DIESEL 1 - 1" → "DIESEL"
    $name = preg_replace('/\s+\d+\s*-\s*\d+$/', '', $name); // Remove " 1 - 1", " 2 - 3" at end
    $name = preg_replace('/\s*-\s*\d+$/', '', $name); // Remove " - 1", " - 2" at end
    $name = trim($name);
    $normalized = strtoupper(preg_replace('/\s+/', ' ', $name));
    if (strpos($normalized, 'TURBO') !== false && strpos($normalized, 'DIESEL') !== false) {
        return 'Turbo Diesel';
    }
    if (strpos($normalized, 'KEROSENE') !== false) {
        return 'Kerosene';
    }
    if (strpos($normalized, 'XCS') !== false) {
        return 'XCS Plus';
    }
    if (strpos($normalized, 'XTRA') !== false && strpos($normalized, 'UNL') !== false) {
        return 'Xtra UNL';
    }
    if (strpos($normalized, 'DIESEL') !== false) {
        return 'Diesel';
    }
    return $name !== '' ? $name : 'Fuel';
}

function merge_clean_fuel_summary_rows(array $rows, bool $with_amount): array {
    $merged = [];
    foreach ($rows as $row) {
        $fuel = clean_fuel_display_name($row['fuel_type'] ?? '');
        if (!isset($merged[$fuel])) {
            $merged[$fuel] = ['fuel_type' => $fuel, 'volume_sales' => 0.0];
            if ($with_amount) {
                $merged[$fuel]['amount_sales'] = 0.0;
            }
        }
        $merged[$fuel]['volume_sales'] += (float)($row['volume_sales'] ?? 0);
        if ($with_amount) {
            $merged[$fuel]['amount_sales'] += (float)($row['amount_sales'] ?? 0);
        }
    }
    return array_values($merged);
}

// Clean any stray output
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ── Auth ──────────────────────────────────────────────────────────────────────
$me         = null;
$role       = '';
$station_id = null;

// Path 1: session cookie — login.php stores $_SESSION['user']
if (!empty($_SESSION['user'])) {
    $me         = $_SESSION['user'];
    $role       = role_key($me['role'] ?? '');
    $station_id = $me['station_id'] ?? null;
    try {
        $s = $pdo->prepare("SELECT station_id FROM users WHERE id = ? LIMIT 1");
        $s->execute([$me['id']]);
        $sid = $s->fetchColumn();
        if ($sid !== false) $station_id = $sid;
    } catch (Exception $e) {}
}

// Path 2: auth_user_id in POST/GET — DB lookup, no session needed.
if (!$me) {
    $posted_uid = (int)($_POST['auth_user_id'] ?? $_GET['auth_user_id'] ?? 0);
    if ($posted_uid > 0) {
        try {
            $u = $pdo->prepare("SELECT * FROM users WHERE id = ? AND (status = 'Active' OR status IS NULL) LIMIT 1");
            $u->execute([$posted_uid]);
            $db_user = $u->fetch(PDO::FETCH_ASSOC);
            if ($db_user) {
                unset($db_user['password_hash']);
                $me         = $db_user;
                $role       = role_key($me['role'] ?? '');
                $station_id = $me['station_id'] ?? null;
                try {
                    $sid_stmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ? LIMIT 1");
                    $sid_stmt->execute([$me['id']]);
                    $sid_val = $sid_stmt->fetchColumn();
                    if ($sid_val !== false) $station_id = $sid_val;
                } catch (Exception $e) {}
                $_SESSION['user']    = $me;
                $_SESSION['user_id'] = $me['id'];
                $_SESSION['role']    = $me['role'];
            }
        } catch (Exception $e) { /* fall through */ }
    }
}

if (!$me) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Schema migration ──────────────────────────────────────────
try {
    $existing = array_column(
        $pdo->query("SHOW COLUMNS FROM fuel_transactions")->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    $add = [
        'shift_period'  => "VARCHAR(50)  NULL",
        'shift_name'    => "VARCHAR(100) NULL",
        'shift_id'      => "INT          NULL",
        'notes'         => "TEXT         NULL",
        'status'        => "VARCHAR(50)  NULL DEFAULT 'Pending Validation'",
        'validated_by'  => "INT          NULL",
        'validated_at'  => "DATETIME     NULL",
        'reject_reason'      => "TEXT         NULL",
        'inventory_deducted' => "TINYINT(1)   NOT NULL DEFAULT 0",
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $existing)) {
            $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN `$col` $def");
        }
    }
    try {
        $pdo->exec("ALTER TABLE fuel_transactions MODIFY COLUMN `status` VARCHAR(50) NULL DEFAULT 'Pending Validation'");
    } catch (Exception $e2) {}
    try {
        $pdo->exec("ALTER TABLE fuel_transactions DROP FOREIGN KEY fk_ft_shift_id");
    } catch (Exception $e3) {}
} catch (Exception $e) {}

// ── Router ────────────────────────────────────────────────────
try {
    switch ($action) {

        case 'encode_reading':
            if ($method !== 'POST') respond(false, 'Method not allowed.');
            if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer']))
                respond(false, 'Unauthorized to encode readings.');

            $fuel_type    = trim($_POST['fuel_type']      ?? '');
            // pump_label: full formatted name e.g. "DIESEL 1 - 1" submitted by the form
            $pump_label   = strtoupper(trim($_POST['pump_label'] ?? ''));
            // NOTE: the form submits 'ending_reading' (the ending meter) and 'beginning_reading' (the beginning meter)
            $present_raw  = $_POST['ending_reading']   ?? $_POST['present_reading'] ?? '0';
            $present      = (float)str_replace(',', '', $present_raw);   // Ending meter (remove commas)
            $tanker_num   = (int)($_POST['tanker_number'] ?? 0);  // pump identifier for this fuel type
            $notes        = trim($_POST['notes'] ?? '');
            $reading_date = $_POST['reading_date']  ?? date('Y-m-d');
            $shift_period = trim($_POST['shift_period'] ?? '');
            $shift_name   = trim($_POST['shift_name']   ?? '');
            $shift_id     = (int)($_POST['shift_id'] ?? 0) ?: null;

            $posted_sid = (int)($_POST['station_id'] ?? 0);
            if ($posted_sid > 0) {
                $station_id = $posted_sid;
            } elseif (empty($station_id)) {
                $station_id = (int)(user_station_id() ?: 1253);
            }

            if (empty($fuel_type)) respond(false, 'Fuel type is required.');
            if ($present  <= 0)   respond(false, 'Ending meter reading must be greater than 0.');
            if ($tanker_num <= 0) respond(false, 'Tanker/pump number is required.');

            // ── Beginning (Previous) reading — pump-specific for 24-hour continuous cycle ──
            // Form posts 'beginning_reading'; fallback fetches last present_reading for same pump.
            $previous = 0.0;
            if (isset($_POST['beginning_reading']) && $_POST['beginning_reading'] !== '') {
                $previous = (float)str_replace(',', '', $_POST['beginning_reading']);
            } elseif (isset($_POST['previous_reading']) && $_POST['previous_reading'] !== '') {
                $previous = (float)str_replace(',', '', $_POST['previous_reading']);
            } else {
                try {
                    // Bulletproof: match pump_id whether stored as tanker_num OR resolved fuel_pumps PK
                    $prev_stmt = $pdo->prepare("
                        SELECT present_reading FROM fuel_transactions
                        WHERE station_id = ?
                          AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                          AND (
                            pump_id = ?
                            OR pump_id = (
                                SELECT fp.id FROM fuel_pumps fp
                                JOIN fuel_types ftp ON ftp.id = fp.fuel_type_id
                                WHERE fp.station_id = ?
                                  AND fp.pump_number = ?
                                  AND LOWER(TRIM(ftp.name)) = LOWER(TRIM(?))
                                LIMIT 1
                            )
                          )
                          AND LOWER(COALESCE(status, '')) NOT IN ('voided', 'rejected', 'cancelled', 'canceled')
                        ORDER BY transaction_date DESC, id DESC LIMIT 1
                    ");
                    $prev_stmt->execute([
                        $station_id, $fuel_type, $tanker_num,
                        $station_id, (string)$tanker_num, $fuel_type
                    ]);
                    $row_prev = $prev_stmt->fetchColumn();
                    if ($row_prev !== false) $previous = (float)$row_prev;
                } catch (Exception $e) {}
            }

            // ── Re-pull calibration from DB ──
            // Priority: fuel_calibration_records table (technician record) → fuel_inventory.latest_calibration
            // Staff may override by editing the calibration input
            $calibration = 0.0;
            try {
                $cal_stmt = $pdo->prepare("
                    SELECT calibration_liters FROM fuel_calibration_records
                    WHERE LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                      AND station_id = ?
                      AND LOWER(TRIM(status)) = 'active'
                    ORDER BY calibration_date DESC, id DESC LIMIT 1
                ");
                $cal_stmt->execute([$fuel_type, $station_id]);
                $cal_row = $cal_stmt->fetchColumn();
                if ($cal_row !== false && $cal_row !== null) {
                    $calibration = (float)$cal_row;
                }
            } catch (Exception $e) {}

            if ($calibration == 0.0) {
                // Fallback: fuel_inventory.latest_calibration
                try {
                    $cal2 = $pdo->prepare("
                        SELECT COALESCE(latest_calibration, 0)
                        FROM fuel_inventory
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                        LIMIT 1
                    ");
                    $cal2->execute([$station_id, $fuel_type]);
                    $calibration = (float)($cal2->fetchColumn() ?: 0);
                } catch (Exception $e) {}
            }

            // Staff override: if they edited the calibration field, use that value
            if (isset($_POST['calibration']) && $_POST['calibration'] !== '') {
                $calibration = (float)str_replace(',', '', $_POST['calibration']);
            }

            // ── Price — always fetched strictly from fuel_inventory (staff cannot override)
            $price = 0.0;
            try {
                $pr_inv = $pdo->prepare("
                    SELECT COALESCE(price_per_liter, 0)
                    FROM fuel_inventory
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    LIMIT 1
                ");
                $pr_inv->execute([$station_id, $fuel_type]);
                $price = (float)($pr_inv->fetchColumn() ?: 0);
            } catch (Exception $e) {}


            // ── Price = 0: allow submission, record reading with ₱0 amount ──
            // Staff can still record meter readings even if price isn't configured yet.
            // Manager can adjust/approve with correct price later.
            // Do NOT block the submission — just record with 0 amount.
            $price_missing = ($price <= 0);
            if ($price_missing) $price = 0.0;

            // ── Detect shift using fixed schedule rules ──
            // Shift 1 (first)  → 6:00 AM – 2:00 PM  (06:00:00–13:59:59)
            // Shift 2 (second) → 2:00 PM – 12:00 MN  (14:00:00–23:59:59)
            // Early morning (00:00–05:59) → Shift 2 (previous night's shift)
            if (empty($shift_period)) {
                $ct = date('H:i:s');
                $auto_sk = ($ct >= '06:00:00' && $ct < '14:00:00') ? 'first' : 'second';

                try {
                    $sp_stmt = $pdo->prepare("SELECT shift_key, shift_name FROM shift_periods WHERE shift_key = ? AND is_active = 1 LIMIT 1");
                    $sp_stmt->execute([$auto_sk]);
                    $sp_row = $sp_stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) { $sp_row = null; }

                if ($sp_row) {
                    $shift_period = $sp_row['shift_key'];
                    if (empty($shift_name)) $shift_name = $sp_row['shift_name'];
                } else {
                    $shift_period = $auto_sk;
                    if (empty($shift_name)) {
                        $shift_name = ($auto_sk === 'first')
                            ? 'Shift 1 (6:00 AM - 2:00 PM)'
                            : 'Shift 2 (2:00 PM - 12:00 MN)';
                    }
                }
            }
            // Final fallback — shift_period is NOT NULL in DB
            if (empty($shift_period)) $shift_period = 'second';

            // ── Validation Rule 1: Ending must be ≥ Beginning ──
            if ($present < $previous) {
                respond(false, "Ending Reading cannot be lower than Beginning Reading.");
            }

            // ── Validation Rule 2: Calibration must be ≥ 0 ──
            if ($calibration < 0) {
                respond(false, "Calibration cannot be negative. Value entered: {$calibration}.");
            }

            // ── Compute Gross Volume first ──
            $gross_volume = round($present - $previous, 4);   // Ending − Beginning

            // ── Validation Rule 3: Calibration cannot exceed Gross Volume ──
            if ($gross_volume > 0 && $calibration > $gross_volume) {
                respond(false, "Calibration ({$calibration}L) cannot be greater than Gross Volume ({$gross_volume}L).");
            }

            // ── Formula: Net Volume = Gross Volume − Calibration ──
            $liters_sold  = round($gross_volume - $calibration, 4);
            if ($liters_sold < 0) $liters_sold = 0.0;   // safety floor

            // ── Formula: Total Amount = Net Volume × Price per Liter ──
            $total_amount = round($liters_sold * $price, 2);


            // ── Generate transaction ID ──
            $txn_id = 'FUEL' . date('Y')
                    . str_pad($station_id, 3, '0', STR_PAD_LEFT)
                    . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

            // ── Ensure required columns exist and are wide enough ────────────
            try {
                $cols = array_column(
                    $pdo->query("SHOW COLUMNS FROM fuel_transactions")->fetchAll(PDO::FETCH_ASSOC),
                    'Field'
                );
                foreach (['shift_period','shift_name','shift_id','notes','status'] as $rc) {
                    if (!in_array($rc, $cols)) {
                        $def = ($rc === 'status') ? "VARCHAR(50) NULL DEFAULT 'Pending Validation'" : "TEXT NULL";
                        $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN `$rc` $def");
                    }
                }
                // Widen shift_period if it's still varchar(20) — shift keys can be longer
                $sp_col = $pdo->query("SHOW COLUMNS FROM fuel_transactions WHERE Field='shift_period'")->fetch(PDO::FETCH_ASSOC);
                if ($sp_col && preg_match('/varchar\((\d+)\)/i', $sp_col['Type'], $m) && (int)$m[1] < 50) {
                    $pdo->exec("ALTER TABLE fuel_transactions MODIFY COLUMN `shift_period` VARCHAR(50) NULL");
                }
                // Widen payment_method if needed
                $pm_col = $pdo->query("SHOW COLUMNS FROM fuel_transactions WHERE Field='payment_method'")->fetch(PDO::FETCH_ASSOC);
                if ($pm_col && preg_match('/varchar\((\d+)\)/i', $pm_col['Type'], $m) && (int)$m[1] < 50) {
                    $pdo->exec("ALTER TABLE fuel_transactions MODIFY COLUMN `payment_method` VARCHAR(50) NULL");
                }
            } catch (Exception $e) {}

            $shift_id = (int)($_POST['shift_id'] ?? 0);
            if ($shift_id > 0) {
                try {
                    $chk = $pdo->prepare("SELECT id FROM shifts WHERE id = ?");
                    $chk->execute([$shift_id]);
                    if (!$chk->fetchColumn()) {
                        $shift_id = null; // Invalid shift_id (e.g. from labor_sessions instead of shifts), fallback to true NULL
                    }
                } catch (Exception $e) { $shift_id = null; }
            } else {
                $shift_id = null;
            }

            // Ensure NOT NULL columns have fallback values
            $shift_period_safe   = substr(!empty($shift_period) ? $shift_period : 'general', 0, 50);
            $shift_name_safe     = substr($shift_name ?? '', 0, 100);
            $payment_method_safe = 'Internal';

            // ── Resolve actual pump_id from fuel_pumps table ──────────────────────────
            // pump_label (e.g. "DIESEL 1 - 1") is the formatted name submitted by the form.
            // Priority: match by pump_label against pump_number in fuel_pumps.
            $resolved_pump_id = null;
            $actual_pump_name = !empty($pump_label) ? $pump_label : $fuel_type; // Default to pump_label or fuel_type
            try {
                if (!empty($pump_label)) {
                    // Match directly by the exact pump_number label
                    $pump_stmt = $pdo->prepare("
                        SELECT id, pump_number FROM fuel_pumps
                        WHERE station_id = ? AND UPPER(TRIM(pump_number)) = ?
                        LIMIT 1
                    ");
                    $pump_stmt->execute([$station_id, $pump_label]);
                    $pump_row = $pump_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($pump_row) {
                        $resolved_pump_id = (int)$pump_row['id'];
                        $actual_pump_name = $pump_row['pump_number'];
                    }
                }
            } catch (Exception $e) {}

            // Fallback: match by fuel_type name + tanker_number via fuel_types join
            if ($resolved_pump_id === null) {
                try {
                    $pump_stmt2 = $pdo->prepare("
                        SELECT fp.id, fp.pump_number FROM fuel_pumps fp
                        JOIN fuel_types ft ON ft.id = fp.fuel_type_id
                        WHERE fp.station_id = ?
                          AND fp.pump_number = ?
                          AND LOWER(TRIM(ft.name)) = LOWER(TRIM(?))
                        LIMIT 1
                    ");
                    $pump_stmt2->execute([$station_id, (string)$tanker_num, $fuel_type]);
                    $pump_row2 = $pump_stmt2->fetch(PDO::FETCH_ASSOC);
                    if ($pump_row2) {
                        $resolved_pump_id = (int)$pump_row2['id'];
                        $actual_pump_name = $pump_row2['pump_number'];
                    }
                } catch (Exception $e) {}
            }

            // Use the resolved pump name for saving — guarantees specific label is stored
            $fuel_type_to_save = $actual_pump_name;
            // $resolved_pump_id is NULL if no match — FK allows NULL (ON DELETE SET NULL)

            // Use the resolved pump name for saving — guarantees specific label is stored
            $fuel_type_to_save = $actual_pump_name;
            // $resolved_pump_id is NULL if no match — FK allows NULL (ON DELETE SET NULL)

            try {
                $pdo->beginTransaction();

                // Check for existing reading for same station, date, shift, and pump/fuel_type
                $existing_tx_id = null;
                try {
                    $chk_tx = $pdo->prepare("
                        SELECT id FROM fuel_transactions
                        WHERE station_id = ?
                          AND DATE(transaction_date) = ?
                          AND shift_period = ?
                          AND (
                            (pump_id IS NOT NULL AND pump_id = ?)
                            OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                          )
                          AND LOWER(COALESCE(status, '')) NOT IN ('rejected','voided','cancelled','canceled')
                        ORDER BY id DESC LIMIT 1
                    ");
                    $chk_tx->execute([$station_id, $reading_date, $shift_period_safe, $resolved_pump_id, $fuel_type_to_save]);
                    $existing_tx_id = $chk_tx->fetchColumn();
                } catch (Exception $e) {}

                $is_admin_user = in_array($role, ['admin', 'superadmin', 'developer']);
                // Meter readings are Step 1. Both admin and staff readings start as READINGS_SUBMITTED so they do not leak into the Fuel Sales Report before Fuel Sales Closing is completed.
                // If closing was already previously verified for this shift/date, keep/set as Verified for admin.
                $reading_status = 'READINGS_SUBMITTED';
                if ($is_admin_user) {
                    try {
                        $chk_cls_status = $pdo->prepare("
                            SELECT status FROM fuel_sales_closing 
                            WHERE station_id = ? AND report_date = ? AND (shift = ? OR shift_period = ?)
                            LIMIT 1
                        ");
                        $chk_cls_status->execute([$station_id, $reading_date, $shift_name_safe, $shift_period_safe]);
                        $cls_stat = strtoupper(trim($chk_cls_status->fetchColumn() ?: ''));
                        if (in_array($cls_stat, ['VERIFIED', 'APPROVED', 'VALIDATED'])) {
                            $reading_status = 'Verified';
                        }
                    } catch (Exception $e) {}
                }

                if ($existing_tx_id) {
                    $pdo->prepare("
                        UPDATE fuel_transactions SET
                            present_reading = ?,
                            previous_reading = ?,
                            calibration = ?,
                            staff_calibration = ?,
                            liters_sold = ?,
                            price_per_liter = ?,
                            total_amount = ?,
                            payment_method = ?,
                            staff_id = ?,
                            notes = ?,
                            status = ?,
                            inventory_deducted = 1,
                            validated_by = CASE WHEN ? THEN ? ELSE validated_by END,
                            validated_at = CASE WHEN ? THEN NOW() ELSE validated_at END
                        WHERE id = ?
                    ")->execute([
                        $present, $previous, $calibration, $calibration,
                        $liters_sold, $price, $total_amount,
                        $payment_method_safe, $me['id'], $notes,
                        $reading_status,
                        $is_admin_user ? 1 : 0, $me['id'],
                        $is_admin_user ? 1 : 0,
                        $existing_tx_id
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO fuel_transactions
                            (transaction_id, station_id, fuel_type, pump_id,
                             present_reading, previous_reading, calibration, staff_calibration,
                             liters_sold, price_per_liter, total_amount,
                             payment_method, staff_id, transaction_date,
                             shift_period, shift_name, shift_id, notes, status, inventory_deducted,
                             validated_by, validated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?, ?)
                    ")->execute([
                        $txn_id, $station_id, $fuel_type_to_save, $resolved_pump_id,
                        $present, $previous, $calibration, $calibration,
                        $liters_sold, $price, $total_amount,
                        $payment_method_safe, $me['id'], $reading_date . ' ' . date('H:i:s'),
                        $shift_period_safe, $shift_name_safe, $shift_id, $notes,
                        $reading_status, 1,
                        $is_admin_user ? $me['id'] : null,
                        $is_admin_user ? date('Y-m-d H:i:s') : null
                    ]);
                }

                // ── Upsert stub in fuel_sales_closing ──────────────────────────────────
                // Readings submission is NOT a completed closing — status must remain READINGS_SUBMITTED until closing form is actually saved
                $closing_stub_status = 'READINGS_SUBMITTED';
                try {
                    $chk_cls = $pdo->prepare("
                        SELECT id, status FROM fuel_sales_closing
                        WHERE station_id = ? AND report_date = ? AND (shift = ? OR shift_period = ?)
                        LIMIT 1
                    ");
                    $chk_cls->execute([$station_id, $reading_date, $shift_name_safe, $shift_period_safe]);
                    $cls_row = $chk_cls->fetch(PDO::FETCH_ASSOC);

                    if ($cls_row) {
                        if (($cls_row['status'] ?? '') !== 'CLOSING_COMPLETED' && ($cls_row['status'] ?? '') !== 'REPORTED') {
                            $pdo->prepare("
                                UPDATE fuel_sales_closing
                                SET status = ?, shift = ?, shift_period = ?
                                WHERE id = ?
                            ")->execute([$closing_stub_status, $shift_name_safe, $shift_period_safe, $cls_row['id']]);
                        }
                    } else {
                        $pdo->prepare("
                            INSERT INTO fuel_sales_closing
                                (station_id, report_date, shift, shift_period, status, encoded_by, encoded_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ")->execute([$station_id, $reading_date, $shift_name_safe, $shift_period_safe, $closing_stub_status, $me['id']]);
                    }
                } catch (Exception $e_cls) {}

                // ── Deduct liters_sold from fuel_inventory automatically ───────────
                try {
                    if ($liters_sold > 0) {
                        // If updating an existing reading, only deduct the difference
                        $old_liters = 0.0;
                        if ($existing_tx_id) {
                            $old_stmt = $pdo->prepare("SELECT liters_sold, inventory_deducted FROM fuel_transactions WHERE id = ?");
                            $old_stmt->execute([$existing_tx_id]);
                            $old_row = $old_stmt->fetch(PDO::FETCH_ASSOC);
                            if ($old_row && !empty($old_row['inventory_deducted'])) {
                                $old_liters = (float)($old_row['liters_sold'] ?? 0);
                            }
                        }
                        $deduct_amount = $liters_sold - $old_liters; // net change

                        if (abs($deduct_amount) > 0.0001) {
                            deduct_fuel_inventory_stock($pdo, (int)$station_id, $fuel_type_to_save, $fuel_type, $deduct_amount, (int)$me['id']);
                        }
                    }
                } catch (Exception $e) {
                    error_log('Fuel inventory deduction error: ' . $e->getMessage());
                }

                $pdo->commit();
            } catch (Exception $insertEx) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                respond(false, 'Database error saving reading: ' . $insertEx->getMessage());
            }

            try {
                log_activity($pdo, $me['id'], 'Fuel Reading Encoded',
                    "{$fuel_type} Pump#{$tanker_num}: Ending={$present}, Beginning={$previous}, Calib={$calibration}, Volume={$liters_sold}L, Amount=₱{$total_amount}");
            } catch (Exception $e) {}

                        // ── Notify all managers at this station (1 Consolidated Notification Per Shift) ──
            try {
                $staff_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? $me['username'] ?? 'Staff');
                $shift_lbl  = !empty($shift_period_safe) ? (ucfirst($shift_period_safe) . ' Shift') : 'First Shift';
                $date_lbl   = date('M d, Y', strtotime($reading_date));
                $notif_msg  = "{$staff_name} submitted Fuel Readings for {$date_lbl} ({$shift_lbl}). Ready for manager validation.";
                $source_key = 'mgr_fuel_shift_' . $station_id . '_' . date('Ymd', strtotime($reading_date)) . '_' . strtolower($shift_period_safe);

                $mgr_stmt = $pdo->prepare("
                    SELECT id FROM users
                    WHERE station_id = ?
                      AND LOWER(TRIM(role)) IN ('manager','admin')
                      AND (is_active = 1 OR status = 'active')
                ");
                $mgr_stmt->execute([$station_id]);
                $mgr_ids = $mgr_stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($mgr_ids as $mgr_id) {
                    try {
                        $ins_notif = $pdo->prepare("
                            INSERT INTO notifications (user_id, type, event_type, severity, title, message, source_key, redirect_url, status, created_at)
                            VALUES (?, 'info', 'transaction', 'high', 'New Fuel Reading — Pending Validation', ?, ?, 'manager_fuel_transaction_validation.php', 'unread', NOW())
                            ON DUPLICATE KEY UPDATE title = VALUES(title), message = VALUES(message), redirect_url = VALUES(redirect_url)
                        ");
                        $ins_notif->execute([$mgr_id, $notif_msg, $source_key]);
                    } catch (Exception $ne) {}
                }
            } catch (Exception $e) {}

            $success_msg = $is_admin_user
                ? 'Entry submitted and verified successfully.'
                : 'Entry submitted successfully. Pending Manager validation.';
            respond(true, $success_msg, [
                'transaction_id'  => $txn_id,
                'previous_reading'=> $previous,
                'calibration'     => $calibration,
                'liters_sold'     => $liters_sold,
                'price_per_liter' => $price,
                'total_amount'    => $total_amount,
                'price_missing'   => $price_missing ?? false,
            ]);
            break;

        case 'reset_shift':
            if ($method !== 'POST') respond(false, 'Method not allowed.');
            $shift_period = trim($_POST['shift_period'] ?? $_GET['shift_period'] ?? '');
            $reading_date = trim($_POST['reading_date'] ?? $_GET['reading_date'] ?? date('Y-m-d'));

            // Refund any transactions being deleted that were deducted from inventory
            try {
                $tx_refund = $pdo->prepare("
                    SELECT fuel_type, liters_sold FROM fuel_transactions
                    WHERE station_id = ?
                      AND DATE(transaction_date) = ?
                      AND (shift_period = ? OR shift_name = ? OR ? = '')
                      AND LOWER(COALESCE(status, '')) NOT IN ('closing_completed', 'saved', 'reported', 'verified', 'approved')
                      AND inventory_deducted = 1
                ");
                $tx_refund->execute([$station_id, $reading_date, $shift_period, $shift_period, $shift_period]);
                foreach ($tx_refund->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                    if ((float)$tr['liters_sold'] > 0) {
                        refund_fuel_inventory_stock($pdo, (int)$station_id, $tr['fuel_type'], $tr['fuel_type'], (float)$tr['liters_sold'], (int)($me['id'] ?? 0));
                    }
                }
            } catch (Exception $e_ref) {}

            // Delete unclosed/draft transactions for this station and shift so inputs reset cleanly
            $del = $pdo->prepare("
                DELETE FROM fuel_transactions
                WHERE station_id = ?
                  AND DATE(transaction_date) = ?
                  AND (shift_period = ? OR shift_name = ? OR ? = '')
                  AND LOWER(COALESCE(status, '')) NOT IN ('closing_completed', 'saved', 'reported', 'verified', 'approved')
            ");
            $del->execute([$station_id, $reading_date, $shift_period, $shift_period, $shift_period]);

            respond(true, 'Shift readings reset successfully.');
            break;

        case 'get_pending':
            if (!in_array($role, ['manager','admin','superadmin'])) respond(false, 'Manager access required.');
            $date  = $_GET['date']  ?? date('Y-m-d');
            $shift = $_GET['shift'] ?? '';
            $sql   = "SELECT ft.*, u.name AS staff_name FROM fuel_transactions ft
                      LEFT JOIN users u ON ft.staff_id=u.id
                      WHERE ft.station_id=? AND DATE(ft.transaction_date)=? AND ft.status='Pending Validation'";
            $params = [$station_id, $date];
            if ($shift) { $sql .= " AND ft.shift_period=?"; $params[] = $shift; }
            $sql .= " ORDER BY ft.transaction_date ASC";
            $rows = $pdo->prepare($sql);
            $rows->execute($params);
            respond(true, '', ['readings' => $rows->fetchAll(PDO::FETCH_ASSOC)]);

        case 'validate_reading':
            if ($method !== 'POST') respond(false, 'Method not allowed.');
            if (!in_array($role, ['manager','admin','superadmin'])) respond(false, 'Manager access required.');
            $txn_id        = trim($_POST['transaction_id'] ?? '');
            $new_status    = $_POST['status'] ?? '';
            $reject_reason = trim($_POST['reject_reason'] ?? '');
            if (!in_array($new_status, ['Approved','Rejected'])) respond(false, 'Status must be Approved or Rejected.');
            $row = $pdo->prepare("SELECT * FROM fuel_transactions WHERE transaction_id=? AND station_id=? AND status='Pending Validation'");
            $row->execute([$txn_id, $station_id]);
            $txn = $row->fetch(PDO::FETCH_ASSOC);
            if (!$txn) respond(false, 'Transaction not found or already processed.');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE fuel_transactions SET status=?,validated_by=?,validated_at=NOW(),reject_reason=? WHERE transaction_id=? AND station_id=?")
                ->execute([$new_status, $me['id'], $reject_reason ?: null, $txn_id, $station_id]);
            if ($new_status === 'Approved') {
                try {
                    $pdo->prepare("
                        UPDATE fuel_inventory
                        SET current_level = GREATEST(0, COALESCE(current_level, current_stock, 0) - ?),
                            current_stock = GREATEST(0, COALESCE(current_stock, current_level, 0) - ?),
                            last_updated  = NOW()
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    ")->execute([$txn['liters_sold'], $txn['liters_sold'], $station_id, $txn['fuel_type']]);
                } catch (Exception $e) {}
            }
            $pdo->commit();
            log_activity($pdo, $me['id'], "Fuel Reading {$new_status}", "TXN {$txn_id} | {$txn['fuel_type']} | {$txn['liters_sold']} L");
            if ($new_status === 'Approved') {
                respond(true, "Transaction approved successfully. Entry saved to Daily Sales Summary.");
            } else {
                respond(true, "Reading {$new_status}.");
            }

        case 'my_entries':
            $date = $_GET['date'] ?? date('Y-m-d');
            $rows = $pdo->prepare("SELECT transaction_id,fuel_type,present_reading,previous_reading,calibration,liters_sold,price_per_liter,total_amount,shift_period,shift_name,status,transaction_date,notes FROM fuel_transactions WHERE station_id=? AND staff_id=? AND DATE(transaction_date)=? ORDER BY transaction_date DESC");
            $rows->execute([$station_id, $me['id'], $date]);
            respond(true, '', ['entries' => $rows->fetchAll(PDO::FETCH_ASSOC)]);

        // ══════════════════════════════════════════════════════════════════════
        // SUMMARY: 3-table fuel sales summary (manager + staff view)
        // Returns: meter_readings, vol_sales_summary, vol_amt_summary
        // ══════════════════════════════════════════════════════════════════════
        case 'summary':
            $date_from   = $_GET['date_from']   ?? date('Y-m-d');
            $date_to     = $_GET['date_to']     ?? date('Y-m-d');
            $shift       = $_GET['shift']       ?? '';
            $fuel_type   = $_GET['fuel_type']   ?? '';
            $staff_id    = $_GET['staff_id']    ?? '';
            $status      = $_GET['status']      ?? '';

            // Validate dates
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

            // Staff can see all entries for their station (e.g. to see other shifts or what was recorded by others at the station)
            $is_manager = in_array($role, ['manager', 'admin', 'superadmin']);

            $base_where  = "ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ?
                            AND LOWER(COALESCE(ft.status, '')) NOT IN ('voided','rejected','cancelled','canceled')";
            $base_params = [$station_id, $date_from, $date_to];

            // Additional filters
            if ($shift) {
                $base_where   .= " AND ft.shift_period = ?";
                $base_params[] = $shift;
            }
            if ($fuel_type) {
                $base_where   .= " AND LOWER(TRIM(ft.fuel_type)) = LOWER(TRIM(?))";
                $base_params[] = $fuel_type;
            }
            if ($staff_id && $is_manager) {
                $base_where   .= " AND ft.staff_id = ?";
                $base_params[] = (int)$staff_id;
            }
            if ($status) {
                $base_where   .= " AND LOWER(ft.status) = LOWER(?)";
                $base_params[] = $status;
            }

            // ── TABLE 1: Meter Reading Table (raw per-transaction rows) ──────
            $mr_sql = "
                SELECT
                    ft.transaction_id,
                    ft.pump_id,
                    fp.pump_number,
                    ft.fuel_type,
                    ft.previous_reading   AS beginning,
                    ft.present_reading    AS ending,
                    ft.calibration        AS cal,
                    ft.liters_sold        AS volume_liters,
                    ft.price_per_liter,
                    ft.total_amount       AS amount,
                    ft.shift_period,
                    ft.shift_name,
                    DATE(ft.transaction_date) AS reading_date,
                    ft.transaction_date,
                    ft.notes,
                    ft.reject_reason,
                    CONCAT(u.first_name, ' ', u.last_name) AS staff_name,
                    ft.status,
                    ft.validated_at,
                    CONCAT(vm.first_name, ' ', vm.last_name) AS validated_by_name
                FROM fuel_transactions ft
                LEFT JOIN users u        ON ft.staff_id    = u.id
                LEFT JOIN users vm       ON ft.validated_by = vm.id
                LEFT JOIN fuel_pumps fp  ON ft.pump_id     = fp.id
                WHERE {$base_where}
                ORDER BY
                    DATE(ft.transaction_date) DESC,
                    ft.shift_period DESC,
                    CASE
                        WHEN TRIM(UPPER(ft.fuel_type)) = 'DIESEL'                                          THEN 1
                        WHEN UPPER(ft.fuel_type) LIKE 'DIESEL 1%' OR UPPER(ft.fuel_type) LIKE '%DIESEL 1%' THEN 2
                        WHEN UPPER(ft.fuel_type) LIKE 'DIESEL 2%' OR UPPER(ft.fuel_type) LIKE '%DIESEL 2%' THEN 3
                        WHEN UPPER(ft.fuel_type) LIKE '%TURBO%DIESEL%'                                      THEN 4
                        WHEN UPPER(ft.fuel_type) LIKE '%KEROSENE%'                                          THEN 5
                        WHEN UPPER(ft.fuel_type) LIKE '%XCS%PLUS%' OR UPPER(ft.fuel_type) LIKE 'XCS PLUS%' THEN 6
                        WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%1%'                                       THEN 7
                        WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%2%'                                       THEN 8
                        WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%'                                         THEN 9
                        WHEN UPPER(ft.fuel_type) LIKE '%DIESEL%'                                           THEN 10
                        ELSE 99
                    END ASC,
                    ft.fuel_type ASC,
                    fp.pump_number ASC,
                    ft.id ASC
            ";
            $mr_stmt = $pdo->prepare($mr_sql);
            $mr_stmt->execute($base_params);
            $meter_readings = $mr_stmt->fetchAll(PDO::FETCH_ASSOC);
            // Clean fuel names
            foreach ($meter_readings as &$row) {
                $row['fuel_type'] = clean_fuel_display_name($row['fuel_type'] ?? '');
            }
            unset($row);

            // ── TABLE 2: Volume Sales Summary (liters per fuel type) ─────────
            $vs_sql = "
                SELECT
                    ft.fuel_type,
                    SUM(ft.liters_sold) AS volume_sales
                FROM fuel_transactions ft
                WHERE {$base_where}
                GROUP BY ft.fuel_type
                ORDER BY ft.fuel_type ASC
            ";
            $vs_stmt = $pdo->prepare($vs_sql);
            $vs_stmt->execute($base_params);
            $vol_sales_summary = merge_clean_fuel_summary_rows($vs_stmt->fetchAll(PDO::FETCH_ASSOC), false);

            // ── TABLE 3: Volume & Amount Summary (liters + amount per fuel type) ──
            $va_sql = "
                SELECT
                    ft.fuel_type,
                    SUM(ft.liters_sold)  AS volume_sales,
                    SUM(ft.total_amount) AS amount_sales
                FROM fuel_transactions ft
                WHERE {$base_where}
                GROUP BY ft.fuel_type
                ORDER BY ft.fuel_type ASC
            ";
            $va_stmt = $pdo->prepare($va_sql);
            $va_stmt->execute($base_params);
            $vol_amt_summary = merge_clean_fuel_summary_rows($va_stmt->fetchAll(PDO::FETCH_ASSOC), true);

            // ── Totals ────────────────────────────────────────────────────────
            $total_liters = array_sum(array_column($vol_amt_summary, 'volume_sales'));
            $total_amount = array_sum(array_column($vol_amt_summary, 'amount_sales'));

            respond(true, '', [
                'meter_readings'    => $meter_readings,
                'vol_sales_summary' => $vol_sales_summary,
                'vol_amt_summary'   => $vol_amt_summary,
                'totals'            => [
                    'total_liters' => round($total_liters, 2),
                    'total_amount' => round($total_amount, 2),
                ],
                'filters' => [
                    'date_from' => $date_from,
                    'date_to'   => $date_to,
                    'shift'     => $shift,
                    'fuel_type' => $fuel_type,
                    'staff_id'  => $staff_id,
                    'status'    => $status,
                ],
            ]);

        // ══════════════════════════════════════════════════════════════════════
        // DAILY SALES REPORT — per shift, for a given date
        // ══════════════════════════════════════════════════════════════════════
        case 'daily_report':
            $date  = $_GET['date']  ?? date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
            $is_manager = in_array($role, ['manager','admin','superadmin']);

            $dr_where  = "ft.station_id = ? AND DATE(ft.transaction_date) = ?";
            $dr_params = [$station_id, $date];

            // Per-shift breakdown
            $shift_sql = "
                SELECT
                    COALESCE(ft.shift_name, ft.shift_period, 'Unknown Shift') AS shift_label,
                    ft.fuel_type,
                    MIN(ft.previous_reading)  AS beginning_reading,
                    MAX(ft.present_reading)   AS ending_reading,
                    SUM(ft.calibration)       AS total_calibration,
                    SUM(ft.liters_sold)       AS total_liters,
                    AVG(ft.price_per_liter)   AS avg_price,
                    SUM(ft.total_amount)      AS total_amount,
                    COUNT(*)                  AS entry_count,
                    GROUP_CONCAT(DISTINCT CONCAT(u.first_name,' ',u.last_name) ORDER BY u.first_name SEPARATOR ', ') AS staff_names
                FROM fuel_transactions ft
                LEFT JOIN users u ON ft.staff_id = u.id
                WHERE {$dr_where}
                GROUP BY ft.shift_period, ft.shift_name, ft.fuel_type
                ORDER BY
                    ft.shift_period ASC,
                    CASE
                        WHEN TRIM(UPPER(ft.fuel_type)) = 'DIESEL'                                          THEN 1
                        WHEN UPPER(ft.fuel_type) LIKE 'DIESEL 1%' OR UPPER(ft.fuel_type) LIKE '%DIESEL 1%' THEN 2
                        WHEN UPPER(ft.fuel_type) LIKE 'DIESEL 2%' OR UPPER(ft.fuel_type) LIKE '%DIESEL 2%' THEN 3
                        WHEN UPPER(ft.fuel_type) LIKE '%TURBO%DIESEL%'                                      THEN 4
                        WHEN UPPER(ft.fuel_type) LIKE '%KEROSENE%'                                          THEN 5
                        WHEN UPPER(ft.fuel_type) LIKE '%XCS%PLUS%' OR UPPER(ft.fuel_type) LIKE 'XCS PLUS%' THEN 6
                        WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%1%'                                       THEN 7
                        WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%2%'                                       THEN 8
                        WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%'                                         THEN 9
                        WHEN UPPER(ft.fuel_type) LIKE '%DIESEL%'                                           THEN 10
                        ELSE 99
                    END ASC,
                    ft.fuel_type ASC
            ";
            $shift_stmt = $pdo->prepare($shift_sql);
            $shift_stmt->execute($dr_params);
            $shift_rows = $shift_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Day totals
            $day_sql = "
                SELECT ft.fuel_type,
                    MIN(ft.previous_reading) AS beginning_reading,
                    MAX(ft.present_reading)  AS ending_reading,
                    SUM(ft.liters_sold)      AS total_liters,
                    SUM(ft.total_amount)     AS total_amount
                FROM fuel_transactions ft
                WHERE {$dr_where}
                GROUP BY ft.fuel_type
                ORDER BY
                    CASE
                        WHEN TRIM(UPPER(ft.fuel_type)) = 'DIESEL'                                          THEN 1
                        WHEN UPPER(ft.fuel_type) LIKE 'DIESEL 1%' OR UPPER(ft.fuel_type) LIKE '%DIESEL 1%' THEN 2
                        WHEN UPPER(ft.fuel_type) LIKE 'DIESEL 2%' OR UPPER(ft.fuel_type) LIKE '%DIESEL 2%' THEN 3
                        WHEN UPPER(ft.fuel_type) LIKE '%TURBO%DIESEL%'                                      THEN 4
                        WHEN UPPER(ft.fuel_type) LIKE '%KEROSENE%'                                          THEN 5
                        WHEN UPPER(ft.fuel_type) LIKE '%XCS%PLUS%' OR UPPER(ft.fuel_type) LIKE 'XCS PLUS%' THEN 6
                        WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%1%'                                       THEN 7
                        WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%2%'                                       THEN 8
                        WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%'                                         THEN 9
                        WHEN UPPER(ft.fuel_type) LIKE '%DIESEL%'                                           THEN 10
                        ELSE 99
                    END ASC,
                    ft.fuel_type ASC
            ";
            $day_stmt = $pdo->prepare($day_sql);
            $day_stmt->execute($dr_params);
            $day_totals = $day_stmt->fetchAll(PDO::FETCH_ASSOC);

            respond(true, '', [
                'report_type'  => 'daily',
                'date'         => $date,
                'shift_detail' => $shift_rows,
                'day_totals'   => $day_totals,
                'grand_liters' => round(array_sum(array_column($day_totals, 'total_liters')), 2),
                'grand_amount' => round(array_sum(array_column($day_totals, 'total_amount')), 2),
            ]);

        // ══════════════════════════════════════════════════════════════════════
        // WEEKLY SUMMARY — Previous vs Present readings per fuel type
        // ══════════════════════════════════════════════════════════════════════
        case 'weekly_report':
            $week_start = $_GET['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
            $week_end   = date('Y-m-d', strtotime($week_start . ' +6 days'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)) $week_start = date('Y-m-d', strtotime('monday this week'));

            $wr_stmt = $pdo->prepare("
                SELECT
                    ft.fuel_type,
                    DATE(ft.transaction_date)  AS reading_date,
                    MIN(ft.previous_reading)   AS beginning,
                    MAX(ft.present_reading)    AS ending,
                    SUM(ft.liters_sold)        AS liters_sold,
                    SUM(ft.total_amount)       AS amount,
                    AVG(ft.price_per_liter)    AS avg_price
                FROM fuel_transactions ft
                WHERE ft.station_id = ?
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY ft.fuel_type, DATE(ft.transaction_date)
                ORDER BY ft.fuel_type ASC, reading_date ASC
            ");
            $wr_stmt->execute([$station_id, $week_start, $week_end]);
            $weekly_rows = $wr_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Weekly totals per fuel type
            $wt_stmt = $pdo->prepare("
                SELECT ft.fuel_type,
                    MIN(ft.previous_reading) AS week_beginning,
                    MAX(ft.present_reading)  AS week_ending,
                    SUM(ft.liters_sold)      AS total_liters,
                    SUM(ft.total_amount)     AS total_amount
                FROM fuel_transactions ft
                WHERE ft.station_id = ?
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY ft.fuel_type ORDER BY ft.fuel_type ASC
            ");
            $wt_stmt->execute([$station_id, $week_start, $week_end]);
            $weekly_totals = $wt_stmt->fetchAll(PDO::FETCH_ASSOC);

            respond(true, '', [
                'report_type'   => 'weekly',
                'week_start'    => $week_start,
                'week_end'      => $week_end,
                'daily_detail'  => $weekly_rows,
                'weekly_totals' => $weekly_totals,
                'grand_liters'  => round(array_sum(array_column($weekly_totals, 'total_liters')), 2),
                'grand_amount'  => round(array_sum(array_column($weekly_totals, 'total_amount')), 2),
            ]);

        // ══════════════════════════════════════════════════════════════════════
        // MONTHLY AUDIT REPORT — Admin/Manager oversight
        // ══════════════════════════════════════════════════════════════════════
        case 'monthly_report':
            $year  = (int)($_GET['year']  ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('n'));
            if ($year < 2020 || $year > 2100) $year = (int)date('Y');
            if ($month < 1 || $month > 12)    $month = (int)date('n');
            if (!in_array($role, ['manager','admin','superadmin'])) respond(false, 'Manager access required.');

            $month_start = sprintf('%04d-%02d-01', $year, $month);
            $month_end   = date('Y-m-t', strtotime($month_start));

            // Daily totals for the month
            $mr_stmt = $pdo->prepare("
                SELECT
                    DATE(ft.transaction_date)  AS reading_date,
                    ft.fuel_type,
                    MIN(ft.previous_reading)   AS beginning,
                    MAX(ft.present_reading)    AS ending,
                    SUM(ft.liters_sold)        AS liters_sold,
                    SUM(ft.total_amount)       AS amount,
                    COUNT(*)                   AS entries,
                    SUM(CASE WHEN LOWER(ft.status) = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                    SUM(CASE WHEN LOWER(ft.status) = 'pending validation' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN LOWER(ft.status) = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
                FROM fuel_transactions ft
                WHERE ft.station_id = ?
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY DATE(ft.transaction_date), ft.fuel_type
                ORDER BY reading_date ASC, ft.fuel_type ASC
            ");
            $mr_stmt->execute([$station_id, $month_start, $month_end]);
            $monthly_detail = $mr_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Monthly totals per fuel type
            $mt_stmt = $pdo->prepare("
                SELECT ft.fuel_type,
                    MIN(ft.previous_reading) AS month_beginning,
                    MAX(ft.present_reading)  AS month_ending,
                    SUM(ft.liters_sold)      AS total_liters,
                    SUM(ft.total_amount)     AS total_amount,
                    COUNT(*)                 AS total_entries,
                    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS staff_names
                FROM fuel_transactions ft
                LEFT JOIN users u ON ft.staff_id = u.id
                WHERE ft.station_id = ?
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY ft.fuel_type ORDER BY ft.fuel_type ASC
            ");
            $mt_stmt->execute([$station_id, $month_start, $month_end]);
            $monthly_totals = $mt_stmt->fetchAll(PDO::FETCH_ASSOC);

            respond(true, '', [
                'report_type'    => 'monthly',
                'year'           => $year,
                'month'          => $month,
                'month_start'    => $month_start,
                'month_end'      => $month_end,
                'daily_detail'   => $monthly_detail,
                'monthly_totals' => $monthly_totals,
                'grand_liters'   => round(array_sum(array_column($monthly_totals, 'total_liters')), 2),
                'grand_amount'   => round(array_sum(array_column($monthly_totals, 'total_amount')), 2),
            ]);

        default:
            // ── Debug auth endpoint (GET only, safe — returns no sensitive data) ──
            if ($action === 'debug_auth') {
                respond(true, 'Auth OK', [
                    'user_id'    => $me['id'] ?? null,
                    'name'       => $me['name'] ?? null,
                    'role'       => $role,
                    'station_id' => $station_id,
                    'auth_path'  => !empty($_SESSION['user']) ? 'session' : 'token',
                ]);
            }
            respond(false, 'Invalid action.');
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}

function respond(bool $ok, string $msg = '', array $data = []): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $data));
    exit;
}
function fuel_validate(float $present, float $previous, float $calibration, PDO $pdo): array {
    // Pull limits from system_settings; fall back to safe defaults if not configured
    $max_liters    = 2000.0;
    $max_calib     = 50.0;
    try {
        $s = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_liters_per_shift','max_calibration_liters')");
        if ($s) {
            foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                if ($k === 'max_liters_per_shift'    && (float)$v > 0) $max_liters = (float)$v;
                if ($k === 'max_calibration_liters'  && (float)$v > 0) $max_calib  = (float)$v;
            }
        }
    } catch (Exception $e) {}

    $errors = [];
    if ($present < $previous)
        $errors[] = "Present reading ({$present}) cannot be less than previous ({$previous}).";
    $diff = $present - $previous;
    // Allow calibration >= diff — results in 0 L net sales (valid, matches UI behaviour)
    $liters = $diff - $calibration;
    if ($liters < -0.001)     $errors[] = "Negative liters computed (" . round($liters, 3) . ").";
    if ($liters > $max_liters) $errors[] = "Liters sold ({$liters}) exceeds {$max_liters} L limit.";
    if ($calibration > $max_calib) $errors[] = "Calibration ({$calibration}) exceeds {$max_calib} L maximum.";
    return ['valid' => empty($errors), 'errors' => $errors, 'liters_sold' => max(0, $liters)];
}
