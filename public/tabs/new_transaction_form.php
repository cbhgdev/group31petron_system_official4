<?php
/**
 * New Transaction Form - Unified Merchandise/Service
 * Two-column layout: Form (left) + Cart/Payment (right)
 */
?>

<style>
.transaction-layout {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 2rem;
}

.form-column {
    background: white;
    padding: 2rem;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.cart-column {
    background: white;
    padding: 2rem;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    position: sticky;
    top: 2rem;
    height: fit-content;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #111827;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e5e7eb;
}

.input-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.input-group {
    display: flex;
    flex-direction: column;
}

.input-group label {
    font-weight: 600;
    font-size: 0.875rem;
    color: #374151;
    margin-bottom: 0.5rem;
}

.input-group input,
.input-group select {
    padding: 0.625rem;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    font-size: 0.875rem;
}

.input-group input:focus,
.input-group select:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.cart-item {
    background: #f9fafb;
    padding: 1rem;
    border-radius: 0.375rem;
    margin-bottom: 0.75rem;
    border-left: 3px solid #2563eb;
}

.cart-item.service-item {
    border-left-color: #9333ea;
}

.cart-item-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 0.5rem;
}

.cart-item-name {
    font-weight: 600;
    color: #111827;
}

.cart-item-badge {
    font-size: 0.625rem;
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    background: #dbeafe;
    color: #1e40af;
}

.cart-item-badge.service {
    background: #f3e8ff;
    color: #6b21a8;
}

.cart-item-details {
    font-size: 0.75rem;
    color: #6b7280;
    margin-bottom: 0.5rem;
}

.cart-item-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.cart-item-price {
    font-weight: 700;
    color: #111827;
}

.cart-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: #9ca3af;
}

.payment-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    font-size: 0.875rem;
}

.payment-row.total {
    border-top: 2px solid #e5e7eb;
    padding-top: 1rem;
    margin-top: 0.5rem;
    font-size: 1.125rem;
    font-weight: 700;
}

.btn-add {
    background: #2563eb;
    color: white;
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: 0.375rem;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
}

.btn-add:hover {
    background: #1d4ed8;
}

.btn-process {
    background: #16a34a;
    color: white;
    padding: 1rem;
    border: none;
    border-radius: 0.375rem;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    width: 100%;
    margin-top: 1rem;
}

.btn-process:hover {
    background: #15803d;
}

.btn-reset {
    background: #6b7280;
    color: white;
    padding: 0.75rem;
    border: none;
    border-radius: 0.375rem;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    margin-top: 0.5rem;
}

.btn-reset:hover {
    background: #4b5563;
}

.btn-remove {
    background: #dc2626;
    color: white;
    padding: 0.25rem 0.5rem;
    border: none;
    border-radius: 0.25rem;
    font-size: 0.75rem;
    cursor: pointer;
}

.btn-remove:hover {
    background: #b91c1c;
}

@media (max-width: 1024px) {
    .transaction-layout {
        grid-template-columns: 1fr;
    }
    
    .cart-column {
        position: static;
    }
}
</style>

