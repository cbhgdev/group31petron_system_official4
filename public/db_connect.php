<?php
global $pdo;

// Configure safe production error reporting (log errors, hide from browser)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

// Auto-detect environment: Live Hosting vs. Localhost / XAMPP
$is_live_hosting = (
    (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'yangch-stationms.online') !== false) ||
    (isset($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'yangch-stationms.online') !== false) ||
    (isset($_SERVER['DOCUMENT_ROOT']) && (strpos($_SERVER['DOCUMENT_ROOT'], 'u261539219') !== false || strpos($_SERVER['DOCUMENT_ROOT'], 'public_html') !== false))
);

$pdo_options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false
];

if ($is_live_hosting) {
    $db_configs = [
        [
            'host'   => getenv('DB_HOST') ?: "localhost",
            'dbname' => getenv('DB_NAME') ?: "u261539219_petrondbs",
            'user'   => getenv('DB_USER') ?: "u261539219_petron_pos",
            'pass'   => getenv('DB_PASS') !== false ? getenv('DB_PASS') : "P3tr0n@123",
        ],
        [
            'host'   => "localhost",
            'dbname' => "petron_pos_db_secure",
            'user'   => "root",
            'pass'   => "",
        ]
    ];
} else {
    $db_configs = [
        [
            'host'   => getenv('DB_HOST') ?: "localhost",
            'dbname' => getenv('DB_NAME') ?: "petron_pos_db_secure",
            'user'   => getenv('DB_USER') ?: "root",
            'pass'   => getenv('DB_PASS') !== false ? getenv('DB_PASS') : "",
        ],
        [
            'host'   => "localhost",
            'dbname' => "u261539219_petrondbs",
            'user'   => "u261539219_petron_pos",
            'pass'   => "P3tr0n@123",
        ]
    ];
}

$pdo = null;
$last_db_error = null;

foreach ($db_configs as $cfg) {
    try {
        $pdo = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4",
            $cfg['user'],
            $cfg['pass'],
            $pdo_options
        );
        $host   = $cfg['host'];
        $dbname = $cfg['dbname'];
        $user   = $cfg['user'];
        $pass   = $cfg['pass'];
        break;
    } catch (PDOException $e) {
        $last_db_error = $e;
    }
}

if (!$pdo) {
    error_log("Secure DB connection failure: " . ($last_db_error ? $last_db_error->getMessage() : "Unknown error"));
    http_response_code(500);
    die("<!DOCTYPE html><html><head><title>System Unavailable</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background:#f8fafc;color:#1e293b;} .box{max-width:480px;margin:0 auto;background:#fff;padding:30px;border-radius:12px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);border:1px solid #e2e8f0;}</style></head><body><div class='box'><h2 style='color:#002F70;'>Petron Management System</h2><p style='color:#64748b;'>Database service is currently unavailable. Please check back shortly or contact the system administrator.</p></div></body></html>");
}

try {
  // Explicitly set UTF-8 connection for older MySQL versions
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

  // Self-healing: Automatically clean any legacy mojibake characters in notifications and logs
  try {
    static $mojibake_checked = false;
    if (!$mojibake_checked) {
      $mojibake_checked = true;
      $pdo->exec("UPDATE notifications SET 
        title = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(title, 'ΓÇö', '—'), 'ГÇÖ', '—'), 'ΓÇÖ', '—'), 'ΓÇô', '–'), 'â€”', '—'),
        message = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(message, 'Γé▒', '₱'), 'â‚±', '₱'), 'ΓÇö', '—'), 'ГÇÖ', '—'), 'â€”', '—'), 'â€™', '\'')
        WHERE BINARY title LIKE '%ΓÇ%' OR BINARY title LIKE '%ГÇ%' OR BINARY title LIKE '%â%' OR BINARY message LIKE '%Γé%' OR BINARY message LIKE '%â%'");
    }
  } catch (Throwable $e) {}
} catch (PDOException $e) {
  error_log("Secure DB post-connect failure: " . $e->getMessage());
}

