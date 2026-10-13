<?php
/**
 * Job Order / Credit Account payment processing helpers.
 *
 * Used by the `settle_payment` action of staff_transactions_hub.php. All writes happen inside
 * the caller's database transaction (the caller has already locked the source row with
 * SELECT ... FOR UPDATE), so payment record, AR balance, customer balance and receipt reference
 * are saved atomically.
 *
 * NOTE: jo_payment_ensure_schema() runs DDL and MUST be called BEFORE beginTransaction().
 */

if (!function_exists('jo_payment_ensure_schema')) {

    function jo_payment_ensure_schema(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        $alters = [
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS customer_id INT NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS transaction_ref VARCHAR(100) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS or_number VARCHAR(60) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS total_amount DECIMAL(12,2) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS previous_paid DECIMAL(12,2) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS receipt_no VARCHAR(60) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS amount_tendered DECIMAL(12,2) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS change_amount DECIMAL(12,2) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS staff_name VARCHAR(150) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS staff_role VARCHAR(40) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS confirmation_status VARCHAR(20) NOT NULL DEFAULT 'Confirmed'",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS is_credit_collection TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(64) NULL",
            "ALTER TABLE payment_audit_log ADD COLUMN IF NOT EXISTS verified_by INT NULL",
            "CREATE UNIQUE INDEX IF NOT EXISTS uq_pal_idem ON payment_audit_log (idempotency_key)",
        ];
        foreach ($alters as $ddl) {
            try { $pdo->exec($ddl); } catch (Throwable $e) { /* already present / unsupported */ }
        }
    }

    function jo_payment_money($v): string
    {
        return '₱' . number_format((float)$v, 2);
    }

    /** Is this saved record a Credit Account (A/R) transaction? */
    function jo_payment_is_credit(array $cur, string $cur_pay_method, string $cur_pay_status): bool
    {
        $m = strtolower(trim($cur_pay_method));
        $s = strtolower(trim($cur_pay_status));
        return $m === 'credit account'
            || str_contains($m, 'receivable')
            || str_contains($m, 'utang')
            || ($m === 'credit')
            || in_array($s, ['credit account', 'credit transaction', 'credit', 'receivables/credit', 'a/r', 'ar'], true)
            || !empty($cur['credit_customer_id'])
            || !empty($cur['is_credit']);
    }

    /**
     * Validate a payment submission against the freshly-locked database row.
     * Returns a normalised context array; throws Exception with a user-facing message on failure.
     */
    function jo_payment_validate(PDO $pdo, array $cur, string $jo_src, int $jo_id, int $station_id, array $post, string $cur_status, string $role): array
    {
        $cur_pay_method = trim($cur['current_payment_method'] ?? $cur['payment_method'] ?? 'Cash');
        $cur_pay_status = trim($cur['current_payment_status'] ?? $cur['payment_status'] ?? 'Pending');
        $is_credit      = jo_payment_is_credit($cur, $cur_pay_method, $cur_pay_status);

        if ($is_credit && !in_array($role, ['staff', 'cashier', 'admin', 'superadmin'], true)) {
            throw new Exception('Only Staff or Admin accounts can process Credit Account payments.');
        }

        // Voided / blocked transactions never accept payment
        $val = strtolower(trim($cur['validation_status'] ?? ''));
        if ($cur_status === 'Voided' || in_array($val, ['voided', 'cancelled', 'canceled', 'rejected'], true)) {
            throw new Exception('Cannot process payment on a voided or cancelled transaction.');
        }
        $void_pending = false;
        try {
            $pending = $pdo->prepare("SELECT 1 FROM transaction_requests WHERE station_id = ? AND transaction_id = ? AND COALESCE(record_source,'job_orders') = ? AND status = 'Pending' AND request_type = 'Void' LIMIT 1");
            $pending->execute([$station_id, (string)$jo_id, $jo_src]);
            $void_pending = (bool)$pending->fetchColumn();
        } catch (PDOException $e) { /* request table/columns differ — ignore */ }
        if ($void_pending) {
            throw new Exception('A void request is pending for this transaction. Payment cannot be processed until it is resolved.');
        }

        // Source of truth: saved total & saved paid amount, recalculated here under row lock
        $total   = round((float)($cur['current_total_amount'] ?? $cur['total_amount'] ?? $cur['total_cost'] ?? $cur['total'] ?? 0), 2);
        $paid    = round((float)($cur['current_amount_paid'] ?? $cur['amount_paid'] ?? 0), 2);
        $balance = max(0, round($total - $paid, 2));
        if ($total <= 0) {
            throw new Exception('This transaction has no billable total.');
        }
        if ($balance <= 0.009) {
            throw new Exception('This transaction has no outstanding balance. It is already fully paid.');
        }

        // Amount
        $raw_amount = trim((string)($post['settle_amount'] ?? ''));
        if ($raw_amount === '' || !is_numeric($raw_amount)) {
            throw new Exception('Please enter a valid payment amount.');
        }
        $amount = round((float)$raw_amount, 2);
        if ($amount <= 0) {
            throw new Exception('Payment amount must be greater than zero.');
        }
        if ($amount > $balance + 0.009) {
            throw new Exception('Payment amount ' . jo_payment_money($amount) . ' exceeds the outstanding balance of ' . jo_payment_money($balance) . '.');
        }

        // Method
        $raw_method = trim((string)($post['settle_method'] ?? 'Cash'));
        $ml = strtolower($raw_method);
        if ($ml === '' ) $ml = 'cash';
        if (str_contains($ml, 'credit account') || str_contains($ml, 'receivable') || str_contains($ml, 'utang') || $ml === 'credit') {
            throw new Exception('Credit Account cannot be used to record a payment. Select the actual payment method received.');
        }
        if ($ml === 'cash') {
            $category = 'cash';
        } elseif (in_array($ml, ['credit card', 'debit card', 'card'], true)) {
            $category = 'card';
        } elseif (in_array($ml, ['gcash', 'maya', 'paymaya', 'e-wallet', 'ewallet'], true)) {
            $category = 'ewallet';
        } elseif (str_contains($ml, 'pvc') || str_contains($ml, 'petron value card')) {
            $category = 'pvc';
        } else {
            throw new Exception('Unsupported payment method selected.');
        }

        $reference = trim((string)($post['settle_reference'] ?? ''));
        if (strlen($reference) > 100) {
            throw new Exception('Reference number is too long (max 100 characters).');
        }
        $tendered = null;
        $change   = 0.0;
        if ($category === 'cash') {
            $reference = '';
            $raw_t = trim((string)($post['settle_tendered'] ?? ''));
            if ($raw_t === '' || !is_numeric($raw_t) || (float)$raw_t <= 0) {
                throw new Exception('Enter the actual cash received from the customer.');
            }
            $tendered = round((float)$raw_t, 2);
            if ($tendered + 0.009 < $amount) {
                throw new Exception('Cash received (' . jo_payment_money($tendered) . ') is less than the payment amount (' . jo_payment_money($amount) . ').');
            }
            $change = max(0, round($tendered - $amount, 2));
        } else {
            if ($reference === '') {
                $label = $category === 'pvc' ? 'PVC card / authorization number' : ($category === 'card' ? 'card terminal reference number' : 'e-wallet reference number');
                throw new Exception('Enter the ' . $label . '.');
            }
            if (empty($post['settle_verified'])) {
                $what = $category === 'pvc' ? 'the Petron-approved PVC verification/redemption was completed'
                      : ($category === 'card' ? 'the card payment was approved on the authorized terminal'
                      : 'the payment was received in the authorized receiving account/app');
                throw new Exception('Confirm that ' . $what . ' before processing this payment.');
            }
        }

        $idem = substr(preg_replace('/[^A-Za-z0-9\-_]/', '', (string)($post['settle_idem'] ?? '')), 0, 64);

        return [
            'is_credit'      => $is_credit,
            'cur_pay_method' => $cur_pay_method,
            'total'          => $total,
            'prev_paid'      => $paid,
            'balance'        => $balance,
            'amount'         => $amount,
            'raw_method'     => $raw_method,
            'category'       => $category,
            'reference'      => $reference,
            'tendered'       => $tendered,
            'change'         => $change,
            'idem'           => $idem !== '' ? $idem : null,
            'notes'          => trim((string)($post['settle_remarks'] ?? '')),
        ];
    }

    /** Look up an earlier submission with the same idempotency key (duplicate click / browser retry). */
    function jo_payment_find_duplicate(PDO $pdo, ?string $idem): ?array
    {
        if (!$idem) return null;
        try {
            $st = $pdo->prepare("SELECT * FROM payment_audit_log WHERE idempotency_key = ? LIMIT 1");
            $st->execute([$idem]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) { return null; }
    }

    /**
     * Insert the confirmed payment record + generate the receipt reference.
     * Returns ['payment_id'=>int,'receipt_no'=>string].
     */
    function jo_payment_record(PDO $pdo, array $cur, array $ctx, string $jo_src, int $jo_id, int $station_id, array $me, string $role, float $new_balance, string $new_status, string $or_number, string $ref_label): array
    {
        $cust_id = (int)($cur['credit_customer_id'] ?? 0) ?: (int)($cur['customer_id'] ?? 0);
        $staff_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
        if ($staff_name === '') $staff_name = (string)($me['name'] ?? $me['username'] ?? ('User #' . ($me['id'] ?? 0)));

        $ins = $pdo->prepare("INSERT INTO payment_audit_log
            (record_id, record_source, staff_id, station_id, amount_paid, payment_method, balance_due, payment_status, remarks, logged_at,
             customer_id, transaction_ref, or_number, total_amount, previous_paid, reference_no, amount_tendered, change_amount,
             staff_name, staff_role, confirmation_status, is_credit_collection, idempotency_key, verified_by)
            VALUES (?,?,?,?,?,?,?,?,?,NOW(), ?,?,?,?,?,?,?,?, ?,?, 'Confirmed', ?, ?, ?)");
        $ins->execute([
            $jo_id, $jo_src, (int)($me['id'] ?? 0), $station_id, $ctx['amount'], $ctx['raw_method'], $new_balance, $new_status, $ctx['notes'],
            $cust_id ?: null, $ref_label, $or_number, $ctx['total'], $ctx['prev_paid'], $ctx['reference'] ?: null,
            $ctx['tendered'], $ctx['category'] === 'cash' ? $ctx['change'] : null,
            $staff_name, $role, $ctx['is_credit'] ? 1 : 0, $ctx['idem'], (int)($me['id'] ?? 0),
        ]);
        $pid = (int)$pdo->lastInsertId();
        $receipt_no = 'PR-' . date('Y') . '-' . str_pad((string)$pid, 6, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE payment_audit_log SET receipt_no = ? WHERE id = ?")->execute([$receipt_no, $pid]);

        return ['payment_id' => $pid, 'receipt_no' => $receipt_no];
    }

    /** Keep Accounts Receivable + customer running balance in sync (credit transactions only). */
    function jo_payment_sync_ar(PDO $pdo, array $cur, array $ctx, string $jo_src, int $jo_id, int $station_id, array $me, float $new_paid, float $new_balance, string $or_number, string $ref_label, string $receipt_no): void
    {
        if (!$ctx['is_credit']) return;

        $cust_id = (int)($cur['credit_customer_id'] ?? 0) ?: (int)($cur['customer_id'] ?? 0);
        $txn_id_str = (string)($cur['transaction_id'] ?? '');
        $mt_id = $jo_src === 'merchandise_transactions' ? $jo_id : (int)($cur['linked_mt_id'] ?? 0);
        $status = ($new_balance <= 0.009) ? 'Settled' : 'Active';

        $found = false;
        try {
            $sel = $pdo->prepare("SELECT id FROM customer_accounts_receivable WHERE ((? > 0 AND transaction_db_id = ?) OR (? <> '' AND transaction_id = ?)) LIMIT 1");
            $sel->execute([$mt_id, $mt_id, $txn_id_str, $txn_id_str]);
            $ar_id = (int)$sel->fetchColumn();
            if ($ar_id) {
                $pdo->prepare("UPDATE customer_accounts_receivable SET amount_paid = ?, outstanding_balance = ?, status = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$new_paid, $new_balance, $status, $ar_id]);
                $found = true;
            }
        } catch (Throwable $e) { error_log('AR lookup/update: ' . $e->getMessage()); }

        if (!$found && $cust_id > 0) {
            try {
                $ar_cols = [];
                foreach ($pdo->query("SHOW COLUMNS FROM customer_accounts_receivable")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $ar_cols[strtolower($c['Field'])] = true;
                }
                $fields = ['customer_id', 'transaction_id', 'transaction_db_id', 'or_number', 'total_amount', 'amount_paid', 'outstanding_balance', 'status'];
                $vals   = [$cust_id, $txn_id_str !== '' ? $txn_id_str : $ref_label, $mt_id ?: $jo_id, $or_number, $ctx['total'], $new_paid, $new_balance, $status];
                if (isset($ar_cols['station_id'])) {
                    $fields[] = 'station_id';
                    $vals[]   = $station_id;
                }
                $field_str = implode(',', $fields) . (isset($ar_cols['created_at']) ? ',created_at' : '');
                $ph_str    = implode(',', array_fill(0, count($fields), '?')) . (isset($ar_cols['created_at']) ? ',NOW()' : '');
                $pdo->prepare("INSERT INTO customer_accounts_receivable ($field_str) VALUES ($ph_str)")->execute($vals);
            } catch (Throwable $e) { error_log('AR insert: ' . $e->getMessage()); }
        }

        if ($cust_id > 0) {
            try {
                $pdo->prepare("UPDATE customers SET balance = GREATEST(0, COALESCE(balance,0) - ?) WHERE id = ? AND station_id = ?")
                    ->execute([$ctx['amount'], $cust_id, $station_id]);
                $b = $pdo->prepare("SELECT balance FROM customers WHERE id = ? AND station_id = ?");
                $b->execute([$cust_id, $station_id]);
                $running = (float)$b->fetchColumn();

                // Ledger: write only to the columns that exist in this installation
                $cols = [];
                foreach ($pdo->query("SHOW COLUMNS FROM customer_credit_transactions")->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[strtolower($c['Field'])] = true;
                $desc = "Credit payment {$receipt_no} (" . $ctx['raw_method'] . ($ctx['reference'] ? ' ref ' . $ctx['reference'] : '') . ") for {$ref_label}";
                $refNo = $ctx['reference'] ?: ($txn_id_str !== '' ? $txn_id_str : $ref_label);
                $data = [
                    'customer_id' => $cust_id, 'station_id' => $station_id, 'transaction_id' => $txn_id_str !== '' ? $txn_id_str : $ref_label,
                    'transaction_type' => 'Payment', 'amount' => $ctx['amount'], 'running_balance' => $running,
                    'payment_method' => $ctx['raw_method'], 'remarks' => $desc, 'description' => $desc, 'created_by' => (int)($me['id'] ?? 0),
                    'reference_no' => $refNo, 'receipt_no' => $receipt_no,
                ];
                $use = array_filter($data, fn($k) => isset($cols[$k]), ARRAY_FILTER_USE_KEY);
                if (isset($use['customer_id'])) {
                    $names = implode(',', array_keys($use));
                    $ph = implode(',', array_fill(0, count($use), '?'));
                    $sql = "INSERT INTO customer_credit_transactions ($names" . (isset($cols['created_at']) ? ',created_at' : '') . ") VALUES ($ph" . (isset($cols['created_at']) ? ',NOW()' : '') . ")";
                    $pdo->prepare($sql)->execute(array_values($use));
                }
            } catch (Throwable $e) { error_log('Customer ledger sync: ' . $e->getMessage()); }
        }
    }

    /**
     * Full payment history for one Job Order / combined transaction (station-isolated).
     * Accurately fetches all payment audit records and synthesizes initial creation payments
     * so that fully-paid or down-paid Job Orders (Cash, Credit Account, E-Wallet, Card)
     * display their complete payment trail chronologically.
     */
    function jo_payment_history(PDO $pdo, string $jo_src, int $jo_id, ?int $station_id = null): array
    {
        $src = $jo_src === 'merchandise_transactions' ? 'merchandise_transactions' : 'job_orders';
        $filter_station = ($station_id !== null && (int)$station_id > 0) ? (int)$station_id : 0;

        $txn = null;
        $linked_jo = null;
        $linked_mt = null;

        if ($src === 'merchandise_transactions') {
            if ($filter_station > 0) {
                $st = $pdo->prepare("SELECT id, transaction_id, or_number, customer_name, payment_method, payment_status, 
                                            total_amount AS total, COALESCE(amount_paid,0) AS paid, 
                                            COALESCE(balance_due, total_amount) AS balance, 
                                            job_order_db_id AS linked_id, job_order_id, workflow_status AS wf, 
                                            validation_status, created_at, staff_id, station_id, reference_no 
                                     FROM merchandise_transactions 
                                     WHERE id = ? AND station_id = ? LIMIT 1");
                $st->execute([$jo_id, $filter_station]);
            } else {
                $st = $pdo->prepare("SELECT id, transaction_id, or_number, customer_name, payment_method, payment_status, 
                                            total_amount AS total, COALESCE(amount_paid,0) AS paid, 
                                            COALESCE(balance_due, total_amount) AS balance, 
                                            job_order_db_id AS linked_id, job_order_id, workflow_status AS wf, 
                                            validation_status, created_at, staff_id, station_id, reference_no 
                                     FROM merchandise_transactions 
                                     WHERE id = ? LIMIT 1");
                $st->execute([$jo_id]);
            }
            $txn = $st->fetch(PDO::FETCH_ASSOC);
            if (!$txn) return ['found' => false, 'payments' => [], 'history' => []];

            $linked_mt = $txn;
            $linked_jo_id = (int)($txn['linked_id'] ?? 0);
            if ($linked_jo_id > 0) {
                $st2 = $pdo->prepare("SELECT id, job_order_id, job_order_number, or_number, customer_name, payment_method, payment_status,
                                             COALESCE(total_cost, estimated_cost, 0) AS total, COALESCE(amount_paid,0) AS paid,
                                             balance_due, status AS wf, validation_status, created_at, created_by, station_id
                                      FROM job_orders WHERE id = ? LIMIT 1");
                $st2->execute([$linked_jo_id]);
                $linked_jo = $st2->fetch(PDO::FETCH_ASSOC);
            }
        } else {
            if ($filter_station > 0) {
                $st = $pdo->prepare("SELECT id, job_order_id AS transaction_id, job_order_id, job_order_number, or_number, customer_name, 
                                            payment_method, payment_status, 
                                            COALESCE(total_cost, estimated_cost, 0) AS total, 
                                            COALESCE(amount_paid,0) AS paid, 
                                            COALESCE(balance_due, COALESCE(total_cost, estimated_cost, 0)) AS balance, 
                                            status AS wf, validation_status, created_at, created_by, station_id
                                     FROM job_orders 
                                     WHERE id = ? AND station_id = ? LIMIT 1");
                $st->execute([$jo_id, $filter_station]);
            } else {
                $st = $pdo->prepare("SELECT id, job_order_id AS transaction_id, job_order_id, job_order_number, or_number, customer_name, 
                                            payment_method, payment_status, 
                                            COALESCE(total_cost, estimated_cost, 0) AS total, 
                                            COALESCE(amount_paid,0) AS paid, 
                                            COALESCE(balance_due, COALESCE(total_cost, estimated_cost, 0)) AS balance, 
                                            status AS wf, validation_status, created_at, created_by, station_id
                                     FROM job_orders 
                                     WHERE id = ? LIMIT 1");
                $st->execute([$jo_id]);
            }
            $txn = $st->fetch(PDO::FETCH_ASSOC);
            if (!$txn) return ['found' => false, 'payments' => [], 'history' => []];

            $linked_jo = $txn;
            // Find linked merchandise_transactions row
            $jo_ref_num = $txn['job_order_id'] ?? '';
            $st2 = $pdo->prepare("SELECT id, transaction_id, or_number, customer_name, payment_method, payment_status, 
                                         total_amount AS total, amount_paid AS paid, balance_due, created_at, staff_id, reference_no
                                  FROM merchandise_transactions 
                                  WHERE job_order_db_id = ? OR (job_order_id = ? AND job_order_id <> '' AND job_order_id IS NOT NULL) 
                                  ORDER BY id DESC LIMIT 1");
            $st2->execute([$jo_id, (string)$jo_id]);
            $linked_mt = $st2->fetch(PDO::FETCH_ASSOC);
            if (!$linked_mt && $jo_ref_num !== '') {
                $st3 = $pdo->prepare("SELECT id, transaction_id, or_number, customer_name, payment_method, payment_status, 
                                             total_amount AS total, amount_paid AS paid, balance_due, created_at, staff_id, reference_no
                                      FROM merchandise_transactions 
                                      WHERE job_order_id = ? 
                                      ORDER BY id DESC LIMIT 1");
                $st3->execute([$jo_ref_num]);
                $linked_mt = $st3->fetch(PDO::FETCH_ASSOC);
            }
        }

        $primary_jo_id = $linked_jo ? (int)$linked_jo['id'] : ($src === 'job_orders' ? $jo_id : 0);
        $primary_mt_id = $linked_mt ? (int)$linked_mt['id'] : ($src === 'merchandise_transactions' ? $jo_id : 0);

        // Canonical financial numbers
        $canon_total = round((float)($txn['total'] ?? 0), 2);
        $canon_paid  = round((float)($txn['paid'] ?? 0), 2);
        $canon_bal   = max(0, round($canon_total - $canon_paid, 2));

        // If payment status is marked Paid/Completed but numeric paid was 0:
        $raw_pay_status = trim($txn['payment_status'] ?? 'Pending');
        $is_paid_status = in_array(strtolower($raw_pay_status), ['paid', 'completed'], true);
        if ($is_paid_status && $canon_paid <= 0.009 && $canon_total > 0) {
            $canon_paid = $canon_total;
            $canon_bal  = 0.00;
        }

        $canon_method  = trim($txn['payment_method'] ?? 'Cash') ?: 'Cash';
        $canon_created = $txn['created_at'] ?? date('Y-m-d H:i:s');

        // Canonical OR / Reference Number
        $canon_or = trim($txn['or_number'] ?? '');
        if ($canon_or === '' && $linked_mt && !empty($linked_mt['or_number'])) {
            $canon_or = trim($linked_mt['or_number']);
        }
        if ($canon_or === '' && $linked_jo && !empty($linked_jo['or_number'])) {
            $canon_or = trim($linked_jo['or_number']);
        }
        if ($canon_or === '') {
            $ref_num_id = $primary_jo_id ?: ($primary_mt_id ?: $jo_id);
            $canon_or = 'OR-' . date('Y', strtotime($canon_created)) . '-' . str_pad((string)$ref_num_id, 6, '0', STR_PAD_LEFT);
        }

        $canon_ref = trim($txn['transaction_id'] ?? '');
        if ($canon_ref === '' && $primary_jo_id > 0) {
            $canon_ref = 'JO #' . $primary_jo_id;
        }

        // Creator / Staff Encoder lookup
        $creator_id = (int)($txn['staff_id'] ?? $txn['created_by'] ?? ($linked_mt['staff_id'] ?? ($linked_jo['created_by'] ?? 0)));
        $creator_name = '';
        if ($creator_id > 0) {
            try {
                $u = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) n, username FROM users WHERE id = ?");
                $u->execute([$creator_id]);
                $ur = $u->fetch(PDO::FETCH_ASSOC);
                if ($ur) {
                    $creator_name = trim($ur['n']) ?: $ur['username'];
                }
            } catch (Throwable $e) {}
        }

        // Build search conditions for payment_audit_log
        $where_clauses = [];
        $params = [];

        if ($primary_jo_id > 0) {
            $where_clauses[] = "(record_source = 'job_orders' AND record_id = ?)";
            $params[] = $primary_jo_id;
        }
        if ($primary_mt_id > 0) {
            $where_clauses[] = "(record_source = 'merchandise_transactions' AND record_id = ?)";
            $params[] = $primary_mt_id;
        }

        // Check by transaction reference strings & OR number
        $ref_strings = array_unique(array_filter([
            $canon_ref,
            $canon_or,
            $linked_jo['job_order_id'] ?? null,
            $linked_jo['job_order_number'] ?? null,
            $linked_mt['transaction_id'] ?? null,
            ($primary_jo_id > 0 ? ('JO #' . $primary_jo_id) : null),
            ($primary_jo_id > 0 ? ('JO-' . $primary_jo_id) : null),
        ]));

        if (!empty($ref_strings)) {
            $placeholders = implode(',', array_fill(0, count($ref_strings), '?'));
            $where_clauses[] = "(transaction_ref IN ($placeholders) OR or_number IN ($placeholders))";
            foreach ($ref_strings as $rs) $params[] = $rs;
            foreach ($ref_strings as $rs) $params[] = $rs;
        }

        $audit_rows = [];
        if (!empty($where_clauses)) {
            $sql = "SELECT id, logged_at, amount_paid, payment_method, reference_no, receipt_no, staff_name, staff_id, 
                           payment_status, balance_due, previous_paid, total_amount, remarks, confirmation_status, 
                           amount_tendered, change_amount, is_credit_collection
                    FROM payment_audit_log 
                    WHERE (" . implode(' OR ', $where_clauses) . ")";
            
            if ($filter_station > 0) {
                $sql .= " AND (station_id = ? OR station_id = 0)";
                $params[] = $filter_station;
            }
            $sql .= " ORDER BY id ASC, logged_at ASC";

            $q = $pdo->prepare($sql);
            $q->execute($params);
            $audit_rows = $q->fetchAll(PDO::FETCH_ASSOC);
        }

        // Deduplicate rows by id and normalize
        $dedup_audit = [];
        $audit_total_paid = 0.0;
        foreach ($audit_rows as $r) {
            $rid = (int)$r['id'];
            if (isset($dedup_audit[$rid])) continue;
            
            $r['amount_paid'] = (float)$r['amount_paid'];
            $r['balance_due'] = (float)$r['balance_due'];
            $r['previous_paid'] = $r['previous_paid'] !== null ? (float)$r['previous_paid'] : null;
            if (empty($r['staff_name']) && !empty($r['staff_id'])) {
                try {
                    $u = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) n, username FROM users WHERE id = ?");
                    $u->execute([(int)$r['staff_id']]);
                    $ur = $u->fetch(PDO::FETCH_ASSOC);
                    $r['staff_name'] = $ur ? (trim($ur['n']) ?: $ur['username']) : ('User #' . $r['staff_id']);
                } catch (Throwable $e) {}
            }
            if (empty($r['receipt_no'])) {
                $r['receipt_no'] = 'PR-' . date('Y', strtotime($r['logged_at'] ?? 'now')) . '-' . str_pad((string)$rid, 6, '0', STR_PAD_LEFT);
            }
            $r['receipt_url'] = 'payment_receipt.php?id=' . $rid;
            $audit_total_paid += $r['amount_paid'];
            $dedup_audit[$rid] = $r;
        }
        $audit_rows = array_values($dedup_audit);

        // Calculate if initial payment record at order creation should be synthesized
        $payments = [];
        $initial_needed = round($canon_paid - $audit_total_paid, 2);

        if ($canon_paid > 0.009 && $initial_needed > 0.009) {
            $receipt_param_id = $primary_jo_id ?: ($primary_mt_id ?: $jo_id);
            $receipt_type = ($src === 'merchandise_transactions' && empty($primary_jo_id)) ? 'merchandise' : 'job_order';
            $init_receipt_url = 'receipt.php?id=' . urlencode((string)$receipt_param_id) . '&type=' . $receipt_type;

            $payments[] = [
                'id'                  => 0,
                'logged_at'           => $canon_created,
                'amount_paid'         => $initial_needed,
                'payment_method'      => $canon_method,
                'reference_no'        => $txn['reference_no'] ?? null,
                'receipt_no'          => $canon_or,
                'staff_name'          => $creator_name ?: 'System Encoder',
                'staff_id'            => $creator_id ?: null,
                'payment_status'      => ($canon_total - $initial_needed <= 0.009) ? 'Paid' : 'Partially Paid',
                'balance_due'         => max(0, round($canon_total - $initial_needed, 2)),
                'previous_paid'       => 0.00,
                'total_amount'        => $canon_total,
                'remarks'             => 'Initial Payment (At Order Creation)',
                'confirmation_status' => 'Confirmed',
                'amount_tendered'     => null,
                'change_amount'       => null,
                'is_credit_collection'=> jo_payment_is_credit([], $canon_method, $raw_pay_status) ? 1 : 0,
                'is_initial'          => true,
                'receipt_url'         => $init_receipt_url,
            ];
        }

        // Append subsequent payments in chronological order
        foreach ($audit_rows as $ar) {
            $payments[] = $ar;
        }

        return [
            'found'    => true,
            'txn'      => [
                'id'             => (int)$txn['id'],
                'source'         => $src,
                'reference'      => $canon_ref,
                'or_number'      => $canon_or,
                'customer'       => $txn['customer_name'] ?? '—',
                'payment_method' => $canon_method,
                'payment_status' => $raw_pay_status,
                'total'          => $canon_total,
                'paid'           => $canon_paid,
                'balance'        => $canon_bal,
                'workflow'       => $txn['wf'] ?? 'Pending',
            ],
            'payments' => $payments,
            'history'  => $payments,
        ];
    }
}

