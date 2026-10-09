<?php
$page_id = 'transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'admin', 'manager', 'superadmin'])) {
    header('Location: login.php');
    exit;
}

$msg = '';
if (isset($_SESSION['success'])) { 
    $msg = $_SESSION['success']; 
    unset($_SESSION['success']); 
}
if (isset($_SESSION['error'])) { 
    $msg = $_SESSION['error']; 
    unset($_SESSION['error']); 
}

// Fuel-related functionality removed - now handled in Fuel Management module

// Fetch merchandise options — unified catalog (same source as Pricing/Inventory/POS)
$merchandise_products = [];
try {
    $stmt = $pdo->prepare("
        SELECT ip.id                                                          AS product_id,
               ip.product_name,
               COALESCE(NULLIF(TRIM(ip.sku),''), CONCAT('P', LPAD(ip.id,4,'0'))) AS sku,
               COALESCE(NULLIF(TRIM(ip.category),''),'General')              AS category,
               COALESCE(NULLIF(TRIM(ip.size),''),NULLIF(TRIM(si.unit),''),'') AS size,
               COALESCE(si.price, ip.unit_price, 0)                          AS unit_price,
               COALESCE(si.stock_level, ip.stock_quantity, ip.stock, 0)      AS stock_level
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
          AND LOWER(COALESCE(ip.status,'active')) NOT IN ('archived','deleted','inactive')

        UNION

        SELECT p.id                                                           AS product_id,
               p.name                                                         AS product_name,
               COALESCE(NULLIF(TRIM(p.sku),''), CONCAT('P', LPAD(p.id,4,'0'))) AS sku,
               COALESCE(pc.name,'General')                                    AS category,
               COALESCE(NULLIF(p.unit,''),'')                                 AS size,
               COALESCE(si2.price, p.price, si2.cost, p.cost, 0)             AS unit_price,
               COALESCE(si2.stock_level, p.current_stock, 0)                 AS stock_level
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        LEFT JOIN station_inventory si2 ON si2.product_id = p.id AND si2.station_id = ?
        WHERE LOWER(COALESCE(pc.name,'')) NOT IN ('fuel','fuel products','services','service')
          AND LOWER(COALESCE(p.status,'active')) NOT IN ('deleted','archived')
          AND p.id NOT IN (SELECT id FROM inventory_products WHERE LOWER(COALESCE(status,'active')) NOT IN ('archived','deleted') AND LOWER(COALESCE(category,'')) NOT IN ('fuel','fuel products'))

        ORDER BY category, product_name
    ");
    $stmt->execute([$station_id, $station_id]);
    $merchandise_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Merchandise load error: ' . $e->getMessage());
    $merchandise_products = [];
}

// Fetch customers for credit transactions
$customers = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, credit_limit, balance FROM customers WHERE station_id = ? AND status = 'Active' ORDER BY name");
    $stmt->execute([$station_id]);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $customers = [];
}

// Get current shift info
$current_shift = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM labor_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
    $stmt->execute([$me['id']]);
    $current_shift = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $current_shift = null;
}

include __DIR__ . '/../partials/header.php';
?>

<script>
// Initialize merchandise products array from PHP data
let merchandiseProducts = <?php 
    $products_for_js = [];
    if (!empty($merchandise_products)) {
        foreach ($merchandise_products as $product) {
            $products_for_js[] = [
                'product_id' => $product['product_id'],
                'sku' => $product['sku'],
                'product_name' => $product['product_name'],
                'clean_name' => strtolower(trim($product['product_name'])),
                'category' => $product['category'],
                'size' => $product['size'],
                'unit_price' => (float)$product['unit_price'],
                'stock_level' => (int)($product['stock_level'] ?? 0)
            ];
        }
    }
    echo json_encode($products_for_js);
?>;
</script>

<style>
/* Staff Transactions Page Styles */
.staff-transactions-container {
    padding: 0;
    max-width: 100%;
}

/* Merchandise container layout */
.merchandise-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    align-items: start;
}

/* Merchandise form section */
.merchandise-form-section {
    min-height: 100%;
}

/* Cart panel */
.cart-panel {
    position: sticky;
    top: 20px;
}

.transaction-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 30px;
    border-bottom: 2px solid #e9ecef;
}

