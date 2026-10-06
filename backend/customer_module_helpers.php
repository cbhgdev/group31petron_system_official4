<?php
/**
 * Shared compatibility helpers for the customer module.
 *
 * The deployed database has both legacy customer columns and newer module
 * columns. These helpers keep Staff, Manager, and Admin screens reading the
 * same values without hard failing when an optional column is absent.
 */

if (!function_exists('customer_table_columns')) {
    function customer_table_columns(PDO $pdo, bool $refresh = false): array {
        static $columns = null;
        if ($columns !== null && !$refresh) {
            return $columns;
        }

        $columns = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM customers");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns[strtolower($row['Field'])] = $row['Field'];
            }
        } catch (Exception $e) {
            $columns = [];
        }

        return $columns;
    }
}

if (!function_exists('customer_has_column')) {
    function customer_has_column(PDO $pdo, string $column): bool {
        $columns = customer_table_columns($pdo);
        return isset($columns[strtolower($column)]);
    }
}

if (!function_exists('customer_ensure_optional_columns')) {
    function customer_ensure_optional_columns(PDO $pdo): void {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['customer_cols_ensured'])) {
            return;
        }
        static $done = false;
        if ($done) {
            return;
        }

        $definitions = [
            'customer_id'            => "VARCHAR(50) NULL DEFAULT NULL",
            'first_name'             => "VARCHAR(100) NULL DEFAULT NULL",
            'middle_name'            => "VARCHAR(100) NULL DEFAULT NULL",
            'last_name'              => "VARCHAR(100) NULL DEFAULT NULL",
            'contact_number'         => "VARCHAR(50) NULL DEFAULT NULL",
            'address'                => "TEXT NULL DEFAULT NULL",
            'email'                  => "VARCHAR(100) NULL DEFAULT NULL",
            'status'                 => "VARCHAR(30) NOT NULL DEFAULT 'active'",
            'vehicle_plate'          => "VARCHAR(50) NULL DEFAULT NULL",
            'vehicle_make'           => "VARCHAR(100) NULL DEFAULT NULL",
            'vehicle_brand'          => "VARCHAR(100) NULL DEFAULT NULL",
            'vehicle_model'          => "VARCHAR(100) NULL DEFAULT NULL",
            'vehicle_type'           => "VARCHAR(100) NULL DEFAULT NULL",
            'credit_limit'           => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            'credit_terms'           => "VARCHAR(50) NULL DEFAULT '30 Days'",
            'balance'                => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            'current_balance'        => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            'outstanding_balance'    => "DECIMAL(15,2) NOT NULL DEFAULT 0.00",
            'gov_id_type'            => "VARCHAR(100) NULL DEFAULT NULL",
            'gov_id_file'            => "VARCHAR(500) NULL DEFAULT NULL",
            'cr_file'                => "VARCHAR(500) NULL DEFAULT NULL",
            'or_file'                => "VARCHAR(500) NULL DEFAULT NULL",
            'company_name'           => "VARCHAR(255) NULL DEFAULT NULL",
            'company_address'        => "TEXT NULL DEFAULT NULL",
            'company_contact_person' => "VARCHAR(255) NULL DEFAULT NULL",
            'company_contact_number' => "VARCHAR(50) NULL DEFAULT NULL",
            'verification_remarks'   => "TEXT NULL DEFAULT NULL",
            'updated_by'             => "INT(11) NULL DEFAULT NULL",
            'updated_at'             => "DATETIME NULL DEFAULT NULL",
            'archived_at'            => "DATETIME NULL DEFAULT NULL",
            'archived_by'            => "INT(11) NULL DEFAULT NULL",
            'archive_reason'         => "VARCHAR(255) NULL DEFAULT NULL",
            'archive_remarks'        => "TEXT NULL DEFAULT NULL",
        ];

        $columns = customer_table_columns($pdo, true);
        foreach ($definitions as $column => $definition) {
            if (!isset($columns[strtolower($column)])) {
                try {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN `$column` $definition");
                } catch (Exception $e) {
                    // Never block the module if the DB user cannot alter schema.
                }
            }
        }

        // Permanently drop customer_type column if it exists in customers or customer_requests
        try {
            $pdo->exec("ALTER TABLE customers DROP COLUMN customer_type");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE customer_requests DROP COLUMN customer_type");
        } catch (Exception $e) {}

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS customer_vehicles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                plate_number VARCHAR(50) NOT NULL,
                vehicle_type VARCHAR(100) NULL,
                brand VARCHAR(100) NULL,
                model VARCHAR(100) NULL,
                year_model VARCHAR(20) NULL,
                color VARCHAR(50) NULL,
                engine_no VARCHAR(100) NULL,
                chassis_no VARCHAR(100) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cust (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS customer_timeline (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                description TEXT NOT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cust (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Exception $e) {}

        customer_table_columns($pdo, true);
        $done = true;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['customer_cols_ensured'] = true;
        }
    }
}

