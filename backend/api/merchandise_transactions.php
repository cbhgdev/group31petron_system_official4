<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../customer_module_helpers.php';

// Ensure session is active (lib.php may have already started it)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Auth check — session stores user under $_SESSION['user']
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

customer_ensure_optional_columns($pdo);
customer_ensure_request_table($pdo);

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetRequest($pdo, $station_id, $role, $me);
            break;
        case 'POST':
            handlePostRequest($pdo, $station_id, $role, $me);
            break;
        case 'PUT':
            handlePutRequest($pdo, $station_id, $role, $me);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("Merchandise Transactions API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}

function handleGetRequest($pdo, $station_id, $role, $me) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_products':
            getMerchandiseProducts($pdo, $station_id);
            break;
        case 'get_customers':
            getCreditCustomers($pdo, $station_id);
            break;
        case 'lookup_job_order':
            lookupJobOrder($pdo, $station_id);
            break;
        case 'get_pending_transactions':
            echo json_encode([
                'success' => true,
                'transactions' => [],
                'message' => 'Transactions are official when saved; there is no manager approval queue.'
            ]);
            break;
        case 'get_transaction_details':
            getTransactionDetails($pdo, $station_id, $role);
            break;
        case 'get_payment_methods':
            getPaymentMethods($pdo);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handlePostRequest($pdo, $station_id, $role, $me) {
    $action = $_POST['action'] ?? '';

    // Also support JSON body action (from fetch() calls)
    // Read the raw body ONCE and cache it so downstream functions can reuse it
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $GLOBALS['_cached_request_body'] = $rawBody;

    if (empty($action)) {
        $body = json_decode($rawBody, true);
        $action = $body['action'] ?? '';
    }
    
    switch ($action) {
        case 'create_transaction':
        case 'create_merchandise_transaction':
            createMerchandiseTransaction($pdo, $station_id, $role, $me);
            break;
        case 'validate_transaction':
        case 'reject_transaction':
            http_response_code(410);
            echo json_encode([
                'error' => 'Transactions are official when saved by staff. Use Adjust, Void, or Correct for manager corrections.'
            ]);
            break;
        case 'adjust_transaction':
            adjustTransaction($pdo, $station_id, $role, $me);
            break;
        case 'log_failed_transaction':
            logFailedTransactionAttempt($pdo, $station_id, $me);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function getTransactionDetails($pdo, $station_id, $role) {
    $mt_id = (int)($_GET['mt_id'] ?? 0);
    if (!$mt_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing transaction ID']);
        return;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT mt.*,
                   COALESCE(u.name, u.username, 'Staff') AS encoder_name,
                   COALESCE(s.name, 'Petron Station') AS station_name
            FROM merchandise_transactions mt
            LEFT JOIN users u ON u.id = mt.staff_id
            LEFT JOIN stations s ON s.id = mt.station_id
            WHERE mt.id = ? AND mt.station_id = ?
            LIMIT 1
        ");
        $stmt->execute([$mt_id, $station_id]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$txn) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found']);
            return;
        }
        $items_stmt = $pdo->prepare("
            SELECT *, COALESCE(item_type,'merchandise') AS item_type
            FROM merchandise_transaction_items
            WHERE transaction_id = ?
            ORDER BY id ASC
        ");
        $items_stmt->execute([$txn['id']]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'transaction' => $txn, 'items' => $items]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}


function getMerchandiseProducts($pdo, $station_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                ip.id AS product_id,
                ip.product_name,
                COALESCE(NULLIF(ip.sku, ''), ip.product_name) AS sku,
                COALESCE(ip.category, 'General') AS category,
                COALESCE(NULLIF(ip.size, ''), '') AS size,
                COALESCE(ip.unit_cost, 0) AS unit_price,
                COALESCE(si.stock_level, 0) AS stock_level,
                CASE 
                    WHEN si.stock_level > 0 AND COALESCE(si.status, 'active') = 'active' THEN 'Available'
                    WHEN si.stock_level <= 0 THEN 'Out of Stock'
                    ELSE 'Not Available'
                END as availability_status
            FROM inventory_products ip
            LEFT JOIN station_inventory si 
                ON ip.id = si.product_id 
                AND si.station_id = ?
            WHERE COALESCE(ip.category, '') <> 'Fuel'
            ORDER BY COALESCE(ip.category, 'General'), ip.product_name
        ");
        $stmt->execute([$station_id]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'products' => $products
        ]);
    } catch (Exception $e) {
        throw new Exception('Error fetching products: ' . $e->getMessage());
    }
}

function getCreditCustomers($pdo, $station_id) {
    try {
        $nameExpr = customer_display_name_expr($pdo, 'c');
        $statusExpr = customer_status_expr($pdo, 'c');
        $creditLimitExpr = customer_credit_limit_expr($pdo, 'c');
        $balanceExpr = customer_balance_expr($pdo, 'c');

        $stmt = $pdo->prepare("
            SELECT
                c.id AS user_id,
                c.id,
                {$nameExpr} AS name,
                {$creditLimitExpr} AS credit_limit,
                {$balanceExpr} AS balance,
                ({$creditLimitExpr} - {$balanceExpr}) AS available_credit
            FROM customers c
            WHERE c.station_id = ?
              AND LOWER({$statusExpr}) = 'active'
              AND (c.type = 'credit' OR {$creditLimitExpr} > 0)
            ORDER BY {$nameExpr}
        ");
        $stmt->execute([$station_id]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'customers' => $customers
        ]);
    } catch (Exception $e) {
        throw new Exception('Error fetching customers: ' . $e->getMessage());
    }
}

function lookupJobOrder($pdo, $station_id) {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'results' => []]);
        return;
    }
    try {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(jo.job_order_number, jo.job_order_id, CONCAT('JO-', jo.id)) AS jo_ref,
                COALESCE(jo.customer_name, c.name, 'Walk-in Customer')               AS customer_name,
                COALESCE(jo.service_type, jo.service_description, '')                AS service_type,
                jo.id
            FROM job_orders jo
            LEFT JOIN customers c ON c.id = jo.customer_id
            WHERE jo.station_id = ?
              AND (
                  jo.job_order_number LIKE ?
                  OR jo.job_order_id  LIKE ?
                  OR c.name           LIKE ?
                  OR jo.customer_name LIKE ?
              )
            ORDER BY jo.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$station_id, $like, $like, $like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'results' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'results' => [], 'error' => $e->getMessage()]);
    }
}

function getPendingTransactions($pdo, $station_id, $role) {
    // Only managers and above can view pending transactions
    if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                mt.*,
                u.name AS staff_name,
                c.name AS customer_name,
                mt.validation_status
            FROM merchandise_transactions mt
            LEFT JOIN users u ON mt.staff_id = u.id
            LEFT JOIN customers c ON mt.credit_customer_id = c.id
            WHERE mt.station_id = ? AND mt.validation_status = 'Pending'
            ORDER BY mt.created_at DESC
        ");
        $stmt->execute([$station_id]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get transaction items and derive transaction_type for each
        foreach ($transactions as &$transaction) {
            $iStmt = $pdo->prepare("
                SELECT 
                    mti.*,
                    ip.product_name,
                    ip.category,
                    ip.size
                FROM merchandise_transaction_items mti
                LEFT JOIN inventory_products ip ON mti.product_id = ip.id
                WHERE mti.transaction_id = ?
            ");
            $iStmt->execute([$transaction['id']]);
            $transaction['items'] = $iStmt->fetchAll(PDO::FETCH_ASSOC);

            // Derive transaction_type if not stored (backward compat)
            if (empty($transaction['transaction_type'])) {
                $has_svc = false; $has_mrc = false;
                foreach ($transaction['items'] as $it) {
                    if (($it['item_type'] ?? 'merchandise') === 'service') $has_svc = true;
                    else $has_mrc = true;
                }
                if (!empty($transaction['job_order_service'])) $has_svc = true;
                $transaction['transaction_type'] = ($has_svc && $has_mrc) ? 'combined'
                    : ($has_svc ? 'job_order' : 'merchandise');
            }
        }
        unset($transaction); // break reference
        
        echo json_encode([
            'success' => true,
            'transactions' => $transactions
        ]);
    } catch (Exception $e) {
        throw new Exception('Error fetching pending transactions: ' . $e->getMessage());
    }
}




function getPaymentMethods($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT method_key, method_name, icon_class, color_class
            FROM payment_method_config
            WHERE is_active = 1
            ORDER BY sort_order
        ");
        $stmt->execute();
        $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'payment_methods' => $payment_methods
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'payment_methods' => [],
            'error' => 'Payment methods are not configured in the database.'
        ]);
    }
}

