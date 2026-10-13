/**
 * petron_realtime.js
 * Complete Global Auto-Refresh & Real-Time Sync Engine — Petron POS System
 *
 * Core Capabilities:
 *  1. Universal AJAX/Fetch Mutation Interceptor:
 *     - Automatically hooks window.fetch & XMLHttpRequest.
 *     - Whenever any POST, PUT, PATCH, or DELETE operation completes successfully
 *       (e.g., creating/editing transactions, job orders, payments, voids, adjustments,
 *        merchandise stock-in, fuel readings, purchase orders, customer approvals),
 *       it immediately re-fetches current server/database truth and updates the UI.
 *  2. Targeted Component Auto-Refresh (refreshActiveView):
 *     - Background-fetches the current URL without full page reload.
 *     - Smoothly swaps affected <tbody> elements, KPI cards, counters, status badges,
 *       and calendar events without disrupting open menus, active focus, or scroll position.
 *  3. Multi-User Real-Time Background Polling:
 *     - Runs every 15 seconds in the background so updates made by OTHER users/terminals
 *       (e.g. manager approving a request, pump attendant encoding meter readings)
 *       appear automatically on all active screens without any manual refresh.
 *  4. Station Isolation & Session Integrity:
 *     - Inherits the current authenticated session and station_id automatically.
 *     - On session timeout (401), cleanly redirects to login.php?timeout=1.
 *     - On maintenance mode (503), handles logout gracefully.
 *  5. Double-Submit Lock / Unlock Protection:
 *     - PetronRealtime.lock(button) / unlock(button) prevents rapid double-clicks.
 */