if (!function_exists('customer_ensure_request_table')) {
    function customer_ensure_request_table(PDO $pdo): void {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['customer_req_table_ensured'])) {
            return;
        }
        static $done = false;
        if ($done) {
            return;
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS customer_requests (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    station_id INT UNSIGNED NOT NULL,
                    requested_by INT UNSIGNED NULL DEFAULT NULL,
                    first_name VARCHAR(100) NOT NULL,
                    middle_name VARCHAR(100) NULL DEFAULT NULL,
                    last_name VARCHAR(100) NOT NULL,
                    contact_number VARCHAR(50) NOT NULL,
                    address TEXT NULL DEFAULT NULL,
                    vehicle_plate VARCHAR(50) NULL DEFAULT NULL,
                    vehicle_make VARCHAR(100) NULL DEFAULT NULL,
                    vehicle_model VARCHAR(100) NULL DEFAULT NULL,
                    vehicle_type VARCHAR(100) NULL DEFAULT NULL,
                    request_reason TEXT NULL DEFAULT NULL,
                    status VARCHAR(30) NOT NULL DEFAULT 'pending',
                    reviewed_by INT UNSIGNED NULL DEFAULT NULL,
                    reviewed_at DATETIME NULL DEFAULT NULL,
                    manager_remarks TEXT NULL DEFAULT NULL,
                    customer_record_id INT UNSIGNED NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL DEFAULT NULL,
                    INDEX idx_station_status (station_id, status),
                    INDEX idx_requested_by (requested_by),
                    INDEX idx_customer_record (customer_record_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            // Request support is best-effort for legacy databases.
        }

        $done = true;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['customer_req_table_ensured'] = true;
        }
    }
}

if (!function_exists('customer_expr_col')) {
    function customer_expr_col(PDO $pdo, string $alias, string $column, string $fallback = "NULL"): string {
        return customer_has_column($pdo, $column) ? "{$alias}.{$column}" : $fallback;
    }
}

if (!function_exists('customer_id_expr')) {
    function customer_id_expr(PDO $pdo, string $alias = 'c'): string {
        $raw = customer_expr_col($pdo, $alias, 'customer_id');
        if ($raw !== "NULL") {
            return "COALESCE(NULLIF($raw,''), CONCAT('CUS-', COALESCE({$alias}.station_id,0), '-', LPAD({$alias}.id,5,'0')))";
        }
        return "CONCAT('CUS-', COALESCE({$alias}.station_id,0), '-', LPAD({$alias}.id,5,'0'))";
    }
}

if (!function_exists('customer_display_name_expr')) {
    function customer_display_name_expr(PDO $pdo, string $alias = 'c'): string {
        $parts = [];
        foreach (['first_name', 'middle_name', 'last_name'] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }

        $fullName = $parts ? "NULLIF(TRIM(CONCAT_WS(' ', " . implode(', ', $parts) . ")), '')" : "NULL";
        $legacyName = customer_has_column($pdo, 'name') ? "NULLIF({$alias}.name,'')" : "NULL";

        return "COALESCE($fullName, $legacyName, 'Unknown Customer')";
    }
}

