<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_login();

/* Transaction History section — included by staff_transactions_hub.php */ ?>
<style>
/* Summary Cards (Matches Master Data Requests Exactly) */
.th-kpi-grid, .txn-kpi-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 14px;
  margin: 16px 0 18px;
  width: 100%;
  box-sizing: border-box;
}
@media (max-width: 1200px) {
  .th-kpi-grid, .txn-kpi-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}
@media (max-width: 700px) {
  .th-kpi-grid, .txn-kpi-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
@media (max-width: 480px) {
  .th-kpi-grid, .txn-kpi-grid {
    grid-template-columns: 1fr;
  }
}
.th-kpi, .txn-kpi-card {
  background: #fff;
  border-radius: 12px;
  padding: 16px 18px;
  border: 1px solid #e2e8f0;
  box-shadow: none;
  transition: transform .15s, box-shadow .15s;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 86px;
}
.th-kpi:hover, .txn-kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 10px rgba(0,0,0,.09);
}
.th-kpi-val, .txn-kpi-val {
  font-size: 26px !important;
  font-weight: 800 !important;
  line-height: 1.1 !important;
  margin-top: 0 !important;
}
.th-kpi-lbl, .txn-kpi-lbl {
  font-size: 10px !important;
  font-weight: 700 !important;
  color: #64748b !important;
  text-transform: uppercase !important;
  letter-spacing: .5px !important;
  display: flex !important;
  align-items: center !important;
  gap: 6px !important;
  line-height: 1.3 !important;
  margin-bottom: 6px !important;
  white-space: nowrap !important;
}
.th-kpi-sub {
  display: none !important;
}
.th-filter-bar {
  background: #fff;
  border-radius: 10px;
  padding: 16px 18px;
  margin: 14px 0;
  box-shadow: 0 1px 6px rgba(0,0,0,.06);
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
}
.th-filter-bar label {
  font-size: 12.5px;
  font-weight: 700;
  color: #334155;
  display: block;
  margin-bottom: 5px;
}
.th-filter-bar input, .th-filter-bar select {
  height: 38px;
  padding: 0 12px;
  border: 1.5px solid #cbd5e1;
  border-radius: 7px;
  font-size: 13.5px;
  color: #0f172a;
  background: #fff;
}
.th-filter-grp {
  display: flex;
  flex-direction: column;
}
.th-badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 5px;
  font-size: 12px;
  font-weight: 700;
}
#histTbl {
  font-size: 13.5px;
  width: 100% !important;
  min-width: 1120px !important;
  table-layout: fixed !important;
  border-collapse: collapse !important;
}
#histTbl th {
  padding: 12px 10px !important;
  font-size: 12.5px !important;
  white-space: nowrap !important;
  overflow: visible !important;
  background: #002F70 !important;
  border-bottom: 2px solid #001f4d !important;
  color: #ffffff !important;
  font-weight: 800 !important;
  text-transform: uppercase !important;
  letter-spacing: 0.3px !important;
  box-sizing: border-box !important;
}
#histTbl td {
  padding: 10px 8px !important;
  word-break: break-word !important;
  overflow-wrap: break-word !important;
  font-size: 13.5px !important;
  vertical-align: middle !important;
  border-bottom: 1px solid #f1f5f9 !important;
  box-sizing: border-box !important;
}
.hist-item-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 2px 7px;
  font-size: 10px;
  font-weight: 600;
  color: #374151;
  margin: 1px 2px 1px 0;
}
.hist-item-chip.svc {
  background: #fffbeb;
  border-color: #fde68a;
  color: #92400e;
}
.hist-action-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 4px 8px;
  border-radius: 5px;
  font-size: 10.5px;
  font-weight: 600;
  cursor: pointer;
  border: 1.5px solid #003d7a !important;
  background: #ffffff !important;
  color: #003d7a !important;
  transition: all 0.15s;
  text-decoration: none;
  white-space: nowrap;
}
.btn-view-act {
  border-color: #003d7a !important;
  background: #ffffff !important;
  color: #003d7a !important;
}
.btn-view-act:hover {
  background: #f0f7ff !important;
  color: #00264D !important;
  border-color: #00264D !important;
}
.btn-print-act {
  border-color: #003d7a !important;
  background: #ffffff !important;
  color: #003d7a !important;
}
.btn-print-act:hover {
  background: #f0f7ff !important;
  color: #00264D !important;
  border-color: #00264D !important;
}

