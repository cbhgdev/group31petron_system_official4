<?php
/**
 * transaction_schema_fix.php
 * Ensures all columns and tables required by the transaction module exist.
 * Safe to call on every request — uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS.
 * Include AFTER db_connect.php.
 */
if (!isset($pdo)) return;

// Fast-path: Skip 38 DDL queries if already provisioned in this session
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['transaction_schema_fixed'])) {
    return;
}

$fixes = [
    // ── merchandise_transactions columns ─────────────────────────────────────
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS customer_id        INT          DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS validation_status  VARCHAR(60)  DEFAULT 'Pending'",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS workflow_status     VARCHAR(60)  DEFAULT 'Pending'",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS validated_by        INT          DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS validated_at        DATETIME     DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS void_reason         TEXT         DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS adjustment_reason   TEXT         DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS manager_remarks     TEXT         DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS rejection_reason    TEXT         DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS remarks             TEXT         DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS amount_paid         DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS balance_due         DECIMAL(12,2) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS amount_tendered     DECIMAL(12,2) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS payment_status      VARCHAR(60)  DEFAULT 'Unpaid'",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS transaction_type    VARCHAR(60)  DEFAULT 'merchandise'",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS job_order_service   VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS job_order_vehicle_plate VARCHAR(60) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS job_order_mechanic_name VARCHAR(120) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS updated_at          DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",

    // ── fuel_transactions columns ─────────────────────────────────────────────
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS status        VARCHAR(60)  DEFAULT 'Pending'",
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS validated_by  INT          DEFAULT NULL",
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS validated_at  DATETIME     DEFAULT NULL",
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS reject_reason TEXT         DEFAULT NULL",
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS manager_id    INT          DEFAULT NULL",

    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS job_order_vehicle_brand    VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS job_order_vehicle_model    VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS job_order_year_model       VARCHAR(20)  DEFAULT NULL",
    "ALTER TABLE merchandise_transactions ADD COLUMN IF NOT EXISTS job_order_estimated_duration INT         DEFAULT NULL",

    // ── job_orders columns ────────────────────────────────────────────────────
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS vehicle_brand VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS vehicle_model VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS year_model    VARCHAR(20)  DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS contact_number VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS customer_id       INT          DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validation_status VARCHAR(60)  DEFAULT 'Pending Validation'",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validated_by      INT          DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS validated_at      DATETIME     DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS void_reason       TEXT         DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS adjustment_reason TEXT         DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS manager_remarks   TEXT         DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS rejection_reason  TEXT         DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS amount_paid       DECIMAL(12,2) DEFAULT 0",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS balance_due       DECIMAL(12,2) DEFAULT NULL",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS payment_status    VARCHAR(60)  DEFAULT 'Unpaid'",
    "ALTER TABLE job_orders ADD COLUMN IF NOT EXISTS updated_at        DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
];

foreach ($fixes as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) { /* column may already exist */ }
}

// ── transaction_requests table ───────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transaction_requests (
            id                    INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id        VARCHAR(255) NOT NULL,
            transaction_db_id     INT          NOT NULL,
            transaction_source    VARCHAR(60)  NOT NULL DEFAULT 'merchandise_transactions',
            request_type          VARCHAR(60)  NOT NULL,
            requested_by          INT          NOT NULL,
            reason                TEXT         NOT NULL,
            proposed_changes_json TEXT         DEFAULT NULL,
            status                VARCHAR(60)  NOT NULL DEFAULT 'Pending',
            resolved_by           INT          DEFAULT NULL,
            resolved_at           DATETIME     DEFAULT NULL,
            created_at            DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tr_txn  (transaction_id),
            INDEX idx_tr_stat (status),
            INDEX idx_tr_src  (transaction_source, transaction_db_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// ── adjustment_history table ─────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS adjustment_history (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id     VARCHAR(255) NOT NULL,
            transaction_db_id  INT          NOT NULL,
            request_id         INT          DEFAULT NULL,
            requested_by       INT          DEFAULT NULL,
            approved_by        INT          NOT NULL,
            reason             TEXT         NOT NULL,
            old_values_json    TEXT         NOT NULL,
            new_values_json    TEXT         NOT NULL,
            created_at         DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ah_txn   (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// ── customer_accounts_receivable table ─────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_accounts_receivable (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            customer_id         INT          NOT NULL,
            transaction_id      VARCHAR(255) NOT NULL,
            transaction_db_id   INT          NOT NULL,
            or_number           VARCHAR(100) DEFAULT NULL,
            total_amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
            amount_paid         DECIMAL(12,2) NOT NULL DEFAULT 0,
            outstanding_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            status              VARCHAR(60)  NOT NULL DEFAULT 'Active',
            created_at          DATETIME     DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_car_cust (customer_id),
            INDEX idx_car_txn  (transaction_id),
            INDEX idx_car_stat (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// ── audit_trail table ─────────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_trail (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            user_id         INT          DEFAULT NULL,
            user_role       VARCHAR(60)  DEFAULT NULL,
            action          VARCHAR(100) DEFAULT NULL,
            module          VARCHAR(100) DEFAULT NULL,
            transaction_id  VARCHAR(255) DEFAULT NULL,
            or_number       VARCHAR(100) DEFAULT NULL,
            request_id      INT          DEFAULT NULL,
            old_values_json TEXT         DEFAULT NULL,
            new_values_json TEXT         DEFAULT NULL,
            reason          TEXT         DEFAULT NULL,
            ip_address      VARCHAR(60)  DEFAULT NULL,
            manager_id      INT          DEFAULT NULL,
            action_type     VARCHAR(60)  DEFAULT NULL,
            new_value       TEXT         DEFAULT NULL,
            old_value       TEXT         DEFAULT NULL,
            station_id      INT          NOT NULL DEFAULT 0,
            entity_type     VARCHAR(60)  DEFAULT NULL,
            created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_txn   (transaction_id),
            INDEX idx_user  (user_id),
            INDEX idx_ts    (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

// ── payment_audit_log table ───────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_audit_log (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            record_id      INT          NOT NULL,
            record_source  VARCHAR(80)  NOT NULL DEFAULT 'merchandise_transactions',
            staff_id       INT          DEFAULT NULL,
            station_id     INT          NOT NULL DEFAULT 0,
            amount_paid    DECIMAL(12,2) DEFAULT 0,
            payment_method VARCHAR(60)  DEFAULT NULL,
            balance_due    DECIMAL(12,2) DEFAULT 0,
            payment_status VARCHAR(60)  DEFAULT NULL,
            remarks        TEXT         DEFAULT NULL,
            logged_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rec  (record_id, record_source),
            INDEX idx_sta  (station_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['transaction_schema_fixed'] = true;
}
