<?php
/**
 * Loyalty Program Schema Fix & Helper
 * Idempotent creation of loyalty_programs, loyalty_accounts, and loyalty_transactions.
 */

function loyalty_ensure_tables(PDO $pdo): void {
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['loyalty_tables_ensured'])) {
        return;
    }
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        // 1. loyalty_programs
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `loyalty_programs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `program_name` VARCHAR(100) NOT NULL DEFAULT 'Petron Rewards Card',
                `points_per_amount` DECIMAL(10,2) NOT NULL DEFAULT 100.00 COMMENT 'Amount in pesos for 1 point',
                `minimum_redeem_points` INT NOT NULL DEFAULT 1,
                `redemption_value` DECIMAL(10,2) NOT NULL DEFAULT 1.00 COMMENT 'Pesos per redeemed point',
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Seed default program if empty
        $stmt = $pdo->query("SELECT COUNT(*) FROM loyalty_programs");
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("
                INSERT INTO loyalty_programs (id, program_name, points_per_amount, minimum_redeem_points, redemption_value, status)
                VALUES (1, 'Petron Rewards Card', 100.00, 1, 1.00, 'active')
            ");
        }

        // 2. loyalty_accounts
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `loyalty_accounts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `customer_id` INT NOT NULL,
                `program_id` INT NOT NULL DEFAULT 1,
                `card_number` VARCHAR(100) NOT NULL,
                `points_balance` INT NOT NULL DEFAULT 0,
                `expiry_date` DATE NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_customer_program` (`customer_id`, `program_id`),
                UNIQUE KEY `uk_card_number` (`card_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        try {
            $eCheck = $pdo->query("SHOW COLUMNS FROM loyalty_accounts LIKE 'expiry_date'")->rowCount();
            if ($eCheck === 0) {
                $pdo->exec("ALTER TABLE loyalty_accounts ADD COLUMN `expiry_date` DATE NULL AFTER `points_balance`");
            }
        } catch (Exception $e) {}

        // 3. loyalty_transactions
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `loyalty_transactions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `loyalty_account_id` INT NOT NULL,
                `customer_id` INT NOT NULL,
                `reference_id` VARCHAR(100) DEFAULT NULL,
                `transaction_type` VARCHAR(50) NOT NULL DEFAULT 'Merchandise',
                `points_earned` INT NOT NULL DEFAULT 0,
                `points_redeemed` INT NOT NULL DEFAULT 0,
                `points_balance_after` INT NOT NULL DEFAULT 0,
                `created_by` INT DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `remarks` TEXT DEFAULT NULL,
                KEY `idx_loyalty_account` (`loyalty_account_id`),
                KEY `idx_customer` (`customer_id`),
                KEY `idx_reference` (`reference_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Auto-create loyalty_accounts for any existing customers that don't have one yet
        $custs = $pdo->query("
            SELECT c.id, c.customer_id, c.points 
            FROM customers c 
            LEFT JOIN loyalty_accounts la ON la.customer_id = c.id
            WHERE la.id IS NULL
        ")->fetchAll(PDO::FETCH_ASSOC);

        $insStmt = $pdo->prepare("
            INSERT INTO loyalty_accounts (customer_id, program_id, card_number, points_balance, status)
            VALUES (?, 1, ?, ?, 'active')
        ");
        foreach ($custs as $c) {
            $cardNo = !empty($c['customer_id']) ? $c['customer_id'] : ('CUS-1253-' . date('Ym') . '-' . str_pad($c['id'], 3, '0', STR_PAD_LEFT));
            $pts = (int)($c['points'] ?? 0);
            try {
                $insStmt->execute([$c['id'], $cardNo, $pts]);
            } catch (Exception $e) {
                // If card_number duplicate, use custom unique
                $insStmt->execute([$c['id'], 'CUS-LOYALTY-' . $c['id'], $pts]);
            }
        }

    } catch (Exception $e) {
        error_log('loyalty_ensure_tables error: ' . $e->getMessage());
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['loyalty_tables_ensured'] = true;
    }
}

/**
 * Get or Create Loyalty Account for a Customer
 */
function get_or_create_loyalty_account(PDO $pdo, int $customerId, string $customCardNo = ''): array {
    loyalty_ensure_tables($pdo);
    $stmt = $pdo->prepare("SELECT * FROM loyalty_accounts WHERE customer_id = ? AND program_id = 1 LIMIT 1");
    $stmt->execute([$customerId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($account) {
        return $account;
    }

    // Get customer_id string from customers table if available
    $cStmt = $pdo->prepare("SELECT customer_id, points FROM customers WHERE id = ?");
    $cStmt->execute([$customerId]);
    $cust = $cStmt->fetch(PDO::FETCH_ASSOC);

    $cardNo = $customCardNo;
    if (!$cardNo) {
        $cardNo = !empty($cust['customer_id']) ? $cust['customer_id'] : ('CUS-1253-' . date('Ym') . '-' . str_pad($customerId, 3, '0', STR_PAD_LEFT));
    }
    $initialPoints = (int)($cust['points'] ?? 0);

    try {
        $ins = $pdo->prepare("INSERT INTO loyalty_accounts (customer_id, program_id, card_number, points_balance, status) VALUES (?, 1, ?, ?, 'active')");
        $ins->execute([$customerId, $cardNo, $initialPoints]);
        $accId = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        // Fallback unique card number
        $cardNo = 'CUS-LOYALTY-' . $customerId;
        $ins = $pdo->prepare("INSERT INTO loyalty_accounts (customer_id, program_id, card_number, points_balance, status) VALUES (?, 1, ?, ?, 'active')");
        $ins->execute([$customerId, $cardNo, $initialPoints]);
        $accId = (int)$pdo->lastInsertId();
    }

    return [
        'id' => $accId,
        'customer_id' => $customerId,
        'program_id' => 1,
        'card_number' => $cardNo,
        'points_balance' => $initialPoints,
        'status' => 'active'
    ];
}