function createMerchandiseTransaction($pdo, $station_id, $role, $me) {
    // Allow staff, cashier, pump_attendant, admin, manager, and superadmin to create transactions
    if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'admin', 'manager', 'superadmin', 'cashier/staff'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    // Use cached body (already read in handlePostRequest) to avoid consuming the stream twice
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        return;
    }

    // customer_name is no longer required — auto-default to Walk-in Customer
    $required_fields = ['items', 'payment_method'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }

    // Auto-default customer to Walk-in Customer
    if (empty($data['customer_name'])) {
        $data['customer_name'] = 'Walk-in Customer';
    }
    // Derive first/last name from payload or split from customer_name
    $data['customer_first_name'] = $data['customer_first_name'] ?? null;
    $data['customer_last_name']  = $data['customer_last_name']  ?? null;
    if (empty($data['customer_first_name']) && $data['customer_name'] !== 'Walk-in Customer') {
        $parts = explode(' ', trim($data['customer_name']), 2);
        $data['customer_first_name'] = $parts[0] ?? null;
        $data['customer_last_name']  = $parts[1] ?? null;
    }

    if (empty($data['items']) || !is_array($data['items'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Items array is required']);
        return;
    }


    $selected_customer_id = (int)($data['customer_id'] ?? 0);

    if ($selected_customer_id > 0) {
        // Verify the customer exists and is active
        try {
            $nameExpr = customer_display_name_expr($pdo, 'c');
            $firstExpr = customer_first_name_expr($pdo, 'c');
            $lastExpr = customer_last_name_expr($pdo, 'c');
            $statusExpr = customer_status_expr($pdo, 'c');
            $stmt = $pdo->prepare("
                SELECT
                    c.id,
                    {$nameExpr} AS display_name,
                    {$firstExpr} AS first_name,
                    {$lastExpr} AS last_name
                FROM customers c
                WHERE c.id = ?
                  AND c.station_id = ?
                  AND LOWER({$statusExpr}) = 'active'
                LIMIT 1
            ");
            $stmt->execute([$selected_customer_id, $station_id]);
            $selectedCustomer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$selectedCustomer) {
                http_response_code(400);
                echo json_encode(['error' => 'Selected customer was not found or is inactive.']);
                return;
            }

            $data['customer_id'] = (int)$selectedCustomer['id'];
            $data['customer_name'] = $selectedCustomer['display_name'] ?: $data['customer_name'];
            $data['customer_first_name'] = $selectedCustomer['first_name'] ?: ($data['customer_first_name'] ?? null);
            $data['customer_last_name'] = $selectedCustomer['last_name'] ?: ($data['customer_last_name'] ?? null);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Unable to validate selected customer: ' . $e->getMessage()]);
            return;
        }
    } else {
        // Walk-in / non-registered customer transaction — no registered customer account required
        $data['customer_id'] = null;
        $fn = trim((string)($data['customer_first_name'] ?? ''));
        $ln = trim((string)($data['customer_last_name'] ?? ''));
        $cn = trim((string)($data['customer_name'] ?? ''));
        if ($fn !== '' || $ln !== '') {
            $data['customer_first_name'] = $fn ?: null;
            $data['customer_last_name']  = $ln ?: null;
            $data['customer_name']       = trim($fn . ' ' . $ln);
        } elseif ($cn !== '' && $cn !== 'Walk-in Customer' && $cn !== 'No Customer') {
            $data['customer_name'] = $cn;
            $parts = explode(' ', $cn, 2);
            $data['customer_first_name'] = $parts[0] ?? null;
            $data['customer_last_name']  = $parts[1] ?? null;
        } else {
            $data['customer_name'] = 'Walk-in Customer';
            $data['customer_first_name'] = null;
            $data['customer_last_name'] = null;
        }
    }


    // ── Safe schema migration: run DDL BEFORE opening a transaction ──────────
    // ALTER TABLE causes an implicit commit in MySQL, which would break any
    // open transaction. Run these outside the transaction block.
    // We check each column individually and add only what's missing.
    $columns_to_add = [
        'shift_id'              => 'INT NULL',
        'shift_period'          => 'VARCHAR(50) NULL',
        'shift_name'            => 'VARCHAR(100) NULL',
        'customer_name'         => "VARCHAR(255) NOT NULL DEFAULT 'Walk-in Customer'",
        'customer_id'           => 'INT NULL',
        'customer_first_name'   => "VARCHAR(100) NULL",
        'customer_last_name'    => "VARCHAR(100) NULL",
        'customer_contact'      => "VARCHAR(50) NULL",
        'credit_customer_id'    => 'INT NULL',
        'payment_method'        => "VARCHAR(50) NOT NULL DEFAULT 'Cash'",
        'subtotal_amount'       => 'DECIMAL(10,2) NULL',
        'vat_amount'            => 'DECIMAL(10,2) NULL',
        'amount_tendered'       => 'DECIMAL(10,2) NULL',
        'change_amount'         => 'DECIMAL(10,2) NULL',
        'card_reference'        => 'VARCHAR(100) NULL',
        'card_type'             => 'VARCHAR(50) NULL',
        'card_last_four'        => 'VARCHAR(4) NULL',
        'ewallet_reference'     => 'VARCHAR(100) NULL',
        'ewallet_provider'      => 'VARCHAR(50) NULL',
        'efuel_card_number'     => 'VARCHAR(50) NULL',
        'efuel_reference'       => 'VARCHAR(100) NULL',
        'fleet_card_number'     => 'VARCHAR(50) NULL',
        'fleet_company_name'    => 'VARCHAR(255) NULL',
        'fleet_auth_number'     => 'VARCHAR(50) NULL',
        'fleet_vehicle_plate'   => 'VARCHAR(50) NULL',
        'fleet_odometer'        => 'VARCHAR(50) NULL',
        'fleet_points_earned'   => 'INT NULL',
        'fleet_points_redeemed' => 'INT NULL',
        'credit_company_name'   => 'VARCHAR(255) NULL',
        'credit_account_number' => 'VARCHAR(100) NULL',
        'credit_po_number'      => 'VARCHAR(50) NULL',
        'credit_due_date'       => 'DATE NULL',
        'remarks'               => 'TEXT NULL',
        'validation_status'     => "VARCHAR(20) NOT NULL DEFAULT 'Official'",
        'validated_by'          => 'INT NULL',
        'validated_at'          => 'DATETIME NULL',
        'rejection_reason'      => 'TEXT NULL',
        'adjustment_reason'     => 'TEXT NULL',
        'updated_at'            => 'DATETIME NULL',
        // ── Payment and workflow tracking ─────────────────────────────────────
        'payment_status'        => "VARCHAR(30) NOT NULL DEFAULT 'Pending'",
        'workflow_status'       => "VARCHAR(20) NOT NULL DEFAULT 'Pending'",
        'amount_paid'           => 'DECIMAL(10,2) NULL',
        'balance_due'           => 'DECIMAL(10,2) NULL',
        'inventory_deducted'    => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=stock deducted from station_inventory on staff save'",
        // ── Job Order integration ──────────────────────────────────────────
        'job_order_id'               => 'VARCHAR(50) NULL',
        'job_order_db_id'            => 'INT NULL',
        'job_order_service'          => 'VARCHAR(500) NULL',
        'job_order_description'      => 'TEXT NULL',
        'job_order_vehicle_plate'    => 'VARCHAR(20) NULL',
        'job_order_vehicle_type'     => 'VARCHAR(50) NULL',
        'job_order_vehicle_brand'    => 'VARCHAR(100) NULL',
        'job_order_vehicle_model'    => 'VARCHAR(100) NULL',
        'job_order_year_model'       => 'VARCHAR(20) NULL',
        'job_order_engine_number'    => 'VARCHAR(100) NULL',
        'job_order_chassis_number'   => 'VARCHAR(100) NULL',
        'job_order_estimated_duration' => 'INT NULL',
        'job_order_mechanic_id'      => 'INT NULL',
        'job_order_mechanic_name'    => 'VARCHAR(255) NULL',
        'job_order_contact'          => 'VARCHAR(50) NULL',
        // ── Transaction type classification ───────────────────────────────────
        // 'job_order' = JO only (no merchandise items)
        // 'merchandise' = merchandise only (no service items)
        // 'combined' = JO + merchandise together
        'transaction_type'           => "VARCHAR(20) NOT NULL DEFAULT 'merchandise'",
        // ── Loyalty & Discount fields ─────────────────────────────────────────
        'discount_amount'            => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'loyalty_discount_amount'    => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'loyalty_type'               => 'VARCHAR(64) NULL',
        'loyalty_card_no'            => 'VARCHAR(64) NULL',
        'loyalty_points_earned'      => 'INT NULL',
        'loyalty_points_redeemed'    => 'INT NULL',
    ];

    try {
        // Get existing columns
        $existing = [];
        $colRows = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colRows as $col) {
            $existing[strtolower($col['Field'])] = true;
        }

        foreach ($columns_to_add as $col => $definition) {
            if (!isset($existing[strtolower($col)])) {
                $pdo->exec("ALTER TABLE merchandise_transactions ADD COLUMN `$col` $definition");
            }
        }
    } catch (Exception $e) {
        error_log("Merchandise migration warning: " . $e->getMessage());
        // Non-fatal — continue and let the INSERT fail with a clear error if needed
    }

    // ── Detect current shift (also outside transaction) ───────────────────────
    $shift_id   = $data['shift_id']     ?? null;
    $shift_key  = $data['shift_period'] ?? null;
    $shift_name = $data['shift_name']   ?? null;

    try {
        $stmt = $pdo->prepare("SELECT id, shift_period, shift_name FROM labor_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
        $stmt->execute([$me['id']]);
        $active_session = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($active_session) {
            // Only use labor_session id as shift_id if it actually exists in the shifts table
            $shift_key  = $shift_key  ?: ($active_session['shift_period'] ?? null);
            $shift_name = $shift_name ?: ($active_session['shift_name']   ?? null);
        }
    } catch (Exception $e) {}

    // Resolve shift_id from the shifts table (not labor_sessions)
    // Try to find an active shift for this station/staff
    if (!$shift_id) {
        try {
            $scheck = $pdo->prepare("SELECT id FROM shifts WHERE station_id = ? AND staff_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
            $scheck->execute([$station_id, $me['id']]);
            $active_shift = $scheck->fetch(PDO::FETCH_ASSOC);
            if ($active_shift) {
                $shift_id = $active_shift['id'];
            }
        } catch (Exception $e) {}
    }

    // Validate that shift_id actually exists in shifts table — set NULL if not
    if ($shift_id) {
        try {
            $sv = $pdo->prepare("SELECT id FROM shifts WHERE id = ? LIMIT 1");
            $sv->execute([$shift_id]);
            if (!$sv->fetch()) {
                $shift_id = null; // FK would fail — use NULL instead
            }
        } catch (Exception $e) {
            $shift_id = null;
        }
    }

    if (!$shift_key) {
        try {
            $ct = date('H:i:s');
            $sp = $pdo->prepare("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 AND start_time <= ? AND end_time >= ? ORDER BY sort_order ASC LIMIT 1");
            $sp->execute([$ct, $ct]);
            $sf = $sp->fetch(PDO::FETCH_ASSOC);
            if ($sf) {
                $shift_key  = $sf['shift_key'];
                $shift_name = $sf['shift_name'];
            } else {
                $sp2 = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order DESC LIMIT 1");
                $sf2 = $sp2->fetch(PDO::FETCH_ASSOC);
                if ($sf2) { $shift_key = $sf2['shift_key']; $shift_name = $sf2['shift_name']; }
            }
        } catch (Exception $e) {}
    }

    // ── Calculate totals & VAT-Inclusive Pricing ──────────────────────────────
    // Item selling prices are VAT-inclusive per Petron standard.
    // Gross Amount = sum of (quantity * unit_price)
    $items_subtotal = 0.0;
    foreach ($data['items'] as $item) {
        $items_subtotal += floatval($item['quantity'] ?? 1) * floatval($item['unit_price'] ?? 0);
    }
    $gross_amount = round($items_subtotal, 2);

    // ── Payment method setup ──────────────────────────────────────────────────
    $raw_payment_method  = trim((string)($data['payment_method'] ?? 'Cash'));
    $raw_ewallet_prov    = trim((string)($data['ewallet_provider'] ?? ''));
    $norm_pay            = normalize_payment_type($raw_payment_method, $raw_ewallet_prov);
    $payment_method      = $norm_pay['payment_type'];
    $ewallet_provider    = $norm_pay['provider'] ?: ($raw_ewallet_prov ?: null);

    // Fleet Card is removed from active payment methods
    if ($payment_method === 'Petron Fleet Card') {
        http_response_code(400);
        echo json_encode(['error' => 'Petron Fleet Card has been decommissioned from active payments. Please select Petron Value Card (PVC Points) or another valid payment method.']);
        return;
    }

    // ── Server-side Loyalty Validation & Discount Computation ─────────────────
    require_once __DIR__ . '/../loyalty_schema_fix.php';
    loyalty_ensure_tables($pdo);

    $prog_stmt = $pdo->query("SELECT * FROM loyalty_programs WHERE status = 'active' ORDER BY id ASC LIMIT 1");
    $prog = $prog_stmt ? $prog_stmt->fetch(PDO::FETCH_ASSOC) : null;
    $pts_per_amount = floatval($prog['points_per_amount'] ?? 100.00) ?: 100.00;
    $redemption_val = floatval($prog['redemption_value'] ?? 1.00) ?: 1.00;
    $min_redeem_pts = intval($prog['minimum_redeem_points'] ?? 1);

    $target_customer_id = intval($data['credit_customer_id'] ?? $data['customer_id'] ?? 0);
    $card_no = trim((string)($data['loyalty_card_no'] ?? ''));

    if ($target_customer_id <= 0 && $card_no !== '') {
        $cFind = $pdo->prepare("SELECT id FROM customers WHERE customer_id = ? OR id_number = ? LIMIT 1");
        $cFind->execute([$card_no, $card_no]);
        $target_customer_id = intval($cFind->fetchColumn());
    }
    if ($target_customer_id <= 0 && !empty($data['customer_name']) && $data['customer_name'] !== 'Walk-in') {
        $cFind2 = $pdo->prepare("SELECT id FROM customers WHERE LOWER(name) = LOWER(?) OR LOWER(CONCAT_WS(' ', first_name, last_name)) = LOWER(?) LIMIT 1");
        $cFind2->execute([$data['customer_name'], $data['customer_name']]);
        $target_customer_id = intval($cFind2->fetchColumn());
    }

    $loyalty_acc = null;
    $curr_points_balance = 0;
    $validated_points_redeemed = 0;
    $loyalty_discount_amount = 0.0;

    // Fetch authoritative linked PVC loyalty account without auto-creating
    if ($target_customer_id > 0) {
        $loyalty_acc = get_customer_pvc_account($pdo, $target_customer_id);
        if ($loyalty_acc) {
            $curr_points_balance = (int)($loyalty_acc['points_balance'] ?? 0);
            $card_no = $loyalty_acc['card_number'] ?: $card_no;
        }
    }

    $is_pvc_payment = ($payment_method === 'Petron Value Card (PVC Points)');

    if ($is_pvc_payment) {
        if ($target_customer_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'A registered customer is required to redeem Petron Value Card (PVC Points).']);
            return;
        }
        if (!$loyalty_acc) {
            http_response_code(400);
            echo json_encode(['error' => 'Selected customer does not have an active linked Petron Value Card (PVC).']);
            return;
        }
        if ($curr_points_balance <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Selected customer has 0 PVC points available for redemption.']);
            return;
        }

        // Auto-calculate eligible redemption
        $max_points_needed = (int)ceil($gross_amount / $redemption_val);
        $validated_points_redeemed = min($curr_points_balance, $max_points_needed);
        $loyalty_discount_amount = min($gross_amount, round($validated_points_redeemed * $redemption_val, 2));
    } elseif ($target_customer_id > 0 && $loyalty_acc && !empty($data['loyalty_points_redeemed'])) {
        $req_points_redeemed = max(0, intval($data['loyalty_points_redeemed']));
        if ($curr_points_balance >= $min_redeem_pts && $req_points_redeemed > 0) {
            $max_points_needed = (int)ceil($gross_amount / $redemption_val);
            $validated_points_redeemed = min($req_points_redeemed, $curr_points_balance, $max_points_needed);
            $loyalty_discount_amount = min($gross_amount, round($validated_points_redeemed * $redemption_val, 2));
        }
    }

    // True net total after loyalty discount
    $total_amount = max(0.0, round($gross_amount - $loyalty_discount_amount, 2));

    // Authoritative Server-Side Tax Calculation (VAT-Inclusive Extraction)
    $tax_calc = petron_calculate_taxes($gross_amount, $loyalty_discount_amount, (int)$station_id);
    $subtotal_amount = $tax_calc['subtotal_amount']; // Vatable Sales (or Non-VAT Sales)
    $vat_amount      = $tax_calc['vat_amount'];      // Extracted VAT (or 0 for Non-VAT)

    // amount_paid: the actual amount the customer paid/tendered right now
    $amount_paid = floatval($data['amount_paid'] ?? $data['amount_tendered'] ?? 0);

    // ── Determine payment_status based on amount vs total ─────────────────────
    $is_credit_account = in_array($payment_method, ['Credit Account', 'Credit'], true);

    if ($is_credit_account) {
        $resolved_payment_status = 'Pending';
        // Credit requires a customer account
        $credit_customer_id = intval($data['credit_customer_id'] ?? 0);
        if (!$credit_customer_id) {
            http_response_code(400);
            echo json_encode(['error' => 'A credit account must be selected for Credit Account transactions.']);
            return;
        }
        // Check credit limit and customer status
        try {
            $creditLimitExpr = customer_credit_limit_expr($pdo, 'c');
            $balanceExpr = customer_balance_expr($pdo, 'c');
            $statusExpr = customer_status_expr($pdo, 'c');
            $cstmt = $pdo->prepare("
                SELECT
                    {$creditLimitExpr} AS credit_limit,
                    {$balanceExpr} AS balance,
                    {$statusExpr} AS status
                FROM customers c
                WHERE c.id = ? AND c.station_id = ?
                LIMIT 1
            ");
            $cstmt->execute([$credit_customer_id, $station_id]);
            $cust = $cstmt->fetch(PDO::FETCH_ASSOC);
            if ($cust) {
                $cust_status = strtolower($cust['status'] ?? '');
                if (in_array($cust_status, ['inactive', 'suspended', 'locked'], true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Transaction blocked: Customer account is not active.']);
                    return;
                }
                $available = floatval($cust['credit_limit']) - floatval($cust['balance']);
                $credit_limit_set = floatval($cust['credit_limit']) > 0;
                if ($credit_limit_set && $total_amount > $available) {
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Insufficient credit limit. Grand total is ₱' . number_format($total_amount, 2)
                                 . ' but only ₱' . number_format($available, 2) . ' is available.'
                    ]);
                    return;
                }
            }
        } catch (Exception $e) {
            error_log("Credit limit check warning: " . $e->getMessage());
        }
        $balance_due = $total_amount; // full net amount is on credit
    } elseif ($is_pvc_payment) {
        if ($total_amount <= 0.009) {
            // Available PVC points covered 100% of the transaction
            $amount_paid = 0.00;
            $resolved_payment_status = 'Paid';
            $balance_due = 0.00;
        } else {
            // Points covered a portion; check if customer paid the rest
            if ($amount_paid >= $total_amount - 0.009) {
                $resolved_payment_status = 'Paid';
                $balance_due = 0.00;
            } else {
                $resolved_payment_status = 'Partially Paid';
                $balance_due = max(0.0, round($total_amount - $amount_paid, 2));
            }
        }
    } else {
        // Cash / Card / E-Wallet
        if ($total_amount <= 0) {
            $amount_paid = 0;
            $resolved_payment_status = 'Paid';
            $balance_due = 0;
        } elseif ($amount_paid <= 0) {
            if ($payment_method === 'Cash' && $total_amount > 0) {
                $amount_paid = $total_amount;
                $resolved_payment_status = 'Paid';
                $balance_due = 0;
            } else {
                $resolved_payment_status = 'Pending';
                $balance_due = $total_amount;
            }
        } elseif ($amount_paid < $total_amount - 0.009) {
            $resolved_payment_status = 'Partially Paid';
            $balance_due = round($total_amount - $amount_paid, 2);
        } else {
            $resolved_payment_status = 'Paid';
            $balance_due = 0;
        }
    }

    // ── Points earned on eligible monetary payments only (₱100 eligible purchase = 1 point) ──
    $validated_points_earned = 0;
    if ($target_customer_id > 0 && $loyalty_acc && $resolved_payment_status === 'Paid') {
        $monetary_paid = max(0.0, min($total_amount, ($amount_paid > 0 ? $amount_paid : $total_amount)));
        $validated_points_earned = intval(floor($monetary_paid / $pts_per_amount));
    }

    // ── Determine ar_status ──────────────────────────────────────────────────
    if ($is_credit_account) {
        $resolved_ar_status = ($balance_due <= 0) ? 'Settled' : 'Pending';
    } elseif ($balance_due > 0) {
        $resolved_ar_status = 'Pending';
    } else {
        $resolved_ar_status = 'No AR';
    }

    // ── Ensure items table exists (DDL outside transaction) ──────────────────
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS merchandise_transaction_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                product_id INT NULL,
                product_name VARCHAR(255) NOT NULL,
                category VARCHAR(100) NOT NULL DEFAULT '',
                size_variant VARCHAR(100) NULL,
                quantity DECIMAL(10,2) NOT NULL,
                unit_price DECIMAL(10,2) NOT NULL,
                subtotal DECIMAL(10,2) NOT NULL,
                item_type VARCHAR(20) NOT NULL DEFAULT 'merchandise',
                INDEX idx_transaction (transaction_id),
                INDEX idx_product (product_id)
            )
        ");
        $itemColCheck = $pdo->query("SHOW COLUMNS FROM merchandise_transaction_items LIKE 'item_type'")->rowCount();
        if ($itemColCheck === 0) {
            $pdo->exec("ALTER TABLE merchandise_transaction_items ADD COLUMN `item_type` VARCHAR(20) NOT NULL DEFAULT 'merchandise' AFTER subtotal");
        }
        $pidColRow = $pdo->query("SHOW COLUMNS FROM merchandise_transaction_items LIKE 'product_id'")->fetch(PDO::FETCH_ASSOC);
        if ($pidColRow && strpos(strtolower($pidColRow['Type']), 'int') !== false && strtolower($pidColRow['Null']) === 'no') {
            $pdo->exec("ALTER TABLE merchandise_transaction_items MODIFY COLUMN `product_id` INT NULL");
        }
    } catch (Exception $e) { error_log("Items table migration warning: " . $e->getMessage()); }

    // Pre-run loyalty tables migration DDL outside transaction
    $loyaltyProgram = $data['loyalty_type'] ?? 'No Loyalty';
    if ($loyaltyProgram !== 'No Loyalty' || !empty($data['loyalty_card_no'])) {
        try {
            require_once __DIR__ . '/../loyalty_schema_fix.php';
            loyalty_ensure_tables($pdo);
        } catch (Exception $e) { error_log("Loyalty schema warning: " . $e->getMessage()); }
    }

    // ── Stock availability validation (merchandise items only) ──────────────
    try {
        foreach ($data['items'] as $item) {
            // Skip service fee items — they have no product_id
            if (($item['item_type'] ?? 'merchandise') === 'service') continue;

            $product_id = intval($item['product_id'] ?? 0);
            $qty        = floatval($item['quantity'] ?? 0);
            if (!$product_id || $qty <= 0) continue;

            $stockStmt = $pdo->prepare("
                SELECT COALESCE(stock_level, 0) AS stock_level, ip.product_name
                FROM station_inventory si
                JOIN inventory_products ip ON ip.id = si.product_id
                WHERE si.station_id = ? AND si.product_id = ?
                LIMIT 1
            ");
            $stockStmt->execute([$station_id, $product_id]);
            $stockRow = $stockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$stockRow) {
                http_response_code(400);
                echo json_encode(['error' => "Product not found in station inventory: " . htmlspecialchars($item['product_name'] ?? "ID $product_id")]);
                return;
            }
            if ($stockRow['stock_level'] < $qty) {
                http_response_code(400);
                echo json_encode([
                    'error' => "Insufficient stock for \"{$stockRow['product_name']}\". "
                             . "Available: " . number_format($stockRow['stock_level'], 2)
                             . ", Requested: " . number_format($qty, 2)
                ]);
                return;
            }
        }
    } catch (Exception $e) {
        error_log("Stock validation warning: " . $e->getMessage());
        // Non-fatal if station_inventory table is missing — proceed
    }

    $legacy_sku_parts = [];
    $legacy_total_qty = 0;
    $legacy_first_price = null;
    $has_service_item = false;
    $has_merchandise_item = false;
    foreach ($data['items'] as $item) {
        $is_service_item = ($item['item_type'] ?? 'merchandise') === 'service';
        $has_service_item = $has_service_item || $is_service_item;
        $has_merchandise_item = $has_merchandise_item || !$is_service_item;
        $qty = max(0, (float)($item['quantity'] ?? 1));
        $price = (float)($item['unit_price'] ?? 0);
        $legacy_total_qty += $qty;
        if ($legacy_first_price === null) {
            $legacy_first_price = $price;
        }
        $name = trim((string)($item['product_name'] ?? ''));
        if ($name !== '') {
            $legacy_sku_parts[] = $name;
        }
    }
    $legacy_item_sku = substr(implode(', ', array_unique($legacy_sku_parts)), 0, 200);
    if ($legacy_item_sku === '') {
        $legacy_item_sku = $has_service_item ? 'Service Fee' : 'Transaction Item';
    }
    if ($legacy_total_qty <= 0) {
        $legacy_total_qty = 1;
    }
    if ($legacy_first_price === null || $legacy_first_price <= 0) {
        $legacy_first_price = $total_amount;
    }
    $resolved_transaction_type = ($has_service_item && $has_merchandise_item) ? 'combined'
        : ($has_service_item ? 'job_order' : 'merchandise');

    // ── Server-Side Idempotency Guard (Prevent double submissions) ─────────────
    try {
        $recentStmt = $pdo->prepare("
            SELECT id, transaction_id
            FROM merchandise_transactions 
            WHERE station_id = ? 
              AND staff_id = ? 
              AND total_amount = ? 
              AND payment_method = ? 
              AND (
                  (customer_name = ? OR (customer_name IS NULL AND ? = 'Walk-in Customer'))
              )
              AND created_at >= (NOW() - INTERVAL 4 SECOND)
            ORDER BY id DESC 
            LIMIT 1
        ");
        $custNameParam = $data['customer_name'] ?? 'Walk-in Customer';
        $recentStmt->execute([
            $station_id, 
            $me['id'], 
            $total_amount, 
            $data['payment_method'],
            $custNameParam,
            $custNameParam
        ]);
        $recentTxn = $recentStmt->fetch(PDO::FETCH_ASSOC);
        if ($recentTxn) {
            // Already inserted a few seconds ago — return success with existing ID to prevent duplication
            echo json_encode([
                'success' => true,
                'transaction_id' => (int)$recentTxn['id'],
                'message' => 'Transaction already processed.',
                'duplicate_prevented' => true
            ]);
            return;
        }
    } catch (Exception $dupErr) {
        error_log("Duplicate check notice: " . $dupErr->getMessage());
    }

    // Generate unique transaction ID
    $transaction_id = '';
    for ($try = 0; $try < 8; $try++) {
        $candidate = 'MERCH' . date('YmdHis') . str_pad((string)$station_id, 4, '0', STR_PAD_LEFT) . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $idCheck = $pdo->prepare("SELECT 1 FROM merchandise_transactions WHERE transaction_id = ? LIMIT 1");
        $idCheck->execute([$candidate]);
        if (!$idCheck->fetchColumn()) {
            $transaction_id = $candidate;
            break;
        }
    }
    if ($transaction_id === '') {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to generate a unique transaction ID. Please try again.']);
        return;
    }

    // Customer records are Manager-controlled. Staff may only link an approved
    // customer_id or submit a customer request from the transaction screen.

    try {
        $pdo->beginTransaction();

        // Build INSERT dynamically based on what columns actually exist
        // (handles both old minimal schema and new full schema)
        $existingCols = [];
        $colRows = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colRows as $col) {
            $existingCols[strtolower($col['Field'])] = true;
        }

        $has = function($col) use ($existingCols) {
            return isset($existingCols[strtolower($col)]);
        };

        // Always-present columns (original schema)
        $cols   = ['transaction_id', 'station_id', 'staff_id', 'total_amount'];
        $vals   = [$transaction_id, $station_id, $me['id'], $total_amount];

        if ($has('item_sku')) {
            $cols[] = 'item_sku';
            $vals[] = $legacy_item_sku;
        }
        if ($has('quantity')) {
            $cols[] = 'quantity';
            $vals[] = (int)ceil($legacy_total_qty);
        }
        if ($has('unit_price')) {
            $cols[] = 'unit_price';
            $vals[] = $legacy_first_price;
        }
        if ($has('transaction_date')) {
            $cols[] = 'transaction_date';
            $vals[] = date('Y-m-d H:i:s');
        }

        // Optional columns — add only if they exist
        $optional = [
            'shift_id'              => $shift_id ?: null,
            'shift_period'          => $shift_key,
            'shift_name'            => $shift_name,
            'customer_id'           => $target_customer_id > 0 ? $target_customer_id : ($data['customer_id'] ?? null),
            'customer_name'         => $data['customer_name'],
            'customer_first_name'   => $data['customer_first_name'] ?? null,
            'customer_last_name'    => $data['customer_last_name']  ?? null,
            'customer_contact'      => $data['customer_contact'] ?? $data['job_order_contact'] ?? null,
            'credit_customer_id'    => $data['credit_customer_id'] ?? null,
            'payment_method'        => $payment_method,
            'subtotal_amount'       => $subtotal_amount,
            'vat_amount'            => $vat_amount,
            'discount_amount'       => $loyalty_discount_amount > 0 ? $loyalty_discount_amount : 0.00,
            'loyalty_discount_amount' => $loyalty_discount_amount > 0 ? $loyalty_discount_amount : 0.00,
            'remarks'               => $data['remarks'] ?? '',
            'validation_status'     => $has_service_item ? 'Pending' : 'Official',
            'amount_tendered'       => $data['amount_tendered'] ?? null,
            'change_amount'         => $data['change_amount'] ?? null,
            'card_reference'        => $data['card_reference'] ?? null,
            'card_type'             => $data['card_type'] ?? null,
            'card_last_four'        => $data['card_last_four'] ?? null,
            'ewallet_reference'     => $data['ewallet_reference'] ?? null,
            'ewallet_provider'      => $ewallet_provider,
            'efuel_card_number'     => $data['efuel_card_number'] ?? null,
            'efuel_reference'       => $data['efuel_reference'] ?? null,
            'fleet_card_number'     => $data['fleet_card_number'] ?? null,
            'fleet_company_name'    => $data['fleet_company_name'] ?? null,
            'fleet_auth_number'     => $data['fleet_auth_number'] ?? null,
            'fleet_vehicle_plate'   => $data['fleet_vehicle_plate'] ?? null,
            'fleet_odometer'        => $data['fleet_odometer'] ?? null,
            'fleet_points_earned'   => isset($data['fleet_points_earned']) ? (int)$data['fleet_points_earned'] : null,
            'fleet_points_redeemed' => isset($data['fleet_points_redeemed']) ? (int)$data['fleet_points_redeemed'] : null,
            'credit_company_name'   => $data['credit_company_name'] ?? null,
            'credit_account_number' => $data['credit_account_number'] ?? null,
            'credit_po_number'      => $data['credit_po_number'] ?? null,
            'credit_due_date'       => !empty($data['credit_due_date']) ? $data['credit_due_date'] : null,
            // ── Payment tracking fields ──────────────────────────────────────────
            'amount_paid'           => $amount_paid > 0 ? $amount_paid : null,
            'balance_due'           => $balance_due > 0 ? $balance_due : null,
            // payment_status: Paid / Partially Paid / Pending
            'payment_status'        => $resolved_payment_status,
            'ar_status'             => $resolved_ar_status,
            // Workflow status tracks service progress only; transaction validity is official on save.
            'workflow_status'       => !empty($data['workflow_status']) ? $data['workflow_status'] : ($has_service_item ? 'Pending' : 'Completed'),
            // ── Job Order integration ──────────────────────────────────────
            'job_order_id'               => (!empty($data['job_order_id']) && ctype_digit((string)$data['job_order_id'])) ? (int)$data['job_order_id'] : null,
            'job_order_db_id'            => !empty($data['job_order_db_id'])         ? (int)$data['job_order_db_id']       : null,
            'job_order_service'          => !empty($data['job_order_service'])       ? $data['job_order_service']          : null,
            'job_order_description'      => !empty($data['job_order_description'])   ? $data['job_order_description']      : null,
            'job_order_vehicle_plate'    => !empty($data['job_order_vehicle_plate']) ? $data['job_order_vehicle_plate']    : null,
            'job_order_vehicle_type'     => !empty($data['job_order_vehicle_type'])  ? $data['job_order_vehicle_type']     : null,
            'job_order_vehicle_brand'    => !empty($data['job_order_vehicle_brand']) ? $data['job_order_vehicle_brand']    : null,
            'job_order_vehicle_model'    => !empty($data['job_order_vehicle_model']) ? $data['job_order_vehicle_model']    : null,
            'job_order_year_model'       => !empty($data['job_order_year_model'])    ? $data['job_order_year_model']       : null,
            'job_order_engine_number'    => !empty($data['job_order_engine_number']) ? $data['job_order_engine_number']    : null,
            'job_order_chassis_number'   => !empty($data['job_order_chassis_number'])? $data['job_order_chassis_number']   : null,
            'job_order_estimated_duration' => isset($data['job_order_estimated_duration']) && $data['job_order_estimated_duration'] ? (int)$data['job_order_estimated_duration'] : null,
            'job_order_mechanic_id'      => !empty($data['job_order_mechanic_id'])   ? (int)$data['job_order_mechanic_id'] : null,
            'job_order_mechanic_name'    => !empty($data['job_order_mechanic_name']) ? $data['job_order_mechanic_name']    : null,
            'job_order_contact'          => !empty($data['job_order_contact'])       ? $data['job_order_contact']          : null,
            // ── Transaction type: classify based on cart contents ──────────────
            // Determined by whether items contain service-type and/or merchandise-type entries
            'transaction_type'           => $resolved_transaction_type,
            // ── Loyalty fields ────────────────────────────────────────────────────
            'loyalty_type'               => ($target_customer_id > 0 && $loyalty_acc) ? 'Petron Value Card (PVC)' : 'No Loyalty',
            'loyalty_card_no'            => ($target_customer_id > 0 && $loyalty_acc) ? ($loyalty_acc['card_number'] ?? $card_no) : null,
            'loyalty_points_earned'      => $validated_points_earned,
            'loyalty_points_redeemed'    => $validated_points_redeemed,
        ];

        foreach ($optional as $col => $val) {
            if ($has($col)) {
                $cols[] = $col;
                $vals[] = $val;
            }
        }

        // Handle transaction_date (old schema) vs created_at (new schema)
        if ($has('transaction_date') && !$has('created_at')) {
            $cols[] = 'transaction_date';
            $vals[] = date('Y-m-d H:i:s');
        }

        // Handle updated_at
        if ($has('updated_at')) {
            $cols[] = 'updated_at';
            $vals[] = date('Y-m-d H:i:s');
        }

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList      = implode(', ', array_map(fn($c) => "`$c`", $cols));

        $stmt = $pdo->prepare("INSERT INTO merchandise_transactions ($colList) VALUES ($placeholders)");
        $stmt->execute($vals);

        $merch_transaction_id = $pdo->lastInsertId();

        // Insert transaction items

        $itemColRows = $pdo->query("SHOW COLUMNS FROM merchandise_transaction_items")->fetchAll(PDO::FETCH_ASSOC);
        $itemCols = [];
        foreach ($itemColRows as $col) {
            $itemCols[strtolower($col['Field'])] = true;
        }

        $hasItem = function($col) use ($itemCols) {
            return isset($itemCols[strtolower($col)]);
        };

        foreach ($data['items'] as $item) {
            $isService = ($item['item_type'] ?? 'merchandise') === 'service';
            $subtotal  = floatval($item['quantity'] ?? 1) * floatval($item['unit_price'] ?? 0);

            $iCols = ['transaction_id', 'product_name', 'quantity', 'unit_price', 'subtotal'];
            $iVals = [
                $merch_transaction_id,
                $item['product_name'] ?? ($isService ? 'Service Fee' : 'Item'),
                $item['quantity'] ?? 1,
                $item['unit_price'] ?? 0,
                $subtotal,
            ];

            // product_id — NULL for service items
            if ($hasItem('product_id')) {
                $iCols[] = 'product_id';
                $iVals[] = $isService ? null : (intval($item['product_id'] ?? 0) ?: null);
            }

            if ($hasItem('category')) {
                $iCols[] = 'category';
                $iVals[] = $item['category'] ?? ($isService ? 'Service Fee' : '');
            }
            if ($hasItem('size_variant')) {
                $iCols[] = 'size_variant';
                $iVals[] = $item['size_variant'] ?? '';
            }
            if ($hasItem('item_type')) {
                $iCols[] = 'item_type';
                $iVals[] = $isService ? 'service' : 'merchandise';
            }

            $iPlaceholders = implode(', ', array_fill(0, count($iCols), '?'));
            $iColList      = implode(', ', array_map(fn($c) => "`$c`", $iCols));

            $itemStmt = $pdo->prepare("INSERT INTO merchandise_transaction_items ($iColList) VALUES ($iPlaceholders)");
            $itemStmt->execute($iVals);
        }

        // Deduct inventory immediately for staff-saved merchandise items.
        $deducted_inventory = false;
        foreach ($data['items'] as $item) {
            if (($item['item_type'] ?? 'merchandise') === 'service') {
                continue;
            }

            $product_id = intval($item['product_id'] ?? 0);
            $qty = floatval($item['quantity'] ?? 0);
            if ($product_id <= 0 || $qty <= 0) {
                continue;
            }

            $stockStmt = $pdo->prepare("
                SELECT stock_level
                FROM station_inventory
                WHERE station_id = ? AND product_id = ?
                FOR UPDATE
            ");
            $stockStmt->execute([$station_id, $product_id]);
            $stockLevel = $stockStmt->fetchColumn();
            if ($stockLevel === false) {
                throw new Exception('Inventory record is missing for product #' . $product_id . '.');
            }
            if ((float)$stockLevel < $qty) {
                throw new Exception('Insufficient stock for product #' . $product_id . '. Available: ' . number_format((float)$stockLevel, 2) . ', required: ' . number_format($qty, 2) . '.');
            }

            $deductStmt = $pdo->prepare("
                UPDATE station_inventory
                SET stock_level = stock_level - ?,
                    last_updated = NOW()
                WHERE station_id = ? AND product_id = ?
            ");
            $deductStmt->execute([$qty, $station_id, $product_id]);
            if ($deductStmt->rowCount() > 0) {
                $deducted_inventory = true;

                // ── FIFO Batch Deduction ──────────────────────────────────────────
                try {
                    $bStmt = $pdo->prepare("
                        SELECT id, batch_number, remaining_qty
                        FROM merchandise_batches
                        WHERE station_id = ? AND product_id = ? AND status = 'active' AND remaining_qty > 0
                        ORDER BY date_received ASC, id ASC
                        FOR UPDATE
                    ");
                    $bStmt->execute([$station_id, $product_id]);
                    $active_batches = $bStmt->fetchAll(PDO::FETCH_ASSOC);

                    $needed_qty = $qty;
                    $first_batch_id = null;

                    foreach ($active_batches as $bRow) {
                        if ($needed_qty <= 0) break;
                        $bid  = (int)$bRow['id'];
                        $brem = (float)$bRow['remaining_qty'];
                        if ($first_batch_id === null) {
                            $first_batch_id = $bid;
                        }

                        $deductBatch = min($needed_qty, $brem);
                        $newRem      = max(0, $brem - $deductBatch);
                        $newBStatus  = ($newRem <= 0) ? 'depleted' : 'active';

                        $pdo->prepare("UPDATE merchandise_batches SET remaining_qty = ?, status = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$newRem, $newBStatus, $bid]);

                        $needed_qty -= $deductBatch;
                    }

                    if ($first_batch_id !== null && $hasItem('batch_id')) {
                        $pdo->prepare("UPDATE merchandise_transaction_items SET batch_id = ? WHERE transaction_id = ? AND product_id = ? AND (batch_id IS NULL OR batch_id = 0)")
                            ->execute([$first_batch_id, $merch_transaction_id, $product_id]);
                    }
                } catch (Exception $fifoErr) {
                    error_log("FIFO batch deduction notice: " . $fifoErr->getMessage());
                }

                // Log to inventory_logs
                try {
                    $qtyBefore = (float)$stockLevel;
                    $qtyAfter = $qtyBefore - $qty;
                    $logStmt = $pdo->prepare("
                        INSERT INTO inventory_logs (
                            station_id, product_id, user_id, action, movement_type,
                            quantity_before, quantity_after, quantity_change, 
                            reference_type, reference_id, reference_no, notes, created_at
                        ) VALUES (?, ?, ?, 'sale', 'OUT', ?, ?, ?, 'merchandise_transaction', NULL, ?, ?, NOW())
                    ");
                    $logStmt->execute([
                        $station_id,
                        $product_id,
                        $me['id'] ?? null,
                        $qtyBefore,
                        $qtyAfter,
                        -$qty,
                        $transaction_id,
                        "POS Merchandise Sale - Ref: " . $transaction_id
                    ]);
                } catch (Exception $logErr) {
                    error_log("Inventory log insert error: " . $logErr->getMessage());
                }
            }
        }

        if ($deducted_inventory && $has('inventory_deducted')) {
            $pdo->prepare("UPDATE merchandise_transactions SET inventory_deducted = 1, updated_at = NOW() WHERE id = ? AND station_id = ?")
                ->execute([$merch_transaction_id, $station_id]);
        }

        if ($is_credit_account && !empty($data['credit_customer_id'])) {
            $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id = ? AND station_id = ?")
                ->execute([$total_amount, $data['credit_customer_id'], $station_id]);

            try {
                $bal_stmt = $pdo->prepare("SELECT balance FROM customers WHERE id = ? AND station_id = ?");
                $bal_stmt->execute([$data['credit_customer_id'], $station_id]);
                $new_bal = (float)$bal_stmt->fetchColumn();

                $cct_stmt = $pdo->prepare("
                    INSERT INTO customer_credit_transactions (
                        customer_id, transaction_id, transaction_type, amount,
                        running_balance, description, station_id, created_by, created_at
                    ) VALUES (?, ?, 'Sale', ?, ?, ?, ?, ?, NOW())
                ");
                $cct_stmt->execute([
                    $data['credit_customer_id'],
                    $transaction_id,
                    $total_amount,
                    $new_bal,
                    "Official Merchandise Sale - Ref: " . $transaction_id,
                    $station_id,
                    $me['id']
                ]);
            } catch (Exception $ccError) {
                error_log("Credit transaction log warning: " . $ccError->getMessage());
            }

            try {
                $or_no = 'OR-' . date('Y') . '-' . str_pad($merch_transaction_id ?? 0, 6, '0', STR_PAD_LEFT);
                $pdo->prepare("
                    INSERT INTO customer_accounts_receivable
                    (customer_id, transaction_id, transaction_db_id, or_number, total_amount, amount_paid, outstanding_balance, status, station_id, created_at)
                    VALUES (?, ?, ?, ?, ?, 0, ?, 'Active', ?, NOW())
                ")->execute([$data['credit_customer_id'], $transaction_id, $merch_transaction_id, $or_no, $total_amount, $total_amount, $station_id]);
            } catch (Exception $are) {
                error_log("AR record creation error: " . $are->getMessage());
            }
        }

        // ── Loyalty Points Transaction & Customer Account Update ──────────────
        if ($target_customer_id > 0 && $loyalty_acc && ($validated_points_earned > 0 || $validated_points_redeemed > 0)) {
            try {
                $accId = (int)$loyalty_acc['id'];
                $newBalance = max(0, $curr_points_balance + $validated_points_earned - $validated_points_redeemed);

                $txnTypeLabel = ($resolved_transaction_type === 'combined') ? 'Combined Transaction' : (($resolved_transaction_type === 'job_order') ? 'Job Order' : 'Merchandise');

                $remarks = "POS Transaction - Ref: " . $transaction_id;
                if ($loyalty_discount_amount > 0) {
                    $remarks .= " (PVC Discount: ₱" . number_format($loyalty_discount_amount, 2) . ", Redeemed: {$validated_points_redeemed} pts)";
                }
                if ($validated_points_earned > 0) {
                    $remarks .= " (Earned: {$validated_points_earned} pts)";
                }

                $dupCheck = $pdo->prepare("SELECT id FROM loyalty_transactions WHERE reference_id = ? AND transaction_type = ? LIMIT 1");
                $dupCheck->execute([$transaction_id, $txnTypeLabel]);
                if (!$dupCheck->fetchColumn()) {
                    $lTxn = $pdo->prepare("
                        INSERT INTO loyalty_transactions (
                            loyalty_account_id, customer_id, reference_id, transaction_type,
                            points_earned, points_redeemed, points_balance_after, created_by, created_at, remarks
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    $lTxn->execute([
                        $accId,
                        $target_customer_id,
                        $transaction_id,
                        $txnTypeLabel,
                        $validated_points_earned,
                        $validated_points_redeemed,
                        $newBalance,
                        $me['id'] ?? null,
                        $remarks
                    ]);

                    // Update loyalty_accounts & customers balance
                    $pdo->prepare("UPDATE loyalty_accounts SET points_balance = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$newBalance, $accId]);
                    $pdo->prepare("UPDATE customers SET points = ? WHERE id = ?")
                        ->execute([$newBalance, $target_customer_id]);
                }
            } catch (Exception $lErr) {
                error_log("Loyalty transaction processing warning: " . $lErr->getMessage());
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        // ── Post-commit: audit logging and manager notification (outside transaction so DDL won't corrupt it) ──
        try {
            notify_transaction_submission($pdo, (int)$station_id, [
                'transaction_id'    => $transaction_id,
                'transaction_db_id' => (int)$merch_transaction_id,
                'transaction_type'  => $resolved_transaction_type,
                'total_amount'      => (float)$total_amount,
                'customer_name'     => $data['customer_name'] ?? 'Walk-in',
                'staff_name'        => $me['name'] ?? $me['username'] ?? 'Staff',
                'shift_period'      => $shift_key ?? $shift_name ?? ''
            ]);

            if (function_exists('log_activity')) {
                log_activity($pdo, $me['id'], 'Merchandise Transaction Saved',
                    "Official transaction ID: $transaction_id, Amount: $total_amount, Items: " . count($data['items']));
            }
            logMerchandiseTransactionAudit($pdo, $me['id'], $merch_transaction_id, 'CREATED', [
                'transaction_id' => $transaction_id,
                'total_amount'   => $total_amount,
                'item_count'     => count($data['items']),
                'customer_name'  => $data['customer_name'] ?? 'Walk-in',
                'payment_method' => $data['payment_method'] ?? '',
                'validation_status' => 'Official',
            ]);
        } catch (Exception $e) { /* audit failure must not block the response */ }

        echo json_encode([
            'success'              => true,
            'transaction_id'       => $transaction_id,
            'merch_transaction_id' => $merch_transaction_id,
            'status'               => 'Official',
            'message'              => 'Transaction saved successfully',
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new Exception('Error creating transaction: ' . $e->getMessage());
    }
}

function validateTransaction($pdo, $station_id, $role, $me) {
    // Only managers and above can validate
    if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    $transaction_id = $data['transaction_id'] ?? '';
    
    if (empty($transaction_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Transaction ID is required']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update transaction status
        $stmt = $pdo->prepare("
            UPDATE merchandise_transactions 
            SET validation_status = 'Approved',
                validated_by = ?,
                validated_at = NOW(),
                updated_at = NOW()
            WHERE id = ? AND station_id = ? AND validation_status = 'Pending'
        ");
        $stmt->execute([$me['id'], $transaction_id, $station_id]);
        
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found or already processed']);
            return;
        }
        
        // Get transaction details for audit
        $stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? FOR UPDATE");
        $stmt->execute([$transaction_id, $station_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // ── Determine transaction_type for receipt labeling ──────────────────
        $has_service = false;
        $has_merch   = false;
        $itemsForDeduction = [];
        $itemRows = $pdo->prepare("SELECT product_id, product_name, quantity, item_type FROM merchandise_transaction_items WHERE transaction_id = ?");
        $itemRows->execute([$transaction_id]);
        foreach ($itemRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['item_type'] ?? 'merchandise') === 'service') {
                $has_service = true;
            } else {
                $has_merch = true;
                if ($row['product_id'] && $row['quantity'] > 0) {
                    $itemsForDeduction[] = $row;
                }
            }
        }

        // Legacy validation path is disabled at the router. Stock is deducted on staff save.
        // This block remains only for backward compatibility if called internally.
        $did_deduct = false;
        if (empty($transaction['inventory_deducted'])) {
            foreach ($itemsForDeduction as $row) {
                $stockStmt = $pdo->prepare("
                    SELECT stock_level
                    FROM station_inventory
                    WHERE station_id = ? AND product_id = ?
                    FOR UPDATE
                ");
                $stockStmt->execute([$station_id, $row['product_id']]);
                $stockLevel = $stockStmt->fetchColumn();
                if ($stockLevel === false) {
                    throw new Exception('Inventory record is missing for ' . ($row['product_name'] ?: 'product #' . $row['product_id']) . '.');
                }
                if ((float)$stockLevel < (float)$row['quantity']) {
                    throw new Exception('Insufficient stock for ' . ($row['product_name'] ?: 'product #' . $row['product_id']) . '. Available: ' . number_format((float)$stockLevel, 2) . ', required: ' . number_format((float)$row['quantity'], 2) . '.');
                }
            }

            foreach ($itemsForDeduction as $row) {
                $deductStmt = $pdo->prepare("
                    UPDATE station_inventory
                    SET stock_level = stock_level - ?,
                        last_updated = NOW()
                    WHERE station_id = ? AND product_id = ?
                ");
                $deductStmt->execute([$row['quantity'], $station_id, $row['product_id']]);
                if ($deductStmt->rowCount() > 0) {
                    $did_deduct = true;
                }
            }
        }
        // Mark inventory_deducted = 1 so UI and reports correctly show deduction status
        if ($did_deduct) {
            try {
                $pdo->prepare("UPDATE merchandise_transactions SET inventory_deducted = 1, updated_at = NOW() WHERE id = ? AND station_id = ?")
                    ->execute([$transaction_id, $station_id]);
            } catch (Exception $_ide) {
                // Column may not exist on older schemas — non-fatal
                error_log("inventory_deducted update warning: " . $_ide->getMessage());
            }
        }

        // ── Ensure transaction_type is stored correctly for Merchandise History filtering ──
        // If the stored value is NULL or empty (legacy record), compute and persist it now.
        $computed_txn_type = ($has_service && $has_merch) ? 'combined'
            : ($has_service ? 'job_order' : 'merchandise');
        // Also consider job_order_service field for records that may not have item_type set
        if (!$has_service && !empty($transaction['job_order_service'])) {
            $computed_txn_type = $has_merch ? 'combined' : 'job_order';
        }
        if (empty($transaction['transaction_type'])) {
            try {
                $pdo->prepare("UPDATE merchandise_transactions SET transaction_type = ? WHERE id = ?")
                    ->execute([$computed_txn_type, $transaction_id]);
                $transaction['transaction_type'] = $computed_txn_type;
            } catch (Exception $e) {
                error_log("Could not update transaction_type: " . $e->getMessage());
            }
        }

        // Update customer balance if credit transaction
        if ($transaction['credit_customer_id']) {
            $cust_chk = $pdo->prepare("SELECT status FROM customers WHERE id = ?");
            $cust_chk->execute([$transaction['credit_customer_id']]);
            $cust_status = $cust_chk->fetchColumn();
            $cust_status = strtolower((string)$cust_status);
            if (in_array($cust_status, ['inactive', 'suspended', 'locked'], true)) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Approval blocked: Customer account is not active.']);
                return;
            }
            
            $stmt = $pdo->prepare("
                UPDATE customers 
                SET balance = balance + ? 
                WHERE id = ?
            ");
            $stmt->execute([$transaction['total_amount'], $transaction['credit_customer_id']]);
            
            // Also write to customer_credit_transactions
            try {
                // Fetch updated balance
                $bal_stmt = $pdo->prepare("SELECT balance FROM customers WHERE id = ?");
                $bal_stmt->execute([$transaction['credit_customer_id']]);
                $new_bal = (float)$bal_stmt->fetchColumn();
                
                $cct_stmt = $pdo->prepare("
                    INSERT INTO customer_credit_transactions (
                        customer_id, transaction_id, transaction_type, amount, 
                        running_balance, description, station_id, created_by, created_at
                    ) VALUES (?, ?, 'Sale', ?, ?, ?, ?, ?, NOW())
                ");
                $cct_stmt->execute([
                    $transaction['credit_customer_id'],
                    $transaction['transaction_id'],
                    $transaction['total_amount'],
                    $new_bal,
                    "Merchandise Sale (Credit) - Ref: " . $transaction['transaction_id'],
                    $station_id,
                    $me['id']
                ]);
            } catch (Exception $ccError) {
                error_log("Error inserting into customer_credit_transactions: " . $ccError->getMessage());
            }
        }
        
        // Log to audit trail
        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Merchandise Transaction Validated', 
                "Transaction ID: {$transaction['transaction_id']}, Amount: {$transaction['total_amount']}");
        }
        
        // Log detailed transaction audit
        logMerchandiseTransactionAudit($pdo, $me['id'], $transaction_id, 'APPROVED', [
            'transaction_id' => $transaction['transaction_id'],
            'total_amount' => $transaction['total_amount'],
            'customer_name' => $transaction['customer_name'],
            'payment_method' => $transaction['payment_method']
        ]);
        
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction validated and committed successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception('Error validating transaction: ' . $e->getMessage());
    }
}

function rejectTransaction($pdo, $station_id, $role, $me) {
    // Only managers and above can reject
    if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    $transaction_id = $data['transaction_id'] ?? '';
    $reason = $data['reason'] ?? '';
    
    if (empty($transaction_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Transaction ID is required']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update transaction status
        $stmt = $pdo->prepare("
            UPDATE merchandise_transactions 
            SET validation_status = 'Rejected',
                validated_by = ?,
                validated_at = NOW(),
                rejection_reason = ?,
                updated_at = NOW()
            WHERE id = ? AND station_id = ? AND validation_status = 'Pending'
        ");
        $stmt->execute([$me['id'], $reason, $transaction_id, $station_id]);
        
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found or already processed']);
            return;
        }
        
        // Get transaction details for audit
        $stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ?");
        $stmt->execute([$transaction_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── No stock deduction on Reject — inventory is untouched ────────────
        // Stock is only deducted on Approve, so rejection leaves inventory intact.
        // This is logged in the audit trail for transparency.
        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Merchandise Transaction Rejected', 
                "Transaction ID: {$transaction['transaction_id']}, Reason: $reason");
        }
        
        // Log detailed transaction audit
        logMerchandiseTransactionAudit($pdo, $me['id'], $transaction_id, 'REJECTED', [
            'transaction_id' => $transaction['transaction_id'],
            'total_amount' => $transaction['total_amount'],
            'customer_name' => $transaction['customer_name'],
            'rejection_reason' => $reason
        ]);
        
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction rejected successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception('Error rejecting transaction: ' . $e->getMessage());
    }
}

function adjustTransaction($pdo, $station_id, $role, $me) {
    // Only managers and above can adjust
    if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $data = json_decode($rawBody, true);
    $transaction_id = $data['transaction_id'] ?? '';
    $adjustments = $data['adjustments'] ?? [];
    $reason = $data['reason'] ?? '';
    
    if (empty($transaction_id) || empty($adjustments)) {
        http_response_code(400);
        echo json_encode(['error' => 'Transaction ID and adjustments are required']);
        return;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get original transaction
        $stmt = $pdo->prepare("SELECT * FROM merchandise_transactions WHERE id = ? AND station_id = ? FOR UPDATE");
        $stmt->execute([$transaction_id, $station_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found']);
            return;
        }

        $original_items_stmt = $pdo->prepare("
            SELECT product_id, quantity, item_type
            FROM merchandise_transaction_items
            WHERE transaction_id = ?
        ");
        $original_items_stmt->execute([$transaction_id]);
        $original_items = $original_items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate new total
        $new_total = 0;
        foreach ($adjustments as $adjustment) {
            $new_total += $adjustment['quantity'] * $adjustment['unit_price'];
        }
        
        // Update transaction
        $stmt = $pdo->prepare("
            UPDATE merchandise_transactions 
            SET total_amount = ?,
                validation_status = 'Adjusted',
                validated_by = ?,
                validated_at = NOW(),
                adjustment_reason = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$new_total, $me['id'], $reason, $transaction_id]);
        
        // Update items
        foreach ($adjustments as $adjustment) {
            $stmt = $pdo->prepare("
                UPDATE merchandise_transaction_items 
                SET quantity = ?, unit_price = ?, subtotal = ?
                WHERE transaction_id = ? AND product_id = ?
            ");
            $subtotal = $adjustment['quantity'] * $adjustment['unit_price'];
            $stmt->execute([$adjustment['quantity'], $adjustment['unit_price'], $subtotal, $transaction_id, $adjustment['product_id']]);
        }
        
        // Reconcile stock from the already-official transaction to the adjusted quantities.
        $was_deducted = !empty($transaction['inventory_deducted']);
        if ($was_deducted) {
            foreach ($original_items as $original_item) {
                if (($original_item['item_type'] ?? 'merchandise') === 'service') {
                    continue;
                }
                $pid = intval($original_item['product_id'] ?? 0);
                $qty = floatval($original_item['quantity'] ?? 0);
                if ($pid > 0 && $qty > 0) {
                    $pdo->prepare("
                        UPDATE station_inventory
                        SET stock_level = stock_level + ?,
                            last_updated = NOW()
                        WHERE station_id = ? AND product_id = ?
                    ")->execute([$qty, $station_id, $pid]);
                }
            }
        }

        $deducted_adjusted_inventory = false;
        foreach ($adjustments as $adjustment) {
            $pid = intval($adjustment['product_id'] ?? 0);
            $qty = floatval($adjustment['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $stockStmt = $pdo->prepare("
                SELECT stock_level
                FROM station_inventory
                WHERE station_id = ? AND product_id = ?
                FOR UPDATE
            ");
            $stockStmt->execute([$station_id, $pid]);
            $stockLevel = $stockStmt->fetchColumn();
            if ($stockLevel === false) {
                throw new Exception('Inventory record is missing for product #' . $pid . '.');
            }
            if ((float)$stockLevel < $qty) {
                throw new Exception('Insufficient stock for product #' . $pid . '. Available: ' . number_format((float)$stockLevel, 2) . ', required: ' . number_format($qty, 2) . '.');
            }
        }

        foreach ($adjustments as $adjustment) {
            $pid = intval($adjustment['product_id'] ?? 0);
            $qty = floatval($adjustment['quantity'] ?? 0);
            if ($pid > 0 && $qty > 0) {
                $pdo->prepare("
                    UPDATE station_inventory
                    SET stock_level = stock_level - ?,
                        last_updated = NOW()
                    WHERE station_id = ? AND product_id = ?
                ")->execute([$qty, $station_id, $pid]);
                $deducted_adjusted_inventory = true;
            }
        }

        if ($was_deducted || $deducted_adjusted_inventory) {
            try {
                $pdo->prepare("UPDATE merchandise_transactions SET inventory_deducted = 1, updated_at = NOW() WHERE id = ? AND station_id = ?")
                    ->execute([$transaction_id, $station_id]);
            } catch (Exception $e) {}
        }

        if (!empty($transaction['credit_customer_id'])) {
            $credit_delta = $new_total - (float)($transaction['total_amount'] ?? 0);
            if (abs($credit_delta) > 0.009) {
                $pdo->prepare("
                    UPDATE customers
                    SET balance = GREATEST(balance + ?, 0)
                    WHERE id = ? AND station_id = ?
                ")->execute([$credit_delta, $transaction['credit_customer_id'], $station_id]);
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        // Post-commit: audit logging
        try {
            if (function_exists('log_activity')) {
                log_activity($pdo, $me['id'], 'Merchandise Transaction Adjusted',
                    "Transaction ID: {$transaction['transaction_id']}, New Total: $new_total, Reason: $reason");
            }
            logMerchandiseTransactionAudit($pdo, $me['id'], $transaction_id, 'ADJUSTED', [
                'original_total'   => $transaction['total_amount'],
                'new_total'        => $new_total,
                'adjustment_reason'=> $reason,
                'adjustments'      => $adjustments,
            ]);
        } catch (Exception $e) { /* audit failure must not block response */ }
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction adjusted successfully',
            'new_total' => $new_total
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw new Exception('Error adjusting transaction: ' . $e->getMessage());
    }
}

function logFailedTransactionAttempt($pdo, $station_id, $me) {
    $rawBody = $GLOBALS['_cached_request_body'] ?? file_get_contents('php://input');
    $data    = json_decode($rawBody, true) ?? [];

    $payment_method  = $data['payment_method']  ?? 'Unknown';
    $amount_tendered = floatval($data['amount_tendered'] ?? 0);
    $grand_total     = floatval($data['grand_total']     ?? 0);
    $reason          = $data['reason']          ?? 'Insufficient Payment';
    $items_count     = intval($data['items_count']       ?? 0);

    try {
        // Ensure the failed_transaction_log table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS failed_transaction_log (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                station_id    INT NOT NULL,
                staff_id      INT NOT NULL,
                payment_method VARCHAR(50) NOT NULL,
                amount_tendered DECIMAL(10,2) NOT NULL DEFAULT 0,
                grand_total   DECIMAL(10,2) NOT NULL DEFAULT 0,
                shortfall     DECIMAL(10,2) NOT NULL DEFAULT 0,
                reason        VARCHAR(255) NOT NULL,
                items_count   INT NOT NULL DEFAULT 0,
                ip_address    VARCHAR(45),
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_station  (station_id),
                INDEX idx_staff    (staff_id),
                INDEX idx_created  (created_at)
            )
        ");

        $pdo->prepare("
            INSERT INTO failed_transaction_log
                (station_id, staff_id, payment_method, amount_tendered, grand_total, shortfall, reason, items_count, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $station_id,
            $me['id'],
            $payment_method,
            $amount_tendered,
            $grand_total,
            max(0, $grand_total - $amount_tendered),
            $reason,
            $items_count,
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);

        // Also write to general activity log if available
        if (function_exists('log_activity')) {
            log_activity($pdo, $me['id'], 'Failed Transaction Attempt',
                "Reason: $reason | Payment: $payment_method | Tendered: $amount_tendered | Total: $grand_total | Station: $station_id");
        }

        // Notify managers via notifications table if it exists
        try {
            $mgr_stmt = $pdo->prepare("
                SELECT id FROM users
                WHERE station_id = ? AND role IN ('manager','admin') AND status = 'Active'
            ");
            $mgr_stmt->execute([$station_id]);
            $managers = $mgr_stmt->fetchAll(PDO::FETCH_ASSOC);

            $notif_check = $pdo->query("SHOW TABLES LIKE 'notifications'")->rowCount();
            if ($notif_check > 0 && !empty($managers)) {
                $staff_name = $me['name'] ?? $me['username'] ?? ('Staff #' . $me['id']);
                $notif_msg  = "Transaction blocked: {$staff_name} attempted a {$payment_method} payment of ₱" .
                              number_format($amount_tendered, 2) . " but Grand Total is ₱" .
                              number_format($grand_total, 2) . ". Reason: {$reason}.";
                $notif_stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, event_type, severity, source_key, status, created_at)
                    VALUES (?, 'warning', 'Transaction Blocked', ?, 'transaction_blocked', 'medium', ?, 'unread', NOW())
                ");
                foreach ($managers as $mgr) {
                    $notif_stmt->execute([$mgr['id'], $notif_msg, 'failed_txn_' . md5($notif_msg)]);
                }
            }
        } catch (Exception $e) {
            error_log("Manager notification warning: " . $e->getMessage());
        }

        echo json_encode(['success' => true, 'logged' => true]);

    } catch (Exception $e) {
        error_log("Failed transaction log error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Logging failed']);
    }
}

function logMerchandiseTransactionAudit($pdo, $user_id, $transaction_id, $action, $data = []) {
    try {
        // ── Write to merchandise_transaction_audit (existing table) ──────────
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'merchandise_transaction_audit'");
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS merchandise_transaction_audit (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_id INT NOT NULL,
                user_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                details JSON,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_transaction (transaction_id),
                INDEX idx_user (user_id),
                INDEX idx_action (action),
                INDEX idx_created_at (created_at)
            )");
        }
        $details_json = json_encode($data);
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $audit_station_id = (int)($data['station_id'] ?? 0);
        if ($audit_station_id <= 0) {
            try {
                $st = $pdo->prepare("SELECT station_id FROM merchandise_transactions WHERE id = ? LIMIT 1");
                $st->execute([$transaction_id]);
                $audit_station_id = (int)($st->fetchColumn() ?: 0);
            } catch (Exception $e) {}
        }
        $audit_cols = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM merchandise_transaction_audit")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $audit_cols[strtolower($col['Field'])] = true;
            }
        } catch (Exception $e) {}
        $txn_int_id = is_numeric($transaction_id) ? (int)$transaction_id : (int)($data['merch_transaction_id'] ?? 0);
        if ($txn_int_id <= 0 && !empty($data['transaction_id'])) {
            try {
                $fStmt = $pdo->prepare("SELECT id FROM merchandise_transactions WHERE transaction_id = ? LIMIT 1");
                $fStmt->execute([$data['transaction_id']]);
                $txn_int_id = (int)($fStmt->fetchColumn() ?: 0);
            } catch (Exception $e) {}
        }

        $audit_values = [
            'transaction_id' => $txn_int_id > 0 ? $txn_int_id : null,
            'user_id' => $user_id,
            'staff_id' => $user_id,
            'staff_name' => $data['staff_name'] ?? ('User #' . $user_id),
            'action' => $action,
            'details' => $details_json,
            'audit_data' => $details_json,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'item_sku' => $data['item_sku'] ?? '',
            'quantity' => (int)($data['item_count'] ?? 0),
            'unit_price' => (float)($data['unit_price'] ?? 0),
            'total_amount' => (float)($data['total_amount'] ?? 0),
            'payment_method' => $data['payment_method'] ?? '',
            'customer_name' => $data['customer_name'] ?? '',
            'station_id' => $audit_station_id,
            'timestamp' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $ins_cols = [];
        $ins_vals = [];
        foreach ($audit_values as $col => $value) {
            if (isset($audit_cols[$col])) {
                $ins_cols[] = $col;
                $ins_vals[] = $value;
            }
        }
        if ($ins_cols && $txn_int_id > 0) {
            $col_sql = implode(', ', array_map(fn($col) => "`{$col}`", $ins_cols));
            $ph_sql = implode(', ', array_fill(0, count($ins_cols), '?'));
            $pdo->prepare("INSERT INTO merchandise_transaction_audit ({$col_sql}) VALUES ({$ph_sql})")
                ->execute($ins_vals);
        }

        // ── Write to audit_trail with staff_id for full chronological log ────
        try {
            $txn_ref    = $data['transaction_id'] ?? "MT-{$transaction_id}";
            $detail_val = json_encode($data);
            // Detect columns available (idempotent — columns added by install script)
            $at_has_staff  = false;
            $at_has_source = false;
            try {
                $atc = $pdo->query("SHOW COLUMNS FROM audit_trail")->fetchAll(\PDO::FETCH_COLUMN);
                $at_has_staff  = in_array('staff_id',     $atc);
                $at_has_source = in_array('source_table', $atc);
            } catch (\Exception $e) {}

            // Fetch station_id from the transaction row
            $at_station = 0;
            try {
                $st_r = $pdo->prepare("SELECT station_id FROM merchandise_transactions WHERE id=? LIMIT 1");
                $st_r->execute([$transaction_id]);
                $at_station = (int)($st_r->fetchColumn() ?: 0);
            } catch (\Exception $e) {}

            if ($at_has_staff && $at_has_source) {
                $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, staff_id, action_type, new_value, station_id, source_table) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$transaction_id, $user_id, $user_id, strtoupper($action), $detail_val, $at_station, 'merchandise_transactions']);
            } else {
                $pdo->prepare("INSERT INTO audit_trail (transaction_id, manager_id, action_type, new_value, station_id) VALUES (?,?,?,?,?)")
                    ->execute([$transaction_id, $user_id, strtoupper($action), $detail_val, $at_station]);
            }
        } catch (\Exception $at_err) {
            error_log("audit_trail write warning: " . $at_err->getMessage());
        }

        // ── Also write to audit_logs so the Audit Trail report shows it ──────
        $action_map = [
            'CREATED'  => 'Create',
            'APPROVED' => 'Approve',
            'REJECTED' => 'Reject',
            'ADJUSTED' => 'Adjust',
            'VOIDED'   => 'Delete',
        ];
        $action_type = $action_map[strtoupper($action)] ?? ucfirst(strtolower($action));

        $txn_ref   = $data['transaction_id'] ?? "MT-{$transaction_id}";
        $amount    = isset($data['total_amount']) ? '₱' . number_format((float)$data['total_amount'], 2) : '';
        $items     = isset($data['item_count'])   ? $data['item_count'] . ' item(s)' : '';
        $customer  = $data['customer_name'] ?? '';
        $payment   = $data['payment_method'] ?? '';
        $parts = array_filter(["Merchandise Transaction {$action_type}", "Ref: {$txn_ref}", $amount, $items,
            $customer ? "Customer: {$customer}" : '', $payment ? "Payment: {$payment}" : '']);
        $detail_str = implode(' | ', $parts);

        $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, new_values, status, ip_address, user_agent, created_at)
                       VALUES (?, 'TRANSACTION', ?, ?, 'merchandise_transactions', ?, ?, 'SUCCESS', ?, ?, NOW())")
            ->execute([$user_id, $action_type, $detail_str, $transaction_id, $details_json, $ip, $ua]);

    } catch (Exception $e) {
        error_log("Error logging merchandise transaction audit: " . $e->getMessage());
    }
}
?>