// ── Self-healing Database schema for pending_price_approvals ────────────────
try {
    // Create table if it doesn't exist at all (full correct schema)
    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_price_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL DEFAULT 1,
        product_type VARCHAR(50) NOT NULL DEFAULT 'merchandise',
        product_id INT NOT NULL DEFAULT 0,
        product_name VARCHAR(255) DEFAULT NULL,
        field_name VARCHAR(100) DEFAULT NULL,
        old_cost DECIMAL(12,2) DEFAULT 0,
        new_cost DECIMAL(12,2) DEFAULT 0,
        old_price DECIMAL(12,2) DEFAULT 0,
        new_price DECIMAL(12,2) DEFAULT 0,
        old_value DECIMAL(12,2) DEFAULT 0,
        new_value DECIMAL(12,2) DEFAULT 0,
        manager_id INT DEFAULT NULL,
        requested_by INT DEFAULT NULL,
        admin_id INT DEFAULT NULL,
        reviewed_by INT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        rejection_reason TEXT DEFAULT NULL,
        reviewer_notes TEXT DEFAULT NULL,
        fuel_type_id INT DEFAULT NULL,
        service_type_id INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_station (station_id),
        INDEX idx_status (status),
        INDEX idx_product (product_type, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add any missing columns to existing table
    $check_cols = $pdo->query("SHOW COLUMNS FROM pending_price_approvals");
    $cols = $check_cols->fetchAll(PDO::FETCH_COLUMN);

    $add_cols = [
        'old_cost'         => "DECIMAL(12,2) DEFAULT 0",
        'new_cost'         => "DECIMAL(12,2) DEFAULT 0",
        'old_price'        => "DECIMAL(12,2) DEFAULT 0",
        'new_price'        => "DECIMAL(12,2) DEFAULT 0",
        'old_value'        => "DECIMAL(12,2) DEFAULT 0",
        'new_value'        => "DECIMAL(12,2) DEFAULT 0",
        'manager_id'       => "INT DEFAULT NULL",
        'requested_by'     => "INT DEFAULT NULL",
        'admin_id'         => "INT DEFAULT NULL",
        'reviewed_by'      => "INT DEFAULT NULL",
        'reviewed_at'      => "DATETIME DEFAULT NULL",
        'rejection_reason' => "TEXT DEFAULT NULL",
        'reviewer_notes'   => "TEXT DEFAULT NULL",
        'fuel_type_id'     => "INT DEFAULT NULL",
        'service_type_id'  => "INT DEFAULT NULL",
        'updated_at'       => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        'product_name'     => "VARCHAR(255) DEFAULT NULL",
        'field_name'       => "VARCHAR(100) DEFAULT NULL",
    ];
    foreach ($add_cols as $col => $def) {
        if (!in_array($col, $cols)) {
            try {
                $pdo->exec("ALTER TABLE pending_price_approvals ADD COLUMN `$col` $def");
            } catch (Exception $ae) { /* column might already exist */ }
        }
    }
    // Drop foreign key constraints that would prevent inserting fuel or service products into pending_price_approvals
    try {
        $pdo->exec("ALTER TABLE pending_price_approvals DROP FOREIGN KEY fk_pending_price_approvals_product");
    } catch (Exception $e) { /* doesn't exist or already dropped */ }
    try {
        $pdo->exec("ALTER TABLE pending_price_approvals DROP FOREIGN KEY fk_pending_price_approvals_product_id");
    } catch (Exception $e) { /* doesn't exist or already dropped */ }

} catch (Exception $db_err) {
    error_log("Db self-heal error: " . $db_err->getMessage());
}

// ── Self-healing Database schema for vehicle_inspection_items ────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_inspection_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_name VARCHAR(100) NOT NULL UNIQUE,
        category VARCHAR(50) DEFAULT 'General',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Populate default items if empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM vehicle_inspection_items")->fetchColumn();
    if ($count === 0) {
        $default_items = [
            'Engine',
            'Battery',
            'Tires',
            'Brakes',
            'Lights',
            'Cooling System',
            'Suspension',
            'Transmission Fluid',
            'Air Filter',
            'Wipers & Washers',
            'Belts & Hoses',
            'Steering System',
            'Exhaust System'
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO vehicle_inspection_items (item_name) VALUES (?)");
        foreach ($default_items as $item) {
            $stmt->execute([$item]);
        }
    }
} catch (Exception $e) {
    error_log("vehicle_inspection_items self-healing error: " . $e->getMessage());
}

// ── Self-healing Database schema for customer_credit_transactions ────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_credit_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL DEFAULT 1,
        customer_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        payment_method VARCHAR(50) DEFAULT 'Credit Payment',
        remarks TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log("customer_credit_transactions self-healing error: " . $e->getMessage());
}