<div class="transaction-layout">
    <!-- LEFT COLUMN: Form -->
    <div class="form-column">
        <form id="transactionForm">
            <!-- Customer Details -->
            <div class="section-title">Customer Details</div>
            
            <div class="input-row">
                <div class="input-group">
                    <label for="first_name">First Name *</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                
                <div class="input-group">
                    <label for="last_name">Last Name *</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
            </div>
            
            <div class="input-row">
                <div class="input-group">
                    <label for="contact_number">Contact Number <span style="color: #9ca3af;">(Optional for walk-in)</span></label>
                    <input type="text" id="contact_number" name="contact_number" placeholder="09XX-XXX-XXXX">
                </div>
            </div>
            
            <!-- Vehicle Details -->
            <div class="section-title" style="margin-top: 2rem;">Vehicle Details <span style="color: #9ca3af; font-size: 0.875rem; font-weight: 400;">(For job orders)</span></div>
            
            <div class="input-row">
                <div class="input-group">
                    <label for="vehicle_type">Vehicle Type</label>
                    <input type="text" id="vehicle_type" name="vehicle_type" placeholder="Sedan, SUV, Motorcycle, Truck, etc.">
                </div>
                
                <div class="input-group">
                    <label for="vehicle_plate">Plate Number</label>
                    <input type="text" id="vehicle_plate" name="vehicle_plate" placeholder="ABC-1234">
                </div>
            </div>
            
            <div class="input-row">
                <div class="input-group">
                    <label for="vehicle_brand">Vehicle Brand <span style="color: #9ca3af;">(Recommended)</span></label>
                    <input type="text" id="vehicle_brand" name="vehicle_brand" placeholder="Toyota, Honda, Mitsubishi, etc.">
                </div>
                
                <div class="input-group">
                    <label for="vehicle_model">Vehicle Model <span style="color: #9ca3af;">(Recommended)</span></label>
                    <input type="text" id="vehicle_model" name="vehicle_model" placeholder="Vios, Civic, Montero, etc.">
                </div>
            </div>

            <div class="input-row">
                <div class="input-group">
                    <label for="engine_number">Engine Number <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="engine_number" name="engine_number" placeholder="e.g. 1NZ-FE-1234567" style="text-transform:uppercase;">
                </div>
                
                <div class="input-group">
                    <label for="chassis_number">Chassis Number (VIN) <span style="color: #dc2626;">*</span></label>
                    <input type="text" id="chassis_number" name="chassis_number" placeholder="e.g. MHFXE1234567890" style="text-transform:uppercase;">
                </div>
            </div>

            
            <!-- Service Details -->
            <div class="section-title" style="margin-top: 2rem;">Service Details <span style="color: #9ca3af; font-size: 0.875rem; font-weight: 400;">(For job orders)</span></div>
            
            <div class="input-row">
                <div class="input-group">
                    <label for="service_category">Service Category <span style="color: #9ca3af;">(Recommended)</span></label>
                    <select id="service_category" name="service_category">
                        <option value="">-- Select Category --</option>
                        <option value="Lubrication">Lubrication</option>
                        <option value="PMS">PMS</option>
                        <option value="Engine">Engine</option>
                        <option value="Fuel System">Fuel System</option>
                        <option value="Cooling System">Cooling System</option>
                        <option value="Transmission">Transmission</option>
                        <option value="Brake">Brake</option>
                        <option value="Suspension">Suspension</option>
                        <option value="Steering">Steering</option>
                        <option value="Tire Services">Tire Services</option>
                        <option value="Battery Services">Battery Services</option>
                        <option value="Electrical">Electrical</option>
                        <option value="Air Conditioning">Air Conditioning</option>
                        <option value="Diagnostics">Diagnostics</option>
                        <option value="Inspection">Inspection</option>
                        <option value="Others">Others</option>
                    </select>
                </div>
                
                <div class="input-group">
                    <label for="service_type">Service Type</label>
                    <select id="service_type" name="service_type">
                        <option value="">-- No Service --</option>
                        <?php foreach ($service_types as $service): ?>
                            <option value="<?= htmlspecialchars($service) ?>">
                                <?= htmlspecialchars($service) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="input-row">
                <div class="input-group">
                    <label for="mechanic_name">Assigned Mechanic</label>
                    <input type="text" id="mechanic_name" name="mechanic_name" placeholder="Enter mechanic name">
                </div>
                
                <div class="input-group">
                    <label for="service_fee">Service Fee (₱)</label>
                    <input type="number" id="service_fee" name="service_fee" min="0" step="0.01" value="0" placeholder="0.00">
                </div>
            </div>
            
            <div class="input-row">
                <div class="input-group" style="grid-column: 1 / -1;">
                    <label for="service_notes">Notes</label>
                    <textarea id="service_notes" name="service_notes" rows="3" 
                              placeholder="Additional notes or service description..."
                              style="padding: 0.625rem; border: 1px solid #d1d5db; border-radius: 0.375rem; font-size: 0.875rem; resize: vertical;"></textarea>
                </div>
            </div>
            
            <div style="display: flex; gap: 0.5rem; margin-bottom: 2rem;">
                <button type="button" class="btn-add" onclick="addServiceToCart()">
                    Add Service to Cart
                </button>
                <button type="button" class="btn-reset" onclick="resetServiceFields()">
                    Reset
                </button>
            </div>
            
            <!-- Merchandise Details -->
            <div class="section-title" style="margin-top: 2rem;">Merchandise Details (Optional)</div>
            
            <div class="input-row">
                <div class="input-group">
                    <label for="product_select">Product</label>
                    <select id="product_select" onchange="autofillProductDetails()">
                        <option value="">-- Select Product --</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= $product['product_id'] ?>" 
                                    data-name="<?= htmlspecialchars($product['product_name']) ?>"
                                    data-sku="<?= htmlspecialchars($product['sku']) ?>"
                                    data-category="<?= htmlspecialchars($product['category']) ?>"
                                    data-price="<?= $product['unit_price'] ?>"
                                    data-stock="<?= $product['stock_level'] ?>">
                                <?= htmlspecialchars($product['product_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="input-group">
                    <label for="product_category">Category</label>
                    <input type="text" id="product_category" readonly style="background: #f3f4f6;">
                </div>
            </div>
            
            <div class="input-row">
                <div class="input-group">
                    <label for="product_quantity">Quantity</label>
                    <input type="number" id="product_quantity" min="1" value="1">
                </div>
                
                <div class="input-group">
                    <label for="product_price">Unit Price (₱)</label>
                    <input type="number" id="product_price" step="0.01" readonly style="background: #f3f4f6;">
                </div>
                
                <div class="input-group">
                    <label for="product_stock">Stock Available</label>
                    <input type="number" id="product_stock" readonly style="background: #f3f4f6;">
                </div>
            </div>
            
            <div style="display: flex; gap: 0.5rem;">
                <button type="button" class="btn-add" onclick="addProductToCart()">
                    Add to Cart
                </button>
                <button type="button" class="btn-reset" onclick="resetProductFields()">
                    Reset
                </button>
            </div>
            
            <!-- Hidden fields -->
            <input type="hidden" name="cart_items" id="cart_items" value="[]">
            <input type="hidden" name="shift_period" value="<?= htmlspecialchars($shift_period) ?>">
            <input type="hidden" name="shift_name" value="<?= htmlspecialchars($shift_name) ?>">
        </form>
    </div>
    
    <!-- RIGHT COLUMN: Cart & Payment -->
    <div class="cart-column">
        <div class="section-title">Cart</div>
        
        <div id="cartContainer">
            <div class="cart-empty">
                Cart is empty<br>
                <span style="font-size: 0.75rem;">Add services or products to get started</span>
            </div>
        </div>
        
        <!-- Payment Panel -->
        <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 2px solid #e5e7eb;">
            <div class="section-title" style="margin-bottom: 1rem;">Payment</div>
            
            <div class="input-group" style="margin-bottom: 1rem;">
                <label for="payment_method">Payment Method</label>
                <select id="payment_method" name="payment_method" onchange="toggleUnifiedPaymentFields()">
                    <option value="Cash">Cash</option>
                    <option value="Card">Card</option>
                    <option value="E-Wallet">E-Wallet</option>
                    <option value="Petron Value Card (PVC Points)">Petron Value Card (PVC Points)</option>
                    <option value="Credit Account">Credit Account</option>
                </select>
            </div>

            <!-- E-Wallet Provider and Reference -->
            <div id="unified_ewallet_fields" style="display: none; margin-bottom: 1rem; padding: 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px;">
                <label for="unified_ewallet_provider" style="font-weight: 600; color: #166534; display: block; margin-bottom: 4px; font-size: 0.85rem;">E-Wallet Provider</label>
                <select id="unified_ewallet_provider" name="ewallet_provider" style="width: 100%; padding: 8px; margin-bottom: 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                    <option value="GCash">GCash</option>
                    <option value="Maya">Maya</option>
                </select>
                <label for="unified_ewallet_ref" style="font-weight: 600; color: #166534; display: block; margin-bottom: 4px; font-size: 0.85rem;">Reference No.</label>
                <input type="text" id="unified_ewallet_ref" name="ewallet_reference" placeholder="e.g. GC123456789 or MY123456789" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box;">
            </div>

            <!-- PVC Points Field -->
            <div id="unified_pvc_fields" style="display: none; margin-bottom: 1rem; padding: 10px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px;">
                <label style="font-weight: 600; color: #1e40af; display: block; margin-bottom: 4px; font-size: 0.85rem;"><i class="fas fa-id-card"></i> Petron Value Card (PVC Points)</label>
                <small style="color: #1e40af; display:block;">PVC points redemption will be automatically applied to this transaction.</small>
            </div>
            
            <div class="payment-row">
                <span>Subtotal:</span>
                <span id="subtotal">₱0.00</span>
            </div>
            
            <div class="payment-row">
                <span>VAT (12%):</span>
                <span id="vat">₱0.00</span>
            </div>
            
            <div class="payment-row total">
                <span>Grand Total:</span>
                <span id="grandTotal">₱0.00</span>
            </div>
            
            <button type="button" class="btn-process" onclick="processTransaction()">
                Process & Print Receipt
            </button>
            
            <button type="button" class="btn-reset" onclick="resetAll()">
                Reset All
            </button>
        </div>
    </div>
</div>

<script>
let cart = [];

// Auto-fill product details when product is selected
function autofillProductDetails() {
    const productSelect = document.getElementById('product_select');
    const selectedOption = productSelect.options[productSelect.selectedIndex];
    
    if (!selectedOption.value) {
        document.getElementById('product_category').value = '';
        document.getElementById('product_price').value = '';
        document.getElementById('product_stock').value = '';
        return;
    }
    
    document.getElementById('product_category').value = selectedOption.dataset.category || '';
    document.getElementById('product_price').value = selectedOption.dataset.price || '0';
    document.getElementById('product_stock').value = selectedOption.dataset.stock || '0';
}

// Add service to cart
function addServiceToCart() {
    const serviceType = document.getElementById('service_type').value;
    const serviceFee = parseFloat(document.getElementById('service_fee').value) || 0;
    
    if (!serviceType || serviceFee <= 0) {
        alert('Please select a service type and enter a valid service fee');
        return;
    }
    
    // Check if service already in cart
    const existingServiceIndex = cart.findIndex(item => item.item_type === 'service');
    
    if (existingServiceIndex >= 0) {
        // Update existing service
        if (confirm('A service is already in cart. Replace it?')) {
            cart[existingServiceIndex] = {
                item_type: 'service',
                product_id: 0,
                product_name: serviceType,
                sku: 'SERVICE',
                category: document.getElementById('service_category').value || 'Service',
                quantity: 1,
                unit_price: serviceFee,
                vehicle_type: document.getElementById('vehicle_type').value,
                vehicle_plate: document.getElementById('vehicle_plate').value,
                vehicle_brand: document.getElementById('vehicle_brand').value,
                vehicle_model: document.getElementById('vehicle_model').value,
                mechanic: document.getElementById('mechanic_name').value,
                notes: document.getElementById('service_notes').value
            };
        } else {
            return;
        }
    } else {
        // Add new service
        cart.push({
            item_type: 'service',
            product_id: 0,
            product_name: serviceType,
            sku: 'SERVICE',
            category: document.getElementById('service_category').value || 'Service',
            quantity: 1,
            unit_price: serviceFee,
            vehicle_type: document.getElementById('vehicle_type').value,
            vehicle_plate: document.getElementById('vehicle_plate').value,
            vehicle_brand: document.getElementById('vehicle_brand').value,
            vehicle_model: document.getElementById('vehicle_model').value,
            mechanic: document.getElementById('mechanic_name').value,
            notes: document.getElementById('service_notes').value
        });
    }
    
    updateCartDisplay();
    alert('Service added to cart');
}

// Reset service fields
function resetServiceFields() {
    document.getElementById('service_category').selectedIndex = 0;
    document.getElementById('service_type').selectedIndex = 0;
    document.getElementById('service_fee').value = '0';
    document.getElementById('vehicle_type').value = '';
    document.getElementById('vehicle_plate').value = '';
    document.getElementById('vehicle_brand').value = '';
    document.getElementById('vehicle_model').value = '';
    document.getElementById('mechanic_name').value = '';
    document.getElementById('service_notes').value = '';
}

// Add product to cart
function addProductToCart() {
    const productSelect = document.getElementById('product_select');
    const quantityInput = document.getElementById('product_quantity');
    
    if (!productSelect.value) {
        alert('Please select a product');
        return;
    }
    
    const selectedOption = productSelect.options[productSelect.selectedIndex];
    const quantity = parseInt(quantityInput.value) || 1;
    const stock = parseInt(selectedOption.dataset.stock) || 0;
    
    if (quantity > stock) {
        alert(`Insufficient stock. Available: ${stock}`);
        return;
    }
    
    if (quantity <= 0) {
        alert('Quantity must be greater than 0');
        return;
    }
    
    const item = {
        item_type: 'merchandise',
        product_id: selectedOption.value,
        product_name: selectedOption.dataset.name,
        sku: selectedOption.dataset.sku,
        category: selectedOption.dataset.category,
        quantity: quantity,
        unit_price: parseFloat(selectedOption.dataset.price) || 0
    };
    
    // Check if item already exists in cart
    const existingIndex = cart.findIndex(i => i.product_id === item.product_id && i.item_type === 'merchandise');
    if (existingIndex >= 0) {
        cart[existingIndex].quantity += quantity;
    } else {
        cart.push(item);
    }
    
    updateCartDisplay();
    resetProductFields();
}

// Remove item from cart
function removeFromCart(index) {
    cart.splice(index, 1);
    updateCartDisplay();
}

// Reset product fields
function resetProductFields() {
    document.getElementById('product_select').selectedIndex = 0;
    document.getElementById('product_category').value = '';
    document.getElementById('product_price').value = '';
    document.getElementById('product_stock').value = '';
    document.getElementById('product_quantity').value = '1';
}

// Reset all form fields and cart
function resetAll() {
    if (!confirm('Reset all fields and clear cart?')) {
        return;
    }
    
    document.getElementById('transactionForm').reset();
    cart = [];
    updateCartDisplay();
    resetProductFields();
}

// Update cart display
function updateCartDisplay() {
    const cartContainer = document.getElementById('cartContainer');
    const cartItemsInput = document.getElementById('cart_items');
    const subtotalEl = document.getElementById('subtotal');
    const vatEl = document.getElementById('vat');
    const grandTotalEl = document.getElementById('grandTotal');
    
    // Update hidden input
    cartItemsInput.value = JSON.stringify(cart);
    
    // Calculate totals
    let subtotal = 0;
    cart.forEach(item => {
        subtotal += item.quantity * item.unit_price;
    });
    
    const vat = subtotal * 0.12;
    const grandTotal = subtotal + vat;
    
    // Update displays
    subtotalEl.textContent = '₱' + subtotal.toFixed(2);
    vatEl.textContent = '₱' + vat.toFixed(2);
    grandTotalEl.textContent = '₱' + grandTotal.toFixed(2);
    
    // Update cart display
    if (cart.length === 0) {
        cartContainer.innerHTML = `
            <div class="cart-empty">
                Cart is empty<br>
                <span style="font-size: 0.75rem;">Add services or products to get started</span>
            </div>
        `;
    } else {
        let html = '';
        cart.forEach((item, index) => {
            const itemTotal = item.quantity * item.unit_price;
            const isService = item.item_type === 'service';
            
            // Build details text
            let detailsText = '';
            if (isService) {
                const vehicleInfo = [];
                if (item.vehicle_plate) vehicleInfo.push(item.vehicle_plate);
                if (item.vehicle_brand) vehicleInfo.push(item.vehicle_brand);
                if (item.vehicle_model) vehicleInfo.push(item.vehicle_model);
                if (item.vehicle_type) vehicleInfo.push(item.vehicle_type);
                
                detailsText = vehicleInfo.length > 0 ? vehicleInfo.join(' • ') : 'Service Fee';
                
                if (item.mechanic) {
                    detailsText += `<br><small>Mechanic: ${item.mechanic}</small>`;
                }
            } else {
                detailsText = `${item.category} • SKU: ${item.sku}`;
            }
            
            html += `
                <div class="cart-item ${isService ? 'service-item' : ''}">
                    <div class="cart-item-header">
                        <span class="cart-item-name">${item.product_name}</span>
                        <span class="cart-item-badge ${isService ? 'service' : ''}">
                            ${isService ? 'SERVICE' : 'PRODUCT'}
                        </span>
                    </div>
                    <div class="cart-item-details">
                        ${detailsText}
                    </div>
                    <div class="cart-item-details">
                        Quantity: ${item.quantity} × ₱${item.unit_price.toFixed(2)}
                    </div>
                    <div class="cart-item-footer">
                        <span class="cart-item-price">₱${itemTotal.toFixed(2)}</span>
                        <button class="btn-remove" onclick="removeFromCart(${index})">Remove</button>
                    </div>
                </div>
            `;
        });
        cartContainer.innerHTML = html;
    }
}

// Process transaction
async function processTransaction() {
    // Validation
    const firstName = document.getElementById('first_name').value.trim();
    const lastName = document.getElementById('last_name').value.trim();
    
    if (!firstName || !lastName) {
        alert('Please enter customer first and last name');
        return;
    }
    
    if (cart.length === 0) {
        alert('Please add at least one service or product to the cart');
        return;
    }
    
    const paymentMethod = document.getElementById('payment_method').value;
    
    if (!confirm(`Process transaction for ${firstName} ${lastName}?\nPayment: ${paymentMethod}\nTotal: ${document.getElementById('grandTotal').textContent}`)) {
        return;
    }
    
    // Prepare form data
    const formData = new FormData();
    formData.append('first_name', firstName);
    formData.append('last_name', lastName);
    formData.append('customer_name', `${firstName} ${lastName}`);
    formData.append('customer_contact', document.getElementById('contact_number').value);
    formData.append('payment_method', paymentMethod);
    if (paymentMethod === 'E-Wallet') {
        const ewProv = document.getElementById('unified_ewallet_provider') ? document.getElementById('unified_ewallet_provider').value : 'GCash';
        const ewRef = document.getElementById('unified_ewallet_ref') ? document.getElementById('unified_ewallet_ref').value : '';
        formData.append('ewallet_provider', ewProv);
        formData.append('ewallet_reference', ewRef);
    }
    
    // Service info
    const serviceInCart = cart.find(item => item.item_type === 'service');
    if (serviceInCart) {
        formData.append('service_type', serviceInCart.product_name);
        formData.append('service_fee', serviceInCart.unit_price);
        formData.append('vehicle_plate', document.getElementById('vehicle_plate').value);
        formData.append('vehicle_type', document.getElementById('vehicle_type').value);
        formData.append('mechanic_name', document.getElementById('mechanic_name').value);
    } else {
        formData.append('service_type', '');
        formData.append('service_fee', '0');
    }
    
    // Cart items
    formData.append('cart_items', JSON.stringify(cart));
    
    // Shift info
    formData.append('shift_period', document.querySelector('input[name="shift_period"]').value);
    formData.append('shift_name', document.querySelector('input[name="shift_name"]').value);
    
    // Submit
    try {
        const response = await fetch('../backend/save_unified_transaction.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(`Transaction saved successfully!\nTransaction ID: ${result.transaction_id}\n\nReceipt will be displayed...`);
            
            // Reset form and cart
            resetAll();
            
            // Optionally redirect to receipt
            // window.open(`../backend/print_receipt.php?id=${result.transaction_db_id}`, '_blank');
        } else {
            alert('Error: ' + (result.error || 'Unknown error occurred'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Network error. Please try again.');
    }
}

function toggleUnifiedPaymentFields() {
    const pm = document.getElementById('payment_method') ? document.getElementById('payment_method').value : '';
    const ewDiv = document.getElementById('unified_ewallet_fields');
    const pvcDiv = document.getElementById('unified_pvc_fields');
    if (ewDiv) ewDiv.style.display = (pm === 'E-Wallet') ? 'block' : 'none';
    if (pvcDiv) pvcDiv.style.display = (pm === 'Petron Value Card (PVC Points)') ? 'block' : 'none';
}

// Initialize
updateCartDisplay();
</script>