if (!function_exists('customer_first_name_expr')) {
    function customer_first_name_expr(PDO $pdo, string $alias = 'c'): string {
        $first = customer_expr_col($pdo, $alias, 'first_name');
        $name = customer_expr_col($pdo, $alias, 'name');
        if ($first !== "NULL" && $name !== "NULL") {
            return "COALESCE(NULLIF($first,''), SUBSTRING_INDEX($name, ' ', 1), '')";
        }
        if ($first !== "NULL") {
            return "COALESCE(NULLIF($first,''), '')";
        }
        if ($name !== "NULL") {
            return "COALESCE(SUBSTRING_INDEX($name, ' ', 1), '')";
        }
        return "''";
    }
}

if (!function_exists('customer_middle_name_expr')) {
    function customer_middle_name_expr(PDO $pdo, string $alias = 'c'): string {
        $middle = customer_expr_col($pdo, $alias, 'middle_name');
        return $middle === "NULL" ? "''" : "COALESCE($middle,'')";
    }
}

if (!function_exists('customer_last_name_expr')) {
    function customer_last_name_expr(PDO $pdo, string $alias = 'c'): string {
        $last = customer_expr_col($pdo, $alias, 'last_name');
        $name = customer_expr_col($pdo, $alias, 'name');
        if ($last !== "NULL" && $name !== "NULL") {
            return "COALESCE(NULLIF($last,''), CASE WHEN LOCATE(' ', $name) > 0 THEN SUBSTRING($name, LOCATE(' ', $name) + 1) ELSE '' END, '')";
        }
        if ($last !== "NULL") {
            return "COALESCE(NULLIF($last,''), '')";
        }
        if ($name !== "NULL") {
            return "CASE WHEN LOCATE(' ', $name) > 0 THEN SUBSTRING($name, LOCATE(' ', $name) + 1) ELSE '' END";
        }
        return "''";
    }
}

if (!function_exists('customer_contact_expr')) {
    function customer_contact_expr(PDO $pdo, string $alias = 'c'): string {
        $parts = [];
        foreach (['contact_number', 'phone'] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }
        return $parts ? "COALESCE(" . implode(', ', $parts) . ", '')" : "''";
    }
}

if (!function_exists('customer_type_expr')) {
    function customer_type_expr(PDO $pdo, string $alias = 'c'): string {
        $hasType = customer_has_column($pdo, 'type');
        $hasCredit = customer_has_column($pdo, 'credit_limit');
        $conditions = [];
        if ($hasType) {
            $conditions[] = "LOWER(COALESCE({$alias}.type, '')) = 'credit'";
        }
        if ($hasCredit) {
            $conditions[] = "COALESCE({$alias}.credit_limit, 0) > 0";
        }
        $creditCheck = $conditions ? implode(' OR ', $conditions) : '1=0';
        return "CASE WHEN ($creditCheck) THEN 'credit' ELSE 'regular' END";
    }
}

if (!function_exists('customer_vehicle_expr')) {
    function customer_vehicle_expr(PDO $pdo, string $field, string $alias = 'c'): string {
        $fallbacks = [
            'vehicle_plate' => ['plate_number', 'vehicle_plate_no'],
            'vehicle_make'  => ['vehicle_brand', 'make'],
            'vehicle_model' => ['model'],
            'vehicle_type'  => ['vehicle_category'],
        ];

        $parts = [];
        if (customer_has_column($pdo, $field)) {
            $parts[] = "NULLIF({$alias}.{$field},'')";
        }
        foreach ($fallbacks[$field] ?? [] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }
        return $parts ? "COALESCE(" . implode(', ', $parts) . ", '')" : "''";
    }
}