/* ── Petron Downward Custom Dropdowns ── */
.petron-dropdown-source { display: none !important; }
.petron-dropdown-wrap {
    position: relative !important;
    display: inline-block !important;
    vertical-align: middle !important;
    box-sizing: border-box !important;
}
.petron-dropdown-wrap.is-open { z-index: 10050 !important; }
.petron-dropdown-trigger {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    width: 100% !important;
    height: 38px !important;
    padding: 0 12px !important;
    background: #fff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 7px !important;
    font-size: 13.5px !important;
    color: #0f172a !important;
    cursor: pointer !important;
    box-sizing: border-box !important;
    gap: 8px !important;
    white-space: nowrap !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-trigger {
    border-color: #1967d2 !important;
    box-shadow: 0 0 0 2px rgba(25,103,210,.2) !important;
}
.petron-dropdown-label {
    flex: 1 !important;
    text-align: left !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.petron-dropdown-arrow {
    font-size: 11px !important;
    color: #64748b !important;
    transition: transform .2s !important;
    flex-shrink: 0 !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-arrow {
    transform: rotate(180deg) !important;
}
.petron-dropdown-menu {
    position: absolute !important;
    top: calc(100% + 4px) !important;
    bottom: auto !important;
    left: 0 !important;
    z-index: 10051 !important;
    min-width: 100% !important;
    background: #fff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 7px !important;
    box-shadow: 0 8px 24px rgba(0,0,0,.15) !important;
    max-height: 240px !important;
    overflow-y: auto !important;
    display: none !important;
    padding: 4px 0 !important;
}
.petron-dropdown-wrap.is-open .petron-dropdown-menu {
    display: block !important;
}
.petron-dropdown-item {
    padding: 8px 14px !important;
    font-size: 13.5px !important;
    color: #1e293b !important;
    background: #fff !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    transition: background .12s, color .12s !important;
}
.petron-dropdown-item:hover,
.petron-dropdown-item.is-selected {
    background: #1967d2 !important;
    color: #fff !important;
}
</style>

<div class="txn-section-header">
  <div class="txn-section-title">
    <div>
      <h1><i class="fas fa-history" style="color:#002F70;margin-right:8px;font-size:20px"></i>Transaction History</h1>
    </div>
  </div>
  <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
    <button type="button" onclick="window.location.href='staff_transactions_hub.php?section=merchandise&active_tab=merchandise'" class="txn-btn secondary" title="Back to Merchandise/Service Transaction">
      <i class="fas fa-arrow-left"></i> <span>Back</span>
    </button>
  </div>
</div>

<!-- KPI Cards (Matches Master Data Requests Design & Size) -->
<div class="th-kpi-grid txn-kpi-grid">
  <div class="th-kpi txn-kpi-card blue" title="This period">
    <div class="th-kpi-lbl txn-kpi-lbl"><i class="fas fa-file-alt" style="color:#0284c7;margin-right:4px;"></i> Total Transactions</div>
    <div class="th-kpi-val txn-kpi-val" style="color:#0284c7;"><?= number_format($hist_kpi_total) ?></div>
  </div>
  <div class="th-kpi txn-kpi-card green" title="Merchandise Only">
    <div class="th-kpi-lbl txn-kpi-lbl"><i class="fas fa-shopping-cart" style="color:#16a34a;margin-right:4px;"></i> Merchandise</div>
    <div class="th-kpi-val txn-kpi-val" style="color:#16a34a;"><?= number_format($hist_kpi_merch) ?></div>
  </div>
  <div class="th-kpi txn-kpi-card orange" title="JO + Combined">
    <div class="th-kpi-lbl txn-kpi-lbl"><i class="fas fa-wrench" style="color:#d97706;margin-right:4px;"></i> Job Orders</div>
    <div class="th-kpi-val txn-kpi-val" style="color:#d97706;"><?= number_format($hist_kpi_jo) ?></div>
  </div>
  <div class="th-kpi txn-kpi-card purple" title="Gross encoded">
    <div class="th-kpi-lbl txn-kpi-lbl"><i class="fas fa-coins" style="color:#7c3aed;margin-right:4px;"></i> Total Sales</div>
    <div class="th-kpi-val txn-kpi-val" style="color:#7c3aed;">&#8369;<?= number_format($hist_kpi_sales, 2) ?></div>
  </div>
  <div class="th-kpi txn-kpi-card teal" title="Fully paid">
    <div class="th-kpi-lbl txn-kpi-lbl"><i class="fas fa-credit-card" style="color:#0d9488;margin-right:4px;"></i> Paid</div>
    <div class="th-kpi-val txn-kpi-val" style="color:#0d9488;"><?= number_format($hist_kpi_paid ?? 0) ?></div>
  </div>
  <div class="th-kpi txn-kpi-card danger" title="Pending / Credit">
    <div class="th-kpi-lbl txn-kpi-lbl"><i class="fas fa-hourglass-half" style="color:#dc2626;margin-right:4px;"></i> Unpaid / Partial</div>
    <div class="th-kpi-val txn-kpi-val" style="color:#dc2626;"><?= number_format($hist_kpi_unpaid ?? 0) ?></div>
  </div>
</div>

<!-- Filters -->
<form method="get" action="" class="th-filter-bar">
  <input type="hidden" name="section" value="history">
  <div class="th-filter-grp">
    <label>From Date</label>
    <input type="date" name="date_from" value="<?= htmlspecialchars($hist_filter_date_from) ?>">
  </div>
  <div class="th-filter-grp">
    <label>To Date</label>
    <input type="date" name="date_to" value="<?= htmlspecialchars($hist_filter_date_to) ?>">
  </div>
  <div class="th-filter-grp">
    <label>Transaction Type</label>
    <select id="histFilterTxnType" name="txn_type">
      <option value="">All Types</option>
      <option value="merchandise" <?= $hist_filter_type==='merchandise'?'selected':'' ?>>Merchandise Only</option>
      <option value="job_order" <?= $hist_filter_type==='job_order'?'selected':'' ?>>Job Order Only</option>
      <option value="combined" <?= $hist_filter_type==='combined'?'selected':'' ?>>Job Order + Merchandise</option>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Customer</label>
    <select id="histFilterCustType" name="cust_type">
      <option value="">All</option>
      <option value="registered" <?= $hist_filter_ctype==='registered'?'selected':'' ?>>Registered</option>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Payment Method</label>
    <select id="histFilterPayment" name="payment">
      <option value="">All Methods</option>
      <?php foreach(['Cash','Card','E-Wallet','Petron Value Card (PVC Points)','Credit Account'] as $_pm): ?>
      <option value="<?= $_pm ?>" <?= $hist_filter_pay===$_pm?'selected':'' ?>><?= $_pm ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Payment Status</label>
    <select id="histFilterPstatus" name="pstatus">
      <option value="">All Statuses</option>
      <option value="paid" <?= strtolower($hist_filter_pstatus)==='paid'?'selected':'' ?>>Paid</option>
      <option value="pending" <?= strtolower($hist_filter_pstatus)==='pending'?'selected':'' ?>>Pending</option>
      <option value="partially paid" <?= strtolower($hist_filter_pstatus)==='partially paid'?'selected':'' ?>>Partially Paid</option>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Status</label>
    <select id="histFilterVstatus" name="vstatus">
      <option value="">All Statuses</option>
      <option value="Completed" <?= $hist_filter_vstatus==='Completed'?'selected':'' ?>>Completed</option>
      <option value="Adjusted" <?= $hist_filter_vstatus==='Adjusted'?'selected':'' ?>>Adjusted</option>
      <option value="Voided" <?= $hist_filter_vstatus==='Voided'?'selected':'' ?>>Voided</option>
    </select>
  </div>
  <div class="th-filter-grp">
    <label>Shift</label>
    <select id="histFilterShift" name="shift">
      <option value="">All Shifts</option>
      <option value="first" <?= $hist_filter_shift==='first'?'selected':'' ?>>Shift 1</option>
      <option value="second" <?= $hist_filter_shift==='second'?'selected':'' ?>>Shift 2</option>
    </select>
  </div>
  <div class="th-filter-grp" style="flex:1;min-width:220px;">
    <label>Search</label>
    <input type="text" name="hsearch" placeholder="Search Transaction ID, Customer, OR No., Plate No." value="<?= htmlspecialchars($hist_search) ?>" style="width:100%;">
  </div>
  <button type="submit" class="txn-btn primary" style="min-width:0; height:38px; padding:0 20px; font-size:13.5px; font-weight:700; border-radius:7px;"><i class="fas fa-search"></i> Filter</button>
  <a href="?section=history" class="txn-btn secondary" style="min-width:0; height:38px; padding:0 18px; font-size:13.5px; font-weight:700; border-radius:7px; display:inline-flex; align-items:center; gap:6px;"><i class="fas fa-times"></i> Reset</a>
</form>

<!-- Table -->
<div class="txn-card" style="margin-top:6px">
  <div class="txn-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:16px 20px;">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fas fa-list-alt" style="color:#002F70;font-size:18px;"></i>
      <h3 style="margin:0;font-size:16.5px;font-weight:800;color:#002F70;">All Transactions</h3>
    </div>
  </div>
  <div class="txn-card-body" style="padding:0">
<?php if (empty($recent_merch)): ?>
    <div style="text-align:center;padding:56px 20px;color:#64748b;">
      <i class="fas fa-receipt" style="font-size:42px;display:block;margin-bottom:12px;color:#cbd5e1;"></i>
      <div style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:4px;"><i class="fas fa-file-alt"></i> No transactions found.</div>
      <div style="font-size:13px;color:#64748b;">Try changing the date range or filter settings.</div>
    </div>
<?php else: ?>
    <div class="vt-table-wrapper" style="width:100% !important;max-width:100% !important;overflow-x:auto !important;box-sizing:border-box !important;border-radius:10px 10px 0 0;background:#fff;">
    <table class="txn-table" id="histTbl" style="width:100% !important;min-width:1120px;table-layout:fixed !important;border-collapse:collapse !important;">
      <colgroup>
        <col style="width:11%; min-width:115px;"><!-- TXN ID -->
        <col style="width:16%; min-width:170px;"><!-- CUSTOMER & VEHICLE -->
        <col style="width:11%; min-width:125px;"><!-- TYPE -->
        <col style="width:21%; min-width:230px;"><!-- PRODUCTS & SERVICES -->
        <col style="width:10%; min-width:120px;"><!-- FEES -->
        <col style="width:12%; min-width:135px;"><!-- TOTAL & PAYMENT -->
        <col style="width:9%;  min-width:105px;"><!-- STATUS -->
        <col style="width:10%; min-width:120px;"><!-- DATE & TIME -->
      </colgroup>
      <thead><tr>
        <th style="white-space:nowrap;padding:12px 10px;">TXN ID</th>
        <th style="white-space:nowrap;padding:12px 10px;">CUSTOMER & VEHICLE</th>
        <th style="white-space:nowrap;padding:12px 8px;text-align:center;">TYPE</th>
        <th style="white-space:nowrap;padding:12px 10px;">PRODUCTS & SERVICES</th>
        <th style="white-space:nowrap;padding:12px 10px;">FEES</th>
        <th style="white-space:nowrap;padding:12px 10px;">TOTAL & PAYMENT</th>
        <th style="white-space:nowrap;padding:12px 8px;text-align:center;">STATUS</th>
        <th style="white-space:nowrap;padding:12px 10px;">DATE & TIME</th>
      </tr></thead>
      <tbody id="histTbody">
      <?php
      // Helper to resolve unit labels
      $resolveUnitLabel = function(string $name, string $variant, float $qty, string $item_type): string {
          if ($item_type === 'service') return 'Svc';
          $n_lower = strtolower($name . ' ' . $variant);
          if (strpos($n_lower, 'refrigerant') !== false || strpos($n_lower, 'r134a') !== false || strpos($n_lower, 'can') !== false) {
              return $qty > 1 ? 'Cans' : 'Can';
          }
          if (strpos($n_lower, 'bottle') !== false || strpos($n_lower, 'coolant') !== false || strpos($n_lower, 'fluid') !== false || strpos($n_lower, 'cleaner') !== false || strpos($n_lower, 'oil') !== false || strpos($n_lower, 'brake') !== false) {
              return $qty > 1 ? 'Bottles' : 'Bottle';
          }
          if (strpos($n_lower, 'liter') !== false || strpos($n_lower, 'litre') !== false || preg_match('/\b\d+(\.\d+)?\s*l\b/i', $n_lower)) {
              return $qty > 1 ? 'Liters' : 'Liter';
          }
          if (strpos($n_lower, 'set') !== false) return 'Set';
          if (strpos($n_lower, 'box') !== false) return $qty > 1 ? 'Boxes' : 'Box';
          return $qty > 1 ? 'Pcs' : 'Pc';
      };

      // Pre-fetch items
      $ht_ids = array_column($recent_merch, 'id');
      $ht_items_map = [];
      if (!empty($ht_ids)) {
          try {
              $in_pl = implode(',', array_map('intval', $ht_ids));
              $itm_stmt = $pdo->query("
                  SELECT transaction_id, product_name, quantity, unit_price, subtotal,
                         COALESCE(item_type,'merchandise') AS item_type,
                         COALESCE(category,'') AS category,
                         COALESCE(size_variant,'') AS size_variant
                  FROM merchandise_transaction_items
                  WHERE transaction_id IN ($in_pl)
                  ORDER BY transaction_id, id ASC
              ");
              foreach ($itm_stmt->fetchAll(PDO::FETCH_ASSOC) as $itm_row) {
                  $ht_items_map[(int)$itm_row['transaction_id']][] = $itm_row;
              }
          } catch (Exception $e) { $ht_items_map = []; }
      }

      foreach ($recent_merch as $ht):
          $ht_id    = (int)$ht['id'];
          $ht_type  = $ht['transaction_type'] ?? 'merchandise';
          $ht_ps    = strtolower(trim($ht['payment_status'] ?? 'pending'));
          if ($ht_ps === 'paid') { $psc='#16a34a'; }
          elseif (in_array($ht_ps,['partially paid','partial payment'])) { $psc='#d97706'; }
          elseif (in_array($ht_ps,['credit account','credit transaction','credit'])) { $psc='#7c3aed'; }
          else { $psc='#ea580c'; }

          if ($ht_type==='combined') { 
            $tc='#002F70'; $tb='#e0f2fe'; $tborder='#93c5fd'; $tl='Job Order + Merch'; 
          } elseif ($ht_type==='job_order') { 
            $tc='#991b1b'; $tb='#fee2e2'; $tborder='#fca5a5'; $tl='Job Order'; 
          } else { 
            $tc='#166534'; $tb='#dcfce7'; $tborder='#86efac'; $tl='Merchandise'; 
          }

          $ht_date_only = '';
          $ht_time_only = '';
          if (!empty($ht['transaction_date'])) {
              try {
                  $dt_obj = new DateTime($ht['transaction_date']);
                  $ht_date_only = $dt_obj->format('M d, Y');
                  $ht_time_only = $dt_obj->format('h:i A');
              } catch(Exception $e){}
          }
          $ht_tid   = htmlspecialchars($ht['transaction_id'] ?? ('#'.$ht_id));
          $ht_cname = htmlspecialchars($ht['customer_name'] ?? 'Walk-in Customer');
          $row_items = $ht_items_map[$ht_id] ?? [];

          // Fallback if no structured item table rows
          if (empty($row_items) && !empty($ht['item_sku'])) {
              $row_items = [[
                  'item_type'    => ($ht_type === 'job_order') ? 'service' : 'merchandise',
                  'product_name' => $ht['item_sku'],
                  'quantity'     => $ht['quantity'] ?? 1,
                  'unit_price'   => $ht['unit_price'] ?? 0,
                  'subtotal'     => $ht['total_amount'] ?? 0,
                  'category'     => '',
                  'size_variant' => '',
              ]];
          }

          // Validation Status Badge (Completed / Released / Adjusted / Voided)
          $val_status = strtolower(trim($ht['validation_status'] ?? 'official'));
          $wf_status  = strtolower(trim($ht['workflow_status'] ?? ''));
          if (in_array($val_status, ['released']) || in_array($wf_status, ['released'])) {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12.5px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;white-space:nowrap;"><i class="fas fa-check" style="font-size:10px;"></i> Released</span>';
          } elseif (in_array($val_status, ['completed', 'approved', 'official', 'verified']) || in_array($wf_status, ['completed'])) {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12.5px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;white-space:nowrap;"><i class="fas fa-check-circle" style="font-size:10px;"></i> Completed</span>';
          } elseif ($val_status === 'adjusted') {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12.5px;font-weight:700;color:#92400e;background:#fef3c7;border:1px solid #fcd34d;white-space:nowrap;"><i class="fas fa-sliders-h" style="font-size:10px;"></i> Adjusted</span>';
          } elseif (in_array($val_status, ['voided', 'cancelled', 'canceled', 'void'])) {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12.5px;font-weight:700;color:#991b1b;background:#fee2e2;border:1px solid #fca5a5;white-space:nowrap;"><i class="fas fa-ban" style="font-size:10px;"></i> Voided</span>';
          } else {
              $v_badge_html = '<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:12.5px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #86efac;white-space:nowrap;"><i class="fas fa-check-circle" style="font-size:10px;"></i> Completed</span>';
          }

          // Service Fee & Labor Fee resolution
          $sf_val = (float)($ht['service_fee'] ?? 0);
          $lf_val = (float)($ht['labor_fee'] ?? 0);
          if ($sf_val == 0 && !empty($row_items)) {
              foreach ($row_items as $ri) {
                  $itype = strtolower($ri['item_type'] ?? '');
                  $icat  = strtolower($ri['category'] ?? '');
                  $iname = strtolower($ri['product_name'] ?? '');
                  if (($itype === 'service' || strpos($icat, 'service') !== false) && strpos($icat, 'labor') === false && strpos($iname, 'labor') === false) {
                      $sf_val += (float)($ri['subtotal'] ?? 0);
                  }
              }
              if ($sf_val == 0 && ($ht_type === 'job_order' || !empty($ht['job_order_service']))) {
                  $sf_val = (float)($ht['total_amount'] ?? 0);
              }
          }
          if ($lf_val == 0 && !empty($row_items)) {
              foreach ($row_items as $ri) {
                  $icat  = strtolower($ri['category'] ?? '');
                  $iname = strtolower($ri['product_name'] ?? '');
                  if ($icat === 'labor' || strpos($iname, 'labor') !== false) {
                      $lf_val += (float)($ri['subtotal'] ?? 0);
                  }
              }
          }
      ?>
      <tr class="hist-row-main">
        <!-- 1. Txn ID -->
        <td style="padding:10px 8px;vertical-align:middle;box-sizing:border-box;">
          <div style="font-weight:800;font-size:12px;color:#002F70;font-family:monospace;word-break:break-all !important;overflow-wrap:anywhere !important;line-height:1.25;white-space:normal !important;" title="<?= $ht_tid ?>"><?= $ht_tid ?></div>
        </td>

        <!-- 2. Customer & Vehicle -->
        <td style="padding:10px 8px;vertical-align:middle;box-sizing:border-box;">
          <div style="font-weight:800;font-size:13.5px;color:#0f172a;line-height:1.25;word-break:break-word;overflow-wrap:break-word;white-space:normal;"><?= $ht_cname ?></div>
          <?php if (!empty($ht['vehicle_plate']) && $ht['vehicle_plate'] !== '—' && $ht['vehicle_plate'] !== 'N/A'): ?>
            <div style="display:inline-flex;align-items:center;gap:4px;background:#f1f5f9;border:1.5px solid #cbd5e1;color:#0f172a;padding:2px 7px;border-radius:5px;font-size:11.5px;font-weight:700;margin-top:3px;">
              <i class="fas fa-car" style="color:#2563eb;font-size:10.5px;"></i> <?= htmlspecialchars($ht['vehicle_plate']) ?>
            </div>
          <?php endif; ?>
        </td>

        <!-- 3. Type -->
        <td style="padding:10px 4px;vertical-align:middle;box-sizing:border-box;text-align:center;overflow:hidden;">
          <span style="background:<?= $tb ?>;color:<?= $tc ?>;border:1.5px solid <?= $tborder ?>;padding:3px 8px;border-radius:6px;font-size:11.5px;font-weight:700;display:inline-block;line-height:1.2;text-align:center;white-space:nowrap;max-width:100%;box-sizing:border-box;"><?= $tl ?></span>
        </td>

        <!-- 4. Products & Services (with Qty & Unit) -->
        <td style="padding:10px 10px;vertical-align:middle;box-sizing:border-box;white-space:normal !important;overflow:visible !important;">
          <?php
          $merch_items = array_filter($row_items, fn($ri) => ($ri['item_type'] ?? 'merchandise') !== 'service');
          $svc_items   = array_filter($row_items, fn($ri) => ($ri['item_type'] ?? '') === 'service');
          $has_any = false;
          
          if (!empty($merch_items)) {
              $has_any = true;
              foreach ($merch_items as $ri) {
                  $qv = (float)($ri['quantity'] ?? 1);
                  $q_num = ($qv == (int)$qv) ? (int)$qv : number_format($qv, 2);
                  $u_lbl = $resolveUnitLabel($ri['product_name'] ?? '', $ri['size_variant'] ?? '', $qv, $ri['item_type'] ?? 'merchandise');
                  ?>
                  <div style="font-weight:700;font-size:13px;color:#0f172a;line-height:1.35;white-space:normal !important;word-break:normal !important;overflow-wrap:break-word !important;word-wrap:break-word !important;">
                    <?= htmlspecialchars($ri['product_name']) ?>
                    <?php if (!empty($ri['size_variant'])): ?>
                    <span style="color:#64748b;font-size:11.5px;font-weight:600;">[<?= htmlspecialchars($ri['size_variant']) ?>]</span>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:11.5px;color:#475569;font-weight:600;margin-top:2px;margin-bottom:4px;white-space:normal !important;">Qty: <?= $q_num ?> <?= $u_lbl ?></div>
                  <?php
              }
          }
          
          $svc_name = '';
          if (!empty($svc_items)) {
              $svc_name = implode(', ', array_map(fn($s) => $s['product_name'], $svc_items));
          } elseif (!empty($ht['job_order_service'])) {
              $svc_name = $ht['job_order_service'];
          }
          
          if ($svc_name) {
              $has_any = true;
              if (!empty($merch_items)) echo '<div style="border-top:1px dashed #cbd5e1;margin:4px 0;"></div>';
              echo '<div style="font-weight:700;color:#1e40af;font-size:13px;line-height:1.35;white-space:normal !important;word-break:normal !important;overflow-wrap:break-word !important;word-wrap:break-word !important;"><i class="fas fa-wrench" style="color:#2563eb;font-size:11px;margin-right:4px;"></i>' . htmlspecialchars($svc_name) . '</div>';
          }
          
          if (!$has_any) {
              echo '<span style="color:#94a3b8;font-size:13px;">—</span>';
          }
          ?>
        </td>

        <!-- 5. Fees -->
        <td style="padding:10px 8px;vertical-align:middle;box-sizing:border-box;overflow:hidden;">
          <?php
          if ($sf_val > 0) {
              echo '<div style="color:#334155;white-space:nowrap;font-size:12px;line-height:1.3;">Svc: <strong style="color:#2563eb;font-weight:800;font-size:12.5px;">₱' . number_format($sf_val, 2) . '</strong></div>';
          }
          if ($lf_val > 0) {
              echo '<div style="color:#334155;margin-top:2px;white-space:nowrap;font-size:12px;line-height:1.3;">Labor: <strong style="color:#16a34a;font-weight:800;font-size:12.5px;">₱' . number_format($lf_val, 2) . '</strong></div>';
          }
          if ($sf_val <= 0 && $lf_val <= 0) {
              echo '<span style="color:#94a3b8;font-size:13px;">—</span>';
          }
          ?>
        </td>

        <!-- 6. Total & Payment -->
        <td style="padding:10px 8px;vertical-align:middle;box-sizing:border-box;">
          <div style="font-weight:900;font-size:15px;color:#002F70;white-space:nowrap;line-height:1.2;">₱<?= number_format((float)$ht['total_amount'], 2) ?></div>
          <div style="display:flex;align-items:center;gap:5px;margin-top:4px;flex-wrap:wrap;">
            <?php $h_pay = format_payment_for_record($ht); ?>
            <span style="color:#0f172a;font-weight:700;font-size:12px;"><?= htmlspecialchars($h_pay['payment_type']) ?></span>
            <?php if ($h_pay['payment_type'] === 'E-Wallet' && !empty($h_pay['provider'])): ?>
              <span style="font-size:11px;font-weight:700;color:#0284c7;background:#e0f2fe;padding:1px 5px;border-radius:3px;"><?= htmlspecialchars($h_pay['provider']) ?></span>
            <?php endif; ?>
            <span style="background:<?= $ht_ps==='paid'?'#dcfce7':'#fee2e2' ?>;color:<?= $ht_ps==='paid'?'#15803d':'#b91c1c' ?>;font-weight:800;font-size:11px;padding:2px 6px;border-radius:4px;border:1px solid <?= $ht_ps==='paid'?'#bbf7d0':'#fecaca' ?>;">
              <?= strtoupper(htmlspecialchars($ht['payment_status'] ?? 'PAID')) ?>
            </span>
          </div>
        </td>

        <!-- 7. Status -->
        <td style="padding:10px 4px;vertical-align:middle;text-align:center;box-sizing:border-box;">
          <?= $v_badge_html ?>
        </td>

        <!-- 8. Date & Time -->
        <td style="padding:10px 10px;vertical-align:middle;box-sizing:border-box;">
          <?php if (!empty($ht_date_only)): ?>
            <div style="font-size:13px;color:#0f172a;font-weight:800;line-height:1.25;white-space:nowrap;"><?= htmlspecialchars($ht_date_only) ?></div>
            <div style="font-size:12px;color:#475569;font-weight:700;margin-top:3px;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
              <i class="far fa-clock" style="font-size:11px;color:#64748b;"></i> <?= htmlspecialchars($ht_time_only) ?>
            </div>
          <?php else: ?>
            <span style="color:#94a3b8;font-size:13px;">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Pagination Footer -->
    <div id="histPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:16px 22px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13.5px; color:#334155; flex-wrap:wrap; gap:12px;">
      <div style="display:flex; align-items:center;">
        <span id="histShowingEntriesText" style="font-size:13.5px; color:#475569; font-weight:700;">Showing <?= empty($recent_merch) ? '0' : '1–'.min(10, count($recent_merch)) ?> of <?= count($recent_merch) ?> entries</span>
      </div>
      <div style="display:flex; align-items:center; gap:16px;">
        <div style="display:flex; align-items:center; gap:8px;">
          <label style="margin:0; font-weight:700; color:#475569; font-size:13.5px;">Rows per page:</label>
          <select id="histPerPage" onchange="histChangePerPage()" style="padding:5px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13.5px; font-weight:700; background:transparent !important; color:#0f172a; outline:none; cursor:pointer;">
            <option value="10" selected>10</option>
            <option value="20">20</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
          <button id="histPrevBtn" onclick="histGoPage(histState.page - 1)" 
                  style="width:36px; height:36px; background:#fff; border:1.5px solid #cbd5e1; border-radius:7px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; font-size:13px; transition: all 0.2s;"
                  onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
            <i class="fas fa-chevron-left"></i>
          </button>
          <span id="histPageLabel" style="color:#0f172a; font-size:13.5px; font-weight:700; padding:0 6px;">Page 1 of <?= max(1, ceil(count($recent_merch) / 10)) ?></span>
          <button id="histNextBtn" onclick="histGoPage(histState.page + 1)" 
                  style="width:36px; height:36px; background:#fff; border:1.5px solid #cbd5e1; border-radius:7px; cursor:<?= count($recent_merch) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($recent_merch) > 10 ? '#0f172a' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; font-size:13px; transition: all 0.2s;"
                  onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
            <i class="fas fa-chevron-right"></i>
          </button>
        </div>
      </div>
    </div>
<?php endif; ?>
  </div>
</div>
<script>
(function(){
var histState = { page: 1, per_page: 10 };

function histRender() {
  var rows = Array.from(document.querySelectorAll('#histTbody tr.hist-row-main'));
  var pp = histState.per_page || 10;
  var tot = rows.length;
  var tp = Math.max(1, Math.ceil(tot / pp));
  if (histState.page > tp) histState.page = tp;
  if (histState.page < 1) histState.page = 1;
  var p = histState.page;

  var start = (p - 1) * pp;
  var end   = p * pp;

  rows.forEach(function(r, i) {
    r.style.display = (i >= start && i < end) ? '' : 'none';
  });

  // Update entries counter
  var showingStart = tot === 0 ? 0 : start + 1;
  var showingEnd   = Math.min(end, tot);
  var entriesLbl   = document.getElementById('histShowingEntriesText');
  if (entriesLbl) {
    entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
  }

  var lbl = document.getElementById('histPageLabel');
  if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

  var prev = document.getElementById('histPrevBtn');
  var next = document.getElementById('histNextBtn');
  if (prev) {
    prev.disabled = (p <= 1);
    prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
    prev.style.color = prev.disabled ? '#cbd5e1' : '#475569';
  }
  if (next) {
    next.disabled = (p >= tp);
    next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
    next.style.color = next.disabled ? '#cbd5e1' : '#475569';
  }
}

window.histState = histState;
window.histGoPage = function(p) {
  var rows = document.querySelectorAll('#histTbody tr.hist-row-main');
  var tp = Math.max(1, Math.ceil(rows.length / (histState.per_page || 10)));
  if (p < 1 || p > tp) return;
  histState.page = p;
  histRender();
};
window.histChangePerPage = function() {
  var s = document.getElementById('histPerPage');
  if (s) histState.per_page = parseInt(s.value, 10);
  histState.page = 1;
  histRender();
};

histRender();
})();

// ── Petron Downward Custom Dropdowns for Transaction History ──
(function() {
    function setupHistPetronDD() {
        var selectors = [
            '#histFilterTxnType',
            '#histFilterCustType',
            '#histFilterPayment',
            '#histFilterPstatus',
            '#histFilterVstatus',
            '#histFilterShift'
        ];
        selectors.forEach(function(selId) {
            var select = document.querySelector(selId);
            if (!select || select.dataset.petronDownReady === '1') return;
            select.dataset.petronDownReady = '1';

            var wrap = document.createElement('div');
            wrap.className = 'petron-dropdown-wrap';
            if (select.name === 'txn_type') wrap.style.minWidth = '190px';
            else if (select.name === 'payment') wrap.style.minWidth = '155px';
            else if (select.name === 'cust_type' || select.name === 'shift') wrap.style.minWidth = '115px';
            else if (select.name === 'pstatus') wrap.style.minWidth = '140px';
            else if (select.name === 'vstatus') wrap.style.minWidth = '130px';
            else wrap.style.minWidth = '140px';

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'petron-dropdown-trigger';

            var label = document.createElement('span');
            label.className = 'petron-dropdown-label';

            var arrow = document.createElement('i');
            arrow.className = 'fas fa-chevron-down petron-dropdown-arrow';

            trigger.appendChild(label);
            trigger.appendChild(arrow);

            var menu = document.createElement('div');
            menu.className = 'petron-dropdown-menu';

            Array.from(select.options).forEach(function(option) {
                if (option.hidden) return;
                var item = document.createElement('div');
                item.className = 'petron-dropdown-item';
                item.dataset.value = option.value;
                item.textContent = option.textContent;
                item.addEventListener('click', function(e) {
                    e.stopPropagation();
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    syncLabel();
                    wrap.classList.remove('is-open');
                });
                menu.appendChild(item);
            });

            function syncLabel() {
                var sel = select.options[select.selectedIndex];
                label.textContent = sel ? sel.textContent.trim() : '';
                Array.from(menu.querySelectorAll('.petron-dropdown-item')).forEach(function(i) {
                    i.classList.toggle('is-selected', i.dataset.value === select.value);
                });
            }

            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                var willOpen = !wrap.classList.contains('is-open');
                document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) { w.classList.remove('is-open'); });
                if (willOpen) {
                    var rect = wrap.getBoundingClientRect();
                    menu.style.left = (rect.right + 10 > window.innerWidth) ? 'auto' : '0';
                    menu.style.right = (rect.right + 10 > window.innerWidth) ? '0' : 'auto';
                    wrap.classList.add('is-open');
                    var s = menu.querySelector('.petron-dropdown-item.is-selected');
                    if (s) s.scrollIntoView({ block: 'nearest' });
                }
            });

            select.addEventListener('change', syncLabel);
            select.classList.add('petron-dropdown-source');
            select.style.display = 'none';
            select.hidden = true;
            select.parentNode.insertBefore(wrap, select.nextSibling);
            wrap.appendChild(trigger);
            wrap.appendChild(menu);
            syncLabel();
        });

        if (!window.__petronDownCloseBoundHist) {
            window.__petronDownCloseBoundHist = true;
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.petron-dropdown-wrap')) {
                    document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) { w.classList.remove('is-open'); });
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.petron-dropdown-wrap.is-open').forEach(function(w) { w.classList.remove('is-open'); });
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupHistPetronDD);
    } else {
        setupHistPetronDD();
    }
})();
</script>
