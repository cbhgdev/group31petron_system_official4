/**
 * Petron Station Management System
 * Global Draft & Autosave Engine — assets/js/global_draft_engine.js
 *
 * Fully automatic non-destructive auto-saving and auto-restoration across all data-entry forms.
 * Runs completely in the background without any visual banners, badges, or buttons.
 * Supports standard forms, dynamic tables, POS carts, Job Orders, and Product/Pricing modals.
 * Drafts do not trigger any database transactions, inventory deductions, or financial reports.
 */
(function(window, document) {
    'use strict';

    const BASE_PATH = (window.pageData && window.pageData.appBasePath)
        ? window.pageData.appBasePath.replace(/\/$/, '')
        : (window.location.pathname.includes('/public/') ? window.location.pathname.split('/public/')[0] : '');

    const DRAFTS_API = BASE_PATH + '/backend/api/drafts_api.php';
    const USER_ID = (window.pageData && window.pageData.userId) || (window.CURRENT_USER_ID) || 0;

    const PetronDraft = {
        timers: {},
        activeContainers: [],

        /**
         * Initialize draft autosave on a specific form or container element
         * @param {string} moduleKey - Unique module key
         * @param {string|HTMLElement} containerSelector - Form or container element
         * @param {Object} options - Custom options
         */
        init: function(moduleKey, containerSelector, options) {
            options = options || {};
            const container = typeof containerSelector === 'string' ? document.querySelector(containerSelector) : containerSelector;
            if (!container || container.getAttribute('data-petron-draft-module') === 'disabled' || container.hasAttribute('data-no-draft')) return;

            const pathname = window.location.pathname.split('/').pop().replace(/\.php$/, '');
            const isTxnPage = (pathname === 'staff_transactions_hub' || pathname === 'staff_transactions' || pathname === 'pos' || pathname === 'transactions' || window.location.pathname.includes('transactions_hub') || window.location.pathname.includes('staff_transactions') || window.location.pathname.includes('pos.php'));
            if (isTxnPage || (moduleKey && (moduleKey.includes('fuel') || moduleKey.includes('meter_readings') || moduleKey.startsWith('pos_merchandise')))) {
                return;
            }

            container.setAttribute('data-petron-draft-module', moduleKey);

            if (!this.activeContainers.some(item => item.container === container && item.moduleKey === moduleKey)) {
                this.activeContainers.push({ container: container, moduleKey: moduleKey, options: options });
            }

            // Clean up any residual banner elements from past versions
            const oldBanner = document.getElementById(`draftBanner_${moduleKey}`);
            if (oldBanner) oldBanner.remove();
            container.querySelectorAll('.petron-draft-status-badge').forEach(function(b) { b.remove(); });

            // Auto-purge any corrupted legacy draft strings from localStorage for this module
            try {
                const storageKey = `petron_draft_${USER_ID}_${moduleKey}`;
                const raw = localStorage.getItem(storageKey);
                if (raw && (raw.includes('Γé') || raw.includes('\u20b1') || moduleKey.includes('fuel_meter_readings'))) {
                    localStorage.removeItem(storageKey);
                }
            } catch (e) {}

            // 1. Check and automatically restore existing draft on load
            this.checkForDraft(moduleKey, container, options);

            // 2. Attach instant synchronous input listeners (0ms LocalStorage write)
            const self = this;
            const inputHandler = function(e) {
                if (e.target && (e.target.type === 'password' || e.target.type === 'submit')) return;
                self.saveToLocalStorage(moduleKey, container, options);
                self.scheduleServerSave(moduleKey, container, options);
            };

            container.addEventListener('input', inputHandler, { passive: true });
            container.addEventListener('change', inputHandler, { passive: true });

            // 3. Save immediately on field blur or focusout
            container.addEventListener('focusout', function(e) {
                if (e.target && (e.target.type === 'password' || e.target.type === 'submit')) return;
                self.saveNow(moduleKey, container, options, false);
            }, { passive: true });

            // 4. Clear draft when form is submitted
            if (container.tagName && container.tagName.toLowerCase() === 'form') {
                container.addEventListener('submit', function() {
                    setTimeout(function() { self.clear(moduleKey); }, 600);
                });
            }

            // Hook reset/cancel/close buttons inside container
            container.querySelectorAll('button[type="reset"], .fet-reset-btn, .btn-discard, .btn-cancel, #clearCartBtn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (btn.classList.contains('btn-discard')) {
                        setTimeout(function() { self.clear(moduleKey); }, 100);
                    }
                });
            });
        },

        /**
         * Collect all input data from a form or container
         */
        collectFormData: function(container, options) {
            if (typeof options.customCollector === 'function') {
                return options.customCollector(container);
            }

            const data = {};
            let hasMeaningfulContent = false;

            // Collect all inputs within container AND any external inputs referencing forms inside container
            let elements = Array.from(container.querySelectorAll('input, select, textarea'));

            if (container.tagName && container.tagName.toLowerCase() === 'form' && container.id) {
                const outsideElements = Array.from(document.querySelectorAll(`[form="${container.id}"]`));
                elements = Array.from(new Set([...elements, ...outsideElements]));
            }

            elements.forEach(function(el) {
                const name = el.name || el.id;
                if (!name || el.type === 'password' || el.type === 'submit' || el.type === 'button') return;

                // Never collect calculated readonly fields (amount, volume, summary)
                if (el.id && (el.id.startsWith('amount_') || el.id.startsWith('volume_') || el.id.startsWith('summary_total_'))) return;
                if (el.readOnly && !el.dataset.allowDraft) return;

                // Use ID if available to ensure unique keying across table rows or complex layouts
                const key = el.id ? el.id : name;

                if (el.type === 'checkbox') {
                    data[key] = el.checked;
                } else if (el.type === 'radio') {
                    if (el.checked) data[key] = el.value;
                } else {
                    const val = el.value !== undefined ? String(el.value).trim() : '';
                    data[key] = val;

                    // Meaningful content check: not empty and not just default zero
                    if (val !== '' && val !== '0' && val !== '0.00' && !el.readOnly && !el.disabled) {
                        hasMeaningfulContent = true;
                    }
                }
            });

            // POS Cart should NEVER be persisted across reloads for transaction module
            const isTxnPage = window.location.pathname.includes('transactions_hub') || window.location.pathname.includes('staff_transactions') || window.location.pathname.includes('pos.php');
            if (!isTxnPage) {
                // 1. Check for POS Cart array (Job Order & Merchandise)
                const currentCart = (typeof window.getPetronCart === 'function') ? window.getPetronCart() : (window.cart || window.activeCart);
                if (Array.isArray(currentCart) && currentCart.length > 0) {
                    data._cart = currentCart;
                    hasMeaningfulContent = true;
                }
            }

            // 2. Custom job order items
            if (window.jobOrderItems && Array.isArray(window.jobOrderItems) && window.jobOrderItems.length > 0) {
                data._jobOrderItems = window.jobOrderItems;
                hasMeaningfulContent = true;
            }

            return hasMeaningfulContent ? data : null;
        },

        /**
         * Automatically restore form data from draft object
         */
        restoreFormData: function(container, data, options) {
            if (typeof options.customRestorer === 'function') {
                options.customRestorer(container, data);
                return;
            }

            if (!data || typeof data !== 'object') return;

            Object.keys(data).forEach(function(key) {
                if (key.startsWith('_')) return; // Custom arrays or metadata

                // NEVER restore calculated readonly fields!
                if (key.startsWith('amount_') || key.startsWith('volume_') || key.startsWith('summary_total_')) return;

                let el = document.getElementById(key);
                if (!el && container.querySelector) {
                    el = container.querySelector(`[name="${key}"], #${key}`);
                }
                if (!el) return;

                // Never overwrite readonly inputs with draft data
                if (el.readOnly && !el.dataset.allowDraft) return;

                const val = data[key];
                if (val === undefined || val === null) return;
                // Reject any corrupted strings
                if (typeof val === 'string' && (val.includes('Γé') || val.includes('\u20b1'))) return;

                if (el.type === 'checkbox') {
                    el.checked = !!val;
                } else if (el.type === 'radio') {
                    const radio = document.querySelector(`[name="${el.name}"][value="${val}"]`);
                    if (radio) radio.checked = true;
                } else {
                    el.value = val;
                }

                // Dispatch events so calculations/reactive listeners recalculate.
                // Each event is individually guarded: inline onchange handlers
                // referencing undefined functions (from other pages) must not throw.
                try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
                try { el.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
                try { el.dispatchEvent(new Event('blur',  { bubbles: true })); } catch (e) {}

                // Meter reading calculation trigger
                if (key.startsWith('ending_') || key.startsWith('beginning_') || key.startsWith('cal_')) {
                    const ft_id = key.replace(/^(ending_|beginning_|cal_)/, '');
                    if (typeof window.updateFuelCalc === 'function') {
                        try { window.updateFuelCalc(ft_id); } catch (err) {}
                    }
                }
            });

            // If custom category was restored, show custom input wrapper if applicable
            if (data.addSvcCategory === 'Custom Services' || data.editSvcCategory === 'Custom Services') {
                const wrap = document.getElementById('addSvcCustomWrap') || document.getElementById('editSvcCustomWrap');
                if (wrap) wrap.style.display = 'block';
            }
            if (data.newMerchCategory === 'Custom Category' || data.editMerchCategory === 'Custom Category') {
                const wrap = document.getElementById('newMerchCustomWrap') || document.getElementById('editMerchCustomWrap');
                if (wrap) wrap.style.display = 'block';
            }

            // 1. Restore POS Cart array (Job Order & Merchandise) - disabled on transaction module
            const isTxnPage = window.location.pathname.includes('transactions_hub') || window.location.pathname.includes('staff_transactions') || window.location.pathname.includes('pos.php');
            if (!isTxnPage && data._cart && Array.isArray(data._cart) && data._cart.length > 0) {
                if (typeof window.setPetronCart === 'function') {
                    window.setPetronCart(data._cart);
                } else {
                    window.cart = data._cart;
                    if (typeof window.renderCart === 'function') window.renderCart();
                    if (typeof window.updateCheckoutBtn === 'function') window.updateCheckoutBtn();
                }
            }

            // 2. Restore custom cart arrays if present
            if (data._activeCart && Array.isArray(data._activeCart) && typeof window.renderCartTable === 'function') {
                window.activeCart = data._activeCart;
                window.renderCartTable();
            }
            if (data._jobOrderItems && Array.isArray(data._jobOrderItems) && typeof window.renderJobOrderItems === 'function') {
                window.jobOrderItems = data._jobOrderItems;
                window.renderJobOrderItems();
            }

            // Global re-calculation triggers if defined on the page
            if (typeof window.recalcAllFuelRows === 'function') {
                try { window.recalcAllFuelRows(); } catch (err) {}
            }
            if (typeof window.onPaymentChange === 'function') {
                try { window.onPaymentChange(); } catch (err) {}
            }
            if (typeof window.onLoyaltyChange === 'function') {
                try { window.onLoyaltyChange(); } catch (err) {}
            }
        },

        /**
         * Instant synchronous save to LocalStorage (0ms latency)
         */
        saveToLocalStorage: function(moduleKey, container, options) {
            const pathname = window.location.pathname.split('/').pop().replace(/\.php$/, '');
            const isTxnPage = (pathname === 'staff_transactions_hub' || pathname === 'staff_transactions' || pathname === 'pos' || pathname === 'transactions' || window.location.pathname.includes('transactions_hub') || window.location.pathname.includes('staff_transactions') || window.location.pathname.includes('pos.php'));
            if (isTxnPage || (moduleKey && (moduleKey.includes('fuel') || moduleKey.includes('meter_readings')))) return;

            const data = this.collectFormData(container, options);
            const storageKey = `petron_draft_${USER_ID}_${moduleKey}`;

            if (data) {
                const draftPayload = {
                    module: moduleKey,
                    data: data,
                    timestamp: Date.now(),
                    formatted_time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                };
                try {
                    localStorage.setItem(storageKey, JSON.stringify(draftPayload));
                } catch (e) {}
            }
        },

        /**
         * Schedule debounced server save (1.0s)
         */
        scheduleServerSave: function(moduleKey, container, options) {
            const self = this;
            if (this.timers[moduleKey]) clearTimeout(this.timers[moduleKey]);

            this.timers[moduleKey] = setTimeout(function() {
                self.saveNow(moduleKey, container, options, false);
            }, 1000);
        },

        /**
         * Save draft immediately to LocalStorage and Server API
         */
        saveNow: function(moduleKey, container, options, isSync) {
            const pathname = window.location.pathname.split('/').pop().replace(/\.php$/, '');
            const isTxnPage = (pathname === 'staff_transactions_hub' || pathname === 'staff_transactions' || pathname === 'pos' || pathname === 'transactions' || window.location.pathname.includes('transactions_hub') || window.location.pathname.includes('staff_transactions') || window.location.pathname.includes('pos.php'));
            if (isTxnPage || (moduleKey && (moduleKey.includes('fuel') || moduleKey.includes('meter_readings')))) return;

            const data = this.collectFormData(container, options);
            const storageKey = `petron_draft_${USER_ID}_${moduleKey}`;

            if (!data) return;

            const draftPayload = {
                module: moduleKey,
                data: data,
                timestamp: Date.now(),
                formatted_time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            };

            // 1. Client-side instant save
            try {
                localStorage.setItem(storageKey, JSON.stringify(draftPayload));
            } catch (e) {}

            // 2. Server-side async save
            if (navigator.onLine) {
                const url = DRAFTS_API + '?action=save';
                const body = JSON.stringify({ module: moduleKey, form_data: data });

                if (isSync && navigator.sendBeacon) {
                    try {
                        const blob = new Blob([body], { type: 'application/json' });
                        navigator.sendBeacon(url, blob);
                    } catch (e) {}
                } else {
                    fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: body,
                        credentials: 'same-origin'
                    }).catch(function() {});
                }
            }
        },

        /**
         * Flush all active draft modules on the page immediately
         */
        flushAll: function() {
            const isTxnPage = window.location.pathname.includes('transactions_hub') || window.location.pathname.includes('staff_transactions') || window.location.pathname.includes('pos.php');
            if (isTxnPage) return;
            const self = this;
            this.activeContainers.forEach(function(item) {
                self.saveNow(item.moduleKey, item.container, item.options, true);
            });
        },

        /**
         * Check for existing draft on page load and automatically restore it silently
         */
        checkForDraft: function(moduleKey, container, options) {
            // Completely disable draft checking on live transaction/encoding screens
            const pathname = window.location.pathname.split('/').pop().replace(/\.php$/, '');
            const isTxnPage = (pathname === 'staff_transactions_hub' || pathname === 'staff_transactions' || pathname === 'pos' || pathname === 'transactions' || window.location.pathname.includes('transactions_hub') || window.location.pathname.includes('staff_transactions') || window.location.pathname.includes('pos.php'));
            const isTxnModule = !moduleKey || moduleKey.includes('fuel') || moduleKey.includes('meter_readings') || moduleKey.startsWith('pos_merchandise') || moduleKey.includes('job_order') || moduleKey.includes('merchandise_transaction');
            if (isTxnPage || isTxnModule) {
                return;
            }

            const self = this;
            const storageKey = `petron_draft_${USER_ID}_${moduleKey}`;

            // 1. Instant recovery from LocalStorage
            let localDraft = null;
            try {
                const stored = localStorage.getItem(storageKey);
                if (stored) localDraft = JSON.parse(stored);
            } catch (e) {}

            if (localDraft && localDraft.data) {
                self.restoreFormData(container, localDraft.data, options);
            }

            // 2. Fallback check for alternate keys (e.g. without section suffix)
            if (!localDraft && moduleKey.includes('_')) {
                const baseKey = moduleKey.replace(/_[^_]+$/, '');
                const altStorageKey = `petron_draft_${USER_ID}_${baseKey}`;
                try {
                    const altStored = localStorage.getItem(altStorageKey);
                    if (altStored) {
                        const altDraft = JSON.parse(altStored);
                        if (altDraft && altDraft.data) {
                            self.restoreFormData(container, altDraft.data, options);
                        }
                    }
                } catch (e) {}
            }

            // 3. Cross-device / after-logout recovery from Server DB
            fetch(DRAFTS_API + '?action=get&module=' + encodeURIComponent(moduleKey), {
                credentials: 'same-origin'
            })
            .then(function(res) { return res.json(); })
            .then(function(resData) {
                if (resData && resData.ok && resData.has_draft && resData.draft && resData.draft.data) {
                    self.restoreFormData(container, resData.draft.data, options);
                }
            })
            .catch(function() {});
        },

        /**
         * Clear draft on client and server
         */
        clear: function(moduleKey) {
            const storageKey = `petron_draft_${USER_ID}_${moduleKey}`;
            try { localStorage.removeItem(storageKey); } catch (e) {}

            fetch(DRAFTS_API + '?action=clear', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ module: moduleKey }),
                credentials: 'same-origin'
            }).catch(function() {});

            const banner = document.getElementById(`draftBanner_${moduleKey}`);
            if (banner) banner.remove();
        },

        /**
         * Auto-scan and initialize all data-entry forms, interactive tables, and modals
         */
        autoScan: function() {
            const self = this;
            const pathname = window.location.pathname.split('/').pop().replace(/\.php$/, '');
            const searchParams = new URLSearchParams(window.location.search);
            const section = searchParams.get('section') || searchParams.get('tab') || searchParams.get('active_tab') || '';
            const isTxnPage = (pathname === 'staff_transactions_hub' || pathname === 'staff_transactions' || pathname === 'pos' || pathname === 'transactions' || window.location.pathname.includes('transactions_hub') || window.location.pathname.includes('staff_transactions') || window.location.pathname.includes('pos.php'));

            // Clean up any residual banner elements
            document.querySelectorAll('[id^="draftBanner_"], .petron-draft-status-badge').forEach(function(b) { b.remove(); });

            // On live transaction and encoding screens, draft engine is completely disabled to protect live entries
            if (isTxnPage) {
                return;
            }

            // 1. Explicit data-draft-module containers (skip on txn page)
            if (!isTxnPage) {
                document.querySelectorAll('[data-draft-module]').forEach(function(el) {
                    const mod = el.getAttribute('data-draft-module');
                    if (mod) self.init(mod, el);
                });
            }

            // 2. Transactions Hub: Job Order & Merchandise / POS encoding section (Skipped on transaction pages to guarantee clean empty cart)
            if (!isTxnPage) {
                const merchJoSection = document.querySelector('#merchandiseSection, #joCard, #cartCard, .txn-grid');
                if (merchJoSection && !merchJoSection.hasAttribute('data-petron-draft-module')) {
                    const merchModuleKey = 'pos_merchandise_joborder' + (section ? '_' + section : '');
                    self.init(merchModuleKey, merchJoSection.closest('.txn-container') || document.body);
                }
            }

            // 3. Fuel Meter Readings encoding grid / table (disabled on transaction pages to protect live encoding)
            if (!isTxnPage) {
                const meterTable = document.querySelector('#encodeCard, #fuelReadingTable, table.fuel-encode-table, #fuelHistoryTable');
                if (meterTable && !meterTable.hasAttribute('data-petron-draft-module') && !meterTable.hasAttribute('data-no-draft') && meterTable.getAttribute('data-petron-draft-module') !== 'disabled') {
                    const meterModuleKey = 'fuel_meter_readings' + (section ? '_' + section : '');
                    self.init(meterModuleKey, meterTable);
                }
            }

            // 4. Product & Pricing Management Form & Modal Mappings (Fuel, Merchandise, Services)
            const pricingProductForms = {
                // Product & Pricing Management (Fuel, Merchandise, Services)
                'addProductForm': 'add_fuel_product_modal',
                'addMerchandiseForm': 'add_merchandise_product_modal',
                'addServiceForm': 'add_service_modal',
                'editPriceForm': 'edit_fuel_price_modal',
                'editPriceFormAdmin': 'edit_fuel_price_admin_modal',
                'editMerchandiseForm': 'edit_merchandise_modal',
                'editServiceForm': 'edit_service_modal',

                // Standard known form IDs across all user roles
                'merchandiseForm': 'merchandise_transaction',
                'jobOrderForm': 'job_order',
                'addUserForm': 'user_creation_form',
                'stockRequestForm': 'stock_request_form',
                'fuelReadingForm': 'fuel_reading',
                'fuelClosingForm': 'fuel_sales_closing',
                'deliveryForm': 'delivery_receipt',
                'poForm': 'purchase_order',
                'adjustmentForm': 'transaction_adjustment',
                'voidForm': 'void_request',
                'adminAddUserForm': 'admin_user_creation',
                'editUserForm': 'edit_user_form',
                'adminEditProdModal': 'admin_edit_product_form'
            };

            Object.keys(pricingProductForms).forEach(function(formId) {
                const formEl = document.getElementById(formId);
                if (formEl && !formEl.hasAttribute('data-petron-draft-module')) {
                    self.init(pricingProductForms[formId], formEl);
                }
            });

            // 5. Universal scan: Every data-entry form across all pages and roles
            document.querySelectorAll('form').forEach(function(f, idx) {
                if (f.hasAttribute('data-petron-draft-module')) return;
                if (f.method && f.method.toUpperCase() === 'GET') return; // skip all GET forms (search/filter)
                if (f.id === 'loginForm' || f.id === 'logoutForm') return;
                // Skip forms that are purely filter/search (no meaningful data-entry inputs)
                if (f.classList.contains('filters') || f.classList.contains('search-form') ||
                    f.getAttribute('role') === 'search') return;

                const formId = f.id;
                const insideInputs = Array.from(f.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea'));
                const outsideInputs = formId ? Array.from(document.querySelectorAll(`input[form="${formId}"]:not([type="hidden"]):not([type="submit"]), select[form="${formId}"], textarea[form="${formId}"]`)) : [];
                const allInputs = [...insideInputs, ...outsideInputs];

                // Skip forms whose elements have inline event handlers (onchange/oninput attributes).
                // These are page-specific and may reference functions not defined on the current page,
                // causing ReferenceErrors when the draft engine fires change events during restore.
                const hasInlineHandlers = allInputs.some(function(el) {
                    return el.hasAttribute('onchange') || el.hasAttribute('oninput') || el.hasAttribute('onkeyup');
                });
                if (hasInlineHandlers) return;

                if (allInputs.length >= 1) {
                    const autoKey = 'form_' + pathname + (section ? '_' + section : '') + (formId ? '_' + formId : '_' + idx);
                    self.init(autoKey, f);
                }
            });
        }
    };

    window.PetronDraft = PetronDraft;

    // Flush all drafts immediately whenever user clicks ANY link or sidebar item
    document.addEventListener('click', function(e) {
        const navTarget = e.target.closest('a[href], .sidebar a, .nav-link, button[onclick*="location"], [data-nav]');
        if (navTarget) {
            PetronDraft.flushAll();
        }
    }, true);

    // Save on beforeunload & pagehide
    window.addEventListener('beforeunload', function() {
        PetronDraft.flushAll();
    });
    window.addEventListener('pagehide', function() {
        PetronDraft.flushAll();
    });

    // Run on DOMContentLoaded and on window load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            PetronDraft.autoScan();
        });
    } else {
        PetronDraft.autoScan();
    }
    window.addEventListener('load', function() {
        PetronDraft.autoScan();
    });

})(window, document);