(function () {
    'use strict';

    /* ── Configuration ──────────────────────────────────────────────────────── */
    var BASE_PATH  = window.PETRON_BASE_PATH || '';
    var API_URL    = BASE_PATH + '/backend/api_refresh.php';

    function getPollIntervalMs() {
        if (typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000) {
            return window.PETRON_AUTO_REFRESH_MS;
        }
        if (typeof window.PETRON_AUTO_REFRESH_SECONDS === 'number' && window.PETRON_AUTO_REFRESH_SECONDS >= 5) {
            return window.PETRON_AUTO_REFRESH_SECONDS * 1000;
        }
        return 10000;
    }
    var POLL_INTERVAL_MS = getPollIntervalMs(); // dynamic default 10s

    var CSRF_TOKEN = (function () {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    })();

    /* ── Internal State ──────────────────────────────────────────────────────── */
    var _handlers         = {};
    var _timers           = {};
    var _pollTimer        = null;
    var _abortCtrls       = {};
    var _inFlight         = {};
    var _isRefreshingView = false;
    var _paused           = false;
    var _lastMutationTime = 0;

    /* ── Helper: Safe Escape ─────────────────────────────────────────────────── */
    function _esc(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /* ── Helper: Is User Actively Typing or Interacting? ─────────────────────── */
    function isUserEditing() {
        var active = document.activeElement;
        if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT')) {
            var inputType = (active.type || '').toLowerCase();
            // Buttons, submits, checkboxes, radios, and hidden inputs do not count as active text typing
            if (['button', 'submit', 'reset', 'checkbox', 'radio', 'hidden'].indexOf(inputType) === -1) {
                if (!active.readOnly && !active.disabled) {
                    return true;
                }
            }
        }

        // If user is on an active encoding card (like Meter Readings Encode Card) with active non-empty inputs
        var encodeCard = document.getElementById('encodeCard');
        if (encodeCard && encodeCard.offsetParent !== null && window.getComputedStyle(encodeCard).display !== 'none') {
            var encInputs = encodeCard.querySelectorAll('input:not([type="hidden"]):not([readonly]), textarea:not([readonly])');
            for (var ei = 0; ei < encInputs.length; ei++) {
                var v = (encInputs[ei].value || '').trim();
                if (v !== '' && v !== '0' && v !== '0.00') {
                    return true;
                }
            }
        }

        // Check if any interactive business modal is currently open and visible
        var openModals = document.querySelectorAll(
            '.modal-overlay, [id$="Modal"], [id*="modal"], .modal.show, .mi-overlay, .sr-modal-overlay'
        );
        for (var i = 0; i < openModals.length; i++) {
            var m = openModals[i];
            if (m.id === 'sessionTimeoutModal' || m.id === 'maintKickoutOverlay' || m.id === 'customerViewModal') continue;
            if (m.offsetParent !== null && window.getComputedStyle(m).display !== 'none' && window.getComputedStyle(m).visibility !== 'hidden') {
                return true;
            }
        }
        return false;
    }

    /* ── Helper: Execute Fetch for Unified API ──────────────────────────────── */
    function _fetchAPI(action, params) {
        if (_paused || _inFlight[action]) return;

        if (_abortCtrls[action]) {
            try { _abortCtrls[action].abort(); } catch (e) {}
        }
        _abortCtrls[action] = typeof AbortController !== 'undefined' ? new AbortController() : null;
        _inFlight[action] = true;

        var url = API_URL + '?action=' + encodeURIComponent(action);
        if (params) {
            Object.keys(params).forEach(function (k) {
                url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            });
        }

        var opts = {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF_TOKEN
            }
        };
        if (_abortCtrls[action]) opts.signal = _abortCtrls[action].signal;

        fetch(url, opts)
            .then(function (res) {
                if (res.status === 401) {
                    window.location.href = BASE_PATH + '/public/login.php?timeout=1';
                    return null;
                }
                if (res.status === 503) {
                    window.location.href = BASE_PATH + '/public/login.php?maintenance=1';
                    return null;
                }
                if (!res.ok) return null;
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.ok) return;
                _dispatch(action, data);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
            })
            .finally(function () {
                _inFlight[action] = false;
            });
    }

    /* ── Helper: Dispatch to Registered Listeners ───────────────────────────── */
    function _dispatch(action, data) {
        var fns = _handlers[action];
        if (fns && fns.length) {
            fns.forEach(function (fn) {
                try { fn(data); } catch (e) { console.warn('[PetronRealtime] handler err:', e); }
            });
        }
        document.dispatchEvent(new CustomEvent('petron:' + action, { detail: data }));
    }

    /* ══════════════════════════════════════════════════════════════════════════
       TARGETED COMPONENT REFRESH (refreshActiveView)
       Re-fetches the current page in the background and swaps:
        - Table <tbody> bodies (transactions, job orders, inventory, POs, history)
        - KPI cards, badges, and counters
        - Calendar events
        WITHOUT reloading the page and WITHOUT losing user context!
    ══════════════════════════════════════════════════════════════════════════ */
    function refreshActiveView(options) {
        options = options || {};
        var isForced = options.force === true;
        if (_paused || _isRefreshingView) return Promise.resolve(false);
        // Active data entry takes absolute precedence unless explicitly forced
        if (!isForced && isUserEditing()) {
            return Promise.resolve(false);
        }

        // Even when forced, never disrupt if user is focused inside an editable text input
        var active = document.activeElement;
        if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA') && !active.readOnly && !active.disabled) {
            var activeType = (active.type || '').toLowerCase();
            if (['button', 'submit', 'reset', 'checkbox', 'radio', 'hidden'].indexOf(activeType) === -1) {
                return Promise.resolve(false);
            }
        }

        if (typeof window.refreshMerchandiseInventoryLive === 'function') {
            _isRefreshingView = true;
            return Promise.resolve()
                .then(function () { return window.refreshMerchandiseInventoryLive({ force: isForced }); })
                .catch(function () { return false; })
                .then(function (res) {
                    _isRefreshingView = false;
                    return res;
                });
        }

        _isRefreshingView = true;

        var targetUrl = window.location.href;
        return fetch(targetUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Petron-Realtime': '1'
            }
        })
        .then(function (resp) {
            if (resp.status === 401) {
                window.location.href = BASE_PATH + '/public/login.php?timeout=1';
                return null;
            }
            if (!resp.ok) return null;
            return resp.text();
        })
        .then(function (html) {
            if (!html) return false;

            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');

            // 1. Update KPI counters & stat cards (elements with id starting with kpi_, stat_, card-, etc.)
            var kpiSelectors = [
                '[id^="kpi_"]', '[id*="_kpi_"]', '[id^="stat_"]', '[id^="card-"]', '[id^="sh_"]', '[id^="fl_"]',
                '.stat-card-value', '.kpi-card-value', '.stat-number', '[data-kpi]', '.inv-stat-val'
            ];
            kpiSelectors.forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (currentEl) {
                    // NEVER touch any element inside the active encode card
                    if (currentEl.closest('#encodeCard') || currentEl.closest('.fet') || currentEl.closest('.fuel-encode-table')) {
                        return;
                    }
                    if (currentEl.id) {
                        var newEl = doc.getElementById(currentEl.id);
                        if (newEl) {
                            var curVal = currentEl.querySelector('.inv-stat-val');
                            var newVal = newEl.querySelector('.inv-stat-val');
                            if (curVal && newVal) {
                                if (curVal.textContent !== newVal.textContent) {
                                    curVal.textContent = newVal.textContent;
                                }
                            } else if (currentEl.innerHTML !== newEl.innerHTML) {
                                currentEl.innerHTML = newEl.innerHTML;
                            }
                        }
                    }
                });
            });

            // 1b. Update tab badge counters without replacing active tab buttons
            ['.inv-tab-nav', '.tab-nav'].forEach(function (sel) {
                var curNav = document.querySelector(sel);
                var nwNav = doc.querySelector(sel);
                if (curNav && nwNav) {
                    var curBtns = curNav.querySelectorAll('button, a');
                    var nwBtns = nwNav.querySelectorAll('button, a');
                    curBtns.forEach(function (btn, idx) {
                        var nwBtn = btn.id ? doc.getElementById(btn.id) : nwBtns[idx];
                        if (!nwBtn) return;
                        var curSpan = btn.querySelector('span');
                        var nwSpan = nwBtn.querySelector('span');
                        if (curSpan && nwSpan && curSpan.textContent !== nwSpan.textContent) {
                            curSpan.textContent = nwSpan.textContent;
                        }
                    });
                }
            });

            // 2. Direct TBODY swaps for dedicated transaction & history tables
            var tbodySelectors = [
                '#mhTableBody', '#jomTableBody', '#fhTableBody', '#joTableBody',
                '#histTbody', '#merchTableBody', '#fuelTableBody', '#reportTableBody',
                '#mgrStaffAdjTable tbody', '#mgrInventoryTable tbody', '#alertTableBody',
                '#fsrTableBody', '#srTableBody', '#fuelTable tbody', '#stockInTable tbody'
            ];
            tbodySelectors.forEach(function (tbsel) {
                var curTb = document.querySelector(tbsel);
                var newTb = doc.querySelector(tbsel);
                if (curTb && newTb && curTb.innerHTML !== newTb.innerHTML) {
                    curTb.innerHTML = newTb.innerHTML;
                }
            });

            // 3. Update Table Bodies (Preserves table headers, colgroups, and scroll position)
            var tableSelectors = [
                '#joUnifiedTable', '#mhHistoryTable', '#jomHistoryTable', '#fuelHistoryTable',
                '#histTbl', '#mhTable', '#jomTable', '#reportTable', '#inventoryTable', '#merchandiseTable',
                '#merchTable', '#poTable', '#shiftsTable', '#fuelPriceTable', '#pumpTable',
                '#tanksTable', '#customerTable', '#customersTable', '#auditTable', '#adminTable',
                '#mgrStaffAdjTable', '#mgrInventoryTable', '#alertTable', '#fsrTable', '#fuelTable',
                'table.txn-table', 'table.data-table',
                'table.manager-table', 'table.admin-table',
                'table.mgr-table', 'table.adm-table', 'table.dev-inner-table',
                'table.vt-table', '.vt-table',
                'table.afto-tbl', '.afto-tbl',
                '.petron-table', '.table-custom'
            ];
            tableSelectors.forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (currentTbl) {
                    // CRITICAL: NEVER overwrite any table inside encodeCard or any table containing active input fields!
                    if (currentTbl.closest('#encodeCard') || currentTbl.classList.contains('fet') || currentTbl.classList.contains('fuel-encode-table')) {
                        return;
                    }
                    if (currentTbl.querySelector('input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), textarea, select')) {
                        return; // Active data-entry form!
                    }
                    var newTbl = currentTbl.id ? doc.getElementById(currentTbl.id) : doc.querySelector(sel);
                    if (newTbl) {
                        var curTbody = currentTbl.querySelector('tbody');
                        var newTbody = newTbl.querySelector('tbody');
                        if (curTbody && newTbody && curTbody.innerHTML !== newTbody.innerHTML) {
                            var isNewStub = newTbody.querySelector('td.empty') || newTbody.textContent.toLowerCase().indexOf('loading') !== -1;
                            var hasCurData = curTbody.querySelector('tr:not(.empty)');
                            if (isNewStub && hasCurData) {
                                // Skip overwriting active rendered data with an empty loading stub
                            } else {
                                curTbody.innerHTML = newTbody.innerHTML;
                            }
                        }
                    }
                });
            });

            // 4. Update targeted counters & text elements by ID
            var textIds = [
                'mhShowingEntriesText', 'jomShowingEntriesText', 'mhPageLabel', 'jomPageLabel',
                'fhPageLabel', 'mhKpiTxnCount', 'mhKpiTotalEncoded', 'joPendingCountBadge',
                'joApprovedCountBadge', 'joRejectedCountBadge', 'joTrackerTotalCount',
                'kpi_jo_count', 'kpi_merch_released', 'kpi_total_encoded',
                'srBannerTitle', 'srBannerText'
            ];
            textIds.forEach(function (id) {
                var cur = document.getElementById(id);
                var nw = doc.getElementById(id);
                if (cur && nw && cur.innerHTML !== nw.innerHTML) {
                    cur.innerHTML = nw.innerHTML;
                }
            });

            // 5. Update Calendar Views (Grid / Events)
            var calSelectors = ['.cal-grid', '.cal-events', '#calendarGrid', '#calendarView'];
            calSelectors.forEach(function (sel) {
                var currentCal = document.querySelector(sel);
                var newCal = doc.querySelector(sel);
                if (currentCal && newCal && currentCal.innerHTML !== newCal.innerHTML) {
                    currentCal.innerHTML = newCal.innerHTML;
                }
            });

            // 6. Update Header Badges & Sidebar Counters from New HTML
            var badgeSelectors = [
                '#notificationBadge', '#badge-notifications', '.header-notif-badge',
                '#notifDropdownBadge', '[data-badge-key]', '[id^="badge-"]'
            ];
            badgeSelectors.forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (currentBdg) {
                    if (currentBdg.id) {
                        var newBdg = doc.getElementById(currentBdg.id);
                        if (newBdg) {
                            currentBdg.textContent = newBdg.textContent;
                            currentBdg.style.display = newBdg.style.display;
                        }
                    }
                });
            });

            // 7. Re-run local UI initializers & paginations if they exist
            if (typeof window.mhRender === 'function') {
                try { window.mhRender(); } catch (e) {}
            }
            if (typeof window.jomRender === 'function') {
                try { window.jomRender(); } catch (e) {}
            }
            if (typeof window.fhRender === 'function') {
                try { window.fhRender(); } catch (e) {}
            }
            if (typeof window.applyFilters === 'function') {
                try { window.applyFilters(); } catch (e) {}
            }
            if (typeof window.filterInvTable === 'function') {
                try { window.filterInvTable(); } catch (e) {}
            }
            if (typeof window.filterFuelTable === 'function') {
                try { window.filterFuelTable(); } catch (e) {}
            }
            if (typeof window.applyFuelInventoryFilters === 'function') {
                try { window.applyFuelInventoryFilters(); } catch (e) {}
            }
            if (typeof window.initAllPetronDropdowns === 'function') {
                try { window.initAllPetronDropdowns(); } catch (e) {}
            }
            if (typeof window.initPetronPagination === 'function') {
                try { window.initPetronPagination(); } catch (e) {}
            }
            if (typeof window.joApplyFilters === 'function') {
                try { window.joApplyFilters(); } catch (e) {}
            }
            if (typeof window.mftvRender === 'function') {
                try { window.mftvRender(); } catch (e) {}
            }
            if (typeof window.mvtRender === 'function') {
                try { window.mvtRender(); } catch (e) {}
            }
            if (typeof window.updateBatchButtons === 'function') {
                try { window.updateBatchButtons(); } catch (e) {}
            }
            if (typeof window.handleLiveCustomerUpdate === 'function') {
                try { window.handleLiveCustomerUpdate(); } catch (e) {}
            }
            if (typeof window.isNotificationDropdownOpen === 'function' && window.isNotificationDropdownOpen()) {
                if (typeof window.petronLoadNotificationsOnly === 'function') {
                    try { window.petronLoadNotificationsOnly(false); } catch (e) {}
                }
            }

            document.dispatchEvent(new CustomEvent('petron:view-refreshed', { detail: { url: targetUrl } }));
            return true;
        })
        .catch(function (err) {
            console.warn('[PetronRealtime] refreshActiveView notice:', err);
            return false;
        })
        .finally(function () {
            _isRefreshingView = false;
        });
    }

    /* ══════════════════════════════════════════════════════════════════════════
       GLOBAL MUTATION INTERCEPTOR (Fetch & XMLHttpRequest)
       Listens to EVERY business save / update across ALL system modules.
    ══════════════════════════════════════════════════════════════════════════ */
    function _isInternalOrTrackingUrl(url) {
        if (!url || typeof url !== 'string') return false;
        return url.indexOf('api_refresh.php') !== -1 ||
               url.indexOf('maintenance_status.php') !== -1 ||
               url.indexOf('badge_seen.php') !== -1 ||
               url.indexOf('notifications_api.php') !== -1 ||
               url.indexOf('staff_fuel_sales_closing_handler.php') !== -1 ||
               url.indexOf('check_closing_status') !== -1 ||
               url.indexOf('keepalive') !== -1 ||
               url.indexOf('ping') !== -1;
    }

    function initGlobalMutationInterceptor() {
        // ── 1. Intercept window.fetch ──
        if (typeof window.fetch === 'function') {
            var origFetch = window.fetch;
            window.fetch = function () {
                var args = arguments;
                var url = (args[0] && typeof args[0] === 'string') ? args[0] : (args[0] && args[0].url ? args[0].url : '');
                var opts = args[1] || {};
                var method = (opts.method || (args[0] && args[0].method) || 'GET').toUpperCase();

                return origFetch.apply(this, args).then(function (response) {
                    // Check if this was a mutation (POST, PUT, PATCH, DELETE) to an app backend
                    if (['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(method) !== -1) {
                        // Exclude internal polling, tracking, and status checks from triggering cascade
                        var isInternal = _isInternalOrTrackingUrl(url);

                        if (!isInternal && response && (response.status >= 200 && response.status < 300)) {
                            _lastMutationTime = Date.now();
                            setTimeout(function () {
                                PetronRealtime.trigger(['notifications', 'badges', 'dashboard']);
                                refreshActiveView({ force: true });

                                // Trigger page-specific refresh routines if declared
                                if (typeof window.autoRefreshJobOrderTracker === 'function') {
                                    try { window.autoRefreshJobOrderTracker(true); } catch (e) {}
                                }
                                if (typeof window.autoRefreshMerchandiseHistory === 'function') {
                                    try { window.autoRefreshMerchandiseHistory(true); } catch (e) {}
                                }
                                if (typeof window.refreshManagerDashboard === 'function') {
                                    try { window.refreshManagerDashboard(); } catch (e) {}
                                }
                                if (typeof window.autoRefreshAdminDashboard === 'function') {
                                    try { window.autoRefreshAdminDashboard(); } catch (e) {}
                                }
                                if (typeof window.refreshStaffDashboard === 'function') {
                                    try { window.refreshStaffDashboard(); } catch (e) {}
                                }
                                if (typeof window.loadStaffNotifications === 'function') {
                                    try { window.loadStaffNotifications(); } catch (e) {}
                                }
                                if (typeof window.fetchUnreadCount === 'function') {
                                    try { window.fetchUnreadCount(); } catch (e) {}
                                }
                            }, 350);
                        }
                    }
                    return response;
                });
            };
        }

        // ── 2. Intercept XMLHttpRequest ──
        if (typeof window.XMLHttpRequest === 'function') {
            var origOpen = XMLHttpRequest.prototype.open;
            var origSend = XMLHttpRequest.prototype.send;

            XMLHttpRequest.prototype.open = function (method, url) {
                this._petronMethod = (method || 'GET').toUpperCase();
                this._petronUrl    = url || '';
                return origOpen.apply(this, arguments);
            };

            XMLHttpRequest.prototype.send = function () {
                var xhr = this;
                var method = xhr._petronMethod || 'GET';
                var url = xhr._petronUrl || '';

                if (['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(method) !== -1) {
                    xhr.addEventListener('load', function () {
                        var isInternal = _isInternalOrTrackingUrl(url);

                        if (!isInternal && xhr.status >= 200 && xhr.status < 300) {
                            _lastMutationTime = Date.now();
                            setTimeout(function () {
                                PetronRealtime.trigger(['notifications', 'badges', 'dashboard']);
                                refreshActiveView({ force: true });

                                if (typeof window.autoRefreshJobOrderTracker === 'function') {
                                    try { window.autoRefreshJobOrderTracker(true); } catch (e) {}
                                }
                                if (typeof window.autoRefreshMerchandiseHistory === 'function') {
                                    try { window.autoRefreshMerchandiseHistory(true); } catch (e) {}
                                }
                                if (typeof window.fetchUnreadCount === 'function') {
                                    try { window.fetchUnreadCount(); } catch (e) {}
                                }
                            }, 350);
                        }
                    });
                }
                return origSend.apply(this, arguments);
            };
        }
    }

    /* ══════════════════════════════════════════════════════════════════════════
       PUBLIC API — window.PetronRealtime
    ══════════════════════════════════════════════════════════════════════════ */
    var PetronRealtime = {
        /**
         * Register a custom handler for an action.
         */
        on: function (action, fn) {
            if (!_handlers[action]) _handlers[action] = [];
            _handlers[action].push(fn);
            return this;
        },

        /**
         * Trigger immediate refresh of one or more actions.
         */
        trigger: function (actions) {
            var list = Array.isArray(actions) ? actions : [actions];
            list.forEach(function (a) { _fetchAPI(a); });
            return this;
        },

        /**
         * Targeted background refresh of the current page's active tables & KPIs.
         */
        refreshActiveView: refreshActiveView,

        /**
         * Prevent double-clicks on buttons during save/submit.
         */
        lock: function (btn, loadingText) {
            if (!btn) return;
            btn.disabled = true;
            if (!btn._petronOrigText) btn._petronOrigText = btn.innerHTML;
            if (loadingText !== false) {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (loadingText || 'Saving...');
            }
        },

        unlock: function (btn) {
            if (!btn) return;
            btn.disabled = false;
            if (btn._petronOrigText) {
                btn.innerHTML = btn._petronOrigText;
                delete btn._petronOrigText;
            }
        },

        pause:  function () { _paused = true; return this; },
        resume: function () { _paused = false; return this; },
        resetPollingInterval: function (ms) {
            if (typeof ms === 'number' && ms >= 5000) {
                window.PETRON_AUTO_REFRESH_MS = ms;
                window.PETRON_AUTO_REFRESH_SECONDS = Math.round(ms / 1000);
            }
            if (typeof schedulePolling === 'function') {
                schedulePolling();
            }
            return this;
        },
        getPollingInterval: function () {
            return typeof getPollIntervalMs === 'function' ? getPollIntervalMs() : 10000;
        },
    };

    // Auto-listen to global auto-refresh changes dispatched across tabs/pages
    document.addEventListener('petron:auto-refresh-interval-changed', function (e) {
        if (e.detail && e.detail.intervalMs) {
            PetronRealtime.resetPollingInterval(e.detail.intervalMs);
        }
    });

    window.PetronRealtime = PetronRealtime;

    /* ── Built-in Notification Bell Handler ─────────────────────────────────── */
    PetronRealtime.on('notifications', function (data) {
        var count = parseInt(data.unread, 10) || 0;
        var badge = document.getElementById('notificationBadge') || document.querySelector('.header-notif-badge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'inline-flex';
                badge.style.setProperty('background', '#dc2626', 'important');
            } else {
                badge.textContent = '';
                badge.style.display = 'none';
            }
        }
        var ddBadge = document.getElementById('notifDropdownBadge');
        if (ddBadge) {
            if (count > 0) {
                ddBadge.textContent = count + ' New';
                ddBadge.style.background = '#dc2626';
            } else {
                ddBadge.textContent = '0 New';
                ddBadge.style.background = '#002F6C';
            }
        }
    });

    /* ── Built-in Sidebar Badges Handler ────────────────────────────────────── */
    PetronRealtime.on('badges', function (data) {
        var b = data.badges || {};
        Object.keys(b).forEach(function (key) {
            var count = parseInt(b[key], 10) || 0;
            var sels = ['[data-badge-key="' + key + '"]', '#badge-' + key];
            sels.forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (el) {
                    el.textContent = count > 0 ? (count > 99 ? '99+' : count) : '';
                    el.style.display = count > 0 ? 'inline-flex' : 'none';
                });
            });
        });
    });

    /* ── Built-in Dashboard Handler ─────────────────────────────────────────── */
    PetronRealtime.on('dashboard', function (data) {
        if (!data || !data.kpi) return;
        var kpi = data.kpi;
        Object.keys(kpi).forEach(function (key) {
            document.querySelectorAll('[data-kpi="' + key + '"]').forEach(function (el) {
                var val = kpi[key];
                if (key.indexOf('total') !== -1 || key.indexOf('amount') !== -1 || key.indexOf('ar') !== -1) {
                    el.textContent = '₱' + parseFloat(val || 0).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                } else {
                    el.textContent = val;
                }
            });
        });
    });

    /* ── Page Visibility API (Pause when tab is hidden, resume on focus) ─────── */
    if (typeof document.hidden !== 'undefined') {
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                PetronRealtime.pause();
            } else {
                PetronRealtime.resume();
                PetronRealtime.trigger(['notifications', 'badges']);
                refreshActiveView({ force: false });
            }
        });
    }

    function schedulePolling() {
        if (_pollTimer) clearInterval(_pollTimer);
        var interval = getPollIntervalMs();
        _pollTimer = setInterval(runBackgroundPolling, interval);
    }

    /* ── Background Real-Time Polling Loop (Dynamic Interval) ─────────────────── */
    function runBackgroundPolling() {
        if (!_paused && !isUserEditing()) {
            PetronRealtime.trigger(['notifications', 'badges']);

            // Auto-refresh active view if at least minElapsed elapsed since last user mutation
            var interval = getPollIntervalMs();
            var minElapsed = Math.min(5000, interval);
            if (Date.now() - _lastMutationTime > minElapsed) {
                refreshActiveView({ force: false });
            }
        }
    }

    /* ── Initialize on DOMContentLoaded ──────────────────────────────────────── */
    initGlobalMutationInterceptor();

    document.addEventListener('DOMContentLoaded', function () {
        // Initial fetch of unread count and badges
        PetronRealtime.trigger(['notifications', 'badges']);

        // Start dynamic background polling loop
        schedulePolling();
    });

})();
