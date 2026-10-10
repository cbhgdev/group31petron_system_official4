<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'calendar';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/calendar_module_helpers.php';
require_login();
calendar_ensure_schema($pdo);

$me = current_user();
$rk = role_key($me['role'] ?? '');
if (!in_array($rk, ['staff','manager','admin','superadmin'])) { header('Location: dashboard.php'); exit; }
$station_id = user_station_id();
if (!$station_id) { die('Error: Not assigned to a station.'); }
$user_id = $me['id'];

// Handle AJAX requests
if (isset($_POST['action']) && $_POST['action'] === 'save_event') {
    header('Content-Type: application/json');
    
    try {
        $event_id = $_POST['event_id'] ?? '';
        $event_date = calendar_normalize_date($_POST['event_date'] ?? '');
        $event_type = calendar_normalize_event_type($_POST['event_type'] ?? '');
        $work_description = calendar_clean_text($_POST['work_description'] ?? '');
        $start_time = calendar_normalize_time($_POST['start_time'] ?? '');
        $end_time = calendar_normalize_time($_POST['end_time'] ?? '', $start_time);
        $status = calendar_normalize_status($_POST['status'] ?? 'pending');

        if ($event_date === '' || $event_type === '' || $work_description === '') {
            echo json_encode(['success' => false, 'message' => 'Please complete the date, type, and description.']);
            exit;
        }

        if ($start_time !== '00:00:00' && $end_time !== '00:00:00' && $end_time < $start_time) {
            echo json_encode(['success' => false, 'message' => 'End time must be later than start time.']);
            exit;
        }
        
        // Collect all dynamic fields into metadata JSON
        $metadata = [];
        
        // Event type specific fields
        switch($event_type) {
            case 'staff_shift':
                $metadata['shift_type'] = $_POST['shift_type'] ?? '';
                $metadata['shift_status'] = $_POST['shift_status'] ?? '';
                break;
                
            case 'job_order':
                $metadata['service_type'] = $_POST['service_type'] ?? '';
                $metadata['customer_name'] = $_POST['customer_name'] ?? '';
                $metadata['job_status'] = $_POST['job_status'] ?? '';
                break;
                
            case 'fuel_delivery':
            case 'merchandise_delivery':
                $metadata['supplier'] = $_POST['supplier'] ?? '';
                $metadata['product'] = $_POST['product'] ?? '';
                $metadata['expected_qty'] = $_POST['expected_qty'] ?? 0;
                $metadata['actual_qty'] = $_POST['actual_qty'] ?? 0;
                $metadata['variance_qty'] = floatval($_POST['actual_qty'] ?? 0) - floatval($_POST['expected_qty'] ?? 0);
                break;
                
            case 'fuel_calibration':
            case 'meter_reading':
                $metadata['pump_number'] = $_POST['pump_number'] ?? '';
                $metadata['expected_reading'] = $_POST['expected_reading'] ?? 0;
                $metadata['actual_reading'] = $_POST['actual_reading'] ?? 0;
                $expected = floatval($_POST['expected_reading'] ?? 0);
                $actual = floatval($_POST['actual_reading'] ?? 0);
                $variance = $actual - $expected;
                $metadata['variance'] = $variance;
                $metadata['variance_percent'] = $expected > 0 ? ($variance / $expected) * 100 : 0;
                break;
                
            case 'customer_transaction':
            case 'payment_collection':
                $metadata['customer_id'] = $_POST['customer_id'] ?? '';
                $metadata['amount'] = $_POST['amount'] ?? 0;
                $metadata['payment_status'] = $_POST['payment_status'] ?? 'unpaid';
                break;
        }
        
        $metadata_json = json_encode($metadata);
        
        // Check for schedule conflicts before saving
        if (calendar_has_time_range($start_time, $end_time) && $status !== 'cancelled' && empty($_POST['force_save'])) {
            $conflict_check = $pdo->prepare("SELECT COUNT(*) FROM staff_calendar_events 
                WHERE staff_encoder_id = ? 
                AND event_date = ? 
                AND start_time IS NOT NULL 
                AND end_time IS NOT NULL
                AND status != 'cancelled'
                AND id != ?
                AND (
                    (start_time < ? AND end_time > ?)
                    OR (start_time < ? AND end_time > ?)
                    OR (start_time >= ? AND end_time <= ?)
                )");
            $conflict_check->execute([
                $user_id, 
                $event_date, 
                $event_id ?: 0,
                $end_time, $start_time,  // Check if new event overlaps existing
                $end_time, $start_time,  // Check if new event overlaps existing
                $start_time, $end_time   // Check if new event contains existing
            ]);
            
            $conflict_count = $conflict_check->fetchColumn();
            if ($conflict_count > 0) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Schedule conflict detected! You have overlapping events on this date.',
                    'conflict' => true
                ]);
                exit;
            }
        }
        
        // Get or create event_type_id
        $event_type_id = calendar_event_type_id($pdo, $event_type);
        
        // Check if metadata column exists, if not add it
        try {
            $pdo->query("SELECT metadata FROM staff_calendar_events LIMIT 1");
        } catch (Exception $e) {
            // Add metadata column if it doesn't exist
            try {
                $pdo->exec("ALTER TABLE staff_calendar_events ADD COLUMN metadata TEXT NULL");
            } catch (Exception $e2) {}
        }
        
        if ($event_id) {
            // Update existing event
            $update_stmt = $pdo->prepare("UPDATE staff_calendar_events SET 
                event_date = ?, event_type_id = ?, work_description = ?, 
                start_time = ?, end_time = ?, status = ?, metadata = ?
                WHERE id = ? AND staff_encoder_id = ?");
            $update_stmt->execute([
                $event_date, $event_type_id, $work_description, 
                $start_time, $end_time, $status, $metadata_json,
                $event_id, $user_id
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Event updated successfully']);
        } else {
            // Create new event
            $insert_stmt = $pdo->prepare("INSERT INTO staff_calendar_events 
                (station_id, staff_encoder_id, event_type_id, event_date, work_description, 
                start_time, end_time, status, metadata, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $insert_stmt->execute([
                $station_id, $user_id, $event_type_id, $event_date, $work_description, 
                $start_time, $end_time, $status, $metadata_json
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Event created successfully']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_event') {
    header('Content-Type: application/json');
    
    try {
        $event_id = $_GET['event_id'] ?? '';
        
        $stmt = $pdo->prepare("SELECT sce.*, et.type_key 
            FROM staff_calendar_events sce
            JOIN staff_event_types et ON sce.event_type_id = et.id
            WHERE sce.id = ? AND sce.staff_encoder_id = ?");
        $stmt->execute([$event_id, $user_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($event) {
            // Decode metadata JSON
            if (!empty($event['metadata'])) {
                $metadata = json_decode($event['metadata'], true);
                $event = array_merge($event, $metadata ?: []);
            }
            
            echo json_encode(['success' => true, 'event' => $event]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Event not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// Month navigation
$today = new DateTime();
$month_offset = (int)($_GET['month_offset'] ?? 0);
$current_view = $_GET['view'] ?? 'month'; // day, week, month, year

$current_month = clone $today;
$current_month->modify($month_offset . ' months');
$current_month->modify('first day of this month');

$month_name = $current_month->format('F Y');
$prev_offset = $month_offset - 1;
$next_offset = $month_offset + 1;
$today_str = $today->format('Y-m-d');
$current_month_str = $current_month->format('Y-m');

// Get all days in month
$first_day = clone $current_month;
$last_day = clone $current_month;
$last_day->modify('last day of this month');

// Get calendar grid (include previous/next month days to fill weeks)
$calendar_start = clone $first_day;
$start_weekday = (int)$calendar_start->format('N'); // 1=Monday, 7=Sunday
if ($start_weekday > 1) {
    $calendar_start->modify('-' . ($start_weekday - 1) . ' days');
}

$calendar_end = clone $last_day;
$end_weekday = (int)$calendar_end->format('N');
if ($end_weekday < 7) {
    $calendar_end->modify('+' . (7 - $end_weekday) . ' days');
}

// Build array of all dates to display
$calendar_dates = [];
$current_date = clone $calendar_start;
while ($current_date <= $calendar_end) {
    $calendar_dates[] = $current_date->format('Y-m-d');
    $current_date->modify('+1 day');
}

// Prepare date ranges based on view
switch($current_view) {
    case 'day':
        $view_start = $today_str;
        $view_end = $today_str;
        $view_title = $today->format('l, F j, Y');
        break;
    case 'week':
        $week_start = clone $today;
        $week_start->modify('sunday this week');
        $week_end = clone $week_start;
        $week_end->modify('+6 days');
        $view_start = $week_start->format('Y-m-d');
        $view_end = $week_end->format('Y-m-d');
        $view_title = $week_start->format('M j') . ' - ' . $week_end->format('M j, Y');
        break;
    case 'year':
        $year_start = clone $today;
        $year_start->modify('first day of January ' . $today->format('Y'));
        $year_end = clone $year_start;
        $year_end->modify('last day of December ' . $today->format('Y'));
        $view_start = $year_start->format('Y-m-d');
        $view_end = $year_end->format('Y-m-d');
        $view_title = $today->format('Y');
        break;
    default: // month
        $view_start = $calendar_dates[0];
        $view_end = end($calendar_dates);
        $view_title = $month_name;
}



// Load events for the entire calendar range
$month_events = [];
$staff_list = [];
$staff_colors = ['#039be5', '#7986cb', '#33b679', '#8e24aa', '#e67c73', '#f6bf26', '#f4511e', '#0b8043', '#d50000'];

try {
    // Load staff list with assigned colors (Logged-in staff ONLY)
    $staff_stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE id = ? AND status = 'Active'");
    $staff_stmt->execute([$user_id]);
    $all_staff = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($all_staff as $idx => $staff) {
        $color = $staff_colors[$idx % count($staff_colors)];
        $staff_list[$staff['id']] = [
            'name' => $staff['name'],
            'color' => $color
        ];
    }
} catch (Exception $e) {}
// ── COMPLETE DEDUPLICATED STAFF CALENDAR ENGINE ────────────────────────────
    
    // Master Target URL Resolver for Direct Module Navigation on Event Click
    $resolve_target_url = function($type_key, $id, $role = 'staff') {
        $id_num = preg_replace('/[^0-9]/', '', (string)$id);
        switch($type_key) {
            case 'job_order':
                if ($role === 'admin') return 'admin_all_transactions.php?search=JO-' . $id_num;
                if ($role === 'manager') return 'manager_transactions_hub.php?tab=job_orders';
                return 'staff_transactions_hub.php?tab=job_orders';
            case 'merchandise_delivery':
            case 'fuel_delivery':
                if ($role === 'admin') return 'admin_fuel_transactions_oversight.php';
                if ($role === 'manager') return 'deliveries_oversight.php';
                return 'staff_transactions_hub.php?tab=deliveries';
            case 'validation_task':
            case 'validation_delivery':
            case 'manager_approval':
            case 'master_data_approval':
            case 'admin_approval':
                if ($role === 'admin') return 'users.php';
                return 'manager_fuel_transaction_validation.php';
            case 'fuel_calibration':
                if ($role === 'admin') return 'admin_calibration_review.php';
                if ($role === 'manager') return 'manager_calibration_review.php';
                return 'staff_fuel_adjustments.php';
            case 'stock_request':
            case 'restock_reminder':
            case 'stock_alert':
                if ($role === 'admin') return 'admin_inventory_management.php';
                return 'inventory_management.php';
            case 'report_schedule':
                if ($role === 'admin') return 'admin_reports.php';
                if ($role === 'manager') return 'manager_reports.php';
                return 'staff_fuel_sales_summary.php';
            default:
                return '#';
        }
    };

$seen_events = []; // Keeps track of unique [date][type_id] to prevent duplicates

    // Helper closure to safely insert unique events
    $add_unique_event = function($date, $event) use (&$month_events, &$seen_events, $resolve_target_url) {
        if (!$date || !isset($event['id'])) return;
        $key = (string)$event['id'];
        if (isset($seen_events[$date][$key])) {
            return; // Skip duplicate!
        }
        $seen_events[$date][$key] = true;
        if (!isset($event['target_url'])) {
            $event['target_url'] = $resolve_target_url($event['type_key'] ?? '', $event['id'] ?? '', 'staff');
        }
        $month_events[$date][] = $event;
    };

    // Comprehensive operational events loading using centralized helper
    $fetched_events = calendar_fetch_all_station_events($pdo, (int)$station_id, $view_start, $view_end, (int)$user_id, 'staff');
    foreach ($fetched_events as $date => $evts) {
        foreach ($evts as $evt) {
            $add_unique_event($date, $evt);
        }
    }

    $calendar_short_status = function($st) {
        $s = strtolower(trim((string)$st));
        if ($s === 'readings_submitted' || $s === 'readings submitted') return 'Submitted';
        if ($s === 'closing_completed' || $s === 'closing completed') return 'Completed';
        if (strpos($s, 'stock-in') !== false || strpos($s, 'stocked') !== false) return 'Stocked In';
        if ($s === 'verified') return 'Verified';
        if ($s === 'completed' || $s === 'done') return 'Completed';
        if ($s === 'official') return 'Official';
        if ($s === 'adjusted') return 'Adjusted';
        if ($s === 'voided') return 'Voided';
        if ($s === 'scheduled') return 'Scheduled';
        if ($s === 'active') return 'Active';
        if ($s === 'pending') return 'Pending';
        return ucwords(str_replace('_', ' ', $s));
    };

    // ─── Left Sidebar Operational Data Preparation ──────────────────────────────
    // Only operational activities actually scheduled for Today, This Week, and Upcoming
    $today_str      = date('Y-m-d');
    $tomorrow_str   = date('Y-m-d', strtotime('+1 day'));
    $week_start_str = date('Y-m-d', strtotime('monday this week'));
    $week_end_str   = date('Y-m-d', strtotime('sunday this week'));
    $upcoming_limit = date('Y-m-d', strtotime('+30 days'));

    $sidebar_range_start = min($today_str, $week_start_str);
    $sidebar_range_end   = max($week_end_str, $upcoming_limit);

    // Fetch scheduled operational events for staff
    $sidebar_ops = calendar_fetch_all_station_events($pdo, (int)$station_id, $sidebar_range_start, $sidebar_range_end, (int)$user_id, 'staff');

    // 1. TODAY'S EVENTS
    $today_events_list = $sidebar_ops[$today_str] ?? [];
    usort($today_events_list, function($a, $b) {
        return strcmp($a['start_time'] ?? '00:00:00', $b['start_time'] ?? '00:00:00');
    });

    // 2. THIS WEEK STATUS SUMMARY (Pending, Approved, Completed)
    $week_status_summary = ['Pending' => 0, 'Approved' => 0, 'Completed' => 0];
    foreach ($sidebar_ops as $d => $evts) {
        if ($d >= $week_start_str && $d <= $week_end_str) {
            foreach ($evts as $e) {
                $st = strtolower($e['status'] ?? 'pending');
                if (in_array($st, ['completed', 'verified', 'official', 'locked', 'stock-in complete', 'closing_completed', 'done'])) {
                    $week_status_summary['Completed']++;
                } elseif (in_array($st, ['approved', 'in progress', 'in_progress', 'readings_submitted', 'submitted', 'active'])) {
                    $week_status_summary['Approved']++;
                } else {
                    $week_status_summary['Pending']++;
                }
            }
        }
    }

    // 3. UPCOMING (Next scheduled operational activities after today)
    $upcoming_events_list = [];
    foreach ($sidebar_ops as $d => $evts) {
        if ($d > $today_str) {
            foreach ($evts as $e) {
                $e['scheduled_day'] = $d;
                $upcoming_events_list[] = $e;
            }
        }
    }
    usort($upcoming_events_list, function($a, $b) {
        $da = ($a['scheduled_day'] ?? '') . ' ' . ($a['start_time'] ?? '00:00:00');
        $db = ($b['scheduled_day'] ?? '') . ' ' . ($b['start_time'] ?? '00:00:00');
        return strcmp($da, $db);
    });
    $summary_stats = [
        'conflicts' => []
    ];
    $upcoming_display_list = array_slice($upcoming_events_list, 0, 8);

    // Merge sidebar events into allCalendarEvents lookup so clicking an upcoming event always finds it
    foreach ($sidebar_ops as $d => $evts) {
        foreach ($evts as $evt) {
            $add_unique_event($d, $evt);
        }
    }

include __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Global Font Awesome Icon Fix ── */
i.fas, i.far, i.fab, i.fa, i[class*="fa-"] {
    font-style: normal !important;
    font-family: "Font Awesome 6 Free", "Font Awesome 5 Free", "FontAwesome" !important;
    font-weight: 900 !important;
    display: inline-block !important;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Google Calendar Style */
.cal-layout { font-family: 'Google Sans', 'Roboto', Arial, sans-serif; background: #fff; display: flex; width: 100%; max-width: 100%; height: auto; min-height: calc(100vh - 150px); overflow: hidden; margin-bottom: 60px !important; box-sizing: border-box; }
.cal-layout * { font-family: 'Google Sans', 'Roboto', Arial, sans-serif; box-sizing: border-box; }

/* Sidebar */
.cal-sidebar { width: 256px; min-width: 256px; max-width: 256px; border-right: 1px solid #dadce0; padding: 8px 0; overflow-y: visible; flex-shrink: 0; height: auto; box-sizing: border-box; }
.cal-create-btn { margin: 20px 12px 32px; background: #fff; border: none; box-shadow: 0 1px 2px 0 rgba(60,64,67,.3), 0 1px 3px 1px rgba(60,64,67,.15); border-radius: 24px; padding: 0 24px 0 12px; height: 56px; display: flex; align-items: center; gap: 16px; cursor: pointer; font-size: 14px; color: #3c4043; font-weight: 500; transition: box-shadow .2s; }
.cal-create-btn:hover { box-shadow: 0 1px 3px 0 rgba(60,64,67,.3), 0 4px 8px 3px rgba(60,64,67,.15); }
.cal-create-btn i { width: 36px; height: 36px; background: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #1a73e8; }

.cal-mini-month { padding: 0 12px 20px; }
.cal-mini-header { display: flex; align-items: center; justify-content: space-between; padding: 8px 4px; }
.cal-mini-title { font-size: 14px; font-weight: 500; color: #3c4043; }
.cal-mini-nav { background: none; border: none; padding: 8px; border-radius: 50%; cursor: pointer; color: #5f6368; }
.cal-mini-nav:hover { background: #f1f3f4; }
.cal-mini-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; }
.cal-mini-day { height: 32px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #3c4043; border-radius: 50%; cursor: pointer; }
.cal-mini-day.today { background: #1a73e8; color: #fff; font-weight: 600; }
.cal-mini-day:hover:not(.today) { background: #f1f3f4; }
.cal-mini-weekday { height: 24px; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #70757a; font-weight: 500; }

.cal-calendars { padding: 0 12px; }
.cal-calendars-title { font-size: 14px; font-weight: 500; color: #3c4043; padding: 12px 8px 8px; }
.cal-calendar-item { display: flex; align-items: center; gap: 12px; padding: 8px; border-radius: 8px; cursor: pointer; font-size: 14px; color: #3c4043; }
.cal-calendar-item:hover { background: #f1f3f4; }
.cal-calendar-checkbox { width: 20px; height: 20px; border-radius: 3px; border: 2px solid; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; }
.cal-calendar-checkbox.checked::before { content: '<i class="fas fa-check"></i>'; }

/* Main content */
.cal-main { flex: 1 1 auto; display: flex; flex-direction: column; overflow: hidden; width: calc(100% - 256px); min-width: 0; max-width: calc(100% - 256px); box-sizing: border-box; }
.cal-header { padding: 8px 16px; border-bottom: 1px solid #dadce0; display: flex; align-items: center; justify-content: space-between; width: 100%; box-sizing: border-box; }
.cal-header-left { display: flex; align-items: center; gap: 16px; min-width: 0; }
.cal-menu-btn { background: none; border: none; padding: 12px; border-radius: 50%; cursor: pointer; color: #5f6368; font-size: 20px; }
.cal-menu-btn:hover { background: #f1f3f4; }
.cal-month-title { font-size: 22px; font-weight: 400; color: #3c4043; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cal-header-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.cal-view-btn { 
    background: white !important; 
    border: 1px solid #dadce0 !important; 
    padding: 8px 16px !important; 
    border-radius: 4px !important; 
    cursor: pointer !important; 
    font-size: 14px !important; 
    color: #3c4043 !important; 
    display: flex !important; 
    align-items: center !important; 
    gap: 6px !important; 
    position: relative !important;
    text-decoration: none !important;
    box-shadow: none !important;
}
.cal-view-btn:hover { 
    background: #f1f3f4 !important; 
    color: #3c4043 !important;
}
.cal-view-btn:focus { 
    outline: none !important; 
    background: #f1f3f4 !important; 
    color: #3c4043 !important;
    box-shadow: none !important;
}
.cal-view-btn:active { 
    background: #f1f3f4 !important;
    color: #3c4043 !important;
    box-shadow: none !important;
}
.cal-view-btn i {
    font-style: normal;
    font-family: "Font Awesome 6 Free", "Font Awesome 5 Free";
    font-weight: 900;
    display: inline-block;
}

/* View dropdown */
.cal-view-dropdown { position: absolute; top: 100%; right: 0; margin-top: 4px; background: #fff; border: 1px solid #dadce0; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,.15); z-index: 100; display: none; min-width: 140px; overflow: hidden; }
.cal-view-dropdown.show { display: block; }
.cal-view-option { padding: 10px 16px; cursor: pointer; font-size: 14px; color: #3c4043; display: flex; align-items: center; justify-content: space-between; transition: all 0.15s ease; }
.cal-view-option:hover { background: #1967d2 !important; color: #fff !important; }
.cal-view-option:hover .shortcut { color: rgba(255, 255, 255, 0.85) !important; }
.cal-view-option.active { background: #1967d2 !important; color: #fff !important; font-weight: 600; }
.cal-view-option.active .shortcut { color: rgba(255, 255, 255, 0.85) !important; }
.cal-view-option .shortcut { font-size: 12px; color: #5f6368; }
.cal-icon-btn { 
    background: none; 
    border: none; 
    padding: 12px; 
    border-radius: 50%; 
    cursor: pointer; 
    color: #5f6368; 
    font-size: 18px; 
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: auto;
    height: auto;
}
.cal-icon-btn:hover { 
    background: #f1f3f4; 
}
.cal-icon-btn i {
    font-style: normal;
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
}

/* Calendar grid */
.cal-content { flex: 1 1 auto; height: auto; overflow: visible; width: 100%; max-width: 100%; box-sizing: border-box; }
.cal-grid-container { width: 100%; max-width: 100%; height: auto; box-sizing: border-box; }
.cal-weekdays { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border-bottom: 1px solid #dadce0; position: sticky; top: 0; background: #fff; z-index: 2; width: 100%; box-sizing: border-box; }
.cal-weekday { padding: 8px 4px; text-align: center; font-size: 11px; font-weight: 500; color: #70757a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); grid-auto-rows: minmax(130px, auto); width: 100%; box-sizing: border-box; }
.cal-day { border: 1px solid #dadce0; border-top: none; border-left: none; padding: 4px; position: relative; background: #fff; min-width: 0; min-height: 130px; box-sizing: border-box; overflow: hidden; }
.cal-day:nth-child(7n) { border-right: none; }
.cal-day:hover { background: #f8f9fa; }
.cal-day.other-month { background: #fafafa; }
.cal-day.today { background: #e8f0fe; }

.cal-day-num { height: 26px; width: 26px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #3c4043; margin-bottom: 4px; font-weight: 600; }
.cal-day.today .cal-day-num { background: #1a73e8; color: #fff; border-radius: 50%; }
.cal-events { display: flex; flex-direction: column; gap: 4px; width: 100%; max-width: 100%; box-sizing: border-box; overflow: hidden; }
.cal-event { 
    width: 100%; 
    max-width: 100%; 
    box-sizing: border-box; 
    padding: 4px 6px; 
    border-radius: 6px; 
    font-size: 11px; 
    cursor: pointer; 
    display: block; 
    border: 1px solid; 
    white-space: normal !important; 
    word-break: break-word !important; 
    overflow: hidden !important; 
    text-overflow: clip !important; 
    box-shadow: 0 1px 2px rgba(0,0,0,0.03); 
    transition: transform 0.12s ease, box-shadow 0.12s ease; 
}
.cal-event:hover { 
    filter: brightness(.97); 
    box-shadow: 0 2px 6px rgba(0,0,0,0.08); 
    transform: translateY(-1px); 
}
.cal-event-header { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    flex-wrap: wrap; 
    gap: 2px 4px; 
    margin-bottom: 2px; 
    width: 100%; 
    box-sizing: border-box; 
}
.cal-event-time { 
    font-weight: 700; 
    font-size: 9.5px; 
    display: inline-flex; 
    align-items: center; 
    gap: 3px; 
    white-space: nowrap; 
}
.cal-event-badge { 
    font-size: 8px; 
    font-weight: 700; 
    padding: 1px 4px; 
    border-radius: 3px; 
    background: rgba(0,0,0,0.06); 
    letter-spacing: 0.2px; 
    white-space: nowrap; 
    text-transform: uppercase; 
    max-width: 100%; 
    box-sizing: border-box; 
}
.cal-event-text { 
    font-size: 11px; 
    font-weight: 600; 
    line-height: 1.32; 
    white-space: normal !important; 
    word-break: break-word !important; 
    overflow: hidden !important; 
    text-overflow: clip !important; 
    width: 100%; 
    box-sizing: border-box; 
}
.cal-more { font-size: 11px; color: #1a73e8; padding: 2px 6px; cursor: pointer; font-weight: 600; border-radius: 4px; }
.cal-more:hover { background: #e8f0fe; }

@media(max-width: 900px) {
    .cal-sidebar { display: none; }
    .cal-grid { grid-auto-rows: 90px; }
}
</style>

<div class="cal-layout">
    <!-- Sidebar -->
    <div class="cal-sidebar">
        <!-- Summary Panels: Today's Events, This Week, Upcoming -->
        <div style="padding: 12px 12px 20px;">
            <!-- 1. TODAY'S EVENTS -->
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <div style="font-size: 11px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px;">
                        <i class="fas fa-calendar-day" style="color: #002F70; margin-right: 4px;"></i> TODAY'S EVENTS
                    </div>
                    <span style="font-size: 10px; font-weight: 700; background: #f1f5f9; color: #1e293b; padding: 2px 7px; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <?= count($today_events_list) ?> <?= count($today_events_list) === 1 ? 'Event' : 'Events' ?>
                    </span>
                </div>

                <?php if (empty($today_events_list)): ?>
                    <div style="font-size: 11px; color: #64748b; font-style: italic; padding: 6px 2px;">
                        • No scheduled events today
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 6px; max-height: 220px; overflow-y: auto;">
                        <?php foreach ($today_events_list as $te):
                            $t_time = (!empty($te['start_time']) && $te['start_time'] !== '00:00:00')
                                ? date('g:i A', strtotime($te['start_time']))
                                : '';
                            $t_title = $te['calendar_title'] ?? ($te['work_description'] ?? $te['type_name']);
                            $te_id = $te['id'] ?? '';
                            $te_type = $te['type_key'] ?? '';
                        ?>
                        <div onclick="clickEvent('<?= htmlspecialchars($te_id) ?>', '<?= htmlspecialchars($te_type) ?>', '<?= htmlspecialchars($te['target_url'] ?? '#') ?>')"
                             style="font-size: 11px; line-height: 1.4; color: #1e293b; cursor: pointer; padding: 4px 6px; border-radius: 4px; border: 1px solid #f1f5f9; transition: all 0.15s ease;"
                             onmouseover="this.style.background='#f8fafc'; this.style.borderColor='#cbd5e1';"
                             onmouseout="this.style.background='transparent'; this.style.borderColor='#f1f5f9';">
                            <span style="color: #002F70; font-weight: 700;">•</span>
                            <?php if ($t_time): ?>
                                <span style="font-weight: 600; color: #475569;"><?= $t_time ?></span> &nbsp;
                            <?php endif; ?>
                            <span><?= htmlspecialchars($t_title) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 2. THIS WEEK -->
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="font-size: 11px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    <i class="fas fa-calendar-week" style="color: #002F70; margin-right: 4px;"></i> THIS WEEK
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; display: flex; flex-direction: column; gap: 6px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px;">
                        <span style="color: #475569; font-weight: 500;">Pending</span>
                        <span style="font-weight: 700; color: #1e293b;"><?= $week_status_summary['Pending'] ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px;">
                        <span style="color: #475569; font-weight: 500;">Approved</span>
                        <span style="font-weight: 700; color: #1e293b;"><?= $week_status_summary['Approved'] ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px;">
                        <span style="color: #475569; font-weight: 500;">Completed</span>
                        <span style="font-weight: 700; color: #1e293b;"><?= $week_status_summary['Completed'] ?></span>
                    </div>
                </div>
            </div>

            <!-- 3. UPCOMING -->
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="font-size: 11px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    <i class="fas fa-forward" style="color: #002F70; margin-right: 4px;"></i> UPCOMING
                </div>

                <?php if (empty($upcoming_display_list)): ?>
                    <div style="font-size: 11px; color: #64748b; font-style: italic; padding: 6px 2px;">
                        • No upcoming events scheduled
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 6px; max-height: 260px; overflow-y: auto;">
                        <?php foreach ($upcoming_display_list as $ue):
                            $u_day = $ue['scheduled_day'];
                            $date_label = ($u_day === $tomorrow_str) ? 'Tomorrow' : date('M j', strtotime($u_day));
                            $u_title = $ue['calendar_title'] ?? ($ue['work_description'] ?? $ue['type_name']);
                            $ue_id = $ue['id'] ?? '';
                            $ue_type = $ue['type_key'] ?? '';
                        ?>
                        <div onclick="clickEvent('<?= htmlspecialchars($ue_id) ?>', '<?= htmlspecialchars($ue_type) ?>', '<?= htmlspecialchars($ue['target_url'] ?? '#') ?>')"
                             style="font-size: 11px; line-height: 1.4; color: #1e293b; cursor: pointer; padding: 4px 6px; border-radius: 4px; border: 1px solid #f1f5f9; transition: all 0.15s ease;"
                             onmouseover="this.style.background='#f8fafc'; this.style.borderColor='#cbd5e1';"
                             onmouseout="this.style.background='transparent'; this.style.borderColor='#f1f5f9';">
                            <span style="color: #002F70; font-weight: 700;">•</span>
                            <span style="font-weight: 600; color: #475569;"><?= $date_label ?></span> –
                            <span><?= htmlspecialchars($u_title) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- Sidebar end -->
    </div>

    <!-- Main calendar -->
    <div class="cal-main">
        <!-- Header -->
        <div class="cal-header">
            <div class="cal-header-left">
                <h1 class="cal-month-title"><?= htmlspecialchars($view_title) ?></h1>
            </div>
            <div class="cal-header-right">
                <a href="staff_calendar.php?view=<?= $current_view ?>&month_offset=<?= $prev_offset ?>" class="cal-icon-btn" title="Previous">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <a href="staff_calendar.php?view=<?= $current_view ?>&month_offset=0" class="cal-view-btn">Today</a>
                <a href="staff_calendar.php?view=<?= $current_view ?>&month_offset=<?= $next_offset ?>" class="cal-icon-btn" title="Next">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <div style="position: relative;">
                    <button class="cal-view-btn" onclick="toggleViewDropdown(event)">
                        <?= ucfirst($current_view) ?> <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="cal-view-dropdown" id="viewDropdown">
                        <div class="cal-view-option <?= $current_view === 'day' ? 'active' : '' ?>" onclick="selectView('day')">
                            <span>Day</span>
                            <span class="shortcut">D</span>
                        </div>
                        <div class="cal-view-option <?= $current_view === 'week' ? 'active' : '' ?>" onclick="selectView('week')">
                            <span>Week</span>
                            <span class="shortcut">W</span>
                        </div>
                        <div class="cal-view-option <?= $current_view === 'month' ? 'active' : '' ?>" onclick="selectView('month')">
                            <span>Month</span>
                            <span class="shortcut">M</span>
                        </div>
                        <div class="cal-view-option <?= $current_view === 'year' ? 'active' : '' ?>" onclick="selectView('year')">
                            <span>Year</span>
                            <span class="shortcut">Y</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Calendar content -->
        <div class="cal-content">
            <div class="cal-grid-container">
                <?php if ($current_view === 'month'): ?>
                <!-- Month View -->
                <!-- Weekdays -->
                <div class="cal-weekdays">
                    <div class="cal-weekday">SUN</div>
                    <div class="cal-weekday">MON</div>
                    <div class="cal-weekday">TUE</div>
                    <div class="cal-weekday">WED</div>
                    <div class="cal-weekday">THU</div>
                    <div class="cal-weekday">FRI</div>
                    <div class="cal-weekday">SAT</div>
                </div>

                <!-- Calendar Grid -->
                <div class="cal-grid">
                    <?php 
                    // Adjust calendar_dates to start with Sunday
                    $calendar_start_adjusted = clone $first_day;
                    $start_weekday = (int)$calendar_start_adjusted->format('w'); // 0=Sunday
                    if ($start_weekday > 0) {
                        $calendar_start_adjusted->modify('-' . $start_weekday . ' days');
                    }
                    
                    $calendar_end_adjusted = clone $last_day;
                    $end_weekday = (int)$calendar_end_adjusted->format('w');
                    if ($end_weekday < 6) {
                        $calendar_end_adjusted->modify('+' . (6 - $end_weekday) . ' days');
                    }
                    
                    $calendar_dates_adjusted = [];
                    $current_date = clone $calendar_start_adjusted;
                    while ($current_date <= $calendar_end_adjusted) {
                        $calendar_dates_adjusted[] = $current_date->format('Y-m-d');
                        $current_date->modify('+1 day');
                    }
                    
                    foreach($calendar_dates_adjusted as $date):
                        $day_num = date('j', strtotime($date));
                        $is_today = ($date === $today_str);
                        $is_other_month = (substr($date, 0, 7) !== $current_month_str);
                        $day_events = $month_events[$date] ?? [];
                        
                        $day_class = 'cal-day';
                        if ($is_today) $day_class .= ' today';
                        if ($is_other_month) $day_class .= ' other-month';
                    ?>
                    <div class="<?= $day_class ?>">
                        <div class="cal-day-num" onclick="clickDay('<?= $date ?>')"><?= $day_num ?></div>
                        <div class="cal-events">
                            <?php 
                            $display_limit = 4;
                            $displayed = 0;
                            foreach($day_events as $event):
                                if ($displayed >= $display_limit) break;
                                $event_color = $event['color'] ?? '#757575';
                                $bg = $event['bg_color'] ?? ($event_color . '18');
                                $tx = $event['text_color'] ?? '#1e293b';
                                $bc = $event['border_color'] ?? ($event_color . '44');
                                $title_display = $event['calendar_title'] ?? $event['work_description'] ?? $event['type_name'];
                                $badge_text = $calendar_short_status($event['status_clean'] ?? $event['status'] ?? '');
                                
                                $time_str = '';
                                if (!empty($event['start_time']) && $event['start_time'] != '00:00:00') {
                                    $time_str = date('g:ia', strtotime($event['start_time']));
                                }
                                $event_id = $event['id'] ?? '';
                                $event_type = $event['type_key'] ?? '';
                                $staff_id = $event['staff_encoder_id'] ?? '';
                                $displayed++;
                            ?>
                            <div class="cal-event" 
                                 data-staff="<?= $staff_id ?>"
                                 style="background: <?= $bg ?>; color: <?= $tx ?>; border-color: <?= $bc ?>;" 
                                 title="<?= htmlspecialchars(($event['staff_name'] ?? '') . ' - ' . ($event['work_description'] ?? $event['type_name'])) ?>"
                                 onclick="clickEvent('<?= htmlspecialchars($event_id) ?>', '<?= htmlspecialchars($event_type) ?>', '<?= htmlspecialchars($event['target_url'] ?? '#') ?>')">
                                <div class="cal-event-header">
                                    <span class="cal-event-time">
                                        <i class="<?= htmlspecialchars($event['icon_class'] ?? 'fas fa-calendar-alt') ?>" style="font-size:9px; opacity:0.85;"></i> 
                                        <?= $time_str ? htmlspecialchars($time_str) : htmlspecialchars($event['type_name']) ?>
                                    </span>
                                    <?php if ($badge_text): ?>
                                    <span class="cal-event-badge"><?= htmlspecialchars($badge_text) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="cal-event-text">
                                    <?= htmlspecialchars($title_display) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (count($day_events) > $display_limit): ?>
                            <div class="cal-more" onclick="clickDay('<?= $date ?>')">+<?= count($day_events) - $display_limit ?> more</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php elseif ($current_view === 'week'): ?>
                <!-- Week View -->
                <div class="cal-weekdays">
                    <?php
                    $week_start_date = new DateTime($view_start);
                    for ($i = 0; $i < 7; $i++):
                        $day_label = $week_start_date->format('D j');
                    ?>
                    <div class="cal-weekday"><?= $day_label ?></div>
                    <?php
                        $week_start_date->modify('+1 day');
                    endfor;
                    ?>
                </div>
                <div class="cal-grid" style="grid-template-columns: repeat(7, minmax(0, 1fr)); grid-auto-rows: minmax(140px, auto);">
                    <?php
                    $week_date = new DateTime($view_start);
                    for ($i = 0; $i < 7; $i++):
                        $date = $week_date->format('Y-m-d');
                        $is_today = ($date === $today_str);
                        $day_events = $month_events[$date] ?? [];
                    ?>
                    <div class="cal-day <?= $is_today ? 'today' : '' ?>">
                        <div class="cal-day-num"><?= $week_date->format('j') ?></div>
                        <div class="cal-events">
                            <?php foreach($day_events as $event):
                                $event_color = $event['color'] ?? '#757575';
                                $bg = $event['bg_color'] ?? ($event_color . '18');
                                $tx = $event['text_color'] ?? '#1e293b';
                                $bc = $event['border_color'] ?? ($event_color . '44');
                                $title_display = $event['calendar_title'] ?? $event['work_description'] ?? $event['type_name'];
                                $badge_text = $calendar_short_status($event['status_clean'] ?? $event['status'] ?? '');
                                $time_str = !empty($event['start_time']) && $event['start_time'] != '00:00:00' ? date('g:ia', strtotime($event['start_time'])) : '';
                            ?>
                            <div class="cal-event" style="background: <?= $bg ?>; color: <?= $tx ?>; border-color: <?= $bc ?>;" 
                                 onclick="clickEvent('<?= htmlspecialchars($event['id'] ?? '') ?>', '<?= htmlspecialchars($event['type_key'] ?? '') ?>', '<?= htmlspecialchars($event['target_url'] ?? '#') ?>')">
                                <div class="cal-event-header">
                                    <?php if ($time_str): ?>
                                    <span class="cal-event-time"><i class="<?= htmlspecialchars($event['icon_class'] ?? 'fas fa-calendar-alt') ?>" style="font-size:9px; opacity:0.85;"></i> <?= htmlspecialchars($time_str) ?></span>
                                    <?php endif; ?>
                                    <?php if ($badge_text): ?>
                                    <span class="cal-event-badge"><?= htmlspecialchars($badge_text) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="cal-event-text"><?= htmlspecialchars($title_display) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php
                        $week_date->modify('+1 day');
                    endfor;
                    ?>
                </div>
                
                <?php elseif ($current_view === 'day'): ?>
                <!-- Day View -->
                <div style="padding: 20px;">
                    <?php 
                    $day_events = $month_events[$today_str] ?? [];
                    if (empty($day_events)):
                    ?>
                    <div style="text-align: center; padding: 40px; color: #5f6368;">
                        <i class="fas fa-calendar-day" style="font-size: 48px; margin-bottom: 16px;"></i>
                        <div>No events scheduled for today</div>
                    </div>
                    <?php else: ?>
                    <div style="max-width: 800px;">
                        <?php foreach($day_events as $event):
                            $event_color = $event['color'] ?? '#757575';
                            $time_range = '';
                            if (!empty($event['start_time']) && $event['start_time'] != '00:00:00') {
                                $time_range = date('g:i A', strtotime($event['start_time']));
                                if (!empty($event['end_time']) && $event['end_time'] != '00:00:00') {
                                    $time_range .= ' - ' . date('g:i A', strtotime($event['end_time']));
                                }
                            }
                            $bg = $event['bg_color'] ?? ($event_color . '14');
                            $tx = $event['text_color'] ?? '#1e293b';
                            $bc = $event['border_color'] ?? ($event_color . '44');
                            $title_display = $event['calendar_title'] ?? $event['work_description'] ?? $event['type_name'];
                        ?>
                        <div style="border: 1px solid <?= $bc ?>; background: <?= $bg ?>; padding: 16px; margin-bottom: 12px; border-radius: 8px; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.04);"
                             onclick="clickEvent('<?= htmlspecialchars($event['id'] ?? '') ?>', '<?= htmlspecialchars($event['type_key'] ?? '') ?>', '<?= htmlspecialchars($event['target_url'] ?? '#') ?>')">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                <div style="font-weight: 700; color: <?= $tx ?>; font-size: 15px;"><?= htmlspecialchars($title_display) ?></div>
                                <div style="color: #64748b; font-size: 12px; font-weight: 600;"><?= $time_range ?></div>
                            </div>
                            <div style="color: #5f6368; font-size: 14px;">
                                <i class="fas fa-user"></i> <?= htmlspecialchars($event['staff_name'] ?? 'Unknown') ?> 
                                <span style="margin-left: 16px;"><i class="fas fa-tag"></i> <?= htmlspecialchars($event['type_name']) ?></span>
                                <span style="margin-left: 16px;"><i class="fas fa-circle" style="font-size: 8px;"></i> <?= ucfirst($event['status'] ?? 'pending') ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php elseif ($current_view === 'year'): ?>
                <!-- Year View -->
                <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                    <?php
                    for ($m = 1; $m <= 12; $m++):
                        $month_date = new DateTime($today->format('Y') . '-' . str_pad($m, 2, '0', STR_PAD_LEFT) . '-01');
                        $month_name_short = $month_date->format('F');
                        $days_in_month = $month_date->format('t');
                        $first_day_of_week = (int)$month_date->format('w');
                    ?>
                    <div style="border: 1px solid #dadce0; border-radius: 8px; padding: 12px; background: #fff;">
                        <div style="font-weight: 600; margin-bottom: 8px; color: #3c4043; text-align: center;"><?= $month_name_short ?></div>
                        <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; font-size: 10px;">
                            <?php foreach(['S','M','T','W','T','F','S'] as $d): ?>
                            <div style="text-align: center; color: #5f6368; font-weight: 500;"><?= $d ?></div>
                            <?php endforeach; ?>
                            <?php for ($i = 0; $i < $first_day_of_week; $i++): ?>
                            <div></div>
                            <?php endfor; ?>
                            <?php for ($d = 1; $d <= $days_in_month; $d++):
                                $date_str = $month_date->format('Y-m') . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                                $has_events = isset($month_events[$date_str]) && count($month_events[$date_str]) > 0;
                                $is_today_date = ($date_str === $today_str);
                            ?>
                            <div style="text-align: center; padding: 4px; <?= $is_today_date ? 'background: #1a73e8; color: #fff; border-radius: 50%;' : ($has_events ? 'font-weight: 600; color: #1a73e8;' : 'color: #3c4043;') ?>">
                                <?= $d ?>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle view dropdown
function toggleViewDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('viewDropdown');
    dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('viewDropdown');
    if (dropdown && !event.target.closest('.cal-view-btn')) {
        dropdown.classList.remove('show');
    }
});

// View selection
function selectView(view) {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('view', view);
    currentUrl.searchParams.set('month_offset', '<?= $month_offset ?>');
    window.location.href = currentUrl.toString();
}

// Keyboard shortcuts
document.addEventListener('keydown', function(event) {
    if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') return;
    
    switch(event.key.toLowerCase()) {
        case 'd':
            selectView('day');
            break;
        case 'w':
            selectView('week');
            break;
        case 'm':
            selectView('month');
            break;
        case 'y':
            selectView('year');
            break;
    }
});

// Create event button
function createEvent() {
    const today = new Date().toISOString().split('T')[0];
    showEventModal(today);
}

// Show event modal
function showEventModal(date = null, eventData = null) {
    const modal = document.getElementById('eventModal');
    const form = document.getElementById('eventForm');
    const title = document.getElementById('modalTitle');
    
    if (eventData) {
        // Edit existing event
        title.textContent = 'Edit Event';
        document.getElementById('eventId').value = eventData.id || '';
        document.getElementById('eventDate').value = eventData.event_date || date;
        document.getElementById('eventType').value = eventData.type_key || '';
        document.getElementById('eventDescription').value = eventData.work_description || '';
        document.getElementById('eventStartTime').value = eventData.start_time || '';
        document.getElementById('eventEndTime').value = eventData.end_time || '';
        document.getElementById('eventStatus').value = eventData.status || 'pending';
        
        // Trigger dynamic fields population
        handleEventTypeChange();
        
        // Populate dynamic fields based on event type
        setTimeout(() => {
            const dynamicFields = document.getElementById('dynamicFields');
            
            // Populate all dynamic field values
            if (eventData.shift_type) {
                const shiftType = dynamicFields.querySelector('[name="shift_type"]');
                if (shiftType) shiftType.value = eventData.shift_type;
            }
            if (eventData.shift_status) {
                const shiftStatus = dynamicFields.querySelector('[name="shift_status"]');
                if (shiftStatus) shiftStatus.value = eventData.shift_status;
            }
            if (eventData.service_type) {
                const serviceType = dynamicFields.querySelector('[name="service_type"]');
                if (serviceType) serviceType.value = eventData.service_type;
            }
            if (eventData.customer_name) {
                const customerName = dynamicFields.querySelector('[name="customer_name"]');
                if (customerName) customerName.value = eventData.customer_name;
            }
            if (eventData.job_status) {
                const jobStatus = dynamicFields.querySelector('[name="job_status"]');
                if (jobStatus) jobStatus.value = eventData.job_status;
            }
            if (eventData.supplier) {
                const supplier = dynamicFields.querySelector('[name="supplier"]');
                if (supplier) supplier.value = eventData.supplier;
            }
            if (eventData.product) {
                const product = dynamicFields.querySelector('[name="product"]');
                if (product) product.value = eventData.product;
            }
            if (eventData.expected_qty) {
                const expectedQty = dynamicFields.querySelector('[name="expected_qty"]');
                if (expectedQty) expectedQty.value = eventData.expected_qty;
            }
            if (eventData.actual_qty) {
                const actualQty = dynamicFields.querySelector('[name="actual_qty"]');
                if (actualQty) actualQty.value = eventData.actual_qty;
            }
            if (eventData.pump_number) {
                const pumpNumber = dynamicFields.querySelector('[name="pump_number"]');
                if (pumpNumber) pumpNumber.value = eventData.pump_number;
            }
            if (eventData.expected_reading) {
                const expectedReading = dynamicFields.querySelector('[name="expected_reading"]');
                if (expectedReading) expectedReading.value = eventData.expected_reading;
            }
            if (eventData.actual_reading) {
                const actualReading = dynamicFields.querySelector('[name="actual_reading"]');
                if (actualReading) actualReading.value = eventData.actual_reading;
            }
            if (eventData.variance !== undefined) {
                const variance = dynamicFields.querySelector('[name="variance"]');
                if (variance) {
                    const pct = eventData.variance_percent || 0;
                    variance.value = `${eventData.variance.toFixed(2)} L (${pct.toFixed(2)}%)`;
                }
            }
            if (eventData.customer_id) {
                const customerId = dynamicFields.querySelector('[name="customer_id"]');
                if (customerId) customerId.value = eventData.customer_id;
            }
            if (eventData.amount) {
                const amount = dynamicFields.querySelector('[name="amount"]');
                if (amount) amount.value = eventData.amount;
            }
            if (eventData.payment_status) {
                const paymentStatus = dynamicFields.querySelector('[name="payment_status"]');
                if (paymentStatus) paymentStatus.value = eventData.payment_status;
            }
        }, 100);
    } else {
        // Create new event
        title.textContent = 'Create Event';
        form.reset();
        document.getElementById('eventDate').value = date || new Date().toISOString().split('T')[0];
        document.getElementById('dynamicFields').innerHTML = '';
    }
    
    modal.style.display = 'flex';
}

// Close modal
function closeModal() {
    document.getElementById('eventModal').style.display = 'none';
}

// Global events dictionary for lookup
const allCalendarEvents = <?= json_encode($month_events) ?>;

// Click on event — always opens the modal form
function clickEvent(eventId, eventType, targetUrl) {
    const match = eventId.match(/\d+$/);
    const numericId = match ? match[0] : eventId;
    
    // Find the event in allCalendarEvents
    let foundEvent = null;
    for (const date in allCalendarEvents) {
        const evts = allCalendarEvents[date];
        const matchEvt = evts.find(e => e.id.toString() === eventId.toString());
        if (matchEvt) {
            foundEvent = matchEvt;
            break;
        }
    }

    if (!foundEvent) {
        // Fallback for manual events
        fetch('staff_calendar.php?action=get_event&event_id=' + eventId)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showEventModal(null, data.event);
                } else {
                    alert('Event not found');
                }
            })
            .catch(e => alert('Error loading event'));
        return;
    }

    // Show details modal
    showDetailsModal(foundEvent);
}

function showDetailsModal(evt) {
    const modal = document.getElementById('detailsModal');
    const title = document.getElementById('detailsTitle');
    const body = document.getElementById('detailsBody');
    const actions = document.getElementById('detailsActions');
    
    // Determine title
    let typeName = evt.type_name || 'Event Details';
    title.textContent = typeName + ' Details';
    
    // Determine status text
    let status = (evt.status || 'pending').toUpperCase();
    
    let html = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <span style="font-weight:600; font-size:12px; text-transform:uppercase; color:#64748b;">Event Date:</span>
            <span style="font-weight:600; color:#1e293b;">${evt.event_date || evt.scheduled_date}</span>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <span style="font-weight:600; font-size:12px; text-transform:uppercase; color:#64748b;">Status:</span>
            <span style="background:#f1f5f9; color:#1e293b; border:1px solid #e2e8f0; padding:3px 9px; border-radius:4px; font-weight:700; font-size:11px; text-transform:uppercase;">${status}</span>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <span style="font-weight:600; font-size:12px; text-transform:uppercase; color:#64748b;">Assigned Manager:</span>
            <span style="font-weight:600; color:#1e293b;">${evt.manager_name || 'System Auto-schedule'}</span>
        </div>
        <div style="margin-bottom:14px; border-top:1px solid #dadce0; padding-top:14px;">
            <span style="font-weight:600; font-size:12px; text-transform:uppercase; color:#64748b; display:block; margin-bottom:6px;">Description:</span>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:10px 12px; border-radius:6px; font-size:13px; color:#1e293b;">${evt.work_description || 'No description provided.'}</div>
        </div>
    `;

    // Dynamic type specific details for Staff Calendar
    if (evt.type_key === 'staff_shift') {
        html += `
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid #dadce0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Duty Shift:</span>
                    <span style="font-weight:700; color:#1e293b;">${evt.shift_name || evt.work_description}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Duty Schedule:</span>
                    <span style="font-weight:600; color:#1e293b;">${evt.shift_hours || (evt.start_time ? evt.start_time.substring(0,5) + ' - ' + (evt.end_time ? evt.end_time.substring(0,5) : '') : 'Scheduled')}</span>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Category:</span>
                    <span style="font-weight:500; color:#1e293b;">Station Shift Schedule</span>
                </div>
            </div>
        `;
    } else if (evt.type_key === 'job_order') {
        html += `
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid #dadce0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">JO Number:</span>
                    <span style="font-weight:700; font-family:monospace; color:#1e293b;">${evt.jo_number || ('JO #' + (evt.numeric_id || evt.id))}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Customer:</span>
                    <span style="font-weight:600; color:#1e293b;">${evt.customer_name || 'Walk-in Customer'}</span>
                </div>
                ${evt.vehicle_plate ? `
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Vehicle Plate:</span>
                    <span style="font-family:monospace; font-weight:700; background:#f1f5f9; padding:2px 8px; border-radius:4px; color:#1e293b;">${evt.vehicle_plate}</span>
                </div>` : ''}
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Service:</span>
                    <span style="font-weight:600; color:#1e293b;">${evt.service_type || 'General Service'}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Assigned Mechanic:</span>
                    <span style="color:#1e293b;">${evt.mechanic_name || evt.staff_name || 'Unassigned'}</span>
                </div>
                ${evt.total_amount ? `
                <div style="display:flex; justify-content:space-between;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Total Amount:</span>
                    <span style="font-weight:700; color:#1e293b;">₱${parseFloat(evt.total_amount).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</span>
                </div>` : ''}
            </div>
        `;
    } else if (evt.type_key === 'merchandise_delivery' || evt.type_key === 'fuel_delivery') {
        const isFuel = (evt.type_key === 'fuel_delivery' || (evt.category_label && evt.category_label.toLowerCase() === 'fuel'));
        html += `
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid #dadce0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">PO / Delivery Ref:</span>
                    <span style="font-weight:700; font-family:monospace; color:#1e293b;">${evt.delivery_ref || evt.po_reference || ('DEL-' + (evt.numeric_id || evt.id))}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Category:</span>
                    <span style="font-weight:600; color:#1e293b;"><i class="fas ${isFuel ? 'fa-truck-droplet' : 'fa-box'}" style="color:#64748b;"></i> ${isFuel ? 'Fuel Delivery' : 'Merchandise Delivery'}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Supplier:</span>
                    <span style="color:#1e293b;">${evt.supplier || 'Petron Corporation'}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Product / Fuel:</span>
                    <span style="font-weight:600; color:#1e293b;">${evt.product || evt.fuel_type || 'N/A'}</span>
                </div>
                ${evt.liters ? `
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Delivery Volume:</span>
                    <span style="font-weight:700; color:#1e293b;">${parseFloat(evt.liters).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})} L</span>
                </div>` : ''}
                ${evt.expected_qty ? `
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Expected Quantity:</span>
                    <span style="color:#1e293b;">${evt.expected_qty} ${evt.unit || 'units'}</span>
                </div>` : ''}
                <div style="display:flex; justify-content:space-between;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Receiving Status:</span>
                    <span style="font-weight:700; color:#1e293b; text-transform:uppercase;">${evt.receiving_status || evt.status || 'Pending'}</span>
                </div>
            </div>
        `;
    } else if (evt.type_key === 'sales_closing') {
        html += `
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid #dadce0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Shift Turnover:</span>
                    <span style="font-weight:700; color:#1e293b;">${evt.shift_name || evt.shift || 'Shift Closing'}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Closing Schedule:</span>
                    <span style="font-weight:600; color:#1e293b;">${evt.closing_time || '2:00 PM / 12:00 MN'}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Submission Status:</span>
                    <span style="font-weight:700; color:#1e293b; text-transform:uppercase;">${evt.submission_status || evt.status || 'Submitted'}</span>
                </div>
                ${evt.gross_sales ? `
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Gross Sales:</span>
                    <span style="font-weight:700; color:#1e293b;">₱${parseFloat(evt.gross_sales).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})}</span>
                </div>` : ''}
                ${evt.total_liters ? `
                <div style="display:flex; justify-content:space-between;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Total Liters:</span>
                    <span style="color:#1e293b;">${parseFloat(evt.total_liters).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})} L</span>
                </div>` : ''}
            </div>
        `;
    } else if (evt.type_key === 'fuel_calibration') {
        html += `
            <div style="margin-top:16px; padding-top:16px; border-top:1px solid #dadce0;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Operation:</span>
                    <span style="font-weight:700; color:#1e293b;">Pump Meter Calibration</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Pump Number:</span>
                    <span style="font-weight:600; color:#1e293b;">Pump ${evt.pump_number || '1'}</span>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Fuel Type:</span>
                    <span style="color:#1e293b;">${evt.fuel_type || 'Fuel'}</span>
                </div>
                ${evt.calibration_liters ? `
                <div style="display:flex; justify-content:space-between;">
                    <span style="font-weight:600; font-size:12px; color:#64748b;">Calibration Liters:</span>
                    <span style="font-weight:700; color:#1e293b;">${parseFloat(evt.calibration_liters).toFixed(2)} L</span>
                </div>` : ''}
            </div>
        `;
    }

    body.innerHTML = html;

    // Action buttons — Modal form only, no redirecting to respective areas
    let actionButtons = `
        <button type="button" onclick="closeDetailsModal()" style="padding: 10px 24px; border: none; background: #002F70; color: #fff; border-radius: 6px; font-size: 13px; cursor: pointer; font-weight: 600; transition: background 0.15s ease;">
            Close
        </button>
    `;

    actions.innerHTML = actionButtons;
    modal.style.display = 'flex';
}

function closeDetailsModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

// Click on day — Always shows Day Overview modal with all events
function clickDay(date) {
    const dayEvts = (allCalendarEvents && allCalendarEvents[date]) ? allCalendarEvents[date] : [];
    showDayOverviewModal(date, dayEvts);
}

function showDayOverviewModal(date, events) {
    const modal = document.getElementById('dayOverviewModal');
    if (!modal) return;
    const dObj = new Date(date + 'T00:00:00');
    const dateFormatted = dObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    
    document.getElementById('dayOverviewTitle').textContent = dateFormatted;
    document.getElementById('dayOverviewSubtitle').textContent = events.length > 0 
        ? (events.length + ' scheduled event(s) & operational activity(ies)')
        : 'No scheduled events or activities on this date';
    
    const listEl = document.getElementById('dayOverviewList');
    if (events.length === 0) {
        listEl.innerHTML = `
            <div style="text-align:center; padding:32px 16px; color:#64748b;">
                <div style="width:56px; height:56px; background:#f1f5f9; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:12px;">
                    <i class="far fa-calendar-check" style="font-size:26px; color:#0284c7;"></i>
                </div>
                <div style="font-weight:700; font-size:14px; color:#1e293b; margin-bottom:4px;">No activities recorded on this date</div>
                <div style="font-size:12px; color:#64748b;">There are no transactions, job orders, or scheduled events on this day.</div>
            </div>
        `;
    } else {
        listEl.innerHTML = events.map(evt => {
            const color = evt.color || '#0284c7';
            const st = (evt.status || 'pending').toUpperCase();
            let badgeBg = '#fef3c7', badgeColor = '#b45309';
            if (st === 'COMPLETED' || st === 'VERIFIED' || st === 'APPROVED' || st === 'OFFICIAL' || st === 'ACTIVE') { badgeBg = '#dcfce7'; badgeColor = '#15803d'; }
            else if (st === 'CANCELLED' || st === 'REJECTED' || st === 'VOIDED') { badgeBg = '#fee2e2'; badgeColor = '#b91c1c'; }
            else if (st === 'ADJUSTED') { badgeBg = '#e0e7ff'; badgeColor = '#4338ca'; }
            
            let timeStr = '';
            if (evt.start_time && evt.start_time !== '00:00:00') {
                timeStr = evt.start_time.substring(0, 5) + (evt.end_time && evt.end_time !== '00:00:00' ? ' - ' + evt.end_time.substring(0, 5) : '');
            }

            const iconClass = evt.icon_class || 'fas fa-calendar-alt';

            return `
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; box-shadow:0 1px 3px rgba(0,0,0,0.04); display:flex; justify-content:space-between; align-items:center; gap:12px; cursor:pointer; transition:background 0.15s ease;" onclick="closeDayOverviewModal(); clickEvent('${evt.id}', '${evt.type_key || ''}', '${evt.target_url || '#'}');">
                    <div style="flex:1; min-width:0;">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
                            <i class="${iconClass}" style="color:${color}; font-size:13px;"></i>
                            <span style="font-weight:700; font-size:13px; color:#0f172a;">${evt.calendar_title || evt.work_description || evt.type_name}</span>
                            <span style="background:${badgeBg}; color:${badgeColor}; font-size:10px; font-weight:700; padding:2px 7px; border-radius:4px;">${st}</span>
                        </div>
                        <div style="font-size:11.5px; color:#64748b; display:flex; gap:14px; flex-wrap:wrap;">
                            ${timeStr ? `<span><i class="far fa-clock" style="color:#0284c7;"></i> ${timeStr}</span>` : ''}
                            <span><i class="far fa-user" style="color:#64748b;"></i> ${evt.staff_name || 'Staff'}</span>
                            <span><i class="fas fa-tag" style="color:#64748b;"></i> ${evt.type_name || 'Event'}</span>
                        </div>
                    </div>
                    <button type="button" onclick="event.stopPropagation(); closeDayOverviewModal(); clickEvent('${evt.id}', '${evt.type_key || ''}', '${evt.target_url || '#'}');" style="padding:7px 12px; background:#002F70; color:#fff; border:none; border-radius:6px; font-size:11.5px; font-weight:600; cursor:pointer; white-space:nowrap; display:flex; align-items:center; gap:5px;">
                        <i class="fas fa-eye"></i> Details
                    </button>
                </div>
            `;
        }).join('');
    }

    modal.style.display = 'flex';
}

function closeDayOverviewModal() {
    const modal = document.getElementById('dayOverviewModal');
    if (modal) modal.style.display = 'none';
}

// Submit event form
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('eventForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(form);
            formData.append('action', 'save_event');
            
            // Show loading state
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Saving...';
            submitBtn.disabled = true;
            
            fetch('staff_calendar.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('<i class="fas fa-check"></i> Event saved successfully!');
                    location.reload();
                } else if (data.conflict) {
                    // Show conflict warning
                    if (confirm('<i class="fas fa-exclamation-triangle"></i> ' + data.message + '\n\nDo you want to save anyway? (Not recommended)')) {
                        formData.append('force_save', '1');
                        // Retry with force flag
                        fetch('staff_calendar.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(r => r.json())
                        .then(data2 => {
                            if (data2.success) {
                                alert('<i class="fas fa-check"></i> Event saved with conflict warning!');
                                location.reload();
                            } else {
                                alert('<i class="fas fa-times"></i> Error: ' + (data2.message || 'Failed to save event'));
                            }
                        });
                    } else {
                        submitBtn.textContent = originalText;
                        submitBtn.disabled = false;
                    }
                } else {
                    alert('<i class="fas fa-times"></i> Error: ' + (data.message || 'Failed to save event'));
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(e => {
                alert('<i class="fas fa-times"></i> Error saving event');
                console.error(e);
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });
    }
    
    // Close modal on outside click
    const modal = document.getElementById('eventModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal();
            }
        });
    }
});

// Toggle staff visibility
function toggleStaff(staffId) {
    // This will hide/show events for specific staff
    const checkbox = event.target.closest('.cal-calendar-item').querySelector('.cal-calendar-checkbox');
    checkbox.classList.toggle('checked');
    
    // Hide/show events by data-staff attribute
    const events = document.querySelectorAll(`[data-staff="${staffId}"]`);
    events.forEach(evt => {
        evt.style.display = checkbox.classList.contains('checked') ? 'flex' : 'none';
    });
}

// Mini calendar navigation
function navigateMiniMonth(offset) {
    // For now, just navigate main calendar
    const currentOffset = <?= $month_offset ?>;
    window.location.href = 'staff_calendar.php?month_offset=' + (currentOffset + offset);
}

// Show conflicts modal
function showConflicts() {
    const conflicts = <?= json_encode($summary_stats['conflicts']) ?>;
    let html = '<div style="max-height: 400px; overflow-y: auto;">';
    
    conflicts.forEach((conflict, idx) => {
        html += `
            <div style="padding: 12px; border: 1px solid #fce8e6; background: #fff; border-radius: 4px; margin-bottom: 12px;">
                <div style="font-weight: 600; color: #d93025; margin-bottom: 8px;">
                    <i class="fas fa-exclamation-triangle"></i> Conflict ${idx + 1}
                </div>
                <div style="font-size: 13px; color: #3c4043; margin-bottom: 4px;">
                    <strong>Date:</strong> ${conflict.event_date}
                </div>
                <div style="font-size: 12px; color: #5f6368; margin-bottom: 8px;">
                    <div><strong>Event 1:</strong> ${conflict.work_description} (${conflict.start_time} - ${conflict.end_time})</div>
                    <div><strong>Event 2:</strong> ${conflict.conflict_desc} (${conflict.conflict_start} - ${conflict.conflict_end})</div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    
    const modal = document.getElementById('eventModal');
    const title = document.getElementById('modalTitle');
    const form = document.getElementById('eventForm');
    
    title.textContent = 'Schedule Conflicts Detected';
    form.innerHTML = html + `
        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #dadce0;">
            <button type="button" onclick="closeModal()" style="padding: 10px 24px; border: 1px solid #dadce0; background: #fff; color: #3c4043; border-radius: 4px; font-size: 14px; cursor: pointer; font-weight: 500; width: 100%;">
                Close
            </button>
        </div>
    `;
    
    modal.style.display = 'flex';
}

// Handle event type change to show/hide dynamic fields
function handleEventTypeChange() {
    const eventType = document.getElementById('eventType').value;
    const dynamicFields = document.getElementById('dynamicFields');
    
    let fieldsHTML = '';
    
    switch(eventType) {
        case 'job_order':
            fieldsHTML = `
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Service Type</label>
                    <input type="text" name="service_type" placeholder="e.g., Oil Change, Tire Replacement" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Customer Name</label>
                    <input type="text" name="customer_name" placeholder="Customer name" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Job Order Status</label>
                    <select name="job_status" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
            `;
            break;
            
        case 'fuel_delivery':
        case 'merchandise_delivery':
            fieldsHTML = `
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Supplier</label>
                    <input type="text" name="supplier" placeholder="Supplier name" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Product/Item</label>
                    <input type="text" name="product" placeholder="Product or item name" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Expected Quantity</label>
                        <input type="number" step="0.01" name="expected_qty" placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Actual Quantity</label>
                        <input type="number" step="0.01" name="actual_qty" placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    </div>
                </div>
            `;
            break;
            
        case 'fuel_calibration':
        case 'meter_reading':
            fieldsHTML = `
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Pump/Tank Number</label>
                    <input type="text" name="pump_number" placeholder="Pump or tank identifier" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Expected Reading</label>
                        <input type="number" step="0.01" name="expected_reading" placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Actual Reading</label>
                        <input type="number" step="0.01" name="actual_reading" placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    </div>
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Variance</label>
                    <input type="text" name="variance" placeholder="Auto-calculated" readonly style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px; background: #f1f3f4;">
                </div>
            `;
            break;
            
        case 'customer_transaction':
        case 'payment_collection':
            fieldsHTML = `
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Customer ID</label>
                    <input type="text" name="customer_id" placeholder="Customer ID" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Amount</label>
                    <input type="number" step="0.01" name="amount" placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Payment Status</label>
                    <select name="payment_status" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                        <option value="unpaid">Unpaid</option>
                        <option value="downpayment">Downpayment</option>
                        <option value="paid">Paid in Full</option>
                    </select>
                </div>
            `;
            break;
    }
    
    dynamicFields.innerHTML = fieldsHTML;
    
    // Add variance calculation listeners for fuel calibration/meter reading
    if (eventType === 'fuel_calibration' || eventType === 'meter_reading') {
        const expected = dynamicFields.querySelector('[name="expected_reading"]');
        const actual = dynamicFields.querySelector('[name="actual_reading"]');
        const variance = dynamicFields.querySelector('[name="variance"]');
        
        function calculateVariance() {
            const exp = parseFloat(expected.value) || 0;
            const act = parseFloat(actual.value) || 0;
            const diff = act - exp;
            const pct = exp > 0 ? ((diff / exp) * 100).toFixed(2) : 0;
            variance.value = `${diff.toFixed(2)} L (${pct}%)`;
        }
        
        expected.addEventListener('input', calculateVariance);
        actual.addEventListener('input', calculateVariance);
    }
}
</script>

<!-- Day Overview Modal -->
<div id="dayOverviewModal" onclick="if(event.target===this)closeDayOverviewModal()" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); width: 92%; max-width: 540px; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; background: #002F6C; color: #fff;">
            <h2 id="dayOverviewTitle" style="margin: 0; font-size: 17px; color: #ffffff !important; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">Day Schedule</h2>
            <div id="dayOverviewSubtitle" style="font-size: 12.5px; color: #cbd5e1; margin-top: 2px; font-weight: 500;"></div>
        </div>
        <div id="dayOverviewList" style="padding: 20px 22px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 10px;">
            <!-- Filled dynamically -->
        </div>
        <div style="padding: 12px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; align-items: center; background: #f8fafc;">
            <button type="button" onclick="closeDayOverviewModal()" style="padding: 9px 20px; border: 1px solid #cbd5e1; background: #002F6C !important; color: #ffffff !important; border-radius: 6px; font-size: 13.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 5px rgba(0,47,108,0.25);">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div id="detailsModal" onclick="if(event.target===this)closeDetailsModal()" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; background: #002F6C; color: #fff;">
            <h2 id="detailsTitle" style="margin: 0; font-size: 17px; color: #ffffff !important; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;">Event Details</h2>
        </div>
        <div style="padding: 24px;">
            <div id="detailsBody" style="font-size: 14px; color: #3c4043; line-height: 1.6;">
                <!-- Filled dynamically via JS -->
            </div>
            <div id="detailsActions" style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 16px; border-top: 1px solid #dadce0; margin-top: 20px;">
                <!-- Filled dynamically via JS -->
            </div>
        </div>
    </div>
</div>

<!-- Event Modal -->
<div id="eventModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.2); width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 24px; border-bottom: 1px solid #dadce0;">
            <h2 id="modalTitle" style="margin: 0; font-size: 22px; color: #3c4043; font-weight: 400;">Create Event</h2>
        </div>
        
        <form id="eventForm" style="padding: 24px;">
            <input type="hidden" id="eventId" name="event_id">
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Date</label>
                <input type="date" id="eventDate" name="event_date" required style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Event Type</label>
                <select id="eventType" name="event_type" required onchange="handleEventTypeChange()" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    <option value="">Select type...</option>
                    <optgroup label="Work Assignments">
                        <option value="job_order">Job Order</option>
                        <option value="fuel_calibration">Fuel Calibration</option>
                        <option value="meter_reading">Meter Reading</option>
                    </optgroup>
                    <optgroup label="Deliveries">
                        <option value="fuel_delivery">Fuel Delivery</option>
                        <option value="merchandise_delivery">Merchandise Delivery</option>
                    </optgroup>
                    <optgroup label="Customer & Payments">
                        <option value="customer_transaction">Customer Transaction</option>
                        <option value="payment_collection">Payment Collection</option>
                    </optgroup>
                    <optgroup label="Other">
                        <option value="maintenance">Maintenance</option>
                        <option value="meeting">Meeting</option>
                        <option value="training">Training</option>
                        <option value="other">Other</option>
                    </optgroup>
                </select>
            </div>
            
            <!-- Dynamic fields based on event type -->
            <div id="dynamicFields"></div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Description</label>
                <textarea id="eventDescription" name="work_description" required rows="3" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px; resize: vertical;"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Start Time</label>
                    <input type="time" id="eventStartTime" name="start_time" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">End Time</label>
                    <input type="time" id="eventEndTime" name="end_time" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                </div>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #3c4043; font-weight: 500;">Status</label>
                <select id="eventStatus" name="status" style="width: 100%; padding: 10px; border: 1px solid #dadce0; border-radius: 4px; font-size: 14px;">
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 16px; border-top: 1px solid #dadce0;">
                <button type="button" onclick="closeModal()" style="padding: 10px 24px; border: 1px solid #dadce0; background: #fff; color: #3c4043; border-radius: 4px; font-size: 14px; cursor: pointer; font-weight: 500;">
                    Cancel
                </button>
                <button type="submit" style="padding: 10px 24px; border: none; background: #1a73e8; color: #fff; border-radius: 4px; font-size: 14px; cursor: pointer; font-weight: 500;">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Auto-Refresh for Calendar Module (Shift 1 & Shift 2 Real-Time Sync) -->
<script>
(function() {
    const REFRESH_INTERVAL_MS = 15000; // 15 seconds auto refresh // 2 seconds auto refresh

    function isUserEditingOrModalOpen() {
        // 1. Check if any modal is currently visible
        const modals = document.querySelectorAll('#eventModal, #detailsModal, [id*="modal"], [id*="Modal"]');
        for (const modal of modals) {
            if (modal && modal.offsetParent !== null && window.getComputedStyle(modal).display !== 'none') {
                return true;
            }
        }
        // 2. Check if an input/textarea/select is focused
        const active = document.activeElement;
        if (active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) {
            return true;
        }
        return false;
    }

    function checkAndAutoRefresh() {
        if (!isUserEditingOrModalOpen()) {
            console.log('[Calendar Sync] Auto-refreshing calendar data for Shift 1 & Shift 2 sync...');
            window.location.reload();
        } else {
            console.log('[Calendar Sync] Refresh paused while user is editing or modal is open.');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        setInterval(checkAndAutoRefresh, REFRESH_INTERVAL_MS);
    });
})();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
