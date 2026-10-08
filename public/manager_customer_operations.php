<?php
ob_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
ob_end_clean();

require_login();

if (!function_exists('sanitize_optional_field')) {
    function sanitize_optional_field(?string $val): string {
        if ($val === null) return 'N/A';
        $trimmed = trim($val);
        if ($trimmed === '') return 'N/A';
        $lower = strtolower($trimmed);
        $invalid_placeholders = ['none', 'null', 'n/a', '-', 'unknown', 'not available', 'not_available', 'undefined', 'n.a.', 'n/a.'];
        if (in_array($lower, $invalid_placeholders, true)) {
            return 'N/A';
        }
        return $trimmed;
    }
}

function manager_send_json(array $data): void {
    while (ob_get_level()) {
        @ob_end_clean();
    }
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($data);
    exit;
}

$me = current_user();
$rawRole = $me['role'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'manager';
$role = role_key($rawRole);

customer_ensure_optional_columns($pdo);
customer_ensure_request_table($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            manager_list_customers();
            break;
        case 'view':
            manager_view_customer();
            break;
        case 'add':
            manager_add_customer();
            break;
        case 'update':
            manager_update_customer();
            break;
        case 'archive':
            manager_archive_customer();
            break;
        case 'restore':
            manager_restore_customer();
            break;
        case 'deactivate':
            manager_deactivate_customer();
            break;
        case 'requests':
            manager_list_customer_requests();
            break;
        case 'approve_request':
            manager_approve_customer_request();
            break;
        case 'reject_request':
            manager_reject_customer_request();
            break;
        case 'archive_vehicle':
            manager_archive_vehicle();
            break;
        case 'restore_vehicle':
            manager_restore_vehicle();
            break;
        case 'ar_history':
            manager_ar_history();
            break;
        case 'ar_payment':
            manager_record_ar_payment();
            break;
        default:
            manager_send_json(['success' => false, 'error' => 'Invalid action.']);
    }
} catch (Throwable $e) {
    manager_send_json(['success' => false, 'error' => $e->getMessage()]);
}

function manager_has_table(PDO $pdo, string $table): bool {
    try {
        // MariaDB does not support prepared statements with SHOW TABLES LIKE ?
        // Use $pdo->quote() to safely build the query string instead.
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $stmt  = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($clean));
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Fallback: try a cheap SELECT 1 to confirm the table exists
        try {
            $pdo->query("SELECT 1 FROM `" . preg_replace('/[^a-zA-Z0-9_]/', '', $table) . "` LIMIT 1");
            return true;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function manager_table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $cache[$table] = [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cache[$table][strtolower($row['Field'])] = $row['Field'];
        }
    } catch (Throwable $e) {}
    return $cache[$table];
}

function manager_has_col(PDO $pdo, string $table, string $column): bool {
    $cols = manager_table_columns($pdo, $table);
    return isset($cols[strtolower($column)]);
}

function manager_scope_customer_where(string $alias = 'c'): array {
    return [[], []];
}

function manager_customer_select_sql(): array {
    global $pdo;
    return [
        'customer_id'    => customer_id_expr($pdo, 'c'),
        'display_name'   => customer_display_name_expr($pdo, 'c'),
        'first_name'     => customer_first_name_expr($pdo, 'c'),
        'middle_name'    => customer_middle_name_expr($pdo, 'c'),
        'last_name'      => customer_last_name_expr($pdo, 'c'),
        'contact'        => customer_contact_expr($pdo, 'c'),
        'email'          => customer_expr_col($pdo, 'c', 'email', "''"),
        'status'         => customer_status_expr($pdo, 'c'),
        'type'           => customer_type_expr($pdo, 'c'),
        'registered_at'  => customer_registered_at_expr($pdo, 'c'),
        'balance'        => customer_balance_expr($pdo, 'c'),
        'credit_limit'   => customer_credit_limit_expr($pdo, 'c'),
        'credit_terms'   => customer_expr_col($pdo, 'c', 'credit_terms', "'30 Days'"),
        'vehicle_plate'  => customer_vehicle_expr($pdo, 'vehicle_plate', 'c'),
        'vehicle_make'   => customer_vehicle_expr($pdo, 'vehicle_make', 'c'),
        'vehicle_model'  => customer_vehicle_expr($pdo, 'vehicle_model', 'c'),
        'vehicle_type'   => customer_vehicle_expr($pdo, 'vehicle_type', 'c'),
        'address'        => customer_expr_col($pdo, 'c', 'address', "''"),
        'gov_id_type'    => customer_expr_col($pdo, 'c', 'gov_id_type', "''"),
        'gov_id_file'    => customer_expr_col($pdo, 'c', 'gov_id_file', "''"),
        'cr_file'        => customer_expr_col($pdo, 'c', 'cr_file', "''"),
        'or_file'        => customer_expr_col($pdo, 'c', 'or_file', "''"),
        'archived_at'    => customer_expr_col($pdo, 'c', 'archived_at', "NULL"),
        'archive_reason' => customer_expr_col($pdo, 'c', 'archive_reason', "''"),
        'archive_remarks' => customer_expr_col($pdo, 'c', 'archive_remarks', "''"),
    ];
}

