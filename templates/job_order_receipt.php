<?php
/**
 * Job Order Receipt Template
 * Expects: $job_order_data  (array — job_orders row + joined station_name, mechanic_name, created_by_name)
 * Outputs: a <div class="jo-receipt"> fragment (no <html>/<body>)
 * CSS lives in the parent page (backend/job_order_receipt.php print action)
 */

$j = $job_order_data ?? [];

// ── Core fields ───────────────────────────────────────────────────────────────
$jo_id          = $j['job_order_id']    ?? $j['job_order_number'] ?? 'N/A';
$created_at     = $j['created_at']      ?? date('Y-m-d H:i:s');
$customer       = $j['customer_name']   ?? 'Walk-in Customer';
$plate          = $j['vehicle_plate']   ?? '';
$vtype          = $j['vehicle_type']    ?? '';
$service_type   = $j['service_type']    ?? '';
$svc_desc       = $j['service_description'] ?? ($j['notes'] ?? '');
$mechanic       = $j['mechanic_name']   ?? 'Unassigned';
$staff          = $j['created_by_name'] ?? 'Staff';
$raw_pay_method = $j['payment_method']  ?? 'Cash';
$raw_ew_provider = $j['ewallet_provider'] ?? null;
$norm_pay       = function_exists('normalize_payment_type') ? normalize_payment_type($raw_pay_method, $raw_ew_provider) : ['payment_type'=>$raw_pay_method, 'provider'=>$raw_ew_provider];
$pay_method     = $norm_pay['payment_type'] ?? 'Cash';
$ew_provider    = $norm_pay['provider'] ?? ($j['ewallet_provider'] ?? null);
$pay_status     = $j['payment_status']  ?? 'Pending';
$total          = (float)($j['estimated_cost'] ?? 0);
$paid           = (float)($j['amount_paid']    ?? 0);
$sukli          = (float)($j['sukli']          ?? 0);