// ── Self-healing Database schema for stock_request_audit & fuel_stock_request_audit ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_request_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stock_request_id INT NULL,
        request_id INT NULL,
        action_type VARCHAR(100) NOT NULL,
        performed_by INT NULL,
        performed_by_role VARCHAR(50) NULL,
        old_status VARCHAR(100) NULL,
        new_status VARCHAR(100) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_stock_req_id (stock_request_id),
        INDEX idx_req_id (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_stock_request_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NULL,
        action_type VARCHAR(100) NOT NULL,
        performed_by INT NULL,
        performed_by_role VARCHAR(50) NULL,
        old_status VARCHAR(100) NULL,
        new_status VARCHAR(100) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fuel_req_id (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log("stock_request_audit self-healing error: " . $e->getMessage());
}

// ── Self-healing Database schema for fuel_sales_closing shift_period column ──
try {
    $chk_sp = $pdo->query("SHOW COLUMNS FROM fuel_sales_closing LIKE 'shift_period'");
    if (!$chk_sp || $chk_sp->rowCount() === 0) {
        $pdo->exec("ALTER TABLE fuel_sales_closing ADD COLUMN shift_period VARCHAR(50) DEFAULT NULL AFTER shift");
    }
} catch (Exception $e) {
    error_log("fuel_sales_closing shift_period self-healing error: " . $e->getMessage());
}

// ── Self-healing Database schema for stations location columns ───────────────
try {
    $st_cols = $pdo->query("SHOW COLUMNS FROM stations")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('barangay', $st_cols)) {
        $pdo->exec("ALTER TABLE stations ADD COLUMN barangay VARCHAR(150) NULL AFTER address");
    }
    if (!in_array('city', $st_cols)) {
        $pdo->exec("ALTER TABLE stations ADD COLUMN city VARCHAR(150) NULL AFTER barangay");
    }
    if (!in_array('province', $st_cols)) {
        $pdo->exec("ALTER TABLE stations ADD COLUMN province VARCHAR(150) NULL AFTER city");
    }
} catch (Exception $e) {
    error_log("stations location columns self-healing error: " . $e->getMessage());
}

// ── Self-healing phpMyAdmin Designer Layout & Coordinates Persistence ────────
try {
    static $pma_layout_checked = false;
    if (!$pma_layout_checked) {
        $pma_layout_checked = true;
        $chk_pma = $pdo->query("SELECT COUNT(*) FROM phpmyadmin.pma__table_coords WHERE db_name = 'u261539219_petrondbs'");
        if (!$chk_pma || (int)$chk_pma->fetchColumn() === 0) {
            $backupFile = __DIR__ . '/../database/petron_designer_layout_backup.sql';
            if (file_exists($backupFile)) {
                $sqlCommands = file_get_contents($backupFile);
                $pdo->exec($sqlCommands);
            }
        }
    }
} catch (Throwable $e) {
    // Gracefully ignore if phpmyadmin DB is unavailable or restricted
}

// ── Self-healing Unique Constraint for fuel_inventory (station_id, ugt_no) ───
try {
    static $fuel_inv_ugt_unique_checked = false;
    if (!$fuel_inv_ugt_unique_checked) {
        $fuel_inv_ugt_unique_checked = true;
        $chk_u_ugt = $pdo->query("SHOW INDEX FROM fuel_inventory WHERE Key_name = 'unique_station_ugt'");
        if (!$chk_u_ugt || $chk_u_ugt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE fuel_inventory ADD UNIQUE KEY `unique_station_ugt` (`station_id`, `ugt_no`)");
        }
    }
} catch (Throwable $e) {
    // Already exists or duplicate entry present
}