.tab-btn {
    padding: 12px 24px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-weight: 600;
    color: #666;
    transition: all 0.3s ease;
}

.tab-btn.active {
    color: #003d7a;
    border-bottom-color: #003d7a;
}

.tab-btn:hover {
    color: #003d7a;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.transaction-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    padding: 30px;
    margin-bottom: 20px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}

.form-input {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}

.form-input:focus {
    outline: none;
    border-color: #003d7a;
    box-shadow: 0 0 0 3px rgba(0,61,122,0.1);
}

.form-select {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    background: white;
}

.auto-pulled {
    background: #f8f9fa;
    border-color: #28a745;
    color: #28a745;
}

.computed {
    background: #e3f2fd;
    border-color: #2196f3;
    color: #1976d2;
}

.calculation-display {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin: 20px 0;
}

.calc-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    padding: 8px 0;
}

.calc-row.total {
    border-top: 2px solid #333;
    padding-top: 15px;
    font-weight: bold;
    font-size: 18px;
}

.btn-primary {
    background: #003d7a;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

.btn-primary:hover {
    background: #002855;
}

.btn-secondary {
    background: #6c757d;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

.shift-info {
    background: #e3f2fd;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.cart-item {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
}

.cart-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.cart-item-info {
    flex: 1;
}

.cart-item-name {
    font-weight: 600;
    color: #333;
    margin-bottom: 5px;
}

.cart-item-details {
    font-size: 14px;
    color: #666;
}

.cart-item-price {
    text-align: right;
    margin-right: 15px;
}

.cart-item-subtotal {
    font-weight: bold;
    color: #003d7a;
}

.cart-item-actions {
    display: flex;
    gap: 5px;
}

.cart-btn {
    background: none;
    border: none;
    padding: 5px;
    cursor: pointer;
    border-radius: 4px;
    transition: all 0.3s ease;
    font-size: 14px;
}

.cart-btn:hover {
    background: #e9ecef;
}

.cart-btn.edit {
    color: #007bff;
}

.cart-btn.remove {
    color: #dc3545;
}

/* Notification Animations */
@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideOut {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .transaction-tabs {
        flex-direction: column;
    }
    
    .merchandise-container {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .cart-panel {
        position: static;
    }
}
</style>

<div class="stock-page">
<div class="page-head">
    <div>
        <h1 class="h1">Staff Transactions</h1>
        <div class="sub">Process merchandise transactions and manage inventory requests</div>
    </div>
</div>

<?php if($msg): ?>
<div class="petron-flash <?php echo (stripos($msg, 'error') !== false || stripos($msg, 'fail') !== false) ? 'flash-error' : 'flash-success'; ?>" role="alert">
    <i class="fas <?php echo (stripos($msg, 'error') !== false || stripos($msg, 'fail') !== false) ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
    <span><?php echo htmlspecialchars($msg); ?></span>
    <button class="flash-close" onclick="this.parentElement.remove();" title="Dismiss">&times;</button>
</div>
<?php endif; ?>

<div class="shift-info">
    <div>
        <strong>Current Shift:</strong> 
        <?php if ($current_shift): ?>
            <?php echo date('M j, Y h:i A', strtotime($current_shift['start_time'])); ?> - Active
        <?php else: ?>
            No active shift
        <?php endif; ?>
    </div>
    <div>
        <strong>Staff:</strong> <?php echo htmlspecialchars($me['name'] ?? $me['username']); ?> | 
        <strong>Station:</strong> #<?php echo (int)$station_id; ?>
    </div>
</div>

<div class="staff-transactions-container">
    <div class="transaction-card">
        <h2 style="margin-bottom: 30px; color: #003d7a;">
            <i class="fas fa-shopping-cart"></i> Merchandise Transactions & Inventory
        </h2>
            
            <!-- Unified Inventory View -->
        <div style="display: grid; gap: 30px;">
            

            <!-- Merchandise Inventory Section -->
            <div style="background: white; border: 1px solid #e9ecef; border-radius: 12px; overflow: hidden;">
                <div style="padding: 20px; background: linear-gradient(135deg, #28a745, #20c997); color: white;">
                    <h3 style="margin: 0; display: flex; align-items: center; gap: 10px;">
Merchandise Inventory
                    </h3>
                </div>
                <div style="padding: 20px;">
                    <div style="overflow:hidden;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Product</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Category</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Stock</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Unit Cost</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="merchandise-tbody">
                                <tr>
                                    <td colspan="5" style="padding: 40px; text-align: center; color: #6c757d;">
                                        <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        Loading merchandise inventory...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Merchandise Transaction Section -->
            <div style="background: white; border: 1px solid #e9ecef; border-radius: 12px; overflow: hidden; margin-top: 30px;">
                <div style="padding: 20px; background: linear-gradient(135deg, #28a745, #20c997); color: white;">
                    <h3 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-shopping-cart"></i>
                        Merchandise Transaction
                    </h3>
                </div>
                <div style="padding: 20px;">
                    <form id="merchandise-form">
                        <div class="merchandise-container">
                            <div class="merchandise-form-section">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Search Item</label>
                                        <div style="position: relative;">
                                            <input type="text" id="item_name_search" 
                                                   class="form-input" 
                                                   placeholder="Type to search merchandise..." 
                                                   autocomplete="off">
                                            <input type="hidden" id="item_sku" name="item_sku">
                                            <div id="item_suggestions" style="position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #e9ecef; border-top: none; max-height: 200px; overflow-y: auto; z-index: 1000; display: none;"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Category <small style="color: #28a745;">(Auto-display)</small></label>
                                        <input type="text" name="category" id="category" 
                                               class="form-input auto-pulled" readonly>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Size Variant <small style="color: #28a745;">(Auto-display)</small></label>
                                        <input type="text" name="size_variant" id="size_variant" 
                                               class="form-input auto-pulled" readonly>
                                    </div>
                                    

                                    <div class="form-group">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" name="quantity" id="quantity" 
                                               class="form-input" min="1" value="1" onchange="computeMerchTransaction()" required>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Unit Price <small style="color: #28a745;">(Auto-pulled)</small></label>
                                        <input type="number" name="unit_price" id="unit_price" 
                                               class="form-input auto-pulled" step="0.01" readonly>
                                    </div>
                                    

                                    <div class="form-group">
                                        <!-- Empty for balance -->
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Payment Method</label>
                                        <select name="payment_method" id="payment_method_merch" class="form-input" required onchange="toggleCashFields()">
                                            <option value="">Select payment method</option>
                                            <option value="Cash">Cash</option>
                                            <option value="Card">Card</option>
                                            <option value="E-Wallet">E-Wallet</option>
                                            <option value="Petron Value Card (PVC Points)">Petron Value Card (PVC Points)</option>
                                            <option value="Credit Account">Credit Account</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row" id="cash_payment_fields" style="display: none;">
                                    <div class="form-group">
                                        <label class="form-label">Amount Tendered</label>
                                        <input type="number" name="amount_tendered" id="amount_tendered_merch" 
                                               class="form-input" placeholder="Enter amount received" 
                                               step="0.01" min="0" onchange="computeChange()">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Change</label>
                                        <input type="number" name="change_amount" id="change_amount_merch" 
                                               class="form-input" placeholder="Auto-computed" readonly>
                                    </div>
                                </div>

                                <div class="form-row" id="card_payment_fields" style="display: none;">
                                    <div class="form-group">
                                        <label class="form-label">Card Reference Number</label>
                                        <input type="text" name="card_reference" id="card_reference_merch" 
                                               class="form-input" placeholder="Enter card reference number">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Card Type</label>
                                        <select name="card_type" id="card_type_merch" class="form-input">
                                            <option value="">Select card type</option>
                                            <option value="Credit Card">Credit Card</option>
                                            <option value="Debit Card">Debit Card</option>
                                            <option value="Gift Card">Gift Card</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row" id="ewallet_payment_fields" style="display: none;">
                                    <div class="form-group">
                                        <label class="form-label">E-Wallet Provider <span style="color: red;">*</span></label>
                                        <select name="ewallet_provider" id="ewallet_provider_merch" class="form-input">
                                            <option value="GCash">GCash</option>
                                            <option value="Maya">Maya</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Reference Number <span style="color: red;">*</span></label>
                                        <input type="text" name="ewallet_reference" id="ewallet_reference_merch" 
                                               class="form-input" placeholder="e.g., GC123456789 or MY123456789">
                                    </div>
                                </div>

                                <div class="form-row" id="pvc_payment_fields" style="display: none;">
                                    <div class="form-group">
                                        <label class="form-label">Petron Value Card (PVC Points)</label>
                                        <p style="margin: 0; color: #1e40af; font-size: 13px;">Customer loyalty points will be validated and redeemed upon submission.</p>
                                    </div>
                                </div>

                                <div class="form-row" id="efuel_payment_fields" style="display: none;">
                                    <div class="form-group">
                                        <label class="form-label">E-Fuel Card Number</label>
                                        <input type="text" name="efuel_card_number" id="efuel_card_number_merch" 
                                               class="form-input" placeholder="Enter e-fuel card number">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Card Balance</label>
                                        <input type="number" name="efuel_card_balance" id="efuel_card_balance_merch" 
                                               class="form-input" placeholder="Card balance" readonly>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Customer Name</label>
                                        <input type="text" name="customer_name" id="customer_name_merch" 
                                               class="form-input" placeholder="Enter customer name" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Credit Customer ID (if applicable)</label>
                                        <input type="text" name="credit_customer_id" id="credit_customer_id_merch" 
                                               class="form-input" placeholder="Enter credit customer ID">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Remarks</label>
                                        <input type="text" name="remarks" id="remarks" 
                                               class="form-input" placeholder="Optional remarks">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="button" class="btn-primary" onclick="addToCart()" style="width: 100%;">
                                            <i class="fas fa-plus"></i> Add to Cart
                                        </button>
                                    </div>
                                </div>
                            </div><!-- /merchandise-form-section -->

                            <div class="cart-panel">
                                <!-- Cart Section -->
                                <div style="padding: 20px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
                                    <h3 style="margin: 0 0 20px 0; color: #003d7a; display: flex; align-items: center; gap: 10px;">
                                        <i class="fas fa-shopping-cart"></i>
                                        Shopping Cart
                                        <span id="cart-count" style="background: #003d7a; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem;">0</span>
                                    </h3>

                                    <div id="cart-items" style="min-height: 100px; margin-bottom: 20px;">
                                        <div style="text-align: center; color: #666; padding: 30px;">
                                            <i class="fas fa-shopping-cart" style="font-size: 48px; margin-bottom: 15px; display: block; opacity: 0.3;"></i>
                                            <p>Your cart is empty</p>
                                        </div>
                                    </div>

                                    <div id="cart-summary" style="display: none;">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 15px; background: white; border-radius: 6px; border-left: 4px solid #003d7a;">
                                            <div>
                                                <strong style="color: #003d7a;">Grand Total:</strong>
                                                <span id="grand_total" style="font-size: 1.2rem; color: #003d7a;">PHP 0.00</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div id="cart-actions" style="display: none;">
                                        <div style="display: flex; gap: 10px;">
                                            <button type="button" class="btn-secondary" onclick="clearCart()" style="flex: 1;">
                                                <i class="fas fa-trash"></i> Clear Cart
                                            </button>
                                            <button type="button" class="btn-primary" onclick="printReceipt()" style="flex: 2;">
                                                <i class="fas fa-cash-register"></i> Process Transaction
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div><!-- /cart-panel -->
                        </div><!-- /merchandise-container -->

                        <!-- Hidden fields for transaction -->
                        <input type="hidden" name="staff_id" value="<?php echo $me['id']; ?>">
                        <input type="hidden" name="shift_id" value="<?php echo $current_shift['id'] ?? ''; ?>">
                        <input type="hidden" name="transaction_timestamp" id="transaction_timestamp">
                    </form>
                </div>
            </div>

            <!-- Staff Request History Section -->
            <div style="background: white; border: 1px solid #e9ecef; border-radius: 12px; overflow: hidden;">
                <div style="padding: 20px; background: linear-gradient(135deg, #6f42c1, #5a32a3); color: white;">
                    <h3 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-clipboard-list"></i>
                        My Request History
                    </h3>
                </div>
                <div style="padding: 20px;">
                    <!-- Status Flow Guide -->
                    <div style="background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 10px 0; color: #495057; font-size: 1rem;">
                            <i class="fas fa-info-circle"></i> Request Status Flow
                        </h4>
                        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px;">
                                <div style="background: #856404; color: white; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px;">
                                    1
                                </div>
                                <div>
                                    <div style="font-weight: 600; color: #856404; font-size: 0.85rem;">Pending</div>
                                    <div style="font-size: 0.75rem; color: #856404;">Submitted request</div>
                                </div>
                            </div>
                            
                            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 6px;">
                                <div style="background: #0c5460; color: white; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px;">
                                    2
                                </div>
                                <div>
                                    <div style="font-weight: 600; color: #0c5460; font-size: 0.85rem;">Approved</div>
                                    <div style="font-size: 0.75rem; color: #0c5460;">Validated by Manager</div>
                                </div>
                            </div>
                            
                            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px;">
                                <div style="background: #155724; color: white; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px;">
                                    3
                                </div>
                                <div>
                                    <div style="font-weight: 600; color: #155724; font-size: 0.85rem;">Completed</div>
                                    <div style="font-size: 0.75rem; color: #155724;">Processed and delivered</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="overflow:hidden;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Request #</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Product</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Requested</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Approved</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Status</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">Date</th>
                                </tr>
                            </thead>
                            <tbody id="requests-tbody">
                                <tr>
                                    <td colspan="6" style="padding: 40px; text-align: center; color: #6c757d;">
                                        <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        Loading your requests...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 15px; display: flex; gap: 10px;">
                        <button type="button" class="btn-secondary" onclick="loadStaffRequests()" style="padding: 6px 12px; font-size: 0.85rem;">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    // Initialize cart array
    let cart = [];


// Load merchandise inventory from database
function loadMerchandiseInventory() {
    const tbody = document.getElementById('merchandise-tbody');
    tbody.innerHTML = `
        <tr>
            <td colspan="5" style="padding: 40px; text-align: center; color: #6c757d;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                Loading merchandise inventory...
            </td>
        </tr>
    `;
    
    // Fetch merchandise inventory from database
    fetch('../backend/api/merchandise_transactions.php?action=get_products')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.products) {
                displayMerchandiseInventory(data.products);
            } else {
                displayMerchandiseInventory([]);
            }
        })
        .catch(error => {
            console.error('Error loading merchandise inventory:', error);
            displayMerchandiseInventory([]);
        });
}