if (!function_exists('customer_status_expr')) {
    function customer_status_expr(PDO $pdo, string $alias = 'c'): string {
        $hasArchivedAt = customer_has_column($pdo, 'archived_at');
        $hasArchiveReason = customer_has_column($pdo, 'archive_reason');
        $status = customer_expr_col($pdo, $alias, 'status');
        $accountStatus = customer_expr_col($pdo, $alias, 'account_status');

        $archivedConditions = [];
        if ($hasArchivedAt) {
            $archivedConditions[] = "{$alias}.archived_at IS NOT NULL";
        }
        if ($hasArchiveReason) {
            $archivedConditions[] = "{$alias}.archive_reason IS NOT NULL AND {$alias}.archive_reason != ''";
        }
        if ($status !== "NULL") {
            $archivedConditions[] = "LOWER($status) = 'archived'";
        }
        if ($accountStatus !== "NULL") {
            $archivedConditions[] = "LOWER($accountStatus) = 'archived'";
        }

        $archivedClause = $archivedConditions ? implode(' OR ', $archivedConditions) : '1=0';

        return "CASE WHEN ($archivedClause) THEN 'archived' ELSE COALESCE(NULLIF($status,''), NULLIF($accountStatus,''), 'active') END";
    }
}

if (!function_exists('customer_registered_at_expr')) {
    function customer_registered_at_expr(PDO $pdo, string $alias = 'c'): string {
        $registered = customer_expr_col($pdo, $alias, 'registered_at');
        $created = customer_expr_col($pdo, $alias, 'created_at');
        if ($registered !== "NULL" && $created !== "NULL") {
            return "COALESCE($registered, $created)";
        }
        return $registered !== "NULL" ? $registered : ($created !== "NULL" ? $created : "NOW()");
    }
}

if (!function_exists('customer_balance_expr')) {
    function customer_balance_expr(PDO $pdo, string $alias = 'c'): string {
        $nonZero = [];
        $raw = [];
        foreach (['outstanding_balance', 'current_balance', 'balance'] as $column) {
            if (customer_has_column($pdo, $column)) {
                $nonZero[] = "NULLIF({$alias}.{$column}, 0)";
                $raw[] = "{$alias}.{$column}";
            }
        }
        return $raw ? "COALESCE(" . implode(', ', array_merge($nonZero, $raw)) . ", 0)" : "0";
    }
}

if (!function_exists('customer_credit_limit_expr')) {
    function customer_credit_limit_expr(PDO $pdo, string $alias = 'c'): string {
        $credit = customer_expr_col($pdo, $alias, 'credit_limit');
        return $credit === "NULL" ? "0" : "COALESCE($credit, 0)";
    }
}

if (!function_exists('customer_verification_status_expr')) {
    function customer_verification_status_expr(PDO $pdo, string $alias = 'c'): string {
        $verification = customer_expr_col($pdo, $alias, 'verification_status');
        $managerStatus = customer_expr_col($pdo, $alias, 'mgr_status');
        if ($verification !== "NULL" && $managerStatus !== "NULL") {
            return "COALESCE(NULLIF($verification,''), NULLIF($managerStatus,''), 'pending')";
        }
        if ($verification !== "NULL") {
            return "COALESCE(NULLIF($verification,''), 'pending')";
        }
        if ($managerStatus !== "NULL") {
            return "COALESCE(NULLIF($managerStatus,''), 'pending')";
        }
        return "'pending'";
    }
}

if (!function_exists('customer_gov_id_type_expr')) {
    function customer_gov_id_type_expr(PDO $pdo, string $alias = 'c'): string {
        $parts = [];
        foreach (['gov_id_type', 'id_type'] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }
        return $parts ? "COALESCE(" . implode(', ', $parts) . ", '')" : "''";
    }
}

