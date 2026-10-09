<?php
require_once __DIR__ . '/../backend/lib.php';
petron_init_dynamic_timezone();
require_once __DIR__ . '/db_connect.php';

$id   = trim($_GET['id']   ?? '');
$type = trim($_GET['type'] ?? 'merchandise');

$txn   = null;
$items = [];
$error = '';

if ($id === '') {
    $error = 'No transaction ID provided.';
} else {
    try {
        // First: Try merchandise_transactions table (handles merchandise, job_order, and combined types)
        $stmt = $pdo->prepare("
            SELECT mt.*,
                   COALESCE(u.username, 'Staff') AS staff_name,
                   COALESCE(s.name, 'Petron Station') AS station_name,
                   COALESCE(s.location, '') AS station_location,
                   COALESCE(s.address, 'Vamenta Blvd., Carmen, CDO') AS station_address,
                   COALESCE(s.vat_tin, '236-002-207-0000') AS station_vat_tin
            FROM   merchandise_transactions mt
            LEFT JOIN users    u ON mt.staff_id   = u.id
            LEFT JOIN stations s ON mt.station_id = s.id
            WHERE  mt.transaction_id = ? OR mt.id = ?
            LIMIT  1
        ");
        $numeric_id = is_numeric($id) ? (int)$id : 0;
        // Handle IDs that start with '#' (e.g., '#123' from job orders)
        if ($numeric_id === 0 && str_starts_with($id, '#')) {
            $clean_id = ltrim($id, '#');
            if (is_numeric($clean_id)) {
                $numeric_id = (int)$clean_id;
            }
        }
        $stmt->execute([$id, $numeric_id]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($txn) {
            $stmt2 = $pdo->prepare("
                SELECT product_name, category, size_variant, quantity, unit_price, subtotal,
                       COALESCE(item_type,'merchandise') AS item_type
                FROM   merchandise_transaction_items
                WHERE  transaction_id = ?
                ORDER  BY id ASC
            ");
            $stmt2->execute([$txn['id']]);
            $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } else if ($type === 'job_order' && $numeric_id > 0) {
            // Fallback: Try job_orders table for pure job orders
            // Handle IDs: numeric (123), with hash (#123), or string (JO-123)
            $stmt_jo = $pdo->prepare("
                SELECT jo.*,
                       COALESCE(cb.username, 'Staff') AS staff_name,
                       COALESCE(s.name, 'Petron Station') AS station_name,
                       COALESCE(s.location, '') AS station_location,
                       COALESCE(s.address, 'Vamenta Blvd., Carmen, CDO') AS station_address,
                       COALESCE(s.vat_tin, '236-002-207-0000') AS station_vat_tin
                FROM   job_orders jo
                LEFT JOIN users    cb ON cb.id = jo.created_by
                LEFT JOIN stations s  ON s.id  = jo.station_id
                WHERE  jo.job_order_id = ? OR jo.job_order_number = ? OR jo.id = ?
                LIMIT  1
            ");
            $stmt_jo->execute([$id, $id, $numeric_id]);
            $jo = $stmt_jo->fetch(PDO::FETCH_ASSOC);
            
            if ($jo) {
                // Map job order to transaction format
                $txn = [
                    'id' => $jo['id'],
                    'transaction_id' => $jo['job_order_id'] ?? ('JO-' . $jo['id']),
                    'customer_name' => $jo['customer_name'] ?? 'Walk-in Customer',
                    'customer_first_name' => '',
                    'customer_last_name' => '',
                    'transaction_date' => $jo['order_date'] ?? $jo['created_at'] ?? '',
                    'created_at' => $jo['created_at'] ?? date('Y-m-d H:i:s'),
                    'staff_name' => $jo['staff_name'] ?? 'N/A',
                    'station_name' => $jo['station_name'] ?? 'Petron Station',
                    'station_location' => $jo['station_location'] ?? '',
                    'station_address' => $jo['station_address'] ?? 'Vamenta Blvd., Carmen, CDO',
                    'station_vat_tin' => $jo['station_vat_tin'] ?? '236-002-207-0000',
                    'total_amount' => $jo['total_amount'] ?? $jo['service_cost'] ?? 0,
                    'subtotal_amount' => 0,
                    'vat_amount' => 0,
                    'amount_paid' => $jo['amount_paid'] ?? 0,
                    'balance_due' => $jo['balance_due'] ?? 0,
                    'payment_method' => $jo['payment_method'] ?? 'Cash',
                    'payment_status' => $jo['payment_status'] ?? 'Pending Payment',
                    'validation_status' => $jo['status'] ?? 'Pending',
                    'remarks' => $jo['notes'] ?? $jo['remarks'] ?? '',
                    'transaction_type' => 'job_order',
                    'job_order_service' => $jo['service_type'] ?? 'Service',
                    'job_order_vehicle_plate' => $jo['vehicle_plate'] ?? '',
                    'job_order_mechanic_name' => $jo['mechanic_name'] ?? $jo['assigned_mechanic'] ?? '',
                ];
                
                // Build items array from service
                if (!empty($jo['service_type'])) {
                    $items[] = [
                        'product_name' => $jo['service_type'],
                        'category' => 'Job Order Service',
                        'size_variant' => '',
                        'quantity' => 1,
                        'unit_price' => $txn['total_amount'],
                        'subtotal' => $txn['total_amount'],
                        'item_type' => 'service',
                    ];
                }
            }
        }
        
        if (!$txn) {
            $error = 'Transaction not found.';
        }
    } catch (Exception $e) {
        $error = 'Database error: ' . htmlspecialchars($e->getMessage());
    }
}

// ── Derived values ────────────────────────────────────────────────────────────
if ($txn) {
    $txn_id       = $txn['transaction_id'];
    $ts           = $txn['transaction_date'] ?? $txn['created_at'] ?? '';
    $disp_date    = $ts ? date('F j, Y', strtotime($ts)) : '—';
    $disp_time    = $ts ? date('h:i A', strtotime($ts))  : '—';
    $customer     = trim(($txn['customer_first_name'] ?? '') . ' ' . ($txn['customer_last_name'] ?? ''))
                    ?: ($txn['customer_name'] ?? 'Walk-in Customer');
    $pay_method   = $txn['payment_method'] ?? 'Cash';
    $total        = (float)($txn['total_amount'] ?? 0);
    $amount_paid  = (float)($txn['amount_paid']  ?? $txn['amount_tendered'] ?? 0);
    $tendered     = (float)($txn['amount_tendered'] ?? $txn['amount_received'] ?? $amount_paid);
    $change       = (float)($txn['change_amount']   ?? $txn['change'] ?? 0);
    $balance_due  = (float)($txn['balance_due']  ?? 0);

    // Fix change if tendered > total but change stored as 0
    if ($tendered > $total && $change <= 0) {
        $change = round($tendered - $total, 2);
    }
    if ($tendered <= 0 && strtolower($txn['payment_method'] ?? '') === 'cash') {
        $tendered = $total;
        $change   = 0.00;
    }
    $amount_paid = $amount_paid > 0 ? $amount_paid : $tendered;

    // Station info
    $station = 'PETRON STATION MANAGEMENT SYSTEM';
    $vat_tin = '248-719-305-00000';
    $atp_no  = 'BIR-ATP-2026-00984712';
    $addr    = 'Vamenta Blvd., Carmen, Cagayan de Oro City, Misamis Oriental';

    // Determine normalised payment status
    $stored_ps = strtolower(trim($txn['payment_status'] ?? ''));
    if (in_array($stored_ps, ['partial payment','partial','partially paid'])) {
        $pay_norm = 'partial';
        if ($balance_due <= 0) $balance_due = max(0, $total - $amount_paid);
    } elseif (in_array($stored_ps, ['pending payment','pending'])) {
        $pay_norm = 'pending';
    } elseif (in_array($stored_ps, ['credit transaction','credit','credit account'])) {
        $pay_norm = 'credit';
    } else {
        $pay_norm = 'paid';
    }

    $pay_labels = [
        'paid'    => ['label'=>'PAID',           'bg'=>'#166534','border'=>'#86efac','txt'=>'#fff'],
        'partial' => ['label'=>'PARTIALLY PAID', 'bg'=>'#92400e','border'=>'#fde68a','txt'=>'#fef9c3'],
        'pending' => ['label'=>'PENDING',        'bg'=>'#9a3412','border'=>'#fed7aa','txt'=>'#ffedd5'],
        'credit'  => ['label'=>'CREDIT ACCOUNT', 'bg'=>'#6b21a8','border'=>'#d8b4fe','txt'=>'#f3e8ff'],
    ];
    $ps_cfg = $pay_labels[$pay_norm] ?? $pay_labels['paid'];

    $validation = $txn['validation_status'] ?? 'Pending';
    $printed_at = date('M j, Y h:i A');

    // ── VAT Computation (100% BIR-Accurate, matches receipt.php) ─────────────
    // ALWAYS derive from stored total_amount — never from items_sum
    if (!empty($txn['subtotal_amount']) && (float)$txn['subtotal_amount'] > 0) {
        $subtotal = (float)$txn['subtotal_amount'];
        $vat_amt  = !empty($txn['vat_amount']) ? (float)$txn['vat_amount'] : round($subtotal * 0.12, 2);
    } else {
        $subtotal = $total > 0 ? round($total / 1.12, 2) : 0;
        $vat_amt  = $total > 0 ? round($total - $subtotal, 2) : 0;
    }
    // Ensure Vatable + VAT = Grand Total exactly
    if (round($subtotal + $vat_amt, 2) !== round($total, 2)) {
        $vat_amt = round($total - $subtotal, 2);
    }
}

// Set default $addr if $txn doesn't exist (for error page footer)
if (!isset($addr)) {
    $addr = 'Vamenta Blvd., Carmen, Cagayan de Oro City';
}
if (!isset($printed_at)) {
    $printed_at = date('M j, Y h:i A');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transaction Verification — <?php echo htmlspecialchars($id ?: 'N/A'); ?></title>
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<script src="../assets/js/security_frontend.js?v=2.0.4"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body {
    font-family: 'Inter', sans-serif;
    background: #f1f5f9;
    min-height: 100vh;
    padding: 32px 16px;
    color: #1e293b;
    -webkit-font-smoothing: antialiased;
}

.vcard {
    max-width: 540px;
    margin: 0 auto;
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 0 12px 32px rgba(0, 47, 108, 0.08), 0 2px 8px rgba(0,0,0,0.04);
    overflow: hidden;
    position: relative;
}

/* Header */
.vhdr {
    background: #ffffff;
    padding: 24px 28px;
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    border-bottom: 3px solid #DC0032; /* Petron Red Accent */
}
.vhdr-logo {
    font-size: 20px;
    font-weight: 800;
    color: #002F6C;
    letter-spacing: 0.5px;
    line-height: 1.2;
}
.vhdr-sub {
    font-size: 12px;
    color: #64748b;
    font-weight: 500;
    margin-top: 3px;
    letter-spacing: 0.3px;
}
.vhdr-icon {
    width: 50px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.vhdr-icon img {
    max-width: 100%;
    height: auto;
}

/* Banners */
.v-verified { display: flex; align-items: center; gap: 10px; background: #ecfdf5; padding: 14px 28px; font-size: 13.5px; font-weight: 700; color: #059669; border-bottom: 1px solid #d1fae5; }
.v-error    { display: flex; align-items: center; gap: 10px; background: #fef2f2; padding: 14px 28px; font-size: 13.5px; font-weight: 700; color: #dc2626; border-bottom: 1px solid #fee2e2; }
.v-not-found { padding: 60px 28px; text-align: center; color: #64748b; }

/* Body */
.vbody { padding: 28px; }

/* Status Badges */
.pay-badge {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px;
    border-radius: 24px; font-size: 11.5px; font-weight: 800; letter-spacing: 0.5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.val-badge {
    display: inline-flex; align-items: center; padding: 6px 14px;
    border-radius: 24px; font-size: 11.5px; font-weight: 700;
}

/* Sections */
.vsec { margin-bottom: 24px; background: #f8fafc; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; }
.vsec-transparent { background: transparent; border: none; padding: 0; }
.vsec-title {
    font-size: 11.5px; font-weight: 800; color: #002F6C; text-transform: uppercase;
    letter-spacing: 0.8px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
}
.vsec-title i { color: #64748b; font-size: 13px; }

/* Rows */
.vrow { display: flex; justify-content: space-between; align-items: flex-start; padding: 8px 0; font-size: 13.5px; gap: 16px; border-bottom: 1px dashed #cbd5e1; }
.vrow:last-child { border-bottom: none; }
.vrow-key { color: #64748b; font-size: 13px; font-weight: 500; flex-shrink: 0; width: 35%; }
.vrow-val { font-weight: 700; color: #0f172a; text-align: right; width: 65%; word-break: break-word; overflow-wrap: break-word; }
.vrow-total { font-size: 16px; font-weight: 900; color: #002F6C; padding-top: 4px; }
.vrow-red { color: #dc2626; font-weight: 800; }
.vrow-purple { color: #7e22ce; font-weight: 800; }

/* Items Table */
.vtbl-container { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-top: 8px; width: 100%; overflow:hidden; -webkit-overflow-scrolling: touch; }
.vtbl { width: 100%; border-collapse: collapse; font-size: 13px; background: #ffffff; }
.vtbl thead th { background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 10px 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
.vtbl thead th:nth-child(2) { text-align: center; }
.vtbl thead th:nth-child(3) { text-align: right; }
.vtbl thead th:nth-child(4) { text-align: right; }
.vtbl tbody td { padding: 12px; border-bottom: 1px solid #f1f5f9; color: #334155; font-weight: 500; vertical-align: middle; word-break: break-word; overflow-wrap: break-word; }
.vtbl tbody tr:last-child td { border-bottom: none; }
.vtbl tbody tr:hover td { background: #f8fafc; }

/* Notes */
.vnote { border-radius: 10px; padding: 14px 16px; font-size: 13px; font-weight: 600; margin-bottom: 20px; display: flex; gap: 10px; line-height: 1.5; }
.vnote i { margin-top: 2px; font-size: 15px; flex-shrink: 0; }
.vnote-partial { background: #fefce8; border: 1px solid #fef08a; color: #854d0e; }
.vnote-pending { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; }
.vnote-credit  { background: #faf5ff; border: 1px solid #e9d5ff; color: #6b21a8; }

/* Footer */
.vfoot { background: #f8fafc; border-top: 1px dashed #cbd5e1; padding: 20px 28px; text-align: center; font-size: 12px; color: #64748b; line-height: 1.6; }
.vfoot strong { color: #002F6C; font-weight: 700; }

/* Buttons */
.vactions { display: flex; gap: 10px; justify-content: center; margin-top: 24px; flex-wrap: wrap; }
.btn-vp { padding: 10px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
.btn-vp:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.btn-print-vp { background: #002F6C; color: #fff; }
.btn-close-vp { background: #e2e8f0; color: #475569; }

@media print {
    body { background: #fff; padding: 0; }
    .vcard { box-shadow: none; border: none; max-width: 100%; border-radius: 0; }
    .vhdr { -webkit-print-color-adjust: exact; print-color-adjust: exact; border-bottom: 2px solid #DC0032; }
    .vsec { border: none; padding: 0; background: transparent; margin-bottom: 15px; }
    .no-print { display: none !important; }
}

/* Mobile Responsive */
@media (max-width: 600px) {
    body { padding: 12px 8px; }
    .vcard { border-radius: 12px; }
    .vhdr { padding: 16px; gap: 10px; }
    .vhdr-logo { font-size: 16px; }
    .vhdr-icon { width: 36px; }
    .vbody { padding: 16px; }
    .vsec { padding: 12px; margin-bottom: 16px; }
    .vrow { font-size: 12.5px; padding: 6px 0; gap: 8px; }
    .vrow-key { font-size: 11.5px; width: 40%; }
    .vrow-val { font-size: 12.5px; width: 60%; }
    .vrow-total { font-size: 15px; }
    .pay-badge, .val-badge { font-size: 10.5px; padding: 5px 12px; }
    .vtbl-container { margin-top: 12px; }
    .vtbl thead th { font-size: 10px; padding: 8px; }
    .vtbl tbody td { font-size: 12px; padding: 8px; }
    .vfoot { padding: 16px; font-size: 11px; }
    .btn-vp { width: 100%; justify-content: center; padding: 12px; }
}
</style>
</head>
<body>

<div class="vcard">

  <!-- Header -->
  <div class="vhdr">
    <div class="vhdr-icon"><img src="/group31petron_system_official4/assets/img/Petron Logo.png" alt="Petron Logo"></div>
    <div>
      <div class="vhdr-logo">PETRON STATION MANAGEMENT</div>
      <div class="vhdr-sub">Official Transaction Verification Portal</div>
    </div>
  </div>

  <?php if ($error): ?>
  <!-- Error / Not Found -->
  <div class="v-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
  <div class="v-not-found">
    <i class="fas fa-file-slash" style="font-size:48px;margin-bottom:12px;display:block;opacity:.3;"></i>
    <p style="font-size:15px;font-weight:700;margin-bottom:6px;">Transaction Not Found</p>
    <p style="font-size:13px;">ID: <strong><?php echo htmlspecialchars($id); ?></strong> could not be located in the database.</p>
    <p style="font-size:12px;margin-top:8px;color:#94a3b8;">This may mean the receipt is invalid, the transaction was deleted, or an incorrect QR code was scanned.</p>
  </div>

  <?php else: ?>
  <!-- Verified -->
  <div class="v-verified">
    <i class="fas fa-shield-check" style="font-size:16px;"></i>
    Record found in database — Official Petron Transaction
  </div>

  <div class="vbody">

    <!-- Payment Status Badge -->
    <div style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
      <span class="pay-badge" style="background:<?php echo $ps_cfg['bg']; ?>;color:<?php echo $ps_cfg['txt']; ?>;border-color:<?php echo $ps_cfg['border']; ?>;">
        <i class="fas fa-<?php echo ['paid'=>'check-circle','partial'=>'adjust','pending'=>'hourglass-half','credit'=>'handshake'][$pay_norm]; ?>"></i>
        <?php echo $ps_cfg['label']; ?>
      </span>
      <?php
        $vl = strtolower(trim($validation));
        if (in_array($vl,['approved','verified','validated'])) { $vc='#166534';$vb='#d1fae5';$vi='fa-check-double'; }
        elseif (in_array($vl,['pending','pending validation',''])) { $vc='#92400e';$vb='#fef9c3';$vi='fa-clock'; }
        else { $vc='#991b1b';$vb='#fee2e2';$vi='fa-times-circle'; }
      ?>
      <span class="val-badge" style="background:<?php echo $vb; ?>;color:<?php echo $vc; ?>;">
        <i class="fas <?php echo $vi; ?>" style="margin-right:5px;"></i> Validation: <?php echo htmlspecialchars(ucfirst($validation)); ?>
      </span>
    </div>

    <!-- Payment Notice -->
    <!-- Payment Notice -->
    <?php if ($pay_norm === 'partial'): ?>
    <div class="vnote vnote-partial">
      <i class="fas fa-exclamation-triangle"></i>
      <div style="flex: 1; word-break: break-word;">
        This is a <strong>Partial Payment</strong> receipt. Balance of
        <strong>&#8369;<?php echo number_format($balance_due, 2); ?></strong> remains outstanding.
      </div>
    </div>
    <?php elseif ($pay_norm === 'pending'): ?>
    <div class="vnote vnote-pending">
      <i class="fas fa-exclamation-triangle"></i>
      <div style="flex: 1; word-break: break-word;">
        <strong>No payment collected yet.</strong><br>
        Full balance of <strong>&#8369;<?php echo number_format($total, 2); ?></strong> is outstanding.
      </div>
    </div>
    <?php elseif ($pay_norm === 'credit'): ?>
    <div class="vnote vnote-credit">
      <i class="fas fa-handshake"></i>
      <div style="flex: 1; word-break: break-word;">
        <strong>Credit Transaction (Utang).</strong> Amount forwarded to the Receivables module.
      </div>
    </div>
    <?php endif; ?>

    <!-- Transaction Info -->
    <div class="vsec">
      <div class="vsec-title"><i class="fas fa-receipt"></i> Transaction Details</div>
      <div class="vrow"><span class="vrow-key">Transaction ID</span><span class="vrow-val" style="color:#002F6C;font-family:monospace;"><?php echo htmlspecialchars($txn_id); ?></span></div>
      <div class="vrow"><span class="vrow-key">Date</span><span class="vrow-val"><?php echo $disp_date; ?></span></div>
      <div class="vrow"><span class="vrow-key">Time</span><span class="vrow-val"><?php echo $disp_time; ?></span></div>
      <div class="vrow"><span class="vrow-key">Customer</span><span class="vrow-val"><?php echo htmlspecialchars($customer); ?></span></div>
      <div class="vrow"><span class="vrow-key">Staff</span><span class="vrow-val"><?php echo htmlspecialchars($txn['staff_name'] ?? 'N/A'); ?></span></div>
      <div class="vrow"><span class="vrow-key">Station</span><span class="vrow-val"><?php echo htmlspecialchars($station); ?></span></div>
      <div class="vrow"><span class="vrow-key">VAT TIN</span><span class="vrow-val"><?php echo htmlspecialchars($vat_tin); ?></span></div>
    </div>

    <!-- Items -->
    <?php if (!empty($items)): ?>
    <div class="vsec">
      <div class="vsec-title"><i class="fas fa-list"></i> Items Purchased</div>
      <div class="vtbl-container">
        <table class="vtbl">
          <thead>
            <tr>
              <th>Item</th>
              <th>Qty</th>
              <th>Unit Price</th>
              <th>Subtotal</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
            <tr>
              <td>
                <?php echo htmlspecialchars($it['product_name'] ?? '—'); ?>
                <?php if (!empty($it['size_variant'])): ?>
                <small style="color:#94a3b8; font-weight:600;"> &bull; <?php echo htmlspecialchars($it['size_variant']); ?></small>
                <?php endif; ?>
              </td>
              <td style="text-align:center;"><?php echo (int)($it['quantity'] ?? 1); ?></td>
              <td style="text-align:right;">&#8369;<?php echo number_format((float)($it['unit_price'] ?? 0), 2); ?></td>
              <td style="text-align:right;font-weight:800;color:#002F6C;">&#8369;<?php echo number_format((float)($it['subtotal'] ?? 0), 2); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Job Order Details (if applicable) -->
    <?php if (!empty($txn['job_order_service']) || !empty($txn['job_order_vehicle_plate'])): ?>
    <div class="vsec">
      <div class="vsec-title" style="color:#b45309;"><i class="fas fa-wrench"></i> Job Order Details</div>
      <?php if (!empty($txn['job_order_service'])): ?>
      <div class="vrow"><span class="vrow-key">Service Type</span><span class="vrow-val"><?php echo htmlspecialchars($txn['job_order_service']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($txn['job_order_vehicle_plate'])): ?>
      <div class="vrow"><span class="vrow-key">Vehicle Plate</span><span class="vrow-val" style="font-family:monospace;font-weight:700;"><?php echo htmlspecialchars($txn['job_order_vehicle_plate']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($txn['job_order_vehicle_type'])): ?>
      <div class="vrow"><span class="vrow-key">Vehicle Type</span><span class="vrow-val"><?php echo htmlspecialchars($txn['job_order_vehicle_type']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($txn['job_order_mechanic_name'])): ?>
      <div class="vrow"><span class="vrow-key">Mechanic</span><span class="vrow-val"><?php echo htmlspecialchars($txn['job_order_mechanic_name']); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($txn['job_order_description'])): ?>
      <div class="vrow" style="align-items:flex-start;"><span class="vrow-key">Description</span><span class="vrow-val" style="font-size:12px;color:#64748b;"><?php echo nl2br(htmlspecialchars($txn['job_order_description'])); ?></span></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Totals -->
    <div class="vsec">
      <div class="vsec-title"><i class="fas fa-calculator"></i> Totals</div>
      <div class="vrow"><span class="vrow-key">Vatable Sales</span><span class="vrow-val">&#8369;<?php echo number_format($subtotal, 2); ?></span></div>
      <div class="vrow"><span class="vrow-key">VAT (12%)</span><span class="vrow-val">&#8369;<?php echo number_format($vat_amt, 2); ?></span></div>
      <div class="vrow"><span class="vrow-key vrow-total">GRAND TOTAL</span><span class="vrow-val vrow-total">&#8369;<?php echo number_format($total, 2); ?></span></div>
    </div>

    <!-- Payment Breakdown -->
    <div class="vsec">
      <div class="vsec-title"><i class="fas fa-credit-card"></i> Payment Breakdown</div>
      <div class="vrow"><span class="vrow-key">Method</span><span class="vrow-val"><?php echo htmlspecialchars($pay_method); ?></span></div>

      <?php if ($pay_norm === 'paid'): ?>
        <div class="vrow"><span class="vrow-key">Amount Tendered</span><span class="vrow-val">&#8369;<?php echo number_format($tendered > 0 ? $tendered : $total, 2); ?></span></div>
        <?php if ($change > 0): ?>
        <div class="vrow"><span class="vrow-key">Change</span><span class="vrow-val">&#8369;<?php echo number_format($change, 2); ?></span></div>
        <?php endif; ?>

      <?php elseif ($pay_norm === 'partial'): ?>
        <div class="vrow"><span class="vrow-key">Amount Paid</span><span class="vrow-val">&#8369;<?php echo number_format($amount_paid, 2); ?></span></div>
        <div class="vrow"><span class="vrow-key">Balance Due</span><span class="vrow-val vrow-red">&#8369;<?php echo number_format($balance_due, 2); ?></span></div>

      <?php elseif ($pay_norm === 'pending'): ?>
        <div class="vrow"><span class="vrow-key">Amount Paid</span><span class="vrow-val">&#8369;0.00</span></div>
        <div class="vrow"><span class="vrow-key">Balance Due</span><span class="vrow-val vrow-red">&#8369;<?php echo number_format($total, 2); ?></span></div>

      <?php elseif ($pay_norm === 'credit'): ?>
        <div class="vrow"><span class="vrow-key">Amount Paid</span><span class="vrow-val">&#8369;0.00</span></div>
        <div class="vrow"><span class="vrow-key">Credit Amount</span><span class="vrow-val vrow-purple">&#8369;<?php echo number_format($total, 2); ?></span></div>
      <?php endif; ?>

      <?php
      // Render payment-method specific metadata fields
      $pm_lower = strtolower($pay_method);
      if (strpos($pm_lower, 'pvc') !== false || strpos($pm_lower, 'value card') !== false || strpos($pm_lower, 'loyalt') !== false || strpos($pm_lower, 'point') !== false) {
          $pvc_card = $txn['loyalty_card_no'] ?? $txn['card_number'] ?? '';
          $pts_redeemed = (float)($txn['loyalty_points_redeemed'] ?? $txn['points_redeemed'] ?? 0);
          $pts_discount = (float)($txn['loyalty_discount_amount'] ?? $txn['loyalty_discount'] ?? 0);
          if ($pvc_card) {
              echo '<div class="vrow"><span class="vrow-key">Petron Value Card</span><span class="vrow-val" style="font-family:monospace; font-weight:700;">' . htmlspecialchars($pvc_card) . '</span></div>';
          }
          if ($pts_redeemed > 0) {
              echo '<div class="vrow"><span class="vrow-key">Points Redeemed</span><span class="vrow-val" style="font-weight:700; color:#002F6C;">' . number_format($pts_redeemed, 2) . ' pts</span></div>';
          }
          if ($pts_discount > 0) {
              echo '<div class="vrow"><span class="vrow-key">Loyalty Discount</span><span class="vrow-val" style="font-weight:700; color:#16a34a;">-&#8369;' . number_format($pts_discount, 2) . '</span></div>';
          }
      } elseif (strpos($pm_lower, 'fleet') !== false) {
          $fleet_no  = $txn['fleet_card_number'] ?? '';
          $comp_name = $txn['company_name'] ?? '';
          $auth_no   = $txn['authorization_number'] ?? '';
          if ($fleet_no) {
              echo '<div class="vrow"><span class="vrow-key">Fleet Card No.</span><span class="vrow-val" style="font-family:monospace;">' . htmlspecialchars($fleet_no) . '</span></div>';
          }
          if ($comp_name) {
              echo '<div class="vrow"><span class="vrow-key">Company Name</span><span class="vrow-val">' . htmlspecialchars($comp_name) . '</span></div>';
          }
          if ($auth_no) {
              echo '<div class="vrow"><span class="vrow-key">Auth Number</span><span class="vrow-val" style="font-family:monospace;">' . htmlspecialchars($auth_no) . '</span></div>';
          }
      } elseif (strpos($pm_lower, 'card') !== false) {
          $card_type = $txn['card_type'] ?? '';
          $last_four = $txn['card_last_four'] ?? '';
          $ref_no    = $txn['reference_number'] ?? $txn['card_reference'] ?? '';
          if ($card_type) {
              echo '<div class="vrow"><span class="vrow-key">Card Type</span><span class="vrow-val">' . htmlspecialchars($card_type) . '</span></div>';
          }
          if ($last_four) {
              echo '<div class="vrow"><span class="vrow-key">Card Number</span><span class="vrow-val">•••• •••• •••• ' . htmlspecialchars($last_four) . '</span></div>';
          }
          if ($ref_no) {
              echo '<div class="vrow"><span class="vrow-key">Reference No.</span><span class="vrow-val" style="font-family:monospace;">' . htmlspecialchars($ref_no) . '</span></div>';
          }
      } elseif (strpos($pm_lower, 'gcash') !== false || strpos($pm_lower, 'maya') !== false || strpos($pm_lower, 'wallet') !== false) {
          $provider = $txn['wallet_provider'] ?? $txn['payment_method'] ?? 'E-Wallet';
          $ref_no   = $txn['reference_number'] ?? $txn['ewallet_reference'] ?? '';
          echo '<div class="vrow"><span class="vrow-key">E-Wallet Provider</span><span class="vrow-val">' . htmlspecialchars($provider) . '</span></div>';
          if ($ref_no) {
              echo '<div class="vrow"><span class="vrow-key">Reference No.</span><span class="vrow-val" style="font-family:monospace;">' . htmlspecialchars($ref_no) . '</span></div>';
          }
      } elseif (strpos($pm_lower, 'fleet') !== false) {
          $fleet_no  = $txn['fleet_card_number'] ?? '';
          $comp_name = $txn['company_name'] ?? '';
          $auth_no   = $txn['authorization_number'] ?? '';
          if ($fleet_no) {
              echo '<div class="vrow"><span class="vrow-key">Fleet Card No.</span><span class="vrow-val" style="font-family:monospace;">' . htmlspecialchars($fleet_no) . '</span></div>';
          }
          if ($comp_name) {
              echo '<div class="vrow"><span class="vrow-key">Company Name</span><span class="vrow-val">' . htmlspecialchars($comp_name) . '</span></div>';
          }
          if ($auth_no) {
              echo '<div class="vrow"><span class="vrow-key">Auth Number</span><span class="vrow-val" style="font-family:monospace;">' . htmlspecialchars($auth_no) . '</span></div>';
          }
      } elseif (strpos($pm_lower, 'e-fuel') !== false || strpos($pm_lower, 'efuel') !== false) {
          $efuel_no = $txn['efuel_card_number'] ?? '';
          $ref_no   = $txn['reference_number'] ?? '';
          if ($efuel_no) {
              echo '<div class="vrow"><span class="vrow-key">E-Fuel Card No.</span><span class="vrow-val" style="font-family:monospace;">' . htmlspecialchars($efuel_no) . '</span></div>';
          }
          if ($ref_no) {
              echo '<div class="vrow"><span class="vrow-key">Reference No.</span><span class="vrow-val" style="font-family:monospace;">' . htmlspecialchars($ref_no) . '</span></div>';
          }
      } elseif (strpos($pm_lower, 'credit') !== false || strpos($pm_lower, 'account') !== false) {
          $comp_name = $txn['company_name'] ?? '';
          $acc_no    = $txn['account_number'] ?? '';
          $due_date  = $txn['due_date'] ?? $txn['credit_due_date'] ?? '';
          if ($comp_name) {
              echo '<div class="vrow"><span class="vrow-key">Company Name</span><span class="vrow-val">' . htmlspecialchars($comp_name) . '</span></div>';
          }
          if ($acc_no) {
              echo '<div class="vrow"><span class="vrow-key">Account No.</span><span class="vrow-val" style="font-family:monospace;">' . htmlspecialchars($acc_no) . '</span></div>';
          }
          if ($due_date) {
              $fmt_due = $due_date;
              try { $fmt_due = (new DateTime($due_date))->format('M j, Y'); } catch (Exception $e) {}
              echo '<div class="vrow"><span class="vrow-key">Due Date</span><span class="vrow-val" style="font-weight:800;color:#9a3412;">' . htmlspecialchars($fmt_due) . '</span></div>';
          }
      } else {
          // Fallback reference number
          $ref_no = $txn['reference_number'] ?? $txn['card_reference'] ?? $txn['ewallet_reference'] ?? '';
          if ($ref_no) {
              echo '<div class="vrow"><span class="vrow-key">Reference No.</span><span class="vrow-val" style="font-family:monospace;">' . htmlspecialchars($ref_no) . '</span></div>';
          }
      }
      ?>
    </div>

  </div><!-- /.vbody -->
  <?php endif; ?>

  <!-- Footer -->
  <div class="vfoot">
    <strong>Petron Station Management System</strong> · <?php echo htmlspecialchars($addr); ?><br>
    Verified: <?php echo $printed_at ?? date('M j, Y h:i A'); ?> &nbsp;|&nbsp; Transaction: <strong><?php echo htmlspecialchars($id ?: 'N/A'); ?></strong>
  </div>

</div><!-- /.vcard -->

</body>
</html>
