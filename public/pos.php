<?php
$page_id = 'pos';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Restrict access - only admin and superadmin can access pos.php
// Staff should use staff_transactions.php instead
if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    $_SESSION['error'] = 'Access denied. Staff should use Staff Transactions interface.';
    header('Location: staff_transactions.php');
    exit;
}

$isAdmin = in_array($role, ['admin', 'superadmin']);
$msg = '';
$last_sale_id = '';
$error = '';

// Handle password verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify'])) {
    if ($role === 'admin') {
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE station_id = ? AND status = 'Active' AND role IN ('manager','Manager')");
        $stmt->execute([$station_id]);
        $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $ok = false;
        foreach ($hashes as $hash) {
            if (password_verify($_POST['verify_password'] ?? '', $hash)) { $ok = true; break; }
        }

        if ($ok) {
            $_SESSION['pos_verified'] = true;
            $_SESSION['pos_verified_time'] = time();
        } else {
            $error = 'Incorrect password.';
        }
    } elseif ($role === 'superadmin') {
        $_SESSION['pos_verified'] = true;
        $_SESSION['pos_verified_time'] = time();
    }
}

// Check session verification (valid for 10 mins)
if (isset($_SESSION['pos_verified']) && $_SESSION['pos_verified'] && (time() - $_SESSION['pos_verified_time'] < 600)) {
    $_SESSION['pos_verified_time'] = time(); // extend
}

// Ensure tables exist (Auto-fix for missing tables)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
        id VARCHAR(64) NOT NULL PRIMARY KEY,
        station_id INT,
        user_id INT,
        customer VARCHAR(255),
        payment_method VARCHAR(32) NOT NULL,
        total DECIMAL(12,2) NOT NULL,
        sale_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        due_date DATE NULL,
        status VARCHAR(50) DEFAULT 'Completed',
        is_locked TINYINT(1) DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_id VARCHAR(64),
        product_id INT,
        name VARCHAR(255),
        qty INT,
        price DECIMAL(12,2),
        amount DECIMAL(12,2),
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
    )");
    
    // Add product_id column to sale_items if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE sale_items ADD COLUMN product_id INT NULL");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Add missing columns if they don't exist
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN customer_id VARCHAR(50) NULL");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Add credit_card_number column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN credit_card_number VARCHAR(4) NULL");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Add credit_card_expiry column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN credit_card_expiry VARCHAR(7) NULL");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Add customer_id column for account receivable if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN ar_customer_id VARCHAR(50) NULL");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Add credit_limit column for account receivable if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN credit_limit DECIMAL(10,2) NULL");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Add loyalty-related columns if they don't exist
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN loyalty_type VARCHAR(64) NULL");
    } catch (PDOException $e) {
        // ignore
    }
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN loyalty_card_no VARCHAR(64) NULL");
    } catch (PDOException $e) {
        // ignore
    }
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN loyalty_points_earned INT NULL");
    } catch (PDOException $e) {
        // ignore
    }
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN loyalty_points_redeemed INT NULL");
    } catch (PDOException $e) {
        // ignore
    }
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN ewallet_provider VARCHAR(50) NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN ewallet_reference VARCHAR(100) NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN fleet_card_number VARCHAR(100) NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN card_reference VARCHAR(100) NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE sales ADD COLUMN card_type VARCHAR(50) NULL");
    } catch (PDOException $e) {}
} catch (PDOException $e) {}