// ── Self-healing Fuel Pumps Tank Linkage Sync (Station 1253 & All Stations) ──
try {
    static $fuel_pumps_tank_synced = false;
    if (!$fuel_pumps_tank_synced) {
        $fuel_pumps_tank_synced = true;
        $pdo->exec("
            UPDATE fuel_pumps fp
            JOIN fuel_inventory fi ON fi.station_id = fp.station_id 
              AND (
                (fi.fuel_type_id > 0 AND fi.fuel_type_id = fp.fuel_type_id)
                OR (NULLIF(fp.ugt_no,'') IS NOT NULL AND LOWER(REPLACE(fi.ugt_no,'-','')) = LOWER(REPLACE(fp.ugt_no,'-','')))
              )
            SET fp.tank_id = fi.id
            WHERE fp.tank_id IS NULL OR fp.tank_id = 0
        ");
    }
} catch (Throwable $e) {}

// ── Self-healing Database schema for fuel_inventory num_pumps column ──────────
try {
    static $fuel_inv_num_pumps_checked = false;
    if (!$fuel_inv_num_pumps_checked) {
        $fuel_inv_num_pumps_checked = true;
        $chk_np = $pdo->query("SHOW COLUMNS FROM fuel_inventory LIKE 'num_pumps'");
        if (!$chk_np || $chk_np->rowCount() === 0) {
            $pdo->exec("ALTER TABLE fuel_inventory ADD COLUMN num_pumps INT NOT NULL DEFAULT 0 AFTER capacity");
        }
        // Always backfill num_pumps for existing records where num_pumps is 0 but pumps exist
        $pdo->exec("
            UPDATE fuel_inventory fi
            SET fi.num_pumps = (
                SELECT COUNT(*) FROM fuel_pumps fp WHERE fp.tank_id = fi.id
            )
            WHERE fi.num_pumps = 0 AND EXISTS (
                SELECT 1 FROM fuel_pumps fp WHERE fp.tank_id = fi.id
            )
        ");
    }
} catch (Throwable $e) {
    error_log("fuel_inventory num_pumps self-healing error: " . $e->getMessage());
}

// ── Self-healing Standardized Payment Taxonomy & Table Schema ───────────────
try {
    static $payment_schema_healed = false;
    if (!$payment_schema_healed) {
        $payment_schema_healed = true;

        // Ensure fuel_transactions payment columns
        $ft_cols = $pdo->query("SHOW COLUMNS FROM fuel_transactions")->fetchAll(PDO::FETCH_COLUMN);
        $ft_add = [
            'ewallet_provider'  => 'VARCHAR(50) NULL',
            'ewallet_reference' => 'VARCHAR(100) NULL',
            'card_reference'    => 'VARCHAR(100) NULL',
            'card_type'         => 'VARCHAR(50) NULL',
            'fleet_card_number' => 'VARCHAR(50) NULL'
        ];
        foreach ($ft_add as $col => $type) {
            if (!in_array($col, $ft_cols, true)) {
                $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN `$col` $type");
            }
        }

        // Ensure job_orders payment columns
        $jo_cols = $pdo->query("SHOW COLUMNS FROM job_orders")->fetchAll(PDO::FETCH_COLUMN);
        $jo_add = [
            'ewallet_provider'        => 'VARCHAR(50) NULL',
            'ewallet_reference'       => 'VARCHAR(100) NULL',
            'card_reference'          => 'VARCHAR(100) NULL',
            'card_type'               => 'VARCHAR(50) NULL',
            'fleet_card_number'       => 'VARCHAR(50) NULL',
            'loyalty_points_redeemed' => 'INT NULL'
        ];
        foreach ($jo_add as $col => $type) {
            if (!in_array($col, $jo_cols, true)) {
                $pdo->exec("ALTER TABLE job_orders ADD COLUMN `$col` $type");
            }
        }

        // Ensure merchandise_transactions payment columns
        try {
            $mt_cols = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_COLUMN);
            $mt_add = [
                'ewallet_provider'        => 'VARCHAR(50) NULL',
                'ewallet_reference'       => 'VARCHAR(100) NULL',
                'card_reference'          => 'VARCHAR(100) NULL',
                'card_type'               => 'VARCHAR(50) NULL',
                'fleet_card_number'       => 'VARCHAR(50) NULL',
                'loyalty_points_redeemed' => 'INT NULL'
            ];
            foreach ($mt_add as $col => $type) {
                if (!in_array($col, $mt_cols, true)) {
                    $pdo->exec("ALTER TABLE merchandise_transactions ADD COLUMN `$col` $type");
                }
            }
        } catch (Throwable $e) {}

        // Ensure sales payment columns
        try {
            $sales_cols = $pdo->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
            $sales_add = [
                'ewallet_provider'        => 'VARCHAR(50) NULL',
                'ewallet_reference'       => 'VARCHAR(100) NULL',
                'card_reference'          => 'VARCHAR(100) NULL',
                'card_type'               => 'VARCHAR(50) NULL',
                'fleet_card_number'       => 'VARCHAR(50) NULL',
                'loyalty_points_redeemed' => 'INT NULL'
            ];
            foreach ($sales_add as $col => $type) {
                if (!in_array($col, $sales_cols, true)) {
                    $pdo->exec("ALTER TABLE sales ADD COLUMN `$col` $type");
                }
            }
        } catch (Throwable $e) {}

        // Ensure canonical payment methods in payment_methods table
        $canonical_pm = [
            'Cash',
            'Card',
            'E-Wallet',
            'Petron Fleet Card',
            'Credit Account',
            'Petron Loyalty Points'
        ];
        $existing_pm = $pdo->query("SELECT id, name, status FROM payment_methods")->fetchAll(PDO::FETCH_ASSOC);
        $existing_names = array_column($existing_pm, 'name');

        foreach ($canonical_pm as $cname) {
            if (!in_array($cname, $existing_names, true)) {
                $ins = $pdo->prepare("INSERT INTO payment_methods (name, status) VALUES (?, 'Active')");
                $ins->execute([$cname]);
            } else {
                $pdo->prepare("UPDATE payment_methods SET status = 'Active' WHERE name = ?")->execute([$cname]);
            }
        }

        // Inactive legacy sub-methods to maintain FK integrity while enforcing canonical active list
        $legacy_sub = ['Credit Card', 'Debit Card', 'GCash', 'Maya'];
        foreach ($legacy_sub as $lname) {
            $pdo->prepare("UPDATE payment_methods SET status = 'Inactive' WHERE name = ?")->execute([$lname]);
        }
    }
} catch (Throwable $e) {
    error_log("Payment taxonomy self-healing error: " . $e->getMessage());
}