function displayMerchandiseInventory(products) {
    const tbody = document.getElementById('merchandise-tbody');
    
    if (!products || products.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" style="padding: 40px; text-align: center; color: #6c757d;">
                    No merchandise inventory available
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = products.map(product => {
        const stock = parseInt(product.stock_level ?? 0);
        const status = stock > 10 ? 'Available' : (stock > 5 ? 'Low Stock' : 'Critical');
        const statusColor = stock > 10 ? '#28a745' : (stock > 5 ? '#ffc107' : '#dc3545');
        
        return `
            <tr style="border-bottom: 1px solid #e9ecef;">
                <td style="padding: 12px; font-weight: 600;">${product.product_name}</td>
                <td style="padding: 12px;">
                    <span style="background: #e9ecef; padding: 2px 8px; border-radius: 4px; font-size: 0.85rem;">
                        ${product.category ?? 'General'}
                    </span>
                </td>
                <td style="padding: 12px;">
                    <span style="background: ${statusColor}20; color: ${statusColor}; padding: 4px 8px; border-radius: 4px; font-weight: 600;">
                        ${stock}
                    </span>
                </td>
                <td style="padding: 12px;">₱${parseFloat(product.unit_cost ?? 0).toFixed(2)}</td>
                <td style="padding: 12px;">
                    <span style="background: ${statusColor}20; color: ${statusColor}; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 0.85rem;">
                        ${status}
                    </span>
                </td>
            </tr>
        `;
    }).join('');
}

// Staff Request functions (from previous implementation)
function loadStaffRequests() {
    const tbody = document.getElementById('requests-tbody');
    tbody.innerHTML = `
        <tr>
            <td colspan="6" style="padding: 40px; text-align: center; color: #6c757d;">
                <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                Loading your requests...
            </td>
        </tr>
    `;
    
    // Fetch staff requests from database
    fetch('../backend/api/staff_requests.php?action=get_my_requests')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayStaffRequests(data.requests);
            } else {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="padding: 40px; text-align: center; color: #6c757d;">
                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                            No stock requests found. Submit a merchandise request to see your requests here.
                        </td>
                    </tr>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading staff requests:', error);
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" style="padding: 40px; text-align: center; color: #6c757d;">
                        <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                        No stock requests found. Submit a merchandise request to see your requests here.
                    </td>
                </tr>
            `;
        });
}

function displayStaffRequests(requests) {
    const tbody = document.getElementById('requests-tbody');
    
    if (!requests || requests.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="padding: 40px; text-align: center; color: #6c757d;">
                    <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                    No stock requests found. Submit a merchandise request to see your requests here.
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = requests.map(req => {
        const statusBadge = getStatusBadge(req.status);
        const approvedQty = req.approved_quantity ? req.approved_quantity : '-';
        const date = new Date(req.created_at).toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric', 
            year: 'numeric' 
        });
        
        return `
            <tr style="border-bottom: 1px solid #e9ecef;">
                <td style="padding: 12px; font-weight: 600; color: #003d7a;">#${req.id}</td>
                <td style="padding: 12px;">${req.item_name}</td>
                <td style="padding: 12px; font-weight: 600;">${req.requested_quantity}</td>
                <td style="padding: 12px; font-weight: 600; color: ${req.approved_quantity ? '#28a745' : '#6c757d';}">
                    ${approvedQty}
                </td>
                <td style="padding: 12px;">${statusBadge}</td>
                <td style="padding: 12px; font-size: 0.85rem; color: #666;">${date}</td>
            </tr>
        `;
    }).join('');
}

function getStatusBadge(status) {
    const statusConfig = {
        'Pending': { color: '#ffc107', bg: '#fff3cd' },
        'Approved': { color: '#17a2b8', bg: '#d1ecf1' },
        'Completed': { color: '#28a745', bg: '#d4edda' },
        'Rejected': { color: '#dc3545', bg: '#f8d7da' }
    };
    
    const config = statusConfig[status] || statusConfig['Pending'];
    return `<span style="background: ${config.bg}; color: ${config.color}; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 0.85rem;">${status}</span>`;
}

// Initialize page load
document.addEventListener('DOMContentLoaded', function() {
    loadStaffRequests();
    loadMerchandiseInventory();
    
    // Update timestamp
    const timestampInput = document.getElementById('transaction_timestamp');
    if (timestampInput) {
        timestampInput.value = new Date().toISOString().slice(0, 19).replace('T', ' ');
    }
});

// Payment field toggling function
function toggleCashFields() {
    const paymentMethod = document.getElementById('payment_method_merch').value;
    const cashFields = document.getElementById('cash_payment_fields');
    const cardFields = document.getElementById('card_payment_fields');
    const ewalletFields = document.getElementById('ewallet_payment_fields');
    const efuelFields = document.getElementById('efuel_payment_fields');
    const fleetFields = document.getElementById('fleet_payment_fields');
    
    const pvcFields = document.getElementById('pvc_payment_fields');
    if (cashFields) cashFields.style.display = 'none';
    if (cardFields) cardFields.style.display = 'none';
    if (ewalletFields) ewalletFields.style.display = 'none';
    if (efuelFields) efuelFields.style.display = 'none';
    if (fleetFields) fleetFields.style.display = 'none';
    if (pvcFields) pvcFields.style.display = 'none';
    
    // Show relevant fields based on payment method
    switch(paymentMethod) {
        case 'Cash':
            if (cashFields) cashFields.style.display = 'flex';
            break;
        case 'Card':
            if (cardFields) cardFields.style.display = 'flex';
            break;
        case 'E-Wallet':
            if (ewalletFields) ewalletFields.style.display = 'flex';
            break;
        case 'Petron Value Card (PVC Points)':
            if (pvcFields) pvcFields.style.display = 'flex';
            break;
        case 'Petron E-Fuel':
            if (efuelFields) efuelFields.style.display = 'flex';
            break;
    }
}

// Compute change function
function computeChange() {
    const amountTendered = parseFloat(document.getElementById('amount_tendered_merch').value) || 0;
    const grandTotal = getGrandTotal();
    const change = amountTendered - grandTotal;
    
    document.getElementById('change_amount_merch').value = change.toFixed(2);
    updateCartDisplay();
}

// Cart functions
function addToCart() {
    const itemName = document.getElementById('item_name_search').value;
    const quantity = parseInt(document.getElementById('quantity').value) || 1;
    const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
    const productId = document.getElementById('item_sku').value;
    const category = document.getElementById('category').value;
    const sizeVariant = document.getElementById('size_variant').value;
    
    if (!itemName || quantity <= 0 || unitPrice <= 0) {
        alert('Please fill in all required fields correctly');
        return;
    }
    
    if (!productId) {
        alert('Please select a product from the dropdown');
        return;
    }
    
    const item = {
        id: Date.now(),
        productId: parseInt(productId),
        name: itemName,
        category: category,
        size: sizeVariant,
        quantity: quantity,
        unitPrice: unitPrice,
        total: quantity * unitPrice
    };
    
    cart.push(item);
    updateCartDisplay();
    clearForm();
}

function clearCart() {
    cart = [];
    updateCartDisplay();
}

function updateCartDisplay() {
    const cartItems = document.getElementById('cart-items');
    const cartCount = document.getElementById('cart-count');
    const cartSummary = document.getElementById('cart-summary');
    const cartActions = document.getElementById('cart-actions');
    const grandTotal = document.getElementById('grand_total');
    
    cartCount.textContent = cart.length;
    
    if (cart.length === 0) {
        cartItems.innerHTML = `
            <div style="text-align: center; color: #666; padding: 30px;">
                <i class="fas fa-shopping-cart" style="font-size: 48px; margin-bottom: 15px; display: block; opacity: 0.3;"></i>
                <p>Your cart is empty</p>
            </div>
        `;
        cartSummary.style.display = 'none';
        cartActions.style.display = 'none';
    } else {
        cartItems.innerHTML = cart.map(item => `
            <div class="cart-item">
                <div class="cart-item-info">
                    <div class="cart-item-name">${item.name}</div>
                    <div class="cart-item-details">
                        ${item.category ? `${item.category} ` : ''}${item.size ? `(${item.size}) ` : ''}| Qty: ${item.quantity} × ₱${item.unitPrice.toFixed(2)}
                    </div>
                </div>
                <div class="cart-item-price">
                    <div class="cart-item-subtotal">₱${item.total.toFixed(2)}</div>
                </div>
                <div class="cart-item-actions">
                    <button class="cart-btn remove" onclick="removeFromCart(${item.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `).join('');
        
        const total = getGrandTotal();
        grandTotal.textContent = `PHP ${total.toFixed(2)}`;
        cartSummary.style.display = 'block';
        cartActions.style.display = 'block';
    }
}

