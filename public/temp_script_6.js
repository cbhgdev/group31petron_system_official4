
        // ── Fuel Sub-tab Switcher ─────────────────────────────────────────────
        // Shows only the active card; hides the other.
        function switchFuelSubTab(tab) {
            var isReadings = (tab === 'readings');
            var encodeCard  = document.getElementById('encodeCard');
            var todayCard   = document.getElementById('todayEntriesCard');
            var encodeBtn   = document.getElementById('fuelSubTabBtn_encode');
            var readingsBtn = document.getElementById('fuelSubTabBtn_readings');
            if (!encodeBtn || !readingsBtn) return;

            // Show/hide the cards
            if (encodeCard)  encodeCard.style.display  = isReadings ? 'none'  : 'block';
            if (todayCard)   todayCard.style.display   = isReadings ? 'block' : 'none';

            // Update tab button styles
            encodeBtn.className  = 'txn-subtab-btn blue ' + (isReadings ? 'inactive' : 'active');
            readingsBtn.className = 'txn-subtab-btn blue ' + (isReadings ? 'active'   : 'inactive');

            // Load history when switching to readings tab, or restore in-progress inputs when switching to encode
            if (isReadings) {
                refreshTodayEntries();
                if (typeof window.setupPetronDownwardDropdowns === 'function') {
                    window.setupPetronDownwardDropdowns(['#subtab_shift']);
                }
            } else {
                if (typeof window.restoreFuelInputCache === 'function') {
                    window.restoreFuelInputCache();
                }
            }

            // Persist active tab in URL without reload
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                if (isReadings) {
                    url.searchParams.set('fuel_tab', 'readings');
                } else {
                    url.searchParams.delete('fuel_tab');
                }
                window.history.replaceState(null, '', url);
            }
        }
        window.switchFuelSubTab = switchFuelSubTab;

        // ── Fuel Sales Closing Button — Live Auto-Detection ─────────────────────────
        // Queries the server to check if closing is pending/completed for today's shift.
        // Runs on: page load, browser back (pageshow), tab focus (visibilitychange), every 45s.
        (function() {
            var _closing_check_url = window.location.pathname.replace(/[^\\/]+$/, '') + 'staff_fuel_sales_closing_handler.php';
            var _closing_shift     = '<?= htmlspecialchars($fuel_shift_name, ENT_QUOTES) ?>';
            var _closing_date      = '<?= date('Y-m-d') ?>';
            var _closing_timer     = null;

            function _applyClosingBtnState(showBtn) {
                var b1 = document.getElementById('fuelSalesClosingBtn');
                var b2 = document.getElementById('fuelSalesClosingBtnReadings');
                var display = showBtn ? 'inline-flex' : 'none';
                if (b1) b1.style.display = display;
                if (b2) b2.style.display = display;
                if (!showBtn) {
                    try {
                        sessionStorage.removeItem('petron_fuel_readings_submitted');
                        sessionStorage.removeItem('petron_fuel_reading_shift');
                        sessionStorage.removeItem('petron_fuel_reading_date');
                    } catch(e) {}
                }
            }

            function _checkClosingStatus(silent) {
                // Quick local checks first (no network call needed)
                if (window.location.search.indexOf('closing_saved=1') !== -1) {
                    _applyClosingBtnState(false);
                    return;
                }

                var url = _closing_check_url
                    + '?action=check_closing_status'
                    + '&date=' + encodeURIComponent(_closing_date)
                    + '&shift=' + encodeURIComponent(_closing_shift)
                    + '&_=' + Date.now();

                fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data || !data.success) return;
                        _applyClosingBtnState(!!data.show_button);
                        // Persist pending flag in sessionStorage for bfcache resilience
                        try {
                            if (data.show_button) {
                                sessionStorage.setItem('petron_fuel_readings_submitted', '1');
                            }
                        } catch(e) {}
                    })
                    .catch(function() {
                        // Network error — fall back to sessionStorage + PHP-baked flags
                        var isCompletedPhp = <?= !empty($is_closing_completed) ? 'true' : 'false' ?>;
                        var hasUnclosedPhp = <?= !empty($has_submitted_readings_unclosed) ? 'true' : 'false' ?>;
                        var sessionPending = false;
                        try { sessionPending = sessionStorage.getItem('petron_fuel_readings_submitted') === '1'; } catch(e) {}
                        _applyClosingBtnState(!isCompletedPhp && (hasUnclosedPhp || sessionPending));
                    });
            }

            // Start periodic check every 45 s while tab is visible
            function _startPolling() {
                _stopPolling();
                _closing_timer = setInterval(function() {
                    if (!document.hidden) _checkClosingStatus(true);
                }, 45000);
            }
            function _stopPolling() {
                if (_closing_timer) { clearInterval(_closing_timer); _closing_timer = null; }
            }

            // Run on page show (including bfcache restore from browser back button)
            window.addEventListener('pageshow', function(e) {
                _checkClosingStatus(false);
                _startPolling();
            });

            // Run when user switches back to this browser tab
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) _checkClosingStatus(true);
            });

            // Expose so external code (e.g. after submit) can trigger it
            window.refreshFuelClosingStatus = _checkClosingStatus;
            window._applyClosingBtnState    = _applyClosingBtnState;
        })();

        // Initialize correct tab on page load
        document.addEventListener('DOMContentLoaded', function() {
            var defaultTab = '<?= $fuel_tab_default ?>';
            switchFuelSubTab(defaultTab);
            // Initial check on DOMContentLoaded (pageshow may fire before scripts)
            if (typeof window.refreshFuelClosingStatus === 'function') {
                window.refreshFuelClosingStatus(false);
            }
        });

        // ── Fuel Transaction Filters ───────────────────────────────────────────
        function resetFuelFilters() {
            const todayCard = document.getElementById('todayEntriesCard');
            const isReadings = todayCard && todayCard.style.display !== 'none';
            if (isReadings) {
                window.location.href = 'staff_transactions_hub.php?section=fuel&fuel_tab=readings';
            } else {
                window.location.href = 'staff_transactions_hub.php?section=fuel&fuel_tab=encode';
            }
        }

        // formatOnInput and formatOnBlur are defined authoritatively at the top of the fuel section

        function formatAllFuelInputs() {
            document.querySelectorAll('input[id^="beginning_"], input[id^="ending_"], input[id^="cal_"]').forEach(function(inp) {
                if (inp && inp.value && inp.value.trim() !== '') {
                    formatOnBlur(inp);
                }
            });
        }
        window.formatAllFuelInputs = formatAllFuelInputs;
        document.addEventListener('DOMContentLoaded', formatAllFuelInputs);
        window.addEventListener('load', formatAllFuelInputs);

        // ── AJAX submit per fuel row (Updated for tanker data) ──────────────────────────────────────────
        async function submitFuelCard(event, ftId) {
            event.preventDefault();

            const form      = document.getElementById('fuelForm_' + ftId);
            const submitBtn = document.getElementById('submitBtn_' + ftId);
            const msgEl     = document.getElementById('cardMsg_'   + ftId);

            if (!form) return false;

            // Build FormData from the form first
            const formData = new FormData(form);

            // Add global remarks to this fuel transaction
            const globalRemarks = document.getElementById('globalFuelRemarks').value.trim();
            formData.set('notes', globalRemarks);

            // ── Beginning reading validation for manual input (first shift) ──
            const beginningEl = document.getElementById('beginning_' + ftId);
            let beginningRaw = ((formData.get('beginning_reading') !== null ? formData.get('beginning_reading') : (beginningEl ? beginningEl.value : '')) || '').replace(/,/g, '');

            // If first-shift editable and empty, default to 0 (valid for new pump / first entry)
            if (beginningEl && !beginningEl.readOnly && (beginningRaw === '' || isNaN(parseFloat(beginningRaw)))) {
                beginningRaw = '0';
                formData.set('beginning_reading', '0');
                if (beginningEl) beginningEl.value = '0.00';
            }

            // Validate: ending_reading (strip commas before parsing)
            const endingRaw = (formData.get('ending_reading') || '').replace(/,/g, '').trim();
            let endingVal = parseFloat(endingRaw);
            const beginningVal = parseFloat(beginningRaw) || 0;

            // If ending reading is blank or 0, but beginning exists, default Ending = Beginning (0 sales / walay halin)
            if ((!endingRaw || isNaN(endingVal) || endingVal <= 0) && beginningVal > 0) {
                endingVal = beginningVal;
                formData.set('ending_reading', beginningVal.toFixed(2));
                if (endingEl) endingEl.value = beginningVal.toFixed(2);
            } else if (isNaN(endingVal) || endingVal < 0) {
                showRowMsg(msgEl, 'error', 'Please enter a valid Ending meter reading.');
                return false;
            } else {
                formData.set('ending_reading', endingRaw);
            }
            formData.set('beginning_reading', beginningRaw);

            // Validation Rule 1: Ending Reading must not be less than Beginning Reading
            // Note: Ending can equal Beginning (volume = 0), which is valid
            if (endingVal < beginningVal) {
                showRowMsg(msgEl, 'error', 'Ending Reading cannot be lower than Beginning Reading.');
                return false;
            }

            const calibrationRaw = (formData.get('calibration') || '0').replace(/,/g, '');
            formData.set('calibration', calibrationRaw);
            const calibrationVal = parseFloat(calibrationRaw) || 0;

            const volEl = document.getElementById('volume_value_' + ftId);
            const amtEl = document.getElementById('amount_value_' + ftId);
            const prEl  = document.getElementById('price_' + ftId);
            if (volEl) formData.set('volume_liters', volEl.value || '0.00');
            if (amtEl) formData.set('total_amount', amtEl.value || '0.00');
            if (prEl)  formData.set('price_per_liter', prEl.value || '0.00');

            // Validation Rule 2: Calibration ≥ 0
            if (calibrationVal < 0) {
                showRowMsg(msgEl, 'error', 'Calibration value cannot be negative.');
                return false;
            }

            // Note: Calibration can equal or exceed Gross Volume (resulting in 0 net volume / walay halin)

            // Disable button, show loading
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
            showRowMsg(msgEl, '', '');

            try {
                const targetUrl = window.location.pathname.replace(/[^\\/]+$/, '') + 'api_fuel_readings.php';
                const res  = await fetch(targetUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                });

                const raw = await res.text().catch(() => '');
                let json;
                try {
                    const jsonStart = raw.indexOf('{"success"');
                    const jsonStr   = jsonStart >= 0 ? raw.slice(jsonStart) : raw;
                    json = JSON.parse(jsonStr);
                } catch (parseErr) {
                    const preview = raw.length > 0
                        ? raw.substring(0, 400).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                        : '(empty response)';
                    showRowMsg(msgEl, 'error',
                        'Server error — could not parse response.<br>' +
                        '<code style="font-size:10px;word-break:break-all;display:block;margin-top:4px;background:#fff;padding:4px;border-radius:4px;">' +
                        preview + '</code>'
                    );
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit';
                    return false;
                }

                if (json.success) {
                    // Show success toast via shared helper
                    showToast('Meter readings submitted successfully.', 'success');

                    // Clear any inline message
                    if (msgEl) { msgEl.style.display = 'none'; msgEl.innerHTML = ''; }

                    // ── Continuous cycle: carryover submitted Ending → next Beginning (Auto-fetched & Read-only) ──
                    const endingEl    = document.getElementById('ending_'    + ftId);
                    const beginningEl = document.getElementById('beginning_' + ftId);
                    const calEl       = document.getElementById('cal_'       + ftId);
                    const volumeEl    = document.getElementById('volume_'    + ftId);
                    const volumeValEl = document.getElementById('volume_value_' + ftId);
                    const amountEl    = document.getElementById('amount_'    + ftId);
                    const amountValEl = document.getElementById('amount_value_' + ftId);

                    const submittedEnding = parseFloat((endingEl?.value || '0').replace(/,/g, ''));

                    // After submission, set beginning to submitted ending (auto-fetched & read-only for next shift)
                    if (beginningEl && submittedEnding > 0) {
                        beginningEl.value = submittedEnding.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        beginningEl.readOnly = true;
                        beginningEl.title = 'Auto-fetched from previous meter reading (' + beginningEl.value + '). Read-only.';
                        delete beginningEl.dataset.missing;
                        beginningEl.style.background = '#f0fdf4';
                        beginningEl.style.fontWeight = '700';
                        beginningEl.style.color      = '#15803d';
                        beginningEl.style.cursor     = 'not-allowed';
                        beginningEl.removeAttribute('required');
                        const badgeEl = document.getElementById('beginning_badge_' + ftId);
                        if (badgeEl) {
                            badgeEl.style.color = '#15803d';
                            badgeEl.innerHTML = '🔒 Auto';
                        }
                    }

                    // Clear ending and computed fields
                    if (endingEl)    endingEl.value    = '';
                    if (calEl)       calEl.value       = '0.00';
                    if (volumeEl)    volumeEl.value    = '0.00';
                    if (volumeValEl) volumeValEl.value = '0.00';
                    if (amountEl)    amountEl.value    = '0.00';
                    if (amountValEl) amountValEl.value = '0.00';
                    try { sessionStorage.removeItem('petron_fuel_reading_cache'); } catch(e) {}

                    // Switch to Meter Reading History tab so the new record is immediately visible
                    if (typeof switchFuelSubTab === 'function') switchFuelSubTab('readings');
                    if (typeof loadTodayEntries  === 'function') loadTodayEntries();
                } else {
                    // Show the actual server error message
                    const errMsg = json.message || 'Submission failed. Please try again.';
                    showRowMsg(msgEl, 'error', errMsg);
                }
            } catch (err) {
                showRowMsg(msgEl, 'error', 'Network error: ' + err.message);
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Save';
                }
            }
        }

        function showRowMsg(el, type, text) {
            if (!el) return;
            if (!text) { el.style.display = 'none'; el.innerHTML = ''; return; }
            const colors = {
                success: { bg: '#dcfce7', color: '#166534', border: '#86efac' },
                error:   { bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' },
            };
            const c = colors[type] || { bg: '#f1f5f9', color: '#475569', border: '#e2e8f0' };
            el.style.cssText = `display:block;background:${c.bg};color:${c.color};border:1px solid ${c.border};
                                border-radius:6px;padding:8px 12px;font-size:11px;font-weight:600;white-space:normal;
                                margin-top:5px;line-height:1.8;`;
            // Convert \n to <br> for multi-line messages
            const safeText = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
            el.innerHTML = safeText;
        }

        // ── Reset a single row (Clears manual inputs: Ending & Calibration; keeps auto Beginning intact) ──
        function resetFuelRow(ftId) {
            const msgEl         = document.getElementById('cardMsg_' + ftId);
            const beginningEl   = document.getElementById(`beginning_${ftId}`);
            const endingEl      = document.getElementById(`ending_${ftId}`);
            const calEl         = document.getElementById(`cal_${ftId}`);
            const volumeEl      = document.getElementById(`volume_${ftId}`);
            const volumeValueEl = document.getElementById(`volume_value_${ftId}`);
            const amountEl      = document.getElementById(`amount_${ftId}`);
            const amountValueEl = document.getElementById(`amount_value_${ftId}`);
            
            // Do NOT touch beginning reading if it's auto-fetched (read-only)
            if (beginningEl && !beginningEl.readOnly) {
                beginningEl.value = '';
            }
            if (endingEl) endingEl.value = '';
            if (calEl) calEl.value = '0.00';
            if (volumeEl) volumeEl.value = '0.00';
            if (volumeValueEl) volumeValueEl.value = '0.00';
            if (amountEl) amountEl.value = '0.00';
            if (amountValueEl) amountValueEl.value = '0.00';
            if (msgEl) showRowMsg(msgEl, '', '');
        }


        // ── Reset ALL fuel rows + clear server-side unclosed draft entries ──────
        async function resetAllFuelRows() {
            if (!confirm('Reset all fuel readings? This will clear all entered data.')) return;
            
            // 1. Clear UI fields
            const allForms = document.querySelectorAll('form[id^="fuelForm_"]');
            allForms.forEach(form => {
                const ftId = form.id.replace('fuelForm_', '');
                resetFuelRow(ftId);
            });
            
            // 2. Clear global remarks
            const remarksInput = document.getElementById('globalFuelRemarks');
            if (remarksInput) remarksInput.value = '';
            const remarksTextarea = document.getElementById('globalRemarksTextarea');
            if (remarksTextarea) remarksTextarea.value = '';
            const remarksBtnLbl = document.getElementById('remarksButtonLabel');
            if (remarksBtnLbl) remarksBtnLbl.textContent = 'Add Remarks';

            // 3. Call API to clear unclosed draft readings from DB for this shift so F5 refresh won't restore them
            try {
                const shiftVal = '<?= htmlspecialchars($fuel_shift_name, ENT_QUOTES) ?>';
                const dateVal  = '<?= date('Y-m-d') ?>';
                const formData = new FormData();
                formData.append('action', 'reset_shift');
                formData.append('shift_period', shiftVal);
                formData.append('reading_date', dateVal);

                await fetch('api_fuel_readings.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
            } catch (e) {
                console.error('Reset shift API error:', e);
            }

            if (window.PetronDraft) {
                window.PetronDraft.clear('fuel_meter_readings');
                window.PetronDraft.clear('fuel_meter_readings_fuel');
            }
            try { sessionStorage.removeItem('petron_fuel_reading_cache'); } catch(e) {}

            showToast('All fuel readings have been reset.', 'info');
        }
        
        // ── Global Remarks Functions ───────────────────────────────────────────
        function openGlobalRemarksModal() {
            const currentRemarks = document.getElementById('globalFuelRemarks').value;
            document.getElementById('globalRemarksTextarea').value = currentRemarks;
            document.getElementById('globalRemarksModal').style.display = 'flex';
            document.getElementById('globalRemarksTextarea').focus();
        }
        
        function closeGlobalRemarksModal() {
            document.getElementById('globalRemarksModal').style.display = 'none';
        }
        
        function saveGlobalRemarks() {
            const remarks = document.getElementById('globalRemarksTextarea').value.trim();
            document.getElementById('globalFuelRemarks').value = remarks;
            
            // Update button label
            const buttonLabel = document.getElementById('remarksButtonLabel');
            if (remarks) {
                buttonLabel.textContent = 'Edit Remarks <i class="fas fa-check"></i>';
                showToast('Remarks saved! They will be applied to all fuel transactions.', 'success');
            } else {
                buttonLabel.textContent = 'Add Remarks';
            }
            
            closeGlobalRemarksModal();
        }
        
        // Close modal on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeGlobalRemarksModal();
            }
        });
        
        // Close modal on background click
        document.getElementById('globalRemarksModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeGlobalRemarksModal();
        });

        // ── Submit ALL valid encoded rows ──────────────────────────────────────
        async function submitAllFuelRows() {
            const allForms = document.querySelectorAll('form[id^="fuelForm_"]');
            if (!allForms || allForms.length === 0) {
                showToast('No fuel reading forms found.', 'error');
                return;
            }

            const formsToSubmit = [];
            const skippedForms  = [];

            allForms.forEach(form => {
                const ftId        = form.id.replace('fuelForm_', '');
                const endingEl    = document.getElementById(`ending_${ftId}`);
                const beginningEl = document.getElementById(`beginning_${ftId}`);
                const calEl       = document.getElementById(`cal_${ftId}`);
                const msgEl       = document.getElementById(`cardMsg_${ftId}`);

                // Skip deactivated tanks if disabled
                if (endingEl && endingEl.disabled) {
                    return;
                }

                let endingRaw    = (endingEl    ? endingEl.value    : '').replace(/,/g, '').trim();
                let beginningRaw = (beginningEl ? beginningEl.value : '').replace(/,/g, '').trim();
                let calRaw       = (calEl       ? calEl.value       : '0.00').replace(/,/g, '').trim();

                let endingVal    = parseFloat(endingRaw)    || 0;
                let beginningVal = parseFloat(beginningRaw) || 0;
                let calVal       = parseFloat(calRaw)       || 0;

                // If beginning reading is editable (first shift) and empty, default to 0
                if (beginningEl && !beginningEl.readOnly && (!beginningRaw || beginningRaw === '')) {
                    beginningEl.value = '0.00';
                    beginningRaw = '0.00';
                    beginningVal = 0;
                }

                // If ending reading is blank or <= 0:
                // Auto-default Ending = Beginning (meaning NO sales / 0 net volume / "walay halin" for this pump during this shift)
                if (!endingRaw || endingVal <= 0) {
                    if (beginningVal > 0) {
                        endingVal = beginningVal;
                        endingRaw = beginningVal.toFixed(2);
                        calVal = 0;
                        calRaw = '0.00';
                        if (endingEl) {
                            endingEl.value = beginningEl.value;
                        }
                        if (calEl) {
                            calEl.value = '0.00';
                        }
                        const volEl = document.getElementById('volume_' + ftId);
                        const amtEl = document.getElementById('amount_' + ftId);
                        if (volEl) volEl.value = '0.00';
                        if (amtEl) amtEl.value = '0.00';
                    } else {
                        endingVal = 0;
                        endingRaw = '0.00';
                    }
                }

                // Ending cannot be lower than Beginning
                if (endingVal < beginningVal) {
                    skippedForms.push({ ftId, reason: `Ending (${endingVal.toLocaleString()}) < Beginning (${beginningVal.toLocaleString()})` });
                    if (msgEl) showRowMsg(msgEl, 'error', 'Ending Reading cannot be lower than Beginning Reading — skipped.');
                    return;
                }

                // Calibration cannot be negative
                if (calVal < 0) {
                    skippedForms.push({ ftId, reason: 'Calibration cannot be negative' });
                    if (msgEl) showRowMsg(msgEl, 'error', 'Calibration cannot be negative — skipped.');
                    return;
                }

                // Note: Calibration can equal or exceed Gross Volume (0 net volume / walay halin is valid)
                formsToSubmit.push({ ftId, form, endingRaw, beginningRaw, calRaw, endingVal });
            });

            if (formsToSubmit.length === 0) {
                if (skippedForms.length > 0) {
                    showToast('Some readings have errors (e.g., Ending < Beginning). Please correct the highlighted rows before submitting.', 'error');
                } else {
                    showToast('Please enter the Ending meter reading for at least one fuel pump before clicking Submit All Readings.', 'info');
                }
                return;
            }

            let confirmMsg = `Submit ${formsToSubmit.length} fuel reading(s) for manager validation?`;
            if (skippedForms.length > 0) {
                confirmMsg += `\n\n<i class="fas fa-exclamation-triangle"></i> ${skippedForms.length} row(s) will be SKIPPED due to errors:\n` +
                    skippedForms.map(s => `• ${s.ftId.replace(/_/g,' ').toUpperCase()}: ${s.reason}`).join('\n');
            }

            const confirmed = await showConfirm(confirmMsg);
            if (!confirmed) return;

            let successCount = 0;
            let errorCount   = 0;
            const errors     = [];

            const globalRemarks = document.getElementById('globalFuelRemarks')?.value.trim() || '';

            // Submit each form
            for (const {ftId, form, endingRaw, beginningRaw, calRaw, endingVal} of formsToSubmit) {
                try {
                    const formData = new FormData(form);
                    formData.set('auth_user_id',     '<?= (int)$me['id'] ?>');
                    formData.set('ending_reading',    endingRaw);
                    formData.set('beginning_reading',  beginningRaw);
                    formData.set('calibration',        calRaw);
                    formData.set('notes',              globalRemarks);

                    const volEl = document.getElementById('volume_value_' + ftId);
                    const amtEl = document.getElementById('amount_value_' + ftId);
                    const prEl  = document.getElementById('price_' + ftId);
                    if (volEl) formData.set('volume_liters', volEl.value || '0.00');
                    if (amtEl) formData.set('total_amount', amtEl.value || '0.00');
                    if (prEl)  formData.set('price_per_liter', prEl.value || '0.00');

                    const targetUrl = window.location.pathname.replace(/[^\\/]+$/, '') + 'api_fuel_readings.php';
                    const response = await fetch(targetUrl, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });

                    const raw = await response.text().catch(() => '');
                    let result;
                    try {
                        const jsonStart = raw.indexOf('{"success"');
                        const jsonStr   = jsonStart >= 0 ? raw.slice(jsonStart) : raw;
                        result = JSON.parse(jsonStr);
                    } catch(e) {
                        result = { success: false, message: 'Invalid server response.' };
                    }

                    if (result.success) {
                        successCount++;
                        // ── Continuous cycle: carryover Ending → Beginning (Auto-fetched & Read-only) ──
                        const endingEl    = document.getElementById(`ending_${ftId}`);
                        const beginningEl = document.getElementById(`beginning_${ftId}`);
                        const calEl       = document.getElementById(`cal_${ftId}`);
                        const volumeEl    = document.getElementById(`volume_${ftId}`);
                        const volumeValEl = document.getElementById(`volume_value_${ftId}`);
                        const amountEl    = document.getElementById(`amount_${ftId}`);
                        const amountValEl = document.getElementById(`amount_value_${ftId}`);

                        if (beginningEl && endingVal > 0) {
                            beginningEl.value = endingVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            beginningEl.readOnly = true;
                            beginningEl.title = 'Auto-fetched from previous meter reading (' + beginningEl.value + '). Read-only.';
                            delete beginningEl.dataset.missing;
                            beginningEl.style.background = '#f0fdf4';
                            beginningEl.style.fontWeight = '700';
                            beginningEl.style.color      = '#15803d';
                            beginningEl.style.border     = '1.5px solid #86efac';
                            beginningEl.style.cursor     = 'not-allowed';
                            beginningEl.removeAttribute('required');
                            const badgeEl = document.getElementById('beginning_badge_' + ftId);
                            if (badgeEl) {
                                badgeEl.style.color = '#15803d';
                                badgeEl.innerHTML = '🔒 Auto';
                            }
                        }
                        if (endingEl)    endingEl.value    = '';
                        if (calEl)       calEl.value       = '0.00';
                        if (volumeEl)    volumeEl.value    = '0.00';
                        if (volumeValEl) volumeValEl.value = '0.00';
                        if (amountEl)    amountEl.value    = '0.00';
                        if (amountValEl) amountValEl.value = '0.00';
                    } else {
                        errorCount++;
                        errors.push(`${ftId}: ${result.message || 'Unknown error'}`);
                    }
                } catch (error) {
                    errorCount++;
                    errors.push(`${ftId}: ${error.message}`);
                }
            }

            // Switch to history tab and refresh
            if (typeof switchFuelSubTab === 'function') switchFuelSubTab('readings');
            if (typeof loadTodayEntries === 'function') loadTodayEntries();

            // Show summary toast
            if (errorCount === 0 || successCount > 0) {
                const remarksInput = document.getElementById('globalFuelRemarks');
                if (remarksInput) remarksInput.value = '';
                const remarksTextarea = document.getElementById('globalRemarksTextarea');
                if (remarksTextarea) remarksTextarea.value = '';
                const remarksBtnLbl = document.getElementById('remarksButtonLabel');
                if (remarksBtnLbl) remarksBtnLbl.textContent = 'Add Remarks';

                if (window.PetronDraft) {
                    window.PetronDraft.clear('fuel_meter_readings');
                    window.PetronDraft.clear('fuel_meter_readings_fuel');
                }
                try { sessionStorage.removeItem('petron_fuel_reading_cache'); } catch(e) {}

                // Mark sessionStorage so if user navigates back the button re-appears
                try {
                    sessionStorage.setItem('petron_fuel_readings_submitted', '1');
                    sessionStorage.setItem('petron_fuel_reading_shift', '<?= htmlspecialchars($fuel_shift_name, ENT_QUOTES) ?>');
                    sessionStorage.setItem('petron_fuel_reading_date', new Date().toISOString().split('T')[0]);
                } catch(e) {}

                // Show closing buttons immediately (before AJAX round-trip)
                const cbtn1 = document.getElementById('fuelSalesClosingBtn');
                const cbtn2 = document.getElementById('fuelSalesClosingBtnReadings');
                if (cbtn1) cbtn1.style.display = 'inline-flex';
                if (cbtn2) cbtn2.style.display = 'inline-flex';

                // Also trigger live AJAX check to confirm server state
                if (typeof window.refreshFuelClosingStatus === 'function') {
                    window.refreshFuelClosingStatus(true);
                }

                showToast('Meter readings submitted! Redirecting to Fuel Sales Closing...', 'success');
                setTimeout(() => {
                    const todayStr = new Date().toISOString().split('T')[0];
                    const shiftStr = '<?= htmlspecialchars($fuel_shift_name, ENT_QUOTES) ?>';
                    window.location.href = 'staff_fuel_sales_closing.php?date=' + encodeURIComponent(todayStr) + '&shift=' + encodeURIComponent(shiftStr) + '&from_readings=1';
                }, 1500);
            } else {
                const firstErr = errors[0] ? errors[0].replace(/^[^:]+:\s*/, '') : 'Submission failed.';
                showToast(firstErr, 'error');
            }
        }

        // ── Generic toast helper ────────────────────────────────────────────────
        function showToast(msg, type = 'success') {
            if (window.showPetronFlash) {
                window.showPetronFlash(msg, type);
                return;
            }
            const colors = {
                success: { bg:'#f0fdf4', color:'#166534', border:'#86efac', icon:'fa-check-circle', iconColor:'#16a34a' },
                error:   { bg:'#fef2f2', color:'#991b1b', border:'#fecaca', icon:'fa-times-circle',  iconColor:'#dc2626' },
                warning: { bg:'#fffbeb', color:'#92400e', border:'#fde68a', icon:'fa-exclamation-triangle', iconColor:'#d97706' },
                info:    { bg:'#eff6ff', color:'#1e40af', border:'#bfdbfe', icon:'fa-info-circle',   iconColor:'#2563eb' },
            };
            const c = colors[type] || colors.success;
            const old = document.getElementById('petronToastRightBanner');
            if (old) old.remove();

            const toast = document.createElement('div');
            toast.id = 'petronToastRightBanner';
            toast.style.cssText = `position:fixed;top:84px;right:22px;left:auto;z-index:999999;` +
                `background:${c.bg};color:${c.color};border:1.5px solid ${c.border};` +
                `padding:12px 18px;border-radius:10px;font-weight:700;` +
                `box-shadow:0 12px 30px rgba(0,0,0,.15);transition:opacity .35s ease, transform .25s ease;` +
                `font-size:13.5px;display:flex;align-items:center;gap:10px;max-width:440px;width:auto;opacity:0;transform:translateY(-10px);`;
            toast.innerHTML = `<i class="fas ${c.icon}" style="color:${c.iconColor};font-size:16px;flex-shrink:0;"></i><span style="flex:1;">${msg}</span>`;
            document.body.appendChild(toast);
            requestAnimationFrame(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateY(0)';
            });
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-10px)';
                    setTimeout(() => toast.remove(), 350);
                }
            }, 3000);
        }

        // ── Custom confirm modal (replaces browser confirm()) ───────────────────
        function showConfirm(msg) {
            return new Promise(resolve => {
                const overlay = document.createElement('div');
                overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99998;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px);';
                overlay.innerHTML = `
                    <div style="background:#ffffff;border-radius:14px;padding:28px 32px;max-width:420px;width:90%;box-shadow:0 12px 36px rgba(0,0,0,.25);text-align:center;">
                        <div style="font-size:28px;color:#002F6C;margin-bottom:14px;"><i class="fas fa-question-circle"></i></div>
                        <p style="font-size:15px;font-weight:600;color:#1e293b;margin:0 0 24px;line-height:1.5;">${msg.replace(/\n/g, '<br>')}</p>
                        <div style="display:flex;gap:14px;justify-content:center;">
                            <button id="confirmNo" type="button" style="padding:10px 24px !important;border-radius:6px !important;border:1px solid #cbd5e1 !important;background-color:#e2e8f0 !important;color:#334155 !important;font-weight:700 !important;cursor:pointer !important;font-size:13px !important;transition:all .2s;" onmouseover="this.style.setProperty('background-color','#cbd5e1','important');this.style.setProperty('color','#0f172a','important');" onmouseout="this.style.setProperty('background-color','#e2e8f0','important');this.style.setProperty('color','#334155','important');">Cancel</button>
                            <button id="confirmYes" type="button" style="padding:10px 24px !important;border-radius:6px !important;border:1px solid #002F6C !important;background-color:#002F6C !important;color:#ffffff !important;font-weight:700 !important;cursor:pointer !important;font-size:13px !important;transition:all .2s;" onmouseover="this.style.setProperty('background-color','#001f4d','important');this.style.setProperty('color','#ffffff','important');" onmouseout="this.style.setProperty('background-color','#002F6C','important');this.style.setProperty('color','#ffffff','important');">Submit</button>
                        </div>
                    </div>`;
                document.body.appendChild(overlay);
                overlay.querySelector('#confirmYes').onclick = () => { overlay.remove(); resolve(true); };
                overlay.querySelector('#confirmNo').onclick  = () => { overlay.remove(); resolve(false); };
            });
        }

                // ── Today's Entries (Table A) — auto-load + refresh after submit ──────
        window.todayEntriesPageSize = 999999;
        window.todayEntriesPage     = 1;
        window.todayEntriesData     = [];

        async function loadTodayEntries(keepPage = false, isBackground = false) {
            const body = document.getElementById('todayEntriesBody');
            const icon = document.getElementById('refreshIcon');
            if (icon && !isBackground) icon.className = 'fas fa-spinner fa-spin';

            // Only show full loading spinner on first load or explicit filter change when table is empty
            if (!isBackground && body && (!window.todayEntriesData || window.todayEntriesData.length === 0)) {
                body.innerHTML = `<div style="text-align:center; padding:40px; color:#94a3b8; font-size:14px; background:#ffffff;">
                    <i class="fas fa-spinner fa-spin" style="font-size:24px; display:block; margin-bottom:12px; color:var(--petron-blue);"></i>
                    Loading today's entries…
                </div>`;
            }

            try {
                // Read local sub-tab date range and shift filter values
                const dateFromVal = document.getElementById('subtab_date_from')?.value || '<?= date('Y-m-01') ?>';
                const dateToVal   = document.getElementById('subtab_date_to')?.value || '<?= date('Y-m-d') ?>';
                const shiftVal    = document.getElementById('subtab_shift')?.value || '';

                const params = new URLSearchParams({ 
                    action: 'summary', 
                    date_from: dateFromVal, 
                    date_to: dateToVal, 
                    auth_user_id: '<?= (int)$me['id'] ?>' 
                });
                if (shiftVal) params.set('shift', shiftVal);

                const url  = `./api_fuel_readings.php?${params.toString()}`;
                const res  = await fetch(url, {credentials:'same-origin'});
                const json = await res.json();

                const readings = json.meter_readings || [];

                // ── Update Summary Cards Counts ──
                const totalCount = readings.length;
                const pendingCount = readings.filter(r => {
                    const s = (r.status || '').toLowerCase().trim();
                    return s === 'pending' || s === 'pending validation';
                }).length;

                const elEncoded = document.getElementById('summary_encoded_count');
                const elPending = document.getElementById('summary_pending_count');
                if (elEncoded) elEncoded.textContent = totalCount;
                if (elPending) elPending.textContent = pendingCount;

                if (!json.success) {
                    if (body) {
                        body.innerHTML = `<div style="text-align:center;padding:40px;color:#ef4444;font-size:14px;background:#ffffff;">
                            <i class="fas fa-exclamation-circle" style="font-size:28px;display:block;margin-bottom:10px;color:#f87171;"></i>
                            Failed to load history entries.
                        </div>`;
                    }
                    if (icon) icon.className = 'fas fa-sync';
                    return;
                }

                window.todayEntriesData = readings;
                if (!keepPage) window.todayEntriesPage = 1;
                try {
                    renderTodayEntriesTable();
                } catch(renderErr) {
                    console.error('renderTodayEntriesTable error:', renderErr);
                    if (body) body.innerHTML = `<div style="text-align:center;padding:40px;color:#ef4444;font-size:13px;background:#ffffff;">
                        <i class="fas fa-exclamation-circle" style="display:block;margin-bottom:6px;font-size:20px;"></i>
                        Error rendering entries. Please refresh the page.
                    </div>`;
                }
            } catch(e) {
                console.error('loadTodayEntries error:', e);
                if (body) {
                    body.innerHTML = `<div style="text-align:center;padding:30px;color:#ef4444;font-size:13px;background:#ffffff;">
                        <i class="fas fa-exclamation-circle" style="display:block;margin-bottom:6px;font-size:20px;"></i>
                        Could not load entries. Please check your connection or refresh the page.
                    </div>`;
                }
            }
            if (icon) icon.className = 'fas fa-sync';
        }

        // ── REAL-TIME BACKGROUND REFRESH FOR METER READING HISTORY (SMOOTH & SILENT, 10s) ──
        let _isRefreshingMeter = false;
        async function autoRefreshMeterReadingHistory() {
            if (_isRefreshingMeter) return;
            if (document.hidden) return; // Skip if browser tab is not visible

            // Only refresh if Meter Reading History card is visible
            const todayCard = document.getElementById('todayEntriesCard');
            if (!todayCard || todayCard.style.display === 'none') return;

            // Do not refresh if user has any modal open
            if (typeof window.isAnyModalOpen === 'function' && window.isAnyModalOpen()) return;
            const modals = ['fuelDetailsModal', 'readingEditModal', 'readingVoidModal', 'readingAdjustModal', 'requestVoidModal', 'requestAdjustModal', 'viewMerchandiseModal', 'viewJobOrderModal'];
            for (let mId of modals) {
                const m = document.getElementById(mId);
                if (m && (m.style.display === 'flex' || m.style.display === 'block')) return;
            }

            try {
                _isRefreshingMeter = true;
                if (typeof loadTodayEntries === 'function') {
                    await loadTodayEntries(true, true); // keepPage = true, isBackground = true (no flicker)
                }
            } catch (e) {
                console.warn('Meter Reading History background refresh notice:', e);
            } finally {
                _isRefreshingMeter = false;
            }
        }

        // Run silent background refresh every 10 seconds
        setInterval(autoRefreshMeterReadingHistory, 10000);
        
        function renderTodayEntriesTable() {
            const body = document.getElementById('todayEntriesBody');
            const rows = window.todayEntriesData || [];
            
            const totalRows = rows.length;
            const pageSize = window.todayEntriesPageSize || 999999; const totalPages = Math.max(1, Math.ceil(totalRows / pageSize));
            if (window.todayEntriesPage > totalPages) window.todayEntriesPage = totalPages;
            
            const startIdx = (window.todayEntriesPage - 1) * window.todayEntriesPageSize;
            const endIdx = Math.min(startIdx + window.todayEntriesPageSize, totalRows);
            const pageRows = rows.slice(startIdx, endIdx);

            // Dual-nozzle code calculator: (1 -> 1-1, 2 -> 1-2, 3 -> 2-1, 4 -> 2-2, 5 -> 3-1, 6 -> 3-2)
            function getDualNozzleCode(seq) {
                const pIdx = Math.ceil(seq / 2);
                const nIdx = ((seq - 1) % 2) + 1;
                return `${pIdx}-${nIdx}`;
            }

            // Assign sequential labels using GROUP-level counter
            function _getFuelGroup(ft) {
                const f = (ft || '').toUpperCase().trim();
                if (f.includes('TURBO') && f.includes('DIESEL')) return 'TURBO DIESEL';
                if (f.includes('DIESEL')) return 'DIESEL';
                if (f.includes('KEROSENE')) return 'KEROSENE';
                if (f.includes('XCS') && f.includes('PLUS'))  return 'XCS PLUS';
                if (f.includes('XTRA') && f.includes('UNL'))  return 'XTRA UNL';
                if (f.includes('BLAZE')) return 'BLAZE';
                return f;
            }
            const _grpTankNozzles = {};
            rows.forEach(r => {
                const fName = (r.fuel_type || '').toUpperCase().trim();
                const pNum  = (r.pump_number || '').toUpperCase().trim();
                const grp   = _getFuelGroup(fName);

                let tankIdx = 1;
                if (/\b(DIESEL\s*2|UNL\s*2|XTRA\s*UNL\s*2)\b/i.test(fName) || /^\s*(?:DIESEL|XTRA\s+UNL)\s*2\b/i.test(pNum)) {
                    tankIdx = 2;
                }

                const key = grp + '_' + tankIdx;
                if (!_grpTankNozzles[key]) _grpTankNozzles[key] = 0;
                _grpTankNozzles[key]++;

                r._seq_label = `${grp} - ${tankIdx}-${_grpTankNozzles[key]}`;
            });

            const statusMap = {
                'pending validation': {color:'#d97706',label:'Pending Validation'},
                'pending':            {color:'#d97706',label:'Pending Validation'},
                'approved':           {color:'#16a34a',label:'Verified'},
                'verified':           {color:'#16a34a',label:'Verified'},
                'validated':          {color:'#16a34a',label:'Verified'},
                'adjusted':           {color:'#2563eb',label:'Adjusted'},
                'rejected':           {color:'#dc2626',label:'Rejected'},
                'readings_submitted': {color:'#0284c7',label:'Submitted'},
                'readings submitted': {color:'#0284c7',label:'Submitted'},
                'submitted':          {color:'#0284c7',label:'Submitted'},
                'closing_completed':  {color:'#16a34a',label:'Closing Completed'},
                'closing completed':  {color:'#16a34a',label:'Closing Completed'},
                'closed':             {color:'#16a34a',label:'Closing Completed'},
            };
            function badge(s) {
                const k = (s||'').toLowerCase().trim();
                const c = statusMap[k] || {color:'#64748b',label:(s||'—').replace(/_/g, ' ')};
                return `<span style="background:${c.color}18; color:${c.color}; border:1.5px solid ${c.color}50; font-weight:800; font-size:11.5px; padding:3px 8px; border-radius:20px; text-transform:uppercase; letter-spacing:.3px; white-space:nowrap; display:inline-block; vertical-align:middle;" title="${c.label}">${c.label}</span>`;
            }
            function fmt(n,d=2){ return Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:d,maximumFractionDigits:d}); }

            function fmtTime(dtStr) {
                if (!dtStr) return '—';
                try {
                    const parts = dtStr.trim().split(' ');
                    if (parts.length < 2) return dtStr;
                    const timeParts = parts[1].split(':');
                    if (timeParts.length < 2) return parts[1];
                    let hour = parseInt(timeParts[0], 10);
                    const minute = timeParts[1];
                    const ampm = hour >= 12 ? 'PM' : 'AM';
                    hour = hour % 12;
                    hour = hour ? hour : 12;
                    return `${hour}:${minute} ${ampm}`;
                } catch(e) {
                    return dtStr;
                }
            }

            function fmtDate(dtStr) {
                if (!dtStr) return '—';
                try {
                    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                    const parts = dtStr.split('-');
                    if (parts.length >= 3) {
                        return `${months[parseInt(parts[1],10)-1]} ${parseInt(parts[2],10)}, ${parts[0]}`;
                    }
                    return dtStr;
                } catch(e) { return dtStr; }
            }
            function fmtShift(shiftPeriod, shiftName) {
                if (shiftName) return shiftName;
                if (!shiftPeriod) return '—';
                const sl = shiftPeriod.toLowerCase();
                if (sl.includes('first') || sl === 'shift_1' || sl === '1') return 'First Shift';
                if (sl.includes('second') || sl === 'shift_2' || sl === '2') return 'Second Shift';
                return shiftPeriod;
            }

            const TH  = 'padding:10px 6px; font-size:11px; font-weight:800; color:#ffffff; text-transform:uppercase; letter-spacing:.2px; line-height:1.25; white-space:nowrap; vertical-align:middle; box-sizing:border-box; border:1px solid #00264D;';
            const THR = TH + ' text-align:right;';

            let html = `<div style="overflow-x:auto; width:100%; border-bottom:1.5px solid #cbd5e1; background:#ffffff; -webkit-overflow-scrolling:touch;">
                <table id="todayReadingsTable" class="report-table no-min-width print-table" style="width:100% !important; min-width:1050px !important; table-layout:fixed !important; border-collapse:collapse !important; text-align:left; box-sizing:border-box;">
                    <colgroup>
                        <col style="width: 8.5%;">  <!-- Date -->
                        <col style="width: 6.5%;">  <!-- Shift -->
                        <col style="width: 11.5%;"> <!-- Name -->
                        <col style="width: 7.5%;">  <!-- Beginning -->
                        <col style="width: 7.5%;">  <!-- Ending -->
                        <col style="width: 8.5%;">  <!-- Calibration -->
                        <col style="width: 7.5%;">  <!-- Volume (L) -->
                        <col style="width: 6.5%;">  <!-- Price/L -->
                        <col style="width: 9.0%;">  <!-- Amount -->
                        <col style="width: 11.0%;"> <!-- Encoded By -->
                        <col style="width: 16.0%;"> <!-- Status -->
                    </colgroup>
                    <thead>
                        <tr style="background:#002F70; border-bottom:2px solid #00264D;">
                            <th style="${TH}" title="Date">Date</th>
                            <th style="${TH}" title="Shift">Shift</th>
                            <th style="${TH}" title="Name">Name</th>
                            <th style="${THR}" title="Beginning">Beginning</th>
                            <th style="${THR}" title="Ending">Ending</th>
                            <th style="${THR}" title="Calibration">Calibration</th>
                            <th style="${THR}" title="Volume (L)">Volume (L)</th>
                            <th style="${THR}" title="Price/L">Price/L</th>
                            <th style="${THR}" title="Amount">Amount</th>
                            <th style="${TH}" title="Encoded By">Encoded By</th>
                            <th style="${TH} text-align:center;" title="Status">Status</th>
                        </tr>
                    </thead>
                    <tbody>`;

            const escapeHtml = (str) => String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

            pageRows.forEach(r => {
                let staffNotes = (r.notes || '').trim();
                let mgrNotes = (r.reject_reason || '').trim();
                let tooltipText = '';
                if (staffNotes) tooltipText += 'Staff: ' + staffNotes;
                if (mgrNotes) tooltipText += (tooltipText ? '\n' : '') + 'Manager: ' + mgrNotes;

                let notesCellContent = '—';
                if (staffNotes && mgrNotes) {
                    notesCellContent = `<div style="line-height:1.2; width:100%; white-space:nowrap;" title="${escapeHtml(tooltipText)}"><strong>S:</strong> ${escapeHtml(staffNotes)}<br><strong>M:</strong> ${escapeHtml(mgrNotes)}</div>`;
                } else if (staffNotes) {
                    notesCellContent = `<div style="line-height:1.2; width:100%; white-space:nowrap;" title="${escapeHtml(tooltipText)}">${escapeHtml(staffNotes)}</div>`;
                } else if (mgrNotes) {
                    notesCellContent = `<div style="line-height:1.2; width:100%; white-space:nowrap; color:#002F70;" title="${escapeHtml(tooltipText)}"><strong>M:</strong> ${escapeHtml(mgrNotes)}</div>`;
                }

                const dateStr = fmtDate(r.reading_date || r.transaction_date);
                const shiftStr = fmtShift(r.shift_period, r.shift_name);
                const fuelStr = r._seq_label || (r.fuel_type || '—').toUpperCase();
                const staffStr = r.staff_name || '—';

                let rBeg = parseFloat(r.beginning) || 0;
                let rEnd = parseFloat(r.ending) || 0;
                let rCal = parseFloat(r.cal !== undefined && r.cal !== null ? r.cal : (r.calibration !== undefined ? r.calibration : (r.staff_calibration || 0))) || 0;
                let rVol = parseFloat(r.volume_liters !== undefined && r.volume_liters !== null ? r.volume_liters : (r.liters_sold || 0)) || 0;
                let rPrice = parseFloat(r.price_per_liter) || 0;
                let rAmt = parseFloat(r.amount !== undefined && r.amount !== null ? r.amount : (r.total_amount || 0)) || 0;

                // Robust frontend fallback calculation: if volume or amount is 0 but readings exist
                if (rVol <= 0 && rEnd > rBeg) {
                    let gross = Math.max(0, rEnd - rBeg);
                    rVol = Math.max(0, gross - rCal);
                }
                if (rAmt <= 0 && rVol > 0 && rPrice > 0) {
                    rAmt = rVol * rPrice;
                }

                html += `<tr style="border-bottom:1px solid #e2e8f0; background:#ffffff; transition: background-color 0.15s ease;" onmouseover="this.style.backgroundColor='#f0f5ff';" onmouseout="this.style.backgroundColor='#ffffff';">
                    <td style="padding:8px 6px; color:#0f172a; font-size:12px; font-weight:700; white-space:nowrap !important; overflow:hidden !important; text-overflow:ellipsis !important; vertical-align:middle; box-sizing:border-box; border:1px solid #e2e8f0;" title="${dateStr}">${dateStr}</td>
                    <td style="padding:8px 6px; color:#334155; font-size:12px; font-weight:700; white-space:nowrap !important; overflow:hidden !important; text-overflow:ellipsis !important; vertical-align:middle; box-sizing:border-box; border:1px solid #e2e8f0;" title="${shiftStr}">${shiftStr}</td>
                    <td style="padding:8px 6px; font-weight:700; color:#002F70; font-size:12px; white-space:nowrap !important; overflow:hidden !important; text-overflow:ellipsis !important; vertical-align:middle; box-sizing:border-box; border:1px solid #e2e8f0;" title="${fuelStr}">${fuelStr}</td>
                    <td style="padding:8px 6px; text-align:right; font-variant-numeric:tabular-nums; font-family:system-ui,-apple-system,sans-serif; color:#0f172a; font-size:12px; font-weight:700; white-space:nowrap !important; overflow:hidden !important; vertical-align:middle; box-sizing:border-box; border:1px solid #e2e8f0;" title="${fmt(rBeg)}">${fmt(rBeg)}</td>
                    <td style="padding:8px 6px; text-align:right; font-variant-numeric:tabular-nums; font-family:system-ui,-apple-system,sans-serif; color:#0f172a; font-weight:700; font-size:12px; white-space:nowrap !important; overflow:hidden !important; vertical-align:middle; box-sizing:border-box; border:1px solid #e2e8f0;" title="${fmt(rEnd)}">${fmt(rEnd)}</td>
                    <td style="padding:8px 6px; text-align:right; font-variant-numeric:tabular-nums; font-family:system-ui,-apple-system,sans-serif; color:#475569; font-size:12px; font-weight:700; white-space:nowrap !important; overflow:hidden !important; vertical-align:middle; box-sizing:border-box; border:1px solid #e2e8f0;" title="${fmt(rCal,3)}">${fmt(rCal,3)}</td>
                    <td style="padding:8px 6px; text-align:right; font-weight:700; font-variant-numeric:tabular-nums; color:#0f172a; font-size:12px; white-space:nowrap !important; overflow:hidden !important; vertical-align:middle; box-sizing:border-box; border:1px solid #e2e8f0;" title="${fmt(rVol)} L">${fmt(rVol)} L</td>
                    <td style="padding:8px 6px; text-align:right; font-variant-numeric:tabular-nums; color:#334155; font-size:12px; font-weight:700; white-space:nowrap !important; overflow:hidden !important; vertical-align:middle; box-sizing:border-box; border:1px solid #e2e8f0;" title="₱${fmt(rPrice)}">₱${fmt(rPrice)}</td>
                    <td style="padding:8px 6px; text-align:right; font-weight:800; font-variant-numeric:tabular-nums; color:#002F70; font-size:12px; white-space:nowrap !important; overflow:hidden !important; vertical-align:middle; box-sizing:border-box; border:1px solid #e2e8f0;" title="₱${fmt(rAmt)}">₱${fmt(rAmt)}</td>
                    <td class="cell-encoded-by" style="padding:8px 6px; color:#1e293b; font-weight:700; font-size:11.5px; line-height:1.25 !important; white-space:normal !important; word-break:normal !important; overflow-wrap:break-word !important; overflow:hidden !important; vertical-align:middle; box-sizing:border-box; border:1px solid #e2e8f0;" title="${staffStr}">${staffStr}</td>
                    <td style="padding:8px 6px; font-size:12px; text-align:center; vertical-align:middle; white-space:nowrap !important; box-sizing:border-box; border:1px solid #e2e8f0;">${badge(r.status)}</td>
                </tr>`;
            });

            if (pageRows.length === 0) {
                html += `<tr>
                    <td colspan="11" style="padding:40px; text-align:center; color:#94a3b8; font-size:15px; font-weight:600; background:#ffffff;">
                        <i class="fas fa-history" style="font-size:28px; display:block; margin-bottom:10px; color:#cbd5e1;"></i>
                        No readings submitted for the selected filter criteria.
                    </td>
                </tr>`;
            }

            html += `</tbody></table></div>`;
            
            // Pagination Footer (Only if Total Records > 10)
            if (totalRows > 10) {
                var showingStart = (window.todayEntriesPage - 1) * window.todayEntriesPageSize + 1;
                var showingEnd = Math.min(window.todayEntriesPage * window.todayEntriesPageSize, totalRows);
                
                html += `
                <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
                    <div style="display:flex; align-items:center;">
                        <span style="font-size:13px; color:#64748b; font-weight:600;">Showing ${showingStart}–${showingEnd} of ${totalRows} entries</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:16px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                            <select onchange="window.todayEntriesPageSize=parseInt(this.value); window.todayEntriesPage=1; renderTodayEntriesTable();" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                                <option value="10" ${window.todayEntriesPageSize === 10 ? 'selected' : ''}>10</option>
                                <option value="20" ${window.todayEntriesPageSize === 20 ? 'selected' : ''}>20</option>
                                <option value="50" ${window.todayEntriesPageSize === 50 ? 'selected' : ''}>50</option>
                                <option value="100" ${window.todayEntriesPageSize === 100 ? 'selected' : ''}>100</option>
                            </select>
                        </div>
                        <div style="display:flex; align-items:center; gap:6px;">
                            <button onclick="if(window.todayEntriesPage>1){ window.todayEntriesPage--; renderTodayEntriesTable(); }" 
                                    style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:${window.todayEntriesPage > 1 ? 'pointer' : 'not-allowed'}; color:${window.todayEntriesPage > 1 ? '#475569' : '#cbd5e1'}; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                                    onmouseover="if(window.todayEntriesPage>1) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <span style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page ${window.todayEntriesPage} of ${totalPages}</span>
                            <button onclick="if(window.todayEntriesPage<${totalPages}){ window.todayEntriesPage++; renderTodayEntriesTable(); }" 
                                    style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:${window.todayEntriesPage < totalPages ? 'pointer' : 'not-allowed'}; color:${window.todayEntriesPage < totalPages ? '#475569' : '#cbd5e1'}; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                                    onmouseover="if(window.todayEntriesPage<${totalPages}) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>`;
            }

            
            body.innerHTML = html;

        }

        // ── Export Sub-Tab Readings ──
        function exportTodayReadings(format) {
            const tableId = 'todayReadingsTable';
            const tableEl = document.getElementById(tableId);
            if (!tableEl) {
                alert('No data available to export.');
                return;
            }
            const dateFromVal = document.getElementById('subtab_date_from')?.value || '<?= date('Y-m-01') ?>';
            const dateToVal   = document.getElementById('subtab_date_to')?.value || '<?= date('Y-m-d') ?>';
            const shiftVal    = document.getElementById('subtab_shift')?.value || 'all_shifts';
            const filename    = `meter_readings_history_${dateFromVal}_to_${dateToVal}_${shiftVal}`;
            const title       = `Petron Fuel Meter Readings History Report (${dateFromVal} to ${dateToVal} - Shift: ${shiftVal.toUpperCase()})`;
            
            if (format === 'excel') {
                if (typeof exportTableToExcel === 'function') {
                    exportTableToExcel(tableId, filename + '.xls');
                } else {
                    alert('Excel export function is not loaded.');
                }
            } else if (format === 'csv') {
                if (typeof exportTableToCSV === 'function') {
                    exportTableToCSV(tableId, filename + '.csv');
                } else {
                    alert('CSV export function is not loaded.');
                }
            } else if (format === 'pdf') {
                if (typeof exportTableToPDF === 'function') {
                    exportTableToPDF(tableId, title);
                } else {
                    alert('PDF export function is not loaded.');
                }
            }
        }

        function refreshTodayEntries() { loadTodayEntries(); }

        // Load on page open
        document.addEventListener('DOMContentLoaded', loadTodayEntries);
        