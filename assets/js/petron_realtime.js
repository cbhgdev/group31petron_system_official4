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
        return 15000;
    }
    var POLL_INTERVAL_MS = getPollIntervalMs(); // dynamic fallback 15s

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
            // If user is focused on an editable input, do not disturb
            if (!active.readOnly && !active.disabled) return true;
        }
        // Check if any modal is currently visible
        var openModals = document.querySelectorAll(
            '.modal-overlay[style*="flex"], .modal-overlay[style*="block"], ' +
            '[id$="Modal"][style*="flex"], [id$="Modal"][style*="block"], ' +
            '[id*="modal"][style*="flex"], [id*="modal"][style*="block"], ' +
            '.modal.show, .mi-overlay[style*="flex"], .mi-overlay[style*="block"]'
        );
        for (var i = 0; i < openModals.length; i++) {
            var m = openModals[i];
            if (m.offsetParent !== null && window.getComputedStyle(m).display !== 'none') {
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
        if (_paused || _isRefreshingView) return Promise.resolve(false);
        var force = options && options.force;
        if (!force && isUserEditing()) {
            return Promise.resolve(false);
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

            // 1. Update KPI counters & stat cards (elements with id starting with kpi_, stat_, etc.)
            var kpiSelectors = [
                '[id^="kpi_"]', '[id*="_kpi_"]', '[id^="stat_"]', '[id^="sh_"]', '[id^="fl_"]',
                '.stat-card-value', '.kpi-card-value', '.stat-number', '[data-kpi]'
            ];
            kpiSelectors.forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (currentEl) {
                    if (currentEl.id) {
                        var newEl = doc.getElementById(currentEl.id);
                        if (newEl && currentEl.innerHTML !== newEl.innerHTML) {
                            currentEl.innerHTML = newEl.innerHTML;
                        }
                    }
                });
            });

            // 2. Update Table Bodies (Preserves table headers, colgroups, and scroll position)
            var tableSelectors = [
                '#joUnifiedTable', '#mhTable', '#jomTable', '#reportTable',
                'table.txn-table', 'table.report-table', 'table.data-table',
                'table.print-table', 'table.manager-table', 'table.admin-table',
                'table.afto-tbl', '.afto-tbl'
            ];
            tableSelectors.forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (currentTbl) {
                    var newTbl = currentTbl.id ? doc.getElementById(currentTbl.id) : doc.querySelector(sel);
                    if (newTbl) {
                        var curTbody = currentTbl.querySelector('tbody');
                        var newTbody = newTbl.querySelector('tbody');
                        if (curTbody && newTbody && curTbody.innerHTML !== newTbody.innerHTML) {
                            curTbody.innerHTML = newTbody.innerHTML;
                        }
                    }
                });
            });

            // 3. Update Calendar Views (Grid / Events)
            var calSelectors = ['.cal-grid', '.cal-events', '#calendarGrid', '#calendarView'];
            calSelectors.forEach(function (sel) {
                var currentCal = document.querySelector(sel);
                var newCal = doc.querySelector(sel);
                if (currentCal && newCal && currentCal.innerHTML !== newCal.innerHTML) {
                    currentCal.innerHTML = newCal.innerHTML;
                }
            });

            // 4. Update Header Badges & Sidebar Counters from New HTML
            var badgeSelectors = [
                '#notificationBadge', '#badge-notifications', '.header-notif-badge',
                '[data-badge-key]', '[id^="badge-"]'
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

            // 5. Re-run local UI initializers if they exist
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
            if (typeof window.updateBatchButtons === 'function') {
                try { window.updateBatchButtons(); } catch (e) {}
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
                        // Exclude internal polling heartbeats from triggering cascade
                        var isInternal = url.indexOf('api_refresh.php') !== -1 ||
                                         url.indexOf('maintenance_status.php') !== -1 ||
                                         url.indexOf('ping') !== -1;

                        if (!isInternal && response && (response.status >= 200 && response.status < 300)) {
                            _lastMutationTime = Date.now();
                            // Execute immediate cascade refresh
                            setTimeout(function () {
                                PetronRealtime.trigger(['notifications', 'badges', 'dashboard']);
                                refreshActiveView({ force: true });

                                // Trigger page-specific refresh routines if declared
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
                        var isInternal = url.indexOf('api_refresh.php') !== -1 ||
                                         url.indexOf('maintenance_status.php') !== -1;

                        if (!isInternal && xhr.status >= 200 && xhr.status < 300) {
                            _lastMutationTime = Date.now();
                            setTimeout(function () {
                                PetronRealtime.trigger(['notifications', 'badges', 'dashboard']);
                                refreshActiveView({ force: true });
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
            return typeof getPollIntervalMs === 'function' ? getPollIntervalMs() : 15000;
        },
    };

    window.PetronRealtime = PetronRealtime;

    /* ── Built-in Notification Bell Handler ─────────────────────────────────── */
    PetronRealtime.on('notifications', function (data) {
        var count = parseInt(data.unread, 10) || 0;
        var badge = document.getElementById('notificationBadge') || document.querySelector('.header-notif-badge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'inline-flex';
            } else {
                badge.style.display = 'none';
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