function removeFromCart(itemId) {
    cart = cart.filter(item => item.id !== itemId);
    updateCartDisplay();
}

function getGrandTotal() {
    return cart.reduce((total, item) => total + item.total, 0);
}

function clearForm() {
    document.getElementById('item_name_search').value = '';
    document.getElementById('quantity').value = '1';
    document.getElementById('unit_price').value = '';
    document.getElementById('category').value = '';
    document.getElementById('size_variant').value = '';
}

function printReceipt() {
    if (cart.length === 0) {
        alert('Your cart is empty');
        return;
    }
    
    // Validate payment method
    const paymentMethod = document.getElementById('payment_method_merch').value;
    if (!paymentMethod) {
        alert('Please select a payment method');
        return;
    }
    
    // Validate customer name
    const customerName = document.getElementById('customer_name_merch').value;
    if (!customerName.trim()) {
        alert('Please enter customer name');
        return;
    }
    
    // Get payment details based on method
    let paymentData = {
        payment_method: paymentMethod,
        customer_name: customerName,
        remarks: document.getElementById('remarks').value || ''
    };
    
    // Add payment-specific details
    if (paymentMethod === 'Cash') {
        const amountTendered = parseFloat(document.getElementById('amount_tendered_merch').value) || 0;
        if (amountTendered <= 0) {
            alert('Please enter amount tendered');
            return;
        }
        paymentData.amount_tendered = amountTendered;
        paymentData.change_amount = amountTendered - getGrandTotal();
    } else if (paymentMethod === 'Card') {
        const cardReference = document.getElementById('card_reference_merch').value;
        const cardType = document.getElementById('card_type_merch').value;
        paymentData.card_reference = cardReference;
        paymentData.card_type = cardType;
        paymentData.amount_tendered = getGrandTotal();
    } else if (paymentMethod === 'E-Wallet') {
        const ewalletReference = document.getElementById('ewallet_reference_merch').value;
        const ewalletProvider = document.getElementById('ewallet_provider_merch').value || 'GCash';
        if (!ewalletReference) {
            alert('Please enter e-wallet reference number');
            return;
        }
        paymentData.ewallet_reference = ewalletReference;
        paymentData.ewallet_provider = ewalletProvider;
        paymentData.amount_tendered = getGrandTotal();
    } else if (paymentMethod === 'E-Fuel Card' || paymentMethod === 'Petron E-Fuel') {
        const efuelCardNumber = document.getElementById('efuel_card_number_merch').value;
        if (!efuelCardNumber) {
            alert('Please enter e-fuel card number');
            return;
        }
        paymentData.efuel_card_number = efuelCardNumber;
        paymentData.amount_tendered = getGrandTotal();
    } else if (paymentMethod === 'Credit Account' || paymentMethod === 'Credit' || paymentMethod === 'Credit (Utang)') {
        const creditCustomerId = document.getElementById('credit_customer_id_merch').value;
        if (!creditCustomerId) {
            alert('Please enter credit customer ID');
            return;
        }
        paymentData.credit_customer_id = creditCustomerId;
        paymentData.amount_tendered = getGrandTotal();
    } else if (paymentMethod === 'Petron Loyalty Points') {
        paymentData.amount_tendered = getGrandTotal();
    }
    
    // Get shift info
    const shiftId = document.querySelector('input[name="shift_id"]').value;
    if (shiftId) {
        paymentData.shift_id = shiftId;
    }
    
    // Prepare transaction data
    const transactionData = {
        action: 'create_merchandise_transaction',
        items: cart.map(item => ({
            product_id: item.productId || 0, // Will be updated when product selection is fixed
            product_name: item.name,
            category: item.category || '',
            size_variant: item.size || '',
            quantity: item.quantity,
            unit_price: item.unitPrice
        })),
        ...paymentData
    };
    
    // Send transaction to server
    fetch('../backend/api/merchandise_transactions.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(transactionData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(`Transaction processed successfully!\nTransaction ID: ${data.transaction_id}\nStatus: Pending Validation`);
            const txnId = data.transaction_id;
            clearCart();
            clearForm();
            if (confirm('Would you like to print a receipt?')) {
                const receiptUrl = `receipt.php?id=${encodeURIComponent(txnId)}&type=merchandise`;
                window.open(receiptUrl, '_blank');
            }
            // Reload the page to refresh shift history
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            alert('Error processing transaction: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error processing transaction. Please try again.');
    });
}

