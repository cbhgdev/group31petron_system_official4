<?php
// ============================================================
// Manager Calibration Review – manager_fuel_pump_master.php
// Purpose: Granular, shift-based calibration and meter reading validation.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_pump_master';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Access control - Manager, Supervisor, Admin
if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: staff_dashboard.php'); 
    exit;
}

if ($station_id <= 0) {
    $_SESSION['error'] = 'No station assigned.';
    header('Location: manager_dashboard.php'); 
    exit;
}

// â”€â”€ Shift Dependency & Continuity Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (!function_exists('get_preceding_shift_and_date')) {
    function get_preceding_shift_and_date($pdo, $shift_key, $date) {
        $stmt = $pdo->query("SELECT shift_key FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC");
        $shifts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($shifts)) {
            return null;
        }
        
        $index = array_search(strtolower($shift_key), array_map('strtolower', $shifts));
        if ($index === false) {
            return null;
        }
        
        if ($index > 0) {
            return [
                'shift_key' => $shifts[$index - 1],
                'date' => $date
            ];
        } else {
            $prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
            return [
                'shift_key' => $shifts[count($shifts) - 1],
                'date' => $prev_date
            ];
        }
    }
}

if (!function_exists('get_preceding_shift_validated_ending')) {
    function get_preceding_shift_validated_ending($pdo, $station_id, $pump_id, $current_shift, $current_date) {
        $preceding = get_preceding_shift_and_date($pdo, $current_shift, $current_date);
        if ($preceding) {
            $stmt = $pdo->prepare("
                SELECT present_reading 
                FROM fuel_transactions 
                WHERE station_id = ? 
                  AND pump_id = ? 
                  AND LOWER(shift_period) = LOWER(?) 
                  AND DATE(transaction_date) = ?
                  AND LOWER(status) IN ('verified', 'adjusted')
                ORDER BY id DESC 
                LIMIT 1
            ");
            $stmt->execute([$station_id, $pump_id, $preceding['shift_key'], $preceding['date']]);
            $val = $stmt->fetchColumn();
            if ($val !== false) {
                return (float)$val;
            }
        }
        
        $stmt_fallback = $pdo->prepare("
            SELECT present_reading 
            FROM fuel_transactions 
            WHERE station_id = ? 
              AND pump_id = ? 
              AND LOWER(status) IN ('verified', 'adjusted')
            ORDER BY transaction_date DESC, id DESC 
            LIMIT 1
        ");
        $stmt_fallback->execute([$station_id, $pump_id]);
        $val_fallback = $stmt_fallback->fetchColumn();
        return $val_fallback !== false ? (float)$val_fallback : 0.0;
    }
}

if (!function_exists('is_preceding_shift_validated')) {
    function is_preceding_shift_validated($pdo, $station_id, $pump_id, $current_shift, $current_date) {
        $preceding = get_preceding_shift_and_date($pdo, $current_shift, $current_date);
        if (!$preceding) {
            return true; 
        }
        
        $stmt_exists = $pdo->prepare("
            SELECT COUNT(*) 
            FROM fuel_transactions 
            WHERE station_id = ? 
              AND pump_id = ? 
              AND LOWER(shift_period) = LOWER(?) 
              AND DATE(transaction_date) = ?
        ");
        $stmt_exists->execute([$station_id, $pump_id, $preceding['shift_key'], $preceding['date']]);
        $exists = (int)$stmt_exists->fetchColumn() > 0;
        
        if (!$exists) {
            return true;
        }
        
        $stmt_unverified = $pdo->prepare("
            SELECT COUNT(*) 
            FROM fuel_transactions 
            WHERE station_id = ? 
              AND pump_id = ? 
              AND LOWER(shift_period) = LOWER(?) 
              AND DATE(transaction_date) = ?
              AND LOWER(status) NOT IN ('verified', 'adjusted')
        ");
        $stmt_unverified->execute([$station_id, $pump_id, $preceding['shift_key'], $preceding['date']]);
        $unverified = (int)$stmt_unverified->fetchColumn();
        
        return $unverified === 0;
    }
}

if (!function_exists('formatShiftLabel')) {
    function formatShiftLabel($shift_key) {
        $s = strtolower(trim($shift_key ?? ''));
        if ($s === 'first') return 'Shift 1';
        if ($s === 'second') return 'Shift 2';
        if ($s === 'third') return 'Shift 3';
        return ucfirst($shift_key);
    }
}

if (!function_exists('getStatusBadgeClass')) {
    function getStatusBadgeClass($status) {
        $s = strtolower(trim($status ?? ''));
        if (str_contains($s, 'pending')) return 'bg-amber';
        if ($s === 'verified' || $s === 'approved' || $s === 'validated') return 'bg-green';
        if ($s === 'adjusted') return 'bg-blue';
        if ($s === 'rejected') return 'bg-red';
        return 'bg-gray';
    }
}

if (!function_exists('getStatusLabel')) {
    function getStatusLabel($status) {
        $s = strtolower(trim($status ?? ''));
        if (str_contains($s, 'pending')) return 'Pending';
        if ($s === 'verified' || $s === 'approved' || $s === 'validated') return 'Verified';
        if ($s === 'adjusted') return 'Adjusted';
        if ($s === 'rejected') return 'Rejected';
        if ($s === 'readings_submitted' || $s === 'submitted') return 'Readings Submitted';
        if ($s === 'closing_completed') return 'Closing Completed';
        return ucwords(str_replace('_', ' ', $status));
    }
}

if (!function_exists('normalizeFuelType')) {
    function normalizeFuelType($fuel_type) {
        $fuel_upper = strtoupper($fuel_type ?? '');
        
        if (strpos($fuel_upper, 'TURBO') !== false && strpos($fuel_upper, 'DIESEL') !== false) {
            return 'Turbo Diesel';
        } elseif (strpos($fuel_upper, 'KEROSENE') !== false) {
            return 'Kerosene';
        } elseif (strpos($fuel_upper, 'XCS') !== false) {
            return 'XCS Plus';
        } elseif (strpos($fuel_upper, 'XTRA') !== false || strpos($fuel_upper, 'UNL') !== false) {
            return 'Xtra UNL';
        } elseif (strpos($fuel_upper, 'DIESEL') !== false) {
            return 'Diesel';
        } else {
            // Fallback: remove numbers and clean up
            $clean = preg_replace('/\s*\d+\s*-?\s*\d*\s*/', ' ', $fuel_type);
            return trim(preg_replace('/\s+/', ' ', $clean));
        }
    }
}

// ── GET Filters ──────────────────────────────────────────────────────────
$search_query       = trim($_GET['search'] ?? $_GET['q'] ?? $_GET['search_query'] ?? '');
$status_filter      = trim($_GET['status'] ?? 'pending');
$shift_filter       = trim($_GET['shift']     ?? 'all');
$fuel_type_filter   = trim($_GET['fuel_type'] ?? 'all');
$staff_filter       = trim($_GET['staff']     ?? '');
$export             = trim($_GET['export']    ?? '');

// Default date: if explicit date provided, use it.
// If search query is provided without explicit date, search across all dates.
// If browsing pending by default, show all pending dates; otherwise use most recent date.
$has_explicit_date = isset($_GET['date']) && $_GET['date'] !== '';
$date_filter = '';
if ($has_explicit_date) {
    $date_filter = trim($_GET['date']);
} elseif ($search_query !== '') {
    $date_filter = '';
    if (!isset($_GET['status'])) {
        $status_filter = 'all';
    }
} else {
    if ($status_filter === 'pending') {
        $date_filter = ''; // Show all pending items across dates so none are hidden
    } else {
        try {
            $latest_stmt = $pdo->prepare("SELECT DATE(transaction_date) FROM fuel_transactions WHERE station_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 1");
            $latest_stmt->execute([$station_id]);
            $latest_date = $latest_stmt->fetchColumn();
            $date_filter = $latest_date ?: date('Y-m-d');
        } catch (Exception $e) {
            $date_filter = date('Y-m-d');
        }
    }
}


// ── POST Actions (Verify / Adjust / Reject) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $export === '') {
    $action  = trim($_POST['action'] ?? '');
    $tx_id   = (int)($_POST['id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if ($tx_id <= 0) {
        $_SESSION['error'] = 'Invalid transaction ID.';
        header('Location: manager_fuel_pump_master.php'); exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT ft.*,
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(staff.first_name, '')), ' ', TRIM(COALESCE(staff.last_name, ''))), ' '),
                       staff.username,
                       'Unknown'
                   ) as staff_name
            FROM fuel_transactions ft
            LEFT JOIN users staff ON ft.staff_id = staff.id
            WHERE ft.id = ? AND ft.station_id = ?
        ");
        $stmt->execute([$tx_id, $station_id]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            throw new Exception("Transaction not found.");
        }

        // 1. VERIFY ACTION
        if ($action === 'verify') {
            $curr_st = strtolower($tx['status'] ?? '');
            if (!str_contains($curr_st, 'pending') && !in_array($curr_st, ['closing_completed', 'submitted', 'readings_submitted', 'adjusted'])) {
                throw new Exception("Transaction has already been processed.");
            }

            $liters_sold = (float)$tx['liters_sold'];
            $prev_reading = (float)$tx['previous_reading'];

            if ($tx['pump_id'] > 0) {
                $tx_date_str = date('Y-m-d', strtotime($tx['transaction_date']));
                $is_admin_user = in_array(strtolower(trim($role)), ['admin', 'superadmin', 'manager', 'developer', 'owner']) || in_array(strtolower(trim($me['role'] ?? '')), ['admin', 'superadmin', 'manager', 'developer', 'owner']);
                
                // Shift validation gate check - Admin & Manager bypass strict sequence requirement
                if (!$is_admin_user && !is_preceding_shift_validated($pdo, $station_id, $tx['pump_id'], $tx['shift_period'], $tx_date_str)) {
                    $preceding = get_preceding_shift_and_date($pdo, $tx['shift_period'], $tx_date_str);
                    throw new Exception("Cannot validate this transaction. The transaction for the preceding shift (" . formatShiftLabel($preceding['shift_key']) . " on " . $preceding['date'] . ") for this fuel line must be verified or adjusted first.");
                }

                // Set Beginning Reading to match preceding shift's validated Ending Reading if available; otherwise retain recorded previous reading
                $prec_ending = get_preceding_shift_validated_ending($pdo, $station_id, $tx['pump_id'], $tx['shift_period'], $tx_date_str);
                if ($prec_ending > 0) {
                    $prev_reading = $prec_ending;
                } else {
                    $prev_reading = (float)$tx['previous_reading'];
                }
                $present_reading = (float)$tx['present_reading'];
                $calibration = (float)$tx['calibration'];
                
                if ($prev_reading > 0 && $present_reading < $prev_reading) {
                    throw new Exception("Ending reading (" . number_format($present_reading, 2) . ") cannot be less than beginning reading (" . number_format($prev_reading, 2) . ").");
                }
                
                $liters_sold = max(0.00, $present_reading - $prev_reading - $calibration);
                $price_per_liter = (float)$tx['price_per_liter'];
                $total_amount = round($liters_sold * $price_per_liter, 2);
                
                $up = $pdo->prepare("UPDATE fuel_transactions SET previous_reading = ?, liters_sold = ?, total_amount = ?, status = 'Verified', validated_by = ?, validated_at = NOW(), reject_reason = ? WHERE id = ?");
                $up->execute([$prev_reading, $liters_sold, $total_amount, $me['id'], $remarks ?: null, $tx_id]);
            } else {
                $up = $pdo->prepare("UPDATE fuel_transactions SET status = 'Verified', validated_by = ?, validated_at = NOW(), reject_reason = ? WHERE id = ?");
                $up->execute([$me['id'], $remarks ?: null, $tx_id]);
            }

            // Deduct stock from fuel_inventory
            $up_stock = $pdo->prepare("UPDATE fuel_inventory 
                                       SET current_level = GREATEST(0, COALESCE(current_level, 0) - ?),
                                           current_stock  = GREATEST(0, COALESCE(current_stock, 0) - ?),
                                           last_updated   = NOW()
                                       WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))");
            $up_stock->execute([$liters_sold, $liters_sold, $station_id, $tx['fuel_type']]);

            log_activity($pdo, $me['id'], 'Fuel Reading Approved', "TXN {$tx['transaction_id']} | {$tx['fuel_type']} | {$liters_sold} L");
            $_SESSION['success'] = "Transaction <strong>{$tx['transaction_id']}</strong> verified and approved successfully.";
        }

        // 2. ADJUST ACTION
        elseif ($action === 'adjust') {
            if (empty($remarks)) {
                throw new Exception("Adjustment reason is required.");
            }
            if (!str_contains(strtolower($tx['status']), 'pending')) {
                throw new Exception("Transaction has already been processed.");
            }

            $ending = isset($_POST['ending']) ? (float)$_POST['ending'] : null;
            $calibration = isset($_POST['calibration']) ? (float)$_POST['calibration'] : null;

            if ($ending !== null && $calibration !== null) {
                $tx_date_str = date('Y-m-d', strtotime($tx['transaction_date']));
                $beginning = (float)$tx['previous_reading'];
                $is_admin_user = in_array(strtolower(trim($role)), ['admin', 'superadmin', 'manager', 'developer', 'owner']) || in_array(strtolower(trim($me['role'] ?? '')), ['admin', 'superadmin', 'manager', 'developer', 'owner']);

                if ($tx['pump_id'] > 0) {
                    // Shift validation gate check - Admin & Manager bypass strict sequence requirement
                    if (!$is_admin_user && !is_preceding_shift_validated($pdo, $station_id, $tx['pump_id'], $tx['shift_period'], $tx_date_str)) {
                        $preceding = get_preceding_shift_and_date($pdo, $tx['shift_period'], $tx_date_str);
                        throw new Exception("Cannot adjust this transaction. The transaction for the preceding shift (" . formatShiftLabel($preceding['shift_key']) . " on " . $preceding['date'] . ") for this fuel line must be verified or adjusted first.");
                    }

                    $prec_ending = get_preceding_shift_validated_ending($pdo, $station_id, $tx['pump_id'], $tx['shift_period'], $tx_date_str);
                    if (isset($_POST['beginning']) && is_numeric($_POST['beginning']) && (float)$_POST['beginning'] > 0) {
                        $beginning = (float)$_POST['beginning'];
                    } elseif ($prec_ending > 0) {
                        $beginning = $prec_ending;
                    } else {
                        $beginning = (float)$tx['previous_reading'];
                    }
                }

                $liters_sold = $ending - $beginning - $calibration;
                if ($liters_sold < 0) {
                    throw new Exception("Ending reading cannot be less than beginning reading and calibration combined.");
                }
                $price_per_liter = (float)$tx['price_per_liter'];
                $total_amount = $liters_sold * $price_per_liter;

                // Update transaction readings and calibration
                $up = $pdo->prepare("
                    UPDATE fuel_transactions 
                    SET previous_reading = ?, 
                        present_reading = ?, 
                        calibration = ?, 
                        liters_sold = ?, 
                        total_amount = ?, 
                        status = 'Adjusted', 
                        validated_by = ?, 
                        validated_at = NOW(), 
                        reject_reason = ? 
                    WHERE id = ?
                ");
                $up->execute([$beginning, $ending, $calibration, $liters_sold, $total_amount, $me['id'], $remarks, $tx_id]);

                // Deduct stock from fuel_inventory
                $up_stock = $pdo->prepare("UPDATE fuel_inventory 
                                           SET current_level = GREATEST(0, COALESCE(current_level, 0) - ?),
                                               current_stock  = GREATEST(0, COALESCE(current_stock, 0) - ?),
                                               last_updated   = NOW()
                                           WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))");
                $up_stock->execute([$liters_sold, $liters_sold, $station_id, $tx['fuel_type']]);

                // Log into fuel_adjustments audit log
                try {
                    $meta_notes = json_encode([
                        'transaction_id' => $tx['transaction_id'],
                        'fuel_line' => 'Pump #' . ($tx['pump_id'] ?? '—'),
                        'fuel_type' => $tx['fuel_type'],
                        'shift' => formatShiftLabel($tx['shift_period']),
                        'staff_name' => $tx['staff_name'] ?? '—',
                        'prev_beginning' => (float)$tx['previous_reading'],
                        'prev_ending' => (float)$tx['present_reading'],
                        'prev_calibration' => (float)$tx['calibration'],
                        'new_beginning' => $beginning,
                        'new_ending' => $ending,
                        'new_calibration' => $calibration,
                    ]);

                    $ins_adj = $pdo->prepare("
                        INSERT INTO fuel_adjustments 
                        (station_id, adjustment_date, fuel_type, fuel_type_id, adjustment_type, liters, previous_value, new_value, reason, user_id, notes, status, approved_by, approved_at, created_at)
                        VALUES (?, CURDATE(), ?, null, 'transaction_adjustment', ?, ?, ?, ?, ?, ?, 'Approved', ?, NOW(), NOW())
                    ");
                    $ins_adj->execute([
                        $station_id,
                        $tx['fuel_type'],
                        $liters_sold,
                        (float)$tx['liters_sold'],
                        $liters_sold,
                        $remarks,
                        $me['id'],
                        $meta_notes,
                        $me['id']
                    ]);
                } catch (Exception $e) {}

                log_activity($pdo, $me['id'], 'Fuel Reading Adjusted and Approved', "TXN {$tx['transaction_id']} | {$tx['fuel_type']} | Old: {$tx['liters_sold']} L -> New: {$liters_sold} L | Reason: {$remarks}");
                $_SESSION['success'] = "Transaction <strong>{$tx['transaction_id']}</strong> adjusted and validated successfully.";
            }
        }

        // 3. REJECT ACTION
        elseif ($action === 'reject') {
            if (empty($remarks)) {
                throw new Exception("Rejection reason is required.");
            }
            if (!str_contains(strtolower($tx['status']), 'pending')) {
                throw new Exception("Transaction has already been processed.");
            }

            $up = $pdo->prepare("UPDATE fuel_transactions SET status = 'Rejected', validated_by = ?, validated_at = NOW(), reject_reason = ? WHERE id = ?");
            $up->execute([$me['id'], $remarks, $tx_id]);

            log_activity($pdo, $me['id'], 'Fuel Reading Rejected', "TXN {$tx['transaction_id']} | Reason: {$remarks}");
            $_SESSION['success'] = "Transaction <strong>{$tx['transaction_id']}</strong> rejected and returned to staff.";
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }

    $redirect_url = 'manager_fuel_pump_master.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirect_url .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: ' . $redirect_url); exit;
}

