<?php
/**
 * ADMIN REPORTS RENDER LAYER
 * Renders the exact UI tables and summaries for all 7 Admin Report Categories
 */

if (!defined('PETRON_SYSTEM')) {
    define('PETRON_SYSTEM', true);
}

require_once __DIR__ . '/../../backend/lib.php';

if (!function_exists('ard_fmt_12h_datetime')) {
    function ard_fmt_12h_datetime(?string $raw_dt): string {
        if (empty($raw_dt) || $raw_dt === 'N/A' || $raw_dt === '—') return '—';
        $ts = strtotime($raw_dt);
        if (!$ts) return htmlspecialchars($raw_dt);
        return date('Y-m-d h:i:s A', $ts);
    }
}

function renderAdminReportContent(string $cat, string $tab, array $report_data): void {
    $rows = $report_data['rows'] ?? [];

    switch ($cat) {

        // =========================================================================
        // 1. SALES REPORTS
        // =========================================================================
        case 'sales':
            if ($tab === 'fuel_sales') {
                $ugt_rows         = $report_data['ugt_rows']         ?? [];
                $ugt_summary      = $report_data['ugt_summary']      ?? [];
                $fuel_summary     = $report_data['fuel_summary']     ?? [];
                $recon            = $report_data['reconciliation']   ?? [];
                $tank_summary     = $report_data['tank_summary']     ?? [];
                $tank_ugt_summary = $report_data['tank_ugt_summary'] ?? [];
                $closing_summary  = $report_data['closing_summary']  ?? [];
                $shift_breakdown  = $report_data['shift_breakdown']  ?? [];
                $variance         = $report_data['variance']         ?? [];
                $fuel_adjustments = $report_data['fuel_adjustments']  ?? [];
                ?>

                <?php if (!empty($report_data['pending_approval_notice'])): ?>
                <div class="no-print" style="background:#fffbeb; border:1.5px solid #fde68a; border-radius:8px; padding:12px 16px; margin-bottom:18px; display:flex; align-items:center; gap:12px; color:#92400e; font-size:13px;">
                    <i class="fas fa-clock" style="font-size:22px; color:#f59e0b; flex-shrink:0;"></i>
                    <div>
                        <strong>Notice — Pending Approval:</strong> <?= htmlspecialchars($report_data['pending_approval_notice']) ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- =====================================================
                     SECTION 1: FUEL METER READING TABLE (UGT DAILY READINGS)
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-tachometer-alt"></i> Fuel Meter Reading Table (UGT Daily Readings)</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:10%;text-align:left;">Date</th>
                                <th style="width:9%;text-align:left;">UGT No.</th>
                                <th style="width:12%;text-align:left;">Fuel Type</th>
                                <th style="width:12%;text-align:right;">Beginning Reading</th>
                                <th style="width:12%;text-align:right;">Ending Reading</th>
                                <th style="width:11%;text-align:right;">Total Calibration (L)</th>
                                <th style="width:11%;text-align:right;">Net Volume Sold (L)</th>
                                <th style="width:10%;text-align:right;">Selling Price/L</th>
                                <th style="width:13%;text-align:right;">Total Fuel Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $grand_beg = 0; $grand_end = 0; $grand_calib = 0; $grand_vol = 0; $grand_sales = 0;
                            foreach ($ugt_rows as $r):
                                $beg       = (float)$r['beginning_reading'];
                                $end       = (float)$r['ending_reading'];
                                $calib     = (float)$r['total_calibration'];
                                $net_vol   = (float)$r['net_volume_sold'];
                                $t_sales   = (float)$r['total_fuel_sales'];

                                $grand_beg   += $beg;
                                $grand_end   += $end;
                                $grand_calib += $calib;
                                $grand_vol   += $net_vol;
                                $grand_sales += $t_sales;
                            ?>
                            <tr>
                                <td style="text-align:left;"><?= date('M d, Y', strtotime($r['report_date'])) ?></td>
                                <td style="text-align:left;"><strong><?= htmlspecialchars($r['ugt_no']) ?></strong></td>
                                <td style="text-align:left;"><?= htmlspecialchars($r['fuel_type']) ?></td>
                                <td style="text-align:right;"><?= number_format($beg, 2) ?></td>
                                <td style="text-align:right;"><?= number_format($end, 2) ?></td>
                                <td style="text-align:right;"><?= number_format($calib, 2) ?> L</td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($net_vol, 2) ?> L</td>
                                <td style="text-align:right;">₱<?= number_format((float)$r['price_per_liter'], 2) ?></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($t_sales, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($ugt_rows)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted"><i class="fas fa-gas-pump me-2 opacity-50"></i>No fuel transaction records found for this period.</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="fw-bold" style="text-align:left;">TOTALS</td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($grand_beg, 2) ?></td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($grand_end, 2) ?></td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($grand_calib, 2) ?> L</td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($grand_vol, 2) ?> L</td>
                                <td></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($grand_sales, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- =====================================================
                     SECTION 2: FUEL SALES SUMMARY
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-chart-bar"></i> Fuel Sales Summary</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Fuel Type</th>
                                <th style="text-align:right;">Liters Sold</th>
                                <th style="text-align:right;">Fuel Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sum_vol = 0; $sum_total = 0; 
                            foreach ($fuel_summary as $s): 
                                $sum_vol   += (float)$s['total_volume'];
                                $sum_total += (float)$s['total_sales']; 
                            ?>
                            <tr>
                                <td style="text-align:left;"><strong><?= htmlspecialchars($s['fuel_type']) ?></strong></td>
                                <td style="text-align:right;" class="fw-bold text-success"><?= number_format((float)$s['total_volume'], 2) ?> L</td>
                                <td style="text-align:right;" class="fw-bold text-primary">₱<?= number_format((float)$s['total_sales'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($fuel_summary)): ?>
                            <tr><td colspan="3" class="text-center py-3 text-muted">No fuel sales summary records available for this period.</td></tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:800; background:#e8f0fe;">
                                <td style="text-align:left; text-transform:uppercase;">TOTAL FUEL SALES SUMMARY</td>
                                <td style="text-align:right;" class="fw-bold text-success"><?= number_format($sum_vol, 2) ?> L</td>
                                <td style="text-align:right;" class="fw-bold text-primary">₱<?= number_format($sum_total, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- =====================================================
                     SECTION 3: VOLUME & AMOUNT SUMMARY
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-calculator"></i> Volume & Amount Summary</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:650px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Summary</th>
                                <th style="text-align:right;">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="width:60%;font-weight:700;color:#334155;text-align:left;">Total Liters Sold</td>
                                <td style="font-weight:700;text-align:right;color:#15803d;font-size:13px;"><?= number_format((float)($recon['total_volume_sold'] ?? $sum_vol), 2) ?> L</td>
                            </tr>
                            <tr style="background:#f8fafc;">
                                <td style="width:60%;font-weight:700;color:#334155;text-align:left;">Total Fuel Sales</td>
                                <td style="font-weight:800;text-align:right;color:#002F6C;font-size:14px;">₱<?= number_format((float)($recon['total_fuel_sales'] ?? $sum_total), 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- =====================================================
                     SECTION 5: TANK LITERS SUMMARY
                     ===================================================== -->
                <?php if (!empty($tank_ugt_summary)): ?>
                <div class="rpt-section-heading"><i class="fas fa-oil-can"></i> Tank Liters Summary</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:750px; width:100%;">
                        <thead>
                            <tr>
                                <th style="width:65%;text-align:left;">Tank / Pump Name</th>
                                <th class="text-end" style="width:35%;text-align:right;">Liters Sold (L)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $tot_tank_liters = 0;
                            foreach ($tank_ugt_summary as $t_name => $t_liters):
                                $tot_tank_liters += (float)$t_liters;
                            ?>
                            <tr>
                                <td style="width:65%;text-align:left; font-weight:700;"><?= htmlspecialchars($t_name) ?></td>
                                <td class="text-end fw-bold text-success" style="width:35%;text-align:right;"><?= number_format((float)$t_liters, 2) ?> L</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:800; background:#e8f0fe;">
                                <td style="width:65%;text-align:left;">TOTAL TANK LITERS</td>
                                <td class="text-end fw-bold text-primary" style="width:35%;text-align:right;"><?= number_format($tot_tank_liters, 2) ?> L</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>

                <!-- =====================================================
                     SECTION 6: FUEL SALES CLOSING SUMMARY
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-cash-register"></i> Fuel Sales Closing Summary</div>
                <div class="mb-4" style="background:#ffffff; padding:14px; border:1px solid #cbd5e1; border-radius:8px;">
                    <?php
                    $c_fuel    = (float)($closing_summary['total_fuel_sales'] ?? $overall_summary['total_fuel_sales'] ?? 0);
                    $c_cash1   = (float)($closing_summary['cash_shift1'] ?? 0);
                    $c_cash2   = (float)($closing_summary['cash_shift2'] ?? 0);
                    $c_totcash = (float)($closing_summary['total_cash'] ?? 0);
                    $c_ar1     = (float)($closing_summary['ar_shift1'] ?? 0);
                    $c_ar2     = (float)($closing_summary['ar_shift2'] ?? 0);
                    $c_totar   = (float)($closing_summary['total_ar'] ?? 0);
                    $c_net     = (float)($closing_summary['net_sales'] ?? ($c_fuel - $c_ar1 - $c_ar2));
                    $c_bank    = (float)($closing_summary['total_cash_bank'] ?? $c_totcash);
                    ?>
                    <!-- Cash Summary & A/R Summary (Side-by-Side) -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                        <div>
                            <h4 style="font-size:13px; font-weight:700; color:#002F6C; margin:0 0 6px 0; text-transform:uppercase;"><i class="fas fa-money-bill-wave me-1"></i> Cash Summary</h4>
                            <table class="rpt-table align-middle" style="width:100%;">
                                <thead>
                                    <tr style="background:#f1f5f9; color:#334155;">
                                        <th style="padding:6px 10px; text-align:left;">Shift</th>
                                        <th style="padding:6px 10px; text-align:right;">Amount (₱)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td style="padding:6px 10px; text-align:left;">Shift 1</td><td style="padding:6px 10px; text-align:right;">₱<?= number_format($c_cash1, 2) ?></td></tr>
                                    <tr><td style="padding:6px 10px; text-align:left;">Shift 2</td><td style="padding:6px 10px; text-align:right;">₱<?= number_format($c_cash2, 2) ?></td></tr>
                                    <tr style="font-weight:700; background:#f8fafc;"><td style="text-align:left;">Total Cash</td><td style="text-align:right; color:#002F6C;" class="fw-bold text-success">₱<?= number_format($c_totcash, 2) ?></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <h4 style="font-size:13px; font-weight:700; color:#002F6C; margin:0 0 6px 0; text-transform:uppercase;"><i class="fas fa-file-invoice-dollar me-1"></i> Accounts Receivable Summary</h4>
                            <table class="rpt-table align-middle" style="width:100%;">
                                <thead>
                                    <tr style="background:#f1f5f9; color:#334155;">
                                        <th style="padding:6px 10px; text-align:left;">Shift</th>
                                        <th style="padding:6px 10px; text-align:right;">Amount (₱)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td style="padding:6px 10px; text-align:left;">Shift 1</td><td style="padding:6px 10px; text-align:right;">₱<?= number_format($c_ar1, 2) ?></td></tr>
                                    <tr><td style="padding:6px 10px; text-align:left;">Shift 2</td><td style="padding:6px 10px; text-align:right;">₱<?= number_format($c_ar2, 2) ?></td></tr>
                                    <tr style="font-weight:700; background:#f8fafc;"><td style="text-align:left;">Total A/R</td><td style="text-align:right; color:#002F6C;" class="fw-bold text-primary">₱<?= number_format($c_totar, 2) ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Overall Summary -->
                    <h4 style="font-size:13px; font-weight:700; color:#002F6C; margin:10px 0 6px 0; text-transform:uppercase;"><i class="fas fa-calculator me-1"></i> Overall Financial Summary</h4>
                    <table class="rpt-table align-middle" style="width:100%;">
                        <thead>
                            <tr style="background:#f1f5f9; color:#334155;">
                                <th style="padding:6px 10px; text-align:left;">Field</th>
                                <th style="padding:6px 10px; text-align:right;">Value (₱)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td style="padding:6px 10px; font-weight:700; text-align:left;">TOTAL FUEL AMOUNT SALES</td><td style="padding:6px 10px; text-align:right; font-weight:700; color:#002F6C;">₱<?= number_format($c_fuel, 2) ?></td></tr>
                            <tr><td style="padding:6px 10px; text-align:left;">LESS: A/R SHIFT 1</td><td style="padding:6px 10px; text-align:right;" class="text-danger">- ₱<?= number_format($c_ar1, 2) ?></td></tr>
                            <tr><td style="padding:6px 10px; text-align:left;">LESS: A/R SHIFT 2</td><td style="padding:6px 10px; text-align:right;" class="text-danger">- ₱<?= number_format($c_ar2, 2) ?></td></tr>
                            <tr style="font-weight:700; background:#f0fdf4;"><td style="padding:6px 10px; color:#15803d; text-align:left;">NET CASH / REMAINING AMOUNT</td><td style="padding:6px 10px; text-align:right;" class="fw-bold text-success">₱<?= number_format($c_net, 2) ?></td></tr>
                            <tr style="font-weight:800; background:#e0f2fe;"><td style="padding:8px 10px; font-size:13px; color:#0369a1; text-align:left;">TOTAL CASH IN BANK</td><td style="padding:8px 10px; text-align:right; font-size:14px;" class="fw-bold text-primary">₱<?= number_format($c_bank, 2) ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- =====================================================
                     SECTION 6: DAILY FUEL SALES SUMMARY (MANAGER SUMMARY)
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-calendar-check"></i> Daily Fuel Sales Summary (Per Shift Breakdown)</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr style="background:#002F6C; color:#fff;">
                                <th style="text-align:left;">Metric</th>
                                <th style="text-align:right;">Shift 1 Only</th>
                                <th style="text-align:right;">Shift 2 Only</th>
                                <th style="text-align:right;">Total (Shift 1 + Shift 2)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sb1_liters = (float)($shift_breakdown['s1_liters'] ?? 0);
                            $sb1_sales  = (float)($shift_breakdown['s1_sales'] ?? 0);
                            $sb1_cash   = (float)($shift_breakdown['s1_cash'] ?? $c_cash1);
                            $sb1_ar     = (float)($shift_breakdown['s1_ar'] ?? $c_ar1);

                            $sb2_liters = (float)($shift_breakdown['s2_liters'] ?? 0);
                            $sb2_sales  = (float)($shift_breakdown['s2_sales'] ?? 0);
                            $sb2_cash   = (float)($shift_breakdown['s2_cash'] ?? $c_cash2);
                            $sb2_ar     = (float)($shift_breakdown['s2_ar'] ?? $c_ar2);
                            ?>
                            <tr>
                                <td style="text-align:left; font-weight:700;">Total Liters Sold</td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($sb1_liters, 2) ?> L</td>
                                <td style="text-align:right;" class="fw-bold"><?= number_format($sb2_liters, 2) ?> L</td>
                                <td style="text-align:right;" class="fw-bold text-success"><?= number_format($sb1_liters + $sb2_liters, 2) ?> L</td>
                            </tr>
                            <tr>
                                <td style="text-align:left; font-weight:700;">Fuel Sales Amount</td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format($sb1_sales, 2) ?></td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format($sb2_sales, 2) ?></td>
                                <td style="text-align:right;" class="fw-bold text-primary">₱<?= number_format($sb1_sales + $sb2_sales, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left; font-weight:700;">Cash Collected</td>
                                <td style="text-align:right;">₱<?= number_format($sb1_cash, 2) ?></td>
                                <td style="text-align:right;">₱<?= number_format($sb2_cash, 2) ?></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($sb1_cash + $sb2_cash, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left; font-weight:700;">Accounts Receivable (A/R)</td>
                                <td style="text-align:right;">₱<?= number_format($sb1_ar, 2) ?></td>
                                <td style="text-align:right;">₱<?= number_format($sb2_ar, 2) ?></td>
                                <td style="text-align:right;" class="fw-bold text-primary">₱<?= number_format($sb1_ar + $sb2_ar, 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- =====================================================
                     SECTION 7: VARIANCE ANALYSIS
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-search-dollar"></i> Variance Analysis</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Fuel Type</th>
                                <th style="text-align:right;">Expected Sales</th>
                                <th style="text-align:right;">Actual / Recorded Sales</th>
                                <th style="text-align:right;">Variance</th>
                                <th style="text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($variance)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No fuel variance records found for this period.</td></tr>
                            <?php else: foreach ($variance as $v):
                                $var = (float)$v['variance'];
                                $abs_var = abs($var);
                                $is_ok = $abs_var < 1;
                                $status_color = $is_ok ? '#16a34a' : ($var < 0 ? '#dc2626' : '#d97706');
                                $status_label = $is_ok ? 'Balanced' : ($var < 0 ? 'Short' : 'Investigate');
                                $status_bg    = $is_ok ? '#f0fdf4' : ($var < 0 ? '#fef2f2' : '#fffbeb');
                            ?>
                            <tr>
                                <td style="text-align:left;"><strong><?= htmlspecialchars($v['fuel_type']) ?></strong></td>
                                <td style="text-align:right;">₱<?= number_format((float)$v['expected_sales'], 2) ?></td>
                                <td style="text-align:right;">₱<?= number_format((float)$v['recorded_sales'], 2) ?></td>
                                <td style="text-align:right;" class="fw-bold" style="color:<?= $status_color ?>;">
                                    <?= $var >= 0 ? '' : '-' ?>₱<?= number_format($abs_var, 2) ?>
                                </td>
                                <td style="text-align:center;">
                                    <span style="padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;background:<?= $status_bg ?>;color:<?= $status_color ?>;">
                                        <?= $status_label ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- =====================================================
                     SECTION 8: FUEL ADJUSTMENT SUMMARY
                     ===================================================== -->
                <div class="rpt-section-heading"><i class="fas fa-sliders-h"></i> Fuel Adjustment Summary</div>
                <div class="table-responsive mb-2">
                    <table class="rpt-table align-middle">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Date</th>
                                <th style="text-align:left;">Pump / UGT</th>
                                <th style="text-align:left;">Fuel Type</th>
                                <th style="text-align:left;">Reason</th>
                                <th style="text-align:left;">Adjusted By</th>
                                <th style="text-align:right;">Liters Adjusted</th>
                                <th style="text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fuel_adjustments as $fa):
                                $adj_date = !empty($fa['adj_date']) ? date('M d, Y', strtotime($fa['adj_date'])) : 'N/A';
                                $dir = $fa['adjustment_direction'] ?? 'Decrease';
                                $l_val = (float)($fa['liters'] ?? 0);
                                $sign = ($dir === 'Decrease' || $l_val < 0) ? '-' : '+';
                            ?>
                            <tr>
                                <td style="text-align:left;"><?= $adj_date ?></td>
                                <td style="text-align:left;"><strong><?= htmlspecialchars($fa['ugt_no']) ?></strong></td>
                                <td style="text-align:left;"><?= htmlspecialchars($fa['fuel_type']) ?></td>
                                <td style="text-align:left;"><?= htmlspecialchars($fa['reason']) ?></td>
                                <td style="text-align:left;"><?= htmlspecialchars($fa['adjusted_by']) ?></td>
                                <td style="text-align:right;" class="fw-bold <?= $sign === '-' ? 'text-danger' : 'text-success' ?>">
                                    <?= $sign ?><?= number_format(abs($l_val), 2) ?> L
                                </td>
                                <td style="text-align:center;">
                                    <span class="badge bg-secondary" style="font-size:11px;"><?= htmlspecialchars(ucfirst($fa['status'] ?? 'Completed')) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($fuel_adjustments)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No fuel adjustment records for this period.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php

            } else { // daily_merch_service
                $merch            = $report_data['merchandise'] ?? [];
                $jos              = $report_data['job_orders'] ?? [];
                $merch_subtotal   = (float)($report_data['merchandise_subtotal'] ?? array_sum(array_column($merch, 'amount')));
                $jo_subtotal      = (float)($report_data['job_orders_subtotal'] ?? array_sum(array_column($jos, 'total_amount')));
                $daily_summary    = $report_data['daily_summary'] ?? [
                    'merchandise' => ['count' => count($merch), 'amount' => $merch_subtotal],
                    'job_order'   => ['count' => count($jos),   'amount' => $jo_subtotal],
                    'overall'     => ['count' => count($merch) + count($jos), 'amount' => $merch_subtotal + $jo_subtotal]
                ];
                $payment_summary  = $report_data['payment_summary'] ?? [];
                $category_summary = $report_data['category_summary'] ?? [];
                $service_revenue  = $report_data['service_revenue'] ?? [
                    'labor_fee'   => array_sum(array_column($jos, 'labor_fee')),
                    'service_fee' => array_sum(array_column($jos, 'service_fee')),
                    'parts'       => array_sum(array_column($jos, 'parts_cost')),
                    'overall'     => $jo_subtotal
                ];
                $status_summary   = $report_data['status_summary'] ?? [];
                ?>

                <!-- 1. MERCHANDISE SALES TRANSACTIONS -->
                <div class="rpt-section-heading"><i class="fas fa-shopping-cart me-2"></i>MERCHANDISE SALES TRANSACTIONS</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:13%;text-align:left;">Receipt No.</th>
                                <th style="width:8%;text-align:left;">Date</th>
                                <th style="width:12%;text-align:left;">Customer</th>
                                <th style="width:11%;text-align:left;">Category</th>
                                <th style="width:23%;text-align:left;">Product</th>
                                <th style="width:5%;text-align:center;">Qty</th>
                                <th style="width:9%;text-align:right;">Unit Price</th>
                                <th style="width:10%;text-align:right;">Amount</th>
                                <th style="width:9%;text-align:center;">Payment Method</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($merch)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No merchandise sales transactions found.</td></tr>
                            <?php else: foreach ($merch as $m): ?>
                                <tr>
                                    <td style="text-align:left;white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($m['receipt_no']) ?></code></td>
                                    <td style="text-align:left;white-space:normal!important;padding:8px 5px!important;font-size:12px;"><?= !empty($m['date']) ? date('m/d/Y', strtotime($m['date'])) : '-' ?></td>
                                    <td style="text-align:left;white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;font-size:12px;"><?= htmlspecialchars($m['customer']) ?></td>
                                    <td style="text-align:left;white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;font-size:12px;"><?= htmlspecialchars($m['category'] ?? 'General') ?></td>
                                    <td style="text-align:left;white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;font-size:12px;"><strong><?= htmlspecialchars($m['product']) ?></strong></td>
                                    <td style="text-align:center;padding:8px 5px!important;font-size:12px;" class="fw-bold"><?= number_format((float)$m['quantity']) ?></td>
                                    <td style="text-align:right;padding:8px 5px!important;font-size:12px;">₱<?= number_format((float)$m['unit_price'], 2) ?></td>
                                    <td style="text-align:right;padding:8px 5px!important;font-size:12px;" class="fw-bold text-primary">₱<?= number_format((float)$m['amount'], 2) ?></td>
                                    <td style="text-align:center;white-space:normal!important;padding:8px 5px!important;">
                                        <?php 
                                        $pm_disp = function_exists('format_payment_for_record') ? format_payment_for_record($m) : ['payment_type' => $m['payment_method'] ?? 'Cash', 'provider' => $m['ewallet_provider'] ?? '', 'reference_no' => $m['ewallet_reference'] ?? ''];
                                        ?>
                                        <span class="badge bg-secondary" style="font-size:10.5px;"><?= htmlspecialchars($pm_disp['payment_type']) ?></span>
                                        <?php if ($pm_disp['payment_type'] === 'E-Wallet' && !empty($pm_disp['provider'])): ?>
                                            <div class="small text-muted" style="font-size:9.5px;margin-top:2px;">Provider: <?= htmlspecialchars($pm_disp['provider']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="7" class="fw-bold" style="text-align:right;padding:8px 5px!important;">Subtotal Merchandise Sales:</td>
                                <td class="fw-bold text-success" style="text-align:right;padding:8px 5px!important;">₱<?= number_format($merch_subtotal, 2) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 2. JOB ORDER SERVICE TRANSACTIONS -->
                <div class="rpt-section-heading"><i class="fas fa-tools me-2"></i>JOB ORDER SERVICE TRANSACTIONS</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:9%;text-align:left;">JO No.</th>
                                <th style="width:7%;text-align:left;">Date</th>
                                <th style="width:9%;text-align:left;">Customer</th>
                                <th style="width:8%;text-align:left;">Vehicle</th>
                                <th style="width:9%;text-align:left;">Mechanic</th>
                                <th style="width:14%;text-align:left;">Service</th>
                                <th style="width:7%;text-align:right;">Labor Fee</th>
                                <th style="width:7%;text-align:right;">Service Fee</th>
                                <th style="width:7%;text-align:right;">Parts Cost</th>
                                <th style="width:8%;text-align:right;">Total Amount</th>
                                <th style="width:8%;text-align:center;">Payment Method</th>
                                <th style="width:7%;text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($jos)): ?>
                                <tr><td colspan="12" class="text-center py-4 text-muted">No job order service transactions found.</td></tr>
                            <?php else: foreach ($jos as $j):
                                $st = strtolower($j['status'] ?? '');
                                $badge = 'bg-secondary';
                                if (str_contains($st, 'completed')) $badge = 'bg-success';
                                elseif (str_contains($st, 'released')) $badge = 'bg-primary';
                                elseif (str_contains($st, 'pending')) $badge = 'bg-warning text-dark';
                                elseif (str_contains($st, 'cancel') || str_contains($st, 'reject')) $badge = 'bg-danger';
                            ?>
                                <tr>
                                    <td style="text-align:left;white-space:normal!important;word-break:break-all!important;padding:8px 4px!important;"><code style="font-size:10px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($j['jo_no']) ?></code></td>
                                    <td style="text-align:left;white-space:normal!important;padding:8px 4px!important;font-size:11.5px;"><?= !empty($j['date']) ? date('m/d/Y', strtotime($j['date'])) : '-' ?></td>
                                    <td style="text-align:left;white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11.5px;"><?= htmlspecialchars($j['customer']) ?></td>
                                    <td style="text-align:left;white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11.5px;"><?= htmlspecialchars($j['vehicle']) ?></td>
                                    <td style="text-align:left;white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11.5px;"><?= htmlspecialchars($j['mechanic']) ?></td>
                                    <td style="text-align:left;white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11.5px;"><strong><?= htmlspecialchars($j['service']) ?></strong></td>
                                    <td style="text-align:right;padding:8px 4px!important;font-size:11.5px;">₱<?= number_format((float)$j['labor_fee'], 2) ?></td>
                                    <td style="text-align:right;padding:8px 4px!important;font-size:11.5px;">₱<?= number_format((float)$j['service_fee'], 2) ?></td>
                                    <td style="text-align:right;padding:8px 4px!important;font-size:11.5px;">₱<?= number_format((float)$j['parts_cost'], 2) ?></td>
                                    <td style="text-align:right;padding:8px 4px!important;font-size:11.5px;" class="fw-bold text-success">₱<?= number_format((float)$j['total_amount'], 2) ?></td>
                                    <td style="text-align:center;white-space:normal!important;padding:8px 4px!important;">
                                        <?php 
                                        $pm_disp = function_exists('format_payment_for_record') ? format_payment_for_record($j) : ['payment_type' => $j['payment_method'] ?? 'Cash', 'provider' => $j['ewallet_provider'] ?? '', 'reference_no' => $j['ewallet_reference'] ?? ''];
                                        ?>
                                        <span class="badge bg-secondary" style="font-size:10px;"><?= htmlspecialchars($pm_disp['payment_type']) ?></span>
                                        <?php if ($pm_disp['payment_type'] === 'E-Wallet' && !empty($pm_disp['provider'])): ?>
                                            <div class="small text-muted" style="font-size:9px;margin-top:2px;">Provider: <?= htmlspecialchars($pm_disp['provider']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;white-space:normal!important;padding:8px 4px!important;"><span class="badge <?= $badge ?>" style="font-size:10px;"><?= htmlspecialchars($j['status']) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="9" class="fw-bold" style="text-align:right;padding:8px 4px!important;">Subtotal Service Sales:</td>
                                <td class="fw-bold text-success" style="text-align:right;padding:8px 4px!important;">₱<?= number_format($jo_subtotal, 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 3. DAILY SALES SUMMARY -->
                <div class="rpt-section-heading"><i class="fas fa-chart-bar me-2"></i>DAILY SALES SUMMARY</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Description</th>
                                <th style="text-align:center;">Transactions</th>
                                <th style="text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="text-align:left;">Merchandise Sales</td>
                                <td style="text-align:center;" class="fw-bold"><?= number_format((int)($daily_summary['merchandise']['count'] ?? 0)) ?></td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($daily_summary['merchandise']['amount'] ?? 0), 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">Job Order Sales</td>
                                <td style="text-align:center;" class="fw-bold"><?= number_format((int)($daily_summary['job_order']['count'] ?? 0)) ?></td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($daily_summary['job_order']['amount'] ?? 0), 2) ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">Overall Daily Sales</td>
                                <td style="text-align:center;" class="fw-bold"><?= number_format((int)($daily_summary['overall']['count'] ?? 0)) ?></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format((float)($daily_summary['overall']['amount'] ?? 0), 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 3b. TAX & VAT BREAKDOWN (VAT-INCLUSIVE PRICING STANDARD) -->
                <?php
                $tot_daily_sales = (float)($daily_summary['overall']['amount'] ?? 0);
                $station_tax_cfg = function_exists('petron_get_station_tax_config') ? petron_get_station_tax_config() : ['is_vat_registered' => true, 'tax_rate' => 0.12];
                $calc_tax = function_exists('petron_calculate_taxes') ? petron_calculate_taxes($tot_daily_sales, 0.0) : ['subtotal_amount' => round($tot_daily_sales / 1.12, 2), 'vat_amount' => round($tot_daily_sales - round($tot_daily_sales / 1.12, 2), 2), 'non_vat_sales' => 0.0];
                $vatable_base = $calc_tax['subtotal_amount'] ?? round($tot_daily_sales / 1.12, 2);
                $vat_ext      = $calc_tax['vat_amount'] ?? round($tot_daily_sales - $vatable_base, 2);
                $non_vat_base = $calc_tax['non_vat_sales'] ?? 0.0;
                ?>
                <div class="rpt-section-heading"><i class="fas fa-file-invoice-dollar me-2"></i>TAX &amp; VAT BREAKDOWN (VAT-INCLUSIVE STANDARD)</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Tax Classification</th>
                                <th style="text-align:right;">Amount (₱)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($station_tax_cfg['is_vat_registered']): ?>
                            <tr>
                                <td style="text-align:left;">Vatable Sales (VAT-Exclusive Base)</td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)$vatable_base, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">Value-Added Tax (12% Extracted)</td>
                                <td style="text-align:right;" class="fw-bold text-primary">₱<?= number_format((float)$vat_ext, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">VAT-Exempt / Zero-Rated Sales</td>
                                <td style="text-align:right;">₱0.00</td>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <td style="text-align:left;">Non-VAT / Exempt Sales</td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)$non_vat_base, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">Value-Added Tax (0%)</td>
                                <td style="text-align:right;">₱0.00</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">Total Gross Sales (VAT-Inclusive):</td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($tot_daily_sales, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 4. PAYMENT METHOD SUMMARY -->
                <div class="rpt-section-heading"><i class="fas fa-credit-card me-2"></i>PAYMENT METHOD SUMMARY</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Payment Method</th>
                                <th style="text-align:center;">Transactions</th>
                                <th style="text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $pm_tot_cnt = 0;
                            $pm_tot_amt = 0;
                            if (empty($payment_summary)):
                            ?>
                                <tr><td colspan="3" class="text-center py-3 text-muted">No payment transactions recorded.</td></tr>
                            <?php else: foreach ($payment_summary as $pm_name => $pm_data):
                                $pm_tot_cnt += (int)($pm_data['count'] ?? 0);
                                $pm_tot_amt += (float)($pm_data['amount'] ?? 0);
                            ?>
                                <tr>
                                    <td style="text-align:left;"><strong><?= htmlspecialchars($pm_name) ?></strong></td>
                                    <td style="text-align:center;" class="fw-bold"><?= number_format((int)($pm_data['count'] ?? 0)) ?></td>
                                    <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($pm_data['amount'] ?? 0), 2) ?></td>
                                </tr>
                                <?php if ($pm_name === 'E-Wallet' && !empty($pm_data['providers'])): ?>
                                    <?php foreach ($pm_data['providers'] as $prov => $prov_data): ?>
                                        <tr style="background-color: #f8fafc;">
                                            <td style="text-align:left; padding-left: 24px; font-size:12px;" class="text-secondary"><i class="fas fa-level-up-alt fa-rotate-90 text-muted me-2"></i>Provider: <strong><?= htmlspecialchars($prov) ?></strong></td>
                                            <td style="text-align:center; font-size:12px;" class="text-secondary"><?= number_format((int)($prov_data['count'] ?? 0)) ?></td>
                                            <td style="text-align:right; font-size:12px;" class="text-secondary">₱<?= number_format((float)($prov_data['amount'] ?? 0), 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($payment_summary)): ?>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">TOTAL:</td>
                                <td style="text-align:center;" class="fw-bold"><?= number_format($pm_tot_cnt) ?></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($pm_tot_amt, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- 5. SALES BY CATEGORY -->
                <div class="rpt-section-heading"><i class="fas fa-tags me-2"></i>SALES BY CATEGORY</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Category</th>
                                <th style="text-align:center;">Transactions</th>
                                <th style="text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $cat_tot_cnt = 0;
                            $cat_tot_amt = 0;
                            if (empty($category_summary)):
                            ?>
                                <tr><td colspan="3" class="text-center py-3 text-muted">No category sales recorded.</td></tr>
                            <?php else: foreach ($category_summary as $cat_name => $cat_data):
                                $cat_tot_cnt += (int)($cat_data['count'] ?? 0);
                                $cat_tot_amt += (float)($cat_data['amount'] ?? 0);
                            ?>
                                <tr>
                                    <td style="text-align:left;"><strong><?= htmlspecialchars($cat_name) ?></strong></td>
                                    <td style="text-align:center;" class="fw-bold"><?= number_format((int)($cat_data['count'] ?? 0)) ?></td>
                                    <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($cat_data['amount'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($category_summary)): ?>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">TOTAL:</td>
                                <td style="text-align:center;" class="fw-bold"><?= number_format($cat_tot_cnt) ?></td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format($cat_tot_amt, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- 6. SERVICE REVENUE BREAKDOWN -->
                <div class="rpt-section-heading"><i class="fas fa-file-invoice-dollar me-2"></i>SERVICE REVENUE BREAKDOWN</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Description</th>
                                <th style="text-align:right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="text-align:left;">Labor Fee Revenue</td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($service_revenue['labor_fee'] ?? 0), 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">Service Fee Revenue</td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($service_revenue['service_fee'] ?? 0), 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">Parts Revenue</td>
                                <td style="text-align:right;" class="fw-bold">₱<?= number_format((float)($service_revenue['parts'] ?? 0), 2) ?></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">Total Service Income:</td>
                                <td style="text-align:right;" class="fw-bold text-success">₱<?= number_format((float)($service_revenue['overall'] ?? 0), 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>


                <!-- 7. TRANSACTION STATUS SUMMARY -->
                <div class="rpt-section-heading"><i class="fas fa-tasks me-2"></i>TRANSACTION STATUS SUMMARY</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th class="text-center">Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $st_tot = 0;
                            if (empty($status_summary)):
                            ?>
                                <tr><td colspan="2" class="text-center py-3 text-muted">No transaction status records found.</td></tr>
                            <?php else: foreach ($status_summary as $st_label => $st_cnt):
                                $st_tot += (int)$st_cnt;
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($st_label) ?></strong></td>
                                    <td class="text-center fw-bold"><?= number_format((int)$st_cnt) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($status_summary)): ?>
                        <tfoot>
                            <tr>
                                <td class="fw-bold">TOTAL:</td>
                                <td class="text-center fw-bold"><?= number_format($st_tot) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php
            }
            break;

        // =========================================================================
        // 2. INVENTORY REPORTS
        // =========================================================================
        case 'inventory':
            if (!function_exists('inv_status_badge')) {
                function inv_status_badge(string $status): string {
                    $s = strtolower(trim($status));
                    if ($s === 'available' || $s === 'normal' || $s === 'in stock') return 'bg-success text-white';
                    if ($s === 'low stock' || $s === 'low fuel')  return 'bg-danger text-white';
                    if ($s === 'critical stock' || $s === 'critical fuel' || $s === 'critical') return 'bg-danger text-white';
                    if ($s === 'out of stock')   return 'bg-danger text-white';
                    if ($s === 'expired')        return 'bg-secondary text-white';
                    return 'bg-secondary text-white';
                }
            }
            $rows = $report_data['rows'] ?? [];
            ?>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 1. MERCHANDISE INVENTORY REPORT
            ═══════════════════════════════════════════════════════════════════════ -->
            <?php if ($tab === 'merch_inventory'): ?>
                <div class="rpt-section-heading"><i class="fas fa-boxes me-2"></i>MERCHANDISE INVENTORY REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead><tr>
                            <th style="width:9%;text-align:left;">SKU</th>
                            <th style="width:11%;text-align:left;">Batch ID</th>
                            <th style="width:20%;text-align:left;">Product</th>
                            <th style="width:11%;text-align:left;">Category</th>
                            <th style="width:7%;text-align:left;">UOM</th>
                            <th class="text-center" style="width:6%;text-align:center;">Initial Stock</th>
                            <th class="text-center" style="width:6%;text-align:center;">Current Stock</th>
                            <th class="text-center" style="width:6%;text-align:center;">Reorder Level</th>
                            <th style="width:8%;text-align:center;">Expiration Date</th>
                            <th style="width:8%;text-align:center;">Status</th>
                            <th style="width:8%;text-align:center;">Last Updated</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="11" class="text-center py-4 text-muted">No merchandise inventory items found.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $stRaw = trim($r['status'] ?? 'Available');
                            $stLower = strtolower($stRaw);
                            $badge = inv_status_badge($stRaw);
                            $expDate = $r['expiration_date'] ?? '';
                            $isExpired = !empty($expDate) && strtotime($expDate) < time();
                            $isLowM = in_array($stLower, ['low stock', 'low fuel', 'critical stock', 'out of stock']);
                            $badgeBgM = $isLowM ? '#dc2626' : ($stLower === 'available' || $stLower === 'in stock' ? '#16a34a' : '#64748b');
                        ?>
                            <tr>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:8px 4px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['sku'] ?: 'N/A') ?></code></td>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:8px 4px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['batch_id'] ?: 'N/A') ?></code></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:12px;"><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11.5px;"><?= htmlspecialchars($r['category'] ?? 'General') ?></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11.5px;"><?= htmlspecialchars($r['uom'] ?? 'pcs') ?></td>
                                <td class="text-center" style="padding:8px 4px!important;font-size:12px;"><?= number_format((float)($r['initial_stock'] ?? 0)) ?></td>
                                <td class="text-center fw-bold" style="padding:8px 4px!important;font-size:12px;"><?= number_format((float)($r['current_stock'] ?? 0)) ?></td>
                                <td class="text-center" style="padding:8px 4px!important;font-size:12px;"><?= number_format((float)($r['reorder_level'] ?? 0)) ?></td>
                                <td class="text-center <?= $isExpired ? 'text-danger fw-bold' : '' ?>" style="padding:8px 4px!important;font-size:11.5px;white-space:normal!important;"><?= !empty($expDate) ? date('m/d/Y', strtotime($expDate)) : 'N/A' ?></td>
                                <td style="text-align:center;padding:8px 4px!important;white-space:normal!important;">
                                    <span class="badge" style="background-color: <?= $badgeBgM ?> !important; color: #ffffff !important; font-weight: 700 !important; padding: 4px 6px !important; border-radius: 6px !important; font-size: 10.5px !important; display: inline-block !important;">
                                        <?= htmlspecialchars($stRaw) ?>
                                    </span>
                                </td>
                                <td class="text-center text-muted" style="font-size:10.5px;padding:8px 4px!important;white-space:normal!important;line-height:1.2;"><?php if (!empty($r['last_updated'])): $lut = strtotime($r['last_updated']); ?><div><?= date('m/d/Y', $lut) ?></div><div style="color:#94a3b8;"><?= date('h:i A', $lut) ?></div><?php else: ?>N/A<?php endif; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 2. FUEL INVENTORY REPORT
            ═══════════════════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'fuel_inventory'): ?>
                <div class="rpt-section-heading"><i class="fas fa-gas-pump me-2"></i>FUEL INVENTORY REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead><tr>
                            <th style="width:9%;text-align:left;">UGT</th>
                            <th style="width:11%;text-align:left;">Fuel Type</th>
                            <th class="text-end" style="width:13%;text-align:right;">Current Volume (L)</th>
                            <th class="text-end" style="width:13%;text-align:right;">Tank Capacity (L)</th>
                            <th class="text-end" style="width:12%;text-align:right;">Reorder Level (L)</th>
                            <th class="text-end" style="width:12%;text-align:right;">Critical Level (L)</th>
                            <th class="text-center" style="width:11%;text-align:center;">Available %</th>
                            <th style="width:9%;text-align:center;">Status</th>
                            <th style="width:10%;text-align:center;">Last Updated</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No fuel inventory records found.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $stRaw = trim($r['status'] ?? 'Available');
                            $stLower = strtolower($stRaw);
                            $pct   = (float)($r['available_pct'] ?? 0);
                            $barColor = $pct <= 20 ? '#ef4444' : ($pct <= 40 ? '#f59e0b' : '#22c55e');

                            // Green for Available, Red for Low Fuel / Critical
                            $isLow = in_array($stLower, ['low fuel', 'low stock', 'critical fuel', 'critical stock', 'out of stock']);
                            $badgeBg = $isLow ? '#dc2626' : '#16a34a';
                        ?>
                            <tr>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><strong><?= htmlspecialchars($r['ugt'] ?? 'N/A') ?></strong></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['fuel_type'] ?? '') ?></td>
                                <td class="text-end fw-bold" style="padding:8px 5px!important;"><?= number_format((float)($r['current_volume'] ?? 0), 2) ?></td>
                                <td class="text-end" style="padding:8px 5px!important;"><?= number_format((float)($r['tank_capacity'] ?? 0), 2) ?></td>
                                <td class="text-end text-warning" style="padding:8px 5px!important;"><?= number_format((float)($r['reorder_level'] ?? 0), 2) ?></td>
                                <td class="text-end text-danger" style="padding:8px 5px!important;"><?= number_format((float)($r['critical_level'] ?? 0), 2) ?></td>
                                <td class="text-center" style="padding:8px 5px!important;">
                                    <div style="background:#e5e7eb;border-radius:999px;height:8px;width:60px;display:inline-block;vertical-align:middle;margin-right:4px">
                                        <div style="background:<?= $barColor ?>;width:<?= min(100,$pct) ?>%;height:100%;border-radius:999px"></div>
                                    </div>
                                    <span class="fw-bold" style="color:<?= $barColor ?>;font-size:11px;"><?= $pct ?>%</span>
                                </td>
                                <td style="text-align:center;padding:8px 5px!important;">
                                    <span class="badge" style="background-color: <?= $badgeBg ?> !important; color: #ffffff !important; font-weight: 700 !important; padding: 4px 8px !important; border-radius: 6px !important; font-size: 10.5px !important; display: inline-block !important;">
                                        <?= htmlspecialchars($stRaw) ?>
                                    </span>
                                </td>
                                <td class="text-center text-muted" style="font-size:10.5px;padding:8px 5px!important;white-space:normal!important;line-height:1.2;"><?php if (!empty($r['last_updated'])): $lut = strtotime($r['last_updated']); ?><div><?= date('m/d/Y', $lut) ?></div><div style="color:#94a3b8;"><?= date('h:i A', $lut) ?></div><?php else: ?>N/A<?php endif; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 3. INVENTORY MOVEMENT REPORT
            ═══════════════════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'inventory_movement'): ?>
                <div class="rpt-section-heading"><i class="fas fa-exchange-alt me-2"></i>INVENTORY MOVEMENT REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead><tr>
                            <th style="width:14%;text-align:left;">Date</th>
                            <th style="width:14%;text-align:left;">Batch ID</th>
                            <th style="width:26%;text-align:left;">Product</th>
                            <th style="width:14%;text-align:center;">Transaction Type</th>
                            <th class="text-center" style="width:10%;text-align:center;">Qty</th>
                            <th class="text-center" style="width:10%;text-align:center;">Balance After</th>
                            <th style="width:12%;text-align:left;">Performed By</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No inventory movement logs found for this date range.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $qty = (float)($r['qty'] ?? 0);
                            $ttColor = 'bg-secondary';
                            $tt = strtolower($r['transaction_type'] ?? '');
                            if ($tt === 'stock in')   $ttColor = 'bg-success';
                            elseif ($tt === 'sales')  $ttColor = 'bg-primary';
                            elseif ($tt === 'return') $ttColor = 'bg-info text-dark';
                            elseif ($tt === 'expired' || $tt === 'damaged') $ttColor = 'bg-danger';
                            elseif (str_contains($tt, 'adjustment')) $ttColor = 'bg-warning text-dark';
                        ?>
                            <tr>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['date'] ?? '') ?></td>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['batch_id'] ?? 'N/A') ?></code></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;"><span class="badge <?= $ttColor ?>"><?= htmlspecialchars($r['transaction_type'] ?? '') ?></span></td>
                                <td class="text-center fw-bold <?= $qty >= 0 ? 'text-success' : 'text-danger' ?>" style="white-space:normal!important;padding:8px 5px!important;">
                                    <?= ($qty > 0 ? '+' : '') . number_format($qty, 2) ?>
                                </td>
                                <td class="text-center fw-bold" style="white-space:normal!important;padding:8px 5px!important;"><?= number_format((float)($r['balance_after'] ?? 0), 2) ?></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['performed_by'] ?? 'System') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 4. INVENTORY ADJUSTMENT REPORT
            ═══════════════════════════════════════════════════════════════════════ -->
            <?php elseif ($tab === 'inventory_adjustment'): ?>
                <div class="rpt-section-heading"><i class="fas fa-sliders-h me-2"></i>INVENTORY ADJUSTMENT REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead><tr>
                            <th style="width:12%;text-align:left;">Request No.</th>
                            <th style="width:12%;text-align:left;">Batch ID</th>
                            <th style="width:20%;text-align:left;">Product</th>
                            <th style="width:14%;text-align:left;">Adjustment Type</th>
                            <th class="text-center" style="width:7%;text-align:center;">Qty</th>
                            <th style="width:11%;text-align:left;">Requested By</th>
                            <th style="width:11%;text-align:left;">Approved By</th>
                            <th class="text-center" style="width:6%;text-align:center;">Status</th>
                            <th class="text-center" style="width:7%;text-align:center;">Approval Date</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="9" class="text-center py-4 text-muted">No inventory adjustment records found.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $st  = strtolower($r['status'] ?? '');
                            $badge = $st === 'approved' ? 'bg-success' : ($st === 'rejected' ? 'bg-danger' : 'bg-warning text-dark');
                            $qty = (float)($r['qty'] ?? 0);
                        ?>
                            <tr>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['request_no'] ?? 'N/A') ?></code></td>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['batch_id'] ?? 'N/A') ?></code></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['adjustment_type'] ?? '') ?></td>
                                <td class="text-center fw-bold <?= $qty < 0 ? 'text-danger' : ($qty > 0 ? 'text-success' : 'text-muted') ?>" style="white-space:normal!important;padding:8px 5px!important;">
                                    <?= ($qty > 0 ? '+' : '') . (floor($qty) == $qty ? number_format($qty) : number_format($qty, 2)) ?>
                                </td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['requested_by'] ?? '') ?></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['approved_by'] ?: '-') ?></td>
                                <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;"><span class="badge <?= $badge ?>"><?= htmlspecialchars(ucfirst($r['status'] ?? '')) ?></span></td>
                                <td class="text-center text-muted" style="white-space:normal!important;padding:8px 5px!important;"><?= !empty($r['approval_date']) ? date('m/d/Y', strtotime($r['approval_date'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ═══════════════════════════════════════════════════════════════════════
                 5. EXPIRED & DAMAGED REPORT
            ═══════════════════════════════════════════════════════════════════════ -->
            <?php else: // expired_damaged ?>
                <div class="rpt-section-heading"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>EXPIRED &amp; DAMAGED REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead><tr>
                            <th style="width:13%;text-align:left;">Batch ID</th>
                            <th style="width:23%;text-align:left;">Product</th>
                            <th style="width:11%;text-align:center;">Expiration Date</th>
                            <th class="text-center" style="width:10%;text-align:center;">Expired Qty</th>
                            <th class="text-center" style="width:10%;text-align:center;">Damaged Qty</th>
                            <th class="text-center" style="width:11%;text-align:center;">Total Deduction</th>
                            <th style="width:11%;text-align:left;">Approved By</th>
                            <th class="text-center" style="width:11%;text-align:center;">Approval Date</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No expired or damaged items reported for this date range.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $expDate = $r['expiration_date'] ?? '';
                            $expQ = (float)($r['expired_qty'] ?? 0);
                            $dmgQ = (float)($r['damaged_qty'] ?? 0);
                            $totD = (float)($r['total_deduction'] ?? 0);
                        ?>
                            <tr>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['batch_id'] ?? 'N/A') ?></code></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;"><?= !empty($expDate) ? date('m/d/Y', strtotime($expDate)) : 'N/A' ?></td>
                                <td class="text-center text-muted fw-bold" style="white-space:normal!important;padding:8px 5px!important;"><?= (floor($expQ) == $expQ ? number_format($expQ) : number_format($expQ, 2)) ?></td>
                                <td class="text-center text-danger fw-bold" style="white-space:normal!important;padding:8px 5px!important;"><?= (floor($dmgQ) == $dmgQ ? number_format($dmgQ) : number_format($dmgQ, 2)) ?></td>
                                <td class="text-center fw-bold" style="color:#f97316;white-space:normal!important;padding:8px 5px!important;"><?= (floor($totD) == $totD ? number_format($totD) : number_format($totD, 2)) ?></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['approved_by'] ?: '-') ?></td>
                                <td class="text-center text-muted" style="white-space:normal!important;padding:8px 5px!important;"><?= !empty($r['approval_date']) ? date('m/d/Y', strtotime($r['approval_date'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php
            break;

        // =========================================================================
        // 3. OPERATIONS REPORTS
        // =========================================================================
        case 'operations':
            $rows    = $report_data['rows'] ?? [];
            $summary = $report_data['summary'] ?? [];

            // ─── 1. JOB ORDER REPORT ─────────────────────────────────────────
            if ($tab === 'job_order') {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-clipboard-list me-2"></i>JOB ORDER REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:8%;text-align:left;">JO No.</th>
                                <th style="width:7%;text-align:left;">Date</th>
                                <th style="width:11%;text-align:left;">Customer</th>
                                <th style="width:8%;text-align:left;">Vehicle</th>
                                <th style="width:9%;text-align:left;">Mechanic</th>
                                <th style="width:9%;text-align:left;">Service Category</th>
                                <th class="text-end" style="width:7%;text-align:right;">Labor Fee</th>
                                <th class="text-end" style="width:6%;text-align:right;">Service Fee</th>
                                <th class="text-end" style="width:7%;text-align:right;">Parts Cost</th>
                                <th class="text-end" style="width:8%;text-align:right;">Total Amount</th>
                                <th class="text-center" style="width:7%;text-align:center;">Payment</th>
                                <th class="text-center" style="width:6%;text-align:center;">Status</th>
                                <th class="text-center" style="width:7%;text-align:center;">Released</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="13" class="text-center py-4 text-muted">No Job Orders found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $st = strtolower($r['status'] ?? '');
                                $badge = 'bg-secondary';
                                if (in_array($st, ['completed', 'verified', 'finalized'])) $badge = 'bg-success';
                                elseif (in_array($st, ['in progress', 'awaiting parts'])) $badge = 'bg-warning text-dark';
                                elseif ($st === 'released') $badge = 'bg-info text-dark';
                                elseif (in_array($st, ['cancelled', 'rejected'])) $badge = 'bg-danger';
                            ?>
                                <tr>
                                    <td style="white-space:normal!important;word-break:break-all!important;padding:8px 4px!important;"><code style="font-size:10px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['jo_no'] ?? 'N/A') ?></code></td>
                                    <td style="white-space:normal!important;padding:8px 4px!important;font-size:11px;"><?= !empty($r['date']) ? date('m/d/y', strtotime($r['date'])) : '-' ?></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11.5px;"><strong><?= htmlspecialchars($r['customer'] ?? '') ?></strong></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11.5px;"><?= htmlspecialchars($r['vehicle'] ?? '') ?></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11.5px;"><?= htmlspecialchars($r['mechanic'] ?? '') ?></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11px;"><?= htmlspecialchars($r['service_category'] ?? '') ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 4px!important;font-size:11px;">₱<?= number_format((float)($r['labor_fee'] ?? 0), 2) ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 4px!important;font-size:11px;">₱<?= number_format((float)($r['service_fee'] ?? 0), 2) ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 4px!important;font-size:11px;">₱<?= number_format((float)($r['parts_cost'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-primary" style="white-space:nowrap!important;padding:8px 4px!important;font-size:11.5px;">₱<?= number_format((float)($r['total_amount'] ?? 0), 2) ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 3px!important;"><span class="badge bg-secondary" style="font-size:10px;padding:3px 5px;"><?= htmlspecialchars($r['payment_method'] ?? 'Unpaid') ?></span></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 3px!important;"><span class="badge <?= $badge ?>" style="font-size:10px;padding:3px 5px;"><?= htmlspecialchars(ucfirst($r['status'] ?? '')) ?></span></td>
                                    <td class="text-center text-muted" style="white-space:normal!important;padding:8px 4px!important;font-size:10.5px;"><?= !empty($r['released_date']) ? date('m/d/y', strtotime($r['released_date'])) : 'N/A' ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="fw-bold text-end">SUMMARY TOTALS:</td>
                                <td class="text-end fw-bold">₱<?= number_format((float)($summary['total_labor'] ?? 0), 2) ?></td>
                                <td class="text-end fw-bold">₱<?= number_format((float)($summary['total_service_fee'] ?? 0), 2) ?></td>
                                <td class="text-end fw-bold">₱<?= number_format((float)($summary['total_parts'] ?? 0), 2) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format((float)($summary['overall_revenue'] ?? 0), 2) ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Job Order Summary (10 Individual Plain Card Boxes) -->
                <div class="rpt-section-heading mt-4 mb-3"><i class="fas fa-calculator me-2"></i>Job Order Summary</div>
                <div class="row row-cols-2 row-cols-sm-3 row-cols-md-5 g-3 mb-4">
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Job Orders</div>
                            <div class="value fs-5 fw-bold text-dark"><?= number_format((int)($summary['total_jos'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Completed Job Orders</div>
                            <div class="value fs-5 fw-bold text-success"><?= number_format((int)($summary['completed_jos'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Pending Job Orders</div>
                            <div class="value fs-5 fw-bold text-warning"><?= number_format((int)($summary['pending_jos'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">In Progress Job Orders</div>
                            <div class="value fs-5 fw-bold text-primary"><?= number_format((int)($summary['in_progress_jos'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Released Vehicles</div>
                            <div class="value fs-5 fw-bold text-info"><?= number_format((int)($summary['released_jos'] ?? 0)) ?></div>
                        </div>
                    </div>

                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Cancelled Job Orders</div>
                            <div class="value fs-5 fw-bold text-danger"><?= number_format((int)($summary['cancelled_jos'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Labor Fee (₱)</div>
                            <div class="value fs-5 fw-bold text-dark">₱<?= number_format((float)($summary['total_labor'] ?? 0), 2) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Service Fee (₱)</div>
                            <div class="value fs-5 fw-bold text-dark">₱<?= number_format((float)($summary['total_service_fee'] ?? 0), 2) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Parts Revenue (₱)</div>
                            <div class="value fs-5 fw-bold text-dark">₱<?= number_format((float)($summary['total_parts'] ?? 0), 2) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Overall Service Revenue (₱)</div>
                            <div class="value fs-5 fw-bold text-success">₱<?= number_format((float)($summary['overall_revenue'] ?? 0), 2) ?></div>
                        </div>
                    </div>
                </div>
                <?php

            // ─── 2. MECHANIC PERFORMANCE REPORT ──────────────────────────────
            } else {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-user-cog me-2"></i>MECHANIC PERFORMANCE REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:18%;text-align:left;">Mechanic</th>
                                <th class="text-center" style="width:8%;text-align:center;">Assigned Jobs</th>
                                <th class="text-center" style="width:8%;text-align:center;">Completed Jobs</th>
                                <th class="text-center" style="width:8%;text-align:center;">Pending Jobs</th>
                                <th class="text-center" style="width:8%;text-align:center;">Cancelled Jobs</th>
                                <th class="text-center" style="width:12%;text-align:center;">Average Completion Time</th>
                                <th class="text-end" style="width:12%;text-align:right;">Labor Revenue</th>
                                <th class="text-end" style="width:13%;text-align:right;">Service Revenue</th>
                                <th class="text-end" style="width:13%;text-align:right;">Total Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No mechanic performance data available.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $avg_mins = (float)($r['avg_completion_mins'] ?? 0);
                            ?>
                                <tr>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 6px!important;"><strong><?= htmlspecialchars($r['mechanic'] ?? '') ?></strong></td>
                                    <td class="text-center fw-bold" style="white-space:normal!important;padding:8px 4px!important;"><?= number_format((int)($r['assigned_jobs'] ?? 0)) ?></td>
                                    <td class="text-center text-success fw-bold" style="white-space:normal!important;padding:8px 4px!important;"><?= number_format((int)($r['completed_jobs'] ?? 0)) ?></td>
                                    <td class="text-center text-warning fw-bold" style="white-space:normal!important;padding:8px 4px!important;"><?= number_format((int)($r['pending_jobs'] ?? 0)) ?></td>
                                    <td class="text-center text-danger fw-bold" style="white-space:normal!important;padding:8px 4px!important;"><?= number_format((int)($r['cancelled_jobs'] ?? 0)) ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 4px!important;"><?= $avg_mins > 0 ? number_format($avg_mins) . ' mins' : 'N/A' ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 6px!important;">₱<?= number_format((float)($r['labor_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 6px!important;">₱<?= number_format((float)($r['service_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-success" style="white-space:nowrap!important;padding:8px 6px!important;">₱<?= number_format((float)($r['total_revenue'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="fw-bold">SUMMARY TOTALS:</td>
                                <td class="text-center fw-bold"><?= number_format((int)($summary['total_assigned'] ?? 0)) ?></td>
                                <td class="text-center fw-bold text-success"><?= number_format((int)($summary['total_completed'] ?? 0)) ?></td>
                                <td class="text-center fw-bold text-warning"><?= number_format((int)($summary['total_pending'] ?? 0)) ?></td>
                                <td class="text-center fw-bold text-danger"><?= number_format((int)array_sum(array_column($rows, 'cancelled_jobs'))) ?></td>
                                <td class="text-center fw-bold"><?= !empty($summary['overall_avg_mins']) ? number_format((float)$summary['overall_avg_mins']) . ' mins' : 'N/A' ?></td>
                                <td class="text-end fw-bold">₱<?= number_format((float)array_sum(array_column($rows, 'labor_revenue')), 2) ?></td>
                                <td class="text-end fw-bold">₱<?= number_format((float)array_sum(array_column($rows, 'service_revenue')), 2) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format((float)($summary['overall_revenue'] ?? 0), 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Mechanic Performance Summary (6 Individual Plain Card Boxes) -->
                <div class="rpt-section-heading mt-4 mb-3"><i class="fas fa-calculator me-2"></i>Mechanic Performance Summary</div>
                <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3 mb-4">
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Mechanics</div>
                            <div class="value fs-5 fw-bold text-dark"><?= number_format((int)($summary['total_mechanics'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Assigned Jobs</div>
                            <div class="value fs-5 fw-bold text-primary"><?= number_format((int)($summary['total_assigned'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Completed Jobs</div>
                            <div class="value fs-5 fw-bold text-success"><?= number_format((int)($summary['total_completed'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Total Pending Jobs</div>
                            <div class="value fs-5 fw-bold text-warning"><?= number_format((int)($summary['total_pending'] ?? 0)) ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Average Completion Time</div>
                            <div class="value fs-5 fw-bold text-info"><?= !empty($summary['overall_avg_mins']) ? number_format((float)$summary['overall_avg_mins']) . ' mins' : 'N/A' ?></div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="rpt-summary-card p-3 border rounded bg-white text-center shadow-sm" style="border: 1px solid #cbd5e1 !important;">
                            <div class="label text-muted small text-uppercase fw-bold mb-1">Overall Revenue Generated (₱)</div>
                            <div class="value fs-5 fw-bold text-success">₱<?= number_format((float)($summary['overall_revenue'] ?? 0), 2) ?></div>
                        </div>
                    </div>
                </div>
                <?php
            }
            break;

        // =========================================================================
        // 4. PROCUREMENT REPORTS
        // =========================================================================
        case 'procurement':

            // ─── 1. PURCHASE ORDER REPORT ────────────────────────────────────
            if ($tab === 'purchase_order') {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-file-alt me-2"></i>PURCHASE ORDER REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:10%;text-align:left;">PO No.</th>
                                <th style="width:10%;text-align:left;">PO Date</th>
                                <th style="width:11%;text-align:left;">Requested By</th>
                                <th style="width:11%;text-align:left;">Approved By</th>
                                <th style="width:15%;text-align:left;">Supplier</th>
                                <th class="text-center" style="width:6%;text-align:center;">Items</th>
                                <th class="text-center" style="width:7%;text-align:center;">Total Qty</th>
                                <th class="text-end" style="width:11%;text-align:right;">Estimated Cost</th>
                                <th class="text-center" style="width:11%;text-align:center;">Expected Delivery</th>
                                <th class="text-center" style="width:8%;text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="10" class="text-center py-4 text-muted">No purchase orders found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $st = strtolower($r['status'] ?? '');
                                $badge = 'bg-secondary';
                                if (str_contains($st, 'complet')) $badge = 'bg-success';
                                elseif (str_contains($st, 'approv')) $badge = 'bg-primary';
                                elseif (str_contains($st, 'partial')) $badge = 'bg-info text-dark';
                                elseif (str_contains($st, 'pending')) $badge = 'bg-warning text-dark';
                                elseif (str_contains($st, 'cancel')) $badge = 'bg-danger';
                            ?>
                                <tr>
                                    <td style="white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['po_number'] ?? 'N/A') ?></code></td>
                                    <td style="white-space:normal!important;padding:8px 5px!important;font-size:11px;"><?= !empty($r['po_date']) ? date('m/d/Y h:i A', strtotime($r['po_date'])) : '-' ?></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['requested_by'] ?? 'N/A') ?></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['approved_by'] ?? 'N/A') ?></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><strong><?= htmlspecialchars($r['supplier'] ?? 'Petron Corporation') ?></strong></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;"><?= number_format((int)($r['item_count'] ?? 0)) ?></td>
                                    <td class="text-center fw-bold" style="white-space:normal!important;padding:8px 5px!important;"><?= number_format((float)($r['total_qty'] ?? 0)) ?></td>
                                    <td class="text-end fw-bold" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['estimated_cost'] ?? 0), 2) ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;font-size:11px;"><?= !empty($r['expected_delivery_date']) ? date('m/d/Y', strtotime($r['expected_delivery_date'])) : 'N/A' ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;"><span class="badge <?= $badge ?>" style="font-size:10.5px;"><?= htmlspecialchars(ucfirst($r['status'] ?? '')) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="fw-bold text-end">SUMMARY TOTALS:</td>
                                <td class="text-center fw-bold"><?= number_format(array_sum(array_column($rows, 'total_qty'))) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format(array_sum(array_column($rows, 'estimated_cost')), 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php

            // ─── 2. FUEL RECONCILIATION REPORT ───────────────────────────────
            } elseif ($tab === 'fuel_reconciliation' || $tab === 'delivery_validation') {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-gas-pump me-2"></i>FUEL RECONCILIATION REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:11%;text-align:left;">UGT No.</th>
                                <th style="width:12%;text-align:left;">Fuel Type</th>
                                <th class="text-end" style="width:11%;text-align:right;">Beginning Reading</th>
                                <th class="text-end" style="width:11%;text-align:right;">Ending Reading</th>
                                <th class="text-end" style="width:10%;text-align:right;">Calibration</th>
                                <th class="text-end" style="width:12%;text-align:right;">Net Volume</th>
                                <th class="text-end" style="width:10%;text-align:right;">Selling Price</th>
                                <th class="text-end" style="width:13%;text-align:right;">Fuel Sales</th>
                                <th class="text-center" style="width:10%;text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No fuel reconciliation records found for this period.</td></tr>
                            <?php else:
                                $tot_net_vol = 0;
                                $tot_sales = 0;
                                foreach ($rows as $r):
                                    $net_vol = (float)($r['net_volume'] ?? 0);
                                    $sales   = (float)($r['fuel_sales'] ?? 0);
                                    $tot_net_vol += $net_vol;
                                    $tot_sales   += $sales;
                                    $st = $r['status'] ?? 'Pending';
                                    $badge = ($st === 'Submitted' || $st === 'Approved' || $st === 'Verified') ? 'bg-success' : 'bg-warning text-dark';
                            ?>
                                <tr>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><strong><?= htmlspecialchars($r['ugt_no'] ?? 'UGT #1') ?></strong></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['fuel_type'] ?? 'Fuel') ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 5px!important;"><?= number_format((float)($r['beginning_reading'] ?? 0), 2) ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 5px!important;"><?= number_format((float)($r['ending_reading'] ?? 0), 2) ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 5px!important;"><?= number_format((float)($r['calibration'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-success" style="white-space:nowrap!important;padding:8px 5px!important;"><?= number_format($net_vol, 2) ?> L</td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['selling_price'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-primary" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format($sales, 2) ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;"><span class="badge <?= $badge ?>" style="font-size:10.5px;"><?= htmlspecialchars($st) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="fw-bold text-end">TOTAL:</td>
                                <td class="fw-bold text-end text-success"><?= number_format($tot_net_vol, 2) ?> L</td>
                                <td></td>
                                <td class="fw-bold text-end text-primary">₱<?= number_format($tot_sales, 2) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php

            // ─── 3. PO VS RECEIVED REPORT ─────────────────────────────────────
            } elseif ($tab === 'po_vs_received') {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-balance-scale me-2"></i>PO VS RECEIVED REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:12%;text-align:left;">PO No.</th>
                                <th style="width:24%;text-align:left;">Product</th>
                                <th class="text-center" style="width:9%;text-align:center;">Ordered Qty</th>
                                <th class="text-center" style="width:9%;text-align:center;">Received Qty</th>
                                <th class="text-center" style="width:10%;text-align:center;">Variance</th>
                                <th class="text-center" style="width:13%;text-align:center;">Expected Delivery</th>
                                <th class="text-center" style="width:13%;text-align:center;">Actual Delivery</th>
                                <th class="text-center" style="width:10%;text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No PO vs Received data found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $var   = (float)($r['variance'] ?? 0);
                                $st    = $r['status'] ?? 'Pending Delivery';
                                $badge = 'bg-secondary';
                                if ($st === 'Complete') $badge = 'bg-success';
                                elseif ($st === 'Partial') $badge = 'bg-warning text-dark';
                                elseif ($st === 'Pending Delivery') $badge = 'bg-info text-dark';
                                elseif ($st === 'Over Delivered') $badge = 'bg-primary';
                                elseif ($st === 'Under Delivered') $badge = 'bg-danger';
                                $var_class = $var == 0 ? 'text-success' : ($var > 0 ? 'text-primary' : 'text-danger');
                            ?>
                                <tr>
                                    <td style="white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['po_number'] ?? 'N/A') ?></code></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                    <td class="text-center fw-bold" style="white-space:normal!important;padding:8px 5px!important;"><?= number_format((float)($r['ordered_qty'] ?? 0)) ?></td>
                                    <td class="text-center fw-bold" style="white-space:normal!important;padding:8px 5px!important;"><?= number_format((float)($r['received_qty'] ?? 0)) ?></td>
                                    <td class="text-center fw-bold <?= $var_class ?>" style="white-space:normal!important;padding:8px 5px!important;"><?= ($var > 0 ? '+' : '') . number_format($var, 2) ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;font-size:11px;"><?= !empty($r['expected_delivery_date']) ? date('m/d/Y', strtotime($r['expected_delivery_date'])) : 'N/A' ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;font-size:11px;"><?= !empty($r['actual_delivery']) ? date('m/d/Y', strtotime($r['actual_delivery'])) : '-' ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;"><span class="badge <?= $badge ?>" style="font-size:10.5px;"><?= htmlspecialchars($st) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="fw-bold text-end">SUMMARY TOTALS:</td>
                                <td class="text-center fw-bold"><?= number_format(array_sum(array_column($rows, 'ordered_qty'))) ?></td>
                                <td class="text-center fw-bold"><?= number_format(array_sum(array_column($rows, 'received_qty'))) ?></td>
                                <td class="text-center fw-bold <?= array_sum(array_column($rows, 'variance')) == 0 ? 'text-success' : (array_sum(array_column($rows, 'variance')) > 0 ? 'text-primary' : 'text-danger') ?>">
                                    <?= (array_sum(array_column($rows, 'variance')) > 0 ? '+' : '') . number_format(array_sum(array_column($rows, 'variance')), 2) ?>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php

            // ─── 4. STOCK-IN APPROVAL REPORT ─────────────────────────────────
            } else {
                ?>
                <div class="rpt-section-heading"><i class="fas fa-check-circle me-2"></i>STOCK-IN APPROVAL REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:13%;text-align:left;">Batch ID</th>
                                <th style="width:24%;text-align:left;">Product</th>
                                <th class="text-center" style="width:9%;text-align:center;">Qty Received</th>
                                <th class="text-end" style="width:10%;text-align:right;">Unit Cost</th>
                                <th class="text-end" style="width:10%;text-align:right;">Selling Price</th>
                                <th style="width:13%;text-align:left;">Approved By</th>
                                <th class="text-center" style="width:11%;text-align:center;">Approval Date</th>
                                <th class="text-center" style="width:10%;text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No stock-in approval records found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $st    = $r['status'] ?? 'Pending Approval';
                                $badge = 'bg-secondary';
                                if ($st === 'Approved') $badge = 'bg-success';
                                elseif ($st === 'Rejected') $badge = 'bg-danger';
                                elseif ($st === 'Pending Approval') $badge = 'bg-warning text-dark';
                            ?>
                                <tr>
                                    <td style="white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['batch_id'] ?? 'N/A') ?></code></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><strong><?= htmlspecialchars($r['product'] ?? '') ?></strong></td>
                                    <td class="text-center fw-bold" style="white-space:normal!important;padding:8px 5px!important;"><?= ((float)($r['qty_received'] ?? 0) == floor((float)($r['qty_received'] ?? 0))) ? number_format((float)($r['qty_received'] ?? 0)) : number_format((float)($r['qty_received'] ?? 0), 2) ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['unit_cost'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-success" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['selling_price'] ?? 0), 2) ?></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['approved_by'] ?? 'N/A') ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;font-size:11px;"><?= !empty($r['approval_date']) ? date('m/d/Y h:i A', strtotime($r['approval_date'])) : '-' ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;"><span class="badge <?= $badge ?>" style="font-size:10.5px;"><?= htmlspecialchars($st) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): 
                            $tot_q = (float)array_sum(array_column($rows, 'qty_received'));
                            $tot_q_str = (floor($tot_q) == $tot_q) ? number_format($tot_q) : number_format($tot_q, 2);
                        ?>
                        <tfoot>
                            <tr>
                                <td colspan="2" class="fw-bold text-end">SUMMARY TOTALS:</td>
                                <td class="text-center fw-bold"><?= $tot_q_str ?></td>
                                <td class="text-end fw-bold">₱<?= number_format(array_sum(array_column($rows, 'unit_cost')), 2) ?></td>
                                <td class="text-end fw-bold text-success">₱<?= number_format(array_sum(array_column($rows, 'selling_price')), 2) ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php
            }
            break;

        // 5. FINANCIAL REPORTS
        // =========================================================================
        case 'financial':

            // ─── 1. REVENUE SUMMARY REPORT ───────────────────────────────────
            if ($tab === 'revenue_summary') {
                $tot_fuel  = array_sum(array_column($rows, 'fuel_revenue'));
                $tot_merch = array_sum(array_column($rows, 'merchandise_revenue'));
                $tot_serv  = array_sum(array_column($rows, 'service_revenue'));
                $tot_gross = array_sum(array_column($rows, 'gross_revenue'));
                $tot_disc  = array_sum(array_column($rows, 'total_discounts'));
                $tot_net   = array_sum(array_column($rows, 'net_revenue'));
                ?>
                <div class="rpt-section-heading"><i class="fas fa-chart-line me-2"></i>REVENUE SUMMARY REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:16%;text-align:left;">Date</th>
                                <th class="text-end" style="width:14%;text-align:right;">Fuel Revenue</th>
                                <th class="text-end" style="width:14%;text-align:right;">Merchandise Revenue</th>
                                <th class="text-end" style="width:14%;text-align:right;">Service Revenue</th>
                                <th class="text-end" style="width:14%;text-align:right;">Gross Revenue</th>
                                <th class="text-end" style="width:14%;text-align:right;">Total Discounts</th>
                                <th class="text-end" style="width:14%;text-align:right;">Net Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No revenue records found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr>
                                    <td style="white-space:normal!important;padding:8px 5px!important;"><?= !empty($r['date']) ? date('m/d/Y', strtotime($r['date'])) : '-' ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['fuel_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['merchandise_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['service_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['gross_revenue'] ?? 0), 2) ?></td>
                                    <td class="text-end text-muted" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['total_discounts'] ?? 0), 2) ?></td>
                                    <td class="text-end fw-bold text-success" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['net_revenue'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td class="fw-bold">TOTALS:</td>
                                <td class="text-end fw-bold" style="white-space:nowrap!important;">₱<?= number_format($tot_fuel, 2) ?></td>
                                <td class="text-end fw-bold" style="white-space:nowrap!important;">₱<?= number_format($tot_merch, 2) ?></td>
                                <td class="text-end fw-bold" style="white-space:nowrap!important;">₱<?= number_format($tot_serv, 2) ?></td>
                                <td class="text-end fw-bold" style="white-space:nowrap!important;">₱<?= number_format($tot_gross, 2) ?></td>
                                <td class="text-end fw-bold text-muted" style="white-space:nowrap!important;">₱<?= number_format($tot_disc, 2) ?></td>
                                <td class="text-end fw-bold text-success" style="white-space:nowrap!important;">₱<?= number_format($tot_net, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- Revenue Summary Tax Breakdown & VAT Extraction Summary -->
                <?php
                $station_tax_cfg = function_exists('petron_get_station_tax_config') ? petron_get_station_tax_config() : ['is_vat_registered' => true, 'tax_rate' => 0.12];
                $rev_tax = function_exists('petron_calculate_taxes') ? petron_calculate_taxes($tot_net, 0.0) : ['subtotal_amount' => round($tot_net / 1.12, 2), 'vat_amount' => round($tot_net - round($tot_net / 1.12, 2), 2), 'non_vat_sales' => 0.0];
                $rev_vatable = $rev_tax['subtotal_amount'] ?? round($tot_net / 1.12, 2);
                $rev_vat_amt = $rev_tax['vat_amount'] ?? round($tot_net - $rev_vatable, 2);
                $rev_non_vat = $rev_tax['non_vat_sales'] ?? 0.0;
                ?>
                <div class="rpt-section-heading mt-4"><i class="fas fa-file-invoice-dollar me-2"></i>TAX BREAKDOWN &amp; VAT EXTRACTION SUMMARY (VAT-INCLUSIVE)</div>
                <div class="table-responsive mb-4">
                    <table class="rpt-table align-middle" style="max-width:600px;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Tax Classification</th>
                                <th class="text-end" style="text-align:right;">Amount (₱)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($station_tax_cfg['is_vat_registered']): ?>
                            <tr>
                                <td style="text-align:left;">Vatable Revenue Base (VAT-Exclusive)</td>
                                <td class="text-end fw-bold" style="text-align:right;">₱<?= number_format((float)$rev_vatable, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">Output Value-Added Tax (12% Extracted)</td>
                                <td class="text-end fw-bold text-primary" style="text-align:right;">₱<?= number_format((float)$rev_vat_amt, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">VAT-Exempt / Zero-Rated Revenue</td>
                                <td class="text-end" style="text-align:right;">₱0.00</td>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <td style="text-align:left;">Non-VAT / Exempt Revenue</td>
                                <td class="text-end fw-bold" style="text-align:right;">₱<?= number_format((float)$rev_non_vat, 2) ?></td>
                            </tr>
                            <tr>
                                <td style="text-align:left;">Output Value-Added Tax (0%)</td>
                                <td class="text-end" style="text-align:right;">₱0.00</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td style="text-align:left;" class="fw-bold">Total Net Revenue (VAT-Inclusive):</td>
                                <td class="text-end fw-bold text-success" style="text-align:right;">₱<?= number_format($tot_net, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php

            // ─── 2. ACCOUNTS RECEIVABLE REPORT ───────────────────────────────
            } elseif ($tab === 'receivables') {
                $tot_ar      = array_sum(array_column($rows, 'outstanding_balance'));
                $curr_ar     = 0;
                $overdue_ar  = 0;
                foreach ($rows as $r) {
                    $st = strtolower($r['status'] ?? '');
                    if ($st === 'overdue') {
                        $overdue_ar += (float)($r['outstanding_balance'] ?? 0);
                    } elseif ($st === 'current' || $st === 'due today') {
                        $curr_ar += (float)($r['outstanding_balance'] ?? 0);
                    }
                }
                ?>
                <div class="rpt-section-heading"><i class="fas fa-file-invoice-dollar me-2"></i>ACCOUNTS RECEIVABLE REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:18%;text-align:left;">Customer</th>
                                <th style="width:14%;text-align:left;">Account Type</th>
                                <th style="width:12%;text-align:left;">Invoice No.</th>
                                <th style="width:11%;text-align:center;">Transaction Date</th>
                                <th style="width:11%;text-align:center;">Due Date</th>
                                <th class="text-end" style="width:14%;text-align:right;">Outstanding Balance</th>
                                <th class="text-center" style="width:10%;text-align:center;">Days Overdue</th>
                                <th class="text-center" style="width:10%;text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No accounts receivable or credit records found.</td></tr>
                            <?php else: foreach ($rows as $r):
                                $st = strtolower($r['status'] ?? '');
                                $badge = 'bg-secondary';
                                if ($st === 'paid') $badge = 'bg-success';
                                elseif ($st === 'current') $badge = 'bg-info text-dark';
                                elseif ($st === 'due today') $badge = 'bg-warning text-dark';
                                elseif ($st === 'overdue') $badge = 'bg-danger';
                            ?>
                                <tr>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><strong><?= htmlspecialchars($r['customer'] ?? 'N/A') ?></strong></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['account_type'] ?? 'Credit Account (AR)') ?></td>
                                    <td style="white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['invoice_no'] ?? 'N/A') ?></code></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;font-size:11px;"><?= !empty($r['transaction_date']) ? date('m/d/Y', strtotime($r['transaction_date'])) : '-' ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;font-size:11px;"><?= !empty($r['due_date']) ? date('m/d/Y', strtotime($r['due_date'])) : '-' ?></td>
                                    <td class="text-end fw-bold text-danger" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['outstanding_balance'] ?? 0), 2) ?></td>
                                    <td class="text-center <?= (int)($r['days_overdue'] ?? 0) > 0 ? 'text-danger fw-bold' : '' ?>" style="white-space:normal!important;padding:8px 5px!important;"><?= number_format((int)($r['days_overdue'] ?? 0)) ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;"><span class="badge <?= $badge ?>" style="font-size:10.5px;"><?= htmlspecialchars($r['status'] ?? 'Current') ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="fw-bold text-end">TOTAL RECEIVABLES: ₱<?= number_format($tot_ar, 2) ?></td>
                                <td class="fw-bold text-info">CURRENT: ₱<?= number_format($curr_ar, 2) ?></td>
                                <td colspan="2" class="fw-bold text-danger">OVERDUE: ₱<?= number_format($overdue_ar, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php

            // ─── 3. PAYMENT COLLECTION REPORT ────────────────────────────────
            } elseif ($tab === 'payment_collections') {
                $tot_col = array_sum(array_column($rows, 'amount_paid'));
                ?>
                <div class="rpt-section-heading"><i class="fas fa-hand-holding-usd me-2"></i>PAYMENT COLLECTION REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:14%;text-align:left;">OR No.</th>
                                <th style="width:20%;text-align:left;">Customer</th>
                                <th style="width:14%;text-align:left;">Invoice No.</th>
                                <th class="text-center" style="width:12%;text-align:center;">Payment Method</th>
                                <th class="text-end" style="width:14%;text-align:right;">Amount Paid</th>
                                <th style="width:14%;text-align:left;">Collected By</th>
                                <th class="text-center" style="width:12%;text-align:center;">Payment Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No payment collection records found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr>
                                    <td style="white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['or_no'] ?? 'N/A') ?></code></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><strong><?= htmlspecialchars($r['customer'] ?? 'N/A') ?></strong></td>
                                    <td style="white-space:normal!important;word-break:break-all!important;padding:8px 5px!important;"><code style="font-size:10.5px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['invoice_no'] ?? 'N/A') ?></code></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;">
                                        <?php 
                                        $pm_disp = function_exists('format_payment_for_record') ? format_payment_for_record($r) : ['payment_type' => $r['payment_method'] ?? 'Cash', 'provider' => $r['ewallet_provider'] ?? '', 'reference_no' => $r['ewallet_reference'] ?? ''];
                                        ?>
                                        <span class="badge bg-secondary" style="font-size:10.5px;"><?= htmlspecialchars($pm_disp['payment_type']) ?></span>
                                        <?php if ($pm_disp['payment_type'] === 'E-Wallet' && !empty($pm_disp['provider'])): ?>
                                            <div class="small text-muted" style="font-size:9.5px;margin-top:2px;">Provider: <?= htmlspecialchars($pm_disp['provider']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold text-success" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['amount_paid'] ?? 0), 2) ?></td>
                                    <td style="white-space:normal!important;word-break:break-word!important;padding:8px 5px!important;"><?= htmlspecialchars($r['collected_by'] ?? 'N/A') ?></td>
                                    <td class="text-center" style="white-space:normal!important;padding:8px 5px!important;font-size:11px;"><?= !empty($r['payment_date']) ? date('m/d/Y h:i A', strtotime($r['payment_date'])) : '-' ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="fw-bold text-end">TOTAL COLLECTIONS:</td>
                                <td class="text-end fw-bold text-success" style="white-space:nowrap!important;">₱<?= number_format($tot_col, 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php

            // ─── 4. SALES VS COLLECTION REPORT ───────────────────────────────
            } else {
                $tot_sales  = array_sum(array_column($rows, 'total_sales'));
                $tot_csales = array_sum(array_column($rows, 'total_credit_sales'));
                $tot_cols   = array_sum(array_column($rows, 'total_collections'));
                $rem_rec    = array_sum(array_column($rows, 'outstanding_balance'));
                ?>
                <div class="rpt-section-heading"><i class="fas fa-balance-scale-right me-2"></i>SALES VS COLLECTION REPORT</div>
                <div class="table-responsive mb-4" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                        <thead>
                            <tr>
                                <th style="width:20%;text-align:left;">Date</th>
                                <th class="text-end" style="width:20%;text-align:right;">Total Sales</th>
                                <th class="text-end" style="width:20%;text-align:right;">Total Credit Sales</th>
                                <th class="text-end" style="width:20%;text-align:right;">Total Collections</th>
                                <th class="text-end" style="width:20%;text-align:right;">Outstanding Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No sales vs collection records found for this date range.</td></tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr>
                                    <td style="white-space:normal!important;padding:8px 5px!important;"><?= !empty($r['date']) ? date('m/d/Y', strtotime($r['date'])) : '-' ?></td>
                                    <td class="text-end fw-bold" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['total_sales'] ?? 0), 2) ?></td>
                                    <td class="text-end text-primary" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['total_credit_sales'] ?? 0), 2) ?></td>
                                    <td class="text-end text-success fw-bold" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['total_collections'] ?? 0), 2) ?></td>
                                    <td class="text-end text-danger fw-bold" style="white-space:nowrap!important;padding:8px 5px!important;">₱<?= number_format((float)($r['outstanding_balance'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rows)): ?>
                        <tfoot>
                            <tr>
                                <td class="fw-bold">TOTALS:</td>
                                <td class="text-end fw-bold" style="white-space:nowrap!important;">₱<?= number_format($tot_sales, 2) ?></td>
                                <td class="text-end fw-bold text-primary" style="white-space:nowrap!important;">₱<?= number_format($tot_csales, 2) ?></td>
                                <td class="text-end fw-bold text-success" style="white-space:nowrap!important;">₱<?= number_format($tot_cols, 2) ?></td>
                                <td class="text-end fw-bold text-danger" style="white-space:nowrap!important;">₱<?= number_format($rem_rec, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
                <?php
            }
            break;

        // =========================================================================
        // 6. CUSTOMER REPORTS
        // =========================================================================
        case 'customer':
            ?>
            <style>
            .cust-info-block { page-break-inside: avoid; }
            .cust-section-divider { border-top: 2.5px solid #1e3a5f; margin: 32px 0 20px; page-break-before: auto; }
            .cust-profile-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 18px; margin-bottom: 18px; }
            .cust-profile-card .info-row { display: flex; flex-wrap: wrap; gap: 0; }
            .cust-profile-card .info-item { flex: 0 0 33.333%; padding: 5px 0; font-size: 12px; }
            .cust-profile-card .info-label { color: #64748b; font-weight: 600; display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
            .cust-profile-card .info-value { color: #1e293b; font-weight: 500; font-size: 13px; }
            .cust-sub-heading { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #1e3a5f; border-bottom: 1px solid #cbd5e1; padding-bottom: 5px; margin: 14px 0 8px; }
            .cust-stats-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 6px; }
            .cust-stat-box { flex: 1 1 130px; background: #fff; border: 1px solid #e2e8f0; border-radius: 5px; padding: 8px 10px; text-align: center; }
            .cust-stat-box .stat-label { font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase; display: block; }
            .cust-stat-box .stat-val { font-size: 15px; font-weight: 700; color: #1e3a5f; display: block; margin-top: 2px; }
            @media print {
                .cust-section-divider { page-break-before: always; border-top: 2px solid #1e3a5f; margin-top: 0; }
                .cust-info-block { page-break-inside: avoid; }
                .no-print { display: none !important; }
            }
            </style>

            <?php
            // Details pre-fetched from data layer
            $all_details = $report_data['customer_details'] ?? [];
            ?>

            <!-- ===================== SECTION 1: CUSTOMER REPORT TABLE ===================== -->
            <h4 class="rpt-section-heading text-uppercase fw-bold border-bottom pb-2 mb-3" style="color:#1e3a5f;font-size:14px;letter-spacing:.5px;">
                <i class="fas fa-users me-2"></i>Customer Report Table
            </h4>
            <div class="table-responsive mb-5" style="overflow-x:hidden !important; width:100% !important;">
                <table class="rpt-table rpt-table-fixed align-middle" style="width:100%;table-layout:fixed;">
                    <thead>
                        <tr>
                            <th style="width:8%;text-align:left;">Customer ID</th>
                            <th style="width:14%;text-align:left;">Customer Name</th>
                            <th style="width:10%;text-align:left;">Contact No.</th>
                            <th class="text-center" style="width:7%;text-align:center;">Visits</th>
                            <th class="text-center" style="width:7%;text-align:center;">Txns</th>
                            <th class="text-center" style="width:7%;text-align:center;">Job Orders</th>
                            <th class="text-center" style="width:7%;text-align:center;">Merch</th>
                            <th class="text-end" style="width:11%;text-align:right;">Total Spent</th>
                            <th class="text-end" style="width:11%;text-align:right;">Balance</th>
                            <th class="text-center" style="width:8%;text-align:center;">Last Visit</th>
                            <th class="text-center" style="width:7%;text-align:center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="12" class="text-center py-4 text-muted">No customer records found.</td></tr>
                        <?php else:
                            $grand_total_spent   = 0;
                            $grand_total_balance = 0;
                            foreach ($rows as $r):
                                $grand_total_spent   += (float)($r['total_amount_spent'] ?? 0);
                                $grand_total_balance += (float)($r['outstanding_balance'] ?? 0);
                                $status_lc = strtolower($r['status'] ?? 'active');
                                $badge_cls = match(true) {
                                    str_contains($status_lc, 'active')   => 'bg-success',
                                    str_contains($status_lc, 'inactive') => 'bg-secondary',
                                    str_contains($status_lc, 'pending')  => 'bg-warning text-dark',
                                    default                              => 'bg-secondary',
                                };
                        ?>
                            <tr>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:8px 4px!important;"><code style="font-size:10px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['customer_id_code'] ?? '') ?></code></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:8px 4px!important;font-size:11.5px;"><strong><?= htmlspecialchars($r['customer_name'] ?? '') ?></strong></td>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:8px 4px!important;font-size:11px;"><?= htmlspecialchars($r['contact_no'] ?? 'N/A') ?></td>
                                <td class="text-center fw-bold" style="white-space:normal!important;padding:8px 4px!important;"><?= number_format((int)($r['total_visits'] ?? 0)) ?></td>
                                <td class="text-center" style="white-space:normal!important;padding:8px 4px!important;"><?= number_format((int)($r['total_transactions'] ?? 0)) ?></td>
                                <td class="text-center" style="white-space:normal!important;padding:8px 4px!important;"><?= number_format((int)($r['total_job_orders'] ?? 0)) ?></td>
                                <td class="text-center" style="white-space:normal!important;padding:8px 4px!important;"><?= number_format((int)($r['total_merch_purchases'] ?? 0)) ?></td>
                                <td class="text-end fw-bold text-success" style="white-space:nowrap!important;padding:8px 4px!important;font-size:11.5px;">₱<?= number_format((float)($r['total_amount_spent'] ?? 0), 2) ?></td>
                                <td class="text-end fw-bold <?= (float)($r['outstanding_balance'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>" style="white-space:nowrap!important;padding:8px 4px!important;font-size:11.5px;">
                                    ₱<?= number_format((float)($r['outstanding_balance'] ?? 0), 2) ?>
                                </td>
                                <td class="text-center" style="white-space:normal!important;padding:8px 4px!important;font-size:11px;"><?= htmlspecialchars($r['last_visit'] ? date('m/d/Y', strtotime($r['last_visit'])) : 'N/A') ?></td>
                                <td class="text-center" style="white-space:normal!important;padding:8px 4px!important;"><span class="badge <?= $badge_cls ?>" style="font-size:10px;"><?= htmlspecialchars(ucfirst($r['status'] ?? 'Active')) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold" style="background:#f1f5f9;">
                            <td colspan="8" class="text-end">Total:</td>
                            <td class="text-end text-success" style="white-space:nowrap!important;">₱<?= number_format($grand_total_spent ?? 0, 2) ?></td>
                            <td class="text-end text-danger" style="white-space:nowrap!important;">₱<?= number_format($grand_total_balance ?? 0, 2) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if (!empty($rows)): ?>
            <!-- ===================== SECTION 2: CUSTOMER INFORMATION REPORT ===================== -->
            <div class="cust-section-divider"></div>
            <h4 class="text-uppercase fw-bold mb-4" style="color:#1e3a5f;font-size:14px;letter-spacing:.5px;">
                <i class="fas fa-id-card me-2"></i>Customer Information Report
            </h4>

            <?php foreach ($rows as $ri => $r):
                $d     = $all_details[$r['id']] ?? [];
                $info  = $d['info']  ?? [];
                $vehs  = $d['vehicles'] ?? [];
                $jos   = $d['service_history'] ?? [];
                $merch = $d['merch_history'] ?? [];
                $pmts  = $d['payment_history'] ?? [];
                $ar    = $d['ar_history'] ?? [];
                $stats = $d['stats'] ?? [];
                $ctype_val = strtolower($info['type'] ?? '');
                $is_credit_fleet = str_contains($ctype_val, 'credit') || str_contains($ctype_val, 'fleet');
            ?>
            <div class="cust-info-block mb-5 <?= $ri > 0 ? 'mt-4' : '' ?>">

                <!-- Customer Header -->
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:38px;height:38px;border-radius:50%;background:#e2e8f0;color:#1e3a5f;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;border:1px solid #cbd5e1;">
                            <?= strtoupper(substr($r['customer_name'] ?? 'C', 0, 1)) ?>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold text-dark" style="font-size:15px;"><?= htmlspecialchars($r['customer_name'] ?? '') ?></h5>
                            <small class="text-muted" style="font-size:11px;">
                                <code style="font-size:11px;color:#1e3a5f;"><?= htmlspecialchars($r['customer_id_code'] ?? '') ?></code>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- 1. Customer Information -->
                <p class="cust-sub-heading"><i class="fas fa-user me-1"></i>Customer Information</p>
                <div class="cust-profile-card">
                    <div class="info-row">
                        <div class="info-item">
                            <span class="info-label">Customer ID</span>
                            <span class="info-value"><?= htmlspecialchars($info['customer_id'] ?? $r['customer_id_code'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Full Name</span>
                            <span class="info-value"><?= htmlspecialchars($info['name'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Contact Number</span>
                            <span class="info-value"><?= htmlspecialchars($info['contact_number'] ?? $info['phone'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Address</span>
                            <span class="info-value"><?= htmlspecialchars($info['address'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Date Registered</span>
                            <span class="info-value"><?= htmlspecialchars($info['registered_at'] ? date('m/d/Y', strtotime($info['registered_at'])) : ($info['created_at'] ? date('m/d/Y', strtotime($info['created_at'])) : 'N/A')) ?></span>
                        </div>
                    </div>
                </div>

                <!-- 2. Vehicle History -->
                <p class="cust-sub-heading"><i class="fas fa-car me-1"></i>Vehicle History</p>
                <div class="table-responsive mb-3" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed" style="font-size:11.5px;width:100%;table-layout:fixed;">
                        <thead><tr>
                            <th style="width:17%;text-align:left;">Plate No.</th>
                            <th style="width:17%;text-align:left;">Vehicle</th>
                            <th style="width:17%;text-align:left;">Brand</th>
                            <th style="width:17%;text-align:left;">Model</th>
                            <th class="text-center" style="width:14%;text-align:center;">Year</th>
                            <th class="text-center" style="width:18%;text-align:center;">Last Service</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($vehs)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-2" style="font-size:11px;">No vehicles registered.</td></tr>
                        <?php else: foreach ($vehs as $v): ?>
                            <tr>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:7px 4px!important;"><strong><?= htmlspecialchars($v['plate_number'] ?? 'N/A') ?></strong></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:7px 4px!important;"><?= htmlspecialchars($v['vehicle_type'] ?? 'Vehicle') ?></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:7px 4px!important;"><?= htmlspecialchars($v['brand'] ?? 'N/A') ?></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:7px 4px!important;"><?= htmlspecialchars($v['model'] ?? 'N/A') ?></td>
                                <td class="text-center" style="white-space:normal!important;padding:7px 4px!important;"><?= htmlspecialchars($v['year_model'] ?? 'N/A') ?></td>
                                <td class="text-center" style="white-space:normal!important;padding:7px 4px!important;"><?= htmlspecialchars($v['last_service'] ? date('m/d/Y', strtotime($v['last_service'])) : 'N/A') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 3. Job Order History -->
                <p class="cust-sub-heading"><i class="fas fa-tools me-1"></i>Job Order History</p>
                <div class="table-responsive mb-3" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed" style="font-size:11.5px;width:100%;table-layout:fixed;">
                        <thead><tr>
                            <th style="width:16%;text-align:left;">JO No.</th>
                            <th style="width:16%;text-align:center;">Date</th>
                            <th style="width:24%;text-align:left;">Service</th>
                            <th style="width:18%;text-align:left;">Mechanic</th>
                            <th class="text-center" style="width:12%;text-align:center;">Status</th>
                            <th class="text-end" style="width:14%;text-align:right;">Total Amount</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($jos)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-2" style="font-size:11px;">No job order history found.</td></tr>
                        <?php else:
                            $jo_total = 0;
                            foreach ($jos as $j):
                                $jo_total += (float)($j['amount'] ?? 0); ?>
                            <tr>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:7px 4px!important;"><code><?= htmlspecialchars($j['jo_no'] ?? 'N/A') ?></code></td>
                                <td class="text-center" style="white-space:normal!important;padding:7px 4px!important;"><?= htmlspecialchars($j['date'] ?? 'N/A') ?></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:7px 4px!important;"><?= htmlspecialchars($j['service'] ?? 'N/A') ?></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:7px 4px!important;"><?= htmlspecialchars($j['mechanic'] ?? 'Unassigned') ?></td>
                                <td class="text-center" style="white-space:normal!important;padding:7px 4px!important;"><span class="badge bg-secondary" style="font-size:10px;"><?= htmlspecialchars($j['status'] ?? 'N/A') ?></span></td>
                                <td class="text-end fw-bold text-success" style="white-space:nowrap!important;padding:7px 4px!important;">₱<?= number_format((float)($j['amount'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="fw-bold" style="background:#f8fafc;">
                                <td colspan="5" class="text-end">Total Job Order Amount:</td>
                                <td class="text-end text-success" style="white-space:nowrap!important;">₱<?= number_format($jo_total, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 4. Merchandise Purchase History -->
                <p class="cust-sub-heading"><i class="fas fa-shopping-cart me-1"></i>Merchandise Purchase History</p>
                <div class="table-responsive mb-3" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed" style="font-size:11.5px;width:100%;table-layout:fixed;">
                        <thead><tr>
                            <th style="width:18%;text-align:left;">Receipt No.</th>
                            <th style="width:18%;text-align:center;">Date</th>
                            <th style="width:32%;text-align:left;">Product</th>
                            <th class="text-center" style="width:14%;text-align:center;">Qty</th>
                            <th class="text-end" style="width:18%;text-align:right;">Amount</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($merch)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-2" style="font-size:11px;">No merchandise purchase history found.</td></tr>
                        <?php else:
                            $merch_total = 0;
                            foreach ($merch as $m):
                                $merch_total += (float)($m['amount'] ?? 0); ?>
                            <tr>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:7px 4px!important;"><code><?= htmlspecialchars($m['receipt_no'] ?? 'N/A') ?></code></td>
                                <td class="text-center" style="white-space:normal!important;padding:7px 4px!important;"><?= htmlspecialchars($m['date'] ?? 'N/A') ?></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:7px 4px!important;"><?= htmlspecialchars($m['product'] ?? 'N/A') ?></td>
                                <td class="text-center fw-bold" style="white-space:normal!important;padding:7px 4px!important;"><?= (int)($m['quantity'] ?? 0) ?></td>
                                <td class="text-end fw-bold text-primary" style="white-space:nowrap!important;padding:7px 4px!important;">₱<?= number_format((float)($m['amount'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="fw-bold" style="background:#f8fafc;">
                                <td colspan="4" class="text-end">Total Merchandise Amount:</td>
                                <td class="text-end text-primary" style="white-space:nowrap!important;">₱<?= number_format($merch_total, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 5. Payment History -->
                <p class="cust-sub-heading"><i class="fas fa-receipt me-1"></i>Payment History</p>
                <div class="table-responsive mb-3" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed" style="font-size:11.5px;width:100%;table-layout:fixed;">
                        <thead><tr>
                            <th style="width:18%;text-align:center;">Date</th>
                            <th style="width:18%;text-align:left;">OR No.</th>
                            <th style="width:22%;text-align:left;">Payment Method</th>
                            <th class="text-end" style="width:22%;text-align:right;">Amount</th>
                            <th class="text-center" style="width:20%;text-align:center;">Status</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($pmts)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-2" style="font-size:11px;">No payment history found.</td></tr>
                        <?php else:
                            $pmt_total = 0;
                            foreach ($pmts as $p):
                                $pmt_total += (float)($p['amount'] ?? 0); ?>
                            <tr>
                                <td class="text-center" style="white-space:normal!important;padding:7px 4px!important;"><?= htmlspecialchars($p['date'] ?? 'N/A') ?></td>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:7px 4px!important;"><code><?= htmlspecialchars($p['or_no'] ?? 'N/A') ?></code></td>
                                <td style="white-space:normal!important;word-break:break-word!important;padding:7px 4px!important;"><?= htmlspecialchars($p['payment_method'] ?? 'N/A') ?></td>
                                <td class="text-end fw-bold text-success" style="white-space:nowrap!important;padding:7px 4px!important;">₱<?= number_format((float)($p['amount'] ?? 0), 2) ?></td>
                                <td class="text-center" style="white-space:normal!important;padding:7px 4px!important;"><span class="badge bg-success" style="font-size:10px;"><?= htmlspecialchars($p['status'] ?? 'Completed') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="fw-bold" style="background:#f8fafc;">
                                <td colspan="3" class="text-end">Total Payments:</td>
                                <td class="text-end text-success" style="white-space:nowrap!important;">₱<?= number_format($pmt_total, 2) ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 6. Accounts Receivable History (Credit/Fleet only) -->
                <?php if ($is_credit_fleet): ?>
                <p class="cust-sub-heading" style="color:#b91c1c;"><i class="fas fa-file-invoice-dollar me-1"></i>Accounts Receivable History <small class="text-muted fw-normal">(Applicable for Credit Account / Fleet Card only)</small></p>
                <div class="table-responsive mb-3" style="overflow-x:hidden !important; width:100% !important;">
                    <table class="rpt-table rpt-table-fixed" style="font-size:11.5px;width:100%;table-layout:fixed;">
                        <thead><tr>
                            <th style="width:25%;text-align:left;">Invoice No.</th>
                            <th style="width:25%;text-align:center;">Due Date</th>
                            <th class="text-end" style="width:25%;text-align:right;">Outstanding Balance</th>
                            <th class="text-center" style="width:25%;text-align:center;">Status</th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($ar)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-2" style="font-size:11px;">No outstanding receivables.</td></tr>
                        <?php else:
                            $ar_total = 0;
                            foreach ($ar as $a):
                                $ar_total += (float)($a['balance'] ?? 0); ?>
                            <tr>
                                <td style="white-space:normal!important;word-break:break-all!important;padding:7px 4px!important;"><code><?= htmlspecialchars($a['invoice_no'] ?? 'N/A') ?></code></td>
                                <td class="text-center" style="white-space:normal!important;padding:7px 4px!important;"><?= htmlspecialchars($a['due_date'] ?? 'N/A') ?></td>
                                <td class="text-end fw-bold text-danger" style="white-space:nowrap!important;padding:7px 4px!important;">₱<?= number_format((float)($a['balance'] ?? 0), 2) ?></td>
                                <td class="text-center" style="white-space:normal!important;padding:7px 4px!important;"><span class="badge bg-warning text-dark" style="font-size:10px;"><?= htmlspecialchars($a['status'] ?? 'Current') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                            <tr class="fw-bold" style="background:#fff5f5;">
                                <td colspan="2" class="text-end">Total Outstanding Balance:</td>
                                <td class="text-end text-danger" style="white-space:nowrap!important;">₱<?= number_format($ar_total, 2) ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- 7. Customer Statistics -->
                <p class="cust-sub-heading"><i class="fas fa-chart-bar me-1"></i>Customer Statistics</p>
                <div class="cust-stats-grid">
                    <div class="cust-stat-box">
                        <span class="stat-label">Total Visits</span>
                        <span class="stat-val"><?= number_format((int)($stats['total_visits'] ?? 0)) ?></span>
                    </div>
                    <div class="cust-stat-box">
                        <span class="stat-label">Total Job Orders</span>
                        <span class="stat-val" style="color:#1d4ed8;"><?= number_format((int)($stats['total_job_orders'] ?? 0)) ?></span>
                    </div>
                    <div class="cust-stat-box">
                        <span class="stat-label">Merch Purchases</span>
                        <span class="stat-val" style="color:#0891b2;"><?= number_format((int)($stats['total_merch_purchases'] ?? 0)) ?></span>
                    </div>
                    <div class="cust-stat-box">
                        <span class="stat-label">Total Amount Spent</span>
                        <span class="stat-val" style="color:#16a34a;">₱<?= number_format((float)($stats['total_amount_spent'] ?? 0), 2) ?></span>
                    </div>
                    <div class="cust-stat-box">
                        <span class="stat-label">Avg. Spending / Visit</span>
                        <span class="stat-val" style="color:#7c3aed;">₱<?= number_format((float)($stats['average_spending'] ?? 0), 2) ?></span>
                    </div>
                    <div class="cust-stat-box">
                        <span class="stat-label">Last Visit Date</span>
                        <span class="stat-val" style="font-size:12px;color:#475569;"><?= htmlspecialchars($stats['last_visit'] ?? 'N/A') ?></span>
                    </div>
                </div>

                <?php if ($ri < count($rows) - 1): ?>
                <div class="cust-section-divider"></div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php
            break;





        // =========================================================================
        // 7. AUDIT REPORTS
        // =========================================================================
        case 'audit':
            $tab_labels = [
                'transaction_logs'     => ['icon' => 'fas fa-exchange-alt',   'title' => 'Transaction Logs'],
                'inventory_logs'       => ['icon' => 'fas fa-boxes',          'title' => 'Inventory Logs'],
                'approval_logs'        => ['icon' => 'fas fa-check-circle',   'title' => 'Approval Logs'],
                'login_history'        => ['icon' => 'fas fa-sign-in-alt',    'title' => 'Login History'],
                'archived_deactivated' => ['icon' => 'fas fa-archive',        'title' => 'Archived & Deactivated Logs'],
            ];
            $cur_tab = $tab_labels[$tab] ?? ['icon' => 'fas fa-history', 'title' => ucwords(str_replace('_', ' ', $tab))];
            ?>
            <h4 class="rpt-section-heading text-uppercase fw-bold border-bottom pb-2 mb-3" style="color:#1e3a5f;font-size:14px;letter-spacing:.5px;">
                <i class="<?= $cur_tab['icon'] ?> me-2"></i><?= htmlspecialchars($cur_tab['title']) ?>
            </h4>
            <div class="table-responsive" style="overflow-x:hidden !important; width:100% !important;">
                <table class="rpt-table rpt-table-fixed align-middle" style="font-size:12px;width:100%;table-layout:fixed;">

                <?php if ($tab === 'transaction_logs'): ?>
                    <thead><tr>
                        <th style="width:13%;text-align:left;">Date / Time</th>
                        <th style="width:12%;text-align:left;">Reference</th>
                        <th style="width:10%;text-align:left;">Module</th>
                        <th style="width:13%;text-align:left;">Action</th>
                        <th style="width:13%;text-align:left;">Performed By</th>
                        <th style="width:24%;text-align:left;">Details</th>
                        <th style="width:15%;text-align:center;">Status</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No transaction logs found for this period.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $mod = $r['module'] ?? 'Merchandise';
                        $mod_cls = match(strtolower($mod)) {
                            'fuel management'   => 'bg-info text-dark',
                            'job orders'        => 'bg-primary',
                            'fuel sales closing'=> 'bg-secondary',
                            'sales adjustments' => 'bg-warning text-dark',
                            'reports'           => 'bg-light text-dark border',
                            'customers'         => 'bg-info text-dark',
                            'inventory'         => 'bg-warning text-dark',
                            'procurement'       => 'bg-secondary text-white',
                            'user management'   => 'bg-dark text-white',
                            default             => 'bg-success',
                        };
                        $sts = strtolower($r['status'] ?? '');
                        $sts_cls = match(true) {
                            $sts === 'completed' || $sts === 'success' => 'bg-success',
                            $sts === 'cancelled' || $sts === 'voided'  => 'bg-danger',
                            default                                    => 'bg-warning text-dark',
                        };
                    ?>
                        <tr>
                            <td><small style="white-space:nowrap;"><?= ard_fmt_12h_datetime($r['datetime'] ?? 'N/A') ?></small></td>
                            <td><code style="font-size:10px;font-weight:700;"><?= htmlspecialchars($r['ref_no'] ?? 'N/A') ?></code></td>
                            <td><span class="badge <?= $mod_cls ?>" style="font-size:10px;"><?= htmlspecialchars($mod) ?></span></td>
                            <td><strong><?= htmlspecialchars($r['action'] ?? 'Created Transaction') ?></strong></td>
                            <td><?= htmlspecialchars($r['performed_by'] ?? 'Staff') ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($r['details'] ?? '—') ?></small></td>
                            <td class="text-center" style="text-align:center;"><span class="badge <?= $sts_cls ?>" style="font-size:10px;"><?= htmlspecialchars(ucfirst($r['status'] ?? 'Completed')) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>

                <?php elseif ($tab === 'inventory_logs'): ?>
                    <thead><tr>
                        <th style="width:12%;text-align:left;">Date / Time</th>
                        <th style="width:12%;text-align:left;">Reference</th>
                        <th style="width:14%;text-align:left;">Product / Item</th>
                        <th style="width:12%;text-align:left;">Movement Type</th>
                        <th class="text-center" style="width:9%;text-align:center;">Qty Change</th>
                        <th style="width:13%;text-align:left;">Performed By</th>
                        <th style="width:16%;text-align:left;">Details / Reason</th>
                        <th style="width:12%;text-align:center;">Status</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No inventory logs found for this period.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $mov = $r['movement_type'] ?? 'Movement';
                        $mov_cls = match(true) {
                            str_contains(strtolower($mov), 'stock in') || str_contains(strtolower($mov), 'delivery') || str_contains(strtolower($mov), 'received') => 'bg-success text-white',
                            str_contains(strtolower($mov), 'stock out') || str_contains(strtolower($mov), 'damage') => 'bg-danger text-white',
                            str_contains(strtolower($mov), 'purchase order') || str_contains(strtolower($mov), 'stock request') => 'bg-primary text-white',
                            str_contains(strtolower($mov), 'adjust') => 'bg-warning text-dark',
                            str_contains(strtolower($mov), 'physical') => 'bg-info text-dark',
                            default => 'bg-secondary text-white',
                        };
                        $chg = (float)($r['quantity_change'] ?? 0);
                        $sts = strtolower($r['status'] ?? '');
                        $sts_cls = match(true) {
                            str_contains($sts, 'approv') || in_array($sts, ['completed', 'official', 'fulfilled', 'validated', 'verified', 'resolved', 'accepted', 'released', 'stock-in complete', 'stock_in_complete', 'delivery successful', 'done']) => 'bg-success text-white',
                            str_contains($sts, 'reject') || str_contains($sts, 'cancel') || str_contains($sts, 'void') => 'bg-danger text-white',
                            str_contains($sts, 'pending stock-in') => 'bg-warning text-dark',
                            str_contains($sts, 'pending') || str_contains($sts, 'draft') => 'bg-info text-dark',
                            default => 'bg-secondary text-white',
                        };
                    ?>
                        <tr>
                            <td style="white-space:normal!important;word-break:break-word;"><small style="white-space:nowrap;"><?= ard_fmt_12h_datetime($r['datetime'] ?? 'N/A') ?></small></td>
                            <td style="white-space:normal!important;word-break:break-all;"><code style="font-size:10px;font-weight:700;word-break:break-all;"><?= htmlspecialchars($r['ref_no'] ?? 'N/A') ?></code></td>
                            <td style="white-space:normal!important;word-break:break-word;"><strong><?= htmlspecialchars($r['product'] ?? 'N/A') ?></strong></td>
                            <td style="white-space:normal!important;word-break:break-word;"><span class="badge <?= $mov_cls ?>" style="font-size:10px;"><?= htmlspecialchars($mov) ?></span></td>
                            <td class="text-center fw-bold <?= $chg >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= ($chg > 0 ? '+' : '') . number_format($chg, 2) ?>
                            </td>
                            <td style="white-space:normal!important;word-break:break-word;"><?= htmlspecialchars($r['performed_by'] ?? 'Staff') ?></td>
                            <td style="white-space:normal!important;word-break:break-word;"><small class="text-muted"><?= htmlspecialchars($r['details'] ?? '—') ?></small></td>
                            <td style="text-align:center;"><span class="badge <?= $sts_cls ?>" style="font-size:10px;"><?= htmlspecialchars(ucwords($r['status'] ?? 'Completed')) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>

                <?php elseif ($tab === 'approval_logs'): ?>
                    <thead><tr>
                        <th style="width:11%;text-align:left;">Date / Time</th>
                        <th style="width:12%;text-align:left;">Request Ref</th>
                        <th style="width:11%;text-align:left;">Workflow / Type</th>
                        <th style="width:10%;text-align:left;">Action / Decision</th>
                        <th style="width:13%;text-align:left;">Requested By</th>
                        <th style="width:13%;text-align:left;">Reviewed By</th>
                        <th style="width:17%;text-align:left;">Details / Feedback</th>
                        <th style="width:13%;text-align:center;">Status</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted" style="font-size:13.5px;">No approval logs found for this period.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $act = strtolower($r['action'] ?? '');
                        $act_cls = match(true) {
                            str_contains($act, 'approv') || str_contains($act, 'complet') || str_contains($act, 'releas') || str_contains($act, 'verifi') || str_contains($act, 'validat') => 'bg-success text-white',
                            str_contains($act, 'reject') || str_contains($act, 'cancel') => 'bg-danger text-white',
                            str_contains($act, 'adjust') => 'bg-warning text-dark',
                            str_contains($act, 'revis')  => 'bg-info text-dark',
                            default                      => 'bg-warning text-dark',
                        };
                        $act_icon = match(true) {
                            str_contains($act, 'approv') || str_contains($act, 'complet') || str_contains($act, 'releas') || str_contains($act, 'verifi') || str_contains($act, 'validat') => '<i class="fas fa-check-circle me-1"></i>',
                            str_contains($act, 'reject') || str_contains($act, 'cancel') => '<i class="fas fa-times-circle me-1"></i>',
                            str_contains($act, 'adjust') => '<i class="fas fa-sliders-h me-1"></i>',
                            str_contains($act, 'revis')  => '<i class="fas fa-edit me-1"></i>',
                            default                      => '<i class="fas fa-hourglass-half me-1"></i>',
                        };

                        $stat_low = strtolower($r['status'] ?? '');
                        $stat_cls = match(true) {
                            str_contains($stat_low, 'approv') || str_contains($stat_low, 'delivery successful') || in_array($stat_low, ['official','completed','fulfilled','validated','verified','resolved','accepted','released','stock-in complete','stock_in_complete','delivery successful','paid','done']) => 'bg-success text-white',
                            str_contains($stat_low, 'reject') || str_contains($stat_low, 'cancel') || str_contains($stat_low, 'return') => 'bg-danger text-white',
                            str_contains($stat_low, 'adjust') => 'bg-warning text-dark',
                            str_contains($stat_low, 'in progress') || str_contains($stat_low, 'inprogress') => 'bg-info text-dark',
                            default => 'bg-secondary text-white',
                        };

                        $dt_raw = $r['datetime'] ?? '';
                        $dt_ts  = !empty($dt_raw) && $dt_raw !== 'N/A' ? strtotime($dt_raw) : false;
                        $dt_d   = $dt_ts ? date('M d, Y', $dt_ts) : ($dt_raw ?: 'N/A');
                        $dt_t   = $dt_ts ? date('h:i:s A', $dt_ts) : '';
                    ?>
                        <tr>
                            <td style="white-space:normal!important;word-break:break-word!important;overflow-wrap:break-word!important;padding:8px 5px!important;">
                                <div style="font-weight:700;font-size:12px;color:#1e293b;line-height:1.2;"><?= htmlspecialchars($dt_d) ?></div>
                                <?php if ($dt_t): ?>
                                    <div style="font-size:11px;color:#64748b;margin-top:2px;line-height:1.1;"><i class="far fa-clock me-1"></i><?= htmlspecialchars($dt_t) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:normal!important;word-break:break-all!important;overflow-wrap:break-word!important;padding:8px 5px!important;">
                                <code style="font-size:10.5px;font-weight:800;color:#0f172a;background:#f1f5f9;padding:2px 4px;border-radius:4px;border:1px solid #e2e8f0;display:inline-block;word-break:break-all;"><?= htmlspecialchars($r['request_no'] ?? 'N/A') ?></code>
                            </td>
                            <td style="white-space:normal!important;word-break:break-word!important;overflow-wrap:break-word!important;padding:8px 5px!important;">
                                <span class="badge bg-light text-dark border" style="font-size:10.5px;font-weight:700;padding:4px 6px;white-space:normal!important;display:inline-block;line-height:1.2;text-align:left;"><?= htmlspecialchars($r['request_type'] ?? 'Request') ?></span>
                            </td>
                            <td style="white-space:normal!important;word-break:break-word!important;overflow-wrap:break-word!important;padding:8px 5px!important;">
                                <span class="badge <?= $act_cls ?>" style="font-size:10.5px;font-weight:700;padding:4px 6px;white-space:normal!important;display:inline-block;line-height:1.2;"><?= $act_icon ?><?= htmlspecialchars($r['action'] ?? 'Pending Review') ?></span>
                            </td>
                            <td style="font-size:12px;font-weight:600;color:#1e293b;white-space:normal!important;word-break:break-word!important;overflow-wrap:break-word!important;line-height:1.25;padding:8px 5px!important;">
                                <?= htmlspecialchars($r['requested_by'] ?? 'Staff') ?>
                            </td>
                            <td style="font-size:12px;font-weight:600;color:#334155;white-space:normal!important;word-break:break-word!important;overflow-wrap:break-word!important;line-height:1.25;padding:8px 5px!important;">
                                <?= htmlspecialchars($r['reviewed_by'] ?? '—') ?>
                            </td>
                            <td style="font-size:11.5px;color:#334155;line-height:1.35;word-break:break-word!important;overflow-wrap:break-word!important;white-space:normal!important;padding:8px 5px!important;">
                                <?= htmlspecialchars($r['details'] ?? '—') ?>
                            </td>
                            <td style="text-align:center;padding:8px 5px!important;white-space:normal!important;">
                                <span class="badge <?= $stat_cls ?>" style="font-size:10.5px;font-weight:700;padding:4px 6px;white-space:normal!important;display:inline-block;"><?= htmlspecialchars(ucfirst($r['status'] ?? 'Pending')) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>

                <?php elseif ($tab === 'login_history'): ?>
                    <thead><tr>
                        <th style="width:13%;text-align:left;">Date / Time</th>
                        <th style="width:14%;text-align:left;">User</th>
                        <th style="width:10%;text-align:left;">Role</th>
                        <th style="width:15%;text-align:left;">Event / Action</th>
                        <th style="width:13%;text-align:left;">IP Address</th>
                        <th style="width:12%;text-align:center;">Status</th>
                        <th style="width:23%;text-align:left;">Reason / Details</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No login or authentication history found.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $st = strtolower($r['status'] ?? '');
                        $st_cls = match(true) {
                            $st === 'success' => 'bg-success',
                            $st === 'failed'  => 'bg-danger',
                            default           => 'bg-secondary',
                        };
                    ?>
                        <tr>
                            <td style="white-space:normal!important;word-break:break-word;"><small style="white-space:nowrap;"><?= ard_fmt_12h_datetime($r['datetime'] ?? 'N/A') ?></small></td>
                            <td style="white-space:normal!important;word-break:break-word;"><strong><?= htmlspecialchars($r['user'] ?? 'N/A') ?></strong></td>
                            <td style="white-space:normal!important;word-break:break-word;"><span class="badge bg-secondary" style="font-size:10px;"><?= htmlspecialchars($r['role'] ?? 'Staff') ?></span></td>
                            <td style="white-space:normal!important;word-break:break-word;"><strong><?= htmlspecialchars($r['action'] ?? 'Login') ?></strong></td>
                            <td style="white-space:normal!important;word-break:break-all;"><code style="font-size:10px;"><?= htmlspecialchars($r['ip_address'] ?? 'N/A') ?></code></td>
                            <td style="text-align:center;"><span class="badge <?= $st_cls ?>" style="font-size:10px;"><?= htmlspecialchars(ucfirst($r['status'] ?? 'Success')) ?></span></td>
                            <td style="white-space:normal!important;word-break:break-word;"><small class="text-muted"><?= htmlspecialchars($r['reason'] ?? '—') ?></small></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>

                <?php else: // archived_deactivated ?>
                    <thead><tr>
                        <th style="width:13%;text-align:left;">Date / Time</th>
                        <th style="width:11%;text-align:left;">Record Type</th>
                        <th style="width:13%;text-align:left;">Reference</th>
                        <th style="width:12%;text-align:left;">Action Done</th>
                        <th style="width:14%;text-align:left;">Performed By</th>
                        <th style="width:24%;text-align:left;">Reason / Details</th>
                        <th style="width:13%;text-align:center;">Status</th>
                    </tr></thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No archived or deactivated records found for this period.</td></tr>
                    <?php else: foreach ($rows as $r):
                        $act = strtolower($r['action'] ?? '');
                        $act_cls = match(true) {
                            str_contains($act, 'archive')   => 'bg-warning text-dark',
                            str_contains($act, 'deactivat') || str_contains($act, 'disable') || str_contains($act, 'inactive') => 'bg-danger',
                            str_contains($act, 'reactivat') || str_contains($act, 'restore') || str_contains($act, 'activat') || str_contains($act, 'active') || str_contains($act, 'enable') => 'bg-success',
                            default                         => 'bg-secondary',
                        };

                        $st_low = strtolower($r['status'] ?? '');
                        $st_cls = match(true) {
                            str_contains($st_low, 'deactivat') || str_contains($st_low, 'disable') || str_contains($st_low, 'inactive') => 'bg-danger',
                            str_contains($st_low, 'archive') => 'bg-warning text-dark',
                            str_contains($st_low, 'active') || str_contains($st_low, 'restore') || str_contains($st_low, 'reactivat') || str_contains($st_low, 'enable') => 'bg-success',
                            default => 'bg-secondary',
                        };
                    ?>
                        <tr>
                            <td><small style="white-space:nowrap;"><?= ard_fmt_12h_datetime($r['datetime'] ?? 'N/A') ?></small></td>
                            <td><span class="badge bg-secondary" style="font-size:10px;"><?= htmlspecialchars($r['entity_type'] ?? 'Record') ?></span></td>
                            <td><code style="font-size:10px;font-weight:700;"><?= htmlspecialchars($r['ref_no'] ?? 'N/A') ?></code></td>
                            <td><span class="badge <?= $act_cls ?>" style="font-size:10px;"><?= htmlspecialchars($r['action'] ?? 'Archived') ?></span></td>
                            <td><?= htmlspecialchars($r['performed_by'] ?? 'Admin') ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($r['details'] ?? '—') ?></small></td>
                            <td class="text-center" style="text-align:center;"><span class="badge <?= $st_cls ?>" style="font-size:10px;"><?= htmlspecialchars(ucfirst($r['status'] ?? 'Archived')) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>

                <?php endif; ?>
                </table>
            </div>
            <?php
            break;
    }
}