// Searchable dropdown functionality
function initSearchableDropdown() {
    const searchInput = document.getElementById('item_name_search');
    const suggestionsDiv = document.getElementById('item_suggestions');
    
    if (!searchInput || !suggestionsDiv) return;
    
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        
        if (query.length < 2) {
            suggestionsDiv.style.display = 'none';
            return;
        }
        
        const filtered = merchandiseProducts.filter(product => 
            product.clean_name.includes(query)
        );
        
        if (filtered.length > 0) {
            suggestionsDiv.innerHTML = filtered.map(product => `
                <div style="padding: 10px; cursor: pointer; border-bottom: 1px solid #e9ecef;" 
                     onclick="selectProduct(${product.product_id})">
                    <div style="font-weight: 600;">${product.product_name}</div>
                    <div style="font-size: 0.85rem; color: #666;">
                        ${product.category} ${product.size ? `- ${product.size}` : ''}
                    </div>
                </div>
            `).join('');
            suggestionsDiv.style.display = 'block';
        } else {
            suggestionsDiv.style.display = 'none';
        }
    });
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
            suggestionsDiv.style.display = 'none';
        }
    });
}

function selectProduct(productId) {
    const product = merchandiseProducts.find(p => p.product_id === productId);
    if (!product) return;
    
    document.getElementById('item_name_search').value = product.product_name;
    document.getElementById('item_sku').value = product.product_id; // Store actual product_id
    document.getElementById('category').value = product.category;
    document.getElementById('size_variant').value = product.size || '';
    document.getElementById('unit_price').value = product.unit_price.toFixed(2);
    
    document.getElementById('item_suggestions').style.display = 'none';
}

// Initialize searchable dropdown on page load
document.addEventListener('DOMContentLoaded', function() {
    initSearchableDropdown();
});

</script>
</div>
</body>
</html><?php include __DIR__ . '/../partials/footer.php'; ?>