// ── Fetch Filtered Calibration Records ────────────────────────────────────
$where = ["ft.station_id = ?"];
$params = [$station_id];

// Search filter
if ($search_query !== '') {
    $where[] = "(LOWER(ft.transaction_id) LIKE ? OR LOWER(ft.fuel_type) LIKE ? OR LOWER(fp.pump_number) LIKE ? OR LOWER(staff.username) LIKE ? OR LOWER(staff.first_name) LIKE ? OR LOWER(staff.last_name) LIKE ? OR LOWER(CONCAT(COALESCE(staff.first_name, ''), ' ', COALESCE(staff.last_name, ''))) LIKE ? OR LOWER(staff.name) LIKE ? OR LOWER(ft.notes) LIKE ? OR LOWER(ft.reject_reason) LIKE ?)";
    $like_val = '%' . strtolower($search_query) . '%';
    $params = array_merge($params, [$like_val, $like_val, $like_val, $like_val, $like_val, $like_val, $like_val, $like_val, $like_val, $like_val]);
}

// Status filter: Pending by default so completed ones disappear automatically!
if ($status_filter !== 'all') {
    if ($status_filter === 'pending') {
        $where[] = "(LOWER(ft.status) LIKE '%pending%' OR LOWER(ft.status) IN ('closing_completed', 'submitted', 'adjusted', 'readings_submitted'))";
    } elseif ($status_filter === 'verified') {
        $where[] = "LOWER(ft.status) IN ('verified', 'approved', 'validated')";
    } elseif ($status_filter === 'adjusted') {
        $where[] = "LOWER(ft.status) = 'adjusted'";
    } elseif ($status_filter === 'rejected') {
        $where[] = "LOWER(ft.status) = 'rejected'";
    }
}