// Handle New Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ADMIN ACTIONS: Approve / Reject / Unlock
    if (isset($_POST['action']) && $isAdmin) {
        $sale_id = $_POST['sale_id'] ?? '';
        $action = $_POST['action'];

        // Unlock Transaction
        if ($action === 'unlock' && isset($_POST['unlock_reason']) && !empty($_POST['unlock_reason'])) {
            try {
                // Password verification required
                if (!isset($_SESSION['pos_verified'])) {
                    $error = 'Password verification required to unlock transactions.';
                } else {
                    $role = role_key($me['role'] ?? '');
                    if ($role === 'admin') {
                        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE station_id = ? AND status = 'Active' AND role IN ('manager','Manager')");
                        $stmt->execute([$station_id]);
                        $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        $ok = false;
                        foreach ($hashes as $hash) {
                            if (password_verify($_POST['unlock_password'] ?? '', $hash)) { $ok = true; break; }
                        }

                        if (!$ok) {
                            $error = 'Incorrect password.';
                        }
                    } elseif ($role === 'superadmin') {
                        $ok = true;
                    } else {
                        $error = 'Only Admin can unlock transactions.';
                    }
                }

                if (!isset($error) && $ok) {
                    $unlock_reason = $_POST['unlock_reason'] ?? '';

                    // Unlock the transaction
                    $stmt = $pdo->prepare("UPDATE sales SET is_locked = 0, override_reason = ?, override_by = ?, override_at = NOW() WHERE id = ?");
                    $stmt->execute([$unlock_reason, $me['id'], $sale_id]);

                    log_activity($pdo, $me['id'], 'Admin Unlock Transaction', 'UNLOCKED Transaction #' . $sale_id . ' - Reason: ' . substr($unlock_reason, 0, 100));

                    $msg = "✅ Transaction #" . $sale_id . " unlocked successfully.";
                    $completed_transactions = []; // Refresh list
                }
            } catch (Exception $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }

        // Approve / Reject
        if ($action && in_array($action, ['approve', 'reject'])) {
            try {
                // Password verification required
                if (!isset($_SESSION['pos_verified'])) {
                    $error = 'Password verification required to approve/reject transactions.';
                } else {
                    $role = role_key($me['role'] ?? '');
                    if ($role === 'admin') {
                        // Admin must verify using manager password
                        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE station_id = ? AND status = 'Active' AND role IN ('manager','Manager')");
                        $stmt->execute([$station_id]);
                        $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        $ok = false;
                        foreach ($hashes as $hash) {
                            if (password_verify($_POST['verify_password'] ?? '', $hash)) { $ok = true; break; }
                        }

                        if (!$ok) {
                            $error = 'Incorrect password.';
                        }
                    } elseif ($role === 'superadmin') {
                        $ok = true;
                    } else {
                        $error = 'Only Admin can approve/reject transactions.';
                    }
                }

                if (!isset($error) && $ok) {
                    $new_status = ($action === 'approve') ? 'Completed' : 'Rejected';
                    $stmt = $pdo->prepare("UPDATE sales SET status = ?, is_locked = ? WHERE id = ?");
                    $stmt->execute([$new_status, 1, $sale_id]);

                    $action_verb = ($action === 'approve') ? 'Approved' : 'Rejected';
                    log_activity($pdo, $me['id'], "Transaction $action_verb", "$action_verb transaction #$sale_id");

                    $msg = "✅ Transaction #$sale_id has been " . strtolower($action_verb) . ".";
                }
            } catch (Exception $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        }
    }
    // STAFF ACTION: Create Multi-Item Transaction
    else {
         $customer_name = $_POST['customer_name'] ?? 'Walk-in';
         
         // Parse items from JSON
         $items_raw = $_POST['items'] ?? '[]';
         if (is_string($items_raw)) {
             $items = json_decode($items_raw, true) ?? [];
         } else {
             $items = $items_raw;
         }
         
         $raw_payment_type = $_POST['payment_type'] ?? 'Cash';
         $raw_ew_provider = $_POST['ewallet_provider'] ?? null;
         $norm_pay = function_exists('normalize_payment_type') ? normalize_payment_type($raw_payment_type, $raw_ew_provider) : ['payment_type'=>$raw_payment_type,'provider'=>$raw_ew_provider];
         $payment_type = $norm_pay['payment_type'] ?? 'Cash';
         $ewallet_provider = $norm_pay['provider'] ?? $raw_ew_provider;
         $ewallet_reference = trim($_POST['ewallet_reference'] ?? '');
         $fleet_card_number = trim($_POST['fleet_card_number'] ?? '');
         $credit_card_number = trim($_POST['credit_card_number'] ?? '');
         $credit_card_expiry = trim($_POST['credit_card_expiry'] ?? '');
         $ar_customer_id = trim($_POST['customer_id'] ?? '');
         $credit_limit = trim($_POST['credit_limit'] ?? '');
         $discount_percentage = (float)($_POST['discount'] ?? 0);
         $is_ar = in_array(strtolower($payment_type), ['credit account', 'credit', 'account receivable'], true);
         
         if (empty($items)) {
             $msg = "❌ Error: Please add at least one item to the transaction.";
         } elseif (empty($customer_name)) {
             $msg = "❌ Error: Customer name is required.";
         } elseif (empty($payment_type)) {
             $msg = "❌ Error: Payment type is required.";
          } else {
              try {
                  $pdo->beginTransaction();
                  
                  $total = 0;
                  $item_details = [];
                  $validation_error = '';
                  
                   // Validate and process each item
                   foreach ($items as $item) {
                       if ($validation_error) break; // Exit loop if error found
                       
                       $product_id = (int)($item['product_id'] ?? 0);
                       $quantity = (int)($item['quantity'] ?? 0);
                       
                       if ($product_id <= 0 || $quantity <= 0) {
                           $validation_error = "❌ Error: Invalid product or quantity.";
                           break;
                       }
                       
                       // Get product details from inventory_products
                       $stmt = $pdo->prepare("
                           SELECT ip.product_name as name, ip.category as category_name, 
                                  COALESCE(si.price, ip.unit_cost, 0) as price, ip.size
                           FROM station_inventory si
                           JOIN inventory_products ip ON ip.id = si.product_id
                           WHERE si.station_id = ?
                             AND ip.product_name = (SELECT name FROM products WHERE id = ? LIMIT 1)
                           LIMIT 1
                       ");
                       $stmt->execute([$station_id, $product_id]);
                       $product = $stmt->fetch(PDO::FETCH_ASSOC);
                       
                       if (!$product) {
                           // Fallback to products table if inventory_products not found
                           $stmt = $pdo->prepare("SELECT p.id as product_id, p.name, pt.name as type_name, si.unit, p.price, p.type_id FROM products p INNER JOIN product_types pt ON p.type_id = pt.id INNER JOIN station_inventory si ON p.id = si.product_id AND si.station_id = ? WHERE p.id = ?");
                           $stmt->execute([$station_id, $product_id]);
                           $product = $stmt->fetch(PDO::FETCH_ASSOC);
                       }
                       
                       if (!$product) {
                           $validation_error = "❌ Error: Product not found (ID: $product_id)";
                           break;
                       }
                       
                       // Check stock availability
                       $stmt = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE product_id = ? AND station_id = ?");
                       $stmt->execute([$product_id, $station_id]);
                       $stock = $stmt->fetchColumn();
                       
                       if ($stock === null || $stock === false || $stock < $quantity) {
                           $validation_error = "❌ Error: Insufficient stock for {$product['name']}. Available: {$stock} {$product['unit']}. Requested: {$quantity} {$product['unit']}.";
                           break;
                       }
                       
                       $item_price = $product['price'];
                       $item_total = $quantity * $item_price;
                       $total += $item_total;
                       
                       $item_details[] = [
                           'product_id' => $product_id,
                           'name' => $product['name'],
                           'category_name' => $product['category_name'] ?? 'Merchandise',
                           'quantity' => $quantity,
                           'unit_price' => $item_price,
                           'price' => $item_price,
                           'total' => $item_total,
                           'unit' => $product['unit'] ?? 'pc',
                           'size' => $product['size'] ?? '',
                           'stock_before' => $stock
                       ];
                    }
                   
                   // Apply discount percentage to total
                   $discount_amount = $total * ($discount_percentage / 100);
                   $final_total = $total - $discount_amount;
                   
                   if ($validation_error) {
                       $msg = $validation_error;
                       $pdo->rollBack();
                   } elseif (in_array($payment_type, ['Card', 'Credit Card']) && (empty($credit_card_number) || empty($credit_card_expiry))) {
                       $msg = "❌ Error: Card details are required for Card payments.";
                       $pdo->rollBack();
                   } elseif ($is_ar && empty($ar_customer_id)) {
                        $msg = "❌ Error: Customer ID is required for Credit Account payments.";
                        $pdo->rollBack();
                    } elseif ($is_ar && ($cust_status_check = $pdo->prepare("SELECT status FROM customers WHERE id = ? LIMIT 1")) && $cust_status_check->execute([$ar_customer_id]) && ($cust_status = $cust_status_check->fetchColumn()) && in_array($cust_status, ['locked', 'inactive'])) {
                        $msg = "❌ Error: Customer account is " . $cust_status . ".";
                        $pdo->rollBack();
                    } else {
                        // Insert Sale
                        $sale_id = uniqid('SALE-');
                        $loyalty_type = $_POST['loyalty'] ?? null;
                        $loyalty_card_no = $_POST['loyalty_card_no'] ?? null;
                        $loyalty_points_earned = !empty($_POST['points_earned']) ? (int)$_POST['points_earned'] : null;
                        $loyalty_points_redeemed = !empty($_POST['redeem_points']) ? (int)$_POST['redeem_points'] : null;

                        $stmt = $pdo->prepare("INSERT INTO sales (id, station_id, user_id, customer, sale_date, sale_time, payment_method, total, credit_card_number, credit_card_expiry, ar_customer_id, credit_limit, loyalty_type, loyalty_card_no, loyalty_points_earned, loyalty_points_redeemed, ewallet_provider, ewallet_reference, fleet_card_number, status, created_at) VALUES (?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$sale_id, $station_id, $me['id'], $customer_name, $payment_type, $final_total, 
                            (in_array($payment_type, ['Card', 'Credit Card']) ? $credit_card_number : null),
                            (in_array($payment_type, ['Card', 'Credit Card']) ? $credit_card_expiry : null),
                            ($is_ar ? $ar_customer_id : null),
                            ($is_ar && !empty($credit_limit) ? $credit_limit : null),
                            $loyalty_type,
                            $loyalty_card_no,
                            $loyalty_points_earned,
                            $loyalty_points_redeemed,
                            ($payment_type === 'E-Wallet' ? ($ewallet_provider ?: 'GCash') : null),
                            ($payment_type === 'E-Wallet' ? $ewallet_reference : null),
                            ($payment_type === 'Petron Fleet Card' ? $fleet_card_number : null),
                            'Completed'
                        ]);
                       $last_sale_id = $sale_id;
                       
                        // Insert each item and deduct stock
                        foreach ($item_details as $item) {
                            // Insert Item
                            $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, name, quantity, unit_price, total_amount) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmtItem->execute([$sale_id, $item['product_id'], $item['name'], $item['quantity'], $item['price'], $item['total']]);
                           
                           // Deduct inventory stock using product name
                           $stmtStock = $pdo->prepare("UPDATE station_inventory SET stock_level = stock_level - ? WHERE product_name = ? AND station_id = ?");
                           $stmtStock->execute([$item['quantity'], $item['name'], $station_id]);

                           // Log inventory movement to inventory_logs
                           try {
                               $qtyBefore = (float)($item['stock_before'] ?? 0);
                               $qtyAfter = $qtyBefore - $item['quantity'];
                               $logStmt = $pdo->prepare("
                                   INSERT INTO inventory_logs (
                                       station_id, product_id, user_id, action, 
                                       quantity_before, quantity_after, quantity_change, 
                                       reference_type, notes, created_at
                                   ) VALUES (?, ?, ?, 'sale', ?, ?, ?, 'sale', ?, NOW())
                               ");
                               $logStmt->execute([
                                   $station_id,
                                   $item['product_id'],
                                   $me['id'] ?? null,
                                   $qtyBefore,
                                   $qtyAfter,
                                   -$item['quantity'],
                                   "POS Sale - Ref: " . $sale_id
                               ]);
                           } catch (Exception $logErr) {
                               error_log("Inventory log insert error in pos.php: " . $logErr->getMessage());
                           }
                       }
                       
                        // Update customer balance if Account Receivable / Credit Account
                        if ($is_ar) {
                            $updateBalanceStmt = $pdo->prepare("
                                UPDATE customers 
                                SET balance = balance + ? 
                                WHERE id = ? AND station_id = ?
                            ");
                            $updateBalanceStmt->execute([
                                $final_total,
                                $ar_customer_id,
                                $station_id
                            ]);
                            
                            // Fetch updated balance for running balance
                            $bal_stmt = $pdo->prepare("SELECT balance FROM customers WHERE id = ?");
                            $bal_stmt->execute([$ar_customer_id]);
                            $new_bal = (float)$bal_stmt->fetchColumn();
                            
                            $cct_stmt = $pdo->prepare("
                                INSERT INTO customer_credit_transactions (
                                    customer_id, transaction_id, transaction_type, amount, 
                                    running_balance, description, station_id, created_by, created_at
                                ) VALUES (?, ?, 'Sale', ?, ?, ?, ?, ?, NOW())
                            ");
                            $cct_stmt->execute([
                                $ar_customer_id,
                                $sale_id,
                                $final_total,
                                $new_bal,
                                "POS Sale (Credit) - Ref: " . $sale_id,
                                $station_id,
                                $me['id']
                            ]);
                        }
                        
                        $pdo->commit();
                       $msg = "✅ Multi-item transaction completed successfully. Stock deducted immediately.";
                   }
               } catch (Exception $e) {
                   if ($pdo->inTransaction()) {
                       $pdo->rollBack();
                   }
                   $msg = "❌ Error: " . $e->getMessage();
               }
          }
      }
}

// Fetch customers for autocomplete
$customers = [];
try {
    $customers = $pdo->query("SELECT name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch(Exception $e){}

// Load station inventory grouped by category from inventory_products table
$inventory = [];
$inventoryTypeOptions = [];
try {
    // Load all products from inventory_products and group by category
    $stmt = $pdo->prepare("
        SELECT ip.product_name as name, ip.category as category_name, ip.size, COALESCE(si.price, ip.unit_cost, 0) as price,
               si.stock_level, si.unit, si.status as inventory_status
        FROM station_inventory si
        JOIN inventory_products ip ON ip.id = si.product_id
        WHERE si.station_id = ? AND ip.category IS NOT NULL AND ip.product_name IS NOT NULL
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $product) {
        $categoryName = trim((string)($product['category_name'] ?? ''));
        if ($categoryName === '') {
            $categoryName = 'Others';
        }

        $categoryKey = strtolower(preg_replace('/[^a-z0-9]+/', '_', $categoryName));
        $categoryKey = trim($categoryKey, '_');
        if ($categoryKey === '') {
            $categoryKey = 'others';
        }

        // Create a unique product ID using the product name
        $productId = crc32($product['name'] . $product['category_name']);
        
        if (!isset($inventory[$categoryKey])) {
            $inventory[$categoryKey] = [];
            $inventoryTypeOptions[$categoryKey] = $categoryName;
        }

        $inventory[$categoryKey][] = [
            'id' => $productId,
            'name' => $product['name'],
            'category_name' => $product['category_name'],
            'price' => $product['price'],
            'stock_level' => $product['stock_level'] ?? 0,
            'unit' => $product['unit'] ?? ($product['category_name'] === 'Fuel' ? 'liters' : 'pc'),
            'size' => $product['size'] ?? '',
            'inventory_status' => $product['inventory_status'] ?? 'active'
        ];
    }
     
} catch (Exception $e) {
     $inventory = [];
     $inventoryTypeOptions = [];
}

if (empty($inventoryTypeOptions)) {
    $inventoryTypeOptions = ['others' => 'Others'];
    if (!isset($inventory['others'])) {
        $inventory['others'] = [];
    }
}

// Fetch Recent Completed Transactions for Admin Review
$recent_transactions = [];
if ($isAdmin) {
    try {
        // Get recent completed sales with staff name and item summary
        $sql = "SELECT s.*, u.name as staff_name,
                (SELECT GROUP_CONCAT(CONCAT(name, ' (', qty, ')') SEPARATOR ', ') FROM sale_items WHERE sale_id = s.id) as items_summary,
                (SELECT SUM(qty) FROM sale_items WHERE sale_id = s.id) as total_qty,
                s.credit_card_number, s.credit_card_expiry, s.ar_customer_id, s.credit_limit
                FROM sales s
                LEFT JOIN users u ON s.user_id = u.id
                WHERE s.status = 'Completed' AND s.station_id = ? AND DATE(s.created_at) = CURDATE()
                ORDER BY s.created_at DESC
                LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id]);
        $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Fetch Completed Transactions for Admin Unlock
$completed_transactions = [];
if ($isAdmin) {
    try {
        // Get completed sales with staff name and item summary
        $sql = "SELECT s.*, u.name as staff_name,
                (SELECT GROUP_CONCAT(CONCAT(name, ' (', qty, ')') SEPARATOR ', ') FROM sale_items WHERE sale_id = s.id) as items_summary,
                (SELECT SUM(qty) FROM sale_items WHERE sale_id = s.id) as total_qty,
                s.credit_card_number, s.credit_card_expiry, s.ar_customer_id, s.credit_limit
                FROM sales s
                LEFT JOIN users u ON s.user_id = u.id
                WHERE s.status = 'Completed' AND s.is_locked = 1 AND s.station_id = ?
                ORDER BY s.finalized_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id]);
        $completed_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1"><?php echo $isAdmin ? 'Transaction Overview' : 'New Transaction'; ?></h1>
        <div class="sub"><?php echo $isAdmin ? 'Monitor recent completed transactions' : 'Create a new point of sale transaction'; ?></div>
    </div>
</div>

<?php if($msg): ?>
<div id="toast" class="toast show" style="background: <?php echo strpos($msg, 'Error')!==false ? '#dc3545' : '#28a745'; ?>; position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%); padding: 16px 20px; border-radius: 8px; color: white; font-weight: 500; z-index: 1000; max-width: 500px;">
    <?php echo $msg; ?>
</div>
<script>setTimeout(() => { const el = document.getElementById('toast'); if(el) el.remove(); }, <?php echo strpos($msg, 'Error')!==false ? '8000' : '3000'; ?>);</script>
<?php endif; ?>

<?php if ($isAdmin && !isset($_SESSION['pos_verified'])): ?>
<div class="card" style="max-width: 400px; margin: 40px auto; padding: 30px;">
    <h3 class="h3" style="text-align: center; margin-bottom: 20px;"><i class="fas fa-lock"></i> Security Check</h3>
    <p style="text-align: center; color: #666; margin-bottom: 20px;">
        Admin privileges required. Please enter your password to approve/reject transactions.
    </p>

    <?php if (isset($error)): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; text-align: center;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <div style="margin-bottom: 20px;">
            <input type="password" name="verify_password" class="inp" style="width: 100%; padding: 12px;" placeholder="Enter Password" required autofocus>
        </div>
        <button type="submit" name="verify" class="btn primary" style="width: 100%;">Verify & Continue</button>
    </form>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- ADMIN VIEW: Recent Transactions Table -->
<div class="card" style="padding: 0;">
    <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
        <h3 style="margin: 0; color: #003d7a;">Today's Completed Transactions</h3>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Customer</th>
                    <th>Product Summary</th>
                    <th>Qty/Liters</th>
                    <th>Total Amount</th>
                    <th>Payment</th>
                    <th>Staff Encoder</th>
                    <th>Status</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_transactions)): ?>
                    <tr><td colspan="9" style="text-align:center; padding:30px; color:#666;">No transactions completed today.</td></tr>
                <?php else: ?>
                    <?php foreach($recent_transactions as $t): ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars($t['id']); ?></td>
                        <td><b><?php echo htmlspecialchars($t['customer']); ?></b></td>
                        <td><?php echo htmlspecialchars(mb_strimwidth($t['items_summary'], 0, 40, "...")); ?></td>
                        <td><?php echo number_format($t['total_qty'], 2); ?></td>
                        <td style="font-weight:bold; color:var(--petron-blue);">₱<?php echo number_format($t['total'], 2); ?></td>
                        <?php 
                        $pay_info = function_exists('format_payment_for_record') ? format_payment_for_record($t) : ['payment_type'=>$t['payment_method'], 'provider'=>'', 'reference_no'=>''];
                        ?>
                        <td>
                            <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:600;"><?php echo htmlspecialchars($pay_info['payment_type']); ?></span>
                            <?php if (!empty($pay_info['provider'])): ?>
                                <div style="font-size:11px; color:#059669; font-weight:600; margin-top:2px;">Provider: <?php echo htmlspecialchars($pay_info['provider']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($pay_info['reference_no'])): ?>
                                <div style="font-size:10px; color:#6b7280;">Ref: <?php echo htmlspecialchars($pay_info['reference_no']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($t['staff_name']); ?></td>
                        <td><span class="badge" style="background:#d1fae5; color:#065f46;">Completed</span></td>
                        <td>
                            <div style="display:flex; gap:5px;">
                                <button class="btn small ghost" onclick="viewTransaction(<?php echo htmlspecialchars(json_encode($t)); ?>)" title="View Details">👁️</button>
                                <span style="font-size:11px; color:#666;"><?php echo date('g:i A', strtotime($t['created_at'])); ?></span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ADMIN VIEW: Completed Transactions for Unlock -->
<?php if (!empty($completed_transactions)): ?>
<div class="card" style="padding: 0; margin-top: 30px;">
    <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
        <h3 style="margin: 0; color: #003d7a;">Completed Transactions (Locked)</h3>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Customer</th>
                    <th>Product Summary</th>
                    <th>Total Amount</th>
                    <th>Payment</th>
                    <th>Staff Encoder</th>
                    <th>Finalized</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($completed_transactions as $t): ?>
                <tr>
                    <td>#<?php echo htmlspecialchars($t['id']); ?></td>
                    <td><b><?php echo htmlspecialchars($t['customer']); ?></b></td>
                    <td><?php echo htmlspecialchars(mb_strimwidth($t['items_summary'], 0, 40, "...")); ?></td>
                    <td style="font-weight:bold; color:var(--petron-blue);">₱<?php echo number_format($t['total'], 2); ?></td>
                    <?php 
                    $pay_info_c = function_exists('format_payment_for_record') ? format_payment_for_record($t) : ['payment_type'=>$t['payment_method'], 'provider'=>'', 'reference_no'=>''];
                    ?>
                    <td>
                        <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:600;"><?php echo htmlspecialchars($pay_info_c['payment_type']); ?></span>
                        <?php if (!empty($pay_info_c['provider'])): ?>
                            <div style="font-size:11px; color:#059669; font-weight:600; margin-top:2px;">Provider: <?php echo htmlspecialchars($pay_info_c['provider']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($pay_info_c['reference_no'])): ?>
                            <div style="font-size:10px; color:#6b7280;">Ref: <?php echo htmlspecialchars($pay_info_c['reference_no']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($t['staff_name']); ?></td>
                    <td><?php echo date('M d, h:i A', strtotime($t['finalized_at'] ?? $t['created_at'])); ?></td>
                    <td>
                        <button type="button" class="btn small primary" onclick="openUnlockModal('<?php echo htmlspecialchars($t['id']); ?>', '<?php echo htmlspecialchars($t['customer']); ?>')">
                            <i class="fas fa-unlock"></i> Unlock
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Modal (View Transaction) -->
<div class="modal" id="viewTransModal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">Transaction Details</h3>
            <button class="modal-close" onclick="closeModal('viewTransModal')">&times;</button>
        </div>
        <div class="modal-body" id="viewTransContent">
            <!-- Populated by JS -->
        </div>
        <div class="modal-footer">
            <button class="btn ghost" onclick="closeModal('viewTransModal')">Close</button>
        </div>
    </div>
</div>

<script>
function viewTransaction(t) {
    const content = `
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:20px; background:#f8f9fa; padding:15px; border-radius:8px;">
            <div>
                <small class="text-muted">Transaction ID</small>
                <div style="font-weight:bold;">#${t.id}</div>
            </div>
            <div>
                <small class="text-muted">Customer</small>
                <div style="font-weight:bold;">${t.customer}</div>
            </div>
            <div>
                <small class="text-muted">Staff Encoder</small>
                <div>${t.staff_name}</div>
                <small class="text-muted">${t.created_at}</small>
            </div>
             <div>
                 <small class="text-muted">Payment Type</small>
                 <div style="font-weight:bold;">${t.payment_method || 'Cash'}</div>
                 ${t.ewallet_provider ? `<div style="font-size:12px; color:#059669; font-weight:600;">Provider: ${t.ewallet_provider}</div>` : ''}
                 ${t.ewallet_reference ? `<div style="font-size:11px; color:#666;">Ref: ${t.ewallet_reference}</div>` : ''}
                 ${t.fleet_card_number ? `<div style="font-size:11px; color:#666;">Fleet Card #: ${t.fleet_card_number}</div>` : ''}
                 ${t.credit_card_number ? `<small class="text-muted">Card: ****${t.credit_card_number}</small><br>` : ''}
                 ${t.credit_card_expiry ? `<small class="text-muted">Expires: ${t.credit_card_expiry}</small><br>` : ''}
                 ${t.ar_customer_id ? `<small class="text-muted">Customer ID: ${t.ar_customer_id}</small>` : ''}
             </div>    
             ${t.credit_limit ? `<div><small class="text-muted">Credit Limit: ₱${parseFloat(t.credit_limit).toFixed(2)}</small></div>` : ''}
        </div>
        
        <h4 style="margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:5px;">Product Breakdown</h4>
        <table class="table" style="font-size:0.9em;">
            <thead>
                <tr>
                    <th>Product / Category</th>
                    <th class="right">Qty/Liters</th>
                    <th class="right">Unit Price</th>
                    <th class="right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <!-- In a real app, we'd fetch items via AJAX. For now, parsing summary or showing placeholder if simple -->
                <tr>
                    <td>${t.items_summary}</td>
                    <td class="right">${t.total_qty}</td>
                    <td class="right">-</td>
                    <td class="right"><b>₱${parseFloat(t.total).toLocaleString(undefined, {minimumFractionDigits:2})}</b></td>
                </tr>
            </tbody>
        </table>
        
        <div style="margin-top:20px; text-align:right;">
            <span style="font-size:1.2em; font-weight:bold;">Total: ₱${parseFloat(t.total).toLocaleString(undefined, {minimumFractionDigits:2})}</span>
        </div>
        
        <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end; border-top:1px solid #eee; padding-top:15px;">
            <form method="post" style="flex:1;">
                <input type="hidden" name="sale_id" value="${t.id}">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="btn danger full">Reject ❌</button>
            </form>
            <form method="post" style="flex:1;">
                <input type="hidden" name="sale_id" value="${t.id}">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn primary full">Approve ✅</button>
            </form>
        </div>
    `;
    document.getElementById('viewTransContent').innerHTML = content;
    document.getElementById('viewTransModal').classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}
</script>


<?php else: ?>
<!-- STAFF VIEW: Multi-Item POS Form -->
<div class="card" style="padding: 20px; max-width: 1200px; margin: 0 auto;">
    <form method="post" id="posForm">
        <!-- Customer Information -->
        <div class="form-group mb-3">
            <label class="lbl">Customer Name</label>
            <input type="text" name="customer_name" list="customerList" class="inp full" placeholder="Walk-in" required>
            <datalist id="customerList">
                <?php foreach($customers as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        
        <!-- Transaction Items Section -->
        <div class="card" style="padding: 20px; margin: 20px 0; background: #f8f9fa;">
            <h3 style="margin: 0 0 20px 0; color: #003d7a;">Transaction Items</h3>
            
            <!-- Add New Item Section -->
            <div style="display: grid; grid-template-columns: 1fr 2fr 1fr auto; gap: 15px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px dashed #ccc;">
                <div>
                    <div class="form-group mb-3">
                        <label class="lbl">Type</label>
                        <select id="add_product_type" class="inp full" onchange="loadProductsMulti()">
                            <?php foreach ($inventoryTypeOptions as $typeKey => $typeLabel): ?>
                                <option value="<?php echo htmlspecialchars($typeKey); ?>"><?php echo htmlspecialchars($typeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <div class="form-group mb-3">
                        <label class="lbl">Product</label>
                        <input type="text" id="add_product_search" class="inp full" placeholder="Type product name (auto-suggest)..." oninput="filterProductsByTyping()" onblur="autoPickBestProduct()" style="margin-bottom: 8px;">
                        <div id="product_suggestions" class="product-suggestions" style="display:none;"></div>
                        <select name="new_product_id" id="add_product_id" class="inp full" onchange="updateProductInfoMulti()" style="display:none;">
                            <option value="">Select Product</option>
                        </select>
                        <small class="muted" id="add_stock_info"></small>
                    </div>
                </div>
                <div>
                    <div class="form-group mb-3">
                        <label class="lbl">Quantity <span id="quantity_unit" style="color: #666; font-weight: normal;"></span></label>
                        <input type="number" id="add_quantity" class="inp full" min="1" value="1">
                    </div>
                </div>
                <div style="display: flex; align-items: flex-end;">
                    <button type="button" onclick="addItemMulti()" class="btn primary">
                        <i class="fas fa-plus"></i> Add
                    </button>
                </div>
            </div>
            
            <!-- Items List Container -->
            <div id="items-container">
                <!-- Dynamic items will be rendered here by JavaScript -->
            </div>
        </div>
        
        <!-- Payment & Total Section -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;">
            <div>
                <div class="form-group mb-3">
                    <label class="lbl">Payment Type</label>
                    <select name="payment_type" id="payment_method_pos" class="inp full" onchange="toggleCreditFields(); toggleLoyaltyFields();">
                        <option value="">-- Select Payment Method --</option>
                        <option value="Cash">Cash</option>
                        <option value="Card">Card</option>
                        <option value="E-Wallet">E-Wallet</option>
                        <option value="Petron Value Card (PVC Points)">Petron Value Card (PVC Points)</option>
                        <option value="Credit Account">Credit Account</option>
                    </select>
                </div>

                <!-- E-Wallet Fields (GCash / Maya) -->
                <div class="form-group mb-3" id="ewallet_pos_field" style="display: none; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 12px; border-radius: 6px;">
                    <label class="lbl" style="font-weight: 600; color: #166534;">E-Wallet Provider</label>
                    <select name="ewallet_provider" id="ewallet_provider_pos" class="inp full mb-2">
                        <option value="GCash">GCash</option>
                        <option value="Maya">Maya</option>
                    </select>
                    <label class="lbl" style="font-weight: 600; color: #166534;">Reference Number</label>
                    <input type="text" name="ewallet_reference" id="ewallet_reference_pos" class="inp full" placeholder="e.g. GC123456789 or MY123456789">
                    <small class="muted" style="color: #15803d;">Specify the provider and transaction reference</small>
                </div>

                <!-- Petron Value Card (PVC Points) Field -->
                <div class="form-group mb-3" id="pvc_points_pos_field" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; padding: 12px; border-radius: 6px;">
                    <label class="lbl" style="font-weight: 600; color: #1e40af;"><i class="fas fa-id-card"></i> Petron Value Card (PVC Points)</label>
                    <small class="muted" style="color: #1e40af; display:block; margin-top:2px;">Points will be validated and automatically redeemed upon processing.</small>
                </div>

                <div class="form-group mb-3">
                    <label class="lbl">Loyalty</label>
                    <select name="loyalty" id="loyalty_select" class="inp full" onchange="toggleLoyaltyFields()">
                        <option value="No Loyalty">No Loyalty</option>
                        <option value="Petron Rewards Card">Petron Rewards Card</option>
                    </select>
                </div>

                <!-- Card Field -->
                <div class="form-group mb-3" id="credit_card_field" style="display: none;">
                    <label class="lbl">Card Details</label>
                    <input type="text" name="credit_card_number" id="credit_card_number" class="inp full mb-2" placeholder="Card Number (last 4 digits)" maxlength="4">
                    <input type="text" name="credit_card_expiry" id="credit_card_expiry" class="inp full" placeholder="MM/YY" maxlength="5">
                    <small class="muted">Required for Card payments</small>
                </div>

                <!-- Account Receivable / Credit Account Field -->
                <div class="form-group mb-3" id="account_receivable_field" style="display: none;">
                    <label class="lbl">Customer Credit Details</label>
                    <input type="text" name="customer_id" id="customer_id" class="inp full mb-2" placeholder="Customer ID">
                    <input type="text" name="credit_limit" id="credit_limit" class="inp full" placeholder="Credit Limit (optional)">
                    <small class="muted">Required for Credit Account payments</small>
                </div>

                <!-- Loyalty Fields (shown when Petron Rewards Card selected) -->
                <div id="loyalty_fields" style="display: none; margin-top: 8px;">
                    <div class="form-group mb-3">
                        <label class="lbl">Loyalty Card No.</label>
                        <input type="text" name="loyalty_card_no" id="loyalty_card_no" class="inp full" placeholder="Enter loyalty card number">
                    </div>
                    <div class="form-group mb-3">
                        <label class="lbl">Points Balance</label>
                        <input type="text" id="points_balance" class="inp full" readonly placeholder="0">
                    </div>
                    <div class="form-group mb-3">
                        <label class="lbl">Points Earned</label>
                        <input type="number" id="points_earned" name="points_earned" class="inp full" min="0" value="0">
                    </div>
                    <div class="form-group mb-3">
                        <label class="lbl">Redeem Points (optional)</label>
                        <input type="number" id="redeem_points" name="redeem_points" class="inp full" min="0" value="0">
                    </div>
                </div>
            </div>
            
            <div>
                <div class="form-group mb-3">
                    <label class="lbl">Discount (%)</label>
                    <input type="number" name="discount" id="discount" class="inp full" step="0.1" min="0" max="100" value="0" oninput="calculateGrandTotal()">
                    <small class="muted">Enter discount percentage (0-100%)</small>
                </div>
                
                <div class="total-display" style="margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px; text-align: right;">
                    <div style="font-size: 0.9em; color: #666;">Total Items</div>
                    <div style="font-size: 1.5em; font-weight: bold; color: var(--petron-blue);" id="total_items">0</div>
                    <div style="font-size: 0.9em; color: #666; margin-top: 10px;">Discount</div>
                    <div style="font-size: 0.9em; color: #666;" id="discount_display">0% (- ₱0.00)</div>
                    <div style="font-size: 0.9em; color: #666; margin-top: 10px;">Grand Total</div>
                    <div style="font-size: 2em; font-weight: bold; color: var(--petron-blue);" id="displayTotal">₱0.00</div>
                </div>
            </div>
        </div>
        
        <!-- Hidden field for items JSON -->
        <input type="hidden" name="items" id="items_json">
        
        <div class="actions" style="margin-top: 30px; display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" class="btn ghost" onclick="clearAllItems()">Clear All</button>
            <button type="submit" class="btn primary" onclick="return validateMultiPayment();">Save Transaction</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Receipt Modal -->
<?php if($last_sale_id): ?>
<div class="modal show" id="receiptModal">
    <div class="modal-content" style="max-width: 400px; text-align: center;">
        <div class="modal-header">
            <h3 class="modal-title">Transaction Complete</h3>
            <button class="modal-close" onclick="document.getElementById('receiptModal').remove()">&times;</button>
        </div>
        <div class="modal-body">
            <div style="font-size: 48px; color: #28a745; margin-bottom: 10px;"><i class="fas fa-check-circle"></i></div>
            <p>Transaction #<?php echo $last_sale_id; ?> saved.</p>
            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                <button class="btn ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button class="btn ghost" onclick="alert('Email sent!')"><i class="fas fa-envelope"></i> Email</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    /* Multi-Item POS Styles */
    .item-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        margin-bottom: 10px;
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .item-info {
        display: flex;
        flex-direction: column;
        gap: 3px;
    }
    .item-name {
        font-weight: 600;
        color: #003d7a;
    }
    .item-stock {
        font-size: 12px;
        color: #6c757d;
    }
    .item-controls {
        display: flex;
        gap: 5px;
        align-items: center;
    }
    .item-qty {
        width: 70px;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    .item-price {
        width: 100px;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: #f0f0f0;
        color: #666;
    }
    .item-subtotal {
        font-weight: 600;
        color: #002F6C;
        font-size: 14px;
    }
    .product-suggestions {
        border: 1px solid #d8dee9;
        border-radius: 8px;
        max-height: 220px;
        overflow-y: auto;
        background: #fff;
        box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        margin-bottom: 8px;
    }
    .product-suggestion-item {
        padding: 10px 12px;
        border-bottom: 1px solid #eef2f7;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: center;
    }
    .product-suggestion-item:last-child {
        border-bottom: 0;
    }
    .product-suggestion-item:hover,
    .product-suggestion-item.active {
        background: #f1f6ff;
    }
    .product-suggestion-main {
        color: #183b68;
        font-weight: 600;
        font-size: 13px;
    }
    .product-suggestion-meta {
        color: #5f6f86;
        font-size: 12px;
        white-space: nowrap;
    }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; }
    .inp.full { width: 100%; }
    .mb-3 { margin-bottom: 1rem; }
    @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
</style>

<script>
// Multi-Item POS JavaScript
const inventoryData = <?php echo json_encode($inventory); ?>;
let items = [];
let itemIdCounter = 0;
const defaultType = <?php echo json_encode((string)array_key_first($inventoryTypeOptions)); ?>;

// Initialize the page
document.addEventListener('DOMContentLoaded', function() {
    calculateGrandTotal(); // Initialize totals display
    const typeSelect = document.getElementById('add_product_type');
    if (typeSelect && typeSelect.value) {
        loadProductsMulti();
    }

    const searchInput = document.getElementById('add_product_search');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                autoPickBestProduct();
                addItemMulti();
            }
        });

        searchInput.addEventListener('focus', function() {
            const keyword = searchInput.value || '';
            renderProductsByType(document.getElementById('add_product_type').value, keyword);
        });
    }

    document.addEventListener('click', function(event) {
        const suggestionBox = document.getElementById('product_suggestions');
        const searchInput = document.getElementById('add_product_search');
        if (!suggestionBox || !searchInput) return;

        if (!suggestionBox.contains(event.target) && event.target !== searchInput) {
            suggestionBox.style.display = 'none';
        }
    });
});

// Load products based on type selection for multi-item
function loadProductsMulti() {
    const typeSelect = document.getElementById('add_product_type');
    const quantityUnit = document.getElementById('quantity_unit');
    const searchInput = document.getElementById('add_product_search');
    const type = typeSelect.value;

    if (searchInput) {
        searchInput.value = '';
    }

    quantityUnit.textContent = '';

    renderProductsByType(type, '');
}

function renderProductsByType(type, keyword) {
    const productSelect = document.getElementById('add_product_id');
    const stockInfo = document.getElementById('add_stock_info');
    const suggestionBox = document.getElementById('product_suggestions');

    productSelect.innerHTML = '<option value="">Select Product</option>';

    if (!type || !inventoryData[type]) {
        stockInfo.textContent = 'Select a product type first';
        suggestionBox.style.display = 'none';
        return;
    }

    const normalizedKeyword = (keyword || '').toLowerCase().trim();
    const allProducts = inventoryData[type] || [];
    const filteredProducts = allProducts.filter(product => {
        if (!normalizedKeyword) return true;
        return String(product.name || '').toLowerCase().includes(normalizedKeyword);
    });

    filteredProducts.forEach(product => {
        const option = document.createElement('option');
        option.value = product.id;

        const stockLevel = parseFloat(product.stock_level) || 0;
        const stockClass = stockLevel <= 0 ? 'color: #dc3545; font-weight: bold;' : '';
        const stockText = stockLevel <= 0 ? ' (OUT OF STOCK)' : ` (Stock: ${stockLevel} ${product.unit || 'pc'})`;

        option.textContent = `${product.name}${stockText}`;
        option.dataset.price = product.price || 0;
        option.dataset.stock = stockLevel;
        option.dataset.unit = product.unit || '';
        option.style = stockClass;

        productSelect.appendChild(option);
    });

    renderSuggestionItems(filteredProducts);

    if (normalizedKeyword) {
        stockInfo.textContent = `${filteredProducts.length} of ${allProducts.length} products match "${keyword}"`;
    } else {
        stockInfo.textContent = `${allProducts.length} products available`;
    }
}

function filterProductsByTyping() {
    const type = document.getElementById('add_product_type').value;
    const keyword = document.getElementById('add_product_search').value;
    renderProductsByType(type, keyword);
}

function renderSuggestionItems(products) {
    const suggestionBox = document.getElementById('product_suggestions');
    const stockInfo = document.getElementById('add_stock_info');
    const searchValue = document.getElementById('add_product_search').value.trim();

    if (!products || products.length === 0) {
        suggestionBox.style.display = 'none';
        if (searchValue !== '') {
            stockInfo.textContent = 'No matching product found';
        }
        return;
    }

    suggestionBox.innerHTML = products.map(product => {
        const stockLevel = parseFloat(product.stock_level) || 0;
        const unit = product.unit || 'pc';
        const price = parseFloat(product.price || 0).toFixed(2);
        const stockLabel = stockLevel <= 0 ? 'Out of stock' : `Stock: ${stockLevel} ${unit}`;
        return `<div class="product-suggestion-item" data-product-id="${product.id}">
                    <span class="product-suggestion-main">${escapeHtml(product.name || '')}</span>
                    <span class="product-suggestion-meta">${escapeHtml(stockLabel)} | ₱${price}</span>
                </div>`;
    }).join('');

    suggestionBox.style.display = 'block';

    suggestionBox.querySelectorAll('.product-suggestion-item').forEach(item => {
        item.addEventListener('mousedown', function(event) {
            event.preventDefault();
            selectSuggestedProduct(this.dataset.productId);
        });
    });
}

function selectSuggestedProduct(productId) {
    const productSelect = document.getElementById('add_product_id');
    const searchInput = document.getElementById('add_product_search');
    const suggestionBox = document.getElementById('product_suggestions');

    for (let index = 1; index < productSelect.options.length; index++) {
        if (String(productSelect.options[index].value) === String(productId)) {
            productSelect.selectedIndex = index;
            break;
        }
    }

    const selectedOption = productSelect.options[productSelect.selectedIndex];
    if (selectedOption && selectedOption.value) {
        searchInput.value = selectedOption.textContent.split(' (')[0].trim();
        updateProductInfoMulti();
    }

    suggestionBox.style.display = 'none';
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function autoPickBestProduct() {
    const productSelect = document.getElementById('add_product_id');
    const searchInput = document.getElementById('add_product_search');
    const stockInfo = document.getElementById('add_stock_info');
    const suggestionBox = document.getElementById('product_suggestions');

    if (!productSelect || productSelect.options.length <= 1) {
        return;
    }

    const keyword = (searchInput.value || '').trim().toLowerCase();
    let bestIndex = 1;

    if (keyword) {
        for (let index = 1; index < productSelect.options.length; index++) {
            const optionName = (productSelect.options[index].textContent || '').toLowerCase();
            if (optionName.startsWith(keyword)) {
                bestIndex = index;
                break;
            }
            if (bestIndex === 1 && optionName.includes(keyword)) {
                bestIndex = index;
            }
        }
    }

    productSelect.selectedIndex = bestIndex;
    updateProductInfoMulti();

    const suggested = (productSelect.options[bestIndex].textContent || '').split(' (')[0].trim();
    if (suggested) {
        stockInfo.textContent = `Suggested: ${suggested}`;
    }
    suggestionBox.style.display = 'none';
}

// Update product info (no fuel logic needed)
function updateProductInfoMulti() {
    const productSelect = document.getElementById('add_product_id');
    const quantityUnit = document.getElementById('quantity_unit');
    const selectedOption = productSelect.options[productSelect.selectedIndex];
    
    if (selectedOption && selectedOption.value) {
        const unit = selectedOption.dataset.unit || 'pc';
        quantityUnit.textContent = `(${unit})`;
    } else {
        quantityUnit.textContent = '';
    }
}

// Add item to cart
function addItemMulti() {
    const typeSelect = document.getElementById('add_product_type');
    const productSelect = document.getElementById('add_product_id');
    const quantityInput = document.getElementById('add_quantity');
    let selectedOption = productSelect.options[productSelect.selectedIndex];
    const type = typeSelect.value;
    
    // Validation
    if (!type) {
        alert('Please select a product type first.');
        return;
    }
    
    if (!selectedOption || !selectedOption.value) {
        const keyword = document.getElementById('add_product_search').value.trim();
        if (keyword) {
            autoPickBestProduct();
            selectedOption = productSelect.options[productSelect.selectedIndex];
        }
        if (!selectedOption || !selectedOption.value) {
            alert('No matching product found. Please type a valid product name.');
            return;
        }
    }
    
    const quantity = parseInt(quantityInput.value) || 0;
    if (quantity <= 0) {
        alert('Quantity must be greater than 0.');
        return;
    }
    
    const stockLevel = parseFloat(selectedOption.dataset.stock) || 0;
    if (stockLevel <= 0) {
        alert('This product is out of stock. Please select a different product.');
        return;
    }
    
    if (quantity > stockLevel) {
        alert(`Insufficient stock! Available: ${stockLevel} ${selectedOption.dataset.unit || 'pc'}`);
        return;
    }
    
    // Check if item already exists in cart
    const existingItemIndex = items.findIndex(item => item.product_id === parseInt(selectedOption.value));
    if (existingItemIndex !== -1) {
        // Update existing item quantity
        const newQuantity = items[existingItemIndex].quantity + quantity;
        if (newQuantity > stockLevel) {
            alert(`Cannot add ${quantity} more. Total would exceed available stock (${stockLevel} ${selectedOption.dataset.unit || 'pc'})`);
            return;
        }
        items[existingItemIndex].quantity = newQuantity;
    } else {
        // Add new item
        const newItem = {
            id: ++itemIdCounter,
            product_id: parseInt(selectedOption.value),
            name: selectedOption.textContent.split(' (')[0].trim(),
            price: parseFloat(selectedOption.dataset.price) || 0,
            unit: selectedOption.dataset.unit || '',
            stock_level: stockLevel,
            quantity: quantity,
            type: type
        };
        items.push(newItem);
    }
    
    // Reset form
    document.getElementById('add_product_type').value = defaultType || '';
    document.getElementById('add_product_search').value = '';
    document.getElementById('add_product_id').innerHTML = '<option value="">Select Product</option>';
    document.getElementById('add_quantity').value = '1';
    document.getElementById('add_stock_info').textContent = '';
    document.getElementById('quantity_unit').textContent = '';
    document.getElementById('product_suggestions').style.display = 'none';

    loadProductsMulti();
    
    renderItems();
    calculateGrandTotal();
}

// Render items in the cart
function renderItems() {
    const container = document.getElementById('items-container');
    
    if (items.length === 0) {
        container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">No items added yet. Select products and click "Add" to build your transaction.</p>';
        document.getElementById('total_items').textContent = '0';
        return;
    }
    
    container.innerHTML = '';
    let grandTotal = 0;
    
    items.forEach((item, index) => {
        const subtotal = item.quantity * item.price;
        grandTotal += subtotal;
        
        const html = `
            <div class="item-row" data-index="${index}">
                <div class="item-info">
                    <span class="item-name">${item.name}</span>
                    <span class="item-stock">${item.stock_level} ${item.unit} available</span>
                </div>
                <div class="item-controls">
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <label style="font-size: 12px;">Qty (${item.unit}):</label>
                        <input type="number" value="${item.quantity}" 
                               onchange="updateItem(${index}, 'quantity', this.value)"
                               min="1" max="${item.stock_level}" class="item-qty">
                    </div>
                    <input type="number" value="${item.price.toFixed(2)}" 
                           readonly
                           class="item-price">
                    <span class="item-subtotal">₱${subtotal.toFixed(2)}</span>
                    <button type="button" onclick="removeItem(${index})" class="btn small danger">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        `;
        container.innerHTML += html;
    });
    
    document.getElementById('total_items').textContent = items.length;
    calculateGrandTotal();
}

// Update item quantity
function updateItem(index, field, value) {
    const item = items[index];
    
    if (field === 'quantity') {
        const newQty = parseInt(value) || 1;
        
        if (newQty > item.stock_level) {
            alert(`Insufficient stock! Available: ${item.stock_level} ${item.unit}. Requested: ${newQty} ${item.unit}.`);
            renderItems(); // Reset to previous value
            return;
        }
        
        if (newQty <= 0) {
            removeItem(index);
            return;
        }
        
        item.quantity = newQty;
    }
    
    renderItems();
}

// Remove item from cart
function removeItem(index) {
    items.splice(index, 1);
    renderItems();
}

// Clear all items
function clearAllItems() {
    if (items.length === 0) {
        alert('No items to clear.');
        return;
    }
    if (!confirm('Clear all items from cart?')) {
        return;
    }
    items = [];
    
    // Reset discount field
    document.getElementById('discount').value = '0';
    
    // Reset form fields
    document.getElementById('add_product_type').value = '';
    document.getElementById('add_product_search').value = '';
    document.getElementById('add_product_id').innerHTML = '<option value="">Select Product</option>';
    document.getElementById('add_stock_info').textContent = '';
    document.getElementById('quantity_unit').textContent = '';
    document.getElementById('product_suggestions').style.display = 'none';
    document.getElementById('add_quantity').value = '1';
    
    renderItems();
}

// Toggle fields based on payment type
function toggleCreditFields() {
    const paymentType = document.getElementById('payment_method_pos').value;
    const creditCardField = document.getElementById('credit_card_field');
    const ewalletField = document.getElementById('ewallet_pos_field');
    const fleetCardField = document.getElementById('fleet_card_pos_field');
    const accountReceivableField = document.getElementById('account_receivable_field');

    // Hide all additional fields by default
    if (creditCardField) creditCardField.style.display = 'none';
    if (ewalletField) ewalletField.style.display = 'none';
    if (fleetCardField) fleetCardField.style.display = 'none';
    if (accountReceivableField) accountReceivableField.style.display = 'none';

    // Show relevant fields based on payment type
    if (paymentType === 'Card' || paymentType === 'Credit Card' || paymentType === 'Debit Card') {
        if (creditCardField) creditCardField.style.display = 'block';
    } else if (paymentType === 'E-Wallet' || paymentType === 'GCash' || paymentType === 'Maya') {
        if (ewalletField) {
            ewalletField.style.display = 'block';
            if (paymentType === 'Maya') {
                const prov = document.getElementById('ewallet_provider_pos');
                if (prov) prov.value = 'Maya';
            } else if (paymentType === 'GCash') {
                const prov = document.getElementById('ewallet_provider_pos');
                if (prov) prov.value = 'GCash';
            }
        }
    } else if (paymentType === 'Petron Value Card (PVC Points)') {
        const pvcField = document.getElementById('pvc_points_pos_field');
        if (pvcField) pvcField.style.display = 'block';
    } else if (paymentType === 'Credit Account' || paymentType === 'Account Receivable' || paymentType === 'Credit') {
        if (accountReceivableField) accountReceivableField.style.display = 'block';
    }

    // Keep loyalty fields updated as well
    toggleLoyaltyFields();
}

// Toggle Loyalty fields
function toggleLoyaltyFields() {
    const loyaltySelect = document.getElementById('loyalty_select');
    const loyaltyFields = document.getElementById('loyalty_fields');
    if (!loyaltySelect || !loyaltyFields) return;

    if (loyaltySelect.value === 'Petron Rewards Card') {
        loyaltyFields.style.display = 'block';
    } else {
        loyaltyFields.style.display = 'none';
    }
}

// Validate multi-item payment
function validateMultiPayment() {
    // Get form elements
    const customerName = document.querySelector('input[name="customer_name"]').value.trim();
    const paymentType = document.getElementById('payment_method_pos').value;
    
    // Validate customer name
    if (!customerName) {
        alert('Customer name is required. Please enter a customer name or "Walk-in".');
        return false;
    }
    
    // Validate items
    if (items.length === 0) {
        alert('Please add at least one item to the transaction.');
        return false;
    }
    
    // Validate payment type
    if (!paymentType) {
        alert('Payment method is required.');
        return false;
    }
    
    // Additional validation for Card
    if (paymentType === 'Card' || paymentType === 'Credit Card') {
        const cardNumber = document.getElementById('credit_card_number');
        const cardExpiry = document.getElementById('credit_card_expiry');
        
        if (!cardNumber || !cardNumber.value.trim()) {
            alert('Card number is required for Card payments.');
            return false;
        }
        
        if (!cardExpiry || !cardExpiry.value.trim()) {
            alert('Card expiry date is required for Card payments.');
            return false;
        }
    }

    // Additional validation for E-Wallet
    if (paymentType === 'E-Wallet') {
        const ewProv = document.getElementById('ewallet_provider_pos');
        if (!ewProv || !ewProv.value.trim()) {
            alert('Please select an E-Wallet Provider (GCash or Maya).');
            return false;
        }
    }

    // Additional validation for Credit Account
    if (paymentType === 'Credit Account' || paymentType === 'Account Receivable') {
        const customerId = document.getElementById('customer_id');
        
        if (!customerId || !customerId.value.trim()) {
            alert('Customer ID is required for Credit Account payments.');
            return false;
        }
    }
    
    // Loyalty validation when Petron Rewards Card selected
    const loyaltyEl = document.getElementById('loyalty_select');
    if (loyaltyEl && loyaltyEl.value === 'Petron Rewards Card') {
        const cardNo = document.getElementById('loyalty_card_no');
        if (!cardNo || !cardNo.value.trim()) {
            alert('Loyalty Card No. is required for Petron Rewards Card.');
            return false;
        }
    }
    
    // Validate payment type specific field submission
    document.getElementById('items_json').value = JSON.stringify(items);
    
    return true;
}

// Calculate grand total with discount
function calculateGrandTotal() {
    let subtotal = 0;
    
    // Calculate subtotal from all items
    items.forEach(item => {
        subtotal += item.quantity * item.price;
    });
    
    // Get discount percentage
    const discountPercentage = parseFloat(document.getElementById('discount').value) || 0;
    
    // Calculate discount amount
    const discountAmount = subtotal * (discountPercentage / 100);
    
    // Calculate final total
    const grandTotal = subtotal - discountAmount;
    
    // Update display
    document.getElementById('discount_display').textContent = `${discountPercentage}% (-₱${discountAmount.toFixed(2)})`;
    document.getElementById('displayTotal').textContent = `₱${grandTotal.toFixed(2)}`;
}

function openUnlockModal(saleId, customerName) {
    const reason = prompt('Reason for unlocking transaction #' + saleId + ' (' + customerName + '):\n\nRequired for audit trail (minimum 10 characters)');
    if (reason && reason.length >= 10) {
        const password = prompt('Enter your password to confirm unlock:');
        if (password) {
            const form = document.createElement('form');
            form.method = 'post';
            form.innerHTML = `
                <input type="hidden" name="action" value="unlock">
                <input type="hidden" name="sale_id" value="${saleId}">
                <input type="hidden" name="unlock_reason" value="${reason}">
                <input type="hidden" name="unlock_password" value="${password}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    } else if (reason && reason.length > 0) {
        alert('Reason must be at least 10 characters for audit trail compliance.');
    }
}

</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
