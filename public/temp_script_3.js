
        // ── In-progress readings session persistence ──
        function persistFuelInputCache() {
            try {
                var data = {};
                var hasAny = false;
                document.querySelectorAll('#encodeCard input[id^="beginning_"], #encodeCard input[id^="ending_"], #encodeCard input[id^="cal_"], input[id^="beginning_fuel_"], input[id^="ending_fuel_"], input[id^="cal_fuel_"]').forEach(function(inp) {
                    if (inp.id && inp.value !== undefined) {
                        data[inp.id] = inp.value;
                        if (inp.value.trim() !== '' && inp.value.trim() !== '0.00' && inp.value.trim() !== '0') {
                            hasAny = true;
                        }
                    }
                });
                if (hasAny) {
                    sessionStorage.setItem('petron_fuel_reading_cache', JSON.stringify(data));
                } else {
                    sessionStorage.removeItem('petron_fuel_reading_cache');
                }
            } catch(e) {}
        }
        window.persistFuelInputCache = persistFuelInputCache;

        function restoreFuelInputCache() {
            try {
                var raw = sessionStorage.getItem('petron_fuel_reading_cache');
                if (!raw) return;
                var data = JSON.parse(raw);
                if (!data || typeof data !== 'object') return;
                Object.keys(data).forEach(function(id) {
                    var inp = document.getElementById(id);
                    if (inp && !inp.readOnly && !inp.disabled && data[id] !== undefined) {
                        inp.value = data[id];
                        var m = id.match(/^(?:beginning|ending|cal)_(.+)$/);
                        if (m && typeof window.updateFuelCalc === 'function') {
                            window.updateFuelCalc(m[1]);
                        }
                    }
                });
            } catch(e) {}
        }
        window.restoreFuelInputCache = restoreFuelInputCache;

        // Clean and reliable input formatting - allows free numeric/decimal entry without cursor jumping
        window.formatOnInput = function(input) {
            if (!input) return;
            var val = input.value;
            // Strip any commas
            var clean = val.replace(/,/g, '');
            // Strip any character that is not a digit or dot
            clean = clean.replace(/[^\d.]/g, '');
            // Only allow a single dot
            var parts = clean.split('.');
            if (parts.length > 2) {
                clean = parts[0] + '.' + parts.slice(1).join('');
            }
            // Max 3 decimal places
            if (parts.length > 1 && parts[1].length > 3) {
                clean = parts[0] + '.' + parts[1].substring(0, 3);
            }
            if (input.value !== clean) {
                var oldStart = input.selectionStart;
                input.value = clean;
                try {
                    input.setSelectionRange(oldStart, oldStart);
                } catch(e) {}
            }
            persistFuelInputCache();
        };

        window.formatOnBlur = function(input) {
            if (!input) return;
            var raw = (input.value || '').replace(/,/g, '').trim();
            if (raw === '') {
                if (input.id && input.id.indexOf('cal_') === 0) {
                    input.value = '0.00';
                }
                persistFuelInputCache();
                return;
            }
            if (raw === '.') raw = '0.00';
            else if (raw.startsWith('.')) raw = '0' + raw;
            var num = parseFloat(raw);
            if (!isNaN(num) && num >= 0) {
                var dec = 2;
                var parts = raw.split('.');
                if (parts.length > 1 && parts[1].length > 2) {
                    dec = Math.min(parts[1].length, 3);
                }
                input.value = num.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: 3 });
            } else {
                if (input.id && input.id.indexOf('cal_') === 0) {
                    input.value = '0.00';
                }
                // Do NOT wipe out non-calibration inputs if user typed something!
            }
            persistFuelInputCache();
        };

        window.handleMeterKeydown = function(e, input) {
            var key = e.key;
            if (key !== 'Enter' && key !== 'ArrowDown' && key !== 'ArrowUp') {
                return;
            }
            e.preventDefault();
            e.stopPropagation();

            var currentId = input.id || '';
            var m = currentId.match(/^(beginning|ending|cal)_(.+)$/);
            if (!m) return;

            var inputType = m[1]; // 'beginning', 'ending', or 'cal'
            var ftId = m[2];

            if (typeof window.formatOnBlur === 'function') window.formatOnBlur(input);
            if (typeof window.updateFuelCalc === 'function') window.updateFuelCalc(ftId);

            var rows = Array.from(document.querySelectorAll('tr[id^="fuelRow_"]'));
            var currentRow = input.closest('tr');
            var currentRowIdx = rows.indexOf(currentRow);
            var targetInput = null;

            if (key === 'Enter') {
                // Natural workflow: Beginning -> Ending -> Calibration -> Next pump row
                if (inputType === 'beginning') {
                    targetInput = document.getElementById('ending_' + ftId);
                } else if (inputType === 'ending') {
                    targetInput = document.getElementById('cal_' + ftId);
                } else if (inputType === 'cal') {
                    if (currentRowIdx !== -1 && currentRowIdx < rows.length - 1) {
                        for (var i = currentRowIdx + 1; i < rows.length; i++) {
                            var nextFtId = (rows[i].id || '').replace('fuelRow_', '');
                            var begInput = document.getElementById('beginning_' + nextFtId);
                            var endInput = document.getElementById('ending_' + nextFtId);
                            if (begInput && !begInput.readOnly && begInput.offsetParent !== null) {
                                targetInput = begInput;
                                break;
                            } else if (endInput && !endInput.readOnly && !endInput.disabled && endInput.offsetParent !== null) {
                                targetInput = endInput;
                                break;
                            }
                        }
                    }
                }
            } else if (key === 'ArrowDown') {
                if (currentRowIdx !== -1 && currentRowIdx < rows.length - 1) {
                    for (var r = currentRowIdx + 1; r < rows.length; r++) {
                        var downFtId = (rows[r].id || '').replace('fuelRow_', '');
                        var candDown = document.getElementById(inputType + '_' + downFtId);
                        if (candDown && !candDown.readOnly && !candDown.disabled && candDown.offsetParent !== null) {
                            targetInput = candDown;
                            break;
                        }
                    }
                }
            } else if (key === 'ArrowUp') {
                if (currentRowIdx > 0) {
                    for (var u = currentRowIdx - 1; u >= 0; u--) {
                        var upFtId = (rows[u].id || '').replace('fuelRow_', '');
                        var candUp = document.getElementById(inputType + '_' + upFtId);
                        if (candUp && !candUp.readOnly && !candUp.disabled && candUp.offsetParent !== null) {
                            targetInput = candUp;
                            break;
                        }
                    }
                }
            }

            if (targetInput) {
                targetInput.focus();
                if (typeof targetInput.select === 'function') {
                    targetInput.select();
                }
                if (typeof targetInput.scrollIntoView === 'function') {
                    targetInput.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            }
        };

        window.updateFuelCalc = window.updateFuelCalc || function(ftId) {};
        