// Date Filter
if ($date_filter !== '' && $date_filter !== 'all') {
    $where[] = "DATE(ft.transaction_date) = ?";
    $params[] = $date_filter;
}

// Shift Filter
if ($shift_filter !== 'all') {
    $where[] = "LOWER(ft.shift_period) = ?";
    $params[] = strtolower($shift_filter);
}

// Fuel Type Filter — handle Diesel without colliding with Turbo Diesel
if ($fuel_type_filter !== 'all') {
    $clean_ft = strtolower($fuel_type_filter);
    if ($clean_ft === 'diesel') {
        $where[] = "(LOWER(ft.fuel_type) LIKE '%diesel%' AND LOWER(ft.fuel_type) NOT LIKE '%turbo%')";
    } else {
        $where[] = "LOWER(ft.fuel_type) LIKE ?";
        $params[] = '%' . $clean_ft . '%';
    }
}

// Staff Filter
if ($staff_filter !== '') {
    $where[] = "(LOWER(staff.username) LIKE ? OR LOWER(staff.first_name) LIKE ? OR LOWER(staff.last_name) LIKE ?)";
    $like_val = '%' . strtolower($staff_filter) . '%';
    $params = array_merge($params, [$like_val, $like_val, $like_val]);
}

$records = [];
try {
    $sql = "SELECT ft.*, 
                   fp.pump_number,
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(staff.first_name, '')), ' ', TRIM(COALESCE(staff.last_name, ''))), ' '),
                       staff.username,
                       'Unknown'
                   ) as staff_name,
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(validator.first_name, '')), ' ', TRIM(COALESCE(validator.last_name, ''))), ' '),
                       validator.username,
                       '—'
                   ) as validator_name
            FROM fuel_transactions ft
            LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
            LEFT JOIN users staff ON ft.staff_id = staff.id
            LEFT JOIN users validator ON ft.validated_by = validator.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY
                ft.transaction_date DESC,
                CASE 
                    WHEN LOWER(ft.shift_period) = 'second' THEN 2
                    WHEN LOWER(ft.shift_period) = 'first' THEN 1
                    ELSE 0
                END DESC,
                fp.pump_number ASC,
                ft.id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch calibration records error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading calibration records: " . $e->getMessage();
}

// ── Metrics Calculations ──────────────────────────────────────────
$total_calibration_liters = 0.0;
$pending_reviews_count = 0;
$total_liters_validated = 0.0;

try {
    $kpi_where = ["station_id = ?"];
    $kpi_params = [$station_id];
    if ($date_filter !== '' && $date_filter !== 'all') {
        $kpi_where[] = "DATE(transaction_date) = ?";
        $kpi_params[] = $date_filter;
    }
    $kpi_sql = "
        SELECT 
            COALESCE(SUM(CASE WHEN LOWER(status) IN ('verified','approved','validated','adjusted') THEN calibration ELSE 0 END), 0) as tot_cal,
            COALESCE(SUM(CASE WHEN LOWER(status) IN ('verified','approved','validated','adjusted') THEN liters_sold ELSE 0 END), 0) as tot_val
        FROM fuel_transactions
        WHERE " . implode(" AND ", $kpi_where);
    $kpi_stmt = $pdo->prepare($kpi_sql);
    $kpi_stmt->execute($kpi_params);
    $kpi_row = $kpi_stmt->fetch(PDO::FETCH_ASSOC);
    if ($kpi_row) {
        $total_calibration_liters = (float)$kpi_row['tot_cal'];
        $total_liters_validated   = (float)$kpi_row['tot_val'];
    }

    $pend_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM fuel_transactions
        WHERE station_id = ? AND (LOWER(status) LIKE '%pending%' OR LOWER(status) IN ('closing_completed','submitted','adjusted','readings_submitted'))
    ");
    $pend_stmt->execute([$station_id]);
    $pending_reviews_count = (int)$pend_stmt->fetchColumn();
} catch (Exception $e) {
    error_log("KPI calculation error: " . $e->getMessage());
}