function manager_log_timeline(int $customerId, string $eventType, string $description): void {
    global $pdo, $me;
    try {
        $stmt = $pdo->prepare("INSERT INTO customer_timeline (customer_id, event_type, description, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$customerId, $eventType, $description, $me['id'] ?? null]);
    } catch (Throwable $e) {}
}

function manager_customer_tx_summary(int $customerId, int $customerStation): array {
    global $pdo;

    $summary = [
        'total_transactions' => 0,
        'merchandise_purchases' => 0,
        'job_orders' => 0,
        'fuel_transactions' => 0,
        'total_amount' => 0.0,
        'last_transaction' => null,
    ];

    $tables = [
        'merchandise_transactions' => [
            'count_key' => 'merchandise_purchases',
            'date_cols' => ['transaction_date', 'created_at'],
            'amount_cols' => ['total_amount', 'grand_total', 'amount'],
            'customer_cols' => ['customer_id', 'credit_customer_id'],
        ],
        'job_orders' => [
            'count_key' => 'job_orders',
            'date_cols' => ['created_at', 'transaction_date'],
            'amount_cols' => ['total_cost', 'estimated_cost', 'amount'],
            'customer_cols' => ['customer_id'],
        ],
        'fuel_transactions' => [
            'count_key' => 'fuel_transactions',
            'date_cols' => ['transaction_date', 'created_at'],
            'amount_cols' => ['total_amount', 'amount'],
            'customer_cols' => ['customer_id'],
        ],
    ];

    foreach ($tables as $table => $meta) {
        if (!manager_has_table($pdo, $table)) continue;

        $customerConditions = [];
        foreach ($meta['customer_cols'] as $column) {
            if (manager_has_col($pdo, $table, $column)) {
                $customerConditions[] = "`$column` = :customer_id";
            }
        }
        if (!$customerConditions) continue;

        $dateCol = null;
        foreach ($meta['date_cols'] as $c) {
            if (manager_has_col($pdo, $table, $c)) { $dateCol = $c; break; }
        }
        $amountCol = null;
        foreach ($meta['amount_cols'] as $c) {
            if (manager_has_col($pdo, $table, $c)) { $amountCol = $c; break; }
        }

        $dateSql = $dateCol ? "MAX(`$dateCol`)" : "NULL";
        $amountSql = $amountCol ? "COALESCE(SUM(`$amountCol`),0)" : "0";

        try {
            $sql = "SELECT COUNT(*) AS row_count, $amountSql AS amount_total, $dateSql AS last_date
                    FROM `$table`
                    WHERE (" . implode(' OR ', $customerConditions) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':customer_id' => $customerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $count = (int)($row['row_count'] ?? 0);
            $summary[$meta['count_key']] += $count;
            $summary['total_transactions'] += $count;
            $summary['total_amount'] += (float)($row['amount_total'] ?? 0);

            $last = $row['last_date'] ?? null;
            if ($last) {
                $summary['last_transaction'] = $summary['last_transaction'] ? max($summary['last_transaction'], $last) : $last;
            }
        } catch (Throwable $e) {}
    }

    return $summary;
}

function manager_empty_stats(): array {
    return [
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
        'credit' => 0,
        'pending_requests' => 0,
        'new_this_month' => 0,
        'archived' => 0,
    ];
}

function manager_list_customers(): void {
    global $pdo;

    try {
        $pdo->query("SELECT 1 FROM customers LIMIT 1");
    } catch (Throwable $e) {
        manager_send_json(['success' => true, 'customers' => [], 'stats' => manager_empty_stats()]);
    }

    $search    = trim($_GET['search'] ?? '');
    $type      = trim($_GET['type'] ?? '');
    $status    = trim($_GET['status'] ?? '');
    $dateFrom  = trim($_GET['date_from'] ?? '');
    $dateTo    = trim($_GET['date_to'] ?? '');
    $tab       = trim($_GET['tab'] ?? 'list'); // list, archived, pending

    $validTypes = ['walk-in', 'regular', 'credit', 'fleet', 'corporate'];
    $validStatuses = ['active', 'inactive', 'archived'];

    $station_id_scope = (int)user_station_id();
    $where = [];
    $params = [];
    $expr = manager_customer_select_sql();

    // ── Station isolation: only show this station's customers ──
    $where[] = "c.station_id = ?";
    $params[] = $station_id_scope;

    if ($tab === 'archived') {
        $where[] = "LOWER({$expr['status']}) = 'archived'";
    } else {
        if ($status !== '' && in_array($status, $validStatuses, true)) {
            $where[] = "LOWER({$expr['status']}) = ?";
            $params[] = strtolower($status);
        } else {
            $where[] = "LOWER({$expr['status']}) != 'archived'";
        }
    }

    if ($search !== '') {
        $words = array_filter(preg_split('/\s+/', trim($search)));
        $s = "%$search%";
        $searchCond = "({$expr['customer_id']} LIKE ? OR {$expr['display_name']} LIKE ? OR c.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR {$expr['contact']} LIKE ? OR {$expr['vehicle_plate']} LIKE ?)";
        array_push($params, $s, $s, $s, $s, $s, $s, $s);

        if (count($words) > 1) {
            $wSub = [];
            foreach ($words as $w) {
                $sw = "%{$w}%";
                $wSub[] = "({$expr['display_name']} LIKE ? OR c.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR {$expr['customer_id']} LIKE ? OR {$expr['contact']} LIKE ? OR {$expr['vehicle_plate']} LIKE ?)";
                array_push($params, $sw, $sw, $sw, $sw, $sw, $sw, $sw);
            }
            $searchCond = "({$searchCond} OR (" . implode(' AND ', $wSub) . "))";
        }
        $where[] = $searchCond;
    }

    if ($type !== '' && in_array($type, $validTypes, true)) {
        $where[] = "LOWER({$expr['type']}) = ?";
        $params[] = strtolower($type);
    }

    if ($dateFrom !== '') {
        $where[] = "DATE({$expr['registered_at']}) >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "DATE({$expr['registered_at']}) <= ?";
        $params[] = $dateTo;
    }

    $whereClause = $where ? implode(' AND ', $where) : '1=1';
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.station_id,
            {$expr['customer_id']} AS customer_id,
            {$expr['display_name']} AS customer_name,
            {$expr['first_name']} AS first_name,
            {$expr['middle_name']} AS middle_name,
            {$expr['last_name']} AS last_name,
            {$expr['contact']} AS contact_number,
            {$expr['email']} AS email,
            {$expr['address']} AS address,
            {$expr['status']} AS status,
            {$expr['vehicle_plate']} AS plate_no,
            {$expr['vehicle_make']} AS vehicle_make,
            {$expr['vehicle_model']} AS vehicle_model,
            {$expr['vehicle_type']} AS vehicle_type,
            {$expr['credit_limit']} AS credit_limit,
            {$expr['credit_terms']} AS credit_terms,
            {$expr['balance']} AS outstanding_balance,
            {$expr['gov_id_type']} AS gov_id_type,
            {$expr['gov_id_file']} AS gov_id_file,
            {$expr['cr_file']} AS cr_file,
            {$expr['or_file']} AS or_file,
            {$expr['archived_at']} AS archived_at,
            {$expr['archive_reason']} AS archive_reason,
            {$expr['archive_remarks']} AS archive_remarks,
            {$expr['registered_at']} AS registered_at
        FROM customers c
        WHERE $whereClause
        ORDER BY c.id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $custIds = array_column($customers, 'id');
    $vehiclesMap = [];
    if ($custIds) {
        try {
            $inClause = implode(',', array_fill(0, count($custIds), '?'));
            $vStmt = $pdo->prepare("SELECT customer_id, plate_number, vehicle_type, brand, model FROM customer_vehicles WHERE customer_id IN ($inClause) AND status = 'active'");
            $vStmt->execute($custIds);
            while ($vRow = $vStmt->fetch(PDO::FETCH_ASSOC)) {
                $vehiclesMap[(int)$vRow['customer_id']][] = $vRow;
            }
        } catch (Throwable $e) {}
    }

    foreach ($customers as &$customer) {
        $cId = (int)$customer['id'];
        $cVehicles = $vehiclesMap[$cId] ?? [];
        if (!$cVehicles && !empty($customer['plate_no'])) {
            $cVehicles[] = [
                'plate_number' => $customer['plate_no'],
                'vehicle_type' => $customer['vehicle_type'] ?? '',
                'brand'        => $customer['vehicle_make'] ?? '',
                'model'        => $customer['vehicle_model'] ?? '',
            ];
        }
        $customer['vehicles'] = $cVehicles;
        $customer['available_credit'] = max(0, (float)$customer['credit_limit'] - (float)($customer['outstanding_balance'] ?? 0));
        $customer['vehicle_count'] = count($cVehicles);
        $customer['last_transaction'] = null;
    }
    unset($customer);

    $firstDayMonth = date('Y-m-01');

    $stats = manager_empty_stats();
    try {
        $statsStmt = $pdo->prepare("
            SELECT
                COUNT(CASE WHEN {$expr['status']} != 'archived' THEN 1 END) AS total,
                SUM(CASE WHEN {$expr['status']} = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN {$expr['status']} = 'inactive' THEN 1 ELSE 0 END) AS inactive,
                SUM(CASE WHEN {$expr['status']} = 'archived' THEN 1 ELSE 0 END) AS archived,
                SUM(CASE WHEN (COALESCE(c.credit_limit, 0) > 0 OR LOWER(COALESCE(c.type, '')) = 'credit') AND {$expr['status']} != 'archived' THEN 1 ELSE 0 END) AS credit,
                SUM(CASE WHEN DATE({$expr['registered_at']}) >= '$firstDayMonth' AND {$expr['status']} != 'archived' THEN 1 ELSE 0 END) AS new_this_month
            FROM customers c
            WHERE c.station_id = ?
        ");
        $statsStmt->execute([$station_id_scope]);
        $fetchedStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        if ($fetchedStats) {
            $stats = array_merge($stats, array_map('intval', $fetchedStats));
        }
    } catch (Throwable $stErr) {
        error_log("Customer stats calculation error: " . $stErr->getMessage());
    }
    $stats['pending_requests'] = manager_count_pending_requests();

    manager_send_json(['success' => true, 'customers' => $customers, 'stats' => $stats]);
}

function manager_view_customer(): void {
    global $pdo;

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $expr = manager_customer_select_sql();
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.station_id,
            {$expr['customer_id']} AS customer_id,
            {$expr['display_name']} AS customer_name,
            {$expr['first_name']} AS first_name,
            {$expr['middle_name']} AS middle_name,
            {$expr['last_name']} AS last_name,
            {$expr['contact']} AS contact_number,
            {$expr['email']} AS email,
            {$expr['address']} AS address,
            {$expr['status']} AS status,
            {$expr['vehicle_plate']} AS plate_no,
            {$expr['vehicle_make']} AS vehicle_make,
            {$expr['vehicle_model']} AS vehicle_model,
            {$expr['vehicle_type']} AS vehicle_type,
            {$expr['credit_limit']} AS credit_limit,
            {$expr['credit_terms']} AS credit_terms,
            {$expr['balance']} AS outstanding_balance,
            {$expr['gov_id_type']} AS gov_id_type,
            {$expr['gov_id_file']} AS gov_id_file,
            {$expr['cr_file']} AS cr_file,
            {$expr['or_file']} AS or_file,
            {$expr['archived_at']} AS archived_at,
            {$expr['archive_reason']} AS archive_reason,
            {$expr['archive_remarks']} AS archive_remarks,
            {$expr['registered_at']} AS registered_at
        FROM customers c
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new Exception('Customer not found.');
    }

    $customerStation = (int)($customer['station_id'] ?? 0);
    $summary = manager_customer_tx_summary($id, $customerStation);
    $customer['available_credit'] = max(0, (float)$customer['credit_limit'] - (float)$customer['outstanding_balance']);

    $vehicles = [];
    try {
        $vStmt = $pdo->prepare("SELECT * FROM customer_vehicles WHERE customer_id = ? ORDER BY id ASC");
        $vStmt->execute([$id]);
        $vehicles = $vStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    if (empty($vehicles) && !empty($customer['plate_no'])) {
        $vehicles[] = [
            'id' => 0,
            'plate_number' => $customer['plate_no'],
            'vehicle_type' => $customer['vehicle_type'] ?: 'N/A',
            'brand'        => $customer['vehicle_make'] ?: 'N/A',
            'model'        => $customer['vehicle_model'] ?: 'N/A',
            'status'       => 'active'
        ];
    }

    $transactions = [];
    if (manager_has_table($pdo, 'merchandise_transactions')) {
        try {
            $tStmt = $pdo->prepare("
                SELECT 
                    transaction_id, 
                    created_at AS date, 
                    CASE 
                        WHEN transaction_type = 'job_order' THEN 'Job Order'
                        WHEN transaction_type = 'combined' THEN 'Combined'
                        WHEN (job_order_service IS NOT NULL AND TRIM(job_order_service) <> '') THEN 'Job Order'
                        ELSE 'Merchandise'
                    END AS type, 
                    total_amount AS amount, 
                    COALESCE(validation_status, 'Completed') AS status 
                FROM merchandise_transactions 
                WHERE customer_id = ? OR credit_customer_id = ? 
                ORDER BY created_at DESC 
                LIMIT 20
            ");
            $tStmt->execute([$id, $id]);
            $transactions = $tStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    if (manager_has_table($pdo, 'fuel_transactions')) {
        try {
            $cols = manager_table_columns($pdo, 'fuel_transactions');
            $txIdCol = isset($cols['transaction_code']) ? 'transaction_code' : (isset($cols['transaction_id']) ? 'transaction_id' : 'id');
            $dateCol = isset($cols['transaction_date']) ? 'transaction_date' : 'created_at';
            $amtCol  = isset($cols['total_amount']) ? 'total_amount' : 'amount';
            $statCol = isset($cols['status']) ? 'status' : "'Completed'";

            if (isset($cols['customer_id'])) {
                $fStmt = $pdo->prepare("
                    SELECT 
                        `$txIdCol` AS transaction_id, 
                        `$dateCol` AS date, 
                        'Fuel' AS type, 
                        `$amtCol` AS amount, 
                        COALESCE(`$statCol`, 'Completed') AS status 
                    FROM fuel_transactions 
                    WHERE customer_id = ? 
                    ORDER BY `$dateCol` DESC 
                    LIMIT 20
                ");
                $fStmt->execute([$id]);
                $fuelTxs = $fStmt->fetchAll(PDO::FETCH_ASSOC);
                $transactions = array_merge($transactions, $fuelTxs);
                usort($transactions, function($a, $b) {
                    return strtotime($b['date'] ?? '') - strtotime($a['date'] ?? '');
                });
                $transactions = array_slice($transactions, 0, 20);
            }
        } catch (Throwable $e) {}
    }

    $jobOrders = [];
    if (manager_has_table($pdo, 'job_orders')) {
        try {
            $hasMechanics = manager_has_table($pdo, 'mechanics');
            if ($hasMechanics) {
                $jStmt = $pdo->prepare("
                    SELECT 
                        jo.job_order_number AS jo_no, 
                        jo.vehicle_plate AS vehicle, 
                        jo.service_type AS service, 
                        COALESCE(m.full_name, CONCAT(m.first_name, ' ', m.last_name), CONCAT('Mechanic #', jo.assigned_mechanic_id)) AS mechanic, 
                        jo.status 
                    FROM job_orders jo 
                    LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id 
                    WHERE jo.customer_id = ? 
                    ORDER BY jo.created_at DESC LIMIT 20
                ");
            } else {
                $jStmt = $pdo->prepare("SELECT job_order_number AS jo_no, vehicle_plate AS vehicle, service_type AS service, assigned_mechanic_id AS mechanic, status FROM job_orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 20");
            }
            $jStmt->execute([$id]);
            $jobOrders = $jStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    if (manager_has_table($pdo, 'merchandise_transactions')) {
        try {
            $mJoStmt = $pdo->prepare("
                SELECT 
                    COALESCE(NULLIF(job_order_id, ''), transaction_id) AS jo_no, 
                    COALESCE(NULLIF(job_order_vehicle_plate, ''), 'N/A') AS vehicle, 
                    COALESCE(NULLIF(job_order_service, ''), 'Service') AS service, 
                    COALESCE(NULLIF(job_order_mechanic_name, ''), NULLIF(job_order_mechanic_id, ''), 'Unassigned') AS mechanic, 
                    COALESCE(NULLIF(workflow_status, ''), NULLIF(validation_status, ''), 'Pending') AS status 
                FROM merchandise_transactions 
                WHERE (customer_id = ? OR credit_customer_id = ?)
                  AND (transaction_type IN ('job_order', 'combined') OR (job_order_service IS NOT NULL AND TRIM(job_order_service) <> ''))
                ORDER BY created_at DESC 
                LIMIT 20
            ");
            $mJoStmt->execute([$id, $id]);
            $mJobOrders = $mJoStmt->fetchAll(PDO::FETCH_ASSOC);

            $existingNos = array_column($jobOrders, 'jo_no');
            foreach ($mJobOrders as $mjo) {
                if (!in_array($mjo['jo_no'], $existingNos)) {
                    $jobOrders[] = $mjo;
                }
            }
        } catch (Throwable $e) {}
    }

    $timeline = [];
    try {
        $tmStmt = $pdo->prepare("SELECT event_type, description, created_at FROM customer_timeline WHERE customer_id = ? ORDER BY created_at ASC");
        $tmStmt->execute([$id]);
        $timeline = $tmStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    if (empty($timeline)) {
        $timeline[] = [
            'event_type' => 'Customer Created',
            'description' => 'Customer account registered in system.',
            'created_at' => $customer['registered_at'] ?: date('Y-m-d H:i:s')
        ];
        if ((float)$customer['credit_limit'] > 0) {
            $timeline[] = [
                'event_type' => 'Credit Approved',
                'description' => 'Credit limit approved: ₱' . number_format($customer['credit_limit'], 2),
                'created_at' => $customer['registered_at'] ?: date('Y-m-d H:i:s')
            ];
        }
        if ($summary['last_transaction']) {
            $timeline[] = [
                'event_type' => 'Last Transaction',
                'description' => 'Most recent purchase or job order transaction processed.',
                'created_at' => $summary['last_transaction']
            ];
        }
        if ($customer['status'] === 'archived') {
            $timeline[] = [
                'event_type' => 'Archived',
                'description' => 'Account archived. Reason: ' . ($customer['archive_reason'] ?: 'Inactive'),
                'created_at' => $customer['archived_at'] ?: date('Y-m-d H:i:s')
            ];
        }
    }

    $loyaltyHistory = [];
    $loyaltyAccount = null;
    try {
        require_once __DIR__ . '/../backend/loyalty_schema_fix.php';
        loyalty_ensure_tables($pdo);

        $loyaltyAccount = get_or_create_loyalty_account($pdo, $id, $customer['loyalty_card_no'] ?? '');
        $accId = (int)($loyaltyAccount['id'] ?? 0);

        $lStmt = $pdo->prepare("
            SELECT reference_id AS reference, transaction_type, points_earned, points_redeemed, points_balance_after AS balance, created_at AS date
            FROM loyalty_transactions
            WHERE customer_id = ? OR loyalty_account_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $lStmt->execute([$id, $accId]);
        $loyaltyHistory = $lStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    manager_send_json([
        'success' => true,
        'customer' => $customer,
        'summary' => $summary,
        'vehicles' => $vehicles,
        'transactions' => $transactions,
        'job_orders' => $jobOrders,
        'loyalty_history' => $loyaltyHistory,
        'loyalty_account' => $loyaltyAccount,
        'timeline' => $timeline,
    ]);
}

function manager_handle_file_upload(string $fileKey): ?string {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmpName = $_FILES[$fileKey]['tmp_name'];
    $name = $_FILES[$fileKey]['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
    if (!in_array($ext, $allowed, true)) {
        throw new Exception("Invalid file type for {$fileKey}. Allowed: JPG, PNG, PDF, DOC.");
    }

    $uploadDir = __DIR__ . '/../uploads/customer_docs/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $newName = uniqid('doc_', true) . '.' . $ext;
    $destination = $uploadDir . $newName;

    if (move_uploaded_file($tmpName, $destination)) {
        return 'uploads/customer_docs/' . $newName;
    }
    return null;
}

if (!function_exists('sanitize_optional_field')) {
    function sanitize_optional_field(?string $val): string {
        if ($val === null) return 'N/A';
        $trimmed = trim($val);
        if ($trimmed === '') return 'N/A';
        $lower = strtolower($trimmed);
        $invalid_placeholders = ['none', 'null', 'n/a', '-', 'unknown', 'not available', 'not_available', 'undefined', 'n.a.', 'n/a.'];
        if (in_array($lower, $invalid_placeholders, true)) {
            return 'N/A';
        }
        return $trimmed;
    }
}

function manager_validate_customer_payload(): array {
    $firstName   = trim($_POST['first_name'] ?? '');
    $middleName  = sanitize_optional_field($_POST['middle_name'] ?? '');
    $lastName    = sanitize_optional_field($_POST['last_name'] ?? '');
    $contact     = sanitize_optional_field($_POST['contact_number'] ?? '');
    $email       = sanitize_optional_field($_POST['email'] ?? '');
    $address     = sanitize_optional_field($_POST['address'] ?? '');
    $status      = trim($_POST['status'] ?? 'active');
    $creditLimit = (float)($_POST['credit_limit'] ?? 0);
    $creditTerms = trim($_POST['credit_terms'] ?? '30 Days');
    $govIdType   = trim($_POST['gov_id_type'] ?? '');

    $placeholders = ['n/a', 'none', 'null', '-'];

    if ($firstName === '' || in_array(strtolower($firstName), $placeholders, true)) {
        throw new Exception('Customer First Name / Name is required and cannot be N/A or a placeholder.');
    }

    if ($contact !== 'N/A') {
        $cleanPhone = preg_replace('/[\s\-\(\)\.]/', '', $contact);
        if (!preg_match('/^(09\d{9}|\+639\d{9}|639\d{9})$/', $cleanPhone)) {
            throw new Exception('Invalid Philippine contact number. Must be an 11-digit mobile number starting with 09 (e.g. 09171234567 or +639171234567).');
        }
        if (str_starts_with($cleanPhone, '+639')) { $contact = '09' . substr($cleanPhone, 4); }
        elseif (str_starts_with($cleanPhone, '639')) { $contact = '09' . substr($cleanPhone, 3); }
        else { $contact = $cleanPhone; }
    }

    if ($email !== 'N/A') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address format. Please enter a valid email address or leave blank for N/A.');
        }
    }

    if (!in_array($status, ['active', 'inactive', 'archived'], true)) {
        $status = 'active';
    }
    // Allow credit limit saving for all customers
    $creditLimit = max(0, $creditLimit);

    $govIdFile = manager_handle_file_upload('gov_id_file');
    $crFile    = manager_handle_file_upload('cr_file');
    $orFile    = manager_handle_file_upload('or_file');

    return [
        'first_name'     => $firstName,
        'middle_name'    => $middleName,
        'last_name'      => $lastName,
        'contact_number' => $contact,
        'email'          => $email,
        'address'        => $address,
        'status'         => $status,
        'credit_limit'   => max(0, $creditLimit),
        'credit_terms'   => $creditTerms,
        'gov_id_type'    => sanitize_optional_field($_POST['gov_id_type'] ?? ''),
        'gov_id_file'    => $govIdFile,
        'cr_file'        => $crFile,
        'or_file'        => $orFile,
        'vehicle_plate'  => strtoupper(trim($_POST['plate_no'] ?? $_POST['vehicle_plate'] ?? '')),
        'vehicle_make'   => trim($_POST['vehicle_make'] ?? $_POST['brand'] ?? ''),
        'vehicle_brand'  => trim($_POST['vehicle_make'] ?? $_POST['brand'] ?? ''),
        'vehicle_model'  => trim($_POST['vehicle_model'] ?? ''),
        'vehicle_type'   => trim($_POST['vehicle_type'] ?? ''),
        'loyalty_card_no' => sanitize_optional_field($_POST['loyalty_card_no'] ?? ''),
    ];
}

function manager_generate_customer_id(int $stationId): string {
    global $pdo;
    $prefix = "CUS-{$stationId}-" . date('Ym') . "-";
    $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = (string)$stmt->fetchColumn();
    $next = $last ? ((int)substr($last, -3) + 1) : 1;
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function manager_audit(string $action, string $details, int $entityId = 0): void {
    global $pdo;
    if (function_exists('write_audit_log')) {
        try {
            write_audit_log($pdo, $action, $details, 'customers', $entityId, 'customer');
        } catch (Throwable $e) {}
    }
}

function manager_add_customer(): void {
    global $pdo, $station_id, $me;

    $stId = $station_id;
    if ($stId <= 0 && !empty($me['id'])) {
        try {
            $uStmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ?");
            $uStmt->execute([(int)$me['id']]);
            $stId = (int)$uStmt->fetchColumn();
        } catch (Throwable $e) {}
    }
    if ($stId <= 0) {
        try {
            $sStmt = $pdo->query("SELECT id FROM stations ORDER BY id ASC LIMIT 1");
            $stId = (int)$sStmt->fetchColumn();
        } catch (Throwable $e) {}
    }
    if ($stId <= 0) {
        $stId = 1253;
    }

    $data = manager_validate_customer_payload();
    $customerId = manager_generate_customer_id($stId);
    $fullName = trim($data['first_name'] . ' ' . $data['middle_name'] . ' ' . $data['last_name']);

    $insertValues = [
        'customer_id'         => $customerId,
        'station_id'          => $stId,
        'name'                => $fullName,
        'first_name'          => $data['first_name'],
        'middle_name'         => $data['middle_name'],
        'last_name'           => $data['last_name'],
        'contact_number'      => $data['contact_number'],
        'phone'               => $data['contact_number'],
        'email'               => $data['email'],
        'address'             => $data['address'],
        'type'                => ($data['credit_limit'] > 0 ? 'credit' : 'cash'),
        'status'              => $data['status'],
        'account_status'      => $data['status'],
        'vehicle_plate'       => $data['vehicle_plate'],
        'plate_number'        => $data['vehicle_plate'],
        'vehicle_make'        => $data['vehicle_make'],
        'vehicle_brand'       => $data['vehicle_make'],
        'vehicle_model'       => $data['vehicle_model'],
        'vehicle_type'        => $data['vehicle_type'],
        'credit_limit'        => $data['credit_limit'],
        'credit_terms'        => $data['credit_terms'],
        'gov_id_type'         => $data['gov_id_type'],
        'loyalty_card_no'     => $data['loyalty_card_no'],
        'outstanding_balance' => 0,
        'current_balance'     => 0,
        'balance'             => 0,
        'registered_by'       => $me['id'] ?? null,
        'verification_status' => 'verified',
        'mgr_status'          => 'approved',
    ];

    if ($data['gov_id_file']) $insertValues['gov_id_file'] = $data['gov_id_file'];
    if ($data['cr_file'])     $insertValues['cr_file']     = $data['cr_file'];
    if ($data['or_file'])     $insertValues['or_file']     = $data['or_file'];

    $newId = customer_insert_existing($pdo, $insertValues, [
        'registered_at' => 'NOW()',
        'created_at'    => 'NOW()',
    ]);

    if (!empty($data['loyalty_card_no'])) {
        try {
            require_once __DIR__ . '/../backend/loyalty_schema_fix.php';
            get_or_create_loyalty_account($pdo, $newId, $data['loyalty_card_no']);
        } catch (Throwable $e) {}
    }

    if (isset($_POST['vehicles_json'])) {
        $vList = json_decode($_POST['vehicles_json'], true) ?: [];
        foreach ($vList as $v) {
            $plate = strtoupper(trim($v['plate_number'] ?? ''));
            if ($plate !== '') {
                try {
                    $vStmt = $pdo->prepare("INSERT INTO customer_vehicles (customer_id, plate_number, vehicle_type, brand, model, year_model, color, engine_no, chassis_no, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                    $vStmt->execute([
                        $newId,
                        $plate,
                        trim($v['vehicle_type'] ?? ''),
                        trim($v['brand'] ?? ''),
                        trim($v['model'] ?? ''),
                        trim($v['year_model'] ?? ''),
                        trim($v['color'] ?? ''),
                        trim($v['engine_no'] ?? ''),
                        trim($v['chassis_no'] ?? '')
                    ]);
                    manager_log_timeline($newId, 'Vehicle Added', "Vehicle {$plate} ({$v['brand']} {$v['model']}) registered.");
                } catch (Throwable $e) {}
            }
        }
    } else if (!empty($data['vehicle_plate'])) {
        try {
            $vStmt = $pdo->prepare("INSERT INTO customer_vehicles (customer_id, plate_number, vehicle_type, brand, model, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
            $vStmt->execute([$newId, $data['vehicle_plate'], $data['vehicle_type'], $data['vehicle_make'], $data['vehicle_model']]);
        } catch (Throwable $e) {}
    }

    manager_log_timeline($newId, 'Customer Created', "Customer {$fullName} registered into system.");
    if (($data['credit_limit'] ?? 0) > 0) {
        manager_log_timeline($newId, 'Credit Approved', "Credit limit approved: ₱" . number_format($data['credit_limit'], 2));
    }

    manager_audit('Add Customer', "Created customer {$fullName} ({$customerId})", $newId);
    if (function_exists('log_activity')) {
        try {
            log_activity($pdo, (int)($me['id'] ?? 0), 'Add Customer', "Added customer {$fullName} ({$customerId}) | Contact: " . ($data['contact_number'] ?: 'N/A') . " | Plate: " . ($data['vehicle_plate'] ?: 'N/A'));
        } catch (Throwable $e) {}
    }
    manager_send_json(['success' => true, 'message' => 'Customer has been saved successfully.', 'id' => $newId, 'customer_id' => $customerId]);
}

function manager_update_customer(): void {
    global $pdo, $station_id, $role, $me;

    $id = (int)($_POST['id'] ?? $_POST['customer_id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $data = manager_validate_customer_payload();
    $fullName = trim($data['first_name'] . ' ' . $data['middle_name'] . ' ' . $data['last_name']);

    $updateValues = [
        'name'           => $fullName,
        'first_name'     => $data['first_name'],
        'middle_name'    => $data['middle_name'],
        'last_name'      => $data['last_name'],
        'contact_number' => $data['contact_number'],
        'phone'          => $data['contact_number'],
        'email'          => $data['email'],
        'address'        => $data['address'],
        'type'           => (($data['credit_limit'] ?? 0) > 0 ? 'credit' : 'cash'),
        'status'         => $data['status'],
        'account_status' => $data['status'],
        'vehicle_plate'  => $data['vehicle_plate'],
        'plate_number'   => $data['vehicle_plate'],
        'vehicle_make'   => $data['vehicle_make'],
        'vehicle_brand'  => $data['vehicle_make'],
        'vehicle_model'  => $data['vehicle_model'],
        'vehicle_type'   => $data['vehicle_type'],
        'credit_limit'   => $data['credit_limit'],
        'credit_terms'   => $data['credit_terms'],
        'gov_id_type'    => $data['gov_id_type'],
        'loyalty_card_no' => $data['loyalty_card_no'],
        'updated_by'     => $me['id'] ?? null,
    ];

    if ($data['gov_id_file']) $updateValues['gov_id_file'] = $data['gov_id_file'];
    if ($data['cr_file'])     $updateValues['cr_file']     = $data['cr_file'];
    if ($data['or_file'])     $updateValues['or_file']     = $data['or_file'];

    customer_update_existing($pdo, $updateValues, 'id = ?', [$id], [
        'updated_at' => 'NOW()',
    ]);

    if (!empty($data['loyalty_card_no'])) {
        try {
            require_once __DIR__ . '/../backend/loyalty_schema_fix.php';
            get_or_create_loyalty_account($pdo, $id, $data['loyalty_card_no']);
            $pdo->prepare("UPDATE loyalty_accounts SET card_number = ? WHERE customer_id = ?")->execute([$data['loyalty_card_no'], $id]);
        } catch (Throwable $e) {}
    }

    if (isset($_POST['vehicles_json'])) {
        $vList = json_decode($_POST['vehicles_json'], true) ?: [];
        foreach ($vList as $v) {
            $plate = strtoupper(trim($v['plate_number'] ?? ''));
            if ($plate !== '') {
                try {
                    $vCheck = $pdo->prepare("SELECT id FROM customer_vehicles WHERE customer_id = ? AND plate_number = ? LIMIT 1");
                    $vCheck->execute([$id, $plate]);
                    if (!$vCheck->fetchColumn()) {
                        $vStmt = $pdo->prepare("INSERT INTO customer_vehicles (customer_id, plate_number, vehicle_type, brand, model, year_model, color, engine_no, chassis_no, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                        $vStmt->execute([
                            $id,
                            $plate,
                            trim($v['vehicle_type'] ?? ''),
                            trim($v['brand'] ?? ''),
                            trim($v['model'] ?? ''),
                            trim($v['year_model'] ?? ''),
                            trim($v['color'] ?? ''),
                            trim($v['engine_no'] ?? ''),
                            trim($v['chassis_no'] ?? '')
                        ]);
                        manager_log_timeline($id, 'Vehicle Added', "Vehicle {$plate} added.");
                    }
                } catch (Throwable $e) {}
            }
        }
    }

    manager_log_timeline($id, 'Customer Updated', "Customer details updated by Manager.");
    manager_audit('Update Customer', "Updated customer {$fullName}", $id);
    if (function_exists('log_activity')) {
        try {
            log_activity($pdo, (int)($me['id'] ?? 0), 'Update Customer', "Updated customer details for {$fullName} (ID #{$id})");
        } catch (Throwable $e) {}
    }
    manager_send_json(['success' => true, 'message' => 'Customer has been updated successfully.']);
}

function manager_archive_customer(): void {
    global $pdo, $me;

    $id      = (int)($_POST['id'] ?? 0);
    $reason  = trim($_POST['reason'] ?? 'Others');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $updated = customer_update_existing($pdo, [
        'status'          => 'archived',
        'account_status'  => 'archived',
        'archive_reason'  => $reason,
        'archive_remarks' => $remarks,
        'archived_by'     => $me['id'] ?? null,
        'updated_by'      => $me['id'] ?? null,
    ], 'id = ?', [$id], [
        'archived_at' => 'NOW()',
        'updated_at'  => 'NOW()',
    ]);

    if ($updated < 1) {
        throw new Exception('Customer not found or already archived.');
    }

    manager_log_timeline($id, 'Archived', "Customer archived. Reason: {$reason}. Remarks: {$remarks}");
    manager_audit('Archive Customer', "Archived customer ID {$id}. Reason: {$reason}", $id);
    if (function_exists('log_activity')) {
        try {
            log_activity($pdo, (int)($me['id'] ?? 0), 'Archive Customer', "Archived customer ID {$id}. Reason: {$reason}");
        } catch (Throwable $e) {}
    }
    manager_send_json(['success' => true, 'message' => 'Customer has been archived successfully.']);
}

function manager_restore_customer(): void {
    global $pdo, $me;

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $updated = customer_update_existing($pdo, [
        'status'          => 'active',
        'account_status'  => 'active',
        'archive_reason'  => null,
        'archive_remarks' => null,
        'archived_at'     => null,
        'archived_by'     => null,
        'updated_by'      => $me['id'] ?? null,
    ], 'id = ?', [$id], [
        'updated_at' => 'NOW()',
    ]);

    if ($updated < 1) {
        throw new Exception('Customer not found or already active.');
    }

    manager_log_timeline($id, 'Restored', "Customer account restored to Active status.");
    manager_audit('Restore Customer', "Restored customer ID {$id} to Active status.", $id);
    if (function_exists('log_activity')) {
        try {
            log_activity($pdo, (int)($me['id'] ?? 0), 'Restore Customer', "Restored customer ID {$id} to Active status");
        } catch (Throwable $e) {}
    }
    manager_send_json(['success' => true, 'message' => 'Customer account restored successfully.']);
}

function manager_deactivate_customer(): void {
    global $pdo, $me;

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $updated = customer_update_existing($pdo, [
        'status' => 'inactive',
        'account_status' => 'inactive',
        'updated_by' => $me['id'] ?? null,
    ], 'id = ?', [$id], [
        'updated_at' => 'NOW()',
    ]);

    if ($updated < 1) {
        throw new Exception('Customer not found or already inactive.');
    }

    manager_log_timeline($id, 'Deactivated', "Customer set to Inactive status.");
    manager_audit('Deactivate', "Deactivated customer ID {$id}", $id);
    manager_send_json(['success' => true, 'message' => 'Customer has been deactivated.']);
}

function manager_archive_vehicle(): void {
    global $pdo;
    $vId = (int)($_POST['vehicle_id'] ?? 0);
    if ($vId <= 0) {
        throw new Exception('Vehicle ID required.');
    }
    $stmt = $pdo->prepare("UPDATE customer_vehicles SET status = 'archived' WHERE id = ?");
    $stmt->execute([$vId]);
    manager_send_json(['success' => true, 'message' => 'Vehicle archived successfully.']);
}

function manager_restore_vehicle(): void {
    global $pdo;
    $vId = (int)($_POST['vehicle_id'] ?? 0);
    if ($vId <= 0) {
        throw new Exception('Vehicle ID required.');
    }
    $stmt = $pdo->prepare("UPDATE customer_vehicles SET status = 'active' WHERE id = ?");
    $stmt->execute([$vId]);
    manager_send_json(['success' => true, 'message' => 'Vehicle restored successfully.']);
}

function manager_count_pending_requests(): int {
    global $pdo;
    if (!manager_has_table($pdo, 'customer_requests')) {
        return 0;
    }

    try {
        $sid = (int)user_station_id();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM customer_requests WHERE LOWER(status) = 'pending' AND station_id = ?");
        $stmt->execute([$sid]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function manager_list_customer_requests(): void {
    global $pdo;

    if (!manager_has_table($pdo, 'customer_requests')) {
        manager_send_json(['success' => true, 'requests' => []]);
    }

    $sid = (int)user_station_id();
    $stmt = $pdo->prepare("
        SELECT
            cr.*,
            TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS requested_by_name
        FROM customer_requests cr
        LEFT JOIN users u ON u.id = cr.requested_by
        WHERE LOWER(cr.status) = 'pending' AND cr.station_id = ?
        ORDER BY cr.created_at DESC, cr.id DESC
        LIMIT 100
    ");
    $stmt->execute([$sid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['request_no'] = 'CR-' . str_pad((string)$r['id'], 5, '0', STR_PAD_LEFT);
    }
    unset($r);
    manager_send_json(['success' => true, 'requests' => $rows]);
}

function manager_fetch_request_for_review(int $requestId): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM customer_requests WHERE id = ? AND LOWER(status) = 'pending' LIMIT 1");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        throw new Exception('Customer request not found.');
    }
    return $request;
}

function manager_approve_customer_request(): void {
    global $pdo, $me, $station_id, $role;

    $requestId = (int)($_POST['id'] ?? 0);
    if ($requestId <= 0) {
        throw new Exception('Request ID is required.');
    }

    $request = manager_fetch_request_for_review($requestId);
    $customerStation = (int)($request['station_id'] ?? $station_id);
    $userStation = (int)(user_station_id() ?? $station_id);

    if (!customer_can_view_all_stations($role) && $customerStation !== $userStation) {
        throw new Exception('Unauthorized: Customer request belongs to a different station.');
    }

    $pdo->beginTransaction();
    try {
        $customerId = manager_generate_customer_id($customerStation);
        $fullName = trim($request['first_name'] . ' ' . ($request['middle_name'] ?? '') . ' ' . $request['last_name']);

        $newId = customer_insert_existing($pdo, [
            'customer_id'         => $customerId,
            'station_id'          => $customerStation,
            'name'                => $fullName,
            'first_name'          => $request['first_name'],
            'middle_name'         => $request['middle_name'],
            'last_name'           => $request['last_name'],
            'contact_number'      => $request['contact_number'],
            'phone'               => $request['contact_number'],
            'address'             => $request['address'],
            'type'                => 'cash',
            'status'              => 'active',
            'account_status'      => 'active',
            'vehicle_plate'       => strtoupper($request['vehicle_plate'] ?? ''),
            'plate_number'        => strtoupper($request['vehicle_plate'] ?? ''),
            'vehicle_make'        => $request['vehicle_make'] ?? '',
            'vehicle_brand'       => $request['vehicle_make'] ?? '',
            'vehicle_model'       => $request['vehicle_model'] ?? '',
            'vehicle_type'        => $request['vehicle_type'] ?? '',
            'credit_limit'        => 0,
            'outstanding_balance' => 0,
            'current_balance'     => 0,
            'balance'             => 0,
            'registered_by'       => $me['id'] ?? null,
        ], [
            'registered_at' => 'NOW()',
            'created_at'    => 'NOW()',
        ]);

        if (!empty($request['vehicle_plate'])) {
            try {
                $vStmt = $pdo->prepare("INSERT INTO customer_vehicles (customer_id, plate_number, vehicle_type, brand, model, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
                $vStmt->execute([$newId, strtoupper($request['vehicle_plate']), $request['vehicle_type'] ?? '', $request['vehicle_make'] ?? '', $request['vehicle_model'] ?? '']);
            } catch (Throwable $e) {}
        }

        manager_log_timeline($newId, 'Customer Created', "Approved from Staff request #{$requestId}.");

        $remarks = trim($_POST['remarks'] ?? '');
        $stmt = $pdo->prepare("
            UPDATE customer_requests
            SET status = 'approved',
                reviewed_by = ?,
                reviewed_at = NOW(),
                customer_record_id = ?,
                manager_remarks = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$me['id'] ?? null, $newId, $remarks, $requestId]);

        // Notify Staff who submitted the request
        if (!empty($request['requested_by'])) {
            $staffId = (int)$request['requested_by'];
            $reqCode = 'CR-' . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT);
            notify(
                $pdo,
                $staffId,
                'staff',
                'success',
                'customer_request',
                'medium',
                "Customer Registration Request Approved",
                "Customer registration request {$reqCode} for {$fullName} has been approved.",
                "cust_req_app_{$requestId}_s{$staffId}",
                "staff_transactions_hub.php?section=merchandise",
                'customer_request',
                $requestId
            );
        }

        $pdo->commit();

        manager_audit('Approve Customer Request', "Approved customer request CR-" . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT) . " for {$fullName} as {$customerId}", $newId);
        if (function_exists('log_activity')) {
            try {
                log_activity($pdo, (int)($me['id'] ?? 0), 'Approve Customer Request', "Approved customer request CR-" . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT) . " for {$fullName} ({$customerId})");
            } catch (Throwable $e) {}
        }
        manager_send_json(['success' => true, 'message' => 'Customer request approved and customer record created.', 'customer_id' => $customerId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function manager_reject_customer_request(): void {
    global $pdo, $me, $role, $station_id;

    $requestId = (int)($_POST['id'] ?? 0);
    if ($requestId <= 0) {
        throw new Exception('Request ID is required.');
    }
    $request = manager_fetch_request_for_review($requestId);
    $customerStation = (int)($request['station_id'] ?? 0);
    $userStation = (int)(user_station_id() ?? $station_id);

    if (!customer_can_view_all_stations($role) && $customerStation !== $userStation) {
        throw new Exception('Unauthorized: Customer request belongs to a different station.');
    }

    $remarks = trim($_POST['remarks'] ?? '');

    $stmt = $pdo->prepare("
        UPDATE customer_requests
        SET status = 'rejected',
            reviewed_by = ?,
            reviewed_at = NOW(),
            manager_remarks = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$me['id'] ?? null, $remarks, $requestId]);

    // Notify Staff who submitted the request
    if (!empty($request['requested_by'])) {
        $staffId = (int)$request['requested_by'];
        $reqCode = 'CR-' . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT);
        $reasonText = $remarks !== '' ? " Reason: {$remarks}" : "";
        notify(
            $pdo,
            $staffId,
            'staff',
            'warning',
            'customer_request',
            'medium',
            "Customer Registration Request Rejected",
            "Customer registration request {$reqCode} was rejected.{$reasonText}",
            "cust_req_rej_{$requestId}_s{$staffId}",
            "staff_transactions_hub.php?section=merchandise",
            'customer_request',
            $requestId
        );
    }

    manager_audit('Reject Customer Request', "Rejected customer request CR-" . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT) . ($remarks !== '' ? ". Reason: {$remarks}" : ""), $requestId);
    if (function_exists('log_activity')) {
        try {
            log_activity($pdo, (int)($me['id'] ?? 0), 'Reject Customer Request', "Rejected customer registration request CR-" . str_pad((string)$requestId, 5, '0', STR_PAD_LEFT) . ($remarks !== '' ? " (Reason: {$remarks})" : ""));
        } catch (Throwable $e) {}
    }
    manager_send_json(['success' => true, 'message' => 'Customer request rejected.']);
}

// ─────────────────────────────────────────────────────────────────────────────
//  AR HISTORY: fetch credit transactions from merch + job orders
// ─────────────────────────────────────────────────────────────────────────────
function manager_ar_history(): void {
    global $pdo;

    $customerId = (int)($_GET['customer_id'] ?? 0);
    if ($customerId <= 0) {
        throw new Exception('Customer ID is required.');
    }

    $today = date('Y-m-d');
    $arRows = [];

    // Fetch customer's credit terms (default 30 days)
    $daysAdd = 30;
    try {
        $ctStmt = $pdo->prepare("SELECT credit_terms FROM customers WHERE id = ? LIMIT 1");
        $ctStmt->execute([$customerId]);
        $ctVal = $ctStmt->fetchColumn();
        if ($ctVal && preg_match('/(\d+)/', (string)$ctVal, $m)) {
            $daysAdd = (int)$m[1];
        }
    } catch (Throwable $e) {}

    // ── 1. All merchandise / job-order transactions for this customer ─────────
    if (manager_has_table($pdo, 'merchandise_transactions')) {
        try {
            $cols = manager_table_columns($pdo, 'merchandise_transactions');

            $balanceSql   = isset($cols['balance_due'])
                ? 'COALESCE(balance_due, GREATEST(0, total_amount - COALESCE(amount_paid,0)))'
                : 'GREATEST(0, total_amount - COALESCE(amount_paid,0))';
            $amtPaidSql   = isset($cols['amount_paid'])  ? 'COALESCE(amount_paid,0)'  : '0';
            $dueDateSql   = isset($cols['due_date'])      ? 'due_date'                 : 'NULL';
            $payStatusSql = isset($cols['payment_status'])? 'COALESCE(payment_status, \'\')' : "''";
            $txTypeSql    = isset($cols['transaction_type']) ? 'transaction_type'      : "'merchandise'";

            // Build a human-readable description
            if (isset($cols['job_order_service']) && isset($cols['job_order_description'])) {
                $descSql = "COALESCE(NULLIF(TRIM(job_order_service),''), NULLIF(TRIM(job_order_description),''), 'Merchandise Purchase')";
            } elseif (isset($cols['job_order_service'])) {
                $descSql = "COALESCE(NULLIF(TRIM(job_order_service),''), 'Merchandise Purchase')";
            } else {
                $descSql = "'Merchandise Purchase'";
            }

            // Customer filter — check both customer_id and credit_customer_id columns
            $custFilter = isset($cols['credit_customer_id'])
                ? '(customer_id = :cid OR credit_customer_id = :cid2)'
                : 'customer_id = :cid';
            $params = isset($cols['credit_customer_id'])
                ? [':cid' => $customerId, ':cid2' => $customerId]
                : [':cid' => $customerId];

            $voidFilter = isset($cols['void_reason']) ? "AND COALESCE(void_reason,'') = ''" : '';
            $creditFilter = "AND (LOWER(TRIM(payment_method)) IN ('credit account', 'credit', 'ar', 'account receivable') OR (credit_customer_id IS NOT NULL AND credit_customer_id > 0))";

            $sql = "
                SELECT
                    DATE(COALESCE(created_at)) AS ar_date,
                    transaction_id AS reference_no,
                    $txTypeSql AS tx_type,
                    $descSql AS description,
                    COALESCE(total_amount, 0) AS total_amount,
                    $amtPaidSql AS amount_paid,
                    $balanceSql AS balance,
                    $dueDateSql AS due_date,
                    $payStatusSql AS pay_status,
                    COALESCE(payment_method, 'Cash') AS payment_method,
                    id AS db_id
                FROM merchandise_transactions
                WHERE ($custFilter)
                  $creditFilter
                  $voidFilter
                ORDER BY created_at DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $txType  = strtolower($row['tx_type'] ?? '');
                $isJO    = in_array($txType, ['job_order', 'combined']) || strpos($txType, 'job') !== false;
                $balance = max(0, (float)($row['balance'] ?? 0));
                $payStatus = $row['pay_status'] ?? '';

                // If payment_status is Paid, or amount_paid >= total_amount => Paid
                $amtPaid  = (float)($row['amount_paid'] ?? 0);
                $total    = (float)($row['total_amount'] ?? 0);
                if ($amtPaid > 0 && $amtPaid >= $total && $balance <= 0) {
                    $payStatus = 'Paid';
                }

                $arDate = $row['ar_date'] ?? date('Y-m-d');
                $dueDate = !empty($row['due_date']) ? $row['due_date'] : date('Y-m-d', strtotime("$arDate + {$daysAdd} days"));

                $arRows[] = [
                    'date'           => $arDate,
                    'reference'      => $row['reference_no'] ?? '',
                    'tx_type'        => $isJO ? 'Job Order' : 'Merchandise',
                    'description'    => $row['description'] ?? 'Merchandise Purchase',
                    'amount'         => $total,
                    'paid'           => $amtPaid,
                    'balance'        => $balance,
                    'due_date'       => $dueDate,
                    'payment_method' => $row['payment_method'] ?? 'Cash',
                    'status'         => manager_ar_row_status($balance, $dueDate, $today, $payStatus),
                    'source'         => 'merchandise',
                    'db_id'          => $row['db_id'],
                ];
            }
        } catch (Throwable $e) {}
    }

    // ── 2. Standalone Job Orders not already captured in merchandise_transactions ──
    if (manager_has_table($pdo, 'job_orders')) {
        try {
            $jcols = manager_table_columns($pdo, 'job_orders');

            $joBalance   = isset($jcols['balance_due'])
                ? 'COALESCE(balance_due, GREATEST(0, COALESCE(total_cost,0) - COALESCE(amount_paid,0)))'
                : 'GREATEST(0, COALESCE(total_cost,0) - COALESCE(amount_paid,0))';
            $joAmtPaid   = isset($jcols['amount_paid'])    ? 'COALESCE(amount_paid,0)'   : '0';
            $joDueDate   = isset($jcols['due_date'])       ? 'due_date'                  : 'NULL';
            $joPayStatus = isset($jcols['payment_status']) ? 'COALESCE(payment_status, \'\')' : "''";
            $joTotal     = isset($jcols['total_cost'])     ? 'COALESCE(total_cost, 0)'   : '0';
            $joPayMethod = isset($jcols['payment_method']) ? "COALESCE(payment_method,'Cash')" : "'Cash'";

            $joDesc = isset($jcols['service_type'])
                ? "COALESCE(NULLIF(TRIM(service_type),''), 'Job Order Service')"
                : "'Job Order Service'";

            $joCustFilter = isset($jcols['customer_id']) ? 'customer_id = :jcid' : '0=1';
            $joCreditFilter = "AND (LOWER(TRIM(payment_method)) IN ('credit account', 'credit', 'ar', 'account receivable') OR COALESCE(is_credit,0) = 1)";

            $sql = "
                SELECT
                    DATE(COALESCE(created_at)) AS ar_date,
                    job_order_number AS reference_no,
                    $joDesc AS description,
                    $joTotal AS total_amount,
                    $joAmtPaid AS amount_paid,
                    $joBalance AS balance,
                    $joDueDate AS due_date,
                    $joPayStatus AS pay_status,
                    $joPayMethod AS payment_method,
                    id AS db_id
                FROM job_orders
                WHERE $joCustFilter
                  $joCreditFilter
                ORDER BY COALESCE(created_at) DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':jcid' => $customerId]);
            $joRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Exclude references already captured from merchandise_transactions
            $existRefs = array_column($arRows, 'reference');
            foreach ($joRows as $jr) {
                if (in_array($jr['reference_no'], $existRefs)) continue;
                $balance   = max(0, (float)($jr['balance'] ?? 0));
                $amtPaid   = (float)($jr['amount_paid'] ?? 0);
                $total     = (float)($jr['total_amount'] ?? 0);
                $payStatus = $jr['pay_status'] ?? '';
                if ($amtPaid > 0 && $amtPaid >= $total && $balance <= 0) {
                    $payStatus = 'Paid';
                }

                $arDate  = $jr['ar_date'] ?? date('Y-m-d');
                $dueDate = !empty($jr['due_date']) ? $jr['due_date'] : date('Y-m-d', strtotime("$arDate + {$daysAdd} days"));

                $arRows[] = [
                    'date'           => $arDate,
                    'reference'      => $jr['reference_no'] ?? '',
                    'tx_type'        => 'Job Order',
                    'description'    => $jr['description'] ?? 'Job Order Service',
                    'amount'         => $total,
                    'paid'           => $amtPaid,
                    'balance'        => $balance,
                    'due_date'       => $dueDate,
                    'payment_method' => $jr['payment_method'] ?? 'Cash',
                    'status'         => manager_ar_row_status($balance, $dueDate, $today, $payStatus),
                    'source'         => 'job_order',
                    'db_id'          => $jr['db_id'],
                ];
            }
        } catch (Throwable $e) {}
    }

    // Sort by date descending
    usort($arRows, fn($a, $b) => strcmp($b['date'], $a['date']));

    // ── 3. Summary ───────────────────────────────────────────────────────────
    $totalMerch = 0.0; $totalJO = 0.0; $totalPaid = 0.0;
    $totalOutstanding = 0.0; $totalOverdue = 0.0; $nextDue = null;
    $totalMerchBal = 0.0; $totalJOBal = 0.0;

    foreach ($arRows as $r) {
        if ($r['tx_type'] === 'Merchandise') {
            $totalMerch += $r['amount'];
            $totalMerchBal += $r['balance'];
        } else {
            $totalJO += $r['amount'];
            $totalJOBal += $r['balance'];
        }
        $totalPaid        += $r['paid'];
        $totalOutstanding += $r['balance'];
        if ($r['balance'] > 0 && $r['status'] === 'Overdue') $totalOverdue += $r['balance'];
        if ($r['balance'] > 0 && $r['due_date']) {
            if (!$nextDue || $r['due_date'] < $nextDue) $nextDue = $r['due_date'];
        }
    }

    // ── 4. Payment history — merge all payment sources ───────────────────────
    $payments = [];

    // Source A: Manual AR payments from customer_credit_transactions
    if (manager_has_table($pdo, 'customer_credit_transactions')) {
        try {
            $pStmt = $pdo->prepare("
                SELECT
                    created_at AS pay_date,
                    CONCAT('OR-', LPAD(id, 5, '0')) AS receipt_no,
                    '' AS reference_no,
                    COALESCE(payment_method, 'Cash') AS payment_method,
                    amount AS amount_paid,
                    COALESCE(remarks, '') AS remarks,
                    'Credit Payment' AS source_type
                FROM customer_credit_transactions
                WHERE customer_id = ?
                ORDER BY created_at DESC
            ");
            $pStmt->execute([$customerId]);
            $cctRows = $pStmt->fetchAll(PDO::FETCH_ASSOC);
            $payments = array_merge($payments, $cctRows);
        } catch (Throwable $e) {}
    }

    // Source B: Merchandise & Job Order transactions (paid)
    if (manager_has_table($pdo, 'merchandise_transactions')) {
        try {
            $mStmt = $pdo->prepare("
                SELECT
                    created_at AS pay_date,
                    CONCAT('OR-MT-', SUBSTR(transaction_id, -6)) AS receipt_no,
                    transaction_id AS reference_no,
                    COALESCE(payment_method, 'Cash') AS payment_method,
                    COALESCE(amount_paid, total_amount, 0) AS amount_paid,
                    CASE 
                        WHEN transaction_type = 'job_order' THEN 'Job Order Payment'
                        WHEN transaction_type = 'combined'  THEN 'Combined Payment'
                        ELSE 'Merchandise Payment'
                    END AS remarks,
                    CASE 
                        WHEN transaction_type = 'job_order' THEN 'Job Order'
                        WHEN transaction_type = 'combined'  THEN 'Combined'
                        ELSE 'Merchandise'
                    END AS source_type
                FROM merchandise_transactions
                WHERE (customer_id = ? OR credit_customer_id = ?)
                  AND COALESCE(payment_status, '') IN ('Paid', 'paid', 'Completed', 'completed')
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $mStmt->execute([$customerId, $customerId]);
            $mRows = $mStmt->fetchAll(PDO::FETCH_ASSOC);
            $payments = array_merge($payments, $mRows);
        } catch (Throwable $e) {}
    }

    // Source C: Job orders with payments
    if (manager_has_table($pdo, 'job_orders')) {
        try {
            $joPayCols = manager_table_columns($pdo, 'job_orders');
            if (isset($joPayCols['amount_paid']) && isset($joPayCols['payment_method'])) {
                $jPStmt = $pdo->prepare("
                    SELECT
                        created_at AS pay_date,
                        CONCAT('OR-JO-', LPAD(id, 5, '0')) AS receipt_no,
                        job_order_number AS reference_no,
                        COALESCE(payment_method, 'Cash') AS payment_method,
                        COALESCE(amount_paid, 0) AS amount_paid,
                        CONCAT('Job Order: ', service_type) AS remarks,
                        'Job Order' AS source_type
                    FROM job_orders
                    WHERE customer_id = ?
                      AND COALESCE(amount_paid, 0) > 0
                    ORDER BY created_at DESC
                    LIMIT 30
                ");
                $jPStmt->execute([$customerId]);
                $joPayRows = $jPStmt->fetchAll(PDO::FETCH_ASSOC);
                // Avoid duplicates with merchandise_transactions job orders
                $existRefs = array_column($payments, 'reference_no');
                foreach ($joPayRows as $jp) {
                    if (!in_array($jp['reference_no'], $existRefs)) {
                        $payments[] = $jp;
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    // Source D: payment_audit_log (if any)
    if (manager_has_table($pdo, 'payment_audit_log')) {
        try {
            $palCols = manager_table_columns($pdo, 'payment_audit_log');
            if (isset($palCols['customer_id']) || isset($palCols['record_id'])) {
                // payment_audit_log doesn't have customer_id directly — join via merchandise_transactions
                $palStmt = $pdo->prepare("
                    SELECT
                        pal.logged_at AS pay_date,
                        CONCAT('OR-PAL-', LPAD(pal.id, 5, '0')) AS receipt_no,
                        mt.transaction_id AS reference_no,
                        COALESCE(pal.payment_method, 'Cash') AS payment_method,
                        COALESCE(pal.amount_paid, 0) AS amount_paid,
                        COALESCE(pal.remarks, 'Payment recorded') AS remarks,
                        'Payment Log' AS source_type
                    FROM payment_audit_log pal
                    JOIN merchandise_transactions mt ON mt.id = pal.record_id AND pal.record_source = 'merchandise_transactions'
                    WHERE mt.customer_id = ? OR mt.credit_customer_id = ?
                    ORDER BY pal.logged_at DESC
                    LIMIT 30
                ");
                $palStmt->execute([$customerId, $customerId]);
                $palRows = $palStmt->fetchAll(PDO::FETCH_ASSOC);
                $existRefs2 = array_column($payments, 'reference_no');
                foreach ($palRows as $pr) {
                    if (!in_array($pr['reference_no'], $existRefs2)) {
                        $payments[] = $pr;
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    // Sort all payments by date descending
    usort($payments, fn($a, $b) => strcmp($b['pay_date'] ?? '', $a['pay_date'] ?? ''));


    // Get credit limit from customers table
    $creditLimit = 0.0;
    try {
        $clStmt = $pdo->prepare("SELECT COALESCE(credit_limit,0) FROM customers WHERE id = ? LIMIT 1");
        $clStmt->execute([$customerId]);
        $creditLimit = (float)$clStmt->fetchColumn();
    } catch (Throwable $e) {}

    $availableCredit = max(0, $creditLimit - $totalOutstanding);

    // Compute accurate overall status
    $overallStatus = 'Good Standing';
    if ($totalOverdue > 0) {
        $overallStatus = 'Overdue';
    } elseif ($totalOutstanding > 0) {
        $overallStatus = 'Outstanding';
    } elseif (count($arRows) > 0) {
        $overallStatus = 'Good Standing';
    } else {
        $overallStatus = 'No AR';
    }

    // Fetch system payment methods from payment_methods table if exists
    $systemPaymentMethods = ['Cash', 'Credit Card', 'Debit Card', 'GCash', 'Bank Transfer', 'Check', 'E-Wallet', 'Credit Account'];
    if (manager_has_table($pdo, 'payment_methods')) {
        try {
            $pmCols = manager_table_columns($pdo, 'payment_methods');
            $nameCol = isset($pmCols['name']) ? 'name' : (isset($pmCols['method_name']) ? 'method_name' : (isset($pmCols['payment_method']) ? 'payment_method' : ''));
            if ($nameCol) {
                $pmStmt = $pdo->query("SELECT DISTINCT `$nameCol` FROM payment_methods WHERE (status IS NULL OR status = 'active' OR status = '1') AND TRIM(`$nameCol`) != ''");
                $dbMethods = $pmStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($dbMethods)) {
                    $systemPaymentMethods = array_values(array_unique(array_filter(array_map('trim', $dbMethods))));
                }
            }
        } catch (Throwable $e) {}
    }

    manager_send_json([
        'success'         => true,
        'ar_rows'         => $arRows,
        'payments'        => $payments,
        'payment_methods' => $systemPaymentMethods,
        'summary'         => [
            'total_merchandise_credit' => $totalMerchBal,
            'total_job_order_credit'   => $totalJOBal,
            'total_credit_purchases'   => $totalMerch + $totalJO,
            'total_payments'           => $totalPaid,
            'outstanding_balance'      => $totalOutstanding,
            'overdue_balance'          => $totalOverdue,
            'credit_limit'             => $creditLimit,
            'available_credit'         => $availableCredit,
            'next_due_date'            => $nextDue,
            'payment_status'           => $overallStatus,
        ],
    ]);
}

function manager_ar_row_status(float $balance, string $dueDate, string $today, string $payStatus): string {
    if ($balance <= 0 || strtolower($payStatus) === 'paid') return 'Paid';
    if ($dueDate && $dueDate < $today)                      return 'Overdue';
    return 'Outstanding';
}

// ─────────────────────────────────────────────────────────────────────────────
//  RECORD AR PAYMENT
// ─────────────────────────────────────────────────────────────────────────────
function manager_record_ar_payment(): void {
    global $pdo, $me;

    $customerId    = (int)($_POST['customer_id'] ?? 0);
    $amount        = (float)($_POST['amount'] ?? 0);
    $rawMethod     = trim($_POST['payment_method'] ?? 'Cash');
    $rawProvider   = trim($_POST['ewallet_provider'] ?? '');
    $norm          = function_exists('normalize_payment_type') ? normalize_payment_type($rawMethod, $rawProvider) : ['payment_type'=>$rawMethod, 'provider'=>$rawProvider];
    $paymentMethod = $norm['payment_type'];
    $ewalletProvider = $norm['provider'] ?: ($paymentMethod === 'E-Wallet' ? 'GCash' : null);
    $remarks       = trim($_POST['remarks'] ?? '');
    $referenceNo   = trim($_POST['reference_no'] ?? '');
    $source        = trim($_POST['source'] ?? '');      // 'merchandise' or 'job_order'
    $sourceId      = (int)($_POST['source_id'] ?? 0);  // db_id of the specific record

    if ($customerId <= 0) throw new Exception('Customer ID is required.');
    if ($amount <= 0)     throw new Exception('Payment amount must be greater than zero.');

    $pdo->beginTransaction();

    try {
        // ── Record into customer_credit_transactions ──────────────────────────
        if (manager_has_table($pdo, 'customer_credit_transactions')) {
            $stationId = (int)(user_station_id() ?: 1);
            $payDesc = $paymentMethod . ($paymentMethod === 'E-Wallet' && !empty($ewalletProvider) ? " ($ewalletProvider)" : '');
            $paymentNote = $remarks ?: "Payment for {$referenceNo}";
            if ($paymentMethod === 'E-Wallet' && !empty($ewalletProvider) && strpos($paymentNote, $ewalletProvider) === false) {
                $paymentNote .= " via {$ewalletProvider}";
            }
            $pdo->prepare("
                INSERT INTO customer_credit_transactions (station_id, customer_id, amount, payment_method, remarks, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ")->execute([$stationId, $customerId, $amount, $paymentMethod, $paymentNote]);
        }

        // ── Apply payment to specific transaction ─────────────────────────────
        $remaining = $amount;

        if ($sourceId > 0 && $source === 'merchandise' && manager_has_table($pdo, 'merchandise_transactions')) {
            $txStmt = $pdo->prepare("SELECT COALESCE(balance_due, total_amount - COALESCE(amount_paid,0)) AS bal, COALESCE(amount_paid,0) AS paid FROM merchandise_transactions WHERE id = ? LIMIT 1");
            $txStmt->execute([$sourceId]);
            $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
            if ($tx) {
                $newPaid = (float)$tx['paid'] + min($remaining, (float)$tx['bal']);
                $newBal  = max(0, (float)$tx['bal'] - $remaining);
                $newStatus = $newBal <= 0 ? 'Paid' : 'Partial';
                $pdo->prepare("UPDATE merchandise_transactions SET amount_paid=?, balance_due=?, payment_status=?, updated_at=NOW() WHERE id=?")
                    ->execute([$newPaid, $newBal, $newStatus, $sourceId]);
                $remaining = max(0, $remaining - (float)$tx['bal']);
            }
        } elseif ($sourceId > 0 && $source === 'job_order' && manager_has_table($pdo, 'job_orders')) {
            $jStmt = $pdo->prepare("SELECT COALESCE(balance_due, total_cost - COALESCE(amount_paid,0)) AS bal, COALESCE(amount_paid,0) AS paid FROM job_orders WHERE id = ? LIMIT 1");
            $jStmt->execute([$sourceId]);
            $jx = $jStmt->fetch(PDO::FETCH_ASSOC);
            if ($jx) {
                $newPaid = (float)$jx['paid'] + min($remaining, (float)$jx['bal']);
                $newBal  = max(0, (float)$jx['bal'] - $remaining);
                $newStatus = $newBal <= 0 ? 'Paid' : 'Partial';
                $pdo->prepare("UPDATE job_orders SET amount_paid=?, balance_due=?, payment_status=?, updated_at=NOW() WHERE id=?")
                    ->execute([$newPaid, $newBal, $newStatus, $sourceId]);
                $remaining = max(0, $remaining - (float)$jx['bal']);
            }
        }

        // ── Update customers.outstanding_balance ──────────────────────────────
        try {
            $obStmt = $pdo->prepare("SELECT COALESCE(outstanding_balance,0) FROM customers WHERE id=? LIMIT 1");
            $obStmt->execute([$customerId]);
            $currentOB = (float)$obStmt->fetchColumn();
            $newOB = max(0, $currentOB - $amount);
            $pdo->prepare("UPDATE customers SET outstanding_balance=?, updated_at=NOW() WHERE id=?")
                ->execute([$newOB, $customerId]);
        } catch (Throwable $e) {}

        $pdo->commit();

        manager_audit('Payment', "Recorded AR payment of ₱" . number_format($amount,2) . " for customer #{$customerId}. Ref: {$referenceNo}", $customerId);
        manager_send_json(['success' => true, 'message' => 'Payment recorded successfully.']);

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