if (!function_exists('customer_company_expr')) {
    function customer_company_expr(PDO $pdo, string $field, string $alias = 'c'): string {
        $fallbacks = [
            'company_name'           => [],
            'company_address'        => [],
            'company_contact_person' => ['contact_person'],
            'company_contact_number' => ['phone', 'contact_number'],
        ];

        $parts = [];
        if (customer_has_column($pdo, $field)) {
            $parts[] = "NULLIF({$alias}.{$field},'')";
        }
        foreach ($fallbacks[$field] ?? [] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }
        return $parts ? "COALESCE(" . implode(', ', $parts) . ", '')" : "''";
    }
}

if (!function_exists('customer_verification_remarks_expr')) {
    function customer_verification_remarks_expr(PDO $pdo, string $alias = 'c'): string {
        $parts = [];
        foreach (['verification_remarks', 'mgr_notes'] as $column) {
            if (customer_has_column($pdo, $column)) {
                $parts[] = "NULLIF({$alias}.{$column},'')";
            }
        }
        return $parts ? "COALESCE(" . implode(', ', $parts) . ", '')" : "''";
    }
}

if (!function_exists('customer_user_name_expr')) {
    function customer_user_name_expr(string $alias): string {
        return "COALESCE(NULLIF({$alias}.name,''), NULLIF(TRIM(CONCAT_WS(' ', {$alias}.first_name, {$alias}.last_name)), ''), NULLIF({$alias}.username,''), 'System')";
    }
}

if (!function_exists('customer_can_view_all_stations')) {
    function customer_can_view_all_stations(string $role): bool {
        return in_array(strtolower($role), ['superadmin', 'developer'], true);
    }
}

if (!function_exists('customer_apply_station_scope')) {
    function customer_apply_station_scope(array &$where, array &$params, string $alias, string $role, int $stationId): void {
        if (!customer_can_view_all_stations($role)) {
            $where[] = "{$alias}.station_id = ?";
            $params[] = $stationId;
        }
    }
}

if (!function_exists('customer_station_sql')) {
    function customer_station_sql(string $alias, string $role, int $stationId, array &$params): string {
        if (customer_can_view_all_stations($role)) {
            return "1=1";
        }
        $params[] = $stationId;
        return "{$alias}.station_id = ?";
    }
}

if (!function_exists('customer_legacy_billing_type')) {
    function customer_legacy_billing_type(string $customerType): string {
        return strtolower($customerType) === 'credit' ? 'credit' : 'cash';
    }
}

if (!function_exists('customer_insert_existing')) {
    function customer_insert_existing(PDO $pdo, array $values, array $rawValues = []): int {
        $columns = customer_table_columns($pdo);
        $names = [];
        $holders = [];
        $params = [];

        foreach ($values as $column => $value) {
            if (isset($columns[strtolower($column)])) {
                $names[] = "`$column`";
                $holders[] = "?";
                $params[] = $value;
            }
        }
        foreach ($rawValues as $column => $raw) {
            if (isset($columns[strtolower($column)])) {
                $names[] = "`$column`";
                $holders[] = $raw;
            }
        }

        if (!$names) {
            throw new Exception('No customer fields available to save.');
        }

        $sql = "INSERT INTO customers (" . implode(',', $names) . ") VALUES (" . implode(',', $holders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('customer_update_existing')) {
    function customer_update_existing(PDO $pdo, array $values, string $whereSql, array $whereParams = [], array $rawValues = []): int {
        $columns = customer_table_columns($pdo);
        $sets = [];
        $params = [];

        foreach ($values as $column => $value) {
            if (isset($columns[strtolower($column)])) {
                $sets[] = "`$column` = ?";
                $params[] = $value;
            }
        }
        foreach ($rawValues as $column => $raw) {
            if (isset($columns[strtolower($column)])) {
                $sets[] = "`$column` = $raw";
            }
        }

        if (!$sets) {
            return 0;
        }

        $stmt = $pdo->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE $whereSql");
        $stmt->execute(array_merge($params, $whereParams));
        return $stmt->rowCount();
    }
}
?>