// ── Load receipt_config for this station ─────────────────────────────────────
$_jo_receipt_cfg = null;
$_jo_station_id  = (int)($j['station_id'] ?? 0);
try {
    if (isset($pdo)) {
        $_rc = $pdo->prepare("SELECT * FROM receipt_config WHERE station_id = ? LIMIT 1");
        $_rc->execute([$_jo_station_id]);
        $_jo_receipt_cfg = $_rc->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Exception $_e) {}

// ── Station fields — receipt_config takes priority ───────────────────────────
$_raw_station_name = $j['station_name'] ?? '';
// Smart detection: if the station name looks like a system name or street, use PETRON CORPORATION
if (preg_match('/(station management|blvd|street|st\.|road|city|misamis)/i', $_raw_station_name)) {
    $_raw_station_name = 'PETRON CORPORATION';
}

$station_header = !empty($_jo_receipt_cfg['station_header']) ? $_jo_receipt_cfg['station_header'] : $_raw_station_name;
$branch_name    = !empty($_jo_receipt_cfg['branch_name'])    ? $_jo_receipt_cfg['branch_name']    : '';
$station_addr   = !empty($_jo_receipt_cfg['station_address']) ? $_jo_receipt_cfg['station_address']
                   : ($j['station_address'] ?: ($j['station_location'] ?? ''));
$vat_tin        = !empty($_jo_receipt_cfg['station_vat_tin']) ? $_jo_receipt_cfg['station_vat_tin']
                   : ($j['station_vat_tin'] ?: '');
$atp_no         = !empty($_jo_receipt_cfg['atp_no'])   ? $_jo_receipt_cfg['atp_no']   : '';
$min_serial     = !empty($_jo_receipt_cfg['min_serial']) ? $_jo_receipt_cfg['min_serial'] : '';
$footer_title   = !empty($_jo_receipt_cfg['footer_title'])   ? $_jo_receipt_cfg['footer_title']   : 'Official Job Order Document';
$footer_msg     = !empty($_jo_receipt_cfg['footer_message']) ? $_jo_receipt_cfg['footer_message'] : 'Thank you for choosing Petron!';
$terms_notes    = $_jo_receipt_cfg['terms_notes'] ?? '';

// ── Receipt number ────────────────────────────────────────────────────────────
$rcpt_no = $j['receipt_number'] ?? ('RCPT-' . strtoupper(substr(md5($jo_id . $created_at), 0, 8)));

// ── Parse service price details ───────────────────────────────────────────────
$svc_prices = [];
if (!empty($j['service_price_details'])) {
    $d = json_decode($j['service_price_details'], true);
    if (is_array($d)) $svc_prices = $d;
}

// ── Parse parts (all stored in required_parts JSON) ───────────────────────────
$all_parts  = [];
if (!empty($j['required_parts'])) {
    $d = json_decode($j['required_parts'], true);
    if (is_array($d)) $all_parts = $d;
}
$auto_parts   = array_values(array_filter($all_parts, fn($p) => ($p['type'] ?? '') !== 'manual'));
$manual_parts = array_values(array_filter($all_parts, fn($p) => ($p['type'] ?? '') === 'manual'));

// ── VAT (12% inclusive) ───────────────────────────────────────────────────────
$vatable = $total > 0 ? $total / 1.12 : 0;
$vat_amt = $total - $vatable;

// ── Logo — absolute path from web root ───────────────────────────────────────
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$logo = $base . '/assets/img/Petron Logo.png';
if (!empty($_jo_receipt_cfg['logo_path'])) {
    $logo = $base . '/' . ltrim($_jo_receipt_cfg['logo_path'], '/');
}
?>
<div class="jo-receipt">

  <!-- ══ HEADER ══════════════════════════════════════════════════════════════ -->
  <div class="jo-r-head">
    <img src="<?php echo htmlspecialchars($logo); ?>"
         alt="Petron"
         class="jo-r-logo-img"
         onerror="this.style.display='none'">
    <div class="jo-r-brand"><?php echo htmlspecialchars($station_header); ?></div>
    <?php if (!empty($branch_name)): ?>
    <div class="jo-r-branch" style="font-weight:700;color:#003d7a;"><?php echo htmlspecialchars($branch_name); ?></div>
    <?php endif; ?>
    <div class="jo-r-address"><?php echo htmlspecialchars($station_addr); ?></div>
    <div class="jo-r-tin">VAT REG TIN: <?php echo $vat_tin; ?></div>
    <div class="jo-r-tin">ATP No.: <?php echo htmlspecialchars($atp_no); ?></div>
    <?php if (!empty($min_serial)): ?>
    <div class="jo-r-tin">MIN: <?php echo htmlspecialchars($min_serial); ?></div>
    <?php endif; ?>
  </div>

  <div class="jo-r-div2"></div>
  <div class="jo-r-title">JOB ORDER</div>
  <div class="jo-r-sub">Official Service Invoice</div>
  <div class="jo-r-div"></div>

  <!-- ══ JOB ORDER DETAILS ════════════════════════════════════════════════════ -->
  <div class="jo-r-lbl">Job Order Details</div>

  <div class="jo-r-row"><span class="jo-r-key">Job Order ID</span><span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($jo_id); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Document No.</span><span class="jo-r-val"><?php echo htmlspecialchars($rcpt_no); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Date</span><span class="jo-r-val"><?php echo date('F j, Y', strtotime($created_at)); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Time</span><span class="jo-r-val"><?php echo date('h:i A', strtotime($created_at)); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Customer</span><span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($customer); ?></span></div>
  <?php if ($plate): ?>
  <div class="jo-r-row"><span class="jo-r-key">Plate No.</span><span class="jo-r-val"><?php echo htmlspecialchars($plate); ?></span></div>
  <?php endif; ?>
  <?php if ($vtype): ?>
  <div class="jo-r-row"><span class="jo-r-key">Vehicle</span><span class="jo-r-val"><?php echo htmlspecialchars($vtype); ?></span></div>
  <?php endif; ?>
  <div class="jo-r-row"><span class="jo-r-key">Mechanic</span><span class="jo-r-val"><?php echo htmlspecialchars($mechanic); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Encoded by</span><span class="jo-r-val"><?php echo htmlspecialchars($staff); ?></span></div>

  <div class="jo-r-div"></div>

  <!-- ══ SERVICE TYPE ═════════════════════════════════════════════════════════ -->
  <div class="jo-r-lbl">Service Type</div>

  <?php if (!empty($svc_prices)): ?>
    <?php foreach ($svc_prices as $s): ?>
      <?php $sp = (float)($s['custom_price'] ?? 0); ?>
      <div class="jo-r-row">
        <span class="jo-r-key"><?php echo htmlspecialchars($s['service_name'] ?? $s['service_key'] ?? 'Service'); ?></span>
        <span class="jo-r-val">&#8369;<?php echo number_format($sp, 2); ?></span>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <?php
    $svc_list = array_filter(array_map('trim', explode(',', $service_type)));
    $per = count($svc_list) > 0 ? $total / count($svc_list) : 0;
    foreach ($svc_list as $s): ?>
      <div class="jo-r-row">
        <span class="jo-r-key"><?php echo htmlspecialchars($s); ?></span>
        <span class="jo-r-val">&#8369;<?php echo number_format($per, 2); ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($svc_desc): ?>
    <div class="jo-r-note"><?php echo htmlspecialchars($svc_desc); ?></div>
  <?php endif; ?>

  <!-- ══ PARTS & MATERIALS ════════════════════════════════════════════════════ -->
  <?php if (!empty($all_parts)): ?>
  <div class="jo-r-div"></div>
  <div class="jo-r-lbl">Parts &amp; Materials</div>

  <div class="jo-r-th">
    <span class="jo-r-td-name">Part / Item</span>
    <span class="jo-r-td-qty">Qty</span>
    <span class="jo-r-td-price">Unit</span>
    <span class="jo-r-td-sub">Subtotal</span>
  </div>

  <?php foreach ($all_parts as $p):
    $pname   = $p['name']       ?? $p['part_name'] ?? 'Part';
    $pqty    = (int)($p['qty']  ?? $p['quantity']  ?? 1);
    $pprice  = (float)($p['unit_price'] ?? $p['price'] ?? 0);
    $psub    = $pprice * $pqty;
    $premarks= $p['remarks']    ?? '';
    $is_manual = ($p['type'] ?? '') === 'manual';
    $is_inv    = ($p['source'] ?? '') === 'inventory';
  ?>
  <div class="jo-r-tr">
    <span class="jo-r-td-name">
      <?php echo htmlspecialchars($pname); ?>
      <?php if ($is_inv): ?><span class="jo-r-badge badge-inv">Inventory</span><?php endif; ?>
      <?php if ($is_manual && !$is_inv): ?><span class="jo-r-badge badge-manual">Manual</span><?php endif; ?>
      <?php if ($premarks): ?><span class="jo-r-remarks"><?php echo htmlspecialchars($premarks); ?></span><?php endif; ?>
    </span>
    <span class="jo-r-td-qty"><?php echo $pqty; ?></span>
    <span class="jo-r-td-price"><?php echo $pprice > 0 ? '&#8369;' . number_format($pprice, 2) : '—'; ?></span>
    <span class="jo-r-td-sub"><?php echo $psub > 0 ? '&#8369;' . number_format($psub, 2) : '—'; ?></span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <div class="jo-r-div"></div>

  <!-- ══ TOTALS ════════════════════════════════════════════════════════════════ -->
  <?php
    $labor_cost_r = (float)($j['custom_price']    ?? $j['estimated_cost'] ?? 0);
    // If service_price_details exists, sum from there for labor
    if (!empty($svc_prices)) {
        $labor_cost_r = array_sum(array_column($svc_prices, 'custom_price'));
    }
    $parts_cost_r = 0;
    foreach ($all_parts as $p) {
        $parts_cost_r += (float)($p['unit_price'] ?? $p['price'] ?? 0) * (int)($p['qty'] ?? $p['quantity'] ?? 1);
    }
    // If parts have no prices, derive from total - labor
    if ($parts_cost_r == 0 && $total > $labor_cost_r) {
        $parts_cost_r = $total - $labor_cost_r;
    }
    $vatable = $total > 0 ? $total / 1.12 : 0;
    $vat_amt = $total - $vatable;
  ?>
  <div class="jo-r-row"><span class="jo-r-key">Labor (Service Fee)</span><span class="jo-r-val">&#8369;<?php echo number_format($labor_cost_r, 2); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Parts (Merchandise)</span><span class="jo-r-val">&#8369;<?php echo number_format($parts_cost_r, 2); ?></span></div>
  <div class="jo-r-div"></div>
  <div class="jo-r-row"><span class="jo-r-key">Vatable Sales</span><span class="jo-r-val">&#8369;<?php echo number_format($vatable, 2); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">VAT (12%)</span><span class="jo-r-val">&#8369;<?php echo number_format($vat_amt, 2); ?></span></div>
  <div class="jo-r-row"><span class="jo-r-key">Zero-Rated</span><span class="jo-r-val">&#8369;0.00</span></div>
  <div class="jo-r-row"><span class="jo-r-key">VAT-Exempt</span><span class="jo-r-val">&#8369;0.00</span></div>

  <div class="jo-r-div2"></div>
  <div class="jo-r-row jo-r-grand">
    <span>GRAND TOTAL</span>
    <span>&#8369;<?php echo number_format($total, 2); ?></span>
  </div>
  <div class="jo-r-div"></div>

  <!-- ══ PAYMENT ═══════════════════════════════════════════════════════════════ -->
  <div class="jo-r-lbl">Payment</div>

  <div class="jo-r-row">
    <span class="jo-r-key">Payment Type</span>
    <span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($pay_method); ?></span>
  </div>

  <?php if ($pay_method === 'E-Wallet' && $ew_provider): ?>
  <div class="jo-r-row">
    <span class="jo-r-key">E-Wallet Provider</span>
    <span class="jo-r-val jo-r-bold" style="color:#059669;"><?php echo htmlspecialchars($ew_provider); ?></span>
  </div>
  <?php endif; ?>

  <?php $pm_lc = strtolower($pay_method); ?>
  <?php if ($pm_lc === 'cash'): ?>
    <div class="jo-r-row">
      <span class="jo-r-key">Amount Tendered</span>
      <span class="jo-r-val">&#8369;<?php echo number_format($paid > 0 ? $paid : $total, 2); ?></span>
    </div>
    <div class="jo-r-row">
      <span class="jo-r-key">Change (Sukli)</span>
      <span class="jo-r-val jo-r-bold">&#8369;<?php echo number_format($sukli, 2); ?></span>
    </div>

  <?php elseif ($pm_lc === 'card'): ?>
    <div class="jo-r-row">
      <span class="jo-r-key">Amount Charged</span>
      <span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span>
    </div>
    <?php
      // Extract card ref from notes if stored there
      $card_ref_val = $j['card_reference'] ?? '';
      if (!$card_ref_val && !empty($j['additional_notes']) && preg_match('/Card Ref:\s*([^\|]+)/i', $j['additional_notes'], $m)) {
          $card_ref_val = trim($m[1]);
      }
    ?>
    <?php if ($card_ref_val): ?>
    <div class="jo-r-row">
      <span class="jo-r-key">Card Ref No.</span>
      <span class="jo-r-val"><?php echo htmlspecialchars($card_ref_val); ?></span>
    </div>
    <?php endif; ?>

  <?php elseif ($pm_lc === 'e-wallet'): ?>
    <div class="jo-r-row">
      <span class="jo-r-key">Amount</span>
      <span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span>
    </div>
    <?php
      $ew_ref = $j['ewallet_reference'] ?? '';
      if (!$ew_ref && !empty($j['additional_notes']) && preg_match('/(?:E-Wallet Ref|Reference No\.?):\s*([^\|]+)/i', $j['additional_notes'], $m)) {
          $ew_ref = trim($m[1]);
      }
    ?>
    <?php if ($ew_ref): ?>
    <div class="jo-r-row">
      <span class="jo-r-key">Reference No.</span>
      <span class="jo-r-val"><?php echo htmlspecialchars($ew_ref); ?></span>
    </div>
    <?php endif; ?>

  <?php elseif (in_array($pm_lc, ['petron fleet card', 'fleet card'])): ?>
    <div class="jo-r-row">
      <span class="jo-r-key">Amount Charged</span>
      <span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span>
    </div>
    <?php
      $fc_id = $j['fleet_card_number'] ?? '';
      if (!$fc_id && !empty($j['additional_notes']) && preg_match('/Fleet Card:\s*([^\|]+)/i', $j['additional_notes'], $m)) {
          $fc_id = trim($m[1]);
      }
    ?>
    <?php if ($fc_id): ?>
    <div class="jo-r-row">
      <span class="jo-r-key">Fleet Card No.</span>
      <span class="jo-r-val"><?php echo htmlspecialchars($fc_id); ?></span>
    </div>
    <?php endif; ?>

  <?php elseif (in_array($pm_lc, ['credit account', 'credit', 'account receivable'])): ?>
    <?php
      $credit_cust_name = '';
      if (!empty($j['additional_notes']) && preg_match('/Credit Customer:\s*([^\|]+)/i', $j['additional_notes'], $m)) {
          $credit_cust_name = trim($m[1]);
      }
    ?>
    <div class="jo-r-row">
      <span class="jo-r-key">Credit Account</span>
      <span class="jo-r-val"><?php echo htmlspecialchars($credit_cust_name ?: $customer); ?></span>
    </div>
    <div class="jo-r-row"><span class="jo-r-key">Amount Tendered</span><span class="jo-r-val">&#8369;0.00</span></div>
    <div class="jo-r-row" style="font-size:9.5px; color:#856404;">
      <span>Transaction forwarded to Receivables module.</span>
    </div>

  <?php elseif ($pm_lc === 'petron loyalty points'): ?>
    <div class="jo-r-row">
      <span class="jo-r-key">Points Redeemed</span>
      <span class="jo-r-val jo-r-bold"><?php echo htmlspecialchars($j['loyalty_points_redeemed'] ?? $j['points_redeemed'] ?? '0'); ?> pts</span>
    </div>
    <div class="jo-r-row">
      <span class="jo-r-key">Amount Equivalent</span>
      <span class="jo-r-val">&#8369;<?php echo number_format($total, 2); ?></span>
    </div>

  <?php else: ?>
    <?php if ($paid > 0): ?>
    <div class="jo-r-row">
      <span class="jo-r-key">Amount Paid</span>
      <span class="jo-r-val">&#8369;<?php echo number_format($paid, 2); ?></span>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  
  <div class="jo-r-div"></div>

  <!-- ══ FOOTER ════════════════════════════════════════════════════════════════ -->
  <div class="jo-r-foot">
    <div class="jo-r-foot-title"><?php echo htmlspecialchars($footer_title); ?></div>
    <div class="jo-r-foot-line">TIN: <?php echo htmlspecialchars($vat_tin); ?> &nbsp;|&nbsp; VAT Reg: Registered</div>
    <div class="jo-r-foot-line">ATP No.: <?php echo htmlspecialchars($atp_no); ?><?php if (!empty($min_serial)): ?> &nbsp;|&nbsp; MIN: <?php echo htmlspecialchars($min_serial); ?><?php endif; ?></div>
    <div class="jo-r-foot-line" style="font-weight:700;color:#003d7a;"><?php echo htmlspecialchars($footer_msg); ?></div>
    <?php if (!empty($terms_notes)): ?>
    <div class="jo-r-foot-line" style="font-size:8px;color:#666;"><?php echo nl2br(htmlspecialchars($terms_notes)); ?></div>
    <?php endif; ?>
    <div class="jo-r-foot-meta">
      Printed: <?php echo date('M j, Y h:i A'); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($jo_id); ?>
    </div>
  </div>

</div><!-- /.jo-receipt -->
