/**
 * Petron Station Management System
 * Universal Live System Synchronization & Dynamic Auto-Refresh Engine
 * assets/js/live_sync.js  v3.0
 *
 * Automatically refreshes dynamic data globally across all roles and modules without full browser/page reloads:
 *  - Notifications & Alerts (10–12 sec)
 *  - Dashboard KPIs & Status Cards (15–20 sec)
 *  - Transaction Records, POS History & Tracker (15–20 sec)
 *  - Fuel Meter Readings & Fuel Sales Closing (15–20 sec)
 *  - Inventory Stocks, Stock-In & Alerts (15–20 sec)
 *  - Stock Requests, Purchase Orders & Approvals (10–15 sec)
 *  - Receivables, Audit Trail & User Management (20–30 sec)
 *
 * Form Safety:
 *  - Active data-entry forms, open modals, and focused input fields are NEVER overwritten.
 *  - Unfinished user inputs are protected by Global Draft Engine while tables and counters update.
 */

(function(window, document) {
    'use strict';

    if (window.LiveSyncEngine && window.LiveSyncEngine.version === '3.1') return;

    // ── Configuration & Dynamic Intervals ──────────────────────────
    function getSyncIntervalMs() {
        if (typeof window.PETRON_AUTO_REFRESH_MS === 'number' && window.PETRON_AUTO_REFRESH_MS >= 5000) {
            return window.PETRON_AUTO_REFRESH_MS;
        }
        if (typeof window.PETRON_AUTO_REFRESH_SECONDS === 'number' && window.PETRON_AUTO_REFRESH_SECONDS >= 5) {
            return window.PETRON_AUTO_REFRESH_SECONDS * 1000;
        }
        return 10000; // default 10 seconds (optimal real-time rate)
    }

    let isSyncingNotifs       = false;
    let isSyncingData         = false;
    let lastNotifCount        = -1;
    let lastSyncTime          = null;
    let notifTimer            = null;
    let dataTimer             = null;

    // ── App Base Path Detection ────────────────────────────────────
    function getAppBasePath() {
        if (window.pageData && window.pageData.appBasePath) {
            return window.pageData.appBasePath.replace(/\/$/, '');
        }
        const scripts = document.querySelectorAll('script[src*="live_sync.js"]');
        for (const s of scripts) {
            const src = s.getAttribute('src') || '';
            if (src.includes('/assets/js/')) {
                return src.split('/assets/js/')[0];
            }
        }
        const pathname = window.location.pathname;
        const publicPos = pathname.indexOf('/public/');
        if (publicPos !== -1) {
            return pathname.substring(0, publicPos);
        }
        return '';
    }

    const basePath     = getAppBasePath();
    const syncEndpoint = basePath + '/backend/api/live_system_sync.php';

    // ── Helper: Check if user is actively interacting with an element ─
    function isUserBusy() {
        const active = document.activeElement;
        if (active && (
            active.tagName === 'INPUT'    ||
            active.tagName === 'TEXTAREA' ||
            active.tagName === 'SELECT'   ||
            active.isContentEditable
        )) {
            return true;
        }
        const encodeCard = document.getElementById('encodeCard');
        if (encodeCard && window.getComputedStyle(encodeCard).display !== 'none') {
            return true;
        }
        if (document.querySelector(
            '.modal.show, .modal[style*="display: block"], .modal[style*="display: flex"], ' +
            '.swal2-container, .popover.show, .dropdown-menu.show'
        )) {
            return true;
        }
        return false;
    }

    // ── Helper: Check if a specific container contains active editing elements ─
    function isContainerBeingEdited(container) {
        if (!container) return false;
        // Never touch the fuel encode card or table
        if (container.closest('#encodeCard') || container.id === 'encodeCard' || container.classList.contains('fet') || container.classList.contains('fuel-encode-table')) {
            return true;
        }
        const active = document.activeElement;
        if (active && container.contains(active)) {
            if (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT') {
                return true;
            }
        }
        // If container has populated input fields that are not submitted, it is being edited!
        const inputs = container.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([readonly]), textarea:not([readonly])');
        for (let i = 0; i < inputs.length; i++) {
            if (inputs[i].value && String(inputs[i].value).trim() !== '' && inputs[i].value !== '0' && inputs[i].value !== '0.00') {
                return true;
            }
        }
        return false;
    }

    // ── Header Notification Badges ────────────────────────────────
    function updateNotificationBadge(count) {
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'block';
                badge.style.background = '#dc3545';
            } else {
                badge.style.display = 'none';
            }
        }
        document.querySelectorAll('.header-notif-badge, .notif-badge-count, #headerNotifBadge').forEach(b => {
            b.textContent = count > 0 ? (count > 99 ? '99+' : count) : '';
            b.style.display = count > 0 ? 'inline-flex' : 'none';
        });
    }

    // ── Sidebar Navigation Badges ──────────────────────────────────
    function updateSidebarBadges(badges) {
        if (!badges || typeof badges !== 'object') return;
        // Strict 1:1 mapping: each key from the server updates ONLY the exact
        // [data-sidebar-badge="<key>"] element. No fan-out / aliasing allowed,
        // because that was causing badges to bleed across Reports ↔ Inventory.
        Object.keys(badges).forEach(key => {
            const val = parseInt(badges[key], 10) || 0;
            document.querySelectorAll(`[data-sidebar-badge="${key}"]`).forEach(el => {
                if (val > 0) {
                    el.textContent   = val > 99 ? '99+' : val;
                    el.style.display = 'flex';
                } else {
                    el.textContent   = '';
                    el.style.display = 'none';
                }
            });
        });
    }


    // ── Fast Polling: Notifications, Alerts & Badges ───────────────
    async function syncNotificationsAndBadges() {
        if (isSyncingNotifs || document.hidden) return;
        isSyncingNotifs = true;

        try {
            const ctrl = new AbortController();
            const tid = setTimeout(() => ctrl.abort(), 6000);
            const res = await fetch(syncEndpoint, {
                method: 'GET',
                signal: ctrl.signal,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' }
            });
            clearTimeout(tid);

            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            if (data && data.success) {
                lastSyncTime = data.formatted_time;
                const newCount = data.unread_notifications || 0;
                if (newCount !== lastNotifCount) {
                    updateNotificationBadge(newCount);
                    lastNotifCount = newCount;
                    if (typeof window._petronNotifUpdateBadge === 'function') {
                        window._petronNotifUpdateBadge(newCount, null);
                    }
                    // Refresh open notification dropdown if visible
                    const nd = document.getElementById('notificationDropdown');
                    if (nd && (nd.classList.contains('show') || nd.style.display === 'block')) {
                        if (typeof window.loadStaffNotifications === 'function') window.loadStaffNotifications();
                        else if (typeof window.petronLoadNotifications === 'function') window.petronLoadNotifications();
                    }
                }
                updateSidebarBadges(data.sidebar_badges || {});
                document.dispatchEvent(new CustomEvent('petron:live_sync', { detail: data }));
            }
        } catch (e) {
            // silent fail
        } finally {
            isSyncingNotifs = false;
        }
    }

    // ── Dynamic DOM Fragment Refreshing (No Page Reload) ───────────
    async function refreshDynamicPageFragments() {
        if (isSyncingData || document.hidden || isUserBusy()) return;

        // Never interrupt user while focused or typing in any form input
        const active = document.activeElement;
        if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT')) {
            return;
        }

        // Never perform background DOM fragment replacement while staff is on fuel encode screen
        const encodeCard = document.getElementById('encodeCard');
        if (encodeCard && window.getComputedStyle(encodeCard).display !== 'none') {
            return;
        }

        isSyncingData = true;

        try {
            // 1. If page has dedicated refresh routines, invoke them first ONLY if viewing history
            if (typeof window.loadTodayEntries === 'function') {
                const todayCard = document.getElementById('todayEntriesCard');
                if (todayCard && todayCard.style.display !== 'none') {
                    try { window.loadTodayEntries(true, true); } catch(e) {}
                }
            }
            if (typeof window.loadJobOrderTracker === 'function') {
                try { window.loadJobOrderTracker(); } catch(e) {}
            }
            if (typeof window.refreshLivePageData === 'function') {
                try { window.refreshLivePageData(); } catch(e) {}
            }

            // 2. Target presentation selectors to dynamically synchronize
            const targetSelectors = [
                // Tables and lists
                '#todayEntriesCard',
                '#fuelHistoryTable',
                '#fuelHistoryTbody',
                '#merchandiseHistoryTable',
                '#merchandiseHistoryTbody',
                '#stockRequestsTable',
                '#stockRequestsTbody',
                '#inventoryTable',
                '#inventoryTbody',
                '#merchTable',
                '#jobOrderTracker',
                '#jobOrderTrackerTbody',
                '#usersTable',
                '#usersTbody',
                '#auditLogsTable',
                '#auditLogsTbody',
                '#transactionsTable',
                '#transactionsTbody',
                '#fuelTransactionsTable',
                '#fuelTransactionsTbody',
                '#backupTable',
                '#backupHistoryTbody',
                '#pendingApprovalsCard',
                '#posPendingOrdersList',
                '#recentActivitiesList',
                // KPI Cards & Metric Summaries
                '.stat-card',
                '.kpi-card',
                '.metric-card',
                '.dev-card',
                '.dev-cards-grid',
                '.dashboard-kpi-grid',
                '.summary-cards-row',
                '[data-live-table]',
                '[data-dynamic-refresh]'
            ];

            const existingElements = [];
            targetSelectors.forEach(sel => {
                const els = document.querySelectorAll(sel);
                els.forEach((el, idx) => {
                    if (!isContainerBeingEdited(el)) {
                        existingElements.push({ selector: sel, element: el, index: idx });
                    }
                });
            });

            if (existingElements.length === 0) {
                isSyncingData = false;
                return;
            }

            // 3. Fetch latest page HTML in background
            const ctrl = new AbortController();
            const tid = setTimeout(() => ctrl.abort(), 8000);
            const res = await fetch(window.location.href, {
                method: 'GET',
                signal: ctrl.signal,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Live-Refresh': '1', 'Accept': 'text/html' }
            });
            clearTimeout(tid);

            if (!res.ok) throw new Error('HTTP ' + res.status);
            const html = await res.text();

            // 4. Parse fresh DOM and swap only matching non-editing fragments
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            let anyTableUpdated = false;
            existingElements.forEach(({ selector, element, index }) => {
                if (isContainerBeingEdited(element)) return;

                let newElement = null;
                if (element.id) {
                    newElement = doc.getElementById(element.id);
                } else {
                    const freshEls = doc.querySelectorAll(selector);
                    if (freshEls && freshEls[index]) {
                        newElement = freshEls[index];
                    }
                }

                if (newElement && element.innerHTML !== newElement.innerHTML) {
                    element.innerHTML = newElement.innerHTML;
                    anyTableUpdated = true;
                }
            });

            // 5. Re-intercept any newly rendered refresh buttons
            interceptRefreshButtons();

            // 6. Re-apply active filters so auto-load never wipes out filter selections
            if (anyTableUpdated) {
                if (typeof window.filterTable === 'function') {
                    try { window.filterTable(); } catch(e) {}
                }
                if (typeof window.filterFuelTable === 'function') {
                    try { window.filterFuelTable(); } catch(e) {}
                }
                if (typeof window.filterServiceTable === 'function') {
                    try { window.filterServiceTable(); } catch(e) {}
                }
                if (typeof window.filterAdminMerchTable === 'function') {
                    try { window.filterAdminMerchTable(); } catch(e) {}
                }
                if (typeof window.filterAdminFuelTable === 'function') {
                    try { window.filterAdminFuelTable(); } catch(e) {}
                }
            }

        } catch (e) {
            // silent fail
        } finally {
            isSyncingData = false;
        }
    }

    // ── Intercept Manual "Refresh" Buttons to use Dynamic Refresh ──
    function interceptRefreshButtons() {
        document.querySelectorAll('[onclick*="location.reload"], [onclick*="window.location.reload"], .btn-refresh-live').forEach(btn => {
            if (btn.closest('form') || btn.dataset.lsIntercepted) return;
            btn.dataset.lsIntercepted = '1';
            btn.classList.add('live-sync-refresh-btn');

            btn.removeAttribute('onclick');
            btn.addEventListener('click', async function(e) {
                e.preventDefault();
                e.stopPropagation();

                const icon = btn.querySelector('i');
                if (icon) icon.classList.add('fa-spin');
                btn.style.opacity = '0.6';
                btn.style.pointerEvents = 'none';

                await Promise.all([
                    syncNotificationsAndBadges(),
                    refreshDynamicPageFragments()
                ]);

                if (icon) icon.classList.remove('fa-spin');
                btn.style.opacity = '1';
                btn.style.pointerEvents = 'auto';
            });
        });
    }

    // ── Dynamic Schedule Manager ──────────────────────────────────
    function scheduleIntervals() {
        if (notifTimer) clearInterval(notifTimer);
        if (dataTimer)  clearInterval(dataTimer);

        const intervalMs = getSyncIntervalMs();

        // 1. Fast background loop: Notifications, security alerts, badges, backup trigger
        notifTimer = setInterval(syncNotificationsAndBadges, intervalMs);

        // 2. Medium loop: Tables, KPIs, inventory, transactions, history
        dataTimer = setInterval(refreshDynamicPageFragments, Math.max(intervalMs, 8000));
    }

    // ── Start Engine & Schedule Periodic Timers ────────────────────
    function startEngine() {
        // Initial fast sync on page load
        syncNotificationsAndBadges();
        interceptRefreshButtons();

        // Schedule periodic sync timers using configured interval
        scheduleIntervals();

        // Visibility change: Sync immediately when user switches back to tab
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                syncNotificationsAndBadges();
                setTimeout(refreshDynamicPageFragments, 500);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startEngine);
    } else {
        startEngine();
    }

    // ── Expose Global API ──────────────────────────────────────────
    window.LiveSyncEngine = {
        version          : '3.1',
        triggerSync      : syncNotificationsAndBadges,
        refreshFragments : refreshDynamicPageFragments,
        isUserBusy       : isUserBusy,
        getLastSyncTime  : () => lastSyncTime,
        getBasePath      : () => basePath,
        getIntervalMs    : getSyncIntervalMs,
        updateInterval   : function(seconds) {
            const sec = Math.max(5, Math.min(300, parseInt(seconds, 10) || 10));
            window.PETRON_AUTO_REFRESH_SECONDS = sec;
            window.PETRON_AUTO_REFRESH_MS = sec * 1000;
            scheduleIntervals();
        }
    };

})(window, document);
