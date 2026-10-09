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
                VALUES (1, 'Petron Value Card (PVC)', 100.00, 1, 1.00, 'active')
            ");
        } else {
            // Ensure program 1 reflects Petron Value Card (PVC)
            $pdo->exec("
                UPDATE loyalty_programs 
                SET program_name = 'Petron Value Card (PVC)' 
                WHERE id = 1 AND (program_name = 'Petron Rewards Card' OR program_name LIKE '%Rewards%')
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
    } catch (Exception $e) {
        error_log('loyalty_ensure_tables error: ' . $e->getMessage());
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['loyalty_tables_ensured'] = true;
    }
}

/**
 * Authoritatively get existing linked PVC account for a customer.
 * NEVER creates a card automatically.
 */
function get_customer_pvc_account(PDO $pdo, int $customerId): ?array {
    loyalty_ensure_tables($pdo);
    if ($customerId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM loyalty_accounts WHERE customer_id = ? AND program_id = 1 AND status = 'active' LIMIT 1");
    $stmt->execute([$customerId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($account) {
        return $account;
    }
    // Also check if customer has a card number in customers table and ensure it exists in loyalty_accounts
    try {
        $cStmt = $pdo->prepare("SELECT id, name, customer_id, id_number, points, loyalty_card_no FROM customers WHERE id = ? LIMIT 1");
        $cStmt->execute([$customerId]);
        $cust = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($cust) {
            $cardNo = trim((string)($cust['loyalty_card_no'] ?: $cust['customer_id'] ?: ''));
            if ($cardNo !== '') {
                $ins = $pdo->prepare("INSERT INTO loyalty_accounts (customer_id, program_id, card_number, points_balance, status) VALUES (?, 1, ?, ?, 'active') ON DUPLICATE KEY UPDATE points_balance = VALUES(points_balance), status = 'active'");
                $ins->execute([$customerId, $cardNo, (int)($cust['points'] ?? 0)]);
                $stmt->execute([$customerId]);
                return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    } catch (Exception $e) {}
    return null;
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