// â”€â”€ Fetch dynamic filters data (from fuel_transactions for accurate type list) â”€
$fuel_types = [];
try {
    // Pull distinct fuel types from actual transactions so the dropdown matches stored data
    $ft_stmt = $pdo->prepare("
        SELECT DISTINCT
            CASE
                WHEN UPPER(fuel_type) LIKE '%TURBO%DIESEL%' THEN 'Turbo Diesel'
                WHEN UPPER(fuel_type) LIKE '%KEROSENE%'     THEN 'Kerosene'
                WHEN UPPER(fuel_type) LIKE '%XCS%'          THEN 'XCS Plus'
                WHEN UPPER(fuel_type) LIKE '%XTRA%UNL%'     THEN 'Xtra UNL'
                WHEN UPPER(fuel_type) LIKE '%DIESEL%'       THEN 'Diesel'
                ELSE fuel_type
            END AS normalized_type
        FROM fuel_transactions
        WHERE station_id = ?
        ORDER BY 1
    ");
    $ft_stmt->execute([$station_id]);
    $fuel_types = array_unique($ft_stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {}

// ── EXPORTS ───────────────────────────────────────────────────────────
if (in_array($export, ['excel', 'pdf'])) {
    $headers = ['Date', 'Shift', 'Pump / Nozzle', 'Fuel Type', 'Staff Encoder', 'Beginning', 'Ending', 'Staff Calibration', 'Manager Calibration', 'Liters Sold', 'Status', 'Validated By', 'Date Validated'];
    $rows_fmt = [];
    foreach ($records as $r) {
        // Normalize fuel type for export
        $fuel_display = $r['fuel_type'];
        $fuel_upper = strtoupper($fuel_display);
        
        if (strpos($fuel_upper, 'TURBO') !== false && strpos($fuel_upper, 'DIESEL') !== false) {
            $fuel_normalized = 'Turbo Diesel';
        } elseif (strpos($fuel_upper, 'KEROSENE') !== false) {
            $fuel_normalized = 'Kerosene';
        } elseif (strpos($fuel_upper, 'XCS') !== false) {
            $fuel_normalized = 'XCS Plus';
        } elseif (strpos($fuel_upper, 'XTRA') !== false || strpos($fuel_upper, 'UNL') !== false) {
            $fuel_normalized = 'Xtra UNL';
        } elseif (strpos($fuel_upper, 'DIESEL') !== false) {
            $fuel_normalized = 'Diesel';
        } else {
            // Fallback: remove numbers and clean up
            $clean = preg_replace('/\s*\d+\s*-?\s*\d*\s*/', ' ', $fuel_display);
            $fuel_normalized = trim(preg_replace('/\s+/', ' ', $clean));
        }
        
        $rows_fmt[] = [
            date('Y-m-d', strtotime($r['transaction_date'])),
            formatShiftLabel($r['shift_period']),
            $r['pump_number'] ?: $r['fuel_type'],
            $fuel_normalized,
            $r['staff_name'] ?? '—',
            number_format($r['previous_reading'], 2),
            number_format($r['present_reading'], 2),
            number_format($r['staff_calibration'], 2) . ' L',
            number_format($r['calibration'], 2) . ' L',
            number_format($r['liters_sold'], 2) . ' L',
            getStatusLabel($r['status']),
            $r['validator_name'] ?? '—',
            $r['validated_at'] ? date('Y-m-d h:i A', strtotime($r['validated_at'])) : '—'
        ];
    }
    $filename = 'calibration_review_' . ($date_filter ?: 'all');

    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Calibration Review Report - ' . htmlspecialchars($date_filter) . '</h2>';
        echo '<p>Station: ' . htmlspecialchars(user_station_name()) . ' | Records: ' . count($rows_fmt) . '</p>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows_fmt as $row) {
            echo '<tr>';
            foreach ($row as $col) echo '<td>' . htmlspecialchars($col) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>'; exit;
    }

    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $tbody = '';
        foreach ($rows_fmt as $row) {
            $tbody .= '<tr>';
            foreach ($row as $col) {
                $tbody .= '<td>' . htmlspecialchars($col) . '</td>';
            }
            $tbody .= '</tr>';
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Calibration Review</title>
        <style>body{font-family:Arial,sans-serif;font-size:10px;padding:20px;color:#333;}
        .pbtn{margin-bottom:12px}@media print{.pbtn{display:none}}
        .hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px;display:flex;align-items:center;justify-content:between;}
        h1{color:#002F6C;font-size:16px;margin:0 0 4px;text-transform:uppercase;}
        table{width:100%;border-collapse:collapse;margin-top:10px;}
        th{background:#002F6C;color:#fff;padding:6px;font-size:8px;text-transform:uppercase;text-align:left;}
        td{padding:5px;border-bottom:1px solid #e2e8f0;font-size:8px;}
        tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;font-weight:bold;">Print</button>
        <a href="javascript:history.back()" style="margin-left:8px;background:#6c757d;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;text-decoration:none;font-weight:bold;">â† Back</a></div>';
        echo '<div class="hdr"><div><h1>Calibration Review</h1><p style="margin:2px 0 0;color:#666;">Date: ' . htmlspecialchars($date_filter) . ' | Station: ' . htmlspecialchars(user_station_name()) . '</p></div></div>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>' . ($tbody ?: '<tr><td colspan="' . count($headers) . '" style="text-align:center;padding:20px;color:#94a3b8">No records found.</td></tr>') . '</tbody></table>';
        echo '</body></html>'; exit;
    }
}

// ── AJAX JSON POLLING ENDPOINT FOR CALIBRATION REVIEW ─────────────────
if (isset($_GET['ajax_cr']) && $_GET['ajax_cr'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'validated'   => number_format((float)$total_liters_validated, 2) . ' L',
            'calibration' => number_format((float)$total_calibration_liters, 2) . ' L',
            'pending'     => number_format($pending_reviews_count)
        ],
        'records_count' => count($records)
    ]);
    exit;
}

require_once __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
* { box-sizing: border-box; }
.mcr-wrap { max-width: 100%; width: 100%; box-sizing: border-box; overflow-x: hidden !important; padding: 0 !important; margin: 0 !important; }
.main-content { max-width: 100% !important; overflow-x: hidden !important; }

/* Petron style headers */
.int-head { display: flex !important; align-items: center !important; justify-content: space-between !important; flex-wrap: wrap !important; gap: 15px !important; margin-top: 0 !important; margin-bottom: 25px !important; padding: 0 !important; border: none !important; width: 100% !important; }
.int-head > div:first-child { flex: 1; min-width: 280px; max-width: 65%; }
.int-head > div:last-child { flex-shrink: 0; display: flex; gap: 8px; flex-wrap: wrap; }
.int-head h1 { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important; font-size: 24px !important; font-weight: 700 !important; color: #002f70 !important; margin: 0 !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; display: flex !important; align-items: center !important; gap: 10px !important; line-height: 1.2 !important; }
.int-head .sub { font-size: 13px; color: #64748b; margin-top: 4px; line-height: 1.4; }

/* Summary Cards (Matches Master Data Requests Exactly) */
.txn-kpi-grid, .mcr-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 18px;
    width: 100%;
    box-sizing: border-box;
}
@media (max-width: 900px) {
    .txn-kpi-grid, .mcr-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .txn-kpi-grid, .mcr-cards {
        grid-template-columns: 1fr;
    }
}
.txn-kpi-card, .mcr-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: none;
    transition: transform .15s, box-shadow .15s;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 86px;
}
.txn-kpi-card:hover, .mcr-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,.09);
}
.txn-kpi-lbl, .mcr-card-lbl {
    font-size: 10px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
    color: #64748b !important;
    margin-bottom: 6px !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    line-height: 1.3 !important;
    white-space: nowrap !important;
}
.txn-kpi-val, .mcr-card-val {
    font-size: 26px !important;
    font-weight: 800 !important;
    color: #002F70;
    line-height: 1.1;
}
.txn-kpi-card.blue .txn-kpi-val,   .mcr-card.blue .mcr-card-val   { color: #0284c7 !important; }
.txn-kpi-card.green .txn-kpi-val,  .mcr-card.green .mcr-card-val  { color: #16a34a !important; }
.txn-kpi-card.yellow .txn-kpi-val, .txn-kpi-card.amber .txn-kpi-val,
.mcr-card.yellow .mcr-card-val,    .mcr-card.amber .mcr-card-val   { color: #d97706 !important; }

.txn-kpi-card.blue .txn-kpi-lbl i,   .mcr-card.blue .mcr-card-lbl i   { color: #0284c7 !important; }
.txn-kpi-card.green .txn-kpi-lbl i,  .mcr-card.green .mcr-card-lbl i  { color: #16a34a !important; }
.txn-kpi-card.yellow .txn-kpi-lbl i, .txn-kpi-card.amber .txn-kpi-lbl i,
.mcr-card.yellow .mcr-card-lbl i,    .mcr-card.amber .mcr-card-lbl i   { color: #d97706 !important; }

/* Filter Bar */
.mcr-filter { 
    display: flex; 
    align-items: flex-end; 
    gap: 8px; 
    flex-wrap: nowrap; 
    background: #fff; 
    border: 1px solid #e2e8f0; 
    border-radius: 10px; 
    padding: 14px 18px; 
    margin-bottom: 20px; 
    box-sizing: border-box; 
    width: 100%;
    overflow-x: auto;
}
.mcr-fg { display: flex; flex-direction: column; gap: 4px; flex-shrink: 1; }
.mcr-fg label { font-size: 12px !important; font-weight: 800 !important; color: #002F70 !important; text-transform: uppercase; letter-spacing: .3px; white-space: nowrap; }
.mcr-fg input, .mcr-fg select { height: 38px !important; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px !important; font-weight: 600 !important; color: #1e293b; background: #fff; outline: none; box-sizing: border-box; width: 100%; }
.mcr-fg input:focus, .mcr-fg select:focus { border-color: #002F70; box-shadow: 0 0 0 3px rgba(0,47,112,.1); }
.mcr-filter-btns { display: flex; gap: 6px; flex-shrink: 0; align-items: flex-end; }
.mcr-filter-btns .ato-btn { height: 38px !important; padding: 0 14px !important; font-size: 13px !important; border-radius: 6px !important; }

/* Table design */
.mcr-table-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 11px; overflow: hidden !important; box-shadow: 0 1px 3px rgba(0,0,0,.04); width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; }
.mcr-table-hd { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #f1f5f9; }
.mcr-table-title { font-size: 16.5px; font-weight: 800; color: #00264D; text-transform: uppercase; letter-spacing: .3px; margin: 0; }
.mcr-tbl-wrap { width: 100% !important; max-width: 100% !important; overflow-x: hidden !important; box-sizing: border-box !important; }
.mcr-tbl { width: 100% !important; max-width: 100% !important; min-width: 0 !important; table-layout: fixed !important; border-collapse: collapse !important; font-size: 13.5px; }
.mcr-tbl thead tr { background: #002F70; }
.mcr-tbl thead th { padding: 12px 6px !important; text-align: left; font-size: 12.5px !important; font-weight: 800 !important; color: #fff; text-transform: uppercase; letter-spacing: .3px; white-space: normal !important; line-height: 1.25 !important; word-break: break-word !important; vertical-align: middle !important; box-sizing: border-box !important; }
.mcr-tbl tbody tr { border-bottom: 1px solid #f1f5f9; }
.mcr-tbl tbody tr:hover td { background: #eff6ff; }
.mcr-tbl tbody td { padding: 9px 6px !important; color: #1e293b !important; vertical-align: middle; background: #fff; font-size: 13px !important; font-weight: 600; line-height: 1.3 !important; white-space: normal !important; word-break: normal !important; overflow-wrap: break-word !important; word-wrap: break-word !important; box-sizing: border-box !important; overflow: hidden !important; }

/* Buttons */
.ato-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 18px; border-radius: 7px; font-size: 14px; font-weight: 700; cursor: pointer; border: 1px solid transparent; text-decoration: none; transition: all .15s; height: 42px; white-space: nowrap; background: white !important; }
.ato-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-print { color: #334155 !important; border-color: #64748b !important; }
.ato-btn-print:hover { background: #64748b !important; color: #fff !important; }
.ato-btn-back { color: #4b5563 !important; border-color: #cbd5e1 !important; }
.ato-btn-back:hover { background: #cbd5e1 !important; }
.ato-btn-filter { color: #002F70 !important; border-color: #002F70 !important; }
.ato-btn-filter:hover { background: #002F70 !important; color: #fff !important; }
.ato-btn-reset { color: #475569 !important; border-color: #cbd5e1 !important; }
.ato-btn-reset:hover { background: #f1f5f9 !important; }

/* Row Actions */
.act-btn { display: flex; align-items: center; justify-content: center; gap: 5px; padding: 0 8px !important; border-radius: 6px; font-size: 12px !important; font-weight: 800 !important; border: 1.5px solid transparent; cursor: pointer; height: 30px !important; text-decoration: none; text-transform: uppercase; background: #ffffff !important; transition: all 0.15s; width: 100%; box-sizing: border-box; }
.act-btn-verify { border-color: #16a34a !important; color: #16a34a !important; background: #ffffff !important; }
.act-btn-verify:hover { background: #16a34a !important; color: #ffffff !important; }
.act-btn-edit { border-color: #0284c7 !important; color: #0284c7 !important; background: #ffffff !important; }
.act-btn-edit:hover { background: #0284c7 !important; color: #ffffff !important; }
.act-btn-reject { border-color: #dc2626 !important; color: #dc2626 !important; background: #ffffff !important; }
.act-btn-reject:hover { background: #dc2626 !important; color: #ffffff !important; }
.act-btn-view { border-color: #475569 !important; color: #475569 !important; background: #ffffff !important; }
.act-btn-view:hover { background: #475569 !important; color: #ffffff !important; }
.act-btn:disabled, .act-btn.disabled { opacity: 0.6; cursor: not-allowed; border-color: #cbd5e1 !important; color: #64748b !important; background: #f8fafc !important; }

/* Status badges */
.badge-st { display: inline-flex; align-items: center; gap: 6px; font-size: 12px !important; font-weight: 800 !important; text-transform: uppercase; letter-spacing: 0.3px; background: none !important; padding: 0; border-radius: 0; white-space: normal !important; line-height: 1.2 !important; word-break: break-word !important; }
.badge-st::before { content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.badge-st.bg-amber { color: #b45309; }
.badge-st.bg-amber::before { background: #d97706; }
.badge-st.bg-green { color: #15803d; }
.badge-st.bg-green::before { background: #16a34a; }
.badge-st.bg-blue { color: #1d4ed8; }
.badge-st.bg-blue::before { background: #2563eb; }
.badge-st.bg-red { color: #b91c1c; }
.badge-st.bg-red::before { background: #dc2626; }
.badge-st.bg-gray { color: #475569; }
.badge-st.bg-gray::before { background: #64748b; }

/* Modal Window styles */
.modal { 
    display: none; 
    position: fixed !important; 
    top: 70px !important; 
    left: 0 !important; 
    right: 0 !important; 
    bottom: 40px !important; 
    z-index: 9999 !important; 
    background: rgba(15, 23, 42, 0.55) !important; 
    backdrop-filter: blur(4px) !important; 
    -webkit-backdrop-filter: blur(4px) !important; 
    align-items: center !important; 
    justify-content: center !important; 
    overflow-y: auto !important; 
    overflow-x: hidden !important; 
    padding: 35px 20px 25px 20px !important; 
    box-sizing: border-box !important; 
}

@media (max-width: 991px) {
    .modal { 
        top: 60px !important; 
        bottom: 0 !important; 
        padding: 20px 12px !important; 
    }
}

.modal.show,
.modal[style*="display: flex"],
.modal[style*="display: block"],
.modal[style*="display:flex"],
.modal[style*="display:block"] {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.modal-content { 
    background: #fff; 
    border-radius: 14px; 
    width: 92%; 
    max-width: 580px; 
    max-height: calc(100vh - 170px) !important; 
    box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.35); 
    overflow: hidden !important; 
    animation: modalIn 0.2s ease; 
    box-sizing: border-box !important; 
    margin: auto !important; 
    display: flex; 
    flex-direction: column; 
}

@keyframes modalIn { 
    from { opacity: 0; transform: translateY(-12px); } 
    to { opacity: 1; transform: none; } 
}

.modal-header { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    padding: 18px 24px; 
    background: #f8fafc; 
    border-bottom: 1.5px solid #e2e8f0; 
    box-sizing: border-box; 
    flex-shrink: 0; 
}

.modal-header h3 { 
    margin: 0; 
    font-size: 16px !important; 
    color: #00264D; 
    font-weight: 800 !important; 
    text-transform: uppercase; 
    letter-spacing: 0.3px; 
}

.modal-body { 
    padding: 22px 24px; 
    overflow-y: auto !important; 
    overflow-x: hidden !important; 
    box-sizing: border-box !important; 
    flex: 1 1 auto; 
    min-height: 0; 
}

.modal-footer { 
    display: flex; 
    gap: 8px; 
    justify-content: flex-end; 
    padding: 14px 24px; 
    border-top: 1.5px solid #e2e8f0; 
    background: #f8fafc; 
    box-sizing: border-box; 
    flex-shrink: 0; 
}

.modal-fg { display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px; }
.modal-fg label { font-size: 13px; font-weight: 800; color: #002F70; text-transform: uppercase; }
.modal-fg input, .modal-fg textarea, .modal-fg select { padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; font-weight: 600; color: #1e293b; background: #fff; outline: none; }
.modal-fg input:focus, .modal-fg textarea:focus { border-color: #002F70; }
.modal-fg input[readonly] { background-color: #f8fafc; color: #64748b; }

.details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 22px; }
.details-item { border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; }
.details-item.full-width { grid-column: span 2; }
.details-lbl { font-size: 12.5px; color: #64748b; text-transform: uppercase; font-weight: 800; }
.details-val { font-size: 15px; color: #1e293b; font-weight: 700; margin-top: 3px; }
</style>

<div class="mcr-wrap">
    <!-- Page Header -->
    <div class="int-head">
        <div>
            <h1><i class="fas fa-check-double"></i> Fuel Transaction Validation</h1>
        </div>
    </div>

    <!-- Summary Cards (Matches Master Data Requests Design & Size) -->
    <div class="txn-kpi-grid">
        <div class="txn-kpi-card blue">
            <div class="txn-kpi-lbl"><i class="fas fa-gas-pump" style="color:#0284c7;margin-right:4px;"></i> Total Validated Liters</div>
            <div class="txn-kpi-val" id="cr_kpi_validated"><?= number_format($total_liters_validated, 2) ?> L</div>
        </div>
        <div class="txn-kpi-card green">
            <div class="txn-kpi-lbl"><i class="fas fa-tint" style="color:#16a34a;margin-right:4px;"></i> Total Calibration Liters</div>
            <div class="txn-kpi-val" id="cr_kpi_calibration"><?= number_format($total_calibration_liters, 2) ?> L</div>
        </div>
        <div class="txn-kpi-card yellow">
            <div class="txn-kpi-lbl"><i class="fas fa-clock" style="color:#d97706;margin-right:4px;"></i> Pending Review Rows</div>
            <div class="txn-kpi-val" id="cr_kpi_pending"><?= number_format($pending_reviews_count) ?></div>
        </div>
    </div>

    <!-- Filters Form -->
    <form method="get" class="mcr-filter">
        <div class="mcr-fg" style="flex: 1 1 140px; min-width: 120px;">
            <label>Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="TXN, pump, staff...">
        </div>
        <div class="mcr-fg" style="width: 155px; flex-shrink: 0;">
            <label>Status</label>
            <select name="status">
                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending Review (Active)</option>
                <option value="verified" <?= $status_filter === 'verified' ? 'selected' : '' ?>>Verified / Approved</option>
                <option value="adjusted" <?= $status_filter === 'adjusted' ? 'selected' : '' ?>>Adjusted</option>
                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
            </select>
        </div>
        <div class="mcr-fg" style="width: 130px; flex-shrink: 0;">
            <label>Review Date</label>
            <input type="date" name="date" value="<?= htmlspecialchars($date_filter) ?>">
        </div>
        <div class="mcr-fg" style="width: 105px; flex-shrink: 0;">
            <label>Shift</label>
            <select name="shift">
                <option value="all">All Shifts</option>
                <option value="first" <?= $shift_filter === 'first' ? 'selected' : '' ?>>Shift 1</option>
                <option value="second" <?= $shift_filter === 'second' ? 'selected' : '' ?>>Shift 2</option>
            </select>
        </div>
        <div class="mcr-fg" style="width: 130px; flex-shrink: 0;">
            <label>Fuel Type</label>
            <select name="fuel_type">
                <option value="all">All Fuel Types</option>
                <?php foreach ($fuel_types as $ft): ?>
                    <option value="<?= htmlspecialchars($ft) ?>" <?= strtolower($fuel_type_filter) === strtolower($ft) ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mcr-fg" style="width: 130px; flex-shrink: 0;">
            <label>Staff Encoder</label>
            <input type="text" name="staff" value="<?= htmlspecialchars($staff_filter) ?>" placeholder="Staff name...">
        </div>
        <div class="mcr-filter-btns">
            <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-search"></i> Filter</button>
            <a href="manager_fuel_pump_master.php" class="ato-btn ato-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>

    <!-- Calibration Review Table Card -->
    <div class="mcr-table-card">
        <div class="mcr-table-hd">
            <h3 class="mcr-table-title"><i class="fas fa-table"></i> Shift-Based Readings & Calibration Logs</h3>
        </div>
        <div class="mcr-tbl-wrap">
            <table class="mcr-tbl" style="width:100% !important;max-width:100% !important;min-width:0 !important;table-layout:fixed !important;border-collapse:collapse !important;">
                <colgroup>
                    <col style="width: 8%;">    <!-- Date -->
                    <col style="width: 5.5%;">  <!-- Shift -->
                    <col style="width: 9.5%;">  <!-- Pump / Nozzle -->
                    <col style="width: 7.5%;">  <!-- Fuel Type -->
                    <col style="width: 16.5%;"> <!-- Staff -->
                    <col style="width: 8.5%;">  <!-- Beginning -->
                    <col style="width: 7.5%;">  <!-- Ending -->
                    <col style="width: 8%;">    <!-- Staff Cal -->
                    <col style="width: 8.5%;">  <!-- Mgr Cal -->
                    <col style="width: 9.5%;">  <!-- Status -->
                    <col style="width: 11%;">   <!-- Actions -->
                </colgroup>
                <thead>
                    <tr>
                        <th style="text-align:left;">Date</th>
                        <th style="text-align:left;">Shift</th>
                        <th style="text-align:left;">Pump / Nozzle</th>
                        <th style="text-align:left;">Fuel Type</th>
                        <th style="text-align:left;">Staff</th>
                        <th style="text-align:right;">Beginning</th>
                        <th style="text-align:right;">Ending</th>
                        <th style="text-align:right;">Staff Cal. (L)</th>
                        <th style="text-align:right;">Manager Cal. (L)</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="11" style="text-align:center;padding:40px;color:#94a3b8;">
                                <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                                No calibration/readings records found for the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): 
                            $status = strtolower($r['status'] ?? 'pending validation');
                            $tx_date = date('Y-m-d', strtotime($r['transaction_date']));
                            $is_admin_user = in_array(strtolower(trim($role)), ['admin', 'superadmin', 'manager', 'developer', 'owner']) || in_array(strtolower(trim($me['role'] ?? '')), ['admin', 'superadmin', 'manager', 'developer', 'owner']);
                            $preceding_ok = true;
                            
                            $is_pending_action = str_contains($status, 'pending') || in_array($status, ['closing_completed', 'submitted', 'readings_submitted', 'adjusted']);
                            
                            // Check sequence validation: Admin and Managers are never locked out
                            if (!$is_admin_user && $is_pending_action && $r['pump_id'] > 0) {
                                $preceding_ok = is_preceding_shift_validated($pdo, $station_id, $r['pump_id'], $r['shift_period'], $tx_date);
                            }
                            
                            $shift_lbl = formatShiftLabel($r['shift_period']);
                            $pump_lbl = $r['pump_number'] ?: $r['fuel_type'];

                            $prec_end_calc = get_preceding_shift_validated_ending($pdo, $station_id, $r['pump_id'], $r['shift_period'], $tx_date);
                            $calc_beginning = $prec_end_calc > 0 ? $prec_end_calc : (float)$r['previous_reading'];

                            $view_modal_payload = [
                                'txn_id' => $r['transaction_id'],
                                'pump' => $pump_lbl,
                                'fuel_type' => $r['fuel_type'],
                                'beginning' => number_format($r['previous_reading'], 2),
                                'ending' => number_format($r['present_reading'], 2),
                                'staff_cal' => number_format($r['staff_calibration'], 2) . ' L',
                                'mgr_cal' => number_format($r['calibration'], 2) . ' L',
                                'liters_sold' => number_format($r['liters_sold'], 2) . ' L',
                                'total_amount' => '₱' . number_format($r['total_amount'], 2),
                                'staff' => $r['staff_name'],
                                'status' => getStatusLabel($r['status']),
                                'validator' => $r['validator_name'] ?: '—',
                                'validated_at' => $r['validated_at'] ? date('M d, Y h:i A', strtotime($r['validated_at'])) : '—',
                                'remarks' => $r['reject_reason'] ?: '—'
                            ];
                        ?>
                            <tr>
                                <td style="font-weight:700; color:#1e293b; font-size:13px; white-space:nowrap;"><?= date('M d, Y', strtotime($r['transaction_date'])) ?></td>
                                <td><strong style="color:#002F70; font-size:13px; white-space:nowrap;"><?= htmlspecialchars($shift_lbl) ?></strong></td>
                                <td>
                                    <span style="font-weight:800; color:#002F70; font-size:13px; display:block; word-break:break-word;">
                                        <?= htmlspecialchars($pump_lbl) ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-weight:700; color:#334155; font-size:13px; display:block;">
                                        <?= htmlspecialchars(normalizeFuelType($r['fuel_type'])) ?>
                                    </span>
                                </td>
                                <td style="font-weight:700; color:#0f172a; font-size:13px; line-height:1.25; word-break:normal; overflow-wrap:break-word; word-wrap:break-word;"><?= htmlspecialchars($r['staff_name']) ?></td>
                                <td style="text-align:right; font-family: monospace; font-size:13.5px; font-weight:700; color:#334155; white-space:nowrap;"><?= number_format($r['previous_reading'], 2) ?></td>
                                <td style="text-align:right; font-family: monospace; font-size:13.5px; font-weight:800; color:#002F70; white-space:nowrap;"><?= number_format($r['present_reading'], 2) ?></td>
                                <td style="text-align:right; font-family: monospace; font-size:13px; font-weight:700; color:#475569; white-space:nowrap;"><?= number_format($r['staff_calibration'], 2) ?> L</td>
                                <td style="text-align:right; font-family: monospace; font-size:13px; font-weight:800; color:#0f172a; white-space:nowrap;"><?= number_format($r['calibration'], 2) ?> L</td>
                                <td style="text-align:center;">
                                    <span class="badge-st <?= getStatusBadgeClass($r['status']) ?>" style="font-size:12px !important; font-weight:800;"><?= getStatusLabel($r['status']) ?></span>
                                </td>
                                <td style="text-align:center; padding: 6px 4px !important; vertical-align:middle;">
                                    <div style="display:flex; flex-direction:column; gap:4px; align-items:stretch; width:100%; min-width:0;">
                                        <!-- View Details (Always available for all statuses and roles) -->
                                        <button type="button" class="act-btn act-btn-view" onclick="openViewModal(<?= htmlspecialchars(json_encode($view_modal_payload)) ?>)" title="View Details"><i class="fas fa-eye"></i> View</button>

                                    <?php if ($is_pending_action): ?>
                                        <?php if ($preceding_ok): ?>
                                            <!-- Verify -->
                                            <form method="post" style="margin:0;" onsubmit="return confirm('Verify and approve this entry? Beginning reading will match preceding shift ending.');">
                                                <input type="hidden" name="action" value="verify">
                                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="act-btn act-btn-verify" title="Verify Readings"><i class="fas fa-check"></i> Verify</button>
                                            </form>
                                            <!-- Edit/Adjust -->
                                            <button type="button" class="act-btn act-btn-edit" onclick="openAdjustModal(<?= htmlspecialchars(json_encode([
                                                'id' => $r['id'],
                                                'txn_id' => $r['transaction_id'],
                                                'pump' => $pump_lbl,
                                                'fuel_type' => $r['fuel_type'],
                                                'beginning' => $calc_beginning,
                                                'ending' => $r['present_reading'],
                                                'calibration' => $r['calibration'],
                                                'price' => $r['price_per_liter']
                                            ])) ?>)" title="Adjust Readings"><i class="fas fa-edit"></i> Edit</button>
                                            <!-- Reject -->
                                            <button type="button" class="act-btn act-btn-reject" onclick="openRejectModal(<?= $r['id'] ?>, '<?= $r['transaction_id'] ?>')" title="Reject"><i class="fas fa-times"></i> Reject</button>
                                        <?php else: ?>
                                            <?php 
                                                $preceding = get_preceding_shift_and_date($pdo, $r['shift_period'], $tx_date);
                                                $prec_lbl = $preceding ? (formatShiftLabel($preceding['shift_key']) . ' on ' . $preceding['date']) : 'Shift 1';
                                            ?>
                                            <button type="button" class="act-btn disabled" disabled title="Waiting for preceding shift (<?= $prec_lbl ?>) validation"><i class="fas fa-lock"></i> Locked</button>
                                            <span style="font-size:11px; font-weight:700; color:#dc2626; text-align:center; display:block; margin-top:2px;">Check preceding shift</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Calibration Review Pagination Footer -->
        <div id="mcrPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13.5px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="mcrShowingEntriesText" style="font-size:13.5px; color:#475569; font-weight:700;">Showing <?= empty($records) ? '0' : '1–'.min(10, count($records)) ?> of <?= count($records) ?> entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:700; color:#475569; font-size:13.5px;">Rows per page:</label>
                    <select id="mcrPerPage" onchange="mcrChangePerPage()" style="padding:5px 9px; border:1px solid #cbd5e1; border-radius:6px; font-size:13.5px; font-weight:700; background:transparent !important; color:#1e293b; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="mcrPrevBtn" onclick="mcrGoPage(mcrState.page - 1)" 
                            style="width:34px; height:34px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="mcrPageLabel" style="color:#1e293b; font-size:13.5px; font-weight:700; padding:0 4px;">Page 1 of <?= max(1, ceil(count($records) / 10)) ?></span>
                    <button id="mcrNextBtn" onclick="mcrGoPage(mcrState.page + 1)" 
                            style="width:34px; height:34px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($records) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($records) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Adjust / Edit Modal -->
<div id="adjustModal" class="modal">
    <div class="modal-content">
        <form method="post">
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="id" id="adj_tx_id">
            
            <div class="modal-header">
                <h3>Adjust Calibration & Readings</h3>
            </div>
            
            <div class="modal-body">
                <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 10px; font-size: 11px; color: #1e3a8a; margin-bottom: 14px;">
                    <i class="fas fa-info-circle"></i> <strong>Sequence Rules:</strong> The beginning reading is programmatically set to match the validated ending reading of the preceding shift to maintain a seamless audit trail.
                </div>
                
                <div class="details-grid" style="margin-bottom: 12px;">
                    <div>
                        <span class="details-lbl">Fuel Line</span>
                        <div class="details-val" id="adj_lbl_pump"></div>
                    </div>
                    <div>
                        <span class="details-lbl">Fuel Type</span>
                        <div class="details-val" id="adj_lbl_fuel"></div>
                    </div>
                </div>

                <div class="modal-fg">
                    <label>Beginning Reading (Preceding Ending)</label>
                    <input type="number" step="0.01" id="adj_beginning" name="beginning" <?= in_array(strtolower(trim($role)), ['admin', 'superadmin', 'manager', 'developer', 'owner']) ? '' : 'readonly' ?> oninput="calculateAdjustedLiters()">
                </div>
                <div class="modal-fg">
                    <label>Ending Reading</label>
                    <input type="number" step="0.01" id="adj_ending" name="ending" required oninput="calculateAdjustedLiters()">
                </div>
                <div class="modal-fg">
                    <label>Manager Calibration (Liters)</label>
                    <input type="number" step="0.1" id="adj_calibration" name="calibration" required oninput="calculateAdjustedLiters()">
                </div>
                
                <div class="modal-fg" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-top: 10px;">
                    <span class="details-lbl">Calculated Liters Sold</span>
                    <div id="adj_calculated_liters" style="font-size: 16px; font-weight: 700; color: #00264D; margin-top: 2px;">0.00 L</div>
                </div>
                
                <div class="modal-fg" style="margin-top: 10px;">
                    <label>Reason for Adjustment</label>
                    <textarea name="remarks" rows="3" required placeholder="Provide clear reason for this override..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="ato-btn ato-btn-reset" onclick="closeModal('adjustModal')">Cancel</button>
                <button type="submit" class="ato-btn ato-btn-filter" style="background: #002F70 !important; color: white !important;"><i class="fas fa-save"></i> Save Adjustment</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <form method="post">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="reject_tx_id">
            
            <div class="modal-header">
                <h3>Reject Reading Entry</h3>
            </div>
            
            <div class="modal-body">
                <p style="font-size: 12.5px; color: #475569; margin-bottom: 12px;">
                    Are you sure you want to reject transaction <strong id="reject_lbl_txn"></strong>? It will be returned to the staff for re-encoding.
                </p>
                <div class="modal-fg">
                    <label>Reason for Rejection</label>
                    <textarea name="remarks" rows="3" required placeholder="Specify why the meter reading/calibration was rejected..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="ato-btn ato-btn-reset" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="ato-btn ato-btn-pdf" style="background: #dc2626 !important; color: white !important;"><i class="fas fa-times"></i> Reject Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- View Details Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Calibration Entry Audit Details</h3>
        </div>
        <div class="modal-body">
            <div class="details-grid">
                <div class="details-item">
                    <div class="details-lbl">Transaction ID</div>
                    <div class="details-val" id="view_txn_id"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Fuel Line (Pump)</div>
                    <div class="details-val" id="view_pump"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Fuel Type</div>
                    <div class="details-val" id="view_fuel_type"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Staff Encoder</div>
                    <div class="details-val" id="view_staff"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Beginning Reading</div>
                    <div class="details-val" id="view_beginning"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Ending Reading</div>
                    <div class="details-val" id="view_ending"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Staff Calibration</div>
                    <div class="details-val" id="view_staff_cal"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Manager Calibration</div>
                    <div class="details-val" id="view_mgr_cal" style="font-weight:700;"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Liters Sold</div>
                    <div class="details-val" id="view_liters_sold"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Total Amount</div>
                    <div class="details-val" id="view_total_amount"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Review Status</div>
                    <div class="details-val" id="view_status"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Validator Manager</div>
                    <div class="details-val" id="view_validator"></div>
                </div>
                <div class="details-item full-width">
                    <div class="details-lbl">Validation Timestamp</div>
                    <div class="details-val" id="view_validated_at"></div>
                </div>
                <div class="details-item full-width">
                    <div class="details-lbl">Manager Notes / Reason</div>
                    <div class="details-val" id="view_remarks" style="white-space:pre-wrap;font-weight:normal;color:#475569;"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="ato-btn ato-btn-reset" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
let activePrice = 0.00;

function openAdjustModal(data) {
    document.getElementById('adj_tx_id').value = data.id;
    document.getElementById('adj_lbl_pump').innerText = data.pump;
    document.getElementById('adj_lbl_fuel').innerText = data.fuel_type;
    document.getElementById('adj_beginning').value = parseFloat(data.beginning).toFixed(2);
    document.getElementById('adj_ending').value = parseFloat(data.ending).toFixed(2);
    document.getElementById('adj_calibration').value = parseFloat(data.calibration).toFixed(2);
    activePrice = parseFloat(data.price || 0);
    calculateAdjustedLiters();
    document.getElementById('adjustModal').style.display = 'flex';
}

function calculateAdjustedLiters() {
    const beginning = parseFloat(document.getElementById('adj_beginning').value) || 0;
    const ending = parseFloat(document.getElementById('adj_ending').value) || 0;
    const calibration = parseFloat(document.getElementById('adj_calibration').value) || 0;
    const liters = Math.max(0, ending - beginning - calibration);
    document.getElementById('adj_calculated_liters').innerText = liters.toFixed(2) + ' L';
}

function openRejectModal(id, txnId) {
    document.getElementById('reject_tx_id').value = id;
    document.getElementById('reject_lbl_txn').innerText = txnId;
    document.getElementById('rejectModal').style.display = 'flex';
}

function openViewModal(data) {
    document.getElementById('view_txn_id').innerText = data.txn_id;
    document.getElementById('view_pump').innerText = data.pump;
    document.getElementById('view_fuel_type').innerText = data.fuel_type;
    document.getElementById('view_staff').innerText = data.staff;
    document.getElementById('view_beginning').innerText = data.beginning;
    document.getElementById('view_ending').innerText = data.ending;
    document.getElementById('view_staff_cal').innerText = data.staff_cal;
    document.getElementById('view_mgr_cal').innerText = data.mgr_cal;
    document.getElementById('view_liters_sold').innerText = data.liters_sold;
    document.getElementById('view_total_amount').innerText = data.total_amount;
    document.getElementById('view_status').innerText = data.status;
    document.getElementById('view_validator').innerText = data.validator;
    document.getElementById('view_validated_at').innerText = data.validated_at;
    document.getElementById('view_remarks').innerText = data.remarks;
    document.getElementById('viewModal').style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// ── Calibration Review Pagination ──
var mcrState = { page: 1, per_page: 10 };

function mcrRender() {
    const tableBody = document.querySelector('.mcr-tbl tbody');
    if (!tableBody) return;

    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    const validRows = allRows.filter(r => !r.querySelector('.fa-inbox'));
    const tot = validRows.length;
    const pp = mcrState.per_page || 10;
    const tp = Math.max(1, Math.ceil(tot / pp));

    if (mcrState.page > tp) mcrState.page = tp;
    if (mcrState.page < 1) mcrState.page = 1;
    const p = mcrState.page;

    const start = (p - 1) * pp;
    const end   = p * pp;

    validRows.forEach(function(r, i) {
        r.style.display = (i >= start && i < end) ? '' : 'none';
    });

    // Update text counter
    const showingStart = tot === 0 ? 0 : start + 1;
    const showingEnd   = Math.min(end, tot);
    const entriesLbl   = document.getElementById('mcrShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
    }

    const lbl = document.getElementById('mcrPageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

    const prev = document.getElementById('mcrPrevBtn');
    const next = document.getElementById('mcrNextBtn');
    if (prev) {
        prev.disabled = (p <= 1);
        prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
        prev.style.color = prev.disabled ? '#cbd5e1' : '#475569';
    }
    if (next) {
        next.disabled = (p >= tp);
        next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
        next.style.color = next.disabled ? '#cbd5e1' : '#475569';
    }
}

window.mcrState = mcrState;
window.mcrGoPage = function(p) {
    const tableBody = document.querySelector('.mcr-tbl tbody');
    if (!tableBody) return;
    const validRows = Array.from(tableBody.querySelectorAll('tr')).filter(r => !r.querySelector('.fa-inbox'));
    const tp = Math.max(1, Math.ceil(validRows.length / (mcrState.per_page || 10)));
    if (p < 1 || p > tp) return;
    mcrState.page = p;
    mcrRender();
};

window.mcrChangePerPage = function() {
    const s = document.getElementById('mcrPerPage');
    if (s) mcrState.per_page = parseInt(s.value, 10);
    mcrState.page = 1;
    mcrRender();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mcrRender);
} else {
    mcrRender();
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
