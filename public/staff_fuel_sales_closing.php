<?php
/**
 * Official Petron Fuel Sales Closing Page
 * Staff enters raw numbers from external paper report, system automatically computes all derived totals.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
if (function_exists('petron_init_dynamic_timezone')) {
    petron_init_dynamic_timezone();
}
require_login();

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me         = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? []);
$station_id = function_exists('user_station_id') ? (int)user_station_id() : (int)($_SESSION['station_id'] ?? $me['station_id'] ?? 1);
if ($station_id <= 0) $station_id = 1;
$user_id    = (int)($_SESSION['user_id'] ?? $me['id'] ?? 0);
$user_name  = $_SESSION['full_name'] ?? $me['name'] ?? $_SESSION['username'] ?? 'JUDY LASTIMOSA';

$page_title = "Fuel Sales Closing";
include __DIR__ . '/../partials/header.php';

$__closing_hour = (int)date('G');
$__default_shift = ($__closing_hour >= 6 && $__closing_hour < 14) ? 'Shift 1' : 'Shift 2';
$report_date = $_GET['date'] ?? date('Y-m-d');
$shift       = $_GET['shift'] ?? $__default_shift;
?>

<div class="main-content" style="padding: 20px; background: #f8fafc; min-height: 100vh; box-sizing: border-box; overflow-x: hidden;">
    <style>
        .closing-container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
            padding-bottom: 12px;
            box-sizing: border-box;
            overflow-x: hidden;
        }
        .txn-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .txn-section-title h1 {
            font-size: 22px;
            font-weight: 800;
            color: #002F70;
            margin: 0 0 4px 0;
        }
        .txn-section-title p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }
        .closing-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
            width: 100%;
            box-sizing: border-box;
        }
        @media (max-width: 900px) {
            .closing-grid { grid-template-columns: 1fr; }
        }
        .closing-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            padding: 16px;
            box-sizing: border-box;
            min-width: 0;  /* prevent grid blowout */
            overflow: visible;
        }
        .closing-card.full-width {
            grid-column: 1 / -1;
        }
        .closing-card-header {
            font-size: 14px;
            font-weight: 700;
            color: #002F70;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 10px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge-tag {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 999px;
            background: #e2e8f0;
            color: #475569;
        }
        .form-row-custom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            gap: 8px;
        }
        .form-row-custom label {
            font-size: 12.5px;
            font-weight: 600;
            color: #334155;
            flex: 1;
            min-width: 0;
        }
        .form-row-custom input {
            width: 160px;
            min-width: 80px;
            padding: 7px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            text-align: right;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            transition: all 0.15s ease-in-out;
            box-sizing: border-box;
            flex-shrink: 0;
        }
        .form-row-custom input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }
        .form-row-custom input[readonly] {
            background: #f8fafc;
            color: #475569;
            border-color: #e2e8f0;
        }
        .form-row-custom.total-row {
            border-top: 1px dashed #cbd5e1;
            padding-top: 12px;
            margin-top: 12px;
        }
        .form-row-custom.total-row label {
            font-size: 13px;
            font-weight: 700;
            color: #002F70;
        }
        .form-row-custom.total-row input {
            font-size: 14px;
            font-weight: 700;
            color: #002F70;
            background: #f0f7ff;
            border-color: #93c5fd;
        }
        .overall-highlight {
            background: #f0f7ff;
            border-color: #bfdbfe;
        }
        .closing-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            table-layout: fixed;
        }
        .closing-table th {
            background: #002F70;
            color: #ffffff;
            font-weight: 700;
            padding: 8px 10px;
            text-align: left;
            border: 1px solid #001f4d;
            word-break: break-word;
        }
        .closing-table td {
            padding: 7px 10px;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            word-break: break-word;
        }
        .closing-table tr:nth-child(even) {
            background: #f8fafc;
        }
        .closing-table .total-tr td {
            font-weight: 800;
            background: #e8f0fe;
            border-top: 2px solid #002F70;
            color: #002F70;
        }
        /* Volume Sales Summary — use DIV rows to avoid global table CSS conflicts */
        .vol-summary-wrap {
            width: 100%;
            box-sizing: border-box;
            overflow: visible !important;
        }
        .vol-summary-header-row,
        .vol-summary-data-row,
        .vol-summary-total-row {
            display: flex !important;
            width: 100% !important;
            box-sizing: border-box !important;
            align-items: stretch !important;
            overflow: visible !important;
        }
        .vol-summary-header-row {
            background: #002F70 !important;
            border-radius: 6px 6px 0 0;
        }
        .vol-summary-data-row {
            border-bottom: 1px solid #e2e8f0;
        }
        .vol-summary-data-row:nth-child(even) {
            background: #f8fafc !important;
        }
        .vol-summary-total-row {
            background: #e8f0fe !important;
            border-top: 2px solid #002F70;
            border-radius: 0 0 6px 6px;
        }
        .vol-col-1 {
            flex: 0 0 38% !important;
            width: 38% !important;
            padding: 6px 6px !important;
            font-size: 11px !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
            min-width: 0;
        }
        .vol-col-2 {
            flex: 0 0 31% !important;
            width: 31% !important;
            padding: 6px 4px !important;
            font-size: 11px !important;
            text-align: right !important;
            box-sizing: border-box !important;
            white-space: nowrap !important;
            min-width: 0;
        }
        .vol-col-3 {
            flex: 0 0 31% !important;
            width: 31% !important;
            padding: 6px 4px !important;
            font-size: 11px !important;
            text-align: right !important;
            box-sizing: border-box !important;
            white-space: nowrap !important;
            min-width: 0;
        }
        .vol-summary-header-row .vol-col-1,
        .vol-summary-header-row .vol-col-2,
        .vol-summary-header-row .vol-col-3 {
            color: #ffffff !important;
            font-weight: 700 !important;
            font-size: 11px !important;
        }
        .table-scroll-wrap {
            width: 100%;
            overflow-x: auto;
        }
        .action-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            margin-top: 12px;
            margin-bottom: 12px;
            padding: 4px 0;
            background: transparent;
            border: none;
            box-shadow: none;
            flex-wrap: wrap;
        }
        .btn-closing {
            padding: 10px 22px;
            font-size: 13px;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-save { background: #002F70; color: #ffffff; box-shadow: 0 2px 6px rgba(0,47,112,0.3); }
        .btn-save:hover { background: #001f4d; box-shadow: 0 4px 12px rgba(0,47,112,0.4); }
        .btn-cancel { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .btn-cancel:hover { background: #e2e8f0; }
    </style>

    <div class="closing-container">
        <!-- Header -->
        <div class="txn-section-header">
            <div class="txn-section-title">
                <div>
                    <h1>FUEL SALES CLOSING</h1>
                    <p>Shift: <strong style="color:#0f172a;"><?= htmlspecialchars($shift) ?></strong> | Date: <strong><?= htmlspecialchars($report_date) ?></strong></p>
                </div>
            </div>
            <div>
                <a href="staff_transactions_hub.php?section=fuel" class="btn-closing btn-cancel">
                    <i class="fas fa-arrow-left"></i> Back to Meter Readings
                </a>
            </div>
        </div>

        <form id="fuelClosingForm">
            <input type="hidden" name="report_date" value="<?= htmlspecialchars($report_date) ?>">
            <input type="hidden" name="shift" value="<?= htmlspecialchars($shift) ?>">

            <div class="closing-grid">
                <!-- Meter Reading (Full Width Table) -->
                <div class="closing-card full-width">
                    <div class="closing-card-header">
                        <span><i class="fas fa-gas-pump me-1"></i> Meter Reading</span>
                    </div>
                    <div class="table-scroll-wrap">
                        <table class="closing-table" id="fuelSalesSummaryTable">
                            <thead>
                                <tr>
                                    <th>Pump / Nozzle Name</th>
                                    <th>Fuel Type</th>
                                    <th style="text-align:right;">Beginning</th>
                                    <th style="text-align:right;">Ending</th>
                                    <th style="text-align:right;">Calib (L)</th>
                                    <th style="text-align:right;">Volume Liters (L)</th>
                                    <th style="text-align:right;">Price / L</th>
                                    <th style="text-align:right;">Amount (₱)</th>
                                </tr>
                            </thead>
                            <tbody id="fuelSalesSummaryTbody">
                                <tr><td colspan="8" style="text-align:center; padding:20px; color:#64748b;">Loading meter readings data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Volume Sales Summary -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <span><i class="fas fa-filter me-1"></i> Volume Sales Summary</span>
                    </div>
                    <div class="vol-summary-wrap">
                        <!-- Header Row -->
                        <div class="vol-summary-header-row">
                            <div class="vol-col-1">Fuel Type</div>
                            <div class="vol-col-2">Volume (L)</div>
                            <div class="vol-col-3">Amount (₱)</div>
                        </div>
                        <!-- Data Rows populated via JS -->
                        <div id="volSalesTbody"></div>
                        <!-- Total Row -->
                        <div class="vol-summary-total-row" id="volSalesFoot" style="display:none;">
                            <div class="vol-col-1" style="font-weight:800; font-size:11px; color:#002F70;"><strong>TOTAL</strong></div>
                            <div class="vol-col-2" style="font-weight:800; font-size:11px; color:#15803d;" id="volSalesTotalLiters"></div>
                            <div class="vol-col-3" style="font-weight:800; font-size:11px; color:#002F70;" id="volSalesTotalAmt"></div>
                        </div>
                    </div>
                </div>

                <!-- Volume & Amount Summary -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <span><i class="fas fa-chart-pie me-1"></i> Volume &amp; Amount Summary</span>
                    </div>
                    <div class="form-row-custom total-row" style="border:none; margin-top:0; padding-top:0;">
                        <label style="font-size:13.5px; font-weight:700;">TOTAL LITERS</label>
                        <input type="text" id="summary_total_liters" readonly value="0.00 L" style="font-size:15px; font-weight:800; color:#15803d; width:180px;">
                    </div>
                    <div class="form-row-custom total-row" style="margin-top:16px;">
                        <label style="font-size:13.5px; font-weight:700;">TOTAL AMOUNT</label>
                        <input type="text" id="summary_total_amount" readonly value="₱0.00" style="font-size:16px; font-weight:800; color:#002F70; width:180px; background:#e8f0fe;">
                    </div>
                </div>

                <!-- Tank Liters Summary -->
                <div class="closing-card full-width">
                    <div class="closing-card-header">
                        <span><i class="fas fa-database me-1"></i> Tank Liters Summary</span>
                    </div>
                    <table class="closing-table">
                        <thead>
                            <tr>
                                <th>Tank / Pump Name</th>
                                <th style="text-align:right;">Liters Sold (L)</th>
                            </tr>
                        </thead>
                        <tbody id="tankSummaryTbody">
                            <!-- Populated via JS -->
                        </tbody>
                        <tfoot>
                            <tr class="total-tr">
                                <td>TOTAL TANK LITERS</td>
                                <td style="text-align:right;" id="tankTotalLiters">0.00 L</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php
                $role = role_key($_SESSION['user']['role'] ?? $_SESSION['role'] ?? 'staff');
                $is_mgr_admin = in_array($role, ['manager', 'admin', 'superadmin', 'developer']);

                $current_shift_str = strtolower(trim($shift));
                $is_s1 = (strpos($current_shift_str, '1') !== false || strpos($current_shift_str, 'first') !== false);
                $is_s2 = (strpos($current_shift_str, '2') !== false || strpos($current_shift_str, 'second') !== false);

                // Default to S1 if ambiguous
                if (!$is_s1 && !$is_s2) {
                    $is_s1 = true;
                }
                ?>

                <!-- Cash Summary -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <span><i class="fas fa-money-bill-wave me-1"></i> Cash Summary</span>
                    </div>
                    <?php if ($is_mgr_admin || $is_s1): ?>
                    <div class="form-row-custom" id="row_cash_shift1">
                        <label>Shift 1 Cash (₱)</label>
                        <input type="text" id="cash_shift1" name="cash_shift1" value="" placeholder="0.00" onfocus="formatClosingFocus(this)" oninput="formatClosingInput(this); calculateClosingTotals()" onblur="formatClosingBlur(this); calculateClosingTotals()" onkeydown="handleClosingKeydown(event, this)" autocomplete="off">
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="cash_shift1" name="cash_shift1" value="">
                    <?php endif; ?>

                    <?php if ($is_mgr_admin || $is_s2): ?>
                    <div class="form-row-custom" id="row_cash_shift2">
                        <label>Shift 2 Cash (₱)</label>
                        <input type="text" id="cash_shift2" name="cash_shift2" value="" placeholder="0.00" onfocus="formatClosingFocus(this)" oninput="formatClosingInput(this); calculateClosingTotals()" onblur="formatClosingBlur(this); calculateClosingTotals()" onkeydown="handleClosingKeydown(event, this)" autocomplete="off">
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="cash_shift2" name="cash_shift2" value="">
                    <?php endif; ?>

                    <div class="form-row-custom total-row">
                        <label>Total Cash (₱)</label>
                        <input type="text" id="total_cash" name="total_cash" readonly value="" placeholder="0.00" style="font-weight:800; color:#002F70;">
                    </div>
                </div>

                <!-- A/R Summary -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <span><i class="fas fa-file-invoice me-1"></i> A/R Summary</span>
                    </div>
                    <?php if ($is_mgr_admin || $is_s1): ?>
                    <div class="form-row-custom" id="row_ar_shift1">
                        <label>Shift 1 A/R (₱)</label>
                        <input type="text" id="ar_shift1" name="ar_shift1" value="" placeholder="0.00" onfocus="formatClosingFocus(this)" oninput="formatClosingInput(this); calculateClosingTotals()" onblur="formatClosingBlur(this); calculateClosingTotals()" onkeydown="handleClosingKeydown(event, this)" autocomplete="off">
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="ar_shift1" name="ar_shift1" value="">
                    <?php endif; ?>

                    <?php if ($is_mgr_admin || $is_s2): ?>
                    <div class="form-row-custom" id="row_ar_shift2">
                        <label>Shift 2 A/R (₱)</label>
                        <input type="text" id="ar_shift2" name="ar_shift2" value="" placeholder="0.00" onfocus="formatClosingFocus(this)" oninput="formatClosingInput(this); calculateClosingTotals()" onblur="formatClosingBlur(this); calculateClosingTotals()" onkeydown="handleClosingKeydown(event, this)" autocomplete="off">
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="ar_shift2" name="ar_shift2" value="">
                    <?php endif; ?>

                    <div class="form-row-custom total-row">
                        <label>Total A/R (₱)</label>
                        <input type="text" id="total_ar" name="total_ar" readonly value="" placeholder="0.00" style="font-weight:800; color:#002F70;">
                    </div>
                </div>

                <!-- Overall Summary -->
                <div class="closing-card overall-highlight">
                    <div class="closing-card-header">
                        <span><i class="fas fa-calculator me-1"></i> Overall Summary</span>
                    </div>
                    <div class="form-row-custom">
                        <label>TOTAL FUEL AMOUNT SALES (₱)</label>
                        <input type="text" id="ov_total_fuel_amount" readonly value="0.00" style="font-weight:800; color:#002F70;">
                    </div>

                    <?php if ($is_mgr_admin || $is_s1): ?>
                    <div class="form-row-custom" id="row_ov_ar_shift1">
                        <label>LESS: A/R SHIFT 1 (₱)</label>
                        <input type="text" id="ov_ar_shift1" readonly value="0.00" style="color:#dc2626;">
                    </div>
                    <?php endif; ?>

                    <?php if ($is_mgr_admin || $is_s2): ?>
                    <div class="form-row-custom" id="row_ov_ar_shift2">
                        <label>LESS: A/R SHIFT 2 (₱)</label>
                        <input type="text" id="ov_ar_shift2" readonly value="0.00" style="color:#dc2626;">
                    </div>
                    <?php endif; ?>

                    <div class="form-row-custom total-row">
                        <label>NET CASH / REMAINING AMOUNT (₱)</label>
                        <input type="text" id="net_sales" name="net_sales" readonly value="0.00" style="font-size:16px; font-weight:800; color:#15803d; background:#dcfce7;">
                    </div>
                </div>

                <!-- Total Cash in Bank -->
                <div class="closing-card overall-highlight">
                    <div class="closing-card-header">
                        <span><i class="fas fa-university me-1"></i> Total Cash in Bank</span>
                    </div>
                    <div class="form-row-custom total-row" style="border:none; margin-top:20px; padding-top:0;">
                        <label style="font-size:16px;">TOTAL CASH IN BANK (₱)</label>
                        <input type="text" id="total_cash_bank" name="total_cash_bank" readonly value="0.00" style="font-size:18px; font-weight:800; color:#002F70; width:220px; background:#e8f0fe;">
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="action-bar">
                <a href="staff_transactions_hub.php?section=fuel" class="btn-closing btn-cancel">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="button" onclick="saveClosingData()" class="btn-closing btn-save">
                    <i class="fas fa-save"></i> Save Closing
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    fetchSummary();
});

function parseVal(val) {
    if (!val) return 0;
    const clean = val.toString().replace(/,/g, '').trim();
    const num = parseFloat(clean);
    return isNaN(num) ? 0 : num;
}

function formatNum(num) {
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatClosingFocus(input) {
    const val = input.value.trim();
    if (val === '0.00' || val === '0' || val === '0.0') {
        input.value = '';
    }
}

function formatClosingInput(input) {
    let val = input.value;
    let parts = val.replace(/[^0-9.]/g, '').split('.');
    if (parts.length > 2) {
        val = parts[0] + '.' + parts.slice(1).join('');
    } else {
        val = val.replace(/[^0-9.]/g, '');
    }
    input.value = val;
}

function formatClosingBlur(input) {
    const clean = input.value.toString().replace(/,/g, '').trim();
    if (clean === '' || isNaN(parseFloat(clean))) {
        input.value = '';
    } else {
        const num = parseFloat(clean);
        input.value = formatNum(num);
    }
}

function handleClosingKeydown(e, input) {
    const key = e.key;
    if (key !== 'Enter' && key !== 'ArrowDown' && key !== 'ArrowUp') {
        return;
    }

    e.preventDefault();
    if (typeof formatClosingBlur === 'function') formatClosingBlur(input);
    if (typeof calculateClosingTotals === 'function') calculateClosingTotals();

    const editableInputs = Array.from(document.querySelectorAll('#fuelClosingForm input:not([readonly]):not([type="hidden"])'));
    const currentIdx = editableInputs.indexOf(input);
    let targetInput = null;

    if (key === 'Enter' || key === 'ArrowDown') {
        if (currentIdx !== -1 && currentIdx < editableInputs.length - 1) {
            targetInput = editableInputs[currentIdx + 1];
        }
    } else if (key === 'ArrowUp') {
        if (currentIdx > 0) {
            targetInput = editableInputs[currentIdx - 1];
        }
    }

    if (targetInput) {
        targetInput.focus();
        if (typeof formatClosingFocus === 'function') {
            formatClosingFocus(targetInput);
        }
        if (typeof targetInput.scrollIntoView === 'function') {
            targetInput.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }
}

async function fetchSummary() {
    const reportDate = '<?= htmlspecialchars($report_date) ?>';
    const shift      = '<?= htmlspecialchars($shift, ENT_QUOTES) ?>';
    try {
        const response = await fetch(`staff_fuel_sales_closing_handler.php?action=get_summary&date=${encodeURIComponent(reportDate)}&shift=${encodeURIComponent(shift)}`);
        const result = await response.json();
        
        if (result.success) {
            // A. Meter Reading Table
            const meterRows = result.meter_rows || [];
            const tbody = document.getElementById('fuelSalesSummaryTbody');
            tbody.innerHTML = '';

            if (meterRows.length > 0) {
                meterRows.forEach(r => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="font-weight:700;">${r.pump_name || r.fuel_type}</td>
                        <td>${r.fuel_type}</td>
                        <td style="text-align:right;">${formatNum(parseFloat(r.beginning_reading))}</td>
                        <td style="text-align:right;">${formatNum(parseFloat(r.ending_reading))}</td>
                        <td style="text-align:right; color:#d97706;">${formatNum(parseFloat(r.calibration))}</td>
                        <td style="text-align:right; font-weight:700; color:#15803d;">${formatNum(parseFloat(r.liters_sold))} L</td>
                        <td style="text-align:right;">₱${formatNum(parseFloat(r.price_per_liter))}</td>
                        <td style="text-align:right; font-weight:800; color:#002F70;">₱${formatNum(parseFloat(r.total_amount))}</td>
                    `;
                    tbody.appendChild(tr);
                });
            } else {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:20px; color:#64748b; font-style:italic;">No meter readings encoded for this date. Default fuel types loaded.</td></tr>`;
            }

            // B. Volume Sales Summary — DIV rows (immune to global table CSS overrides)
            const byFuel = result.by_fuel || {};
            const volTbody = document.getElementById('volSalesTbody');
            volTbody.innerHTML = '';
            let sumVol = 0;
            let sumAmt = 0;

            Object.keys(byFuel).forEach(fKey => {
                const vol = byFuel[fKey].liters || 0;
                const amt = byFuel[fKey].amount || 0;
                sumVol += vol;
                sumAmt += amt;
                const row = document.createElement('div');
                row.className = 'vol-summary-data-row';
                row.innerHTML = `
                    <div class="vol-col-1" style="font-weight:700; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${fKey}</div>
                    <div class="vol-col-2" style="font-weight:700; color:#15803d; white-space:nowrap;">${formatNum(vol)} L</div>
                    <div class="vol-col-3" style="font-weight:800; color:#002F70; white-space:nowrap;">&#x20b1;${formatNum(amt)}</div>
                `;
                volTbody.appendChild(row);
            });

            // Show totals footer in Volume Sales Summary (div-based row, needs display:flex)
            const volFoot = document.getElementById('volSalesFoot');
            const volFootL = document.getElementById('volSalesTotalLiters');
            const volFootA = document.getElementById('volSalesTotalAmt');
            if (volFoot) {
                if (sumVol > 0 || sumAmt > 0) {
                    if (volFootL) volFootL.textContent = formatNum(sumVol) + ' L';
                    if (volFootA) volFootA.textContent = '\u20b1' + formatNum(sumAmt);
                    volFoot.style.display = 'flex';
                } else {
                    volFoot.style.display = 'none';
                }
            }

            // C. Volume & Amount Summary Totals
            const totalFuelSales = result.totals.total_fuel_sales || sumAmt;
            const totalLitersSold = result.totals.total_liters_sold || sumVol;
            document.getElementById('summary_total_liters').value = formatNum(totalLitersSold) + ' L';
            document.getElementById('summary_total_amount').value = '₱' + formatNum(totalFuelSales);

            // D. Tank Liters Summary Table
            const tankSummary = result.tank_summary || {};
            const tankTbody = document.getElementById('tankSummaryTbody');
            tankTbody.innerHTML = '';
            let sumTankLiters = 0;

            Object.keys(tankSummary).forEach(tName => {
                const tVol = tankSummary[tName] || 0;
                sumTankLiters += tVol;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight:700;">${tName}</td>
                    <td style="text-align:right; font-weight:700; color:#15803d;">${formatNum(tVol)} L</td>
                `;
                tankTbody.appendChild(tr);
            });
            document.getElementById('tankTotalLiters').textContent = formatNum(sumTankLiters) + ' L';

            // Cash Summary & A/R Summary are strictly manual input — always start blank
            if (document.getElementById('cash_shift1')) document.getElementById('cash_shift1').value = '';
            if (document.getElementById('cash_shift2')) document.getElementById('cash_shift2').value = '';
            if (document.getElementById('ar_shift1'))   document.getElementById('ar_shift1').value   = '';
            if (document.getElementById('ar_shift2'))   document.getElementById('ar_shift2').value   = '';

            calculateClosingTotals();
        }
    } catch (e) {
        console.error('Failed to load summary data:', e);
    }
}

function calculateClosingTotals() {
    const elCash1 = document.getElementById('cash_shift1');
    const elCash2 = document.getElementById('cash_shift2');
    const hasCashInput = (elCash1 && elCash1.value.trim() !== '') || (elCash2 && elCash2.value.trim() !== '');
    const cashS1 = elCash1 ? parseVal(elCash1.value) : 0;
    const cashS2 = elCash2 ? parseVal(elCash2.value) : 0;
    const totalCash = cashS1 + cashS2;
    if (document.getElementById('total_cash')) {
        document.getElementById('total_cash').value = hasCashInput ? formatNum(totalCash) : '';
    }

    const elAr1 = document.getElementById('ar_shift1');
    const elAr2 = document.getElementById('ar_shift2');
    const hasArInput = (elAr1 && elAr1.value.trim() !== '') || (elAr2 && elAr2.value.trim() !== '');
    const arS1 = elAr1 ? parseVal(elAr1.value) : 0;
    const arS2 = elAr2 ? parseVal(elAr2.value) : 0;
    const totalAr = arS1 + arS2;
    if (document.getElementById('total_ar')) {
        document.getElementById('total_ar').value = hasArInput ? formatNum(totalAr) : '';
    }

    const totalFuelAmountElem = document.getElementById('summary_total_amount');
    const totalFuelAmountStr  = totalFuelAmountElem ? totalFuelAmountElem.value.replace(/[^0-9.]/g, '') : '0';
    const totalFuelAmount     = parseFloat(totalFuelAmountStr) || 0;

    if (document.getElementById('ov_total_fuel_amount')) {
        document.getElementById('ov_total_fuel_amount').value = formatNum(totalFuelAmount);
    }
    if (document.getElementById('ov_ar_shift1')) {
        document.getElementById('ov_ar_shift1').value = formatNum(arS1);
    }
    if (document.getElementById('ov_ar_shift2')) {
        document.getElementById('ov_ar_shift2').value = formatNum(arS2);
    }

    const netSales = totalFuelAmount - arS1 - arS2;
    if (document.getElementById('net_sales')) {
        document.getElementById('net_sales').value = formatNum(netSales);
    }

    if (document.getElementById('total_cash_bank')) {
        document.getElementById('total_cash_bank').value = formatNum(totalCash);
    }
}

async function saveClosingData() {
    const form = document.getElementById('fuelClosingForm');
    const formData = new FormData(form);
    
    // Clean formatted numbers before submitting
    const numberFields = ['cash_shift1', 'cash_shift2', 'total_cash', 'ar_shift1', 'ar_shift2', 'total_ar', 'net_sales', 'total_cash_bank'];
    numberFields.forEach(field => {
        const input = document.getElementById(field);
        if (input) {
            formData.set(field, parseVal(input.value).toFixed(2));
        }
    });

    const totalFuelAmountStr = document.getElementById('summary_total_amount').value.replace(/[^0-9.]/g, '');
    const totalLitersStr     = document.getElementById('summary_total_liters').value.replace(/[^0-9.]/g, '');
    formData.set('total_fuel_sales', (parseFloat(totalFuelAmountStr) || 0).toFixed(2));
    formData.set('total_liters', (parseFloat(totalLitersStr) || 0).toFixed(2));

    formData.append('action', 'save_closing');

    try {
        const response = await fetch('staff_fuel_sales_closing_handler.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            try {
                sessionStorage.removeItem('petron_fuel_reading_cache');
                sessionStorage.removeItem('petron_fuel_readings_submitted');
                sessionStorage.removeItem('petron_fuel_reading_shift');
                sessionStorage.removeItem('petron_fuel_reading_date');
            } catch(e) {}
            const targetUrl = result.report_url || 'staff_transactions_hub.php?section=fuel&closing_saved=1';
            alert('<i class="fas fa-check-circle"></i> Fuel Sales Closing saved successfully.');
            window.location.href = targetUrl;
        } else {
            alert('Error: ' + (result.message || 'Failed to save closing.'));
        }
    } catch (e) {
        alert('Server error while saving closing: ' + e.message);
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
