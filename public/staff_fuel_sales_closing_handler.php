<?php
/**
 * Handler for Official Petron Fuel Sales Closing AJAX requests
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id    = (int)$_SESSION['user_id'];
$station_id = (int)($_SESSION['station_id'] ?? 0);

if ($station_id <= 0 && $user_id > 0) {
    try {
        $st_stmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ?");
        $st_stmt->execute([$user_id]);
        $st_val = $st_stmt->fetchColumn();
        if ($st_val && (int)$st_val > 0) {
            $station_id = (int)$st_val;
            $_SESSION['station_id'] = $station_id;
        }
    } catch (Exception $e) {}
}
if ($station_id <= 0) $station_id = 1;

// Detect role — admin/superadmin closings are auto-approved (no manager approval needed)
$current_user_role = 'staff';
try {
    $role_stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $role_stmt->execute([$user_id]);
    $current_user_role = role_key($role_stmt->fetchColumn() ?: 'staff');
} catch (Exception $e) {}
$is_admin_closing = in_array($current_user_role, ['admin', 'superadmin', 'developer'], true);

$action = $_REQUEST['action'] ?? '';

if ($action === 'get_summary') {
    $report_date = $_GET['date'] ?? date('Y-m-d');
    $shift       = trim($_GET['shift'] ?? $_POST['shift'] ?? '');

    // Shift period key matching helper
    $shift_key = '';
    $shift_lower = strtolower($shift);
    if (strpos($shift_lower, 'second') !== false || strpos($shift_lower, 'shift 2') !== false || strpos($shift_lower, 'shift2') !== false || strpos($shift_lower, '2nd') !== false) {
        $shift_key = 'second';
    } elseif (strpos($shift_lower, 'first') !== false || strpos($shift_lower, 'shift 1') !== false || strpos($shift_lower, 'shift1') !== false || strpos($shift_lower, '1st') !== false) {
        $shift_key = 'first';
    }
    $shift_like = $shift_key ? "%{$shift_key}%" : "%";

    // Deduplicated query: fetch only the latest transaction entry per pump/nozzle for Section A
    $sql = "
        SELECT 
            ft.id,
            COALESCE(NULLIF(fp.pump_number, ''), ft.fuel_type) AS pump_name,
            ft.fuel_type,
            COALESCE(ft.previous_reading, 0) AS beginning_reading,
            COALESCE(ft.present_reading, 0) AS ending_reading,
            COALESCE(ft.calibration, 0) AS calibration,
            COALESCE(ft.liters_sold, 0) AS liters_sold,
            COALESCE(ft.price_per_liter, 0) AS price_per_liter,
            COALESCE(ft.total_amount, 0) AS total_amount
        FROM fuel_transactions ft
        LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
        INNER JOIN (
            SELECT MAX(id) AS max_id
            FROM fuel_transactions
            WHERE station_id = ? 
              AND (DATE(transaction_date) = ? OR (transaction_date IS NULL AND DATE(created_at) = ?))
              AND (
                  ? = '' 
                  OR shift_period = ? 
                  OR shift_name = ? 
                  OR shift_period = ?
                  OR LOWER(shift_name) LIKE ?
              )
              AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled','canceled')
            GROUP BY COALESCE(pump_id, fuel_type)
        ) latest ON ft.id = latest.max_id
        ORDER BY ft.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $station_id, $report_date, $report_date,
        $shift, $shift, $shift, $shift_key, $shift_like
    ]);
    $meter_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Summaries per fuel type
    $by_fuel = [
        'Diesel'       => ['liters' => 0.0, 'amount' => 0.0],
        'Turbo Diesel' => ['liters' => 0.0, 'amount' => 0.0],
        'XCS Plus'     => ['liters' => 0.0, 'amount' => 0.0],
        'XTRA Advance' => ['liters' => 0.0, 'amount' => 0.0],
        'Kerosene'     => ['liters' => 0.0, 'amount' => 0.0],
    ];

    // Exactly 7 UGT Tanks
    $tank_summary = [
        'UGT #1 (DIESEL 1)'       => 0.0,
        'UGT #2 (DIESEL 2)'       => 0.0,
        'UGT #3 (TURBO DIESEL)'   => 0.0,
        'UGT #4 (XCS PLUS)'       => 0.0,
        'UGT #5 (XTRA ADVANCE 1)' => 0.0,
        'UGT #6 (XTRA ADVANCE 2)' => 0.0,
        'UGT #7 (KEROSENE)'       => 0.0,
    ];

    $total_fuel_sales   = 0.00;
    $total_liters_sold  = 0.00;

    foreach ($meter_rows as $row) {
        $pName  = strtoupper(trim($row['pump_name'] ?: $row['fuel_type']));
        $ftype  = strtolower(trim($row['fuel_type']));
        $amt    = (float)$row['total_amount'];
        $liters = (float)$row['liters_sold'];

        $total_fuel_sales  += $amt;
        $total_liters_sold += $liters;

        // Group by Fuel Type
        if (strpos($ftype, 'turbo') !== false) {
            $by_fuel['Turbo Diesel']['liters'] += $liters;
            $by_fuel['Turbo Diesel']['amount'] += $amt;
        } elseif (strpos($ftype, 'diesel') !== false || strpos($ftype, 'dsl') !== false) {
            $by_fuel['Diesel']['liters'] += $liters;
            $by_fuel['Diesel']['amount'] += $amt;
        } elseif (strpos($ftype, 'xcs') !== false) {
            $by_fuel['XCS Plus']['liters'] += $liters;
            $by_fuel['XCS Plus']['amount'] += $amt;
        } elseif (strpos($ftype, 'xtra') !== false || strpos($ftype, 'advance') !== false || strpos($ftype, 'unl') !== false || strpos($ftype, 'uls') !== false || strpos($ftype, 'primax') !== false) {
            $by_fuel['XTRA Advance']['liters'] += $liters;
            $by_fuel['XTRA Advance']['amount'] += $amt;
        } elseif (strpos($ftype, 'kerosene') !== false || strpos($ftype, 'kero') !== false) {
            $by_fuel['Kerosene']['liters'] += $liters;
            $by_fuel['Kerosene']['amount'] += $amt;
        }

        // Group into Exactly 7 UGT Tanks
        if (strpos($pName, 'DIESEL 1') !== false) {
            $tank_summary['UGT #1 (DIESEL 1)'] += $liters;
        } elseif (strpos($pName, 'DIESEL 2') !== false) {
            $tank_summary['UGT #2 (DIESEL 2)'] += $liters;
        } elseif (strpos($pName, 'TURBO') !== false) {
            $tank_summary['UGT #3 (TURBO DIESEL)'] += $liters;
        } elseif (strpos($pName, 'XCS') !== false) {
            $tank_summary['UGT #4 (XCS PLUS)'] += $liters;
        } elseif (strpos($pName, 'XTRA UNL 1') !== false || strpos($pName, 'XTRA AD 1') !== false || strpos($pName, 'ADVANCE 1') !== false) {
            $tank_summary['UGT #5 (XTRA ADVANCE 1)'] += $liters;
        } elseif (strpos($pName, 'XTRA UNL 2') !== false || strpos($pName, 'XTRA AD 2') !== false || strpos($pName, 'ADVANCE 2') !== false) {
            $tank_summary['UGT #6 (XTRA ADVANCE 2)'] += $liters;
        } elseif (strpos($pName, 'KERO') !== false) {
            $tank_summary['UGT #7 (KEROSENE)'] += $liters;
        } else {
            if (strpos($ftype, 'turbo') !== false) {
                $tank_summary['UGT #3 (TURBO DIESEL)'] += $liters;
            } elseif (strpos($ftype, 'diesel') !== false) {
                $tank_summary['UGT #1 (DIESEL 1)'] += $liters;
            } elseif (strpos($ftype, 'xcs') !== false) {
                $tank_summary['UGT #4 (XCS PLUS)'] += $liters;
            } elseif (strpos($ftype, 'xtra') !== false || strpos($ftype, 'advance') !== false) {
                $tank_summary['UGT #5 (XTRA ADVANCE 1)'] += $liters;
            } elseif (strpos($ftype, 'kero') !== false) {
                $tank_summary['UGT #7 (KEROSENE)'] += $liters;
            }
        }
    }

    // Fetch existing saved closing if available for this specific date + shift
    $stmt_closing = $pdo->prepare("
        SELECT * FROM fuel_sales_closing
        WHERE station_id = ? AND report_date = ? AND (? = '' OR shift = ? OR shift_period = ?)
        ORDER BY id DESC LIMIT 1
    ");
    $stmt_closing->execute([$station_id, $report_date, $shift, $shift, $shift_key]);
    $existing = $stmt_closing->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'report_date' => $report_date,
        'shift' => $shift,
        'meter_rows' => $meter_rows,
        'by_fuel' => $by_fuel,
        'tank_summary' => $tank_summary,
        'totals' => [
            'total_fuel_sales' => $total_fuel_sales,
            'total_liters_sold' => $total_liters_sold,
        ],
        'existing_closing' => $existing ?: null
    ]);
    exit;
}

if ($action === 'save_closing') {
    $report_date        = $_POST['report_date'] ?? date('Y-m-d');
    $shift              = trim($_POST['shift'] ?? 'General');
    $total_fuel_sales   = (float)($_POST['total_fuel_sales'] ?? 0);
    $total_liters       = (float)($_POST['total_liters'] ?? 0);

    $cash_shift1        = (float)($_POST['cash_shift1'] ?? 0);
    $cash_shift2        = (float)($_POST['cash_shift2'] ?? 0);
    $total_cash         = (float)($_POST['total_cash'] ?? 0);

    $ar_shift1          = (float)($_POST['ar_shift1'] ?? 0);
    $ar_shift2          = (float)($_POST['ar_shift2'] ?? 0);
    $total_ar           = (float)($_POST['total_ar'] ?? 0);

    $net_sales          = (float)($_POST['net_sales'] ?? 0);
    $total_cash_bank    = (float)($_POST['total_cash_bank'] ?? 0);

    $verified_by        = trim($_POST['verified_by'] ?? '');
    $checked_by         = trim($_POST['checked_by'] ?? '');

    $shift_key = '';
    $shift_lower = strtolower($shift);
    if (strpos($shift_lower, 'second') !== false || strpos($shift_lower, 'shift 2') !== false || strpos($shift_lower, 'shift2') !== false || strpos($shift_lower, '2nd') !== false) {
        $shift_key = 'second';
    } elseif (strpos($shift_lower, 'first') !== false || strpos($shift_lower, 'shift 1') !== false || strpos($shift_lower, 'shift1') !== false || strpos($shift_lower, '1st') !== false) {
        $shift_key = 'first';
    }

    try {
        $pdo->beginTransaction();

        $shift_like = $shift_key ? "%{$shift_key}%" : "%";

        // Check who encoded the meter readings for this shift and date
        $readings_by_admin = false;
        try {
            $chk_staff = $pdo->prepare("
                SELECT u.role 
                FROM fuel_transactions ft
                JOIN users u ON ft.staff_id = u.id
                WHERE ft.station_id = ? 
                  AND (DATE(ft.transaction_date) = ? OR (ft.transaction_date IS NULL AND DATE(ft.created_at) = ?))
                  AND (ft.shift_period = ? OR ft.shift_name = ? OR ? = '' OR LOWER(ft.shift_name) LIKE ?)
                  AND LOWER(COALESCE(ft.status,'')) NOT IN ('rejected','voided','cancelled','canceled')
                ORDER BY ft.id DESC LIMIT 1
            ");
            $chk_staff->execute([$station_id, $report_date, $report_date, $shift_key, $shift, $shift, $shift_like]);
            $encoder_role = role_key($chk_staff->fetchColumn() ?: '');
            if (in_array($encoder_role, ['admin', 'superadmin', 'developer', 'manager'])) {
                $readings_by_admin = true;
            }
        } catch (Exception $e) {}
        $is_auto_approved = ($readings_by_admin || $is_admin_closing);
        $closing_status   = $is_auto_approved ? 'Verified' : 'CLOSING_COMPLETED';
        $txn_status       = $is_auto_approved ? 'Verified' : 'CLOSING_COMPLETED';

        $stmt_chk = $pdo->prepare("SELECT id FROM fuel_sales_closing WHERE station_id = ? AND report_date = ? AND (shift = ? OR shift_period = ?)");
        $stmt_chk->execute([$station_id, $report_date, $shift, $shift_key]);
        $exist_id = $stmt_chk->fetchColumn();

        if ($exist_id) {
            $stmt_upd = $pdo->prepare("
                UPDATE fuel_sales_closing SET
                    shift = ?, shift_period = ?, total_fuel_sales = ?, total_liters = ?, cash_shift1 = ?, cash_shift2 = ?,
                    total_cash = ?, ar_shift1 = ?, ar_shift2 = ?, total_ar = ?, net_sales = ?,
                    total_cash_bank = ?, verified_by = ?, checked_by = ?, encoded_by = ?, encoded_at = NOW(), status = ?
                WHERE id = ?
            ");
            $stmt_upd->execute([
                $shift, $shift_key, $total_fuel_sales, $total_liters, $cash_shift1, $cash_shift2,
                $total_cash, $ar_shift1, $ar_shift2, $total_ar, $net_sales,
                $total_cash_bank, $verified_by, $checked_by, $user_id, $closing_status, $exist_id
            ]);
            $saved_id = $exist_id;
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO fuel_sales_closing (
                    station_id, report_date, shift, shift_period, total_fuel_sales, total_liters, cash_shift1, cash_shift2,
                    total_cash, ar_shift1, ar_shift2, total_ar, net_sales, total_cash_bank, verified_by, checked_by,
                    encoded_by, encoded_at, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?
                )
            ");
            $stmt_ins->execute([
                $station_id, $report_date, $shift, $shift_key, $total_fuel_sales, $total_liters, $cash_shift1, $cash_shift2,
                $total_cash, $ar_shift1, $ar_shift2, $total_ar, $net_sales, $total_cash_bank, $verified_by, $checked_by, $user_id,
                $closing_status
            ]);
            $saved_id = $pdo->lastInsertId();
        }

        // Update corresponding fuel_transactions status
        if ($is_auto_approved) {
            $pdo->prepare("
                UPDATE fuel_transactions
                SET status = 'Verified', validated_by = COALESCE(validated_by, ?), validated_at = COALESCE(validated_at, NOW())
                WHERE station_id = ?
                  AND (DATE(transaction_date) = ? OR (transaction_date IS NULL AND DATE(created_at) = ?))
                  AND (shift_period = ? OR shift_name = ? OR ? = '' OR LOWER(shift_name) LIKE ?)
                  AND LOWER(COALESCE(status,'')) NOT IN ('rejected','voided','cancelled','canceled')
            ")->execute([$user_id, $station_id, $report_date, $report_date, $shift_key, $shift, $shift, $shift_like]);
        } else {
            $pdo->prepare("
                UPDATE fuel_transactions
                SET status = 'CLOSING_COMPLETED'
                WHERE station_id = ?
                  AND (DATE(transaction_date) = ? OR (transaction_date IS NULL AND DATE(created_at) = ?))
                  AND (shift_period = ? OR shift_name = ? OR ? = '' OR LOWER(shift_name) LIKE ?)
                  AND LOWER(COALESCE(status,'')) NOT IN ('rejected','voided','cancelled','canceled')
            ")->execute([$station_id, $report_date, $report_date, $shift_key, $shift, $shift, $shift_like]);
        }

        $pdo->commit();

        // ── Include lib.php for notification helpers ───────────────
        require_once __DIR__ . '/../backend/lib.php';

        $shift_label = !empty($shift) ? $shift : ($shift_key === 'second' ? 'Shift 2' : 'Shift 1');

        // 1. Notify staff: Closing saved successfully
        notify(
            $pdo, $user_id, 'staff', 'success', 'fuel_sales_closing', 'medium',
            "Fuel Sales Closing Saved ({$shift_label})",
            "Your fuel sales closing for {$report_date} ({$shift_label}) has been saved successfully.",
            "fuel_closing_staff_{$saved_id}",
            'staff_fuel_sales_closing.php',
            'fuel_sales_closing', (int)$saved_id,
            $shift_label
        );

        // 2. Notify Manager and Admin: Fuel Sales Closing Submitted
        try {
            $staff_stmt = $pdo->prepare("SELECT first_name, last_name, name, username FROM users WHERE id = ?");
            $staff_stmt->execute([$user_id]);
            $s_user = $staff_stmt->fetch(PDO::FETCH_ASSOC);
            $staff_name = trim(($s_user['first_name'] ?? '') . ' ' . ($s_user['last_name'] ?? '')) ?: ($s_user['name'] ?? $s_user['username'] ?? 'Staff');

            $date_lbl   = date('M d, Y', strtotime($report_date));
            $closing_msg = "{$staff_name} submitted Fuel Sales Closing for {$date_lbl} ({$shift_label}). Ready for validation.";
            $base_closing_key = 'mgr_fuel_closing_' . $station_id . '_' . date('Ymd', strtotime($report_date)) . '_' . ($shift_key ?: 'shift');

            $mgr_stmt = $pdo->prepare("
                SELECT id, role FROM users
                WHERE (station_id = ? OR station_id IS NULL OR station_id = 0)
                  AND LOWER(TRIM(role)) IN ('manager', 'admin', 'superadmin', 'supervisor')
                  AND (status = 'Active' OR status = 'active' OR status IS NULL)
            ");
            $mgr_stmt->execute([$station_id]);
            $managers = $mgr_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($managers as $mgr) {
                $mgr_id = (int)$mgr['id'];
                $mgr_role = strtolower(trim((string)$mgr['role']));
                $user_closing_key = $base_closing_key . '_u' . $mgr_id;

                try {
                    $chk_closing = $pdo->prepare("
                        SELECT id FROM notifications 
                        WHERE user_id = ? AND (source_key = ? OR source_key = ?)
                        LIMIT 1
                    ");
                    $chk_closing->execute([$mgr_id, $user_closing_key, $base_closing_key]);
                    $existing_cls_id = $chk_closing->fetchColumn();

                    if ($existing_cls_id) {
                        $upd_cls = $pdo->prepare("
                            UPDATE notifications
                            SET title = 'Fuel Sales Closing Submitted — Pending Validation',
                                message = ?,
                                recipient_role = ?,
                                redirect_url = 'manager_fuel_transaction_validation.php',
                                source_key = ?,
                                status = 'unread',
                                created_at = NOW()
                            WHERE id = ?
                        ");
                        $upd_cls->execute([$closing_msg, $mgr_role, $user_closing_key, $existing_cls_id]);
                    } else {
                        $ins_cls = $pdo->prepare("
                            INSERT INTO notifications 
                                (user_id, recipient_role, type, event_type, severity, title, message, source_key, redirect_url, reference_type, reference_id, shift_period, status, created_at)
                            VALUES (?, ?, 'info', 'fuel_sales_closing', 'high', 'Fuel Sales Closing Submitted — Pending Validation', ?, ?, 'manager_fuel_transaction_validation.php', 'fuel_sales_closing', ?, ?, 'unread', NOW())
                        ");
                        $ins_cls->execute([$mgr_id, $mgr_role, $closing_msg, $user_closing_key, (int)$saved_id, $shift_label]);
                    }
                } catch (Exception $ne) {}
            }
        } catch (Exception $e) {}

        $report_url = 'staff_transactions_hub.php?section=fuel&closing_saved=1';

        echo json_encode([
            'success' => true,
            'message' => 'Fuel Sales Closing saved successfully.',
            'closing_id' => $saved_id,
            'report_date' => $report_date,
            'report_url' => $report_url
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
         echo json_encode(['success' => false, 'message' => 'Failed to save closing: ' . $e->getMessage()]);
    }
    exit;
}

// ── check_closing_status — lightweight AJAX for real-time button visibility ──────
if ($action === 'check_closing_status') {
    $report_date = $_GET['date'] ?? date('Y-m-d');
    $shift       = trim($_GET['shift'] ?? '');

    $shift_key = '';
    $shift_lower = strtolower($shift);
    if (strpos($shift_lower, 'second') !== false || strpos($shift_lower, 'shift 2') !== false || strpos($shift_lower, '2') !== false) {
        $shift_key = 'second';
    } elseif (strpos($shift_lower, 'first') !== false || strpos($shift_lower, 'shift 1') !== false) {
        $shift_key = 'first';
    }
    $shift_like = $shift_key ? "%{$shift_key}%" : "%";

    $closing_completed = false;
    $readings_pending  = false;

    try {
        // Check if a real (non-stub) closing exists with financial data
        $stmt = $pdo->prepare("
            SELECT id, status, total_fuel_sales, net_sales, total_cash, total_cash_bank,
                   cash_shift1, cash_shift2, ar_shift1, ar_shift2, total_ar, checked_by
            FROM fuel_sales_closing
            WHERE station_id = ? AND report_date = ?
              AND (shift = ? OR shift_period = ? OR LOWER(shift) LIKE ?)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$station_id, $report_date, $shift, $shift_key, $shift_like]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $status_up = strtoupper(trim($row['status'] ?? ''));
            $completed_statuses = ['CLOSING_COMPLETED', 'SAVED', 'REPORTED', 'VERIFIED', 'APPROVED', 'VALIDATED'];

            if (in_array($status_up, $completed_statuses)) {
                $has_data = (
                    (float)($row['total_fuel_sales'] ?? 0) > 0 ||
                    (float)($row['net_sales'] ?? 0) > 0 ||
                    (float)($row['total_cash'] ?? 0) > 0 ||
                    (float)($row['total_cash_bank'] ?? 0) > 0 ||
                    (float)($row['cash_shift1'] ?? 0) > 0 ||
                    (float)($row['cash_shift2'] ?? 0) > 0 ||
                    (float)($row['ar_shift1'] ?? 0) > 0 ||
                    (float)($row['ar_shift2'] ?? 0) > 0 ||
                    (float)($row['total_ar'] ?? 0) > 0 ||
                    !empty(trim($row['checked_by'] ?? '')) ||
                    !empty(trim($row['encoded_by'] ?? ''))
                );
                // Completed if: has actual financial/encoded data,
                // OR if fully verified/approved, OR if status is CLOSING_COMPLETED (already submitted by staff)
                if ($has_data || in_array($status_up, ['CLOSING_COMPLETED', 'VERIFIED', 'APPROVED', 'VALIDATED', 'SAVED', 'REPORTED'])) {
                    $closing_completed = true;
                }
            }
        }

        // Check if meter readings exist for this shift/date (readings pending = show button)
        if (!$closing_completed) {
            $stmt2 = $pdo->prepare("
                SELECT COUNT(*) FROM fuel_transactions
                WHERE station_id = ? AND DATE(transaction_date) = ?
                  AND (shift_period = ? OR shift_name = ? OR ? = '')
                  AND LOWER(COALESCE(status,'')) NOT IN ('rejected','voided','cancelled','canceled')
                  AND present_reading > 0
            ");
            $stmt2->execute([$station_id, $report_date, $shift_key, $shift, $shift_key]);
            if ((int)$stmt2->fetchColumn() > 0) {
                $readings_pending = true;
            }
            // NOTE: We intentionally do NOT set readings_pending=true just because a stub row exists.
            // A stub without financial data and without a completed status means closing is not yet done,
            // but we only want the button visible if actual readings are present in fuel_transactions.
        }
    } catch (Exception $e) {}

    echo json_encode([
        'success'           => true,
        'closing_completed' => $closing_completed,
        'readings_pending'  => $readings_pending,
        'show_button'       => (!$closing_completed && $readings_pending),
        'date'              => $report_date,
        'shift'             => $shift,
    ]);
    exit;
}
