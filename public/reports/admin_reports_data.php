<?php
/**
 * ADMIN REPORTS DATA FETCHING LAYER
 * Fetches accurate data for all 7 Admin Report Categories & Sub-Tabs
 */

if (!defined('PETRON_SYSTEM')) {
    define('PETRON_SYSTEM', true);
}

require_once __DIR__ . '/../../backend/lib.php';

if (!function_exists('ard_table_exists')) {
    function ard_table_exists(PDO $pdo, string $tbl): bool {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$tbl'");
            return $stmt && $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('get_exact_ugt_no')) {
    function get_exact_ugt_no(string $rawFuelType): string {
        $s = strtoupper(trim($rawFuelType));
        
        // Explicit check for DIESEL 2 vs DIESEL 1
        if (strpos($s, 'DIESEL 2') !== false || strpos($s, 'DIESEL-2') !== false || strpos($s, 'UGT #2') !== false || strpos($s, 'UGT-02') !== false || strpos($s, 'UGT 2') !== false) return 'UGT #2';
        if (strpos($s, 'DIESEL 1') !== false || strpos($s, 'DIESEL-1') !== false || strpos($s, 'UGT #1') !== false || strpos($s, 'UGT-01') !== false || strpos($s, 'UGT 1') !== false) return 'UGT #1';
        
        // Check TURBO DIESEL -> UGT #3
        if (strpos($s, 'TURBO') !== false || strpos($s, 'UGT #3') !== false || strpos($s, 'UGT-03') !== false || strpos($s, 'UGT 3') !== false) return 'UGT #3';
        
        // Check XCS PLUS -> UGT #4
        if (strpos($s, 'XCS') !== false || strpos($s, 'UGT #4') !== false || strpos($s, 'UGT-04') !== false || strpos($s, 'UGT 4') !== false) return 'UGT #4';

        // Explicit check for XTRA UNL 2 vs XTRA UNL 1
        if (strpos($s, 'XTRA UNL 2') !== false || strpos($s, 'XTRA 2') !== false || strpos($s, 'UNL 2') !== false || strpos($s, 'UGT #6') !== false || strpos($s, 'UGT-06') !== false || strpos($s, 'UGT 6') !== false) return 'UGT #6';
        if (strpos($s, 'XTRA UNL 1') !== false || strpos($s, 'XTRA 1') !== false || strpos($s, 'UNL 1') !== false || strpos($s, 'UGT #5') !== false || strpos($s, 'UGT-05') !== false || strpos($s, 'UGT 5') !== false) return 'UGT #5';
        
        // Check KEROSENE
        if (strpos($s, 'KEROSENE') !== false || strpos($s, 'UGT #7') !== false || strpos($s, 'UGT-07') !== false || strpos($s, 'UGT 7') !== false) return 'UGT #7';
        
        // Fallback checks
        if (strpos($s, 'DIESEL') !== false) {
            if (strpos($s, '2') !== false) return 'UGT #2';
            return 'UGT #1';
        }
        if (strpos($s, 'XTRA') !== false || strpos($s, 'UNL') !== false) {
            if (strpos($s, '2') !== false) return 'UGT #6';
            return 'UGT #5';
        }
        return 'UGT #1';
    }
}

if (!function_exists('staff_report_fuel_display_name')) {
    function staff_report_fuel_display_name($fuel_type): string {
        $name = trim((string)$fuel_type);
        $name = preg_replace('/\s+\d+\s*-\s*\d+$/', '', $name);
        $name = preg_replace('/\s*-\s*\d+$/', '', $name);
        $name = trim($name);
        $normalized = strtoupper(preg_replace('/\s+/', ' ', $name));
        if (strpos($normalized, 'TURBO') !== false && strpos($normalized, 'DIESEL') !== false) return 'Turbo Diesel';
        if (strpos($normalized, 'KEROSENE') !== false) return 'Kerosene';
        if (strpos($normalized, 'XCS') !== false) return 'XCS Plus';
        if (strpos($normalized, 'XTRA') !== false && strpos($normalized, 'UNL') !== false) return 'Xtra UNL';
        if (strpos($normalized, 'DIESEL') !== false) return 'Diesel';
        return $name !== '' ? $name : 'Fuel';
    }
}

if (!function_exists('getAdminReportData')) {
    function getAdminReportData(PDO $pdo, int $station_id, string $date_from, string $date_to, string $cat, string $tab, array $filters = []): array {
    $data = [];

    // Helper for table-qualified station_id clause
    $st_params = ($station_id > 0) ? ['station_id' => $station_id] : [];
    $st_clause = function(string $alias) use ($station_id): string {
        if ($station_id <= 0) return "";
        return " AND {$alias}.station_id = :station_id ";
    };

    switch ($cat) {

        // =========================================================================
        // 1. SALES REPORTS
        // =========================================================================
        case 'sales':
            if ($tab === 'fuel_sales') {
                // ---------------------------------------------------------------
                // FUEL SALES REPORT - UGT Daily Reconciliation (Back Office)
                // Combines Shift 1 + Shift 2 into ONE daily report per pump/UGT
                // NO: customer, receipt, payment method, attendant, shift columns
                // 5 CORE FUEL TYPES: Diesel, Turbo Diesel, XCS Plus, XTR Advance, Kerosene
                // ---------------------------------------------------------------
                
                $sql_norm = "CASE 
                    WHEN UPPER(ft.fuel_type) LIKE '%TURBO%' THEN 'Turbo Diesel'
                    WHEN UPPER(ft.fuel_type) LIKE '%DIESEL%' THEN 'Diesel'
                    WHEN UPPER(ft.fuel_type) LIKE '%XCS%' THEN 'XCS Plus'
                    WHEN UPPER(ft.fuel_type) LIKE '%XTRA%' OR UPPER(ft.fuel_type) LIKE '%UNL%' THEN 'XTR Advance'
                    WHEN UPPER(ft.fuel_type) LIKE '%KEROSENE%' THEN 'Kerosene'
                    ELSE ft.fuel_type
                END";

                $extra_where  = "";
                $extra_params = [];
                if (!empty($filters['fuel_type'])) {
                    $extra_where .= " AND ({$sql_norm}) = :filter_fuel_type ";
                    $extra_params['filter_fuel_type'] = $filters['fuel_type'];
                }
                // UGT filter: filter_ugt holds a UGT label like "UGT #1" or "UGT-01"
                // We convert it to a fuel_type LIKE match via get_exact_ugt_no logic
                if (!empty($filters['ugt'])) {
                    $fi_ugt_raw = strtoupper(trim($filters['ugt']));
                    // Map UGT label to fuel_type LIKE patterns
                    $ugt_fuel_like = null;
                    if (strpos($fi_ugt_raw, '#1') !== false || strpos($fi_ugt_raw, '-01') !== false || strpos($fi_ugt_raw, ' 1') !== false) {
                        $ugt_fuel_like = 'DIESEL 1%';
                    } elseif (strpos($fi_ugt_raw, '#2') !== false || strpos($fi_ugt_raw, '-02') !== false || strpos($fi_ugt_raw, ' 2') !== false) {
                        $ugt_fuel_like = 'DIESEL 2%';
                    } elseif (strpos($fi_ugt_raw, '#3') !== false || strpos($fi_ugt_raw, '-03') !== false || strpos($fi_ugt_raw, ' 3') !== false) {
                        $ugt_fuel_like = 'TURBO%';
                    } elseif (strpos($fi_ugt_raw, '#4') !== false || strpos($fi_ugt_raw, '-04') !== false || strpos($fi_ugt_raw, ' 4') !== false) {
                        $ugt_fuel_like = 'XCS%';
                    } elseif (strpos($fi_ugt_raw, '#5') !== false || strpos($fi_ugt_raw, '-05') !== false || strpos($fi_ugt_raw, ' 5') !== false) {
                        $ugt_fuel_like = 'XTRA UNL 1%';
                    } elseif (strpos($fi_ugt_raw, '#6') !== false || strpos($fi_ugt_raw, '-06') !== false || strpos($fi_ugt_raw, ' 6') !== false) {
                        $ugt_fuel_like = 'XTRA UNL 2%';
                    } elseif (strpos($fi_ugt_raw, '#7') !== false || strpos($fi_ugt_raw, '-07') !== false || strpos($fi_ugt_raw, ' 7') !== false) {
                        $ugt_fuel_like = 'KEROSENE%';
                    }
                    if ($ugt_fuel_like !== null) {
                        $extra_where .= " AND UPPER(ft.fuel_type) LIKE :filter_ugt_like ";
                        $extra_params['filter_ugt_like'] = $ugt_fuel_like;
                    }
                }
                // Fallback: old pump_id integer filter (kept for backward compat if pump_id is set)
                if (!empty($filters['pump_id']) && (int)$filters['pump_id'] > 0) {
                    $extra_where .= " AND ft.pump_id = :filter_pump_id ";
                    $extra_params['filter_pump_id'] = (int)$filters['pump_id'];
                }
                if (!empty($filters['shift'])) {
                    $shift_val = strtolower(trim($filters['shift']));
                    if (strpos($shift_val, '1') !== false || strpos($shift_val, 'first') !== false) {
                        $extra_where .= " AND (LOWER(COALESCE(ft.shift_period, ft.shift_name, '')) LIKE '%first%' OR LOWER(COALESCE(ft.shift_period, ft.shift_name, '')) LIKE '%shift 1%' OR ft.shift_id = 1 OR COALESCE(ft.shift_period, ft.shift_name, '') = '1') ";
                    } elseif (strpos($shift_val, '2') !== false || strpos($shift_val, 'second') !== false) {
                        $extra_where .= " AND (LOWER(COALESCE(ft.shift_period, ft.shift_name, '')) LIKE '%second%' OR LOWER(COALESCE(ft.shift_period, ft.shift_name, '')) LIKE '%shift 2%' OR ft.shift_id = 2 OR COALESCE(ft.shift_period, ft.shift_name, '') = '2') ";
                    }
                }
                $base_params = array_merge(['date_from' => $date_from, 'date_to' => $date_to], $st_params, $extra_params);

                // Build UGT list for the filter dropdown from fuel_inventory (reliable UGT labels)
                $pump_list = [];
                try {
                    $ugt_fi_stmt = $pdo->prepare(
                        "SELECT ugt_no, fuel_type FROM fuel_inventory
                         WHERE station_id = :sid AND status IN ('active','Active','Normal')
                           AND ugt_no IS NOT NULL AND TRIM(ugt_no) != ''
                         ORDER BY ugt_no ASC"
                    );
                    $ugt_fi_stmt->execute(['sid' => $station_id]);
                    $fi_rows = $ugt_fi_stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($fi_rows as $fr) {
                        $pump_list[] = ['pump_id' => $fr['ugt_no'], 'label' => $fr['ugt_no'] . ' (' . $fr['fuel_type'] . ')'];
                    }
                } catch (Exception $e) { $pump_list = []; }

                // Fallback: build from fuel_transactions pump_ids if fuel_inventory empty
                if (empty($pump_list)) {
                    $all_pumps_stmt = $pdo->prepare(
                        "SELECT DISTINCT ft.pump_id
                         FROM fuel_transactions ft
                         WHERE ft.pump_id IS NOT NULL AND ft.pump_id > 0 {$st_clause('ft')}
                         ORDER BY ft.pump_id ASC"
                    );
                    $all_pumps_stmt->execute($st_params);
                    $all_pump_ids = $all_pumps_stmt->fetchAll(PDO::FETCH_COLUMN);
                    $ugt_map = [];
                    foreach ($all_pump_ids as $idx => $pid) {
                        $code = sprintf('UGT-%02d', $idx + 1);
                        $ugt_map[$pid] = $code;
                        $pump_list[] = ['pump_id' => $pid, 'label' => $code];
                    }
                }
                $data['pump_list'] = $pump_list;

                // Fuel sales only appear in official reports if approved by Manager/Admin and fuel closing is verified
                $app_clause = function(string $alias) {
                    return " AND LOWER(COALESCE({$alias}.status, '')) IN ('verified','approved','validated')
                             AND EXISTS (
                                 SELECT 1 FROM fuel_sales_closing fsc_app
                                 WHERE fsc_app.station_id = {$alias}.station_id
                                   AND fsc_app.report_date = DATE(COALESCE({$alias}.transaction_date, {$alias}.created_at))
                                   AND (
                                       fsc_app.shift_period = {$alias}.shift_period
                                       OR fsc_app.shift = {$alias}.shift_name
                                       OR fsc_app.shift = {$alias}.shift_period
                                       OR LOWER(fsc_app.shift) LIKE CONCAT('%', LOWER(COALESCE({$alias}.shift_period, '')), '%')
                                       OR {$alias}.shift_period IS NULL OR {$alias}.shift_period = ''
                                   )
                                   AND LOWER(COALESCE(fsc_app.status, '')) IN ('verified','approved','validated')
                             ) ";
                };

                // 1. UGT DAILY SALES TABLE — deduplicated latest reading per pump per day
                $stmt = $pdo->prepare(
                    "SELECT ft.pump_id,
                            ft.fuel_type as raw_fuel_type,
                            {$sql_norm} as clean_fuel_type,
                            DATE(COALESCE(ft.transaction_date, ft.created_at)) as report_date,
                            COALESCE(ft.previous_reading, 0) as beginning_reading,
                            COALESCE(ft.present_reading, 0)  as ending_reading,
                            COALESCE(ft.calibration, 0) as total_calibration,
                            COALESCE(ft.liters_sold, 0) as net_volume_sold,
                            COALESCE(ft.price_per_liter, 0) as price_per_liter,
                            COALESCE(ft.total_amount, 0) as total_fuel_sales,
                            1 as shift_count
                     FROM fuel_transactions ft
                     LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
                         INNER JOIN (
                             SELECT MAX(id) AS max_id
                             FROM fuel_transactions
                             WHERE DATE(COALESCE(transaction_date, created_at)) BETWEEN :date_from AND :date_to
                               {$st_clause('fuel_transactions')}
                               {$app_clause('fuel_transactions')}
                             GROUP BY COALESCE(pump_id, fuel_type), DATE(COALESCE(transaction_date, created_at))
                         ) latest ON ft.id = latest.max_id
                     WHERE 1=1 {$extra_where}
                     ORDER BY ft.fuel_type ASC, ft.pump_id ASC"
                );
                $stmt->execute($base_params);
                $raw_ugt = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($raw_ugt as &$r) {
                    $rawFuel = $r['raw_fuel_type'] ?? '';
                    $r['ugt_no'] = get_exact_ugt_no($rawFuel);
                    $r['fuel_type'] = !empty($rawFuel) ? $rawFuel : ($r['clean_fuel_type'] ?? 'Fuel');
                }
                unset($r);
                if (!empty($filters['ugt'])) {
                    $target_ugt = strtoupper(trim($filters['ugt']));
                    $canonical_target = get_exact_ugt_no($target_ugt);
                    $raw_ugt = array_values(array_filter($raw_ugt, function($r) use ($target_ugt, $canonical_target) {
                        $u = strtoupper(trim($r['ugt_no'] ?? ''));
                        return $u === $target_ugt || $u === $canonical_target || stripos($u, $target_ugt) !== false;
                    }));
                }
                $data['ugt_rows'] = $raw_ugt;

                // 2. DAILY FUEL SALES SUMMARY — per normalized 5 fuel types
                $stmt2 = $pdo->prepare(
                    "SELECT 
                        cat.norm_fuel_type as fuel_type,
                        COUNT(DISTINCT cat.pump_id) as ugt_count,
                        SUM(COALESCE(cat.liters_sold, 0)) as total_volume,
                        MAX(cat.price_per_liter) as avg_price,
                        SUM(COALESCE(cat.total_amount, 0)) as total_sales
                    FROM (
                        SELECT ft.pump_id, ft.liters_sold, ft.price_per_liter, ft.total_amount,
                               {$sql_norm} as norm_fuel_type
                        FROM fuel_transactions ft
                            INNER JOIN (
                                SELECT MAX(id) AS max_id
                                FROM fuel_transactions
                                WHERE DATE(COALESCE(transaction_date, created_at)) BETWEEN :date_from AND :date_to
                                  {$st_clause('fuel_transactions')}
                                  {$app_clause('fuel_transactions')}
                                GROUP BY COALESCE(pump_id, fuel_type), DATE(COALESCE(transaction_date, created_at))
                            ) latest ON ft.id = latest.max_id
                        WHERE 1=1 {$extra_where}
                    ) cat
                    GROUP BY cat.norm_fuel_type
                    ORDER BY total_sales DESC"
                );
                $stmt2->execute($base_params);
                $data['fuel_summary'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                // 3. DAILY RECONCILIATION SUMMARY
                $stmt3 = $pdo->prepare(
                    "SELECT COUNT(DISTINCT ft.pump_id) as total_ugts,
                            MIN(ft.previous_reading) as total_beginning,
                            MAX(ft.present_reading)  as total_ending,
                            SUM(COALESCE(ft.calibration, 0)) as total_calibration,
                            SUM(COALESCE(ft.liters_sold, 0)) as total_volume_sold,
                            SUM(COALESCE(ft.total_amount, 0)) as total_fuel_sales
                     FROM fuel_transactions ft
                     INNER JOIN (
                         SELECT MAX(id) AS max_id
                         FROM fuel_transactions
                         WHERE DATE(COALESCE(transaction_date, created_at)) BETWEEN :date_from AND :date_to
                           {$st_clause('fuel_transactions')}
                           {$app_clause('fuel_transactions')}
                         GROUP BY COALESCE(pump_id, fuel_type), DATE(COALESCE(transaction_date, created_at))
                     ) latest ON ft.id = latest.max_id
                     WHERE 1=1 {$extra_where}"
                );
                $stmt3->execute($base_params);
                $data['reconciliation'] = $stmt3->fetch(PDO::FETCH_ASSOC);

                // 4. VARIANCE CHECK — per normalized 5 fuel types
                $stmt4 = $pdo->prepare(
                    "SELECT 
                        cat.norm_fuel_type as fuel_type,
                        SUM(COALESCE(cat.liters_sold,0) * COALESCE(cat.price_per_liter,0)) as expected_sales,
                        SUM(COALESCE(cat.total_amount,0)) as recorded_sales,
                        ROUND(SUM(COALESCE(cat.total_amount,0)) - SUM(COALESCE(cat.liters_sold,0) * COALESCE(cat.price_per_liter,0)), 2) as variance
                    FROM (
                        SELECT ft.liters_sold, ft.price_per_liter, ft.total_amount,
                               {$sql_norm} as norm_fuel_type
                        FROM fuel_transactions ft
                        INNER JOIN (
                            SELECT MAX(id) AS max_id
                            FROM fuel_transactions
                            WHERE DATE(COALESCE(transaction_date, created_at)) BETWEEN :date_from AND :date_to
                              {$st_clause('fuel_transactions')}
                              {$app_clause('fuel_transactions')}
                            GROUP BY COALESCE(pump_id, fuel_type), DATE(COALESCE(transaction_date, created_at))
                        ) latest ON ft.id = latest.max_id
                        WHERE 1=1 {$extra_where}
                    ) cat
                    GROUP BY cat.norm_fuel_type
                    ORDER BY cat.norm_fuel_type ASC"
                );
                $stmt4->execute($base_params);
                $data['variance'] = $stmt4->fetchAll(PDO::FETCH_ASSOC);

                // 5. UGT SALES SUMMARY (per pump summary)
                try {
                    $stmt_ugt_sum = $pdo->prepare(
                        "SELECT 
                            ft.pump_id,
                            ft.fuel_type as raw_fuel_type,
                            {$sql_norm} as clean_fuel_type,
                            SUM(COALESCE(ft.liters_sold, 0)) as total_volume,
                            MAX(ft.price_per_liter) as avg_price,
                            SUM(COALESCE(ft.total_amount, 0)) as total_sales
                         FROM fuel_transactions ft
                         WHERE DATE(ft.transaction_date) BETWEEN :date_from AND :date_to
                           {$app_clause('ft')}
                           {$st_clause('ft')} {$extra_where}
                         GROUP BY ft.pump_id, ft.fuel_type
                         ORDER BY ft.pump_id ASC"
                    );
                    $stmt_ugt_sum->execute($base_params);
                    $raw_ugt_sum = $stmt_ugt_sum->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($raw_ugt_sum as &$us) {
                        $rf = $us['raw_fuel_type'] ?? '';
                        $us['ugt_no'] = get_exact_ugt_no($rf);
                        $us['fuel_type'] = !empty($rf) ? $rf : ($us['clean_fuel_type'] ?? 'Fuel');
                    }
                    $data['ugt_summary'] = $raw_ugt_sum;
                } catch (Exception $e) {
                    $data['ugt_summary'] = [];
                }

                // Dynamic UGT Tanks Liters Sold Summary:
                // Only load tanks that have actually been configured/added for this station in fuel_inventory.
                // A new station with no fuel products configured will have an empty tank summary.
                $tank_ugt_summary = [];
                $tank_mapping     = [];

                try {
                    $stmt_tanks = $pdo->prepare(
                        "SELECT id, ugt_no, fuel_type, capacity
                         FROM fuel_inventory
                         WHERE 1=1 {$st_clause('fuel_inventory')}
                           AND LOWER(COALESCE(status, 'active')) NOT IN ('archived', 'deleted')
                         ORDER BY id ASC"
                    );
                    $stmt_tanks->execute($st_params);
                    $configured_tanks = $stmt_tanks->fetchAll(PDO::FETCH_ASSOC);

                    // Safely sort by numerical UGT number in PHP
                    usort($configured_tanks, function($a, $b) {
                        $numA = (int)preg_replace('/[^0-9]/', '', (string)($a['ugt_no'] ?? ''));
                        $numB = (int)preg_replace('/[^0-9]/', '', (string)($b['ugt_no'] ?? ''));
                        if ($numA !== $numB) {
                            return $numA <=> $numB;
                        }
                        return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
                    });

                    foreach ($configured_tanks as $stk) {
                        $u_raw = trim((string)($stk['ugt_no'] ?? ''));
                        if ($u_raw !== '') {
                            $u_num = preg_replace('/[^0-9]/', '', $u_raw);
                            $u_label = $u_num ? ('UGT #' . (int)$u_num) : $u_raw;
                        } else {
                            $u_label = 'UGT #' . $stk['id'];
                        }
                        $f_label = strtoupper(trim((string)($stk['fuel_type'] ?? 'Fuel')));
                        $tank_label = strtoupper($u_label) . ' (' . $f_label . ')';

                        $tank_ugt_summary[$tank_label] = 0.0;
                        $tank_mapping[] = [
                            'label'     => $tank_label,
                            'u_num'     => preg_replace('/[^0-9]/', '', $u_label),
                            'u_raw'     => strtoupper($u_raw),
                            'fuel_type' => $f_label,
                            'clean_ft'  => strtolower(trim((string)($stk['fuel_type'] ?? '')))
                        ];
                    }
                } catch (Exception $e) {
                    $tank_ugt_summary = [];
                    $tank_mapping     = [];
                }

                // Only populate liters if station has configured tanks and transactions exist
                if (!empty($tank_ugt_summary) && !empty($raw_ugt)) {
                    foreach ($raw_ugt as $r) {
                        $pName  = strtoupper(trim(($r['raw_fuel_type'] ?? '') ?: ($r['clean_fuel_type'] ?? '')));
                        $ftype  = strtolower(trim($r['clean_fuel_type'] ?? ''));
                        $uNum   = preg_replace('/[^0-9]/', '', (string)($r['ugt_no'] ?? ''));
                        $liters = (float)($r['net_volume_sold'] ?? 0);

                        $assigned = false;
                        // 1. Try to match by UGT number if present
                        if ($uNum !== '') {
                            foreach ($tank_mapping as $tm) {
                                if ($tm['u_num'] !== '' && (int)$tm['u_num'] === (int)$uNum) {
                                    $tank_ugt_summary[$tm['label']] += $liters;
                                    $assigned = true;
                                    break;
                                }
                            }
                        }

                        // 2. Try to match by fuel type
                        if (!$assigned) {
                            foreach ($tank_mapping as $tm) {
                                if (
                                    (!empty($pName) && (stripos($tm['fuel_type'], $pName) !== false || stripos($pName, $tm['fuel_type']) !== false)) ||
                                    (!empty($ftype) && (stripos($tm['clean_ft'], $ftype) !== false || stripos($ftype, $tm['clean_ft']) !== false))
                                ) {
                                    $tank_ugt_summary[$tm['label']] += $liters;
                                    $assigned = true;
                                    break;
                                }
                            }
                        }
                    }
                }
                $data['tank_ugt_summary'] = $tank_ugt_summary;

                // 6. TANK LITER SUMMARY (Remaining Liters)
                try {
                    $stmt_tank = $pdo->prepare(
                        "SELECT 
                            fi.id,
                            COALESCE(fi.ugt_no, CONCAT('UGT #', fi.id)) as ugt_no,
                            fi.fuel_type,
                            COALESCE(fi.current_level, fi.current_stock, 0) as current_level,
                            COALESCE(fi.capacity, 0) as capacity,
                            fi.status
                         FROM fuel_inventory fi
                         WHERE 1=1 {$st_clause('fi')}
                         ORDER BY fi.id ASC"
                    );
                    $stmt_tank->execute($st_params);
                    $data['tank_summary'] = $stmt_tank->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $data['tank_summary'] = [];
                }

                // 7. FUEL SALES CLOSING SUMMARY
                try {
                    $close_where = "";
                    if (!empty($filters['shift'])) {
                        $shift_val = trim($filters['shift']);
                        if (strpos($shift_val, '1') !== false) {
                            $close_where .= " AND (fsc.shift LIKE '%Shift 1%' OR fsc.shift = '1') ";
                        } elseif (strpos($shift_val, '2') !== false) {
                            $close_where .= " AND (fsc.shift LIKE '%Shift 2%' OR fsc.shift = '2') ";
                        }
                    }
                    $stmt_close = $pdo->prepare(
                        "SELECT 
                            SUM(COALESCE(fsc.total_fuel_sales, 0)) as total_fuel_sales,
                            SUM(COALESCE(fsc.total_liters, 0)) as total_liters,
                            SUM(COALESCE(fsc.total_store_sales, 0)) as shop_sales,
                            SUM(COALESCE(fsc.cash_shift1, 0)) as cash_shift1,
                            SUM(COALESCE(fsc.cash_shift2, 0)) as cash_shift2,
                            SUM(COALESCE(fsc.total_cash, 0)) as total_cash,
                            SUM(COALESCE(fsc.ar_shift1, 0)) as ar_shift1,
                            SUM(COALESCE(fsc.ar_shift2, 0)) as ar_shift2,
                            SUM(COALESCE(fsc.total_ar, 0)) as total_ar,
                            SUM(COALESCE(fsc.gross_sales, 0)) as gross_sales,
                            SUM(COALESCE(fsc.expected_cash, 0)) as expected_cash,
                            SUM(COALESCE(fsc.total_cash_bank, 0)) as total_cash_bank
                         FROM fuel_sales_closing fsc
                         WHERE fsc.report_date BETWEEN :date_from AND :date_to
                           AND LOWER(COALESCE(fsc.status, '')) IN ('verified', 'approved', 'official', 'validated')
                           {$st_clause('fsc')} {$close_where}"
                    );
                    $stmt_close->execute(['date_from' => $date_from, 'date_to' => $date_to] + $st_params);
                    $data['closing_summary'] = $stmt_close->fetch(PDO::FETCH_ASSOC) ?: [];
                } catch (Exception $e) {
                    $data['closing_summary'] = [];
                }

                // Shift Breakdown for Manager Daily Summary
                try {
                    $stmt_s1 = $pdo->prepare(
                        "SELECT 
                            SUM(COALESCE(ft.liters_sold, 0)) as liters,
                            SUM(COALESCE(ft.total_amount, 0)) as sales
                         FROM fuel_transactions ft
                         INNER JOIN (
                             SELECT MAX(id) AS max_id
                             FROM fuel_transactions
                             WHERE DATE(COALESCE(transaction_date, created_at)) BETWEEN :date_from AND :date_to
                               {$st_clause('fuel_transactions')}
                               {$app_clause('fuel_transactions')}
                             GROUP BY COALESCE(pump_id, fuel_type), DATE(COALESCE(transaction_date, created_at)), COALESCE(shift_period, shift_name, shift_id)
                         ) latest ON ft.id = latest.max_id
                         WHERE (LOWER(COALESCE(ft.shift_period, ft.shift_name, '')) LIKE '%first%' OR LOWER(COALESCE(ft.shift_period, ft.shift_name, '')) LIKE '%shift 1%' OR ft.shift_id = 1 OR COALESCE(ft.shift_period, ft.shift_name, '') = '1')"
                    );
                    $stmt_s1->execute(['date_from' => $date_from, 'date_to' => $date_to] + $st_params);
                    $s1_data = $stmt_s1->fetch(PDO::FETCH_ASSOC) ?: [];

                    $stmt_s2 = $pdo->prepare(
                        "SELECT 
                            SUM(COALESCE(ft.liters_sold, 0)) as liters,
                            SUM(COALESCE(ft.total_amount, 0)) as sales
                         FROM fuel_transactions ft
                         INNER JOIN (
                             SELECT MAX(id) AS max_id
                             FROM fuel_transactions
                             WHERE DATE(COALESCE(transaction_date, created_at)) BETWEEN :date_from AND :date_to
                               {$st_clause('fuel_transactions')}
                               {$app_clause('fuel_transactions')}
                             GROUP BY COALESCE(pump_id, fuel_type), DATE(COALESCE(transaction_date, created_at)), COALESCE(shift_period, shift_name, shift_id)
                         ) latest ON ft.id = latest.max_id
                         WHERE (LOWER(COALESCE(ft.shift_period, ft.shift_name, '')) LIKE '%second%' OR LOWER(COALESCE(ft.shift_period, ft.shift_name, '')) LIKE '%shift 2%' OR ft.shift_id = 2 OR COALESCE(ft.shift_period, ft.shift_name, '') = '2')"
                    );
                    $stmt_s2->execute(['date_from' => $date_from, 'date_to' => $date_to] + $st_params);
                    $s2_data = $stmt_s2->fetch(PDO::FETCH_ASSOC) ?: [];

                    // Check for pending readings/closing requiring approval
                    try {
                        $stmt_pen = $pdo->prepare("
                            SELECT COUNT(*) FROM fuel_transactions ft
                            WHERE DATE(COALESCE(ft.transaction_date, ft.created_at)) BETWEEN :date_from AND :date_to
                              {$st_clause('ft')}
                              AND LOWER(COALESCE(ft.status, '')) NOT IN ('verified','approved','validated','rejected','voided','cancelled','canceled')
                        ");
                        $stmt_pen->execute(['date_from' => $date_from, 'date_to' => $date_to] + $st_params);
                        $pen_cnt = (int)$stmt_pen->fetchColumn();
                        if ($pen_cnt > 0) {
                            $data['pending_approval_notice'] = "There are {$pen_cnt} fuel transaction reading(s) awaiting validation/approval. Unapproved sales figures are held until validated.";
                        }
                    } catch (Exception $e) {}

                    $cs = $data['closing_summary'] ?? [];
                    $data['shift_breakdown'] = [
                        's1_liters' => (float)($s1_data['liters'] ?? 0),
                        's1_sales'  => (float)($s1_data['sales'] ?? 0),
                        's1_cash'   => (float)($cs['cash_shift1'] ?? 0),
                        's1_ar'     => (float)($cs['ar_shift1'] ?? 0),
                        's2_liters' => (float)($s2_data['liters'] ?? 0),
                        's2_sales'  => (float)($s2_data['sales'] ?? 0),
                        's2_cash'   => (float)($cs['cash_shift2'] ?? 0),
                        's2_ar'     => (float)($cs['ar_shift2'] ?? 0),
                    ];
                } catch (Exception $e) {
                    $data['shift_breakdown'] = [];
                }

                // 8. FUEL ADJUSTMENT SUMMARY
                try {
                    $stmt_adj = $pdo->prepare(
                        "SELECT 
                            fa.adjustment_date as adj_date,
                            COALESCE(fa.ugt_no, 'UGT #1') as ugt_no,
                            fa.fuel_type,
                            COALESCE(fa.reason, fa.notes, 'Stock adjustment') as reason,
                            COALESCE(u.username, u.full_name, 'System') as adjusted_by,
                            COALESCE(fa.liters, fa.variance, 0) as liters,
                            fa.adjustment_direction,
                            fa.status
                         FROM fuel_adjustments fa
                         LEFT JOIN users u ON fa.user_id = u.id
                         WHERE (fa.adjustment_date IS NULL OR fa.adjustment_date BETWEEN :date_from AND :date_to)
                           {$st_clause('fa')}
                         ORDER BY fa.adjustment_date DESC"
                    );
                    $stmt_adj->execute(['date_from' => $date_from, 'date_to' => $date_to] + $st_params);
                    $data['fuel_adjustments'] = $stmt_adj->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $data['fuel_adjustments'] = [];
                }

                // 9. Filter dropdowns
                try {
                    $stmt6 = $pdo->prepare(
                        "SELECT DISTINCT {$sql_norm} as fuel_type
                         FROM fuel_transactions ft WHERE 1=1 {$st_clause('ft')} ORDER BY fuel_type ASC"
                    );
                    $stmt6->execute($st_params);
                    $data['fuel_types'] = $stmt6->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {
                    $data['fuel_types'] = [];
                }

            } else { // daily_merch_service
                // ---------------------------------------------------------------
                // DAILY MERCHANDISE & SERVICE SALES REPORT (24-Hour Summary)
                // Real DB queries with full dynamic filters & 5 summary sections
                // ---------------------------------------------------------------

                $filter_pm     = $filters['payment_method'] ?? '';
                $filter_ttype  = $filters['transaction_type'] ?? ''; // 'merchandise', 'job_order', or '' (both)
                $filter_cust   = trim($filters['customer'] ?? '');
                $filter_mech   = trim($filters['mechanic'] ?? '');
                $filter_status = $filters['status'] ?? '';

                // 1. MERCHANDISE SALES TRANSACTIONS TABLE
                $data['merchandise'] = [];
                if ($filter_ttype === '' || $filter_ttype === 'merchandise') {
                    $m_where = " AND LOWER(COALESCE(mt.transaction_type,'')) NOT IN ('job_order','service') ";
                    $m_params = ['date_from' => $date_from, 'date_to' => $date_to];

                    if (!empty($filter_pm)) {
                        $norm_f = function_exists('normalize_payment_type') ? normalize_payment_type($filter_pm) : ['type' => $filter_pm, 'provider' => ''];
                        if ($norm_f['type'] === 'E-Wallet') {
                            if (!empty($norm_f['provider'])) {
                                $m_where .= " AND (LOWER(COALESCE(mt.ewallet_provider,'')) = LOWER(:filter_prov) OR LOWER(COALESCE(mt.payment_method,'')) = LOWER(:filter_prov)) ";
                                $m_params['filter_prov'] = $norm_f['provider'];
                            } else {
                                $m_where .= " AND (LOWER(COALESCE(mt.payment_method,'')) IN ('e-wallet', 'ewallet', 'gcash', 'maya') OR (mt.ewallet_provider IS NOT NULL AND mt.ewallet_provider != '')) ";
                            }
                        } else {
                            $m_where .= " AND LOWER(COALESCE(mt.payment_method,'')) = LOWER(:filter_pm) ";
                            $m_params['filter_pm'] = $norm_f['type'];
                        }
                    }
                    if (!empty($filter_cust)) {
                        $m_where .= " AND (LOWER(mt.customer_name) LIKE LOWER(:filter_cust) OR LOWER(mt.customer_first_name) LIKE LOWER(:filter_cust) OR LOWER(mt.customer_last_name) LIKE LOWER(:filter_cust)) ";
                        $m_params['filter_cust'] = '%' . $filter_cust . '%';
                    }
                    if (!empty($filter_status)) {
                        $m_where .= " AND LOWER(COALESCE(mt.workflow_status, mt.validation_status, '')) = LOWER(:filter_status) ";
                        $m_params['filter_status'] = $filter_status;
                    }

                    $sql_merch = "SELECT 
                                    mt.transaction_id as receipt_no,
                                    DATE(mt.transaction_date) as date,
                                    COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
                                    COALESCE(mti.category, 'General') as category,
                                    COALESCE(mti.product_name, mt.item_sku, 'Merchandise Product') as product,
                                    COALESCE(mti.quantity, mt.quantity, 1) as quantity,
                                    COALESCE(mti.unit_price, mt.unit_price, 0) as unit_price,
                                    COALESCE(mti.subtotal, mt.total_amount, 0) as amount,
                                    COALESCE(mt.payment_method, 'Cash') as payment_method,
                                    COALESCE(mt.ewallet_provider, '') as ewallet_provider,
                                    COALESCE(mt.ewallet_reference, '') as ewallet_reference
                                  FROM merchandise_transactions mt
                                  LEFT JOIN merchandise_transaction_items mti ON mt.id = mti.transaction_id AND (mti.item_type IS NULL OR mti.item_type = 'merchandise')
                                  WHERE DATE(mt.transaction_date) BETWEEN :date_from AND :date_to
                                    {$st_clause('mt')}
                                    {$m_where}
                                  ORDER BY mt.transaction_date DESC";
                    $stmt_m = $pdo->prepare($sql_merch);
                    $stmt_m->execute(array_merge($m_params, $st_params));
                    $data['merchandise'] = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
                }

                // 2. JOB ORDER SERVICE TRANSACTIONS TABLE
                $data['job_orders'] = [];
                if ($filter_ttype === '' || $filter_ttype === 'job_order') {
                    $j_where = " AND LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service') ";
                    $j_params = ['date_from' => $date_from, 'date_to' => $date_to];

                    if (!empty($filter_pm)) {
                        $norm_jf = function_exists('normalize_payment_type') ? normalize_payment_type($filter_pm) : ['type' => $filter_pm, 'provider' => ''];
                        if ($norm_jf['type'] === 'E-Wallet') {
                            if (!empty($norm_jf['provider'])) {
                                $j_where .= " AND (LOWER(COALESCE(mt.ewallet_provider,'')) = LOWER(:j_filter_prov) OR LOWER(COALESCE(mt.payment_method,'')) = LOWER(:j_filter_prov)) ";
                                $j_params['j_filter_prov'] = $norm_jf['provider'];
                            } else {
                                $j_where .= " AND (LOWER(COALESCE(mt.payment_method,'')) IN ('e-wallet', 'ewallet', 'gcash', 'maya') OR (mt.ewallet_provider IS NOT NULL AND mt.ewallet_provider != '')) ";
                            }
                        } else {
                            $j_where .= " AND LOWER(COALESCE(mt.payment_method,'')) = LOWER(:j_filter_pm) ";
                            $j_params['j_filter_pm'] = $norm_jf['type'];
                        }
                    }
                    if (!empty($filter_cust)) {
                        $j_where .= " AND (LOWER(mt.customer_name) LIKE LOWER(:j_filter_cust) OR LOWER(mt.customer_first_name) LIKE LOWER(:j_filter_cust) OR LOWER(mt.customer_last_name) LIKE LOWER(:j_filter_cust)) ";
                        $j_params['j_filter_cust'] = '%' . $filter_cust . '%';
                    }
                    if (!empty($filter_mech)) {
                        $j_where .= " AND LOWER(COALESCE(mt.job_order_mechanic_name,'')) LIKE LOWER(:j_filter_mech) ";
                        $j_params['j_filter_mech'] = '%' . $filter_mech . '%';
                    }
                    if (!empty($filter_status)) {
                        $j_where .= " AND LOWER(COALESCE(mt.workflow_status, mt.validation_status, '')) = LOWER(:j_filter_status) ";
                        $j_params['j_filter_status'] = $filter_status;
                    }

                    $sql_jo = "SELECT 
                                COALESCE(NULLIF(mt.job_order_id,''), mt.transaction_id) as jo_no,
                                DATE(mt.transaction_date) as date,
                                COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
                                CONCAT(COALESCE(mt.job_order_vehicle_plate,'N/A'), ' - ', COALESCE(mt.job_order_vehicle_type,'Vehicle')) as vehicle,
                                COALESCE(NULLIF(mt.job_order_mechanic_name,''), 'Unassigned') as mechanic,
                                COALESCE(NULLIF(mt.job_order_service,''), 'Vehicle Service') as service,
                                COALESCE(mt.subtotal_amount, 0) as labor_fee,
                                COALESCE(mt.vat_amount, 0) as service_fee,
                                GREATEST(COALESCE(mt.total_amount,0) - COALESCE(mt.subtotal_amount,0) - COALESCE(mt.vat_amount,0), 0) as parts_cost,
                                COALESCE(mt.total_amount, 0) as total_amount,
                                COALESCE(mt.payment_method, 'Cash') as payment_method,
                                COALESCE(mt.ewallet_provider, '') as ewallet_provider,
                                COALESCE(mt.ewallet_reference, '') as ewallet_reference,
                                COALESCE(mt.workflow_status, mt.validation_status, 'Completed') as status
                               FROM merchandise_transactions mt
                               WHERE DATE(mt.transaction_date) BETWEEN :date_from AND :date_to
                                 {$st_clause('mt')}
                                 {$j_where}
                               ORDER BY mt.transaction_date DESC";
                    $stmt_j = $pdo->prepare($sql_jo);
                    $stmt_j->execute(array_merge($j_params, $st_params));
                    $data['job_orders'] = $stmt_j->fetchAll(PDO::FETCH_ASSOC);
                }

                // Subtotals
                $merch_count  = count($data['merchandise']);
                $merch_amount = array_sum(array_column($data['merchandise'], 'amount'));

                $jo_count  = count($data['job_orders']);
                $jo_amount = array_sum(array_column($data['job_orders'], 'total_amount'));

                $data['merchandise_subtotal'] = $merch_amount;
                $data['job_orders_subtotal']  = $jo_amount;

                // 3. DAILY SALES SUMMARY
                $data['daily_summary'] = [
                    'merchandise' => ['count' => $merch_count, 'amount' => $merch_amount],
                    'job_order'   => ['count' => $jo_count,    'amount' => $jo_amount],
                    'overall'     => ['count' => $merch_count + $jo_count, 'amount' => $merch_amount + $jo_amount]
                ];

                // 4. PAYMENT METHOD SUMMARY
                $pm_map = [];
                foreach ($data['merchandise'] as $m) {
                    $pm_raw = $m['payment_method'] ?: 'Cash';
                    $prov = !empty($m['ewallet_provider']) ? trim($m['ewallet_provider']) : '';
                    $norm = function_exists('normalize_payment_type') ? normalize_payment_type($pm_raw, $prov) : ['type' => $pm_raw, 'provider' => $prov];
                    $pm_type = $norm['type'];
                    $prov_name = $norm['provider'];

                    if (!isset($pm_map[$pm_type])) {
                        $pm_map[$pm_type] = ['count' => 0, 'amount' => 0, 'providers' => []];
                    }
                    $pm_map[$pm_type]['count']++;
                    $pm_map[$pm_type]['amount'] += (float)$m['amount'];

                    if ($pm_type === 'E-Wallet' && !empty($prov_name)) {
                        if (!isset($pm_map[$pm_type]['providers'][$prov_name])) {
                            $pm_map[$pm_type]['providers'][$prov_name] = ['count' => 0, 'amount' => 0];
                        }
                        $pm_map[$pm_type]['providers'][$prov_name]['count']++;
                        $pm_map[$pm_type]['providers'][$prov_name]['amount'] += (float)$m['amount'];
                    }
                }
                foreach ($data['job_orders'] as $j) {
                    $pm_raw = $j['payment_method'] ?: 'Cash';
                    $prov = !empty($j['ewallet_provider']) ? trim($j['ewallet_provider']) : '';
                    $norm = function_exists('normalize_payment_type') ? normalize_payment_type($pm_raw, $prov) : ['type' => $pm_raw, 'provider' => $prov];
                    $pm_type = $norm['type'];
                    $prov_name = $norm['provider'];

                    if (!isset($pm_map[$pm_type])) {
                        $pm_map[$pm_type] = ['count' => 0, 'amount' => 0, 'providers' => []];
                    }
                    $pm_map[$pm_type]['count']++;
                    $pm_map[$pm_type]['amount'] += (float)$j['total_amount'];

                    if ($pm_type === 'E-Wallet' && !empty($prov_name)) {
                        if (!isset($pm_map[$pm_type]['providers'][$prov_name])) {
                            $pm_map[$pm_type]['providers'][$prov_name] = ['count' => 0, 'amount' => 0];
                        }
                        $pm_map[$pm_type]['providers'][$prov_name]['count']++;
                        $pm_map[$pm_type]['providers'][$prov_name]['amount'] += (float)$j['total_amount'];
                    }
                }
                $data['payment_summary'] = $pm_map;

                // 5. SALES BY CATEGORY (Merchandise)
                $cat_map = [];
                foreach ($data['merchandise'] as $m) {
                    $c = $m['category'] ?: 'General';
                    if (!isset($cat_map[$c])) $cat_map[$c] = ['count' => 0, 'amount' => 0];
                    $cat_map[$c]['count']++;
                    $cat_map[$c]['amount'] += (float)$m['amount'];
                }
                $data['category_summary'] = $cat_map;

                // 6. SERVICE REVENUE BREAKDOWN
                $total_labor       = array_sum(array_column($data['job_orders'], 'labor_fee'));
                $total_service_fee = array_sum(array_column($data['job_orders'], 'service_fee'));
                $total_parts       = array_sum(array_column($data['job_orders'], 'parts_cost'));
                $data['service_revenue'] = [
                    'labor_fee'   => $total_labor,
                    'service_fee' => $total_service_fee,
                    'parts'       => $total_parts,
                    'overall'     => $jo_amount
                ];

                // 7. TRANSACTION STATUS SUMMARY
                $status_counts = [
                    'Completed Job Orders' => 0,
                    'Released Vehicles'    => 0,
                    'Pending Job Orders'   => 0,
                    'Cancelled Job Orders' => 0
                ];
                foreach ($data['job_orders'] as $j) {
                    $st = strtolower($j['status']);
                    if (str_contains($st, 'completed')) $status_counts['Completed Job Orders']++;
                    elseif (str_contains($st, 'release')) $status_counts['Released Vehicles']++;
                    elseif (str_contains($st, 'cancel') || str_contains($st, 'reject')) $status_counts['Cancelled Job Orders']++;
                    else $status_counts['Pending Job Orders']++;
                }
                $data['status_summary'] = $status_counts;
            }
            break;

        // =========================================================================
        // 2. INVENTORY REPORTS
        // =========================================================================
        case 'inventory':

            // ---- 1. MERCHANDISE INVENTORY REPORT ----
            if ($tab === 'merch_inventory') {
                $fi_cat    = trim($filters['category']    ?? '');
                $fi_brand  = trim($filters['brand']       ?? '');
                $fi_status = trim($filters['status']      ?? '');
                $fi_batch  = trim($filters['batch_id']    ?? '');
                $fi_prod   = trim($filters['product']     ?? '');

                $where  = " WHERE p.station_id = :station_id ";
                $params = ['station_id' => $station_id];

                if (!empty($fi_cat))   { $where .= " AND LOWER(COALESCE(pc.name,'')) LIKE LOWER(:fi_cat) ";   $params['fi_cat']   = "%$fi_cat%"; }
                if (!empty($fi_brand)) { $where .= " AND LOWER(COALESCE(p.brand,'')) LIKE LOWER(:fi_brand) "; $params['fi_brand'] = "%$fi_brand%"; }
                if (!empty($fi_prod))  { $where .= " AND LOWER(p.name) LIKE LOWER(:fi_prod) ";                $params['fi_prod']  = "%$fi_prod%"; }
                if (!empty($fi_batch)) { $where .= " AND LOWER(COALESCE(mb.batch_number,'')) LIKE LOWER(:fi_batch) "; $params['fi_batch'] = "%$fi_batch%"; }
                if (!empty($fi_status)) {
                    if ($fi_status === 'Out of Stock')    $where .= " AND p.current_stock <= 0 ";
                    elseif ($fi_status === 'Critical Stock') $where .= " AND p.current_stock > 0 AND p.current_stock <= p.min_stock_level / 2 ";
                    elseif ($fi_status === 'Low Stock')   $where .= " AND p.current_stock > 0 AND p.current_stock <= p.min_stock_level ";
                    elseif ($fi_status === 'Expired')     $where .= " AND do.expiry_date IS NOT NULL AND do.expiry_date < CURDATE() ";
                    elseif ($fi_status === 'Available')   $where .= " AND p.current_stock > p.min_stock_level ";
                }

                $sql = "SELECT p.sku,
                               COALESCE(
                                   mb.batch_number,
                                   (SELECT msi.batch_ref FROM merchandise_stock_in msi
                                    WHERE msi.product_id = p.id AND msi.station_id = p.station_id
                                    AND msi.batch_ref LIKE 'BT-%'
                                    ORDER BY msi.encoded_at DESC LIMIT 1),
                                   CONCAT('BT-', DATE_FORMAT(p.updated_at, '%Y%m%d'), '-', LPAD(p.id, 4, '0'))
                               ) as batch_id,
                               p.name as product,
                               COALESCE(pc.name, 'General') as category,
                               COALESCE(p.unit, 'pcs') as uom,
                               COALESCE(mb.quantity_received, p.current_stock) as initial_stock,
                               p.current_stock,
                               COALESCE(p.min_stock_level, 0) as reorder_level,
                               COALESCE(do.expiry_date, NULL) as expiration_date,
                               p.updated_at as last_updated,
                               CASE
                                 WHEN p.current_stock <= 0 THEN 'Out of Stock'
                                 WHEN do.expiry_date IS NOT NULL AND do.expiry_date < CURDATE() THEN 'Expired'
                                 WHEN p.current_stock <= (p.min_stock_level / 2) THEN 'Critical Stock'
                                 WHEN p.current_stock <= p.min_stock_level THEN 'Low Stock'
                                 ELSE 'Available'
                               END as status
                        FROM products p
                        LEFT JOIN product_categories pc ON p.category_id = pc.id
                        LEFT JOIN merchandise_batches mb ON p.id = mb.product_id AND mb.status = 'active'
                        LEFT JOIN deliveries_oversight do ON mb.delivery_id = do.id
                        {$where}
                        ORDER BY p.name ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Filter dropdown options
                $data['categories'] = $pdo->prepare("SELECT DISTINCT pc.name FROM products p LEFT JOIN product_categories pc ON p.category_id = pc.id WHERE p.station_id = :sid AND pc.name IS NOT NULL ORDER BY pc.name ASC");
                $data['categories']->execute(['sid' => $station_id]);
                $data['categories'] = $data['categories']->fetchAll(PDO::FETCH_COLUMN);

                $data['brands'] = $pdo->prepare("SELECT DISTINCT brand FROM products WHERE station_id = :sid AND brand IS NOT NULL AND brand != '' ORDER BY brand ASC");
                $data['brands']->execute(['sid' => $station_id]);
                $data['brands'] = $data['brands']->fetchAll(PDO::FETCH_COLUMN);

            // ---- 2. FUEL INVENTORY REPORT ----
            } elseif ($tab === 'fuel_inventory') {
                $fi_fuel  = trim($filters['fuel_type'] ?? '');
                $fi_ugt   = trim($filters['ugt']       ?? '');

                $where  = " WHERE fi.station_id = :station_id AND fi.status = 'active'
                            AND fi.ugt_no IS NOT NULL AND TRIM(fi.ugt_no) != '' ";
                $params = ['station_id' => $station_id];

                if (!empty($fi_fuel)) { $where .= " AND LOWER(fi.fuel_type) LIKE LOWER(:fi_fuel) "; $params['fi_fuel'] = "%$fi_fuel%"; }
                if (!empty($fi_ugt))  { $where .= " AND LOWER(fi.ugt_no) LIKE LOWER(:fi_ugt) ";    $params['fi_ugt']  = "%$fi_ugt%"; }

                $sql = "SELECT fi.ugt_no as ugt,
                               fi.fuel_type,
                               COALESCE(fi.current_stock, fi.current_level, 0) as current_volume,
                               COALESCE(fi.capacity, 0) as tank_capacity,
                               COALESCE(fi.reorder_level, 0) as reorder_level,
                               COALESCE(fi.critical_level, 0) as critical_level,
                               CASE
                                 WHEN fi.capacity > 0
                                 THEN ROUND((COALESCE(fi.current_stock, fi.current_level, 0) / fi.capacity) * 100, 1)
                                 ELSE 0
                               END as available_pct,
                               fi.last_updated,
                               CASE
                                 WHEN COALESCE(fi.current_stock, fi.current_level, 0) <= fi.critical_level THEN 'Critical Fuel'
                                 WHEN COALESCE(fi.current_stock, fi.current_level, 0) <= fi.reorder_level  THEN 'Low Fuel'
                                 ELSE 'Available'
                               END as status
                        FROM fuel_inventory fi
                        {$where}
                        ORDER BY fi.ugt_no ASC, fi.fuel_type ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $data['fuel_types'] = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_inventory WHERE station_id = :sid AND status = 'active' ORDER BY fuel_type ASC");
                $data['fuel_types']->execute(['sid' => $station_id]);
                $data['fuel_types'] = $data['fuel_types']->fetchAll(PDO::FETCH_COLUMN);

                $data['ugts'] = $pdo->prepare("SELECT DISTINCT ugt_no FROM fuel_inventory WHERE station_id = :sid AND status = 'active' AND ugt_no IS NOT NULL AND ugt_no != '' ORDER BY ugt_no ASC");
                $data['ugts']->execute(['sid' => $station_id]);
                $data['ugts'] = $data['ugts']->fetchAll(PDO::FETCH_COLUMN);

            // ---- 3. INVENTORY MOVEMENT REPORT ----
            } elseif ($tab === 'inventory_movement') {
                $fi_ttype = trim($filters['transaction_type'] ?? '');
                $fi_prod  = trim($filters['product']          ?? '');
                $fi_batch = trim($filters['batch_id']         ?? '');
                $fi_user  = trim($filters['user']             ?? '');

                // Map friendly type → DB action
                $type_map = [
                    'Stock In'                => 'stock_in',
                    'Sales'                   => 'sale',
                    'Return'                  => 'return',
                    'Expired'                 => 'expired',
                    'Damaged'                 => 'damaged',
                    'Physical Count Adjustment' => 'physical_count',
                    'Manual Adjustment'       => 'adjustment',
                ];

                $where  = " WHERE DATE(il.created_at) BETWEEN :date_from AND :date_to AND il.station_id = :station_id ";
                $params = ['date_from' => $date_from, 'date_to' => $date_to, 'station_id' => $station_id];

                if (!empty($fi_ttype)) {
                    $db_action = $type_map[$fi_ttype] ?? $fi_ttype;
                    $where .= " AND LOWER(il.action) = LOWER(:fi_ttype) ";
                    $params['fi_ttype'] = $db_action;
                }
                if (!empty($fi_prod))  { $where .= " AND LOWER(COALESCE(p.name,'')) LIKE LOWER(:fi_prod) "; $params['fi_prod'] = "%$fi_prod%"; }
                if (!empty($fi_batch)) {
                    $where .= " AND (LOWER(COALESCE(mb.batch_number,'')) LIKE LOWER(:fi_batch) OR LOWER(COALESCE(il.reference_type,'')) LIKE LOWER(:fi_batch2)) ";
                    $params['fi_batch'] = "%$fi_batch%";
                    $params['fi_batch2'] = "%$fi_batch%";
                }
                if (!empty($fi_user))  { $where .= " AND (LOWER(COALESCE(u.name,'')) LIKE LOWER(:fi_user) OR LOWER(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) LIKE LOWER(:fi_user2)) "; $params['fi_user'] = "%$fi_user%"; $params['fi_user2'] = "%$fi_user%"; }

                $sql = "SELECT DATE(il.created_at) as date,
                               COALESCE(mb.batch_number, il.reference_type, 'N/A') as batch_id,
                               COALESCE(p.name, CONCAT('Product #', il.product_id)) as product,
                               CASE il.action
                                 WHEN 'stock_in'      THEN 'Stock In'
                                 WHEN 'sale'          THEN 'Sales'
                                 WHEN 'return'        THEN 'Return'
                                 WHEN 'expired'       THEN 'Expired'
                                 WHEN 'damaged'       THEN 'Damaged'
                                 WHEN 'physical_count' THEN 'Physical Count Adjustment'
                                 WHEN 'adjustment'    THEN 'Manual Adjustment'
                                 ELSE il.action
                               END as transaction_type,
                               il.quantity_change as qty,
                               il.quantity_after as balance_after,
                               COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')),''), 'System') as performed_by
                        FROM inventory_logs il
                        LEFT JOIN products p ON il.product_id = p.id
                        LEFT JOIN merchandise_batches mb ON il.product_id = mb.product_id AND mb.station_id = il.station_id AND mb.status = 'active'
                        LEFT JOIN users u ON il.user_id = u.id
                        {$where}
                        ORDER BY il.created_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ---- 4. INVENTORY ADJUSTMENT REPORT ----
            } elseif ($tab === 'inventory_adjustment') {
                $fi_status = trim($filters['status']    ?? '');
                $fi_atype  = trim($filters['adj_type']  ?? '');
                $fi_batch  = trim($filters['batch_id']  ?? '');
                $fi_prod   = trim($filters['product']   ?? '');

                $all_adj_rows = [];

                // 1. Merchandise Adjustments
                if (ard_table_exists($pdo, 'merchandise_adjustments')) {
                    $where_m  = " WHERE DATE(COALESCE(ma.requested_at, ma.created_at)) BETWEEN :date_from_m AND :date_to_m AND ma.station_id = :station_id_m ";
                    $params_m = ['date_from_m' => $date_from, 'date_to_m' => $date_to, 'station_id_m' => $station_id];

                    if (!empty($fi_status)) { $where_m .= " AND LOWER(ma.status) = LOWER(:fi_status_m) ";           $params_m['fi_status_m'] = $fi_status; }
                    if (!empty($fi_atype))  { $where_m .= " AND LOWER(ma.adjustment_type) LIKE LOWER(:fi_atype_m) "; $params_m['fi_atype_m']  = "%$fi_atype%"; }
                    if (!empty($fi_batch))  { $where_m .= " AND LOWER(COALESCE(ma.sku,'')) LIKE LOWER(:fi_batch_m) "; $params_m['fi_batch_m'] = "%$fi_batch%"; }
                    if (!empty($fi_prod))   { $where_m .= " AND LOWER(ma.product_name) LIKE LOWER(:fi_prod_m) ";      $params_m['fi_prod_m']  = "%$fi_prod%"; }

                    $sql_m = "SELECT CONCAT('ADJ-', LPAD(ma.id, 5, '0')) as request_no,
                                     COALESCE(ma.sku, 'N/A') as batch_id,
                                     ma.product_name as product,
                                     ma.adjustment_type,
                                     ma.quantity_change as qty,
                                     COALESCE(NULLIF(u1.name,''), NULLIF(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,'')),''), 'Staff') as requested_by,
                                     COALESCE(NULLIF(u2.name,''), NULLIF(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,'')),''), '-') as approved_by,
                                     ma.status,
                                     ma.approved_at as approval_date,
                                     COALESCE(ma.requested_at, ma.created_at) as sort_date
                              FROM merchandise_adjustments ma
                              LEFT JOIN users u1 ON ma.requested_by = u1.id
                              LEFT JOIN users u2 ON ma.approved_by  = u2.id
                              {$where_m}";
                    try {
                        $stmt_m = $pdo->prepare($sql_m);
                        $stmt_m->execute($params_m);
                        $m_rows = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
                        if ($m_rows) {
                            $all_adj_rows = array_merge($all_adj_rows, $m_rows);
                        }
                    } catch (Exception $e) {}
                }

                // 2. Fuel Adjustments (Physical count / tank dips, transaction adjustments, stock-in adjustments, calibration)
                if (ard_table_exists($pdo, 'fuel_adjustments')) {
                    $where_f  = " WHERE DATE(COALESCE(fa.adjustment_date, fa.created_at)) BETWEEN :date_from_f AND :date_to_f AND fa.station_id = :station_id_f ";
                    $params_f = ['date_from_f' => $date_from, 'date_to_f' => $date_to, 'station_id_f' => $station_id];

                    if (!empty($fi_status)) { $where_f .= " AND LOWER(fa.status) = LOWER(:fi_status_f) ";           $params_f['fi_status_f'] = $fi_status; }
                    if (!empty($fi_atype))  { 
                        $where_f .= " AND (LOWER(fa.adjustment_type) LIKE LOWER(:fi_atype_f1) OR LOWER(REPLACE(fa.adjustment_type, '_', ' ')) LIKE LOWER(:fi_atype_f2)) "; 
                        $params_f['fi_atype_f1']  = "%$fi_atype%"; 
                        $params_f['fi_atype_f2']  = "%$fi_atype%"; 
                    }
                    if (!empty($fi_batch))  { 
                        $where_f .= " AND (LOWER(COALESCE(fa.ugt_no,'')) LIKE LOWER(:fi_batch_f1) OR LOWER(COALESCE(fa.notes,'')) LIKE LOWER(:fi_batch_f2)) "; 
                        $params_f['fi_batch_f1'] = "%$fi_batch%"; 
                        $params_f['fi_batch_f2'] = "%$fi_batch%"; 
                    }
                    if (!empty($fi_prod))   { $where_f .= " AND LOWER(fa.fuel_type) LIKE LOWER(:fi_prod_f) ";         $params_f['fi_prod_f']  = "%$fi_prod%"; }

                    $sql_f = "SELECT CONCAT('FADJ-', LPAD(fa.id, 5, '0')) as request_no,
                                     COALESCE(NULLIF(fa.ugt_no,''), 'Fuel Tank') as batch_id,
                                     fa.fuel_type as product,
                                     CASE 
                                         WHEN LOWER(fa.adjustment_type) = 'transaction_adjustment' THEN 'Transaction Adjustment'
                                         WHEN LOWER(fa.adjustment_type) = 'stock_in' THEN 'Stock-In Adjustment'
                                         WHEN LOWER(fa.adjustment_type) LIKE '%tank dip%' OR LOWER(fa.adjustment_type) LIKE '%physical count%' THEN 'Physical Count / Tank Dip'
                                         ELSE COALESCE(NULLIF(fa.adjustment_type,''), 'Fuel Adjustment')
                                     END as adjustment_type,
                                     fa.liters as qty,
                                     COALESCE(NULLIF(u1.name,''), NULLIF(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,'')),''), 'Staff') as requested_by,
                                     COALESCE(NULLIF(u2.name,''), NULLIF(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,'')),''), '-') as approved_by,
                                     fa.status,
                                     COALESCE(fa.approved_at, fa.adjustment_date) as approval_date,
                                     COALESCE(fa.adjustment_date, fa.created_at) as sort_date
                              FROM fuel_adjustments fa
                              LEFT JOIN users u1 ON fa.user_id = u1.id
                              LEFT JOIN users u2 ON fa.approved_by = u2.id
                              {$where_f}";
                    try {
                        $stmt_f = $pdo->prepare($sql_f);
                        $stmt_f->execute($params_f);
                        $f_rows = $stmt_f->fetchAll(PDO::FETCH_ASSOC);
                        if ($f_rows) {
                            $all_adj_rows = array_merge($all_adj_rows, $f_rows);
                        }
                    } catch (Exception $e) {}
                }

                // 3. Merchandise Item Adjustments logged in Transaction Adjustments (POS transactions)
                if (ard_table_exists($pdo, 'transaction_adjustments')) {
                    $where_ta = " WHERE DATE(ta.adjustment_date) BETWEEN :date_from_ta AND :date_to_ta AND ta.station_id = :station_id_ta ";
                    $params_ta = ['date_from_ta' => $date_from, 'date_to_ta' => $date_to, 'station_id_ta' => $station_id];

                    if (!empty($fi_batch)) { $where_ta .= " AND LOWER(ta.transaction_id) LIKE LOWER(:fi_batch_ta) "; $params_ta['fi_batch_ta'] = "%$fi_batch%"; }

                    $sql_ta = "SELECT ta.id, ta.transaction_id, ta.adjustment_reason, ta.adjustment_date, ta.fields_changed,
                                      COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')),''), 'Manager') as approved_by
                               FROM transaction_adjustments ta
                               LEFT JOIN users u ON ta.adjusted_by = u.id
                               {$where_ta}";
                    try {
                        $stmt_ta = $pdo->prepare($sql_ta);
                        $stmt_ta->execute($params_ta);
                        $ta_rows = $stmt_ta->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($ta_rows as $tar) {
                            $fc = json_decode($tar['fields_changed'] ?? '{}', true);
                            if (!empty($fc['adjusted_items']) && is_array($fc['adjusted_items'])) {
                                foreach ($fc['adjusted_items'] as $item) {
                                    $item_type = $item['item_type'] ?? '';
                                    if ($item_type === 'merchandise' || $item_type === 'item') {
                                        $pname = $item['product_name'] ?? 'Merchandise';
                                        $old_q = (float)($item['old_qty'] ?? 0);
                                        $new_q = (float)($item['new_qty'] ?? 0);
                                        $diff_q = $new_q - $old_q;

                                        if (!empty($fi_prod) && stripos($pname, $fi_prod) === false) continue;
                                        if (!empty($fi_atype) && stripos('Transaction Adjustment', $fi_atype) === false) continue;
                                        if (!empty($fi_status) && strtolower($fi_status) !== 'approved') continue;

                                        $all_adj_rows[] = [
                                            'request_no'      => 'TADJ-' . str_pad($tar['id'], 5, '0', STR_PAD_LEFT),
                                            'batch_id'        => $tar['transaction_id'],
                                            'product'         => $pname,
                                            'adjustment_type' => 'Transaction Adjustment',
                                            'qty'             => $diff_q,
                                            'requested_by'    => 'POS Staff',
                                            'approved_by'     => $tar['approved_by'],
                                            'status'          => 'Approved',
                                            'approval_date'   => $tar['adjustment_date'],
                                            'sort_date'       => $tar['adjustment_date']
                                        ];
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {}
                }

                // Sort all rows by sort_date DESC
                usort($all_adj_rows, function($a, $b) {
                    return strcmp($b['sort_date'] ?? '', $a['sort_date'] ?? '');
                });

                $data['rows'] = $all_adj_rows;

            // ---- 5. EXPIRED & DAMAGED REPORT ----
            } else { // expired_damaged
                $fi_batch = trim($filters['batch_id']   ?? '');
                $fi_prod  = trim($filters['product']    ?? '');
                $fi_atype = trim($filters['adj_type']   ?? '');

                $all_exp_dmg_rows = [];

                // 1. Merchandise Adjustments with type expired or damaged
                if (ard_table_exists($pdo, 'merchandise_adjustments')) {
                    $where_m  = " WHERE DATE(COALESCE(ma.approved_at, ma.requested_at, ma.created_at)) BETWEEN :date_from_m AND :date_to_m AND ma.station_id = :station_id_m
                                  AND (LOWER(ma.adjustment_type) LIKE '%expire%' OR LOWER(ma.adjustment_type) LIKE '%damage%' OR LOWER(ma.adjustment_type) LIKE '%spoil%' OR LOWER(ma.adjustment_type) LIKE '%waste%' OR LOWER(COALESCE(ma.reason,'')) LIKE '%damage%' OR LOWER(COALESCE(ma.reason,'')) LIKE '%expire%') ";
                    $params_m = ['date_from_m' => $date_from, 'date_to_m' => $date_to, 'station_id_m' => $station_id];

                    if (!empty($fi_batch)) { $where_m .= " AND (LOWER(COALESCE(ma.sku,'')) LIKE LOWER(:fi_batch_m) OR LOWER(CONCAT('ADJ-', LPAD(ma.id, 5, '0'))) LIKE LOWER(:fi_batch_m2)) "; $params_m['fi_batch_m'] = "%$fi_batch%"; $params_m['fi_batch_m2'] = "%$fi_batch%"; }
                    if (!empty($fi_prod))  { $where_m .= " AND LOWER(ma.product_name) LIKE LOWER(:fi_prod_m) "; $params_m['fi_prod_m'] = "%$fi_prod%"; }

                    $sql_m = "SELECT COALESCE(NULLIF(ma.sku,''), CONCAT('ADJ-', LPAD(ma.id, 5, '0'))) as batch_id,
                                     ma.product_name as product,
                                     (SELECT do.expiry_date FROM deliveries_oversight do WHERE do.product = ma.product_name AND do.station_id = ma.station_id AND do.expiry_date IS NOT NULL LIMIT 1) as expiration_date,
                                     CASE WHEN LOWER(ma.adjustment_type) LIKE '%expire%' OR LOWER(COALESCE(ma.reason,'')) LIKE '%expire%' THEN ABS(ma.quantity_change) ELSE 0 END as expired_qty,
                                     CASE WHEN LOWER(ma.adjustment_type) LIKE '%damage%' OR LOWER(COALESCE(ma.reason,'')) LIKE '%damage%' THEN ABS(ma.quantity_change) ELSE 0 END as damaged_qty,
                                     ABS(ma.quantity_change) as total_deduction,
                                     COALESCE(ma.approved_at, ma.requested_at, ma.created_at) as approval_date,
                                     COALESCE(NULLIF(u2.name,''), NULLIF(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,'')),''), u2.username, 'Manager / Admin') as approved_by,
                                     COALESCE(ma.requested_at, ma.created_at) as sort_date
                              FROM merchandise_adjustments ma
                              LEFT JOIN users u2 ON ma.approved_by = u2.id
                              {$where_m}";
                    try {
                        $stmt_m = $pdo->prepare($sql_m);
                        $stmt_m->execute($params_m);
                        $m_rows = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
                        if ($m_rows) {
                            $all_exp_dmg_rows = array_merge($all_exp_dmg_rows, $m_rows);
                        }
                    } catch (Exception $e) {}
                }

                // 2. Deliveries Oversight (damaged deliveries or rejected / returned deliveries)
                if (ard_table_exists($pdo, 'deliveries_oversight')) {
                    $where_do = " WHERE do.station_id = :station_id_do
                                    AND (do.damaged_quantity > 0 OR LOWER(COALESCE(do.status,'')) LIKE '%damage%' OR LOWER(COALESCE(do.status,'')) LIKE '%reject%' OR LOWER(COALESCE(do.remarks,'')) LIKE '%damage%' OR LOWER(COALESCE(do.remarks,'')) LIKE '%expire%')
                                    AND DATE(COALESCE(do.delivery_date, do.admin_action_at, do.manager_action_at, do.created_at)) BETWEEN :date_from_do AND :date_to_do ";
                    $params_do = ['station_id_do' => $station_id, 'date_from_do' => $date_from, 'date_to_do' => $date_to];

                    if (!empty($fi_batch)) { 
                        $where_do .= " AND (LOWER(COALESCE(do.batch_id,'')) LIKE LOWER(:fi_batch_do1) OR LOWER(COALESCE(do.delivery_ref,'')) LIKE LOWER(:fi_batch_do2) OR LOWER(COALESCE(do.dr_number,'')) LIKE LOWER(:fi_batch_do3)) "; 
                        $params_do['fi_batch_do1'] = "%$fi_batch%"; 
                        $params_do['fi_batch_do2'] = "%$fi_batch%"; 
                        $params_do['fi_batch_do3'] = "%$fi_batch%"; 
                    }
                    if (!empty($fi_prod))  { $where_do .= " AND LOWER(do.product) LIKE LOWER(:fi_prod_do) "; $params_do['fi_prod_do'] = "%$fi_prod%"; }

                    $sql_do = "SELECT COALESCE(NULLIF(do.dr_number,''), NULLIF(do.batch_id,''), NULLIF(do.delivery_ref,''), CONCAT('DMG-', LPAD(do.id, 5, '0'))) as batch_id,
                                      do.product as product,
                                      do.expiry_date as expiration_date,
                                      CASE WHEN LOWER(COALESCE(do.status,'')) LIKE '%expire%' OR LOWER(COALESCE(do.remarks,'')) LIKE '%expire%' THEN COALESCE(NULLIF(do.damaged_quantity,0), do.expected_quantity - do.actual_quantity, 0) ELSE 0 END as expired_qty,
                                      CASE WHEN LOWER(COALESCE(do.status,'')) LIKE '%expire%' OR LOWER(COALESCE(do.remarks,'')) LIKE '%expire%' THEN 0 ELSE COALESCE(NULLIF(do.damaged_quantity,0), GREATEST(do.expected_quantity - do.actual_quantity, 0), 0) END as damaged_qty,
                                      GREATEST(COALESCE(do.damaged_quantity, 0), GREATEST(do.expected_quantity - do.actual_quantity, 0)) as total_deduction,
                                      COALESCE(do.admin_action_at, do.manager_action_at, do.delivery_date, do.created_at) as approval_date,
                                      COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')),''), u.username, 'Admin') as approved_by,
                                      COALESCE(do.admin_action_at, do.manager_action_at, do.created_at) as sort_date
                               FROM deliveries_oversight do
                               LEFT JOIN users u ON (do.admin_id = u.id OR do.manager_id = u.id)
                               {$where_do}";
                    try {
                        $stmt_do = $pdo->prepare($sql_do);
                        $stmt_do->execute($params_do);
                        $do_rows = $stmt_do->fetchAll(PDO::FETCH_ASSOC);
                        if ($do_rows) {
                            $all_exp_dmg_rows = array_merge($all_exp_dmg_rows, $do_rows);
                        }
                    } catch (Exception $e) {}
                }

                // 3. Merchandise Stock-In with condition Damaged or negative variance
                if (ard_table_exists($pdo, 'merchandise_stock_in')) {
                    $where_msi = " WHERE msi.station_id = :station_id_msi
                                   AND (LOWER(msi.condition_flag) IN ('damaged','bad','defective','short') OR LOWER(COALESCE(msi.remarks,'')) LIKE '%damage%' OR LOWER(COALESCE(msi.remarks,'')) LIKE '%expire%' OR msi.qty_variance < 0)
                                   AND DATE(msi.encoded_at) BETWEEN :date_from_msi AND :date_to_msi ";
                    $params_msi = ['station_id_msi' => $station_id, 'date_from_msi' => $date_from, 'date_to_msi' => $date_to];
                    if (!empty($fi_batch)) { $where_msi .= " AND (LOWER(COALESCE(msi.batch_ref,'')) LIKE LOWER(:fi_batch_msi1) OR LOWER(COALESCE(msi.po_number,'')) LIKE LOWER(:fi_batch_msi2)) "; $params_msi['fi_batch_msi1'] = "%$fi_batch%"; $params_msi['fi_batch_msi2'] = "%$fi_batch%"; }
                    if (!empty($fi_prod))  { $where_msi .= " AND LOWER(msi.product_name) LIKE LOWER(:fi_prod_msi) "; $params_msi['fi_prod_msi'] = "%$fi_prod%"; }

                    $sql_msi = "SELECT COALESCE(NULLIF(msi.batch_ref,''), NULLIF(msi.po_number,''), CONCAT('STK-', LPAD(msi.id, 5, '0'))) as batch_id,
                                       msi.product_name as product,
                                       NULL as expiration_date,
                                       CASE WHEN LOWER(COALESCE(msi.remarks,'')) LIKE '%expire%' THEN ABS(COALESCE(msi.qty_variance, 0)) ELSE 0 END as expired_qty,
                                       CASE WHEN LOWER(COALESCE(msi.remarks,'')) LIKE '%expire%' THEN 0 ELSE (CASE WHEN msi.qty_variance < 0 THEN ABS(msi.qty_variance) ELSE msi.qty_received END) END as damaged_qty,
                                       CASE WHEN msi.qty_variance < 0 THEN ABS(msi.qty_variance) ELSE msi.qty_received END as total_deduction,
                                       msi.encoded_at as approval_date,
                                       COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')),''), u.username, 'Admin / Manager') as approved_by,
                                       msi.encoded_at as sort_date
                                FROM merchandise_stock_in msi
                                LEFT JOIN users u ON msi.encoded_by = u.id
                                {$where_msi}";
                    try {
                        $stmt_msi = $pdo->prepare($sql_msi);
                        $stmt_msi->execute($params_msi);
                        $msi_rows = $stmt_msi->fetchAll(PDO::FETCH_ASSOC);
                        if ($msi_rows) {
                            $all_exp_dmg_rows = array_merge($all_exp_dmg_rows, $msi_rows);
                        }
                    } catch (Exception $e) {}
                }

                // 4. Fuel Adjustments with spillage, loss, leakage, or damage
                if (ard_table_exists($pdo, 'fuel_adjustments')) {
                    $where_fa = " WHERE fa.station_id = :station_id_fa
                                    AND (LOWER(fa.adjustment_type) IN ('spillage', 'theft/loss', 'loss', 'damage', 'waste', 'discrepancy')
                                         OR LOWER(COALESCE(fa.reason,'')) LIKE '%spill%' OR LOWER(COALESCE(fa.reason,'')) LIKE '%leak%' OR LOWER(COALESCE(fa.reason,'')) LIKE '%damage%' OR LOWER(COALESCE(fa.reason,'')) LIKE '%loss%'
                                         OR LOWER(COALESCE(fa.notes,'')) LIKE '%spill%' OR LOWER(COALESCE(fa.notes,'')) LIKE '%loss%')
                                    AND DATE(COALESCE(fa.adjustment_date, fa.created_at)) BETWEEN :date_from_fa AND :date_to_fa ";
                    $params_fa = ['station_id_fa' => $station_id, 'date_from_fa' => $date_from, 'date_to_fa' => $date_to];

                    if (!empty($fi_batch)) { 
                        $where_fa .= " AND (LOWER(COALESCE(fa.ugt_no,'')) LIKE LOWER(:fi_batch_fa1) OR LOWER(COALESCE(fa.notes,'')) LIKE LOWER(:fi_batch_fa2)) "; 
                        $params_fa['fi_batch_fa1'] = "%$fi_batch%"; 
                        $params_fa['fi_batch_fa2'] = "%$fi_batch%"; 
                    }
                    if (!empty($fi_prod))  { $where_fa .= " AND LOWER(fa.fuel_type) LIKE LOWER(:fi_prod_fa) "; $params_fa['fi_prod_fa'] = "%$fi_prod%"; }

                    $sql_fa = "SELECT COALESCE(NULLIF(fa.ugt_no,''), CONCAT('FADJ-', LPAD(fa.id, 5, '0'))) as batch_id,
                                      fa.fuel_type as product,
                                      NULL as expiration_date,
                                      0 as expired_qty,
                                      ABS(fa.liters) as damaged_qty,
                                      ABS(fa.liters) as total_deduction,
                                      COALESCE(fa.approved_at, fa.adjustment_date) as approval_date,
                                      COALESCE(NULLIF(u2.name,''), NULLIF(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,'')),''), u2.username, 'Manager / Admin') as approved_by,
                                      COALESCE(fa.adjustment_date, fa.created_at) as sort_date
                               FROM fuel_adjustments fa
                               LEFT JOIN users u2 ON fa.approved_by = u2.id
                               {$where_fa}";
                    try {
                        $stmt_fa = $pdo->prepare($sql_fa);
                        $stmt_fa->execute($params_fa);
                        $fa_rows = $stmt_fa->fetchAll(PDO::FETCH_ASSOC);
                        if ($fa_rows) {
                            $all_exp_dmg_rows = array_merge($all_exp_dmg_rows, $fa_rows);
                        }
                    } catch (Exception $e) {}
                }

                // 5. Inventory Logs with expired or damaged action (using actual schema: quantity_change, reference_no, products.name)
                if (ard_table_exists($pdo, 'inventory_logs')) {
                    $where_il = " WHERE il.station_id = :station_id_il
                                    AND (LOWER(il.action) IN ('damage', 'damaged', 'defective', 'disposal', 'waste', 'spoilage', 'expired', 'loss', 'spill')
                                         OR LOWER(COALESCE(il.reason,'')) LIKE '%damage%' OR LOWER(COALESCE(il.reason,'')) LIKE '%expire%' OR LOWER(COALESCE(il.reason,'')) LIKE '%spoil%'
                                         OR LOWER(COALESCE(il.notes,'')) LIKE '%damage%' OR LOWER(COALESCE(il.notes,'')) LIKE '%expire%')
                                    AND DATE(il.created_at) BETWEEN :date_from_il AND :date_to_il ";
                    $params_il = ['station_id_il' => $station_id, 'date_from_il' => $date_from, 'date_to_il' => $date_to];

                    if (!empty($fi_batch)) { $where_il .= " AND LOWER(COALESCE(il.reference_no,'')) LIKE LOWER(:fi_batch_il) "; $params_il['fi_batch_il'] = "%$fi_batch%"; }
                    if (!empty($fi_prod))  { $where_il .= " AND LOWER(COALESCE(p.name,'')) LIKE LOWER(:fi_prod_il) "; $params_il['fi_prod_il'] = "%$fi_prod%"; }

                    $sql_il = "SELECT COALESCE(NULLIF(il.reference_no,''), CONCAT('ILOG-', LPAD(il.id, 5, '0'))) as batch_id,
                                      COALESCE(p.name, CONCAT('Product #', il.product_id)) as product,
                                      p.expiration_date as expiration_date,
                                      CASE WHEN LOWER(il.action) LIKE '%expire%' OR LOWER(COALESCE(il.reason,'')) LIKE '%expire%' OR LOWER(COALESCE(il.notes,'')) LIKE '%expire%' THEN ABS(il.quantity_change) ELSE 0 END as expired_qty,
                                      CASE WHEN LOWER(il.action) LIKE '%expire%' OR LOWER(COALESCE(il.reason,'')) LIKE '%expire%' OR LOWER(COALESCE(il.notes,'')) LIKE '%expire%' THEN 0 ELSE ABS(il.quantity_change) END as damaged_qty,
                                      ABS(il.quantity_change) as total_deduction,
                                      il.created_at as approval_date,
                                      COALESCE(NULLIF(u.name,''), NULLIF(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')),''), u.username, 'Staff / Manager') as approved_by,
                                      il.created_at as sort_date
                               FROM inventory_logs il
                               LEFT JOIN products p ON il.product_id = p.id
                               LEFT JOIN users u ON il.user_id = u.id
                               {$where_il}";
                    try {
                        $stmt_il = $pdo->prepare($sql_il);
                        $stmt_il->execute($params_il);
                        $il_rows = $stmt_il->fetchAll(PDO::FETCH_ASSOC);
                        if ($il_rows) {
                            $all_exp_dmg_rows = array_merge($all_exp_dmg_rows, $il_rows);
                        }
                    } catch (Exception $e) {}
                }

                // 6. Products / Inventory Products with past expiration date
                if (ard_table_exists($pdo, 'inventory_products')) {
                    try {
                        $where_ip = " WHERE ip.station_id = :station_id_ip AND ip.expiration_date IS NOT NULL AND DATE(ip.expiration_date) BETWEEN :date_from_ip AND :date_to_ip ";
                        $params_ip = ['station_id_ip' => $station_id, 'date_from_ip' => $date_from, 'date_to_ip' => $date_to];
                        if (!empty($fi_batch)) { $where_ip .= " AND LOWER(COALESCE(ip.sku,'')) LIKE LOWER(:fi_batch_ip) "; $params_ip['fi_batch_ip'] = "%$fi_batch%"; }
                        if (!empty($fi_prod))  { $where_ip .= " AND LOWER(ip.product_name) LIKE LOWER(:fi_prod_ip) "; $params_ip['fi_prod_ip'] = "%$fi_prod%"; }

                        $sql_ip = "SELECT COALESCE(NULLIF(ip.sku,''), CONCAT('PRD-', LPAD(ip.id, 5, '0'))) as batch_id,
                                          ip.product_name as product,
                                          ip.expiration_date as expiration_date,
                                          COALESCE(ip.stock_quantity, 0) as expired_qty,
                                          0 as damaged_qty,
                                          COALESCE(ip.stock_quantity, 0) as total_deduction,
                                          ip.expiration_date as approval_date,
                                          'System Audit' as approved_by,
                                          ip.expiration_date as sort_date
                                   FROM inventory_products ip
                                   {$where_ip}";
                        $stmt_ip = $pdo->prepare($sql_ip);
                        $stmt_ip->execute($params_ip);
                        $ip_rows = $stmt_ip->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        if ($ip_rows) {
                            $all_exp_dmg_rows = array_merge($all_exp_dmg_rows, $ip_rows);
                        }
                    } catch (Exception $e) {}
                }

                // Apply filter by Type if specified
                if (!empty($fi_atype)) {
                    $all_exp_dmg_rows = array_values(array_filter($all_exp_dmg_rows, function($r) use ($fi_atype) {
                        $fa = strtolower($fi_atype);
                        if ($fa === 'expired') {
                            return (float)($r['expired_qty'] ?? 0) > 0;
                        } elseif ($fa === 'damaged') {
                            return (float)($r['damaged_qty'] ?? 0) > 0;
                        } elseif ($fa === 'spillage') {
                            return stripos($r['batch_id'] ?? '', 'FADJ') !== false 
                                || stripos($r['product'] ?? '', 'diesel') !== false 
                                || stripos($r['product'] ?? '', 'unl') !== false
                                || stripos($r['product'] ?? '', 'xcs') !== false
                                || stripos($r['product'] ?? '', 'kerosene') !== false;
                        }
                        return true;
                    }));
                }

                // Sort all rows by sort_date DESC
                usort($all_exp_dmg_rows, function($a, $b) {
                    return strcmp($b['sort_date'] ?? '', $a['sort_date'] ?? '');
                });

                // Deduplicate rows
                $unique_exp = [];
                $seen_exp = [];
                foreach ($all_exp_dmg_rows as $r) {
                    $k = ($r['batch_id'] ?? '') . '|' . ($r['product'] ?? '') . '|' . substr($r['sort_date'] ?? '', 0, 16);
                    if (!isset($seen_exp[$k])) {
                        $seen_exp[$k] = true;
                        $unique_exp[] = $r;
                    }
                }

                $data['rows'] = $unique_exp;

                $tot_exp_qty = 0;
                $tot_dmg_qty = 0;
                $tot_deduction = 0;
                foreach ($unique_exp as $r) {
                    $tot_exp_qty += (float)($r['expired_qty'] ?? 0);
                    $tot_dmg_qty += (float)($r['damaged_qty'] ?? 0);
                    $tot_deduction += (float)($r['total_deduction'] ?? 0);
                }
                $data['summary'] = [
                    'total_records'   => count($unique_exp),
                    'total_expired'   => $tot_exp_qty,
                    'total_damaged'   => $tot_dmg_qty,
                    'total_deduction' => $tot_deduction
                ];
            }
            break;

        // =========================================================================
        // 3. OPERATIONS REPORTS
        // =========================================================================
        case 'operations':
            $data['mechanics'] = $pdo->prepare("SELECT id, full_name, full_name as name FROM mechanics WHERE station_id = :sid AND (archived = 0 OR archived IS NULL) ORDER BY full_name ASC");
            $data['mechanics']->execute(['sid' => $station_id]);
            $data['mechanics'] = $data['mechanics']->fetchAll(PDO::FETCH_ASSOC);

            $data['service_categories'] = $pdo->query("SELECT id, name FROM service_categories WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

            // ---- 1. JOB ORDER REPORT ----
            if ($tab === 'job_order') {
                $fi_status = trim($filters['status']         ?? '');
                $fi_mech   = trim($filters['mechanic']       ?? '');
                $fi_scat   = trim($filters['service_cat']    ?? '');
                $fi_cust   = trim($filters['customer']       ?? '');
                $fi_plate  = trim($filters['plate']          ?? '');
                $fi_payst  = trim($filters['payment_status'] ?? '');
                $fi_search = trim($filters['search']         ?? '');

                // Station filtering conditions
                $sid_cond1 = ($station_id > 0) ? " AND jo.station_id = :sid1 " : "";
                $sid_cond2 = ($station_id > 0) ? " AND mt.station_id = :sid2 " : "";

                $sql = "SELECT * FROM (
                            SELECT jo.id as id,
                                   jo.job_order_number as jo_no,
                                   jo.created_at as date,
                                   COALESCE(NULLIF(jo.customer_name,''), NULLIF(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')),''), 'Walk-in') as customer,
                                   CONCAT(COALESCE(jo.vehicle_plate,'N/A'), ' - ', COALESCE(jo.vehicle_type,'Vehicle')) as vehicle,
                                   COALESCE(m.full_name, 'Unassigned') as mechanic,
                                   COALESCE(sc.name, jo.service_type, jo.service_description, 'General Service') as service_category,
                                   COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) as labor_fee,
                                   COALESCE(jo.estimated_cost, 0) as service_fee,
                                   COALESCE(jo.actual_parts_cost, jo.estimated_parts_cost, 0) as parts_cost,
                                   COALESCE(jo.total_cost, (COALESCE(jo.actual_labor_cost,0) + COALESCE(jo.actual_parts_cost,0)), 0) as total_amount,
                                   COALESCE(NULLIF(jo.payment_method,''), NULLIF(jo.payment_status,''), 'Unpaid') as payment_method,
                                   COALESCE(jo.status, 'Pending') as status,
                                   jo.completed_at as released_date,
                                   'job_orders' as source_table
                            FROM job_orders jo
                            LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id
                            LEFT JOIN service_categories sc ON jo.service_category_id = sc.id
                            LEFT JOIN customers c ON jo.customer_id = c.id
                            WHERE DATE(jo.created_at) BETWEEN :df1 AND :dt1
                              {$sid_cond1}

                            UNION ALL

                            SELECT mt.id as id,
                                   COALESCE(NULLIF(mt.job_order_id,''), mt.transaction_id) as jo_no,
                                   COALESCE(mt.transaction_date, mt.created_at) as date,
                                   COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
                                   CONCAT(COALESCE(mt.job_order_vehicle_plate,'N/A'), ' - ', COALESCE(NULLIF(mt.job_order_vehicle_type,''),'Vehicle')) as vehicle,
                                   COALESCE(NULLIF(mt.job_order_mechanic_name,''), 'Unassigned') as mechanic,
                                   COALESCE(NULLIF(mt.job_order_service,''), 'Vehicle Service') as service_category,
                                   COALESCE(mt.subtotal_amount, 0) as labor_fee,
                                   0 as service_fee,
                                   GREATEST(COALESCE(mt.total_amount,0) - COALESCE(mt.subtotal_amount,0), 0) as parts_cost,
                                   COALESCE(mt.total_amount, 0) as total_amount,
                                   COALESCE(NULLIF(mt.payment_method,''), 'Cash') as payment_method,
                                   COALESCE(NULLIF(mt.workflow_status,''), NULLIF(mt.validation_status,''), 'Completed') as status,
                                   COALESCE(mt.transaction_date, mt.created_at) as released_date,
                                   'merchandise_transactions' as source_table
                            FROM merchandise_transactions mt
                            WHERE DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN :df2 AND :dt2
                              {$sid_cond2}
                              AND (
                                  LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service','combined')
                                  OR (mt.job_order_service IS NOT NULL AND TRIM(mt.job_order_service) != '')
                              )
                              AND (mt.job_order_db_id IS NULL OR mt.job_order_db_id = 0 OR mt.job_order_db_id NOT IN (SELECT id FROM job_orders))
                        ) cjo
                        WHERE 1=1 ";

                $params = [
                    'df1' => $date_from,
                    'dt1' => $date_to,
                    'df2' => $date_from,
                    'dt2' => $date_to,
                ];
                if ($station_id > 0) {
                    $params['sid1'] = $station_id;
                    $params['sid2'] = $station_id;
                }

                if (!empty($fi_status)) {
                    $st_low = strtolower($fi_status);
                    if ($st_low === 'completed') {
                        $sql .= " AND LOWER(cjo.status) IN ('completed','verified','finalized') ";
                    } elseif ($st_low === 'released') {
                        $sql .= " AND LOWER(cjo.status) = 'released' ";
                    } elseif ($st_low === 'pending') {
                        $sql .= " AND LOWER(cjo.status) IN ('pending','reviewed') ";
                    } elseif ($st_low === 'in progress' || $st_low === 'inprogress') {
                        $sql .= " AND LOWER(cjo.status) IN ('in progress','inprogress','awaiting parts') ";
                    } elseif ($st_low === 'cancelled' || $st_low === 'canceled') {
                        $sql .= " AND LOWER(cjo.status) IN ('cancelled','canceled','rejected','voided') ";
                    } else {
                        $sql .= " AND LOWER(cjo.status) = LOWER(:fi_status) ";
                        $params['fi_status'] = $fi_status;
                    }
                }
                if (!empty($fi_mech)) {
                    $sql .= " AND LOWER(cjo.mechanic) LIKE LOWER(:fi_mech) ";
                    $params['fi_mech'] = "%$fi_mech%";
                }
                if (!empty($fi_scat)) {
                    $sql .= " AND LOWER(cjo.service_category) LIKE LOWER(:fi_scat) ";
                    $params['fi_scat'] = "%$fi_scat%";
                }
                if (!empty($fi_cust)) {
                    $sql .= " AND LOWER(cjo.customer) LIKE LOWER(:fi_cust) ";
                    $params['fi_cust'] = "%$fi_cust%";
                }
                if (!empty($fi_plate)) {
                    $sql .= " AND LOWER(cjo.vehicle) LIKE LOWER(:fi_plate) ";
                    $params['fi_plate'] = "%$fi_plate%";
                }
                if (!empty($fi_payst)) {
                    $sql .= " AND LOWER(cjo.payment_method) LIKE LOWER(:fi_payst) ";
                    $params['fi_payst'] = "%$fi_payst%";
                }
                if (!empty($fi_search)) {
                    $sql .= " AND (
                        LOWER(cjo.jo_no) LIKE LOWER(:srch)
                        OR LOWER(cjo.customer) LIKE LOWER(:srch)
                        OR LOWER(cjo.vehicle) LIKE LOWER(:srch)
                        OR LOWER(cjo.mechanic) LIKE LOWER(:srch)
                        OR LOWER(cjo.service_category) LIKE LOWER(:srch)
                    ) ";
                    $params['srch'] = "%$fi_search%";
                }

                $sql .= " ORDER BY cjo.date DESC ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $data['rows'] = $rows;

                // Footer Summary calculations
                $total_jos       = count($rows);
                $completed_jos   = 0;
                $pending_jos     = 0;
                $in_progress_jos = 0;
                $released_jos    = 0;
                $cancelled_jos   = 0;
                $total_labor     = 0;
                $total_sfee      = 0;
                $total_parts     = 0;
                $overall_rev     = 0;

                foreach ($rows as $r) {
                    $st = strtolower(trim($r['status'] ?? ''));
                    if (in_array($st, ['completed', 'verified', 'finalized'])) $completed_jos++;
                    elseif (in_array($st, ['pending', 'reviewed'])) $pending_jos++;
                    elseif (in_array($st, ['in progress', 'inprogress', 'awaiting parts'])) $in_progress_jos++;
                    elseif ($st === 'released') $released_jos++;
                    elseif (in_array($st, ['cancelled', 'canceled', 'rejected', 'voided'])) $cancelled_jos++;

                    $total_labor += (float)($r['labor_fee'] ?? 0);
                    $total_sfee  += (float)($r['service_fee'] ?? 0);
                    $total_parts += (float)($r['parts_cost'] ?? 0);
                    $overall_rev += (float)($r['total_amount'] ?? 0);
                }

                $data['summary'] = [
                    'total_jos'         => $total_jos,
                    'completed_jos'     => $completed_jos,
                    'pending_jos'       => $pending_jos,
                    'in_progress_jos'   => $in_progress_jos,
                    'released_jos'      => $released_jos,
                    'cancelled_jos'     => $cancelled_jos,
                    'total_labor'       => $total_labor,
                    'total_service_fee' => $total_sfee,
                    'total_parts'       => $total_parts,
                    'overall_revenue'   => $overall_rev,
                ];

            // ---- 2. MECHANIC PERFORMANCE REPORT ----
            } else {
                $fi_mech   = trim($filters['mechanic']    ?? '');
                $fi_scat   = trim($filters['service_cat'] ?? '');
                $fi_status = trim($filters['status']      ?? '');
                $fi_search = trim($filters['search']      ?? '');

                $sid_cond1  = ($station_id > 0) ? " AND jo.station_id = :sid1 " : "";
                $sid_cond2  = ($station_id > 0) ? " AND mt.station_id = :sid2 " : "";
                $sid_cond_m = ($station_id > 0) ? " AND m.station_id = :sid_m " : "";

                $where_m = " WHERE (m.archived = 0 OR m.archived IS NULL) {$sid_cond_m} ";
                $params = [
                    'df1' => $date_from,
                    'dt1' => $date_to,
                    'df2' => $date_from,
                    'dt2' => $date_to,
                ];
                if ($station_id > 0) {
                    $params['sid1']  = $station_id;
                    $params['sid2']  = $station_id;
                    $params['sid_m'] = $station_id;
                }

                if (!empty($fi_mech))   { $where_m .= " AND LOWER(m.full_name) LIKE LOWER(:fi_mech) "; $params['fi_mech'] = "%$fi_mech%"; }
                if (!empty($fi_search)) { $where_m .= " AND LOWER(m.full_name) LIKE LOWER(:srch) ";    $params['srch']    = "%$fi_search%"; }

                $cjo_filter = "";
                if (!empty($fi_scat))   { $cjo_filter .= " AND LOWER(cjo.service_cat) LIKE LOWER(:fi_scat) "; $params['fi_scat'] = "%$fi_scat%"; }
                if (!empty($fi_status)) {
                    $st_low = strtolower($fi_status);
                    if ($st_low === 'completed') {
                        $cjo_filter .= " AND LOWER(cjo.status) IN ('completed','verified','finalized') ";
                    } elseif ($st_low === 'released') {
                        $cjo_filter .= " AND LOWER(cjo.status) = 'released' ";
                    } elseif ($st_low === 'pending') {
                        $cjo_filter .= " AND LOWER(cjo.status) IN ('pending','reviewed') ";
                    } elseif ($st_low === 'in progress' || $st_low === 'inprogress') {
                        $cjo_filter .= " AND LOWER(cjo.status) IN ('in progress','inprogress','awaiting parts') ";
                    } elseif ($st_low === 'cancelled' || $st_low === 'canceled') {
                        $cjo_filter .= " AND LOWER(cjo.status) IN ('cancelled','canceled','rejected','voided') ";
                    } else {
                        $cjo_filter .= " AND LOWER(cjo.status) = LOWER(:fi_status) ";
                        $params['fi_status'] = $fi_status;
                    }
                }

                $sql = "SELECT m.full_name as mechanic,
                               COUNT(cjo.id) as assigned_jobs,
                               SUM(CASE WHEN LOWER(COALESCE(cjo.status,'')) IN ('completed','verified','finalized','released') THEN 1 ELSE 0 END) as completed_jobs,
                               SUM(CASE WHEN LOWER(COALESCE(cjo.status,'')) IN ('pending','reviewed','in progress','awaiting parts') THEN 1 ELSE 0 END) as pending_jobs,
                               SUM(CASE WHEN LOWER(COALESCE(cjo.status,'')) IN ('cancelled','rejected','voided') THEN 1 ELSE 0 END) as cancelled_jobs,
                               ROUND(AVG(CASE WHEN cjo.released_date IS NOT NULL AND cjo.date IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, cjo.date, cjo.released_date) ELSE NULL END), 0) as avg_completion_mins,
                               SUM(CASE WHEN LOWER(COALESCE(cjo.status,'')) IN ('completed','verified','finalized','released') THEN COALESCE(cjo.labor_fee, 0) ELSE 0 END) as labor_revenue,
                               SUM(CASE WHEN LOWER(COALESCE(cjo.status,'')) IN ('completed','verified','finalized','released') THEN COALESCE(cjo.service_fee, 0) ELSE 0 END) as service_revenue,
                               SUM(CASE WHEN LOWER(COALESCE(cjo.status,'')) IN ('completed','verified','finalized','released') THEN COALESCE(cjo.total_amount, 0) ELSE 0 END) as total_revenue
                        FROM mechanics m
                        LEFT JOIN (
                            SELECT jo.id,
                                   jo.assigned_mechanic_id,
                                   m2.full_name as mechanic,
                                   jo.created_at as date,
                                   jo.completed_at as released_date,
                                   COALESCE(jo.actual_labor_cost, jo.estimated_labor_cost, 0) as labor_fee,
                                   COALESCE(jo.estimated_cost, 0) as service_fee,
                                   COALESCE(jo.total_cost, (COALESCE(jo.actual_labor_cost,0) + COALESCE(jo.actual_parts_cost,0)), 0) as total_amount,
                                   COALESCE(jo.status, 'Pending') as status,
                                   sc.name as service_cat
                            FROM job_orders jo
                            LEFT JOIN mechanics m2 ON jo.assigned_mechanic_id = m2.id
                            LEFT JOIN service_categories sc ON jo.service_category_id = sc.id
                            WHERE DATE(jo.created_at) BETWEEN :df1 AND :dt1
                              {$sid_cond1}

                            UNION ALL

                            SELECT mt.id,
                                   NULL as assigned_mechanic_id,
                                   mt.job_order_mechanic_name as mechanic,
                                   COALESCE(mt.transaction_date, mt.created_at) as date,
                                   COALESCE(mt.transaction_date, mt.created_at) as released_date,
                                   COALESCE(mt.subtotal_amount, 0) as labor_fee,
                                   0 as service_fee,
                                   COALESCE(mt.total_amount, 0) as total_amount,
                                   COALESCE(NULLIF(mt.workflow_status,''), NULLIF(mt.validation_status,''), 'Completed') as status,
                                   mt.job_order_service as service_cat
                            FROM merchandise_transactions mt
                            WHERE DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN :df2 AND :dt2
                              {$sid_cond2}
                              AND (
                                  LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service','combined')
                                  OR (mt.job_order_service IS NOT NULL AND TRIM(mt.job_order_service) != '')
                              )
                              AND (mt.job_order_db_id IS NULL OR mt.job_order_db_id = 0 OR mt.job_order_db_id NOT IN (SELECT id FROM job_orders))
                        ) cjo ON (m.id = cjo.assigned_mechanic_id OR LOWER(TRIM(m.full_name)) = LOWER(TRIM(cjo.mechanic))) {$cjo_filter}
                        {$where_m}
                        GROUP BY m.id, m.full_name
                        ORDER BY total_revenue DESC, m.full_name ASC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $data['rows'] = $rows;

                // Footer summary calculations for mechanics
                $total_mechanics = count($rows);
                $total_assigned  = array_sum(array_column($rows, 'assigned_jobs'));
                $total_completed = array_sum(array_column($rows, 'completed_jobs'));
                $total_pending   = array_sum(array_column($rows, 'pending_jobs'));
                $total_revenue   = array_sum(array_column($rows, 'total_revenue'));

                $mins_arr = array_filter(array_column($rows, 'avg_completion_mins'), fn($v) => !is_null($v) && $v > 0);
                $overall_avg_mins = count($mins_arr) ? round(array_sum($mins_arr) / count($mins_arr)) : 0;

                $data['summary'] = [
                    'total_mechanics'     => $total_mechanics,
                    'total_assigned'      => $total_assigned,
                    'total_completed'     => $total_completed,
                    'total_pending'       => $total_pending,
                    'overall_avg_mins'    => $overall_avg_mins,
                    'overall_revenue'     => $total_revenue,
                ];
            }
            break;

        // =========================================================================
        // 4. PROCUREMENT REPORTS
        // =========================================================================
        case 'procurement':
            // Load categories for filter dropdown
            try {
                $stmt = $pdo->query("SELECT id, name FROM product_categories ORDER BY name");
                $data['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $data['categories'] = []; }

            if ($tab === 'purchase_order') {
                // Build dynamic WHERE
                $po_where  = "WHERE DATE(po.created_at) BETWEEN :date_from AND :date_to";
                $po_params = ['date_from' => $date_from, 'date_to' => $date_to];
                if ($station_id > 0) { $po_where .= " AND po.station_id = :station_id"; $po_params['station_id'] = $station_id; }
                if (!empty($filters['po_status'])) {
                    $po_where .= " AND po.status LIKE :po_status";
                    $po_params['po_status'] = '%' . $filters['po_status'] . '%';
                }
                if (!empty($filters['search'])) {
                    $po_where .= " AND po.po_number LIKE :search";
                    $po_params['search'] = '%' . $filters['search'] . '%';
                }
                // Note: category filter applied via items join
                $cat_join  = '';
                $cat_where = '';
                if (!empty($filters['category'])) {
                    $cat_join  = " LEFT JOIN purchase_order_items poi2 ON poi2.po_id = po.id LEFT JOIN products p2 ON p2.id = poi2.product_id LEFT JOIN product_categories pc2 ON pc2.id = p2.category_id ";
                    $cat_where = " AND pc2.name = :cat_filter";
                    $po_params['cat_filter'] = $filters['category'];
                }

                $sql = "SELECT po.po_number,
                               po.created_at as po_date,
                               COALESCE(u1.name, CONCAT(u1.first_name,' ',u1.last_name), 'N/A') as requested_by,
                               COALESCE(u2.name, CONCAT(u2.first_name,' ',u2.last_name), 'N/A') as approved_by,
                               COALESCE(s.name, fs.supplier_name, 'Petron Corporation') as supplier,
                               COUNT(DISTINCT poi.id) as item_count,
                               COALESCE(SUM(poi.quantity_ordered), 0) as total_qty,
                               COALESCE(SUM(poi.total_price), 0) as estimated_cost,
                               po.expected_delivery_date,
                               po.status
                        FROM purchase_orders po
                        LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
                        LEFT JOIN suppliers s ON po.supplier_id = s.id
                        LEFT JOIN fuel_suppliers fs ON po.supplier_id = fs.id
                        LEFT JOIN users u1 ON po.created_by = u1.id
                        LEFT JOIN users u2 ON po.approved_by = u2.id
                        {$cat_join}
                        {$po_where} {$cat_where}
                        GROUP BY po.id
                        ORDER BY po.created_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($po_params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } elseif ($tab === 'fuel_reconciliation' || $tab === 'delivery_validation') {
                $fr_where  = "WHERE DATE(ft.transaction_date) BETWEEN :date_from AND :date_to";
                $fr_params = ['date_from' => $date_from, 'date_to' => $date_to];
                if ($station_id > 0) { $fr_where .= " AND ft.station_id = :station_id"; $fr_params['station_id'] = $station_id; }
                if (!empty($filters['fuel_type'])) {
                    $fr_where .= " AND LOWER(ft.fuel_type) LIKE :fuel_type";
                    $fr_params['fuel_type'] = '%' . strtolower($filters['fuel_type']) . '%';
                }
                if (!empty($filters['ugt'])) {
                    $fi_ugt_raw = strtoupper(trim($filters['ugt']));
                    $ugt_fuel_like = null;
                    if (strpos($fi_ugt_raw, '#1') !== false || strpos($fi_ugt_raw, '-01') !== false || strpos($fi_ugt_raw, ' 1') !== false) {
                        $ugt_fuel_like = 'DIESEL 1%';
                    } elseif (strpos($fi_ugt_raw, '#2') !== false || strpos($fi_ugt_raw, '-02') !== false || strpos($fi_ugt_raw, ' 2') !== false) {
                        $ugt_fuel_like = 'DIESEL 2%';
                    } elseif (strpos($fi_ugt_raw, '#3') !== false || strpos($fi_ugt_raw, '-03') !== false || strpos($fi_ugt_raw, ' 3') !== false) {
                        $ugt_fuel_like = 'TURBO%';
                    } elseif (strpos($fi_ugt_raw, '#4') !== false || strpos($fi_ugt_raw, '-04') !== false || strpos($fi_ugt_raw, ' 4') !== false) {
                        $ugt_fuel_like = 'XCS%';
                    } elseif (strpos($fi_ugt_raw, '#5') !== false || strpos($fi_ugt_raw, '-05') !== false || strpos($fi_ugt_raw, ' 5') !== false) {
                        $ugt_fuel_like = 'XTRA UNL 1%';
                    } elseif (strpos($fi_ugt_raw, '#6') !== false || strpos($fi_ugt_raw, '-06') !== false || strpos($fi_ugt_raw, ' 6') !== false) {
                        $ugt_fuel_like = 'XTRA UNL 2%';
                    } elseif (strpos($fi_ugt_raw, '#7') !== false || strpos($fi_ugt_raw, '-07') !== false || strpos($fi_ugt_raw, ' 7') !== false) {
                        $ugt_fuel_like = 'KEROSENE%';
                    }
                    if ($ugt_fuel_like !== null) {
                        $fr_where .= " AND UPPER(ft.fuel_type) LIKE :ugt_fuel_like ";
                        $fr_params['ugt_fuel_like'] = $ugt_fuel_like;
                    }
                }
                if (!empty($filters['status'])) {
                    if (strtolower($filters['status']) === 'submitted') {
                        $fr_where .= " AND LOWER(COALESCE(ft.status, '')) IN ('verified','approved','validated')";
                    } elseif (strtolower($filters['status']) === 'pending') {
                        $fr_where .= " AND LOWER(COALESCE(ft.status, '')) NOT IN ('verified','approved','validated')";
                    }
                }

                $sql = "SELECT ft.id,
                               ft.fuel_type as raw_fuel_type,
                               ft.pump_id,
                               COALESCE(ft.previous_reading, 0) as beginning_reading,
                               COALESCE(ft.present_reading, 0) as ending_reading,
                               COALESCE(ft.calibration, 0) as calibration,
                               GREATEST(0, COALESCE(ft.present_reading, 0) - COALESCE(ft.previous_reading, 0) - COALESCE(ft.calibration, 0)) as net_volume,
                               COALESCE(ft.price_per_liter, 0) as selling_price,
                               COALESCE(ft.total_amount, 0) as fuel_sales,
                               CASE 
                                 WHEN LOWER(COALESCE(ft.status, '')) IN ('verified','approved','validated') THEN 'Submitted'
                                 ELSE 'Pending'
                               END as status,
                               ft.transaction_date
                        FROM fuel_transactions ft
                        {$fr_where}
                        ORDER BY ft.transaction_date DESC, ft.id DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($fr_params);
                $raw_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($raw_rows as &$r) {
                    $rawFuel = $r['raw_fuel_type'] ?? '';
                    $r['ugt_no']    = get_exact_ugt_no($rawFuel);
                    $r['clean_fuel_type'] = staff_report_fuel_display_name($rawFuel);
                    $r['fuel_type'] = !empty($rawFuel) ? $rawFuel : $r['clean_fuel_type'];
                    if ((float)$r['fuel_sales'] <= 0 && (float)$r['net_volume'] > 0 && (float)$r['selling_price'] > 0) {
                        $r['fuel_sales'] = round((float)$r['net_volume'] * (float)$r['selling_price'], 2);
                    }
                }
                if (!empty($filters['ugt'])) {
                    $target_ugt = strtoupper(trim($filters['ugt']));
                    $raw_rows = array_values(array_filter($raw_rows, function($r) use ($target_ugt) {
                        return strtoupper(trim($r['ugt_no'] ?? '')) === $target_ugt || stripos($r['ugt_no'] ?? '', $target_ugt) !== false;
                    }));
                }
                $data['rows'] = $raw_rows;

            } elseif ($tab === 'po_vs_received') {
                $pvr_where  = "WHERE DATE(po.created_at) BETWEEN :date_from AND :date_to";
                $pvr_params = ['date_from' => $date_from, 'date_to' => $date_to];
                if ($station_id > 0) { $pvr_where .= " AND po.station_id = :station_id"; $pvr_params['station_id'] = $station_id; }
                if (!empty($filters['po_no'])) {
                    $pvr_where .= " AND po.po_number LIKE :po_no";
                    $pvr_params['po_no'] = '%' . $filters['po_no'] . '%';
                }
                if (!empty($filters['search'])) {
                    $pvr_where .= " AND (po.po_number LIKE :search OR poi.item_name LIKE :search2)";
                    $pvr_params['search']  = '%' . $filters['search'] . '%';
                    $pvr_params['search2'] = '%' . $filters['search'] . '%';
                }

                $sql = "SELECT po.po_number,
                               COALESCE(poi.item_name, po.product_name) as product,
                               COALESCE(poi.quantity_ordered, po.quantity, 0) as ordered_qty,
                               COALESCE(poi.quantity_received, poi.received_quantity, 0) as received_qty,
                               (COALESCE(poi.quantity_received, poi.received_quantity, 0) - COALESCE(poi.quantity_ordered, po.quantity, 0)) as variance,
                               po.expected_delivery_date,
                               COALESCE(poi.received_at, po.stock_in_at) as actual_delivery,
                               CASE
                                 WHEN poi.quantity_received IS NULL THEN 'Pending Delivery'
                                 WHEN poi.quantity_received >= poi.quantity_ordered THEN 'Complete'
                                 WHEN poi.quantity_received > poi.quantity_ordered THEN 'Over Delivered'
                                 WHEN poi.quantity_received < poi.quantity_ordered AND poi.quantity_received > 0 THEN 'Partial'
                                 ELSE 'Under Delivered'
                               END as status
                        FROM purchase_orders po
                        LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
                        {$pvr_where}
                        ORDER BY po.created_at DESC, poi.id ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($pvr_params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } else { // stock_in_approval
                $si_where  = "WHERE DATE(msi.encoded_at) BETWEEN :date_from AND :date_to";
                $si_params = ['date_from' => $date_from, 'date_to' => $date_to];
                if ($station_id > 0) { $si_where .= " AND msi.station_id = :station_id"; $si_params['station_id'] = $station_id; }
                if (!empty($filters['product'])) {
                    $si_where .= " AND msi.product_name LIKE :product";
                    $si_params['product'] = '%' . $filters['product'] . '%';
                }
                if (!empty($filters['search'])) {
                    $si_where .= " AND (msi.product_name LIKE :search OR msi.batch_ref LIKE :search2 OR msi.po_number LIKE :search3)";
                    $si_params['search']  = '%' . $filters['search'] . '%';
                    $si_params['search2'] = '%' . $filters['search'] . '%';
                    $si_params['search3'] = '%' . $filters['search'] . '%';
                }
                // Approval status — msi has no explicit status col; manager encoded = Approved
                // filter_appr_status maps to condition_flag or we use remarks keyword
                if (!empty($filters['appr_status'])) {
                    if ($filters['appr_status'] === 'Approved') {
                        $si_where .= " AND msi.encoded_by IS NOT NULL";
                    } elseif ($filters['appr_status'] === 'Rejected') {
                        $si_where .= " AND (msi.remarks LIKE '%reject%' OR msi.condition_flag IN ('Damaged','Short'))";
                    }
                }

                $sql = "SELECT COALESCE(msi.batch_ref, CONCAT('BT-', msi.id)) as batch_id,
                               msi.product_name as product,
                               msi.qty_received,
                               msi.unit_cost,
                               msi.selling_price,
                               COALESCE(u.name, CONCAT(u.first_name,' ',u.last_name), 'N/A') as approved_by,
                               msi.encoded_at as approval_date,
                               CASE
                                 WHEN msi.encoded_by IS NOT NULL THEN 'Approved'
                                 ELSE 'Pending Approval'
                               END as status
                        FROM merchandise_stock_in msi
                        LEFT JOIN users u ON msi.encoded_by = u.id
                        {$si_where}
                        ORDER BY msi.encoded_at DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($si_params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            break;

        // =========================================================================
        // 5. FINANCIAL REPORTS
        // =========================================================================
        case 'financial':
            if ($tab === 'revenue_summary') {
                $rev_search = trim($filters['search'] ?? '');
                $sql = "SELECT 
                            d.date,
                            COALESCE(f.fuel_rev, 0) as fuel_revenue,
                            COALESCE(m.merch_rev, 0) as merchandise_revenue,
                            COALESCE(s.serv_rev, 0) as service_revenue,
                            (COALESCE(f.fuel_rev, 0) + COALESCE(m.merch_rev, 0) + COALESCE(s.serv_rev, 0)) as gross_revenue,
                            0.00 as total_discounts,
                            (COALESCE(f.fuel_rev, 0) + COALESCE(m.merch_rev, 0) + COALESCE(s.serv_rev, 0)) as net_revenue
                        FROM (
                            SELECT DATE(transaction_date) as date FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df1 AND :dt1 AND LOWER(COALESCE(status, '')) IN ('verified','approved','validated')
                            UNION
                            SELECT DATE(transaction_date) as date FROM merchandise_transactions WHERE DATE(transaction_date) BETWEEN :df2 AND :dt2
                        ) d
                        LEFT JOIN (
                            SELECT DATE(transaction_date) as date, SUM(total_amount) as fuel_rev
                            FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df3 AND :dt3 AND LOWER(COALESCE(status, '')) IN ('verified','approved','validated') GROUP BY DATE(transaction_date)
                        ) f ON d.date = f.date
                        LEFT JOIN (
                            SELECT DATE(transaction_date) as date, SUM(total_amount) as merch_rev
                            FROM merchandise_transactions WHERE LOWER(COALESCE(transaction_type,'')) NOT IN ('job_order','service')
                            AND DATE(transaction_date) BETWEEN :df4 AND :dt4 GROUP BY DATE(transaction_date)
                        ) m ON d.date = m.date
                        LEFT JOIN (
                            SELECT DATE(transaction_date) as date, SUM(total_amount) as serv_rev
                            FROM merchandise_transactions WHERE LOWER(COALESCE(transaction_type,'')) IN ('job_order','service')
                            AND DATE(transaction_date) BETWEEN :df5 AND :dt5 GROUP BY DATE(transaction_date)
                        ) s ON d.date = s.date
                        ORDER BY d.date DESC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'df1' => $date_from, 'dt1' => $date_to,
                    'df2' => $date_from, 'dt2' => $date_to,
                    'df3' => $date_from, 'dt3' => $date_to,
                    'df4' => $date_from, 'dt4' => $date_to,
                    'df5' => $date_from, 'dt5' => $date_to
                ]);
                $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($rev_search)) {
                    $data['rows'] = array_filter($all_rows, function($r) use ($rev_search) {
                        return str_contains(strtolower($r['date']), strtolower($rev_search));
                    });
                } else {
                    $data['rows'] = $all_rows;
                }

            } elseif ($tab === 'receivables') {
                $filter_cust   = trim($filters['customer'] ?? '');
                $filter_status = trim($filters['status'] ?? '');
                $filter_due    = trim($filters['due_date'] ?? '');

                $all_ar_rows = [];

                // ── Source 1: customer_accounts_receivable table (no station_id col – join customers for station) ──
                $car_params = [];
                $car_where  = " WHERE c.station_id = :car_sid AND DATE(car.created_at) BETWEEN :car_df AND :car_dt ";
                $car_params['car_sid'] = $station_id;
                $car_params['car_df']  = $date_from;
                $car_params['car_dt']  = $date_to;
                if (!empty($filter_cust)) {
                    $car_where .= " AND LOWER(c.name) LIKE LOWER(:car_cust) ";
                    $car_params['car_cust'] = '%' . $filter_cust . '%';
                }
                if (!empty($filter_due)) {
                    $car_where .= " AND DATE(COALESCE(mtt.credit_due_date, mtt.due_date, DATE_ADD(DATE(car.created_at), INTERVAL 30 DAY))) = :car_due ";
                    $car_params['car_due'] = $filter_due;
                }
                $sql_car = "SELECT
                                COALESCE(c.name, 'Credit Customer') as customer,
                                'Credit Account (AR)' as account_type,
                                COALESCE(NULLIF(car.or_number,''), car.transaction_id, CONCAT('AR-', LPAD(car.id,5,'0'))) as invoice_no,
                                DATE(car.created_at) as transaction_date,
                                COALESCE(mtt.credit_due_date, mtt.due_date, DATE_ADD(DATE(car.created_at), INTERVAL 30 DAY)) as due_date,
                                COALESCE(car.outstanding_balance, 0) as outstanding_balance,
                                GREATEST(DATEDIFF(CURDATE(), COALESCE(mtt.credit_due_date, mtt.due_date, DATE_ADD(DATE(car.created_at), INTERVAL 30 DAY))), 0) as days_overdue,
                                CASE
                                  WHEN LOWER(COALESCE(car.status,'')) IN ('paid','settled') THEN 'Paid'
                                  WHEN COALESCE(car.outstanding_balance,0) <= 0 THEN 'Paid'
                                  WHEN DATE(COALESCE(mtt.credit_due_date, mtt.due_date, DATE_ADD(DATE(car.created_at), INTERVAL 30 DAY))) = CURDATE() THEN 'Due Today'
                                  WHEN DATE(COALESCE(mtt.credit_due_date, mtt.due_date, DATE_ADD(DATE(car.created_at), INTERVAL 30 DAY))) < CURDATE() THEN 'Overdue'
                                  ELSE 'Current'
                                END as status
                            FROM customer_accounts_receivable car
                            LEFT JOIN customers c ON car.customer_id = c.id
                            LEFT JOIN merchandise_transactions mtt ON car.transaction_db_id = mtt.id
                            {$car_where}
                            ORDER BY car.created_at DESC";
                $stmt_car = $pdo->prepare($sql_car);
                $stmt_car->execute($car_params);
                $all_ar_rows = array_merge($all_ar_rows, $stmt_car->fetchAll(PDO::FETCH_ASSOC));

                // ── Source 2: merchandise_transactions – any credit/unpaid/balance_due/ar rows ──
                $mt_params = [];
                $mt_where  = " WHERE mt.station_id = :mt_sid
                               AND DATE(mt.transaction_date) BETWEEN :mt_df AND :mt_dt
                               AND (
                                   LOWER(COALESCE(mt.payment_method,'')) LIKE '%credit%'
                                   OR LOWER(COALESCE(mt.payment_method,'')) LIKE '%fleet%'
                                   OR LOWER(COALESCE(mt.payment_method,'')) LIKE '%account%'
                                   OR LOWER(COALESCE(mt.payment_status,'')) IN ('unpaid','credit','partial')
                                   OR LOWER(COALESCE(mt.ar_status,'')) IN ('pending','partial','unpaid')
                                   OR COALESCE(mt.balance_due,0) > 0
                                   OR mt.credit_customer_id IS NOT NULL
                               ) ";
                $mt_params['mt_sid'] = $station_id;
                $mt_params['mt_df']  = $date_from;
                $mt_params['mt_dt']  = $date_to;
                if (!empty($filter_cust)) {
                    $mt_where .= " AND (LOWER(COALESCE(mt.customer_name,'')) LIKE LOWER(:mt_cust)
                                    OR LOWER(TRIM(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')))) LIKE LOWER(:mt_cust2)
                                    OR LOWER(COALESCE(c.name,'')) LIKE LOWER(:mt_cust3)) ";
                    $mt_params['mt_cust']  = '%' . $filter_cust . '%';
                    $mt_params['mt_cust2'] = '%' . $filter_cust . '%';
                    $mt_params['mt_cust3'] = '%' . $filter_cust . '%';
                }
                if (!empty($filter_due)) {
                    $mt_where .= " AND DATE(COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))) = :mt_due ";
                    $mt_params['mt_due'] = $filter_due;
                }
                $sql_mt = "SELECT
                               COALESCE(NULLIF(TRIM(mt.customer_name),''),
                                        NULLIF(TRIM(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,''))),''),
                                        c.name, 'Credit Customer') as customer,
                               COALESCE(mt.payment_method, 'Credit Account (AR)') as account_type,
                               COALESCE(NULLIF(mt.transaction_id,''), CONCAT('MT-', LPAD(mt.id,5,'0'))) as invoice_no,
                               DATE(mt.transaction_date) as transaction_date,
                               COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY)) as due_date,
                               COALESCE(NULLIF(mt.balance_due,0), mt.total_amount, 0) as outstanding_balance,
                               GREATEST(DATEDIFF(CURDATE(), COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))), 0) as days_overdue,
                               CASE
                                 WHEN LOWER(COALESCE(mt.payment_status,'')) = 'paid' AND COALESCE(mt.balance_due,0) <= 0 THEN 'Paid'
                                 WHEN DATE(COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))) = CURDATE() THEN 'Due Today'
                                 WHEN DATE(COALESCE(mt.credit_due_date, mt.due_date, DATE_ADD(DATE(mt.transaction_date), INTERVAL 30 DAY))) < CURDATE() THEN 'Overdue'
                                 ELSE 'Current'
                               END as status
                           FROM merchandise_transactions mt
                           LEFT JOIN customers c ON mt.credit_customer_id = c.id
                           {$mt_where}
                           ORDER BY mt.transaction_date DESC";
                $stmt_mt = $pdo->prepare($sql_mt);
                $stmt_mt->execute($mt_params);
                $all_ar_rows = array_merge($all_ar_rows, $stmt_mt->fetchAll(PDO::FETCH_ASSOC));

                // ── Source 3: Registered credit-eligible customers (credit_limit > 0 or credit_terms set) ──
                // Show as registered credit accounts even if current balance = 0
                $cust_params = [];
                $cust_where  = " WHERE c.station_id = :cust_sid
                                 AND (c.credit_limit > 0 OR (c.credit_terms IS NOT NULL AND c.credit_terms != '')) ";
                $cust_params['cust_sid'] = $station_id;
                if (!empty($filter_cust)) {
                    $cust_where .= " AND LOWER(c.name) LIKE LOWER(:cust_name) ";
                    $cust_params['cust_name'] = '%' . $filter_cust . '%';
                }
                $sql_cust = "SELECT
                                 c.name as customer,
                                 CONCAT('Credit Account (Limit: ₱', FORMAT(c.credit_limit,2), ')') as account_type,
                                 CONCAT('CUST-', LPAD(c.id,5,'0')) as invoice_no,
                                 DATE(c.created_at) as transaction_date,
                                 NULL as due_date,
                                 COALESCE(NULLIF(c.outstanding_balance,0), NULLIF(c.current_balance,0), 0) as outstanding_balance,
                                 0 as days_overdue,
                                 CASE
                                   WHEN COALESCE(c.outstanding_balance,0) <= 0 AND COALESCE(c.current_balance,0) <= 0 THEN 'Paid'
                                   ELSE 'Current'
                                 END as status
                             FROM customers c
                             {$cust_where}
                             ORDER BY c.name ASC";
                $stmt_cust = $pdo->prepare($sql_cust);
                $stmt_cust->execute($cust_params);
                $cust_rows = $stmt_cust->fetchAll(PDO::FETCH_ASSOC);

                // Deduplicate: only add customer-level record if not already in sources 1+2
                $seen_invoices = array_map(fn($r) => $r['invoice_no'], $all_ar_rows);
                foreach ($cust_rows as $cr) {
                    if (!in_array($cr['invoice_no'], $seen_invoices)) {
                        $all_ar_rows[] = $cr;
                    }
                }

                $rows = $all_ar_rows;

                if (!empty($filter_status)) {
                    $rows = array_filter($rows, function($r) use ($filter_status) {
                        return strtolower($r['status']) === strtolower($filter_status);
                    });
                }

                $data['rows'] = array_values($rows);



            } elseif ($tab === 'payment_collections') {
                $filter_pm   = trim($filters['payment_method'] ?? '');
                $filter_cust = trim($filters['customer'] ?? '');

                $col_where = " WHERE LOWER(COALESCE(mt.payment_status,'Paid')) = 'paid' AND DATE(mt.transaction_date) BETWEEN :date_from AND :date_to ";
                $col_params = ['date_from' => $date_from, 'date_to' => $date_to];

                if (!empty($filter_pm)) {
                    $norm_col_f = function_exists('normalize_payment_type') ? normalize_payment_type($filter_pm) : ['type' => $filter_pm, 'provider' => ''];
                    if ($norm_col_f['type'] === 'E-Wallet') {
                        if (!empty($norm_col_f['provider'])) {
                            $col_where .= " AND (LOWER(COALESCE(mt.ewallet_provider,'')) = LOWER(:filter_prov) OR LOWER(COALESCE(mt.payment_method,'')) = LOWER(:filter_prov)) ";
                            $col_params['filter_prov'] = $norm_col_f['provider'];
                        } else {
                            $col_where .= " AND (LOWER(COALESCE(mt.payment_method,'')) IN ('e-wallet', 'ewallet', 'gcash', 'maya') OR (mt.ewallet_provider IS NOT NULL AND mt.ewallet_provider != '')) ";
                        }
                    } else {
                        $col_where .= " AND LOWER(COALESCE(mt.payment_method,'')) = LOWER(:filter_pm) ";
                        $col_params['filter_pm'] = $norm_col_f['type'];
                    }
                }
                if (!empty($filter_cust)) {
                    $col_where .= " AND (LOWER(mt.customer_name) LIKE LOWER(:filter_cust) OR LOWER(mt.customer_first_name) LIKE LOWER(:filter_cust) OR LOWER(mt.customer_last_name) LIKE LOWER(:filter_cust)) ";
                    $col_params['filter_cust'] = '%' . $filter_cust . '%';
                }

                $sql_col = "SELECT 
                                mt.transaction_id as or_no,
                                COALESCE(NULLIF(mt.customer_name,''), NULLIF(CONCAT(COALESCE(mt.customer_first_name,''),' ',COALESCE(mt.customer_last_name,'')),''), 'Walk-in') as customer,
                                COALESCE(mt.credit_po_number, mt.transaction_id) as invoice_no,
                                COALESCE(mt.payment_method, 'Cash') as payment_method,
                                COALESCE(mt.ewallet_provider, '') as ewallet_provider,
                                COALESCE(mt.ewallet_reference, '') as ewallet_reference,
                                COALESCE(mt.amount_paid, mt.total_amount, 0) as amount_paid,
                                COALESCE(u.name, 'Cashier Staff') as collected_by,
                                mt.transaction_date as payment_date
                            FROM merchandise_transactions mt
                            LEFT JOIN users u ON mt.staff_id = u.id
                            {$col_where}
                            ORDER BY mt.transaction_date DESC";
                $stmt = $pdo->prepare($sql_col);
                $stmt->execute($col_params);
                $data['rows'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } else { // sales_vs_collection
                $svc_search = trim($filters['search'] ?? '');
                $sql_svc = "SELECT 
                                d.date,
                                COALESCE(s.total_sales, 0) as total_sales,
                                COALESCE(cs.total_credit_sales, 0) as total_credit_sales,
                                COALESCE(col.total_collections, 0) as total_collections,
                                GREATEST(COALESCE(cs.total_credit_sales, 0) - COALESCE(col.total_collections, 0), 0) as outstanding_balance
                            FROM (
                                SELECT DATE(transaction_date) as date FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df1 AND :dt1 AND LOWER(COALESCE(status, '')) IN ('verified','approved','validated')
                                UNION
                                SELECT DATE(transaction_date) as date FROM merchandise_transactions WHERE DATE(transaction_date) BETWEEN :df2 AND :dt2
                            ) d
                            LEFT JOIN (
                                SELECT date, SUM(amount) as total_sales FROM (
                                    SELECT DATE(transaction_date) as date, total_amount as amount FROM fuel_transactions WHERE DATE(transaction_date) BETWEEN :df3 AND :dt3 AND LOWER(COALESCE(status, '')) IN ('verified','approved','validated')
                                    UNION ALL
                                    SELECT DATE(transaction_date) as date, total_amount as amount FROM merchandise_transactions WHERE DATE(transaction_date) BETWEEN :df4 AND :dt4
                                ) t1 GROUP BY date
                            ) s ON d.date = s.date
                            LEFT JOIN (
                                SELECT DATE(transaction_date) as date, SUM(total_amount) as total_credit_sales
                                FROM merchandise_transactions
                                WHERE (LOWER(COALESCE(payment_method,'')) LIKE '%credit%' OR LOWER(COALESCE(payment_method,'')) LIKE '%fleet%')
                                  AND DATE(transaction_date) BETWEEN :df5 AND :dt5
                                GROUP BY DATE(transaction_date)
                            ) cs ON d.date = cs.date
                            LEFT JOIN (
                                SELECT DATE(transaction_date) as date, SUM(COALESCE(amount_paid, total_amount, 0)) as total_collections
                                FROM merchandise_transactions
                                WHERE LOWER(COALESCE(payment_status,'Paid')) = 'paid'
                                  AND DATE(transaction_date) BETWEEN :df6 AND :dt6
                                GROUP BY DATE(transaction_date)
                            ) col ON d.date = col.date
                            ORDER BY d.date DESC";
                $stmt = $pdo->prepare($sql_svc);
                $stmt->execute([
                    'df1' => $date_from, 'dt1' => $date_to,
                    'df2' => $date_from, 'dt2' => $date_to,
                    'df3' => $date_from, 'dt3' => $date_to,
                    'df4' => $date_from, 'dt4' => $date_to,
                    'df5' => $date_from, 'dt5' => $date_to,
                    'df6' => $date_from, 'dt6' => $date_to
                ]);
                $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($svc_search)) {
                    $data['rows'] = array_filter($all_rows, function($r) use ($svc_search) {
                        return str_contains(strtolower($r['date']), strtolower($svc_search));
                    });
                } else {
                    $data['rows'] = $all_rows;
                }
            }
            break;

        // =========================================================================
        // 6. CUSTOMER REPORTS
        // =========================================================================
        case 'customer':
            // FINAL CUSTOMER REPORT (Single Customer Report overview)
            $filter_cname   = trim($filters['cust_name'] ?? '');
            $filter_ctype   = trim($filters['cust_type'] ?? '');
            $filter_pstatus = trim($filters['payment_status'] ?? '');
            $filter_plate   = trim($filters['plate'] ?? '');
            $filter_search  = trim($filters['search'] ?? '');

            $c_where = " WHERE 1=1 ";
            $c_params = [];
            if ($station_id > 0) {
                $c_where .= " AND (c.station_id = :station_id OR c.station_id IS NULL) ";
                $c_params['station_id'] = $station_id;
            }

            if (!empty($filter_cname)) {
                $c_where .= " AND LOWER(c.name) LIKE LOWER(:filter_cname) ";
                $c_params['filter_cname'] = '%' . $filter_cname . '%';
            }
            if (!empty($filter_ctype)) {
                if (in_array(strtolower($filter_ctype), ['petron fleet card', 'fleet card', 'fleet'])) {
                    $c_where .= " AND (LOWER(COALESCE(c.type, '')) LIKE '%fleet%') ";
                } elseif (in_array(strtolower($filter_ctype), ['credit account', 'credit'])) {
                    $c_where .= " AND (LOWER(COALESCE(c.type, '')) LIKE '%credit%') ";
                } else {
                    $c_where .= " AND (LOWER(COALESCE(c.type, '')) LIKE LOWER(:filter_ctype)) ";
                    $c_params['filter_ctype'] = '%' . $filter_ctype . '%';
                }
            }
            if (!empty($filter_plate)) {
                $c_where .= " AND (LOWER(COALESCE(c.vehicle_plate,'')) LIKE LOWER(:filter_plate) OR EXISTS (SELECT 1 FROM customer_vehicles cv WHERE cv.customer_id = c.id AND LOWER(cv.plate_number) LIKE LOWER(:filter_plate2))) ";
                $c_params['filter_plate']  = '%' . $filter_plate . '%';
                $c_params['filter_plate2'] = '%' . $filter_plate . '%';
            }
            if (!empty($filter_search)) {
                $c_where .= " AND (LOWER(c.name) LIKE LOWER(:filter_search) OR LOWER(COALESCE(c.customer_id,'')) LIKE LOWER(:filter_search2) OR LOWER(COALESCE(c.contact_number, c.phone,'')) LIKE LOWER(:filter_search3)) ";
                $c_params['filter_search']  = '%' . $filter_search . '%';
                $c_params['filter_search2'] = '%' . $filter_search . '%';
                $c_params['filter_search3'] = '%' . $filter_search . '%';
            }

            $sql_table = "SELECT c.id,
                                 COALESCE(NULLIF(c.customer_id,''), CONCAT('CUST-', LPAD(c.id, 4, '0'))) as customer_id_code,
                                 c.name as customer_name,
                                 COALESCE(NULLIF(c.contact_number,''), NULLIF(c.phone,''), 'N/A') as contact_no,
                                 COALESCE(NULLIF(c.type,''), 'cash') as billing_type,
                                 (SELECT COUNT(DISTINCT DATE(transaction_date)) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as total_visits,
                                 (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as total_transactions,
                                 (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id AND LOWER(COALESCE(mt.transaction_type,'')) IN ('job_order','service')) as total_job_orders,
                                 (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id AND LOWER(COALESCE(mt.transaction_type,'')) NOT IN ('job_order','service')) as total_merch_purchases,
                                 (SELECT COALESCE(SUM(total_amount), 0) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as total_amount_spent,
                                 COALESCE(c.current_balance, c.outstanding_balance, 0) as outstanding_balance,
                                 (SELECT MAX(transaction_date) FROM merchandise_transactions mt WHERE mt.customer_id = c.id) as last_visit,
                                 COALESCE(NULLIF(c.status,''), 'Active') as status
                          FROM customers c
                          {$c_where}
                          ORDER BY total_amount_spent DESC";
            $stmt_tbl = $pdo->prepare($sql_table);
            $stmt_tbl->execute($c_params);
            $rows_cust = $stmt_tbl->fetchAll(PDO::FETCH_ASSOC);

            $details_map = [];
            foreach ($rows_cust as $r_cust) {
                $details_map[$r_cust['id']] = getAdminCustomerDetails($pdo, (int)$r_cust['id']);
            }

            $data['rows'] = $rows_cust;
            $data['customer_details'] = $details_map;
            break;

        // =========================================================================
        // 7. AUDIT REPORTS (Clean 5-Tab Architecture)
        // =========================================================================
        case 'audit':
            $filter_srch     = trim($filters['search'] ?? '');
            $filter_staff    = (int)($filters['staff_id'] ?? 0);
            $filter_mod      = trim($filters['module'] ?? '');
            $filter_stat     = trim($filters['status'] ?? '');
            $filter_act      = trim($filters['action'] ?? '');
            $viewer_role     = strtolower(trim($filters['viewer_role'] ?? ''));     // 'manager', 'admin', 'staff', etc.
            $viewer_user_id  = (int)($filters['viewer_user_id'] ?? 0);              // ID of the logged-in user
            $is_manager_view = ($viewer_role === 'manager' && $viewer_user_id > 0);
            $is_admin_view   = ($viewer_role === 'admin' && $viewer_user_id > 0);
            $is_staff_view   = ($viewer_role === 'staff' && $viewer_user_id > 0);

            // Allowed roles in SQL for viewer role
            $role_filter_roles = null;
            if ($is_manager_view) {
                // Manager: staff + own actions
                $role_filter_roles = "('staff')";
            } elseif ($is_admin_view) {
                // Admin: staff, manager, + own actions (excludes superadmin & other admins)
                $role_filter_roles = "('staff','manager')";
            } elseif ($is_staff_view) {
                // Staff: own actions ONLY (never manager, admin, superadmin, or other staff)
                $role_filter_roles = "('__none_self_only__')";
            }
            $has_role_filter = ($role_filter_roles !== null);

            // Fetch excluded names for secondary PHP filter
            $excluded_names = [];
            if ($is_manager_view) {
                try {
                    $st_ex = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name, username FROM users WHERE LOWER(COALESCE(role,'staff')) != 'staff' AND id != ?");
                    $st_ex->execute([$viewer_user_id]);
                    foreach ($st_ex->fetchAll(PDO::FETCH_ASSOC) ?: [] as $eu) {
                        if (!empty($eu['name']))     $excluded_names[] = strtolower(trim($eu['name']));
                        if (!empty($eu['username'])) $excluded_names[] = strtolower(trim($eu['username']));
                    }
                } catch (Exception $e) {}
            } elseif ($is_admin_view) {
                try {
                    $st_ex = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name, username FROM users WHERE LOWER(COALESCE(role,'staff')) NOT IN ('staff', 'manager') AND id != ?");
                    $st_ex->execute([$viewer_user_id]);
                    foreach ($st_ex->fetchAll(PDO::FETCH_ASSOC) ?: [] as $eu) {
                        if (!empty($eu['name']))     $excluded_names[] = strtolower(trim($eu['name']));
                        if (!empty($eu['username'])) $excluded_names[] = strtolower(trim($eu['username']));
                    }
                } catch (Exception $e) {}
            } elseif ($is_staff_view) {
                try {
                    $st_ex = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name, username FROM users WHERE id != ?");
                    $st_ex->execute([$viewer_user_id]);
                    foreach ($st_ex->fetchAll(PDO::FETCH_ASSOC) ?: [] as $eu) {
                        if (!empty($eu['name']))     $excluded_names[] = strtolower(trim($eu['name']));
                        if (!empty($eu['username'])) $excluded_names[] = strtolower(trim($eu['username']));
                    }
                } catch (Exception $e) {}
            }
            $excluded_names = array_values(array_filter(array_unique($excluded_names)));

            // Fetch staff list for dropdown filter
            $staff_list = [];
            try {
                $st_q = ($station_id > 0) ? "WHERE station_id = ? AND status = 'Active'" : "WHERE status = 'Active'";
                $st_p = ($station_id > 0) ? [$station_id] : [];
                if ($is_manager_view) {
                    $st_q .= " AND (LOWER(COALESCE(role,'staff')) = 'staff' OR id = ?)";
                    $st_p[] = $viewer_user_id;
                } elseif ($is_admin_view) {
                    $st_q .= " AND (LOWER(COALESCE(role,'staff')) IN ('staff', 'manager') OR id = ?)";
                    $st_p[] = $viewer_user_id;
                } elseif ($is_staff_view) {
                    $st_q .= " AND id = ?";
                    $st_p[] = $viewer_user_id;
                }
                $sl = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS full_name, role FROM users $st_q ORDER BY full_name");
                $sl->execute($st_p);
                $staff_list = $sl->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
            $data['staff_list'] = $staff_list;

            // ── 1. TRANSACTION LOGS ──────────────────────────────────────────
            if ($tab === 'transaction_logs') {
                $raw = [];
                $seen_tx = [];

                // 1. Merchandise Transactions
                if (ard_table_exists($pdo, 'merchandise_transactions')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(mt.created_at) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND mt.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND mt.staff_id = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR mt.staff_id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT mt.created_at AS datetime,
                            COALESCE(NULLIF(mt.transaction_id,''), CONCAT('TRX-',mt.id)) AS ref_no,
                            'Merchandise' AS module,
                            CASE
                                WHEN mt.void_reason IS NOT NULL AND TRIM(mt.void_reason)!='' THEN 'Void Requested'
                                WHEN mt.adjustment_reason IS NOT NULL AND TRIM(mt.adjustment_reason)!='' THEN 'Adjustment Requested'
                                WHEN LOWER(COALESCE(mt.transaction_type,'')) LIKE '%return%' THEN 'Processed Return'
                                WHEN LOWER(COALESCE(mt.transaction_type,'')) LIKE '%void%' THEN 'Voided Transaction'
                                WHEN LOWER(COALESCE(mt.transaction_type,'')) LIKE '%refund%' THEN 'Refunded Transaction'
                                ELSE 'Created Transaction'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, CONCAT('Staff #',mt.staff_id)) AS performed_by,
                            CONCAT('Customer: ', COALESCE(mt.customer_name,'Walk-in'), ' | ', COALESCE(mt.payment_method,'Cash')) AS details,
                            COALESCE(mt.total_amount, 0) AS total_amount,
                            CASE
                                WHEN LOWER(COALESCE(mt.validation_status,'')) IN ('voided','rejected','cancelled') THEN 'Cancelled'
                                WHEN LOWER(COALESCE(mt.validation_status,'')) IN ('verified','approved','completed','submitted','paid') THEN 'Completed'
                                ELSE 'Pending'
                            END AS status,
                            mt.staff_id AS user_id
                        FROM merchandise_transactions mt
                        LEFT JOIN users u ON u.id = mt.staff_id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_mt = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_mt as $r) {
                            $raw[] = $r;
                            $seen_tx[$r['user_id'].'|'.substr($r['datetime'],0,16).'|merch'] = true;
                        }
                    } catch (Exception $e) {}
                }

                // 2. Fuel Meter Readings / Fuel Transactions
                if (ard_table_exists($pdo, 'fuel_transactions')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(ft.transaction_date,ft.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND ft.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND ft.staff_id = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR ft.staff_id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(ft.transaction_date, ft.created_at) AS datetime,
                            COALESCE(NULLIF(ft.transaction_id,''), CONCAT('FTX-',ft.id)) AS ref_no,
                            'Fuel Management' AS module,
                            'Fuel Meter Reading' AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, CONCAT('Staff #',ft.staff_id)) AS performed_by,
                            CONCAT('Fuel: ', COALESCE(ft.fuel_type,'N/A'), ' | Vol: ', FORMAT(COALESCE(ft.liters_sold,0),2), 'L | Pump: ', COALESCE(ft.pump_number,ft.pump_id,'N/A')) AS details,
                            COALESCE(ft.total_amount, 0) AS total_amount,
                            CASE
                                WHEN LOWER(COALESCE(ft.status,'')) IN ('voided','rejected','cancelled') THEN 'Cancelled'
                                WHEN LOWER(COALESCE(ft.status,'')) IN ('verified','approved','validated') THEN 'Completed'
                                ELSE 'Pending'
                            END AS status,
                            ft.staff_id AS user_id
                        FROM fuel_transactions ft
                        LEFT JOIN users u ON u.id = ft.staff_id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_ft = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_ft as $r) {
                            $raw[] = $r;
                            $seen_tx[$r['user_id'].'|'.substr($r['datetime'],0,16).'|fuel'] = true;
                        }
                    } catch (Exception $e) {}
                }

                // 3. Job Orders
                if (ard_table_exists($pdo, 'job_orders')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(jo.created_at) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND jo.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (jo.user_id = ? OR jo.created_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR u.id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT jo.created_at AS datetime,
                            COALESCE(NULLIF(jo.job_order_id,''), COALESCE(NULLIF(jo.job_order_number,''), CONCAT('JO-',jo.id))) AS ref_no,
                            'Job Orders' AS module,
                            CASE WHEN jo.updated_at > jo.created_at THEN 'Updated Job Order' ELSE 'Created Job Order' END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, CONCAT('User #',jo.created_by)) AS performed_by,
                            CONCAT('Service: ', COALESCE(jo.service_type,'N/A'), ' | Plate: ', COALESCE(jo.vehicle_plate,'N/A'), ' | Cust: ', COALESCE(jo.customer_name,'N/A')) AS details,
                            COALESCE(jo.total_cost, jo.estimated_cost, 0) AS total_amount,
                            CASE
                                WHEN LOWER(COALESCE(jo.status,'')) IN ('cancelled','rejected') THEN 'Cancelled'
                                WHEN LOWER(COALESCE(jo.status,'')) IN ('completed','released','approved','verified') THEN 'Completed'
                                ELSE 'Pending'
                            END AS status,
                            COALESCE(jo.created_by, jo.user_id) AS user_id
                        FROM job_orders jo
                        LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_jo = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_jo as $r) {
                            $raw[] = $r;
                            $seen_tx[$r['user_id'].'|'.substr($r['datetime'],0,16).'|jo'] = true;
                        }
                    } catch (Exception $e) {}
                }

                // 4. Fuel Sales Closing / Shift Reports
                if (ard_table_exists($pdo, 'shift_reports')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(sr.created_at) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND sr.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (sr.user_id = ? OR sr.created_by = ? OR sr.staff_id = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR u.id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT sr.created_at AS datetime,
                            CONCAT('FSC-',sr.id) AS ref_no,
                            'Fuel Sales Closing' AS module,
                            'Submitted Shift Closing' AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, 'Staff') AS performed_by,
                            CONCAT('Shift: ', COALESCE(sr.shift,'N/A'), ' | Report Date: ', COALESCE(sr.report_date,'N/A')) AS details,
                            COALESCE(sr.total_sales, sr.net_sales, 0) AS total_amount,
                            CASE
                                WHEN LOWER(COALESCE(sr.status,'')) IN ('rejected','cancelled') THEN 'Cancelled'
                                WHEN LOWER(COALESCE(sr.status,'')) IN ('finalized','approved','completed') THEN 'Completed'
                                ELSE 'Pending'
                            END AS status,
                            COALESCE(sr.user_id, sr.created_by, sr.staff_id) AS user_id
                        FROM shift_reports sr
                        LEFT JOIN users u ON u.id = COALESCE(sr.user_id, sr.created_by, sr.staff_id)
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_sr = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_sr as $r) {
                            $raw[] = $r;
                            $seen_tx[$r['user_id'].'|'.substr($r['datetime'],0,16).'|fsc'] = true;
                        }
                    } catch (Exception $e) {}
                }

                // 5. Transaction Adjustments
                if (ard_table_exists($pdo, 'transaction_adjustments')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(ta.adjustment_date, ta.created_at, NOW())) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND ta.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND ta.adjusted_by = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR ta.adjusted_by = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(ta.adjustment_date, ta.created_at, NOW()) AS datetime,
                            CONCAT('ADJ-',ta.id) AS ref_no,
                            'Sales Adjustments' AS module,
                            'Adjustment Requested' AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, CONCAT('User #',ta.adjusted_by)) AS performed_by,
                            CONCAT('Target Txn: ', COALESCE(ta.transaction_id,'N/A'), ' | Reason: ', COALESCE(ta.adjustment_reason,'N/A')) AS details,
                            COALESCE(ta.amount_difference, 0) AS total_amount,
                            'Pending' AS status,
                            ta.adjusted_by AS user_id
                        FROM transaction_adjustments ta
                        LEFT JOIN users u ON u.id = ta.adjusted_by
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_ta = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_ta as $r) {
                            $raw[] = $r;
                        }
                    } catch (Exception $e) {}
                }

                // 6. Reports Generation / Exports / Edits from Activity Logs
                if (ard_table_exists($pdo, 'activity_logs')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(al.created_at) BETWEEN ? AND ?
                              AND (
                                LOWER(al.action) LIKE '%report%'
                                OR LOWER(al.action) LIKE '%export%'
                                OR LOWER(al.action) LIKE '%print%'
                                OR LOWER(al.action) LIKE '%transaction%'
                                OR LOWER(al.action) LIKE '%void%'
                                OR LOWER(al.action) LIKE '%edit%'
                                OR LOWER(al.action) LIKE '%status%'
                              )";
                        if ($station_id > 0) { $w .= " AND u.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND al.user_id = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR al.user_id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT al.created_at AS datetime,
                            CONCAT('ACT-',al.id) AS ref_no,
                            CASE
                                WHEN LOWER(al.action) LIKE '%report%' OR LOWER(al.action) LIKE '%export%' OR LOWER(al.action) LIKE '%print%' THEN 'Reports'
                                WHEN LOWER(al.action) LIKE '%fuel%' THEN 'Fuel Management'
                                WHEN LOWER(al.action) LIKE '%job%' THEN 'Job Orders'
                                ELSE 'Merchandise'
                            END AS module,
                            COALESCE(al.action, 'Action') AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, CONCAT('User #',al.user_id)) AS performed_by,
                            COALESCE(al.details, 'Activity recorded') AS details,
                            0 AS total_amount,
                            'Completed' AS status,
                            al.user_id
                        FROM activity_logs al
                        INNER JOIN users u ON u.id = al.user_id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_al = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_al as $r) {
                            $act_lc  = strtolower($r['action']);
                            $user_id = $r['user_id'];
                            $min     = substr($r['datetime'], 0, 16);

                            // Omit redundant generic transaction saves already captured
                            if (str_contains($act_lc, 'merchandise transaction') && isset($seen_tx["$user_id|$min|merch"])) continue;
                            if (str_contains($act_lc, 'fuel reading') && isset($seen_tx["$user_id|$min|fuel"])) continue;

                            $raw[] = $r;
                        }
                    } catch (Exception $e) {}
                }

                // PHP-side filter & sort
                $filtered = [];
                foreach ($raw as $r) {
                    if (!empty($excluded_names)) {
                        $perf = strtolower(trim($r['performed_by'] ?? ''));
                        $skip_actor = false;
                        foreach ($excluded_names as $ex) {
                            if ($ex !== '' && ($perf === $ex || str_contains($perf, $ex))) { $skip_actor = true; break; }
                        }
                        if ($skip_actor) continue;
                    }
                    if ($filter_mod  !== '' && strtolower($r['module']) !== strtolower($filter_mod)) continue;
                    if ($filter_stat !== '' && strtolower($r['status']) !== strtolower($filter_stat)) continue;
                    if ($filter_srch !== '') {
                        $hay = strtolower($r['ref_no'].' '.$r['module'].' '.$r['action'].' '.$r['performed_by'].' '.$r['details']);
                        if (strpos($hay, strtolower($filter_srch)) === false) continue;
                    }
                    $filtered[] = $r;
                }

                usort($filtered, fn($a,$b) => strtotime($b['datetime']) <=> strtotime($a['datetime']));
                $unique = []; $seen = [];
                foreach ($filtered as $r) {
                    $k = ($r['user_id'] ?? '') . '|' . substr($r['datetime'],0,16) . '|' . strtolower($r['module']) . '|' . strtolower($r['action']) . '|' . strtolower(substr($r['details'],0,30));
                    if (!isset($seen[$k])) { $seen[$k] = true; $unique[] = $r; }
                }
                $data['rows'] = $unique;

            // ── 2. INVENTORY LOGS ────────────────────────────────────────────
            } elseif ($tab === 'inventory_logs') {
                $raw = [];
                $seen_inv = [];

                // 1. Inventory Logs table
                if (ard_table_exists($pdo, 'inventory_logs')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(il.created_at) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND (p.station_id = ? OR u.station_id = ?)"; $p[] = $station_id; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND il.user_id = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR il.user_id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT il.created_at AS datetime,
                            CONCAT('INV-',il.id) AS ref_no,
                            COALESCE(NULLIF(p.name,''), CONCAT('Product #', il.product_id), 'N/A') AS product,
                            COALESCE(p.sku, 'N/A') AS sku,
                            CASE
                                WHEN LOWER(COALESCE(il.action,'')) LIKE '%stock in%' OR LOWER(COALESCE(il.action,'')) LIKE '%stockin%' THEN 'Stock In'
                                WHEN LOWER(COALESCE(il.action,'')) LIKE '%stock out%' OR LOWER(COALESCE(il.action,'')) LIKE '%stockout%' THEN 'Stock Out'
                                WHEN LOWER(COALESCE(il.action,'')) LIKE '%adjust%' THEN 'Inventory Adjustment'
                                WHEN LOWER(COALESCE(il.action,'')) LIKE '%expir%' THEN 'Expired Products'
                                WHEN LOWER(COALESCE(il.action,'')) LIKE '%damage%' THEN 'Damaged Products'
                                WHEN LOWER(COALESCE(il.action,'')) LIKE '%count%' OR LOWER(COALESCE(il.action,'')) LIKE '%physical%' THEN 'Physical Count'
                                ELSE COALESCE(il.action, 'Movement')
                            END AS movement_type,
                            COALESCE(il.quantity_before, 0) AS quantity_before,
                            COALESCE(il.quantity_after, 0) AS quantity_after,
                            COALESCE(il.quantity_change, il.quantity_after - il.quantity_before, 0) AS quantity_change,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, 'System') AS performed_by,
                            COALESCE(il.notes, 'Inventory movement recorded') AS details,
                            'Completed' AS status,
                            il.user_id
                        FROM inventory_logs il
                        LEFT JOIN products p ON il.product_id = p.id
                        LEFT JOIN users u ON il.user_id = u.id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_il = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_il as $r) {
                            $raw[] = $r;
                            $seen_inv[$r['user_id'].'|'.substr($r['datetime'],0,16).'|'.$r['product']] = true;
                        }
                    } catch (Exception $e) {}
                }

                // 2. Stock Requests (Staff requests & manager approved changes)
                if (ard_table_exists($pdo, 'stock_requests')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(sr.created_at) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND sr.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND sr.staff_id = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR sr.staff_id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT sr.created_at AS datetime,
                            COALESCE(NULLIF(sr.request_no,''), CONCAT('STK-',sr.id)) AS ref_no,
                            COALESCE(sr.item_name, 'Stock Item') AS product,
                            'N/A' AS sku,
                            'Stock Request' AS movement_type,
                            0 AS quantity_before,
                            COALESCE(sr.requested_quantity, 0) AS quantity_after,
                            COALESCE(sr.requested_quantity, 0) AS quantity_change,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, CONCAT('Staff #',sr.staff_id)) AS performed_by,
                            CONCAT('Status: ', COALESCE(sr.status,'Pending'), ' | Reason: ', COALESCE(sr.reason,'N/A')) AS details,
                            COALESCE(sr.status, 'Pending') AS status,
                            sr.staff_id AS user_id
                        FROM stock_requests sr
                        LEFT JOIN users u ON u.id = sr.staff_id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_sr = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_sr as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // 3. Stock-In Records & Approved Deliveries
                if (ard_table_exists($pdo, 'stock_in_records')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(si.received_at) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND si.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND si.received_by = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR si.received_by = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT si.received_at AS datetime,
                            CONCAT('SIN-',si.id) AS ref_no,
                            COALESCE(si.product_name, 'Merchandise') AS product,
                            'N/A' AS sku,
                            'Stock-In Approved' AS movement_type,
                            0 AS quantity_before,
                            COALESCE(si.quantity_received, 0) AS quantity_after,
                            COALESCE(si.quantity_received, 0) AS quantity_change,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, 'Manager') AS performed_by,
                            CONCAT('Supplier: ', COALESCE(si.supplier_name,'N/A'), ' | Batch: ', COALESCE(si.batch_number,'N/A')) AS details,
                            CASE WHEN LOWER(COALESCE(si.status,'')) IN ('verified','approved','received') THEN 'Completed' ELSE 'Pending' END AS status,
                            si.received_by AS user_id
                        FROM stock_in_records si
                        LEFT JOIN users u ON u.id = si.received_by
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_si = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_si as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // 4. Fuel Deliveries (Tanker bulk receiving)
                if (ard_table_exists($pdo, 'fuel_deliveries')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(fd.delivery_date, fd.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND fd.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (fd.received_by = ? OR fd.verified_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR fd.received_by = ? OR fd.verified_by = ?)"; $p[] = $viewer_user_id; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(fd.delivery_date, fd.created_at) AS datetime,
                            COALESCE(NULLIF(fd.invoice_no,''), CONCAT('FD-',fd.id)) AS ref_no,
                            COALESCE(fd.fuel_type, 'Fuel') AS product,
                            'N/A' AS sku,
                            'Fuel Delivery' AS movement_type,
                            0 AS quantity_before,
                            COALESCE(fd.delivery_liters, 0) AS quantity_after,
                            COALESCE(fd.delivery_liters, 0) AS quantity_change,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, 'Manager') AS performed_by,
                            CONCAT('Supplier: ', COALESCE(fd.supplier,'N/A'), ' | Tank Assigned: ', COALESCE(fd.tank_assigned,'N/A'), ' | Tanker: ', COALESCE(fd.tanker_number,'N/A')) AS details,
                            CASE WHEN LOWER(COALESCE(fd.status,'')) IN ('verified','approved','received','completed') THEN 'Completed' ELSE 'Pending' END AS status,
                            COALESCE(fd.received_by, fd.verified_by) AS user_id
                        FROM fuel_deliveries fd
                        LEFT JOIN users u ON u.id = COALESCE(fd.received_by, fd.verified_by)
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_fd = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_fd as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // PHP-side filter & sort
                $filtered = [];
                foreach ($raw as $r) {
                    if (!empty($excluded_names)) {
                        $perf = strtolower(trim($r['performed_by'] ?? ''));
                        $skip_actor = false;
                        foreach ($excluded_names as $ex) {
                            if ($ex !== '' && ($perf === $ex || str_contains($perf, $ex))) { $skip_actor = true; break; }
                        }
                        if ($skip_actor) continue;
                    }
                    if ($filter_act  !== '' && strtolower($r['movement_type']) !== strtolower($filter_act)) continue;
                    if ($filter_srch !== '') {
                        $hay = strtolower($r['ref_no'].' '.$r['product'].' '.$r['movement_type'].' '.$r['performed_by'].' '.$r['details']);
                        if (strpos($hay, strtolower($filter_srch)) === false) continue;
                    }
                    $filtered[] = $r;
                }

                usort($filtered, fn($a,$b) => strtotime($b['datetime']) <=> strtotime($a['datetime']));
                $unique = []; $seen = [];
                foreach ($filtered as $r) {
                    $k = ($r['user_id'] ?? '') . '|' . substr($r['datetime'],0,16) . '|' . strtolower($r['product']) . '|' . strtolower($r['movement_type']) . '|' . $r['quantity_change'];
                    if (!isset($seen[$k])) { $seen[$k] = true; $unique[] = $r; }
                }
                $data['rows'] = $unique;

            // ── 3. APPROVAL LOGS ────────────────────────────────────────────
            } elseif ($tab === 'approval_logs') {
                $raw = [];

                // 1. Customer Registration & Loyalty Requests
                if (ard_table_exists($pdo, 'customer_requests')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(cr.reviewed_at, cr.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND cr.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (cr.requested_by = ? OR cr.reviewed_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND cr.requested_by = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(cr.reviewed_at, cr.created_at) AS datetime,
                            CONCAT('CR-', cr.id) AS request_no,
                            'Customer Registration' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(cr.status,'')) IN ('rejected','cancelled') THEN 'Rejected'
                                WHEN LOWER(COALESCE(cr.status,'')) IN ('approved','completed','verified') THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager') AS reviewed_by,
                            CONCAT('Customer: ', cr.first_name, ' ', COALESCE(cr.last_name,''), ' | Plate: ', COALESCE(cr.vehicle_plate,'N/A'), ' | Reason: ', COALESCE(cr.request_reason,'N/A'), CASE WHEN cr.manager_remarks IS NOT NULL AND TRIM(cr.manager_remarks)!='' THEN CONCAT(' | Remarks: ', cr.manager_remarks) ELSE '' END) AS details,
                            COALESCE(cr.status, 'Pending') AS status
                        FROM customer_requests cr
                        LEFT JOIN users u1 ON u1.id = cr.requested_by
                        LEFT JOIN users u2 ON u2.id = cr.reviewed_by
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 2. Job Orders Approvals & Reviews
                if (ard_table_exists($pdo, 'job_orders')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(jo.reviewed_at, jo.approved_at, jo.updated_at, jo.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND jo.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (jo.user_id = ? OR jo.created_by = ? OR jo.approved_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND (jo.user_id = ? OR jo.created_by = ?)"; $p[] = $viewer_user_id; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(jo.reviewed_at, jo.approved_at, jo.updated_at, jo.created_at) AS datetime,
                            COALESCE(NULLIF(jo.job_order_number,''), NULLIF(jo.job_order_id,''), CONCAT('JO-',jo.id)) AS request_no,
                            'Job Order Approval' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(jo.status,'')) IN ('rejected','cancelled') OR (jo.rejection_reason IS NOT NULL AND TRIM(jo.rejection_reason)!='') THEN 'Rejected'
                                WHEN LOWER(COALESCE(jo.status,'')) IN ('approved','verified','completed','reviewed','finalized') OR jo.approved_by IS NOT NULL THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager') AS reviewed_by,
                            CONCAT('Service: ', COALESCE(jo.service_type, jo.service_description, 'Job Order'), ' | Vehicle: ', COALESCE(jo.vehicle_plate, 'N/A'), ' | Customer: ', COALESCE(jo.customer_name, 'Walk-in'), CASE WHEN jo.rejection_reason IS NOT NULL AND TRIM(jo.rejection_reason)!='' THEN CONCAT(' | Reason: ', jo.rejection_reason) WHEN jo.manager_remarks IS NOT NULL AND TRIM(jo.manager_remarks)!='' THEN CONCAT(' | Remarks: ', jo.manager_remarks) ELSE '' END) AS details,
                            COALESCE(jo.status, 'Pending') AS status
                        FROM job_orders jo
                        LEFT JOIN users u1 ON u1.id = COALESCE(jo.user_id, jo.created_by)
                        LEFT JOIN users u2 ON u2.id = COALESCE(jo.approved_by, jo.reviewed_by, jo.validated_by)
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 3. Merchandise Transactions (Validations, Void & Adjustment Requests)
                if (ard_table_exists($pdo, 'merchandise_transactions')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(mt.validated_at, mt.updated_at, mt.created_at)) BETWEEN ? AND ? AND (mt.void_reason IS NOT NULL AND TRIM(mt.void_reason)!='' OR mt.adjustment_reason IS NOT NULL AND TRIM(mt.adjustment_reason)!='' OR LOWER(COALESCE(mt.validation_status,'')) IN ('rejected','cancelled','pending'))";
                        if ($station_id > 0) { $w .= " AND mt.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (mt.staff_id = ? OR mt.validated_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND mt.staff_id = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(mt.validated_at, mt.updated_at, mt.created_at) AS datetime,
                            COALESCE(NULLIF(mt.transaction_id,''), CONCAT('TXN-',mt.id)) AS request_no,
                            CASE
                                WHEN (mt.void_reason IS NOT NULL AND TRIM(mt.void_reason)!='') THEN 'Void Request'
                                WHEN (mt.adjustment_reason IS NOT NULL AND TRIM(mt.adjustment_reason)!='') THEN 'Adjustment Request'
                                ELSE 'Transaction Validation'
                            END AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(mt.validation_status,'')) IN ('rejected','cancelled') OR (mt.rejection_reason IS NOT NULL AND TRIM(mt.rejection_reason)!='') THEN 'Rejected'
                                WHEN LOWER(COALESCE(mt.validation_status,'')) IN ('official','completed','approved','verified','voided') THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, CASE WHEN LOWER(COALESCE(mt.validation_status,'')) IN ('official','completed','approved') THEN 'Manager / Admin' ELSE '—' END) AS reviewed_by,
                            CONCAT(
                                CASE WHEN mt.void_reason IS NOT NULL AND TRIM(mt.void_reason)!='' THEN CONCAT('Void Reason: ', mt.void_reason, ' | ')
                                     WHEN mt.rejection_reason IS NOT NULL AND TRIM(mt.rejection_reason)!='' THEN CONCAT('Reject Reason: ', mt.rejection_reason, ' | ')
                                     WHEN mt.adjustment_reason IS NOT NULL AND TRIM(mt.adjustment_reason)!='' THEN CONCAT('Adj Reason: ', mt.adjustment_reason, ' | ')
                                     ELSE '' END,
                                'Customer: ', COALESCE(mt.customer_name, 'Walk-in'),
                                ' | Amount: ₱', FORMAT(COALESCE(mt.total_amount,0),2)
                            ) AS details,
                            COALESCE(mt.validation_status, mt.workflow_status, 'Pending') AS status
                        FROM merchandise_transactions mt
                        LEFT JOIN users u1 ON u1.id = mt.staff_id
                        LEFT JOIN users u2 ON u2.id = mt.validated_by
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 4. Fuel Transactions Validations
                if (ard_table_exists($pdo, 'fuel_transactions')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(ft.validated_at, ft.transaction_date, ft.created_at)) BETWEEN ? AND ? AND (ft.reject_reason IS NOT NULL AND TRIM(ft.reject_reason)!='' OR LOWER(COALESCE(ft.status,'')) IN ('rejected','cancelled','pending'))";
                        if ($station_id > 0) { $w .= " AND ft.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (ft.staff_id = ? OR ft.validated_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND ft.staff_id = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(ft.validated_at, ft.transaction_date, ft.created_at) AS datetime,
                            COALESCE(NULLIF(ft.transaction_id,''), CONCAT('FUEL-',ft.id)) AS request_no,
                            'Fuel Validation' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(ft.status,'')) IN ('rejected','cancelled') OR (ft.reject_reason IS NOT NULL AND TRIM(ft.reject_reason)!='') THEN 'Rejected'
                                WHEN LOWER(COALESCE(ft.status,'')) IN ('validated','approved','completed','official') THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, CASE WHEN LOWER(COALESCE(ft.status,'')) IN ('validated','approved','completed','official') THEN 'Manager' ELSE '—' END) AS reviewed_by,
                            CONCAT('Fuel: ', COALESCE(ft.fuel_type,'N/A'), ' | Liters: ', FORMAT(COALESCE(ft.liters_sold,0),2), ' L | Amount: ₱', FORMAT(COALESCE(ft.total_amount,0),2), CASE WHEN ft.reject_reason IS NOT NULL AND TRIM(ft.reject_reason)!='' THEN CONCAT(' | Reject Reason: ', ft.reject_reason) ELSE '' END) AS details,
                            COALESCE(ft.status, 'Pending') AS status
                        FROM fuel_transactions ft
                        LEFT JOIN users u1 ON u1.id = ft.staff_id
                        LEFT JOIN users u2 ON u2.id = COALESCE(ft.validated_by, ft.manager_id)
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 5. Daily Fuel Sales Closing (Shift Readings Submission)
                if (ard_table_exists($pdo, 'fuel_sales_closing')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(fsc.encoded_at, fsc.report_date)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND fsc.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (fsc.encoded_by = ? OR fsc.verified_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND fsc.encoded_by = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(fsc.encoded_at, fsc.report_date) AS datetime,
                            CONCAT('FSC-', fsc.id) AS request_no,
                            'Daily Shift Closing Validation' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(fsc.status,'')) IN ('rejected','cancelled') THEN 'Rejected'
                                WHEN LOWER(COALESCE(fsc.status,'')) IN ('verified','official','approved','completed') THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, CASE WHEN LOWER(COALESCE(fsc.status,'')) IN ('verified','official','approved','completed') THEN 'Manager' ELSE '—' END) AS reviewed_by,
                            CONCAT('Date: ', fsc.report_date, ' | Shift: ', COALESCE(fsc.shift,'N/A'), ' | Fuel: ₱', FORMAT(COALESCE(fsc.total_fuel_sales,0),2), ' | Store: ₱', FORMAT(COALESCE(fsc.total_store_sales,0),2)) AS details,
                            COALESCE(fsc.status, 'Pending') AS status
                        FROM fuel_sales_closing fsc
                        LEFT JOIN users u1 ON u1.id = fsc.encoded_by
                        LEFT JOIN users u2 ON u2.id = COALESCE(fsc.verified_by, fsc.checked_by)
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 6. Purchase Orders (Merchandise)
                if (ard_table_exists($pdo, 'purchase_orders')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(po.approved_at, po.admin_finalized_at, po.updated_at, po.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND po.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (po.created_by = ? OR po.approved_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND po.created_by = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(po.approved_at, po.admin_finalized_at, po.updated_at, po.created_at) AS datetime,
                            COALESCE(NULLIF(po.po_number,''), CONCAT('PO-',po.id)) AS request_no,
                            'Purchase Order' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(po.status,'')) LIKE '%reject%' OR (po.rejection_reason IS NOT NULL AND TRIM(po.rejection_reason)!='') THEN 'Rejected'
                                WHEN LOWER(COALESCE(po.status,'')) LIKE '%approv%' OR LOWER(COALESCE(po.status,'')) IN ('confirmed','received','completed','official') THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager / Admin') AS reviewed_by,
                            CONCAT('PO: ', COALESCE(po.product_name, po.type, 'Merchandise PO'), ' | Qty: ', COALESCE(po.quantity,0), ' | Total: ₱', FORMAT(COALESCE(po.total_amount,0),2), CASE WHEN po.rejection_reason IS NOT NULL AND TRIM(po.rejection_reason)!='' THEN CONCAT(' | Reason: ', po.rejection_reason) ELSE '' END) AS details,
                            COALESCE(po.status, 'Pending') AS status
                        FROM purchase_orders po
                        LEFT JOIN users u1 ON u1.id = po.created_by
                        LEFT JOIN users u2 ON u2.id = COALESCE(po.approved_by, po.admin_id)
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 7. Fuel Purchase Orders
                if (ard_table_exists($pdo, 'fuel_purchase_orders')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(fpo.approved_at, fpo.updated_at, fpo.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND fpo.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (fpo.created_by = ? OR fpo.approved_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND fpo.created_by = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(fpo.approved_at, fpo.updated_at, fpo.created_at) AS datetime,
                            COALESCE(NULLIF(fpo.po_number,''), CONCAT('FPO-',fpo.id)) AS request_no,
                            'Fuel Purchase Order' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(fpo.status,'')) LIKE '%reject%' THEN 'Rejected'
                                WHEN LOWER(COALESCE(fpo.status,'')) LIKE '%approv%' OR fpo.approved_by IS NOT NULL THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager / Admin') AS reviewed_by,
                            CONCAT('Volume: ', FORMAT(COALESCE(fpo.volume,0),2), ' L | Total: ₱', FORMAT(COALESCE(fpo.total_amount,0),2)) AS details,
                            COALESCE(fpo.status, 'Pending') AS status
                        FROM fuel_purchase_orders fpo
                        LEFT JOIN users u1 ON u1.id = fpo.created_by
                        LEFT JOIN users u2 ON u2.id = fpo.approved_by
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 8. Merchandise Stock Requests
                if (ard_table_exists($pdo, 'stock_requests')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(sr.processed_at, sr.updated_at, sr.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND sr.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (sr.staff_id = ? OR sr.manager_id = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND sr.staff_id = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(sr.processed_at, sr.updated_at, sr.created_at) AS datetime,
                            COALESCE(NULLIF(sr.request_no,''), CONCAT('SR-',sr.id)) AS request_no,
                            'Stock Request Approval' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(sr.status,'')) IN ('rejected','cancelled') THEN 'Rejected'
                                WHEN LOWER(COALESCE(sr.status,'')) IN ('approved','fulfilled') THEN 'Approved'
                                WHEN LOWER(COALESCE(sr.status,'')) LIKE '%revis%' OR LOWER(COALESCE(sr.status,'')) = 'returned' THEN 'Request Revision'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, CONCAT('Staff #',sr.staff_id)) AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager') AS reviewed_by,
                            CONCAT('Item: ', COALESCE(sr.item_name,'N/A'), ' | Qty: ', COALESCE(sr.requested_quantity,0), ' | Reason: ', COALESCE(sr.remarks, sr.manager_notes, 'N/A')) AS details,
                            COALESCE(sr.status, 'Pending') AS status
                        FROM stock_requests sr
                        LEFT JOIN users u1 ON u1.id = sr.staff_id
                        LEFT JOIN users u2 ON u2.id = sr.manager_id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 9. Fuel Stock Requests
                if (ard_table_exists($pdo, 'fuel_stock_requests')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(fsr.processed_at, fsr.updated_at, fsr.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND fsr.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (fsr.staff_id = ? OR fsr.manager_id = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND fsr.staff_id = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(fsr.processed_at, fsr.updated_at, fsr.created_at) AS datetime,
                            COALESCE(NULLIF(fsr.request_no,''), CONCAT('FSR-',fsr.id)) AS request_no,
                            'Fuel Stock Request' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(fsr.status,'')) IN ('rejected','cancelled') THEN 'Rejected'
                                WHEN LOWER(COALESCE(fsr.status,'')) IN ('approved','fulfilled') THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager') AS reviewed_by,
                            CONCAT('Fuel: ', COALESCE(fsr.fuel_type,'N/A'), ' | Requested: ', FORMAT(COALESCE(fsr.requested_liters,0),2), ' L | Approved: ', FORMAT(COALESCE(fsr.approved_liters,0),2), ' L') AS details,
                            COALESCE(fsr.status, 'Pending') AS status
                        FROM fuel_stock_requests fsr
                        LEFT JOIN users u1 ON u1.id = fsr.staff_id
                        LEFT JOIN users u2 ON u2.id = fsr.manager_id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 10. Merchandise Adjustments (Inventory Reconciliation)
                if (ard_table_exists($pdo, 'merchandise_adjustments')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(ma.approved_at, ma.updated_at, ma.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND ma.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (ma.requested_by = ? OR ma.approved_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND ma.requested_by = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(ma.approved_at, ma.updated_at, ma.created_at) AS datetime,
                            CONCAT('MADJ-', ma.id) AS request_no,
                            'Stock Adjustment Approval' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(ma.status,'')) = 'rejected' THEN 'Rejected'
                                WHEN LOWER(COALESCE(ma.status,'')) = 'approved' THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager') AS reviewed_by,
                            CONCAT('Product: ', COALESCE(ma.product_name, ma.sku, 'Item'), ' | Change: ', COALESCE(ma.quantity_change,0), ' | Reason: ', COALESCE(ma.reason, ma.rejection_reason, 'N/A')) AS details,
                            COALESCE(ma.status, 'Pending') AS status
                        FROM merchandise_adjustments ma
                        LEFT JOIN users u1 ON u1.id = ma.requested_by
                        LEFT JOIN users u2 ON u2.id = ma.approved_by
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 11. Fuel Adjustments (Variance / Calibration)
                if (ard_table_exists($pdo, 'fuel_adjustments')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(fa.approved_at, fa.updated_at, fa.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND fa.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (fa.user_id = ? OR fa.approved_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND fa.user_id = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(fa.approved_at, fa.updated_at, fa.created_at) AS datetime,
                            CONCAT('FADJ-', fa.id) AS request_no,
                            'Fuel Adjustment Approval' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(fa.status,'')) = 'rejected' THEN 'Rejected'
                                WHEN LOWER(COALESCE(fa.status,'')) = 'approved' THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager') AS reviewed_by,
                            CONCAT('Fuel: ', COALESCE(fa.fuel_type,'N/A'), ' | Liters: ', FORMAT(COALESCE(fa.liters,0),2), ' | Reason: ', COALESCE(fa.reason,'N/A')) AS details,
                            COALESCE(fa.status, 'Pending') AS status
                        FROM fuel_adjustments fa
                        LEFT JOIN users u1 ON u1.id = fa.user_id
                        LEFT JOIN users u2 ON u2.id = fa.approved_by
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 12. Deliveries Oversight (Discrepancy Resolution / Returns)
                if (ard_table_exists($pdo, 'deliveries_oversight')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(do.resolved_at, do.finalized_at, do.manager_action_at, do.admin_action_at, do.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND do.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (do.encoded_by = ? OR do.resolved_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND do.encoded_by = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(do.resolved_at, do.finalized_at, do.manager_action_at, do.admin_action_at, do.created_at) AS datetime,
                            COALESCE(NULLIF(do.dr_number,''), NULLIF(do.delivery_ref,''), CONCAT('DEL-',do.id)) AS request_no,
                            CONCAT(UCASE(SUBSTRING(do.delivery_type,1,1)),LOWER(SUBSTRING(do.delivery_type,2)), ' Delivery Resolution') AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(do.status,'')) IN ('approved','resolved','finalized','accepted') OR do.resolution_action = 'Accept' THEN 'Approved'
                                WHEN LOWER(COALESCE(do.status,'')) IN ('rejected','returned') OR do.resolution_action = 'Reject' THEN 'Rejected'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager / Admin') AS reviewed_by,
                            CONCAT('Supplier: ', COALESCE(do.supplier,'N/A'), ' | Product: ', COALESCE(do.product,'N/A'), ' | Qty: ', FORMAT(COALESCE(do.quantity,0),2), CASE WHEN do.return_reason IS NOT NULL AND TRIM(do.return_reason)!='' THEN CONCAT(' | Return Reason: ', do.return_reason) WHEN do.remarks IS NOT NULL AND TRIM(do.remarks)!='' THEN CONCAT(' | Remarks: ', do.remarks) ELSE '' END) AS details,
                            COALESCE(do.status, 'Pending') AS status
                        FROM deliveries_oversight do
                        LEFT JOIN users u1 ON u1.id = do.encoded_by
                        LEFT JOIN users u2 ON u2.id = COALESCE(do.resolved_by, do.finalized_by, do.manager_id, do.admin_id)
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch(Exception $e) {}
                }

                // 13. Master Data Requests
                if (ard_table_exists($pdo, 'master_data_requests')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(mdr.updated_at, mdr.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND mdr.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (mdr.requested_by = ? OR mdr.reviewed_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND mdr.requested_by = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(mdr.updated_at, mdr.created_at) AS datetime,
                            COALESCE(NULLIF(mdr.request_no,''), CONCAT('MR-',mdr.id)) AS request_no,
                            'Master Data Request' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(mdr.status,'')) IN ('rejected','cancelled') THEN 'Rejected'
                                WHEN LOWER(COALESCE(mdr.status,'')) IN ('approved','completed') THEN 'Approved'
                                WHEN LOWER(COALESCE(mdr.status,'')) LIKE '%revis%' THEN 'Request Revision'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, CONCAT('User #',mdr.requested_by)) AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager / Admin') AS reviewed_by,
                            CONCAT('Category: ', COALESCE(mdr.category,'N/A'), ' | Reason: ', COALESCE(mdr.rejection_reason, 'N/A')) AS details,
                            COALESCE(mdr.status, 'Pending') AS status
                        FROM master_data_requests mdr
                        LEFT JOIN users u1 ON u1.id = mdr.requested_by
                        LEFT JOIN users u2 ON u2.id = mdr.reviewed_by
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // 14. Pending Price Change Approvals
                if (ard_table_exists($pdo, 'pending_price_approvals')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(ppa.reviewed_at, ppa.updated_at, ppa.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND ppa.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND (ppa.requested_by = ? OR ppa.reviewed_by = ?)"; $p[] = $filter_staff; $p[] = $filter_staff; }
                        if ($is_staff_view) { $w .= " AND ppa.requested_by = ?"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(ppa.reviewed_at, ppa.updated_at, ppa.created_at) AS datetime,
                            CONCAT('PPA-',ppa.id) AS request_no,
                            'Price Change Approval' AS request_type,
                            CASE
                                WHEN LOWER(COALESCE(ppa.status,'')) IN ('rejected','cancelled') THEN 'Rejected'
                                WHEN LOWER(COALESCE(ppa.status,'')) IN ('approved','completed') THEN 'Approved'
                                ELSE 'Pending Review'
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u1.first_name,''),' ',COALESCE(u1.last_name,''))),''), u1.username, 'Staff') AS requested_by,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u2.first_name,''),' ',COALESCE(u2.last_name,''))),''), u2.username, 'Manager / Admin') AS reviewed_by,
                            CONCAT('Product: ', COALESCE(ppa.product_name,ppa.product_type,'N/A'), ' | Old: ₱', FORMAT(COALESCE(ppa.old_price,ppa.old_value,0),2), ' -> New: ₱', FORMAT(COALESCE(ppa.new_price,ppa.new_value,0),2), ' | Notes: ', COALESCE(ppa.reviewer_notes, ppa.rejection_reason, ppa.reason, 'N/A')) AS details,
                            COALESCE(ppa.status, 'Pending') AS status
                        FROM pending_price_approvals ppa
                        LEFT JOIN users u1 ON u1.id = ppa.requested_by
                        LEFT JOIN users u2 ON u2.id = COALESCE(ppa.reviewed_by, ppa.manager_id, ppa.admin_id)
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // 15. Transaction Adjustments
                if (ard_table_exists($pdo, 'transaction_adjustments')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(COALESCE(ta.adjustment_date, ta.created_at, NOW())) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND ta.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND ta.adjusted_by = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR ta.adjusted_by = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(ta.adjustment_date, ta.created_at, NOW()) AS datetime,
                            CONCAT('ADJ-',ta.id) AS request_no,
                            'Adjustment Request' AS request_type,
                            'Pending Review' AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, CONCAT('User #',ta.adjusted_by)) AS requested_by,
                            'Manager' AS reviewed_by,
                            CONCAT('Txn: ', COALESCE(ta.transaction_id,'N/A'), ' | Diff: ₱', FORMAT(COALESCE(ta.amount_difference,0),2), ' | Reason: ', COALESCE(ta.adjustment_reason, ta.manager_remarks, 'N/A')) AS details,
                            'Pending' AS status
                        FROM transaction_adjustments ta
                        LEFT JOIN users u ON u.id = ta.adjusted_by
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // PHP-side filter & sort
                $filtered = [];
                foreach ($raw as $r) {
                    if (!empty($excluded_names)) {
                        $req_by = strtolower(trim($r['requested_by'] ?? ''));
                        $rev_by = strtolower(trim($r['reviewed_by'] ?? ''));
                        $skip_actor = false;
                        if ($is_staff_view) {
                            foreach ($excluded_names as $ex) {
                                if ($ex !== '' && ($req_by === $ex || str_contains($req_by, $ex))) { $skip_actor = true; break; }
                            }
                        } else {
                            foreach ($excluded_names as $ex) {
                                if ($ex !== '' && ($req_by === $ex || str_contains($req_by, $ex))) { $skip_actor = true; break; }
                                if ($ex !== '' && ($rev_by === $ex || str_contains($rev_by, $ex))) { $skip_actor = true; break; }
                            }
                        }
                        if ($skip_actor) continue;
                    }
                    if ($filter_stat !== '') {
                        $fs = strtolower($filter_stat);
                        $act_low = strtolower($r['action'] ?? '');
                        $stat_low = strtolower($r['status'] ?? '');
                        $matches = false;
                        if ($fs === 'pending') {
                            if (str_contains($act_low, 'pending') || str_contains($stat_low, 'pending') || str_contains($stat_low, 'submitted') || str_contains($stat_low, 'draft')) {
                                $matches = true;
                            }
                        } elseif ($fs === 'approved') {
                            if (str_contains($act_low, 'approv') || str_contains($stat_low, 'approv') || in_array($stat_low, ['completed','official','fulfilled','validated','verified','resolved','accepted'])) {
                                $matches = true;
                            }
                        } elseif ($fs === 'rejected') {
                            if (str_contains($act_low, 'reject') || str_contains($stat_low, 'reject') || str_contains($stat_low, 'cancel') || str_contains($stat_low, 'return')) {
                                $matches = true;
                            }
                        } elseif ($fs === 'for revision') {
                            if (str_contains($act_low, 'revis') || str_contains($stat_low, 'revis') || str_contains($stat_low, 'return') || str_contains($act_low, 'return')) {
                                $matches = true;
                            }
                        } else {
                            if (str_contains($act_low, $fs) || str_contains($stat_low, $fs)) {
                                $matches = true;
                            }
                        }
                        if (!$matches) continue;
                    }
                    if ($filter_srch !== '') {
                        $fsrch = strtolower($filter_srch);
                        $hay = strtolower(($r['request_no'] ?? '').' '.($r['request_type'] ?? '').' '.($r['action'] ?? '').' '.($r['status'] ?? '').' '.($r['requested_by'] ?? '').' '.($r['reviewed_by'] ?? '').' '.($r['details'] ?? ''));
                        if (strpos($hay, $fsrch) === false) continue;
                    }
                    $filtered[] = $r;
                }

                usort($filtered, fn($a,$b) => strtotime($b['datetime']) <=> strtotime($a['datetime']));
                $unique = []; $seen = [];
                foreach ($filtered as $r) {
                    $k = $r['request_no'] . '|' . $r['request_type'] . '|' . $r['action'] . '|' . substr($r['datetime'],0,16);
                    if (!isset($seen[$k])) { $seen[$k] = true; $unique[] = $r; }
                }
                $data['rows'] = $unique;

            // ── 4. LOGIN HISTORY ────────────────────────────────────────────
            } elseif ($tab === 'login_history') {
                $raw = [];

                // 1. Login Attempts
                if (ard_table_exists($pdo, 'login_attempts')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(la.attempt_time) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND u.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND la.user_id = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR la.user_id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT la.attempt_time AS datetime,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, la.username, 'Unknown') AS user,
                            COALESCE(NULLIF(u.role,''), 'Staff') AS role,
                            CASE WHEN LOWER(COALESCE(la.status,'')) = 'success' THEN 'Login' ELSE 'Failed Login' END AS action,
                            COALESCE(la.ip_address, 'N/A') AS ip_address,
                            CASE WHEN LOWER(COALESCE(la.status,'')) = 'success' THEN 'Success' ELSE 'Failed' END AS status,
                            COALESCE(la.failure_reason, 'Login Attempt') AS reason
                        FROM login_attempts la
                        LEFT JOIN users u ON la.user_id = u.id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_la = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_la as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // 2. Logouts, Automatic Timeouts, Password Resets from Activity Logs
                if (ard_table_exists($pdo, 'activity_logs')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(al.created_at) BETWEEN ? AND ?
                              AND (
                                LOWER(al.action) LIKE '%logout%'
                                OR LOWER(al.action) LIKE '%timeout%'
                                OR LOWER(al.action) LIKE '%password%'
                                OR LOWER(al.action) LIKE '%otp%'
                                OR LOWER(al.action) LIKE '%clock%'
                              )";
                        if ($station_id > 0) { $w .= " AND u.station_id = ?"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND al.user_id = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR al.user_id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT al.created_at AS datetime,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, CONCAT('User #',al.user_id)) AS user,
                            COALESCE(NULLIF(u.role,''), 'Staff') AS role,
                            COALESCE(al.action, 'Session Event') AS action,
                            COALESCE(al.ip_address, 'N/A') AS ip_address,
                            'Success' AS status,
                            COALESCE(al.details, 'Session activity recorded') AS reason
                        FROM activity_logs al
                        INNER JOIN users u ON u.id = al.user_id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_al = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_al as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // PHP-side filter & sort
                $filtered = [];
                foreach ($raw as $r) {
                    if (!empty($excluded_names)) {
                        $usr = strtolower(trim($r['user'] ?? ''));
                        $skip_actor = false;
                        foreach ($excluded_names as $ex) {
                            if ($ex !== '' && ($usr === $ex || str_contains($usr, $ex))) { $skip_actor = true; break; }
                        }
                        if ($skip_actor) continue;
                    }
                    if ($filter_stat !== '' && strtolower($r['status']) !== strtolower($filter_stat)) continue;
                    if ($filter_srch !== '') {
                        $hay = strtolower($r['user'].' '.$r['role'].' '.$r['action'].' '.$r['reason'].' '.$r['ip_address']);
                        if (strpos($hay, strtolower($filter_srch)) === false) continue;
                    }
                    $filtered[] = $r;
                }

                usort($filtered, fn($a,$b) => strtotime($b['datetime']) <=> strtotime($a['datetime']));
                $unique = []; $seen = [];
                foreach ($filtered as $r) {
                    $k = $r['user'] . '|' . substr($r['datetime'],0,19) . '|' . strtolower($r['action']);
                    if (!isset($seen[$k])) { $seen[$k] = true; $unique[] = $r; }
                }
                $data['rows'] = $unique;

            // ── 5. ARCHIVED & DEACTIVATED LOGS ──────────────────────────────
            } else {
                $raw = [];

                // 1. Audit Logs for Archive/Deactivate
                if (ard_table_exists($pdo, 'audit_logs')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(al.created_at) BETWEEN ? AND ?
                              AND (
                                LOWER(COALESCE(al.action_type,'')) LIKE '%archive%'
                                OR LOWER(COALESCE(al.action_type,'')) LIKE '%deactivat%'
                                OR LOWER(COALESCE(al.action_type,'')) LIKE '%reactivat%'
                                OR LOWER(COALESCE(al.action_type,'')) LIKE '%restore%'
                                OR LOWER(COALESCE(al.log_type,'')) LIKE '%archive%'
                                OR LOWER(COALESCE(al.log_type,'')) LIKE '%deactivat%'
                                OR LOWER(COALESCE(al.action_details,'')) LIKE '%archive%'
                                OR LOWER(COALESCE(al.action_details,'')) LIKE '%deactivat%'
                              )";
                        if ($station_id > 0) { $w .= " AND (u.station_id = ? OR u.station_id IS NULL)"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND al.user_id = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR al.user_id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT al.created_at AS datetime,
                            CASE
                                WHEN LOWER(COALESCE(al.entity_type, al.log_type, '')) LIKE '%customer%' THEN 'Customer'
                                WHEN LOWER(COALESCE(al.entity_type, al.log_type, '')) LIKE '%user%' THEN 'User Account'
                                WHEN LOWER(COALESCE(al.entity_type, al.log_type, '')) LIKE '%product%' OR LOWER(COALESCE(al.entity_type, al.log_type, '')) LIKE '%merch%' THEN 'Merchandise'
                                WHEN LOWER(COALESCE(al.entity_type, al.log_type, '')) LIKE '%service%' THEN 'Service'
                                ELSE 'Record'
                            END AS entity_type,
                            COALESCE(NULLIF(al.entity_id,''), CONCAT('AUD-',al.id)) AS ref_no,
                            CASE
                                WHEN LOWER(COALESCE(al.action_type,'')) LIKE '%restore%' OR LOWER(COALESCE(al.action_type,'')) LIKE '%reactivat%' THEN 'Reactivated'
                                WHEN LOWER(COALESCE(al.action_type,'')) LIKE '%deactivat%' OR LOWER(COALESCE(al.action_details,'')) LIKE '%deactivat%' THEN 'Deactivated'
                                WHEN LOWER(COALESCE(al.action_type,'')) LIKE '%archive%' OR LOWER(COALESCE(al.action_details,'')) LIKE '%archive%' THEN 'Archived'
                                ELSE COALESCE(al.action_type, 'Archived')
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, 'Admin') AS performed_by,
                            COALESCE(al.action_details, 'Status updated') AS details,
                            CASE
                                WHEN LOWER(COALESCE(al.action_type,'')) LIKE '%restore%' OR LOWER(COALESCE(al.action_type,'')) LIKE '%reactivat%' THEN 'Active'
                                WHEN LOWER(COALESCE(al.action_type,'')) LIKE '%deactivat%' OR LOWER(COALESCE(al.action_details,'')) LIKE '%deactivat%' THEN 'Deactivated'
                                ELSE 'Archived'
                            END AS status
                        FROM audit_logs al
                        LEFT JOIN users u ON u.id = al.user_id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_aul = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_aul as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // 2. Activity Logs for Archive/Deactivate
                if (ard_table_exists($pdo, 'activity_logs')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE DATE(act.created_at) BETWEEN ? AND ?
                              AND (
                                LOWER(act.action) LIKE '%archive%'
                                OR LOWER(act.action) LIKE '%deactivat%'
                                OR LOWER(act.action) LIKE '%restore%'
                                OR LOWER(act.action) LIKE '%reactivat%'
                                OR LOWER(act.details) LIKE '%archive%'
                                OR LOWER(act.details) LIKE '%deactivat%'
                              )";
                        if ($station_id > 0) { $w .= " AND (u.station_id = ? OR u.station_id IS NULL)"; $p[] = $station_id; }
                        if ($filter_staff > 0) { $w .= " AND act.user_id = ?"; $p[] = $filter_staff; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR act.user_id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT act.created_at AS datetime,
                            CASE
                                WHEN LOWER(act.action) LIKE '%user%' OR LOWER(act.details) LIKE '%user%' THEN 'User Account'
                                WHEN LOWER(act.action) LIKE '%merchandise%' OR LOWER(act.details) LIKE '%merchandise%' THEN 'Merchandise'
                                WHEN LOWER(act.action) LIKE '%service%' OR LOWER(act.details) LIKE '%service%' THEN 'Service'
                                WHEN LOWER(act.action) LIKE '%customer%' OR LOWER(act.details) LIKE '%customer%' THEN 'Customer'
                                ELSE 'Record'
                            END AS entity_type,
                            COALESCE(NULLIF(act.reference,''), CONCAT('ACT-',act.id)) AS ref_no,
                            CASE
                                WHEN LOWER(act.action) LIKE '%restore%' OR LOWER(act.action) LIKE '%reactivat%' THEN 'Reactivated'
                                WHEN LOWER(act.action) LIKE '%deactivat%' OR LOWER(act.details) LIKE '%deactivat%' THEN 'Deactivated'
                                WHEN LOWER(act.action) LIKE '%archive%' OR LOWER(act.details) LIKE '%archive%' THEN 'Archived'
                                ELSE act.action
                            END AS action,
                            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username, 'Admin') AS performed_by,
                            COALESCE(act.details, 'Activity recorded') AS details,
                            CASE
                                WHEN LOWER(act.action) LIKE '%restore%' OR LOWER(act.action) LIKE '%reactivat%' THEN 'Active'
                                WHEN LOWER(act.action) LIKE '%deactivat%' OR LOWER(act.details) LIKE '%deactivat%' THEN 'Deactivated'
                                ELSE 'Archived'
                            END AS status
                        FROM activity_logs act
                        LEFT JOIN users u ON u.id = act.user_id
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_act = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_act as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // 3. Users table fallback for deactivated accounts
                if (ard_table_exists($pdo, 'users')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE (LOWER(u.status) IN ('inactive','deactivated','archived')) AND DATE(COALESCE(u.updated_at, u.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND u.station_id = ?"; $p[] = $station_id; }
                        if ($has_role_filter) { $w .= " AND (LOWER(COALESCE(u.role,'staff')) IN {$role_filter_roles} OR u.id = ?)"; $p[] = $viewer_user_id; }
                        $sql = "SELECT COALESCE(u.updated_at, u.created_at) AS datetime,
                            'User Account' AS entity_type,
                            CONCAT('USER-',u.id) AS ref_no,
                            'Deactivated' AS action,
                            'Admin / Owner' AS performed_by,
                            CONCAT('User: ', COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''), u.username), ' | Role: ', COALESCE(u.role,'Staff')) AS details,
                            'Deactivated' AS status
                        FROM users u
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_u = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_u as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // 4. Products table fallback for deactivated/archived products
                if (ard_table_exists($pdo, 'products')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE LOWER(p.status) IN ('inactive','archived','deactivated') AND DATE(COALESCE(p.updated_at, p.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND p.station_id = ?"; $p[] = $station_id; }
                        $sql = "SELECT COALESCE(p.updated_at, p.created_at) AS datetime,
                            'Product' AS entity_type,
                            CONCAT('PRD-',p.id) AS ref_no,
                            'Archived' AS action,
                            'Manager / Admin' AS performed_by,
                            CONCAT('Product: ', COALESCE(p.name,'N/A'), ' | SKU: ', COALESCE(p.sku,'N/A')) AS details,
                            'Archived' AS status
                        FROM products p
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_p = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_p as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // 5. Customers table fallback for archived customer accounts
                if (ard_table_exists($pdo, 'customers')) {
                    try {
                        $p = [$date_from, $date_to];
                        $w = "WHERE (c.archived_at IS NOT NULL OR LOWER(COALESCE(c.account_status,'')) IN ('archived','inactive','deactivated'))
                              AND DATE(COALESCE(c.archived_at, c.updated_at, c.created_at)) BETWEEN ? AND ?";
                        if ($station_id > 0) { $w .= " AND c.station_id = ?"; $p[] = $station_id; }
                        $sql = "SELECT COALESCE(c.archived_at, c.updated_at, c.created_at) AS datetime,
                            'Customer' AS entity_type,
                            CONCAT('CUST-',c.id) AS ref_no,
                            'Archived' AS action,
                            'Manager / Admin' AS performed_by,
                            CONCAT('Customer: ', COALESCE(c.name, CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))), CASE WHEN c.archive_reason IS NOT NULL AND c.archive_reason != '' THEN CONCAT(' | Reason: ', c.archive_reason) ELSE '' END) AS details,
                            'Archived' AS status
                        FROM customers c
                        $w";
                        $st = $pdo->prepare($sql); $st->execute($p);
                        $rows_c = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        foreach ($rows_c as $r) { $raw[] = $r; }
                    } catch (Exception $e) {}
                }

                // PHP-side filter & sort
                $filtered = [];
                foreach ($raw as $r) {
                    if (!empty($excluded_names)) {
                        $perf = strtolower(trim($r['performed_by'] ?? ''));
                        $skip_actor = false;
                        foreach ($excluded_names as $ex) {
                            if ($ex !== '' && ($perf === $ex || str_contains($perf, $ex))) { $skip_actor = true; break; }
                        }
                        if ($skip_actor) continue;
                    }
                    if ($filter_act !== '' && strcasecmp($r['action'], $filter_act) !== 0) continue;
                    if ($filter_srch !== '') {
                        $hay = strtolower($r['ref_no'].' '.$r['entity_type'].' '.$r['action'].' '.$r['performed_by'].' '.$r['details']);
                        if (strpos($hay, strtolower($filter_srch)) === false) continue;
                    }
                    $filtered[] = $r;
                }

                usort($filtered, fn($a,$b) => strtotime($b['datetime']) <=> strtotime($a['datetime']));
                $unique = []; $seen = [];
                foreach ($filtered as $r) {
                    $k = $r['ref_no'] . '|' . $r['entity_type'] . '|' . $r['action'] . '|' . substr($r['datetime'],0,16);
                    if (!isset($seen[$k])) { $seen[$k] = true; $unique[] = $r; }
                }
                $data['rows'] = $unique;
            }
            break;
    }
    return $data;
}
}

/**
 * Fetch complete details for a single customer for the View Customer modal
 */
function getAdminCustomerDetails(PDO $pdo, int $customer_id): array {
    $details = [];

    // 1. Customer Info
    $stmt_c = $pdo->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $stmt_c->execute([$customer_id]);
    $details['info'] = $stmt_c->fetch(PDO::FETCH_ASSOC) ?: [];

    if (empty($details['info'])) return $details;

    // 2. Vehicle History (with last service date)
    $stmt_v = $pdo->prepare(
        "SELECT cv.plate_number, cv.vehicle_type, cv.brand, cv.model, cv.year_model, cv.color,
                (SELECT MAX(jo.created_at) FROM job_orders jo WHERE jo.customer_id = ? AND jo.vehicle_plate = cv.plate_number) as last_service
         FROM customer_vehicles cv
         WHERE cv.customer_id = ?
         ORDER BY cv.id DESC"
    );
    $stmt_v->execute([$customer_id, $customer_id]);
    $details['vehicles'] = $stmt_v->fetchAll(PDO::FETCH_ASSOC);

    // 3. Job Order History
    $stmt_s = $pdo->prepare(
        "SELECT jo.job_order_number as jo_no,
                DATE(jo.created_at) as date,
                COALESCE(NULLIF(jo.service_description,''), jo.service_type, 'Service') as service,
                COALESCE(m.full_name, 'Unassigned') as mechanic,
                jo.status,
                COALESCE(jo.total_cost, jo.actual_labor_cost, 0) as amount
         FROM job_orders jo
         LEFT JOIN mechanics m ON jo.assigned_mechanic_id = m.id
         WHERE jo.customer_id = ?
         ORDER BY jo.created_at DESC"
    );
    $stmt_s->execute([$customer_id]);
    $details['service_history'] = $stmt_s->fetchAll(PDO::FETCH_ASSOC);

    // 4. Merchandise Purchase History
    $stmt_m = $pdo->prepare(
        "SELECT mt.transaction_id as receipt_no,
                DATE(mt.transaction_date) as date,
                COALESCE(NULLIF(mt.item_sku,''), 'Merchandise Item') as product,
                mt.quantity,
                mt.total_amount as amount
         FROM merchandise_transactions mt
         WHERE mt.customer_id = ?
         ORDER BY mt.transaction_date DESC"
    );
    $stmt_m->execute([$customer_id]);
    $details['merch_history'] = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    // 5. Payment History (OR No. included)
    try {
        $stmt_p = $pdo->prepare(
            "SELECT DATE(transaction_date) as date,
                    COALESCE(NULLIF(transaction_id,''), CONCAT('OR-', LPAD(id, 5, '0'))) as or_no,
                    payment_method,
                    total_amount as amount,
                    COALESCE(NULLIF(payment_status,''), NULLIF(validation_status,''), 'Completed') as status
             FROM merchandise_transactions
             WHERE customer_id = ?
             UNION ALL
             SELECT DATE(created_at) as date,
                    CONCAT('CR-', LPAD(id, 5, '0')) as or_no,
                    'Credit Payment' as payment_method,
                    amount,
                    'Completed' as status
             FROM customer_credit_transactions
             WHERE customer_id = ?
             ORDER BY date DESC"
        );
        $stmt_p->execute([$customer_id, $customer_id]);
        $details['payment_history'] = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e_ph) {
        // Fallback: Query merchandise_transactions only if customer_credit_transactions is unavailable
        try {
            $stmt_p_fb = $pdo->prepare(
                "SELECT DATE(transaction_date) as date,
                        COALESCE(NULLIF(transaction_id,''), CONCAT('OR-', LPAD(id, 5, '0'))) as or_no,
                        payment_method,
                        total_amount as amount,
                        COALESCE(NULLIF(payment_status,''), NULLIF(validation_status,''), 'Completed') as status
                 FROM merchandise_transactions
                 WHERE customer_id = ?
                 ORDER BY transaction_date DESC"
            );
            $stmt_p_fb->execute([$customer_id]);
            $details['payment_history'] = $stmt_p_fb->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e_ph2) {
            $details['payment_history'] = [];
        }
    }

    // 6. Accounts Receivable History (Credit only based on legacy type field)
    $ctype = strtolower(trim($details['info']['type'] ?? ''));
    $ar_rows = [];
    if (str_contains($ctype, 'credit') || str_contains($ctype, 'fleet')) {
        $stmt_ar = $pdo->prepare(
            "SELECT COALESCE(NULLIF(transaction_id,''), CONCAT('INV-', LPAD(id, 5, '0'))) as invoice_no,
                    DATE(COALESCE(credit_due_date, due_date, DATE_ADD(DATE(transaction_date), INTERVAL 30 DAY))) as due_date,
                    COALESCE(balance_due, total_amount, 0) as balance,
                    CASE
                      WHEN LOWER(COALESCE(payment_status,'')) = 'paid' THEN 'Paid'
                      WHEN COALESCE(credit_due_date, due_date, DATE_ADD(DATE(transaction_date), INTERVAL 30 DAY)) < CURDATE() THEN 'Overdue'
                      WHEN COALESCE(credit_due_date, due_date, DATE_ADD(DATE(transaction_date), INTERVAL 30 DAY)) = CURDATE() THEN 'Due Today'
                      ELSE 'Current'
                    END as status
             FROM merchandise_transactions
             WHERE customer_id = ?
               AND (LOWER(COALESCE(payment_method,'')) LIKE '%credit%' OR LOWER(COALESCE(payment_method,'')) LIKE '%fleet%')
             ORDER BY transaction_date DESC"
        );
        $stmt_ar->execute([$customer_id]);
        $ar_rows = $stmt_ar->fetchAll(PDO::FETCH_ASSOC);
    }
    $details['ar_history'] = $ar_rows;

    // 7. Customer Statistics
    $all_dates   = array_unique(array_filter(array_column($details['merch_history'], 'date')));
    $total_visits = count($all_dates);
    $total_jos    = count($details['service_history']);
    $total_merch  = count($details['merch_history']);
    $total_spent  = array_sum(array_column($details['merch_history'], 'amount'))
                  + array_sum(array_column($details['service_history'], 'amount'));
    $avg_spent    = ($total_visits > 0) ? ($total_spent / $total_visits) : 0;
    $last_visit   = !empty($details['merch_history'])
                  ? $details['merch_history'][0]['date']
                  : (!empty($details['service_history']) ? $details['service_history'][0]['date'] : 'N/A');

    $details['stats'] = [
        'total_visits'          => $total_visits,
        'total_job_orders'      => $total_jos,
        'total_merch_purchases' => $total_merch,
        'total_amount_spent'    => $total_spent,
        'average_spending'      => $avg_spent,
        'last_visit'            => $last_visit,
    ];

    return $details;
}
