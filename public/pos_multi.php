<?php
/**
 * POS System - Phase 2: Multi-Product Transactions
 * 
 * This script implements multi-product capability allowing staff to add
 * multiple items to a single transaction with dynamic rows.
 * 
 * Features:
 * - Add/Remove items dynamically
 * - Product type selection (Fuel vs Merchandise)
 * - Auto-populated prices from inventory
 * - Stock validation per item
 * - Individual item subtotals + grand total
 * - Enhanced receipt with all items
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/inventory_automation.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Restrict access - only admin and superadmin can access pos_multi.php
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

// Load inventory from inventory_products table
$inventory = [];
try {
    // Load all products from inventory_products and group by category
    $stmt = $pdo->prepare("
        SELECT ip.product_name as name, ip.category as category_name, ip.size, ip.unit_cost as price,
               si.stock_level, si.unit, si.status as inventory_status
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON ip.product_name = si.product_name AND si.station_id = ?
        WHERE ip.category IS NOT NULL AND ip.product_name IS NOT NULL
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group products by category
    $inventory = [];
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
}

// Handle multi-product transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_name = $_POST['customer_name'] ?? 'Walk-in';
    
    // Parse items from JSON if it's a string
    $items_raw = $_POST['items'] ?? '[]';
    if (is_string($items_raw)) {
        $items = json_decode($items_raw, true) ?? [];
    } else {
        $items = $items_raw;
    }
    
    $raw_payment_type = trim($_POST['payment_type'] ?? 'Cash');
    $raw_ew_provider = trim($_POST['ewallet_provider'] ?? '');
    $norm_pay = function_exists('normalize_payment_type') ? normalize_payment_type($raw_payment_type, $raw_ew_provider) : ['payment_type'=>$raw_payment_type,'provider'=>$raw_ew_provider];
    $payment_type = $norm_pay['payment_type'] ?? 'Cash';
    $ewallet_provider = $norm_pay['provider'] ?? ($payment_type === 'E-Wallet' ? 'GCash' : null);
    $ewallet_reference = trim($_POST['ewallet_reference'] ?? $_POST['gcash_ref_number'] ?? '');
    $fleet_card_number = trim($_POST['fleet_card_number'] ?? '');
    $card_reference = trim($_POST['card_reference'] ?? '');
    $discount = (float)($_POST['discount'] ?? 0);
    
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
                  $pump_id = !empty($item['pump_id']) ? (int)$item['pump_id'] : null;
                  $nozzle_id = !empty($item['nozzle_id']) ? (int)$item['nozzle_id'] : null;
                  
                  if ($product_id <= 0 || $quantity <= 0) {
                      $validation_error = "❌ Error: Invalid product or quantity.";
                      break;
                  }
                  
                  // Get product details
                  $stmt = $pdo->prepare("SELECT p.id as product_id, p.name, pt.name as type_name, si.unit, p.price, p.type_id FROM products p INNER JOIN product_types pt ON p.type_id = pt.id INNER JOIN station_inventory si ON p.id = si.product_id AND si.station_id = ? WHERE p.id = ?");
                  $stmt->execute([$station_id, $product_id]);
                  $product = $stmt->fetch(PDO::FETCH_ASSOC);
                  
                  if (!$product) {
                      $validation_error = "❌ Error: Product not found (ID: $product_id)";
                      break;
                  }
                  
                  // Validate pump for fuel products (nozzle validation removed)
                  if ($product['type_name'] === 'fuel') {
                      if (empty($pump_id)) {
                          $validation_error = "❌ Error: Pump selection is required for {$product['name']}";
                          break;
                      }

                      // Validate pump exists and belongs to this station
                      $stmt = $pdo->prepare("SELECT id FROM fuel_pumps WHERE id = ? AND station_id = ? AND status = 'Active'");
                      $stmt->execute([$pump_id, $station_id]);
                      if (!$stmt->fetch()) {
                          $validation_error = "❌ Error: Invalid pump selection for {$product['name']}";
                          break;
                      }
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
                      'product_id' => $product['product_id'],
                      'name' => $product['name'],
                      'type_name' => $product['type_name'],
                      'quantity' => $quantity,
                      'price' => $item_price,
                      'unit_price' => $item_price,
                      'total' => $item_total,
                      'unit' => $product['unit'],
                      'pump_id' => $pump_id,
                      'nozzle_id' => $nozzle_id,
                      'stock_before' => $stock
                  ];
               }
              
              // Apply discount to total
              $final_total = $total - $discount;
              
               if ($validation_error) {
                   if ($pdo->inTransaction()) {
                       $pdo->rollBack();
                   }
                   $msg = $validation_error;
               } elseif ($final_total <= 0) {
                   if ($pdo->inTransaction()) {
                       $pdo->rollBack();
                   }
                   $msg = "❌ Error: Total amount must be greater than 0.";
               } else {
                 // Insert sale - All transactions are immediate/completed
                 $initial_status = 'Completed';
                 $sale_id = uniqid('SALE-');
                 $is_locked = 1; // Lock completed transactions
                 
                 // Add pump_id column if it doesn't exist
                 try {
                     $pdo->exec("ALTER TABLE sales ADD COLUMN pump_id INT NULL");
                 } catch (PDOException $e) {
                     // Column already exists, ignore
                 }
                 
                 // Ensure sales columns exist
                 foreach ([
                     'customer' => 'VARCHAR(255) NULL',
                     'ewallet_provider' => 'VARCHAR(50) NULL',
                     'ewallet_reference' => 'VARCHAR(100) NULL',
                     'fleet_card_number' => 'VARCHAR(100) NULL',
                     'gcash_ref_number' => 'VARCHAR(100) NULL'
                 ] as $col => $def) {
                     try {
                         $pdo->exec("ALTER TABLE sales ADD COLUMN {$col} {$def}");
                     } catch (PDOException $e) {}
                 }

                 $stmt = $pdo->prepare("INSERT INTO sales (id, station_id, user_id, customer, sale_date, sale_time, payment_method, total, status, pump_id, created_at, gcash_ref_number, ewallet_provider, ewallet_reference, fleet_card_number) VALUES (?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, NOW(), ?, ?, ?, ?)");
                 $stmt->execute([
                     $sale_id,
                     $station_id,
                     $me['id'],
                     $customer_name,
                     $payment_type,
                     $final_total,
                     $initial_status,
                     $item_details[0]['pump_id'] ?? null,
                     ($payment_type === 'E-Wallet' ? $ewallet_reference : null),
                     ($payment_type === 'E-Wallet' ? ($ewallet_provider ?: 'GCash') : null),
                     ($payment_type === 'E-Wallet' ? $ewallet_reference : null),
                     ($payment_type === 'Petron Fleet Card' ? $fleet_card_number : null)
                 ]);
                 $last_sale_id = $sale_id;
                 
                 // Add name column if it doesn't exist
                 try {
                     $pdo->exec("ALTER TABLE sale_items ADD COLUMN name VARCHAR(255) NULL AFTER product_id");
                 } catch (PDOException $e) {
                     // Column already exists, ignore
                 }
                 
                 // Add pump_id and nozzle_id columns if they don't exist
                 try {
                     $pdo->exec("ALTER TABLE sale_items ADD COLUMN pump_id INT NULL");
                     $pdo->exec("ALTER TABLE sale_items ADD COLUMN nozzle_id INT NULL");
                 } catch (PDOException $e) {
                     // Columns already exist, ignore
                 }
                 
                  // Insert items and deduct stock with audit trail
                   $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, pump_id, nozzle_id, quantity, unit_price, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
                   $stmtStock = $pdo->prepare("UPDATE station_inventory SET stock_level = stock_level - ?, last_updated = NOW() WHERE product_id = ? AND station_id = ?");
                   $stmtAudit = $pdo->prepare("INSERT INTO inventory_transactions (station_id, product_id, transaction_type, quantity, reference_type, reference_id, notes, created_by, created_at) VALUES (?, ?, 'pos_sale', ?, 'sales', ?, ?, ?, NOW())");
                   
                   foreach ($item_details as $item) {
                       $stmtItem->execute([$sale_id, $item['product_id'], $item['pump_id'], $item['nozzle_id'], $item['quantity'], $item['unit_price'], $item['total']]);
                      
                      // Deduct stock
                      $stmtStock->execute([$item['quantity'], $item['product_id'], $station_id]);
                      
                      // Record in inventory_transactions for audit trail
                      $stmtAudit->execute([
                          $station_id,
                          $item['product_id'],
                          -$item['quantity'],  // Negative = deduction
                          $sale_id,
                          "POS sale: {$item['name']} x{$item['quantity']} @ P{$item['unit_price']}",
                          $me['id']
                      ]);

                       // Record in inventory_logs for movement history
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
                               "POS Sale (Multi) - Ref: " . $sale_id
                           ]);
                       } catch (Exception $logErr) {
                           error_log("Inventory log insert error in pos_multi.php: " . $logErr->getMessage());
                       }
                  }
                  
                   $pdo->commit();
                   
                   // Log activity after commit (outside transaction)
                   $item_summary = array_map(function($i) { return $i['name'] . ' x' . $i['quantity']; }, $item_details);
                   log_activity($pdo, $me['id'], 'POS Sale', "Sale $sale_id: " . implode(', ', $item_summary) . " | Total: P" . number_format($final_total, 2) . " ($payment_type)", 'pos');
                   
                   $msg = "Transaction completed successfully. Sale ID: $sale_id";
              }
         } catch (Exception $e) {
             if ($pdo->inTransaction()) {
                 $pdo->rollBack();
             }
             $msg = "❌ Error: " . $e->getMessage();
         }
     }
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Multi-Product Transaction</h1>
        <div class="sub">Add multiple items to a single transaction with inventory integration</div>
    </div>
</div>

<?php if($msg): ?>
<div id="toast" class="toast show" style="background: <?php echo strpos($msg, 'Error') !== false ? '#dc3545' : '#28a745'; ?>; position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%); padding: 16px 20px; border-radius: 8px; color: white; font-weight: 500; z-index: 1000; max-width: 500px;">
    <?php echo $msg; ?>
</div>
<script>setTimeout(() => { const el = document.getElementById('toast'); if(el) el.remove(); }, <?php echo strpos($msg, 'Error') !== false ? '8000' : '3000'; ?>);</script>
<?php endif; ?>

<?php if (true): ?>
<!-- Multi-Product Transaction Form (for all users) -->
<div class="card" style="padding: 20px; max-width: 1200px; margin: 0 auto;">
    <form method="post" id="posMultiForm">
        <div style="margin-bottom: 30px;">
            <div class="form-group mb-3">
                <label class="lbl">Customer Name</label>
                <input type="text" name="customer_name" list="customerList" class="inp full" placeholder="Walk-in" required>
                <datalist id="customerList">
                    <?php 
                    $customers = [];
                    try {
                        $customers = $pdo->query("SELECT name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                    } catch(Exception $e) {}
                    foreach($customers as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>
        
        <!-- Items Section -->
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 20px 0; color: #003d7a;">Transaction Items</h3>
            
            <!-- Add New Item Section -->
             <div style="display: grid; grid-template-columns: 1fr 2fr 1fr 1fr; gap: 15px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px dashed #ccc;">
                 <div>
                      <div class="form-group mb-3">
                          <label class="lbl">Type</label>
                          <select id="add_product_type" class="inp full" onchange="loadProductsMulti()">
                              <option value="">Select Type</option>
                              <option value="fuel">Fuel</option>
                              <option value="merch">Merchandise</option>
                          </select>
                      </div>
                 </div>
                 <div>
                      <div class="form-group mb-3">
                          <label class="lbl">Product</label>
                          <input type="text" id="add_product_search" class="inp full" placeholder="Type to search product..." oninput="filterProductsByTyping()" style="margin-bottom: 8px;">
                          <select name="new_product_id" id="add_product_id" class="inp full" onchange="updateProductInfoMulti()">
                              <option value="">Select Product</option>
                          </select>
                          <small class="muted" id="add_stock_info"></small>
                      </div>
                 </div>
                 <div>
                      <div class="form-group mb-3">
                          <label class="lbl">Quantity</label>
                          <input type="number" id="add_quantity" class="inp full" min="1" value="1">
                      </div>
                 </div>
                 <div style="display: flex; align-items: flex-end;">
                     <button type="button" onclick="addItemMulti()" class="btn primary">
                         <i class="fas fa-plus"></i> Add
                     </button>
                 </div>
             </div>
             
             <!-- Pump & Nozzle Selection (shown only for fuel) -->
             <div id="pump_nozzle_section" style="display: none; background: #e8f4f8; padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 4px solid #0066cc;">
                 <h4 style="margin-top: 0;">Fuel Product Details</h4>
                 <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                     <div class="form-group mb-3">
                         <label class="lbl">Pump <span style="color: red;">*</span></label>
                         <select id="add_pump_id" class="inp full" onchange="loadNozzlesMulti()">
                             <option value="">Select Pump</option>
                         </select>
                         <small class="muted" id="add_pump_info"></small>
                     </div>
                     <div class="form-group mb-3">
                         <label class="lbl">Nozzle <span style="color: red;">*</span></label>
                         <select id="add_nozzle_id" class="inp full">
                             <option value="">Select Nozzle</option>
                         </select>
                         <small class="muted" id="add_nozzle_info"></small>
                     </div>
                 </div>
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
                    <label class="lbl">Payment Type <span style="color: red;">*</span></label>
                    <select name="payment_type" id="payment_method_pos" class="inp full" onchange="togglePosMultiPayment()">
                        <option value="">Select payment type</option>
                        <option value="Cash">Cash</option>
                        <option value="Card">Card</option>
                        <option value="E-Wallet">E-Wallet</option>
                        <option value="Petron Value Card (PVC Points)">Petron Value Card (PVC Points)</option>
                        <option value="Credit Account">Credit Account</option>
                    </select>
                </div>
                
                <!-- E-Wallet Provider and Reference Fields -->
                <div id="ewallet_pos_multi_field" style="display: none;">
                    <div class="form-group mb-3">
                        <label class="lbl">E-Wallet Provider <span style="color: red;">*</span></label>
                        <select name="ewallet_provider" id="ewallet_provider" class="inp full">
                            <option value="GCash">GCash</option>
                            <option value="Maya">Maya</option>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="lbl">Reference Number <span style="color: red;">*</span></label>
                        <input type="text" name="ewallet_reference" id="ewallet_reference" class="inp full" placeholder="e.g., GC123456789 or MY123456789">
                        <small class="muted">Enter the transaction reference from the e-wallet app</small>
                    </div>
                </div>

                <!-- Card Details -->
                <div class="form-group mb-3" id="card_pos_multi_field" style="display: none;">
                    <label class="lbl">Card / Auth Reference</label>
                    <input type="text" name="card_reference" id="card_reference" class="inp full" placeholder="e.g., Auth / Ref #">
                </div>

                <!-- PVC Points Details -->
                <div class="form-group mb-3" id="pvc_pos_multi_field" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; padding: 12px; border-radius: 6px;">
                    <label class="lbl" style="color: #1e40af; font-weight:600;"><i class="fas fa-id-card"></i> Petron Value Card (PVC Points)</label>
                    <small class="muted" style="color: #1e40af; display:block;">PVC points redemption will be automatically calculated and validated.</small>
                </div>
            </div>
            
            <div>
                <div class="form-group mb-3">
                    <label class="lbl">Discount</label>
                    <input type="number" name="discount" id="discount" class="inp full" step="0.01" value="0" oninput="calculateGrandTotal()">
                </div>
                
                <div class="total-display" style="margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px; text-align: right;">
                    <div style="font-size: 0.9em; color: #666;">Total Items</div>
                    <div style="font-size: 1.5em; font-weight: bold; color: var(--petron-blue);" id="total_items">0</div>
                    <div style="font-size: 0.9em; color: #666; margin-top: 10px;">Discount</div>
                    <div style="font-size: 0.9em; color: #666;">- ₱</div>
                    <div style="font-size: 0.9em; color: #666; margin-top: 10px;">Grand Total</div>
                    <div style="font-size: 2em; font-weight: bold; color: var(--petron-blue);" id="displayTotal">₱0.00</div>
                </div>
            </div>
        </div>
        
        <div class="actions" style="margin-top: 30px; display: flex; gap: 10px; justify-content: space-between; align-items: center;">
            <button type="button" class="btn ghost" onclick="clearForm()">
                <i class="fas fa-undo"></i> Clear Form
            </button>
            <button type="submit" class="btn primary" onclick="return validateForm();">
                <i class="fas fa-save"></i> Save Transaction
            </button>
        </div>
    </form>
</div>

<?php endif; ?>

<!-- Receipt Modal -->
<?php if($last_sale_id): ?>
<div class="modal show" id="receiptModal">
    <div class="modal-content" style="max-width: 500px; text-align: center;">
        <div class="modal-header">
            <h3 class="modal-title">Transaction Complete</h3>
            <button class="modal-close" onclick="document.getElementById('receiptModal').remove()">&times;</button>
        </div>
        <div class="modal-body">
            <div style="font-size: 48px; color: #28a745; margin-bottom: 10px;"><i class="fas fa-check-circle"></i></div>
            <p style="font-weight: 600; font-size: 1.1em;">Transaction #<?php echo htmlspecialchars($last_sale_id); ?> saved.</p>
            <div style="margin: 12px 0; padding: 10px; background: #f8f9fa; border-radius: 6px; text-align: left; font-size: 14px;">
                <div><strong>Payment Type:</strong> <?php echo htmlspecialchars($payment_type ?? 'Cash'); ?></div>
                <?php if (($payment_type ?? '') === 'E-Wallet' && !empty($ewallet_provider)): ?>
                    <div><strong>Provider:</strong> <?php echo htmlspecialchars($ewallet_provider); ?></div>
                <?php endif; ?>
                <?php if (!empty($ewallet_reference)): ?>
                    <div><strong>Reference No.:</strong> <?php echo htmlspecialchars($ewallet_reference); ?></div>
                <?php elseif (!empty($fleet_card_number)): ?>
                    <div><strong>Card No.:</strong> <?php echo htmlspecialchars($fleet_card_number); ?></div>
                <?php endif; ?>
            </div>
            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
                <button class="btn ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button class="btn ghost" onclick="alert('Email sent!')"><i class="fas fa-envelope"></i> Email</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .item-row {
        display: grid;
        grid-template-columns: 2fr 150px 120px 100px 50px;
        gap: 10px;
        align-items: center;
        padding: 10px;
        background: white;
        border-radius: 6px;
        margin-bottom: 10px;
        border-left: 4px solid #003d7a;
    }
    .item-row:hover {
        background: #f0f0f0;
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
    .toast {
        animation: slideIn 0.3s ease-out;
    }
    @keyframes slideIn {
        from { transform: translateX(-50%) translateY(-100%); opacity: 0; }
        to { transform: translateX(-50%) translateY(0); opacity: 1; }
    }
</style>

<script>
// Product data loaded from PHP - supports both fuel and merchandise
const inventoryData = <?php echo json_encode($inventory); ?>;
console.log('Inventory Data:', inventoryData);
let items = [];
let itemIdCounter = 0;
let currentFuelTypeId = null;

// Load products based on type selection
function loadProductsMulti() {
     const typeSelect = document.getElementById('add_product_type');
     const pumpNozzleSection = document.getElementById('pump_nozzle_section');
     const searchInput = document.getElementById('add_product_search');
     const type = typeSelect.value;

     if (searchInput) {
         searchInput.value = '';
     }

     renderProductsByType(type, '');

     if (type === 'fuel') {
         pumpNozzleSection.style.display = 'block';
     } else {
         pumpNozzleSection.style.display = 'none';
     }
}

function renderProductsByType(type, keyword) {
     const productSelect = document.getElementById('add_product_id');
     const stockInfo = document.getElementById('add_stock_info');

     productSelect.innerHTML = '<option value="">Select Product</option>';

     if (!type || !inventoryData[type]) {
         stockInfo.textContent = 'Select a product type first';
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
         option.dataset.typeId = product.type_id || '';
         option.style = stockClass;

         productSelect.appendChild(option);
     });

     if (normalizedKeyword) {
         stockInfo.textContent = `${filteredProducts.length} of ${allProducts.length} ${type} products match "${keyword}"`;
     } else {
         stockInfo.textContent = `${allProducts.length} ${type} products available`;
     }
}

function filterProductsByTyping() {
     const type = document.getElementById('add_product_type').value;
     const keyword = document.getElementById('add_product_search').value;
     renderProductsByType(type, keyword);
}

// Update product info and load pumps if fuel
function updateProductInfoMulti() {
     const productSelect = document.getElementById('add_product_id');
     const selectedOption = productSelect.options[productSelect.selectedIndex];
     const typeSelect = document.getElementById('add_product_type');
     const type = typeSelect.value;
     
     if (type === 'fuel' && selectedOption && selectedOption.value) {
         currentFuelTypeId = selectedOption.dataset.typeId;
         loadPumpsMulti(currentFuelTypeId);
     }
}

// Load pumps for fuel type
async function loadPumpsMulti(fuelTypeId) {
     const pumpSelect = document.getElementById('add_pump_id');
     const pumpInfo = document.getElementById('add_pump_info');
     
     // Reset nozzles when pump changes
     document.getElementById('add_nozzle_id').innerHTML = '<option value="">Select Nozzle</option>';
     
     if (!fuelTypeId) {
         pumpSelect.innerHTML = '<option value="">Select Pump</option>';
         pumpInfo.textContent = 'Select fuel product first';
         return;
     }
     
     try {
         const response = await fetch(`../backend/api/pumps.php?action=get_by_fuel_type&fuel_type_id=${fuelTypeId}`);
         const data = await response.json();
         
         if (data.success) {
             pumpSelect.innerHTML = '<option value="">Select Pump</option>';
             
             if (data.data.length === 0) {
                 pumpInfo.textContent = '⚠️ No active pumps available for this fuel type';
                 pumpSelect.disabled = true;
             } else {
                 data.data.forEach(pump => {
                     const option = document.createElement('option');
                     option.value = pump.id;
                     option.textContent = `Pump ${pump.pump_number}`;
                     pumpSelect.appendChild(option);
                 });
                 pumpInfo.textContent = `Found ${data.data.length} active pump(s)`;
                 pumpSelect.disabled = false;
             }
         } else {
             pumpInfo.textContent = '❌ Error loading pumps: ' + (data.error || 'Unknown error');
             pumpSelect.disabled = true;
         }
     } catch (error) {
         pumpInfo.textContent = '❌ Error: ' + error.message;
         pumpSelect.disabled = true;
     }
}

// Load nozzles for pump
async function loadNozzlesMulti() {
     const pumpId = document.getElementById('add_pump_id').value;
     const nozzleSelect = document.getElementById('add_nozzle_id');
     const nozzleInfo = document.getElementById('add_nozzle_info');
     
     if (!pumpId) {
         nozzleSelect.innerHTML = '<option value="">Select Nozzle</option>';
         nozzleInfo.textContent = 'Select pump first';
         return;
     }
     
     try {
         const response = await fetch(`../backend/api/nozzles.php?action=get_by_pump&pump_id=${pumpId}`);
         const data = await response.json();
         
         if (data.success) {
             nozzleSelect.innerHTML = '<option value="">Select Nozzle</option>';
             
             if (data.data.length === 0) {
                 nozzleInfo.textContent = '⚠️ No active nozzles available for this pump';
                 nozzleSelect.disabled = true;
             } else {
                 data.data.forEach(nozzle => {
                     const option = document.createElement('option');
                     option.value = nozzle.id;
                     option.textContent = `Nozzle ${nozzle.nozzle_number}`;
                     nozzleSelect.appendChild(option);
                 });
                 nozzleInfo.textContent = `Found ${data.data.length} active nozzle(s)`;
                 nozzleSelect.disabled = false;
             }
         } else {
             nozzleInfo.textContent = '❌ Error loading nozzles: ' + (data.error || 'Unknown error');
             nozzleSelect.disabled = true;
         }
     } catch (error) {
         nozzleInfo.textContent = '❌ Error: ' + error.message;
         nozzleSelect.disabled = true;
     }
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
     // Initialize is handled by product type selection now
});


function addItemMulti() {
     console.log('addItemMulti called, current items:', items);
     const typeSelect = document.getElementById('add_product_type');
     const productSelect = document.getElementById('add_product_id');
     const quantityInput = document.getElementById('add_quantity');
     const selectedOption = productSelect.options[productSelect.selectedIndex];
     const type = typeSelect.value;
     
     // Validation
     if (!type) {
         alert('Please select a product type first.');
         return;
     }
     
     if (!selectedOption || !selectedOption.value) {
         alert('Please select a product first.');
         return;
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
     
     // For fuel products, validate pump and nozzle
     if (type === 'fuel') {
         const pumpId = document.getElementById('add_pump_id').value;
         const nozzleId = document.getElementById('add_nozzle_id').value;
         
         if (!pumpId) {
             alert('Pump selection is required for fuel products.');
             return;
         }
         
         if (!nozzleId) {
             alert('Nozzle selection is required for fuel products.');
             return;
         }
         
         const newItem = {
             id: ++itemIdCounter,
             product_id: parseInt(selectedOption.value),
             name: selectedOption.textContent.split(' (')[0].trim(),
             price: parseFloat(selectedOption.dataset.price) || 0,
             unit: selectedOption.dataset.unit || '',
             stock_level: stockLevel,
             quantity: quantity,
             pump_id: parseInt(pumpId),
             nozzle_id: parseInt(nozzleId),
             type: 'fuel'
         };
         
         console.log('Adding fuel item:', newItem);
         items.push(newItem);
     } else {
         const newItem = {
             id: ++itemIdCounter,
             product_id: parseInt(selectedOption.value),
             name: selectedOption.textContent.split(' (')[0].trim(),
             price: parseFloat(selectedOption.dataset.price) || 0,
             unit: selectedOption.dataset.unit || '',
             stock_level: stockLevel,
             quantity: quantity,
             type: 'merch'
         };
         
         console.log('Adding merchandise item:', newItem);
         items.push(newItem);
     }
     
     // Reset form
     document.getElementById('add_product_type').value = '';
    document.getElementById('add_product_search').value = '';
     document.getElementById('add_product_id').innerHTML = '<option value="">Select Product</option>';
     document.getElementById('add_quantity').value = '1';
     document.getElementById('add_pump_id').innerHTML = '<option value="">Select Pump</option>';
     document.getElementById('add_nozzle_id').innerHTML = '<option value="">Select Nozzle</option>';
     document.getElementById('pump_nozzle_section').style.display = 'none';
     
     console.log('Items after push:', items);
     renderItems();
     calculateGrandTotal();
 }

function renderItems() {
     const container = document.getElementById('items-container');
     
     if (items.length === 0) {
         container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">No items added yet. Click "Add Item" to add products to the transaction.</p>';
         document.getElementById('total_items').textContent = '0';
         return;
     }
     
     container.innerHTML = '';
     let grandTotal = 0;
     
     items.forEach((item, index) => {
         const subtotal = item.quantity * item.price;
         grandTotal += subtotal;
         
         const fuelInfo = item.type === 'fuel' ? ` | Pump: ${item.pump_id}, Nozzle: ${item.nozzle_id}` : '';
         
         const html = `
             <div class="item-row" data-index="${index}">
                 <div class="item-info">
                     <span class="item-name">${item.name}</span>
                     <span class="item-stock">${item.stock_level} ${item.unit} available${fuelInfo}</span>
                 </div>
                 <div class="item-controls">
                     <div style="display: flex; align-items: center; gap: 5px;">
                         <label style="font-size: 12px;">Qty:</label>
                         <input type="number" value="${item.quantity}" 
                                onchange="updateItem(${index}, 'quantity', this.value)"
                                min="1" class="item-qty">
                     </div>
                     <input type="number" value="${item.price}" 
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

function updateItem(index, field, value) {
    const item = items[index];
    const productSelect = document.getElementById('add_product_id');
    const selectedOption = productSelect.options[productSelect.selectedIndex];
    
    if (field === 'quantity') {
        const newQty = parseInt(value) || 1;
        const stockLevel = parseFloat(selectedOption.dataset.stock) || 0;
        
        if (newQty > stockLevel) {
            alert(`Insufficient stock! Available: ${stockLevel} ${item.unit}. Requested: ${newQty} ${item.unit}.`);
            return;
        }
        
        item.quantity = newQty;
    }
    
    renderItems();
}

function removeItem(index) {
    items.splice(index, 1);
    renderItems();
}

function clearForm() {
    if (items.length > 0 && !confirm('Are you sure you want to clear all items?')) {
        return;
    }
    items = [];
    renderItems();
    document.getElementById('add_product_search').value = '';
    document.getElementById('add_product_id').value = '';
    document.getElementById('add_stock_info').textContent = '';
}

function calculateGrandTotal() {
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    let itemsTotal = 0;
    
    items.forEach(item => {
        itemsTotal += item.quantity * item.price;
    });
    
    const grandTotal = itemsTotal - discount;
    document.getElementById('displayTotal').innerText = '₱' + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function togglePosMultiPayment() {
    const paymentType = document.getElementById('payment_method_pos').value;
    const ewField = document.getElementById('ewallet_pos_multi_field');
    const cardField = document.getElementById('card_pos_multi_field');
    const fleetField = document.getElementById('fleet_pos_multi_field');
    
    const pvcField = document.getElementById('pvc_pos_multi_field');
    
    if (ewField) ewField.style.display = (paymentType === 'E-Wallet') ? 'block' : 'none';
    if (cardField) cardField.style.display = (paymentType === 'Card') ? 'block' : 'none';
    if (pvcField) pvcField.style.display = (paymentType === 'Petron Value Card (PVC Points)') ? 'block' : 'none';
}

function toggleGcashRef() {
    togglePosMultiPayment();
}

function validateForm() {
    const form = document.getElementById('posMultiForm');
    const customerName = document.querySelector('input[name="customer_name"]').value.trim();
    const paymentType = document.getElementById('payment_method_pos').value;
    
    if (!customerName) {
        alert('Customer name is required. Please enter a customer name or "Walk-in".');
        return false;
    }
    
    if (items.length === 0) {
        alert('Please add at least one item to the transaction.');
        return false;
    }
    
    if (!paymentType) {
        alert('Payment type is required.');
        return false;
    }
    
    if (paymentType === 'E-Wallet') {
        const ewRef = document.getElementById('ewallet_reference');
        if (!ewRef || !ewRef.value.trim()) {
            alert('Reference number is required for E-Wallet payments.');
            return false;
        }
    }
    
    // Serialize items array into hidden input field
    // Remove any existing items input
    const existingItemsInput = document.getElementById('items_input');
    if (existingItemsInput) {
        existingItemsInput.remove();
    }
    
    // Create hidden input with JSON-encoded items
    const itemsInput = document.createElement('input');
    itemsInput.type = 'hidden';
    itemsInput.id = 'items_input';
    itemsInput.name = 'items';
    itemsInput.value = JSON.stringify(items);
    form.appendChild(itemsInput);
    
    return true;
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
