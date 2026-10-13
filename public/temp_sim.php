<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Simulate staff_transactions_hub.php logic for $meter_reading_pumps
$station_id = 1253;
$fuel_shift_key = 'first';
$fuel_shift_name = 'Shift 1';
$today_date = date('Y-m-d');

// Include pumps logic from staff_transactions_hub
// Let's run the exact same queries as lines 395-580
$last_readings_by_pump = [];
$latest_stmt = $pdo->prepare("
    SELECT ft.id, COALESCE(fp.pump_number, ft.fuel_type) AS pump_label, ft.fuel_type, ft.present_reading, ft.pump_id
    FROM fuel_transactions ft
    LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
    WHERE ft.station_id = ?
      AND LOWER(COALESCE(ft.status, '')) NOT IN ('voided', 'rejected', 'cancelled', 'canceled')
      AND ft.present_reading > 0
    ORDER BY ft.transaction_date DESC, ft.id DESC
");
$latest_stmt->execute([$station_id]);
$latest_rows = $latest_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($latest_rows as $row) {
    $lbl = strtoupper(trim($row['pump_label'] ?? ''));
    $ft_name = strtoupper(trim($row['fuel_type'] ?? ''));
    $val = (float)$row['present_reading'];

    if ($lbl !== '' && !isset($last_readings_by_pump[$lbl])) {
        $last_readings_by_pump[$lbl] = $val;
        $last_readings_by_pump[preg_replace('/\s*-\s*/', ' - ', preg_replace('/\s+/', ' ', $lbl))] = $val;
        $last_readings_by_pump[str_replace(' ', '', $lbl)] = $val;
    }
    if ($ft_name !== '' && !isset($last_readings_by_pump[$ft_name])) {
        $last_readings_by_pump[$ft_name] = $val;
        $last_readings_by_pump[preg_replace('/\s*-\s*/', ' - ', preg_replace('/\s+/', ' ', $ft_name))] = $val;
        $last_readings_by_pump[str_replace(' ', '', $ft_name)] = $val;
    }
    if (!empty($row['pump_id'])) {
        $p_key = 'PUMP_' . (int)$row['pump_id'];
        if (!isset($last_readings_by_pump[$p_key])) {
            $last_readings_by_pump[$p_key] = $val;
        }
    }
}

$st_readings = $pdo->prepare("
    SELECT ft.id, COALESCE(NULLIF(fp.pump_number, ''), ft.fuel_type) AS pump_label, ft.fuel_type, ft.pump_id,
           ft.present_reading, ft.previous_reading, ft.calibration, ft.liters_sold, ft.total_amount, ft.status
    FROM fuel_transactions ft
    LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
    WHERE ft.station_id = ?
      AND DATE(ft.transaction_date) = ?
      AND (ft.shift_period = ? OR ft.shift_name = ?)
      AND LOWER(COALESCE(ft.status,'')) NOT IN ('rejected','voided','cancelled','canceled')
    ORDER BY ft.id ASC
");
$st_readings->execute([$station_id, $today_date, $fuel_shift_key, $fuel_shift_name]);
$saved_rows = $st_readings->fetchAll(PDO::FETCH_ASSOC);
$today_saved_readings = [];
foreach ($saved_rows as $sr) {
    $lbl_u = strtoupper(trim($sr['pump_label'] ?? ''));
    $ft_u  = strtoupper(trim($sr['fuel_type'] ?? ''));
    if ($lbl_u !== '') {
        $today_saved_readings[$lbl_u] = $sr;
        $today_saved_readings[preg_replace('/\s*-\s*/', ' - ', preg_replace('/\s+/', ' ', $lbl_u))] = $sr;
        $today_saved_readings[str_replace(' ', '', $lbl_u)] = $sr;
    }
    if ($ft_u !== '') {
        $today_saved_readings[$ft_u]  = $sr;
        $today_saved_readings[preg_replace('/\s*-\s*/', ' - ', preg_replace('/\s+/', ' ', $ft_u))] = $sr;
        $today_saved_readings[str_replace(' ', '', $ft_u)] = $sr;
    }
    if (!empty($sr['pump_id'])) {
        $today_saved_readings['PUMP_' . (int)$sr['pump_id']] = $sr;
    }
}

// Now test what each pump gets:
// fetch fuel_inventory
$fuel_types = $pdo->query("SELECT * FROM fuel_inventory WHERE station_id = $station_id")->fetchAll(PDO::FETCH_ASSOC);

// Include lines 4970-5074
// We need fetch_pumps_for_fuel_product
$test_results = [];
foreach ($fuel_types as $idx => $ft) {
    $ft_name = trim($ft['fuel_type'] ?? '');
    $raw_pumps = function_exists('fetch_pumps_for_fuel_product') ? fetch_pumps_for_fuel_product($pdo, $station_id, $ft) : [];
    $active_pumps = array_values(array_filter($raw_pumps, fn($p) => strtolower(trim($p['status'] ?? 'active')) === 'active'));
    $pumps_to_use = !empty($active_pumps) ? $active_pumps : $raw_pumps;

    foreach ($pumps_to_use as $p_idx => $pump) {
        $p_id = (int)($pump['id'] ?? 0);
        $raw_pnum = trim($pump['pump_number'] ?? '');
        $clean_base_group = strtoupper(trim(preg_replace('/\b(DIESEL|XTRA\s+UNL)\s+[12]\b/i', '$1', $ft_name)));
        if (str_contains($clean_base_group, 'TURBO') && str_contains($clean_base_group, 'DIESEL')) {
            $clean_base_group = 'TURBO DIESEL';
        } elseif (str_contains($clean_base_group, 'DIESEL')) {
            $clean_base_group = 'DIESEL';
        } elseif (str_contains($clean_base_group, 'XCS') && str_contains($clean_base_group, 'PLUS')) {
            $clean_base_group = 'XCS PLUS';
        } elseif (str_contains($clean_base_group, 'XTRA') && str_contains($clean_base_group, 'UNL')) {
            $clean_base_group = 'XTRA UNL';
        } elseif (str_contains($clean_base_group, 'KEROSENE')) {
            $clean_base_group = 'KEROSENE';
        } elseif (str_contains($clean_base_group, 'BLAZE')) {
            $clean_base_group = 'PETRON BLAZE 100';
        }

        $tank_idx = 1;
        if (preg_match('/\b(diesel\s*2|unl\s*2|xtra\s*unl\s*2)\b/i', $ft_name)) {
            $tank_idx = 2;
        } elseif ($raw_pnum !== '' && preg_match('/^\s*(?:diesel|xtra\s+unl)\s*2\b/i', $raw_pnum)) {
            $tank_idx = 2;
        }
        $nozzle_idx = $p_idx + 1;
        $disp_name = "{$clean_base_group} - {$tank_idx}-{$nozzle_idx}";

        $lbl_key = strtoupper(trim($disp_name));
        $saved_row = $today_saved_readings[$lbl_key] 
            ?? ($p_id > 0 && isset($today_saved_readings['PUMP_' . $p_id]) ? $today_saved_readings['PUMP_' . $p_id] : null)
            ?? ($today_saved_readings[strtoupper(trim($ft_name))] ?? null);

        if ($saved_row && (float)($saved_row['present_reading'] ?? 0) > 0) {
            $pump_prev_reading = (float)$saved_row['present_reading'];
            $src = 'today_saved';
        } elseif (isset($last_readings_by_pump[$lbl_key]) && (float)$last_readings_by_pump[$lbl_key] > 0) {
            $pump_prev_reading = (float)$last_readings_by_pump[$lbl_key];
            $src = 'last_readings_lbl';
        } elseif ($p_id > 0 && isset($last_readings_by_pump['PUMP_' . $p_id]) && (float)$last_readings_by_pump['PUMP_' . $p_id] > 0) {
            $pump_prev_reading = (float)$last_readings_by_pump['PUMP_' . $p_id];
            $src = 'last_readings_pid';
        } elseif (isset($last_readings_by_pump[strtoupper(trim($ft_name))]) && (float)$last_readings_by_pump[strtoupper(trim($ft_name))] > 0) {
            $pump_prev_reading = (float)$last_readings_by_pump[strtoupper(trim($ft_name))];
            $src = 'last_readings_ft';
        } else {
            $pump_prev_reading = 0.00;
            $src = 'none';
        }

        $test_results[] = [
            'display_name' => $disp_name,
            'p_id' => $p_id,
            'ft_name' => $ft_name,
            'prev_reading' => $pump_prev_reading,
            'src' => $src,
            'saved_row_id' => $saved_row['id'] ?? null
        ];
    }
}

file_put_contents(__DIR__ . '/temp_row_results.txt', print_r($test_results, true));
echo "TEST_COUNT: " . count($test_results);
