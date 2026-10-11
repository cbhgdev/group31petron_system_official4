<?php
/**
 * Helper to render unified Job Order Tracker rows.
 * Shared between initial page render and live AJAX auto-refresh polling.
 */

if (!function_exists('render_job_order_tracker_rows')) {
    function render_job_order_tracker_rows($job_orders, $pending_requests = [], $inv_impact = [], $role = 'staff', $enable_void_transaction = true) {
        if (empty($job_orders)) {
            return '<tr id="joEmptyRow">
                <td colspan="9" style="text-align:center;padding:40px;color:#94a3b8;background:#fff;">
                    <i class="fas fa-clipboard" style="font-size:28px;display:block;margin-bottom:8px;"></i>
                    No job orders found.
                </td>
            </tr>';
        }

        ob_start();
        foreach ($job_orders as $job):
            $val_status  = $job['validation_status'] ?? 'Pending Validation';
            $wf_status   = $job['status'] ?? 'Pending';
            $pay_status  = $job['payment_status'] ?? 'Pending';
            $remarks     = $job['rejection_remarks'] ?? $job['notes'] ?? $job['additional_notes'] ?? '';

            $inv_key     = ($job['_source'] ?? 'job_orders') . ':' . $job['id'];
            $pending_req = $pending_requests[$inv_key] ?? null;

            // Canonical workflow status (strict state machine: Pending -> In Progress -> Completed -> Released)
            $raw_wf_lower = strtolower(trim($wf_status ?? ''));
            if ($raw_wf_lower === 'released') {
                $canonical_status = 'Released';
            } elseif (in_array($raw_wf_lower, ['completed', 'finished', 'done'])) {
                $canonical_status = 'Completed';
            } elseif (in_array($raw_wf_lower, ['in progress', 'inprogress', 'active', 'ongoing'])) {
                $canonical_status = 'In Progress';
            } elseif (in_array($raw_wf_lower, ['waiting for parts', 'waiting_for_parts'])) {
                $canonical_status = 'Waiting for Parts';
            } elseif (in_array($raw_wf_lower, ['voided', 'cancelled', 'canceled', 'rejected']) || strtolower($val_status) === 'voided') {
                $canonical_status = 'Voided';
            } else {
                $canonical_status = 'Pending';
            }

            // Determine combined workflow label + badge style
            if ($pending_req) {
                if (($pending_req['request_type'] ?? '') === 'Adjustment') {
                    $wf_color='#d97706'; $wf_bg='#fef3c7'; $wf_label='Adjustment Requested'; $row_filter='adj_requested';
                } elseif (($pending_req['request_type'] ?? '') === 'Void') {
                    $wf_color='#dc2626'; $wf_bg='#fee2e2'; $wf_label='Void Requested'; $row_filter='void_requested';
                }
            } elseif (in_array(strtolower($val_status), ['adjusted']) || in_array(strtolower($wf_status), ['adjusted'])) {
                $wf_color='#4338ca'; $wf_bg='#e0e7ff'; $wf_label='Adjusted'; $row_filter='adjusted';
            } elseif ($canonical_status === 'Voided') {
                $wf_color='#991b1b'; $wf_bg='#fee2e2'; $wf_label='Voided'; $row_filter='voided';
            } elseif ($canonical_status === 'Released') {
                $wf_color='#475569'; $wf_bg='#f1f5f9'; $wf_label='Released'; $row_filter='released';
            } elseif ($canonical_status === 'Completed') {
                $wf_color='#16a34a'; $wf_bg='#dcfce7'; $wf_label='Completed'; $row_filter='completed';
            } elseif ($canonical_status === 'In Progress') {
                $wf_color='#8b5cf6'; $wf_bg='#ede9fe'; $wf_label='In Progress'; $row_filter='inprogress';
            } elseif ($canonical_status === 'Waiting for Parts') {
                $wf_color='#2563eb'; $wf_bg='#dbeafe'; $wf_label='Waiting for Parts'; $row_filter='waiting_for_parts';
            } else {
                $wf_color='#d97706'; $wf_bg='#fef3c7'; $wf_label='Pending'; $row_filter='pending';
            }

            // Payment badge — supports both new and legacy status values
            $pay_status_lower = strtolower(trim($pay_status));
            if (in_array($pay_status_lower, ['paid', 'completed'])) {
                $pay_bg = '#dcfce7'; $pay_color = '#166534'; $pay_border = '#86efac'; $pay_icon = 'fa-check-circle'; $pay_label = 'PAID';
            } elseif (in_array($pay_status_lower, ['partially paid', 'partial payment', 'partial'])) {
                $pay_bg = '#fef9c3'; $pay_color = '#854d0e'; $pay_border = '#fde047'; $pay_icon = 'fa-adjust'; $pay_label = 'PARTIAL';
            } elseif (in_array($pay_status_lower, ['credit account', 'credit transaction', 'credit', 'receivables/credit', 'a/r', 'ar'])) {
                $pay_bg = '#ede9fe'; $pay_color = '#5b21b6'; $pay_border = '#c4b5fd'; $pay_icon = 'fa-file-invoice-dollar'; $pay_label = 'A/R CREDIT';
            } else {
                $pay_bg = '#fee2e2'; $pay_color = '#b91c1c'; $pay_border = '#fca5a5'; $pay_icon = 'fa-clock'; $pay_label = 'UNPAID';
            }

            // Parts/items
            $parts_raw = $job['required_parts'] ?? '';
            $parts_display = '—';
            if (!empty($parts_raw)) {
                $decoded = json_decode($parts_raw, true);
                if (is_array($decoded)) {
                    $parts_display = implode(', ', array_map(fn($p) => is_array($p) ? ($p['name'] ?? $p['part_name'] ?? '') : $p, $decoded));
                } else {
                    $parts_display = $parts_raw;
                }
                if (strlen($parts_display) > 60) $parts_display = substr($parts_display, 0, 57) . '…';
            }

            // Receivables: due date + overdue
            $jo_due_date  = $job['due_date'] ?? null;
            $jo_is_overdue = false;
            if ($jo_due_date && !in_array($pay_status, ['Paid','Completed'])) {
                $jo_is_overdue = (strtotime(date('Y-m-d')) >= strtotime($jo_due_date));
            }
            $jo_total_for_bal   = (float)($job['total_cost'] ?? $job['estimated_cost'] ?? 0);
            $jo_paid_for_bal    = (float)($job['amount_paid'] ?? 0);
            $jo_balance_display = (float)($job['balance_due'] ?? 0);
            if ($pay_label === 'PAID') {
                $jo_balance_display = 0.00;
                if ($jo_paid_for_bal <= 0) $jo_paid_for_bal = $jo_total_for_bal;
            } elseif ($jo_balance_display <= 0.009 && $jo_total_for_bal > 0) {
                $jo_balance_display = max(0, $jo_total_for_bal - $jo_paid_for_bal);
            }

            // Separated remarks
            $staff_remark   = trim($job['staff_remarks'] ?? $job['notes'] ?? $job['job_order_description'] ?? '');
            $manager_remark = trim($job['manager_notes'] ?? $job['admin_remarks'] ?? '');
            $remarks        = !empty($staff_remark) ? $staff_remark : (!empty($manager_remark) ? $manager_remark : '');

            // Shift Indicator
            $shift_name_raw = trim($job['shift_name'] ?? $job['shift_period'] ?? '');
            if (!empty($shift_name_raw)) {
                $shift_label    = $shift_name_raw;
                $shift_key_data = (stripos($shift_name_raw,'2') !== false || stripos($shift_name_raw,'pm') !== false || stripos($shift_name_raw,'night') !== false) ? 'shift2' : 'shift1';
            } else {
                $created_hour = (int)date('H', strtotime($job['created_at']));
                if ($created_hour >= 6 && $created_hour < 18) {
                    $shift_label = 'Shift 1'; $shift_key_data = 'shift1';
                } else {
                    $shift_label = 'Shift 2'; $shift_key_data = 'shift2';
                }
            }

            $pay_method = trim($job['payment_method'] ?? 'Cash');
            if (empty($pay_method)) $pay_method = 'Cash';
            $pml = strtolower($pay_method);
            $pay_method_icon = 'fa-money-bill-wave';
            $pay_method_color = '#16a34a';
            if (str_contains($pml, 'gcash') || str_contains($pml, 'maya') || str_contains($pml, 'wallet')) {
                $pay_method_icon = 'fa-mobile-alt';
                $pay_method_color = '#0284c7';
            } elseif (str_contains($pml, 'card')) {
                $pay_method_icon = 'fa-credit-card';
                $pay_method_color = '#4f46e5';
            } elseif (str_contains($pml, 'credit') || str_contains($pml, 'receivable') || str_contains($pml, 'utang')) {
                $pay_method_icon = 'fa-file-invoice-dollar';
                $pay_method_color = '#9333ea';
            } elseif (str_contains($pml, 'fleet')) {
                $pay_method_icon = 'fa-id-card';
                $pay_method_color = '#0d9488';
            }

            $est_comp = $job['due_date'] ?? null;
            $created_time = !empty($job['created_at']) ? strtotime($job['created_at']) : time();
            $est_disp = '';
            $est_sub = '';

            if ($est_comp && $est_comp !== '0000-00-00' && $est_comp !== '0000-00-00 00:00:00') {
                $ts = strtotime($est_comp);
                if ($ts && $ts > 0) {
                    $est_disp = date('M j, Y', $ts);
                    $est_sub = date('h:i A', $ts);
                }
            }

            if (empty($est_disp)) {
                $duration_mins = (int)($job['estimated_duration'] ?? 0);
                if ($duration_mins > 0) {
                    $completion_ts = $created_time + ($duration_mins * 60);
                    $est_disp = date('M j, Y', $completion_ts);
                    $est_sub = date('h:i A', $completion_ts) . ' (' . $duration_mins . 'm)';
                } else {
                    $est_disp = '—';
                    $est_sub = '';
                }
            }

            $comp_ts = strtotime($est_disp);
            $today_ts = strtotime(date('Y-m-d'));
            $color = ($comp_ts < $today_ts && !in_array($wf_status ?? '', ['Completed'])) ? '#dc2626' : '#1e293b';
            $mech = trim($job['mechanic_name'] ?? '');
        ?>
        <tr
            class="jo-data-row"
            id="joRow_<?= htmlspecialchars(($job['_source'] ?? 'job_orders') . '_' . $job['id']) ?>"
            data-jo-id="<?= (int)$job['id'] ?>"
            data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
            data-jo-raw-status="<?= htmlspecialchars($canonical_status) ?>"
            data-jo-filter="<?= $row_filter ?>"
            data-jo-shift="<?= $shift_key_data ?>"
            data-jo-type="<?= htmlspecialchars($job['transaction_type'] ?? 'job_order') ?>"
            data-jo-mechanic="<?= htmlspecialchars($job['mechanic_name'] ?? '') ?>"
            data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
            data-jo-date="<?= date('Y-m-d', strtotime($job['created_at'])) ?>"
            data-jo-total="<?= (float)($job['total_cost'] ?? $job['estimated_cost'] ?? 0) ?>"
            data-jo-paid="<?= (float)$jo_paid_for_bal ?>"
            data-jo-balance="<?= (float)$jo_balance_display ?>"
            data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
            data-jo-paymethod="<?= htmlspecialchars($pay_method) ?>"
            data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '') ?>"
            data-jo-search="<?= htmlspecialchars(strtolower(implode(' ', [
                $job['job_order_id'] ?? ('#'.$job['id']),
                $job['customer_name'] ?? '',
                $job['vehicle_plate'] ?? '',
                $job['service_type'] ?? '',
            ]))) ?>"
            style="<?= $canonical_status === 'Voided' ? 'background:#fff8f8;' : ($jo_is_overdue ? 'background:#fff5f5;border-left:3px solid #dc2626;' : '') ?>">

            <!-- 1. JO # / OR NO. -->
            <td style="padding:11px 8px;vertical-align:middle;box-sizing:border-box;">
                <?php
                $jo_raw_id = trim($job['job_order_id'] ?? '');
                if (empty($jo_raw_id) || is_numeric($jo_raw_id) || $jo_raw_id === '#'.$job['id']) {
                    $jo_num_disp = 'JO-' . str_pad($job['id'], 6, '0', STR_PAD_LEFT);
                } else {
                    $jo_num_disp = $jo_raw_id;
                }
                ?>
                <div style="font-weight:800;font-size:13.5px;color:<?= $wf_status==='Rejected' ? '#dc2626' : '#002F70' ?>;line-height:1.2;white-space:nowrap;">
                    <?= htmlspecialchars($jo_num_disp) ?>
                </div>
                <div style="font-family:monospace;font-size:11.5px;font-weight:700;color:#475569;margin-top:3px;white-space:nowrap;">
                    <?php
                    $jo_or = '';
                    if (!empty($job['created_at'])) {
                        $jo_or = 'OR-' . date('Y', strtotime($job['created_at'])) . '-' . str_pad($job['id'], 6, '0', STR_PAD_LEFT);
                    }
                    echo $jo_or ? htmlspecialchars($jo_or) : '<span style="color:#cbd5e1;">—</span>';
                    ?>
                </div>
                <div style="margin-top:4px;">
                    <?php if (($job['transaction_type'] ?? '') === 'combined'): ?>
                        <span style="display:inline-flex;align-items:center;gap:3px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:800;white-space:nowrap;">
                            <i class="fas fa-tools" style="font-size:9px;"></i> JO + Merch
                        </span>
                    <?php else: ?>
                        <span style="display:inline-flex;align-items:center;gap:3px;background:#f8fafc;color:#475569;border:1px solid #cbd5e1;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:800;white-space:nowrap;">
                            <i class="fas fa-wrench" style="font-size:9px;"></i> Job Order
                        </span>
                    <?php endif; ?>
                </div>
            </td>

            <!-- 2. CUSTOMER & VEHICLE -->
            <td style="padding:11px 8px;vertical-align:middle;box-sizing:border-box;">
                <div style="font-weight:700;font-size:13.5px;color:#0f172a;line-height:1.25;word-break:break-word;">
                    <?= htmlspecialchars($job['customer_name'] ?? '—') ?>
                </div>
                <?php if (!empty($job['vehicle_plate']) || !empty($job['vehicle_type'])): ?>
                <div style="display:inline-flex;align-items:center;flex-wrap:wrap;gap:4px 6px;background:#f1f5f9;border:1px solid #cbd5e1;color:#1e293b;padding:3px 7px;border-radius:4px;font-size:11.5px;font-weight:700;margin-top:4px;max-width:100%;box-sizing:border-box;line-height:1.35;">
                    <span style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">
                        <i class="fas fa-car" style="color:#2563eb;font-size:10px;flex-shrink:0;"></i>
                        <span><?= htmlspecialchars($job['vehicle_plate'] ?? '—') ?></span>
                    </span>
                    <?php if (!empty($job['vehicle_type'])): ?>
                        <span style="color:#64748b;font-weight:600;word-break:break-word;">• <?= htmlspecialchars($job['vehicle_type']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </td>

            <!-- 3. SERVICE & MECHANIC -->
            <td style="padding:11px 8px;vertical-align:middle;box-sizing:border-box;">
                <div style="font-weight:700;font-size:13px;color:#0369a1;line-height:1.25;word-break:break-word;">
                    <?= htmlspecialchars($job['service_type'] ?? '—') ?>
                </div>
                <div style="font-size:11.5px;color:#334155;font-weight:600;margin-top:3px;display:flex;align-items:center;gap:4px;white-space:nowrap;">
                    <i class="fas fa-user-cog" style="color:#64748b;font-size:11px;flex-shrink:0;"></i>
                    <span><?= htmlspecialchars($mech && $mech !== 'Unassigned' ? $mech : 'Unassigned') ?></span>
                </div>
            </td>

            <!-- 4. PRODUCTS / MERCHANDISE -->
            <td style="padding:8px 5px;vertical-align:middle;box-sizing:border-box;">
                <?php if (!empty($parts_display) && $parts_display !== '—'): ?>
                    <div style="font-weight:700;font-size:11.5px;color:#0f172a;line-height:1.25;word-break:break-word;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($parts_display) ?>">
                        <i class="fas fa-box" style="color:#0284c7;font-size:10px;margin-right:3px;flex-shrink:0;"></i>
                        <span><?= htmlspecialchars($parts_display) ?></span>
                    </div>
                <?php else: ?>
                    <span style="color:#94a3b8;font-size:11px;">—</span>
                <?php endif; ?>
            </td>

            <!-- 5. FEES BREAKDOWN -->
            <td style="padding:11px 8px;vertical-align:middle;box-sizing:border-box;">
                <div style="color:#334155;font-size:12px;white-space:nowrap;font-variant-numeric:tabular-nums;">Svc: <strong style="color:#002F70;font-weight:800;font-size:13px;">₱<?= number_format((float)($job['service_fee'] ?? $job['estimated_cost'] ?? $job['total_cost'] ?? 0), 2) ?></strong></div>
                <?php
                $labor_val = (float)($job['actual_labor_cost'] ?? $job['estimated_labor_cost'] ?? 0);
                ?>
                <div style="color:#334155;font-size:11.5px;margin-top:3px;white-space:nowrap;font-variant-numeric:tabular-nums;">Labor: <strong style="color:<?= $labor_val > 0 ? '#16a34a' : '#94a3b8' ?>;font-weight:800;font-size:12px;"><?= $labor_val > 0 ? '₱' . number_format($labor_val, 2) : '—' ?></strong></div>
            </td>

            <!-- 6. PAYMENT -->
            <td style="padding:11px 10px;vertical-align:middle;box-sizing:border-box;">
                <div style="display:flex;align-items:center;gap:5px;font-weight:700;font-size:12.5px;color:#1e293b;white-space:nowrap;" title="<?= htmlspecialchars($pay_method) ?>">
                    <i class="fas <?= $pay_method_icon ?>" style="color:<?= $pay_method_color ?>;font-size:11.5px;flex-shrink:0;"></i>
                    <span style="overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($pay_method) ?></span>
                </div>
                <div style="margin-top:4px;">
                    <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:5px;font-size:10.5px;font-weight:800;background:<?= $pay_bg ?>;color:<?= $pay_color ?>;border:1px solid <?= $pay_border ?>;letter-spacing:0.3px;white-space:nowrap;">
                        <i class="fas <?= $pay_icon ?>" style="font-size:9.5px;"></i>
                        <?= htmlspecialchars($pay_label) ?>
                    </span>
                </div>
                <?php if ($pay_label === 'PARTIAL' || $pay_label === 'PARTIALLY PAID'): ?>
                    <div style="font-size:11px;color:#166534;font-weight:600;margin-top:4px;line-height:1.2;white-space:nowrap;">
                        Paid: <strong style="font-weight:800;">₱<?= number_format($jo_paid_for_bal, 2) ?></strong>
                    </div>
                    <div style="font-size:11px;color:#b91c1c;font-weight:700;margin-top:2px;line-height:1.2;white-space:nowrap;">
                        Bal: <strong style="font-weight:800;">₱<?= number_format($jo_balance_display, 2) ?></strong>
                    </div>
                <?php elseif ($pay_label === 'PAID'): ?>
                    <div style="font-size:11px;color:#166534;font-weight:600;margin-top:3px;line-height:1.2;white-space:nowrap;">
                        ₱<?= number_format($jo_total_for_bal > 0 ? $jo_total_for_bal : $jo_paid_for_bal, 2) ?>
                    </div>
                <?php elseif ($pay_label === 'A/R' || $pay_label === 'A/R CREDIT'): ?>
                    <div style="font-size:11px;color:#5b21b6;font-weight:700;margin-top:3px;line-height:1.2;white-space:nowrap;">
                        Bal: ₱<?= number_format($jo_balance_display, 2) ?>
                    </div>
                <?php else: ?>
                    <div style="font-size:11px;color:#b91c1c;font-weight:700;margin-top:3px;line-height:1.2;white-space:nowrap;">
                        Bal: ₱<?= number_format($jo_balance_display > 0 ? $jo_balance_display : $jo_total_for_bal, 2) ?>
                    </div>
                <?php endif; ?>
            </td>

            <!-- 7. SCHEDULE & DATE -->
            <td style="padding:11px 8px;vertical-align:middle;box-sizing:border-box;">
                <div style="font-weight:700;font-size:12.5px;color:#0f172a;line-height:1.2;white-space:nowrap;">
                    <?= date('M j, Y', strtotime($job['created_at'])) ?> <span style="font-weight:500;color:#64748b;font-size:11.5px;">• <?= date('h:i A', strtotime($job['created_at'])) ?></span>
                </div>
                <div style="font-size:11.5px;color:<?= $color ?>;margin-top:3px;font-weight:600;white-space:nowrap;">
                    <i class="fas fa-clock" style="font-size:10.5px;color:#0284c7;flex-shrink:0;"></i> Est: <?= htmlspecialchars($est_sub ?: $est_disp) ?>
                </div>
            </td>

            <!-- 8. STATUS -->
            <td style="padding:11px 4px;vertical-align:middle;text-align:center;white-space:nowrap;overflow:visible;box-sizing:border-box;">
                <span style="display:inline-flex;align-items:center;justify-content:center;gap:3px;padding:3px 5px;border-radius:10px;font-size:10px;font-weight:700;white-space:nowrap;background:<?= $wf_bg ?>;color:<?= $wf_color ?>;border:1px solid <?= $wf_color ?>40;"
                      title="<?= htmlspecialchars($wf_label) ?>">
                    <i class="fas <?= $wf_status === 'Completed' || $val_status === 'Completed' ? 'fa-check-circle' : ($wf_status === 'In Progress' ? 'fa-spinner fa-spin' : ($wf_status === 'Rejected' || str_contains($wf_label, 'Void') ? 'fa-ban' : 'fa-clock')) ?>"></i>
                    <?= htmlspecialchars($wf_label) ?>
                </span>
            </td>

            <!-- 9. ACTIONS -->
            <td style="padding:8px 6px;text-align:center;vertical-align:middle;white-space:nowrap;overflow:visible;box-sizing:border-box;">
                <?php
                    $jo_total   = (float)($job['total_cost'] ?? $job['estimated_cost'] ?? 0);
                    $jo_paid    = (float)($job['amount_paid'] ?? 0);
                    $jo_balance = (float)($job['balance_due'] ?? 0);
                    if ($jo_balance <= 0.009 && $jo_total > 0) $jo_balance = max(0, $jo_total - $jo_paid);
                    $labor_fee_val = (float)($job['actual_labor_cost'] ?? $job['estimated_labor_cost'] ?? 0);
                    
                    // Prepare JO data for modals (JSON-encoded)
                    $jo_or_number = '';
                    if (!empty($job['or_number'])) {
                        $jo_or_number = $job['or_number'];
                    } elseif (!empty($job['created_at'])) {
                        $jo_or_number = 'OR-' . date('Y', strtotime($job['created_at'])) . '-' . str_pad($job['id'], 6, '0', STR_PAD_LEFT);
                    }
                    $jo_data = json_encode([
                        'id' => (int)$job['id'],
                        'jo_ref' => $job['job_order_id'] ?? ('#'.$job['id']),
                        'or_number' => $jo_or_number,
                        'customer' => $job['customer_name'] ?? '',
                        'contact_number' => $job['contact_number'] ?? $job['customer_contact'] ?? '',
                        'vehicle_plate' => $job['vehicle_plate'] ?? '',
                        'vehicle_type' => $job['vehicle_type'] ?? '',
                        'vehicle_brand' => $job['vehicle_brand'] ?? $job['brand'] ?? '',
                        'vehicle_model' => $job['vehicle_model'] ?? $job['model'] ?? '',
                        'year_model' => $job['year_model'] ?? $job['vehicle_year'] ?? '',
                        'engine_number' => $job['engine_number'] ?? '',
                        'chassis_number' => $job['chassis_number'] ?? $job['vin'] ?? '',
                        'odometer' => $job['odometer'] ?? '',
                        'service_type' => $job['service_type'] ?? '',
                        'service_description' => $job['service_description'] ?? $job['additional_notes'] ?? '',
                        'parts' => $parts_display,
                        'mechanic' => $job['mechanic_name'] ?? 'Unassigned',
                        'workflow_status' => $wf_label,
                        'payment_status' => $pay_label,
                        'payment_method' => $pay_method,
                        'total' => $jo_total,
                        'labor_fee' => $labor_fee_val,
                        'paid' => $jo_paid,
                        'balance' => $jo_balance,
                        'remarks' => !empty($remarks) ? $remarks : ($job['service_description'] ?? $job['additional_notes'] ?? ''),
                        'staff_remarks' => $job['staff_remarks'] ?? $job['notes'] ?? $job['job_order_description'] ?? '',
                        'customer_complaint' => $job['customer_complaint'] ?? '',
                        'repair_recommendation' => $job['repair_recommendation'] ?? '',
                        'created_at' => $job['created_at'],
                        'source' => $job['_source'] ?? 'job_orders',
                        'estimated_duration' => (!empty($job['estimated_duration']) && (int)$job['estimated_duration'] > 0) ? (int)$job['estimated_duration'] : null
                    ]);
                ?>
                <div style="display:flex;flex-direction:column;gap:3px;width:100%;">
                    <!-- Always show View button -->
                    <button type="button"
                            onclick='viewJobOrderDetails(<?= htmlspecialchars($jo_data, ENT_QUOTES) ?>)'
                            class="txn-btn secondary" 
                            style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;background:#f1f5f9 !important;color:#002F70 !important;border:1.5px solid #94a3b8 !important;white-space:nowrap !important;"
                            onmouseover="this.style.background='#dbeafe';this.style.borderColor='#3b82f6';"
                            onmouseout="this.style.background='#f1f5f9';this.style.borderColor='#94a3b8';">
                        <i class="fas fa-eye" style="font-size:12px !important;"></i> <span style="font-size:13px !important;font-weight:800 !important;white-space:nowrap !important;display:inline !important;">View</span>
                    </button>

                    <?php if ($wf_label === 'Voided' || $canonical_status === 'Voided'): ?>
                        <span style="font-size:12px;color:#991b1b;font-weight:800;text-align:center;padding:2px 0;">
                            <i class="fas fa-ban"></i> Voided
                        </span>

                    <?php elseif ($wf_label === 'Void Requested' || ($pending_req && ($pending_req['request_type'] ?? '') === 'Void')): ?>
                        <button type="button"
                                onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;">
                            <i class="fas fa-print"></i> Reprint
                        </button>
                        <span style="font-size:10px;color:#dc2626;font-weight:800;text-align:center;padding:2px 0;white-space:nowrap;display:block;">
                            <i class="fas fa-clock"></i> Void Requested
                        </span>

                    <?php elseif ($wf_label === 'Adjustment Requested' || ($pending_req && ($pending_req['request_type'] ?? '') === 'Adjustment')): ?>
                        <button type="button"
                                onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;">
                            <i class="fas fa-print"></i> Reprint
                        </button>
                        <span style="font-size:10px;color:#d97706;font-weight:800;text-align:center;padding:2px 0;white-space:nowrap;display:block;">
                            <i class="fas fa-clock"></i> Adj. Requested
                        </span>

                    <?php elseif ($wf_label === 'Adjusted'): ?>
                        <button type="button"
                                onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;">
                            <i class="fas fa-print"></i> Reprint
                        </button>

                        <?php
                        $is_credit_approved = ($pay_method === 'Credit Account' || in_array($pay_status, ['Credit Account', 'Credit Transaction', 'Credit', 'Receivables/Credit', 'A/R']));
                        $is_fully_paid = ($pay_status === 'Paid' || $jo_balance <= 0.009);
                        $can_release = ($is_fully_paid || $is_credit_approved);
                        ?>

                        <?php if (!$can_release): ?>
                            <button type="button"
                                    onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',false,'tracker','Completed')"
                                    class="txn-btn success" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;">
                                <i class="fas fa-money-bill-wave"></i>
                                <?= in_array($pay_status, ['Partially Paid','Partial Payment','Partial']) ? 'Settle Balance' : 'Mark Paid' ?>
                            </button>

                            <button type="button"
                                    class="txn-btn primary jo-btn-disabled"
                                    title="Settle the outstanding balance or obtain the required authorized credit approval before releasing this Job Order."
                                    onclick="showTxnAlert('Settle the outstanding balance or obtain the required authorized credit approval before releasing this Job Order.', 'warning');"
                                    style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;background:#94a3b8 !important;color:#fff !important;cursor:not-allowed;opacity:0.65;border:none;">
                                <i class="fas fa-car-side"></i> Release Vehicle
                            </button>
                        <?php else: ?>
                            <button type="button"
                                    onclick="performJobOrderAction('release_job_order', <?= (int)$job['id'] ?>, '<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>', this)"
                                    class="txn-btn primary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;background:#002F70;color:#fff;border:none;">
                                <i class="fas fa-car-side"></i> Release Vehicle
                            </button>
                        <?php endif; ?>

                        <button type="button"
                                data-jo-id="<?= (int)$job['id'] ?>"
                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                data-jo-paymethod="<?= htmlspecialchars($pay_method) ?>"
                                data-jo-total="<?= (float)$jo_total ?>"
                                data-jo-labor="<?= (float)$labor_fee_val ?>"
                                data-jo-paid="<?= (float)$jo_paid ?>"
                                data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                                data-jo-plate="<?= htmlspecialchars($job['vehicle_plate'] ?? '') ?>"
                                data-jo-vtype="<?= htmlspecialchars($job['vehicle_type'] ?? '') ?>"
                                data-jo-mech="<?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?>"
                                onclick="return openRequestAdjustModal(event, this);"
                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                            <i class="fas fa-sliders-h"></i> <?= in_array($role, ['admin','superadmin']) ? 'Adjust' : 'Request Adjust' ?>
                        </button>
                        <?php if ($enable_void_transaction): ?>
                        <button type="button"
                                data-jo-id="<?= (int)$job['id'] ?>"
                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                onclick="return openRequestVoidModal(event, this);"
                                class="void-btn">
                            <i class="fas fa-ban"></i> <?= in_array($role, ['admin','superadmin']) ? 'Void' : 'Request Void' ?>
                        </button>
                        <?php endif; ?>

                    <?php elseif ($canonical_status === 'Released'): ?>
                        <!-- RELEASED: View & Reprint available, with adjust/void options if permitted -->
                        <button type="button"
                                onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;">
                            <i class="fas fa-print"></i> Reprint
                        </button>

                        <button type="button"
                                data-jo-id="<?= (int)$job['id'] ?>"
                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                data-jo-paymethod="<?= htmlspecialchars($pay_method) ?>"
                                data-jo-total="<?= (float)$jo_total ?>"
                                data-jo-labor="<?= (float)$labor_fee_val ?>"
                                data-jo-paid="<?= (float)$jo_paid ?>"
                                data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                                data-jo-plate="<?= htmlspecialchars($job['vehicle_plate'] ?? '') ?>"
                                data-jo-vtype="<?= htmlspecialchars($job['vehicle_type'] ?? '') ?>"
                                data-jo-mech="<?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?>"
                                onclick="return openRequestAdjustModal(event, this);"
                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                            <i class="fas fa-sliders-h"></i> <?= in_array($role, ['admin','superadmin']) ? 'Adjust' : 'Request Adjust' ?>
                        </button>
                        <?php if ($enable_void_transaction): ?>
                        <button type="button"
                                data-jo-id="<?= (int)$job['id'] ?>"
                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                onclick="return openRequestVoidModal(event, this);"
                                class="void-btn">
                            <i class="fas fa-ban"></i> <?= in_array($role, ['admin','superadmin']) ? 'Void' : 'Request Void' ?>
                        </button>
                        <?php endif; ?>

                    <?php elseif ($canonical_status === 'Completed'): ?>
                        <!-- COMPLETED -->
                        <button type="button"
                                onclick="printJobOrderReceipt(<?= (int)$job['id'] ?>,'<?= addslashes($job['job_order_id'] ?? ('#'.$job['id'])) ?>')"
                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;">
                            <i class="fas fa-print"></i> Reprint
                        </button>

                        <?php
                        $is_credit_approved = ($pay_method === 'Credit Account' || in_array($pay_status, ['Credit Account', 'Credit Transaction', 'Credit', 'Receivables/Credit', 'A/R']));
                        $is_fully_paid = ($pay_status === 'Paid' || $jo_balance <= 0.009);
                        $can_release = ($is_fully_paid || $is_credit_approved);
                        ?>

                        <?php if (!$can_release): ?>
                            <button type="button"
                                    onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',false,'tracker','Completed')"
                                    class="txn-btn success" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;">
                                <i class="fas fa-money-bill-wave"></i>
                                <?= in_array($pay_status, ['Partially Paid','Partial Payment','Partial']) ? 'Settle Balance' : 'Mark Paid' ?>
                            </button>

                            <button type="button"
                                    class="txn-btn primary jo-btn-disabled"
                                    title="Settle the outstanding balance or obtain the required authorized credit approval before releasing this Job Order."
                                    onclick="showTxnAlert('Settle the outstanding balance or obtain the required authorized credit approval before releasing this Job Order.', 'warning');"
                                    style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;background:#94a3b8 !important;color:#fff !important;cursor:not-allowed;opacity:0.65;border:none;">
                                <i class="fas fa-car-side"></i> Release Vehicle
                            </button>
                        <?php else: ?>
                            <button type="button"
                                    onclick="performJobOrderAction('release_job_order', <?= (int)$job['id'] ?>, '<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>', this)"
                                    class="txn-btn primary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;background:#002F70;color:#fff;border:none;">
                                <i class="fas fa-car-side"></i> Release Vehicle
                            </button>
                        <?php endif; ?>

                        <button type="button"
                                data-jo-id="<?= (int)$job['id'] ?>"
                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                data-jo-paymethod="<?= htmlspecialchars($pay_method) ?>"
                                data-jo-total="<?= (float)$jo_total ?>"
                                data-jo-labor="<?= (float)$labor_fee_val ?>"
                                data-jo-paid="<?= (float)$jo_paid ?>"
                                data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                                data-jo-plate="<?= htmlspecialchars($job['vehicle_plate'] ?? '') ?>"
                                data-jo-vtype="<?= htmlspecialchars($job['vehicle_type'] ?? '') ?>"
                                data-jo-mech="<?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?>"
                                onclick="return openRequestAdjustModal(event, this);"
                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                            <i class="fas fa-sliders-h"></i> <?= in_array($role, ['admin','superadmin']) ? 'Adjust' : 'Request Adjust' ?>
                        </button>
                        <?php if ($enable_void_transaction): ?>
                        <button type="button"
                                data-jo-id="<?= (int)$job['id'] ?>"
                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                onclick="return openRequestVoidModal(event, this);"
                                class="void-btn">
                            <i class="fas fa-ban"></i> <?= in_array($role, ['admin','superadmin']) ? 'Void' : 'Request Void' ?>
                        </button>
                        <?php endif; ?>

                    <?php elseif ($canonical_status === 'In Progress'): ?>
                        <!-- IN PROGRESS -->
                        <?php
                        $is_credit_approved = ($pay_method === 'Credit Account' || in_array($pay_status, ['Credit Account', 'Credit Transaction', 'Credit', 'Receivables/Credit', 'A/R']));
                        $is_fully_paid = ($pay_status === 'Paid' || $jo_balance <= 0.009);
                        ?>
                        <?php if ($is_fully_paid || $is_credit_approved): ?>
                            <button type="button" 
                                    onclick="performJobOrderAction('set_completed', <?= (int)$job['id'] ?>, '<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>', this)"
                                    class="txn-btn success" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;">
                                <i class="fas fa-check"></i> Mark Complete
                            </button>
                        <?php else: ?>
                            <button type="button"
                                    onclick="openPaymentModal(<?= (int)$job['id'] ?>,'<?= addslashes($job['_source'] ?? 'job_orders') ?>',<?= $jo_total ?>,<?= $jo_paid ?>,<?= $jo_balance ?>,'<?= addslashes($job['customer_name'] ?? '') ?>',true,'tracker','In Progress')"
                                    class="txn-btn success" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;">
                                <i class="fas fa-check"></i> Complete & Settle
                            </button>
                        <?php endif; ?>

                        <button type="button"
                                data-jo-id="<?= (int)$job['id'] ?>"
                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                data-jo-paymethod="<?= htmlspecialchars($pay_method) ?>"
                                data-jo-total="<?= (float)$jo_total ?>"
                                data-jo-labor="<?= (float)$labor_fee_val ?>"
                                data-jo-paid="<?= (float)$jo_paid ?>"
                                data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                                data-jo-plate="<?= htmlspecialchars($job['vehicle_plate'] ?? '') ?>"
                                data-jo-vtype="<?= htmlspecialchars($job['vehicle_type'] ?? '') ?>"
                                data-jo-mech="<?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?>"
                                onclick="return openRequestAdjustModal(event, this);"
                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                            <i class="fas fa-sliders-h"></i> <?= in_array($role, ['admin','superadmin']) ? 'Adjust' : 'Request Adjust' ?>
                        </button>
                        <?php if ($enable_void_transaction): ?>
                        <button type="button"
                                data-jo-id="<?= (int)$job['id'] ?>"
                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                onclick="return openRequestVoidModal(event, this);"
                                class="void-btn">
                            <i class="fas fa-ban"></i> <?= in_array($role, ['admin','superadmin']) ? 'Void' : 'Request Void' ?>
                        </button>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- PENDING or WAITING FOR PARTS -->
                        <button type="button" 
                                onclick="performJobOrderAction('set_in_progress', <?= (int)$job['id'] ?>, '<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>', this)"
                                class="txn-btn primary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;background:#002F70;color:#fff;border:none;">
                            <i class="fas fa-play"></i> Start In Progress
                        </button>

                        <button type="button"
                                data-jo-id="<?= (int)$job['id'] ?>"
                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                data-jo-paymethod="<?= htmlspecialchars($pay_method) ?>"
                                data-jo-total="<?= (float)$jo_total ?>"
                                data-jo-labor="<?= (float)$labor_fee_val ?>"
                                data-jo-paid="<?= (float)$jo_paid ?>"
                                data-jo-service="<?= htmlspecialchars($job['service_type'] ?? '') ?>"
                                data-jo-plate="<?= htmlspecialchars($job['vehicle_plate'] ?? '') ?>"
                                data-jo-vtype="<?= htmlspecialchars($job['vehicle_type'] ?? '') ?>"
                                data-jo-mech="<?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?>"
                                onclick="return openRequestAdjustModal(event, this);"
                                class="txn-btn secondary" style="width:100%;padding:4px 6px;font-size:11px !important;font-weight:700 !important;box-sizing:border-box;text-align:center;justify-content:center;cursor:pointer;">
                            <i class="fas fa-sliders-h"></i> <?= in_array($role, ['admin','superadmin']) ? 'Adjust' : 'Request Adjust' ?>
                        </button>
                        <?php if ($enable_void_transaction): ?>
                        <button type="button"
                                data-jo-id="<?= (int)$job['id'] ?>"
                                data-jo-source="<?= htmlspecialchars($job['_source'] ?? 'job_orders') ?>"
                                data-jo-ref="<?= htmlspecialchars($job['job_order_id'] ?? ('#'.$job['id'])) ?>"
                                data-jo-customer="<?= htmlspecialchars($job['customer_name'] ?? '—') ?>"
                                data-jo-status="<?= htmlspecialchars($wf_label) ?>"
                                data-jo-paystatus="<?= htmlspecialchars($pay_label) ?>"
                                onclick="return openRequestVoidModal(event, this);"
                                class="void-btn">
                            <i class="fas fa-ban"></i> <?= in_array($role, ['admin','superadmin']) ? 'Void' : 'Request Void' ?>
                        </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
        endforeach;
        return ob_get_clean();
    }
}
