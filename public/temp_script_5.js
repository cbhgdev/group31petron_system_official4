
            (function() {
                'use strict';

                // Only purge stale/corrupted draft form entries; NEVER remove navigation or sidebar state
                try {
                    for (var i = localStorage.length - 1; i >= 0; i--) {
                        var lk = localStorage.key(i);
                        if (lk && lk.indexOf('petron_draft_') === 0 && (lk.indexOf('fuel') !== -1 || lk.indexOf('meter') !== -1 || lk.indexOf('reading') !== -1 || lk.indexOf('transactions_hub') !== -1 || (localStorage.getItem(lk) && localStorage.getItem(lk).indexOf('Γé') !== -1))) {
                            localStorage.removeItem(lk);
                        }
                    }
                } catch(e) {}

                /* ── Parse a formatted number string (may have commas) ── */
                function parseNum(str) {
                    return parseFloat((str || '').toString().replace(/,/g, '')) || 0;
                }

                /* ── Format a number with 2 decimal places + thousand commas ── */
                function fmtNum(n) {
                    return n.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
                }

                /* ── Core calculation for one row ── */
                function calcRow(ftId) {
                    var bEl  = document.getElementById('beginning_' + ftId);
                    var eEl  = document.getElementById('ending_'    + ftId);
                    var cEl  = document.getElementById('cal_'       + ftId);
                    var pEl  = document.getElementById('price_'     + ftId);
                    var vEl  = document.getElementById('volume_'    + ftId);
                    var vvEl = document.getElementById('volume_value_' + ftId);
                    var aEl  = document.getElementById('amount_'    + ftId);
                    var avEl = document.getElementById('amount_value_' + ftId);
                    var msgEl = document.getElementById('cardMsg_'  + ftId);

                    if (!bEl || !eEl || !cEl || !pEl || !vEl || !aEl) return;

                    var beginning = parseNum(bEl.value);
                    var ending    = parseNum(eEl.value);
                    var cal       = parseNum(cEl.value);
                    var price     = parseNum(pEl.value);

                    /* ── Validation ── */
                    var errMsg = '';
                    var hasEndingVal = eEl.value.trim() !== '';
                    if (hasEndingVal && ending < beginning) {
                        errMsg = 'Ending Reading cannot be lower than Beginning Reading.';
                        eEl.style.borderColor = '#dc2626';
                    } else {
                        eEl.style.borderColor = '';
                    }
                    if (cal < 0) {
                        errMsg = errMsg || 'Calibration cannot be negative.';
                        cEl.style.borderColor = '#dc2626';
                    } else {
                        cEl.style.borderColor = '';
                    }
                    if (msgEl && errMsg) {
                        msgEl.style.display = 'block';
                        msgEl.textContent = errMsg;
                    } else if (msgEl) {
                        msgEl.style.display = 'none';
                        msgEl.textContent = '';
                    }

                    /* ── Formulas ── */
                    /* Net Fuel Dispensed = Ending - Beginning - Calibration */
                    var net    = ending - beginning - cal;
                    var volume = net > 0 ? net : 0;
                    /* Fuel Sales Amount = Net Volume × Price/Liter */
                    var amount = volume * price;

                    /* ── Update display fields ── */
                    vEl.value = fmtNum(volume);
                    if (vvEl) vvEl.value = volume.toFixed(2);
                    aEl.value = fmtNum(amount);
                    if (avEl) avEl.value = amount.toFixed(2);
                }

                /* ── Attach listeners to all fuel inputs ── */
                function attachAll() {
                    /* Find all ending_fuel_* and cal_fuel_* inputs */
                    var allEnding = document.querySelectorAll('input[id^="ending_fuel_"]');
                    var allCal    = document.querySelectorAll('input[id^="cal_fuel_"]');
                    var allBeg    = document.querySelectorAll('input[id^="beginning_fuel_"]:not([readonly])');

                    function makeHandler(inp) {
                        return function() {
                            var m = inp.id.match(/^(?:ending|beginning|cal)_(.+)$/);
                            if (m) calcRow(m[1]);
                        };
                    }

                    [].slice.call(allEnding).concat([].slice.call(allCal)).concat([].slice.call(allBeg)).forEach(function(inp) {
                        if (inp._fuelCalcAttached) return;
                        inp._fuelCalcAttached = true;
                        var fn = makeHandler(inp);
                        inp.addEventListener('input',  fn);
                        inp.addEventListener('change', fn);
                        inp.addEventListener('keyup',  fn);
                    });

                    /* Run initial calc for each row */
                    var allFuelIds = <?= json_encode($all_ft_ids_js) ?>;
                    if (Array.isArray(allFuelIds) && allFuelIds.length > 0) {
                        allFuelIds.forEach(function(fid) {
                            calcRow(fid);
                        });
                    } else {
                        [].slice.call(allEnding).concat([].slice.call(allBeg)).forEach(function(inp) {
                            if (typeof window.formatOnBlur === 'function' && inp.value && inp.value.trim() !== '') {
                                window.formatOnBlur(inp);
                            }
                            var m = inp.id.match(/^(?:ending|beginning)_(.+)$/);
                            if (m) calcRow(m[1]);
                        });
                    }
                    /* Restore any in-progress input values from current session */
                    if (typeof window.restoreFuelInputCache === 'function') {
                        window.restoreFuelInputCache();
                    }
                }

                /* ── Expose globally so inline oninput handlers can call it ── */
                window.updateFuelCalc = function(ftId) { calcRow(ftId); };

                /* ── Boot ── */
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function() {
                        attachAll();
                        if (typeof window.restoreFuelInputCache === 'function') window.restoreFuelInputCache();
                    });
                } else {
                    attachAll();
                    if (typeof window.restoreFuelInputCache === 'function') window.restoreFuelInputCache();
                }
                window.addEventListener('load', function() {
                    attachAll();
                    if (typeof window.restoreFuelInputCache === 'function') window.restoreFuelInputCache();
                });
                window.addEventListener('pageshow', function() {
                    if (typeof window.restoreFuelInputCache === 'function') window.restoreFuelInputCache();
                });

            })();
            