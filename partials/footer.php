
  </main>

  <style>
    /* ── Footer: strip ALL underlines (Bootstrap 5 sets a { text-decoration: underline } globally) ── */
    .fixed-footer,
    .fixed-footer *,
    .fixed-footer a,
    .fixed-footer a:hover,
    .fixed-footer a:visited,
    .fixed-footer a:focus,
    .fixed-footer span,
    .fixed-footer .footer-text,
    .fixed-footer .footer-clock,
    .fixed-footer .footer-identity,
    .fixed-footer .footer-identity-text {
        text-decoration: none !important;
    }

    .fixed-footer {
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 40px !important;
        background-color: #ffffff !important;
        border-top: 1px solid #e0e0e0 !important;
        z-index: 990 !important;
        display: flex !important;
        align-items: center !important;
        font-size: 0.85em !important;
        color: #666666 !important;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1) !important;
    }
    
    /* Footer always attached to sidebar - full width */
    @media (max-width: 991px) {
        .fixed-footer {
            left: 0 !important;
            width: 100% !important;
        }
    }
    
    /* Ensure footer is always visible */
    .fixed-footer * {
        pointer-events: auto !important;
    }
    
    .footer-sidebar-area {
        width: 250px !important;
        height: 100% !important;
        background-color: #ffffff !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        color: #666666 !important;
        font-size: 0.85em !important;
        font-weight: 500 !important;
        transition: width 0.3s ease !important;
        overflow: hidden !important;
        padding: 0 10px !important;
        box-sizing: border-box !important;
    }

    .footer-identity {
        display: flex !important;
        align-items: center !important;
        font-size: 11px !important;
        font-weight: 700 !important;
        color: var(--petron-blue, #00264D) !important;
        letter-spacing: 0.4px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        max-width: 100% !important;
    }

    .footer-identity-text {
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    /* Hide identity text when sidebar is collapsed, show only icon */
    body.sidebar-collapsed .footer-identity-text {
        display: none !important;
    }

    body.sidebar-collapsed .footer-identity i {
        margin-right: 0 !important;
    }
    
    .footer-content {
        flex: 1 !important;
        display: flex !important;
        align-items: center !important;
        padding: 0 20px !important;
        height: 100% !important;
        margin-left: 0 !important;
    }
    
    .footer-left {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        flex-shrink: 0 !important;
    }
    
    .footer-center {
        flex-grow: 1 !important;
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
    }
    
    .footer-right {
        display: flex !important;
        align-items: center !important;
        gap: 20px !important;
        flex-shrink: 0 !important;
    }
    
    /* Sidebar collapsed state */
    body.sidebar-collapsed .footer-sidebar-area {
        width: 70px !important;
    }
    
    .footer-text {
        font-size: 0.85em !important;
        color: #666666 !important;
        font-weight: 500 !important;
    }
    
    .footer-clock {
        font-size: 0.85em !important;
        color: #666666 !important;
        font-weight: 500 !important;
        white-space: nowrap !important;
    }
    
    .footer-clock i {
        margin-right: 5px !important;
        color: var(--petron-blue) !important;
    }
    
    /* Footer Toggle Button Styling */
    .footer-toggle {
        background: var(--petron-blue) !important;
        border: none !important;
        color: white !important;
        font-size: 14px !important;
        cursor: pointer !important;
        padding: 8px 12px !important;
        border-radius: 6px !important;
        transition: all 0.3s ease !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin-right: 15px !important;
        min-width: 36px !important;
        height: 36px !important;
    }

    .footer-toggle:hover {
        background: #0040a0 !important;
        transform: translateY(-1px) !important;
        box-shadow: 0 2px 8px rgba(0, 47, 112, 0.3) !important;
    }

    .footer-toggle:active {
        transform: translateY(0) !important;
    }
    
    .footer-toggle i {
        font-size: 14px !important;
        margin: 0 !important;
    }
    
    /* Override any conflicting styles */
    body {
        padding-bottom: 40px !important; /* Account for fixed footer */
    }
    
    main {
        padding-bottom: 110px !important; /* fixed footer (40px) + scroll btn (50px from bottom, 40px tall) = 90px, +20px buffer */
    }
    
    /* Toggle Scroll Button Styling - ABSOLUTE MAXIMUM PRIORITY */
    .toggle-scroll-btn,
    #toggleScrollBtn {
        position: fixed !important;
        bottom: 50px !important; /* sits just above the 40px footer */
        right: 20px !important;
        width: 40px !important;
        height: 40px !important;
        background: var(--petron-blue, #002F6C) !important;
        border: 2px solid #ffffff !important;
        border-radius: 50% !important;
        color: white !important;
        font-size: 14px !important;
        cursor: pointer !important;
        z-index: 2147483647 !important; /* ABSOLUTE MAXIMUM z-index */
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        box-shadow: 0 4px 14px rgba(0, 47, 112, 0.35), 0 2px 4px rgba(0,0,0,0.12) !important;
        /* Always visible and clickable */
        opacity: 1 !important;
        transform: scale(1) translateY(0) !important;
        pointer-events: auto !important;
        transition: opacity 0.25s ease, transform 0.25s ease, background 0.2s ease, box-shadow 0.2s ease !important;
        visibility: visible !important;
        isolation: isolate !important;
    }

    .toggle-scroll-btn.visible,
    #toggleScrollBtn.visible {
        opacity: 1 !important;
        transform: scale(1) translateY(0) !important;
        pointer-events: auto !important;
        z-index: 2147483647 !important;
        visibility: visible !important;
    }

    .toggle-scroll-btn:hover,
    #toggleScrollBtn:hover {
        background: #0040a0 !important;
        box-shadow: 0 6px 18px rgba(0, 47, 112, 0.45), 0 3px 6px rgba(0,0,0,0.15) !important;
        transform: scale(1.08) translateY(-1px) !important;
        pointer-events: auto !important;
        z-index: 2147483647 !important;
    }

    .toggle-scroll-btn:active,
    #toggleScrollBtn:active {
        transform: scale(0.94) translateY(0) !important;
        box-shadow: 0 2px 6px rgba(0, 47, 112, 0.25) !important;
        pointer-events: auto !important;
        z-index: 2147483647 !important;
    }

    /* Icon must not block clicks */
    .toggle-scroll-btn i,
    #toggleScrollBtn i {
        pointer-events: none !important;
        display: block !important;
        line-height: 1 !important;
    }

    /* Red highlight while the page is scrolling */
    .toggle-scroll-btn.scrolling,
    #toggleScrollBtn.scrolling {
        background: var(--petron-red, #E30613) !important;
        border-color: #ffffff !important;
        box-shadow: 0 4px 16px rgba(227, 6, 19, 0.5), 0 2px 6px rgba(0,0,0,0.15) !important;
        transform: scale(1.12) translateY(-1px) !important;
        pointer-events: auto !important;
        z-index: 2147483647 !important;
    }

    /* Arrow icon */
    .toggle-scroll-btn i,
    #toggleScrollBtn i {
        display: block !important;
        line-height: 1 !important;
        transition: none !important;
        pointer-events: none !important;
    }

    .toggle-scroll-btn.arrow-up i {
        /* intentionally empty — icon swap handled in JS */
    }

    /* Mobile */
    @media (max-width: 768px) {
        .toggle-scroll-btn,
        #toggleScrollBtn {
            width: 36px !important;
            height: 36px !important;
            font-size: 13px !important;
            bottom: 50px !important;
            right: 12px !important;
            pointer-events: auto !important;
            z-index: 2147483647 !important;
        }
    }

    /* ══ GLOBAL PRINT — hide all system chrome ══════════════════════ */
    @media print {
        .fixed-footer,
        .footer-sidebar-area,
        .footer-content,
        .toggle-scroll-btn,
        #toggleScrollBtn,
        .toast {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            overflow: hidden !important;
        }
        body {
            padding-bottom: 0 !important;
        }
        main {
            padding-bottom: 0 !important;
        }
        body.report-printing > *:not(#report-print-root) {
            display: none !important;
        }
        body.report-printing #report-print-root {
            display: block !important;
            visibility: visible !important;
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #ffffff !important;
            color: #000000 !important;
        }
        body.report-printing #report-print-root * {
            visibility: visible !important;
        }
        body.report-printing #report-print-root table {
            width: 100% !important;
            border-collapse: collapse !important;
        }
        body.report-printing #report-print-root thead {
            display: table-header-group !important;
        }
        body.report-printing #report-print-root tfoot {
            display: table-footer-group !important;
        }
        body.report-printing #report-print-root tr {
            break-inside: avoid !important;
            page-break-inside: avoid !important;
        }
        body.report-printing #report-print-root .no-print,
        body.report-printing #report-print-root .rpt-filter-bar,
        body.report-printing #report-print-root .fr-filter-bar,
        body.report-printing #report-print-root .cr-filter-bar,
        body.report-printing #report-print-root .pr-filter-bar,
        body.report-printing #report-print-root .mp-filter-bar,
        body.report-printing #report-print-root .export-actions,
        body.report-printing #report-print-root .rpt-export-actions,
        body.report-printing #report-print-root .pr-export-actions,
        body.report-printing #report-print-root .mp-export-actions,
        body.report-printing #report-print-root .actions,
        body.report-printing #report-print-root .modal,
        body.report-printing #report-print-root .modal-overlay,
        body.report-printing #report-print-root [class*="btn"],
        body.report-printing #report-print-root [class*="actions"] {
            display: none !important;
        }
    }

    /* Clean Rows per page select dropdown */
    .rows-select {
        background-color: transparent !important;
        background: transparent !important;
        color: #333333 !important;
        border: 1px solid #cbd5e1 !important;
        outline: none !important;
        box-shadow: none !important;
    }

    /* Clean pagination buttons — prevent global button theme color from bleeding in */
    .pag-btn {
        background: #ffffff !important;
        background-color: #ffffff !important;
        color: #374151 !important;
        border: 1px solid #d1d5db !important;
        box-shadow: none !important;
        font-weight: 400 !important;
    }
    .pag-btn:hover:not(:disabled) {
        background: #f1f5f9 !important;
        background-color: #f1f5f9 !important;
        border-color: #94a3b8 !important;
        color: #1e293b !important;
    }
    .pag-btn:disabled {
        opacity: 0.45 !important;
        cursor: not-allowed !important;
    }

    .flt-btn-print,
    .exp-btn-print,
    .btn-act-print {
        color: #002F70 !important;
        border-color: #002F70 !important;
        background: #ffffff !important;
    }

    .flt-btn-print:hover,
    .exp-btn-print:hover,
    .btn-act-print:hover {
        background: #002F70 !important;
        color: #ffffff !important;
    }
</style>

  <!-- TOGGLE SCROLL BUTTON — injected into body by JS to avoid fixed-in-overflow-container bug -->
  <script>
  (function() {
    // Remove any button that may have been rendered inside <main>
    var existing = document.getElementById('toggleScrollBtn');
    if (existing) existing.remove();

    // Create fresh button as a direct child of <body>
    var btn = document.createElement('button');
    btn.id = 'toggleScrollBtn';
    btn.className = 'toggle-scroll-btn visible';
    btn.setAttribute('aria-label', 'Scroll to bottom');
    btn.setAttribute('title', 'Scroll to bottom');
    btn.setAttribute('type', 'button');
    // FORCE CLICKABILITY WITH INLINE STYLES - ABSOLUTE MAXIMUM PRIORITY
    btn.style.cssText = 'pointer-events: auto !important; cursor: pointer !important; z-index: 2147483647 !important; position: fixed !important; opacity: 1 !important; visibility: visible !important; display: flex !important;';
    btn.innerHTML = '<i class="fas fa-arrow-down" style="pointer-events: none !important; display: block !important;"></i>';
    document.body.appendChild(btn);
    
  })();
  </script>

  <!-- FIXED FOOTER -->
  <footer class="fixed-footer">
    <div class="footer-sidebar-area" id="footerSidebarArea">
    </div>
    <div class="footer-content">
      <div class="footer-left">
        <!-- Left content can be added here if needed -->
      </div>
      <div class="footer-center">
        <span class="footer-text">© 2026 Petron Management System. All rights reserved.</span>
      </div>
      <div class="footer-right">
        <span id="footer-clock" class="footer-clock"></span>
      </div>
    </div>
    
    <script>
        // Footer is now attached to sidebar - no positioning logic needed
        // Footer automatically adjusts with sidebar CSS transitions
    </script>
  </footer>

  <!-- Bootstrap JavaScript -->
  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  
  <script src="../assets/js/app.js"></script>
</main>

  <script>
    function updateFooterClock() {
        const footerClock = document.getElementById('footer-clock');
        if (!footerClock) return;
        const now = new Date();
        if (typeof window.formatPetronDateTime === 'function') {
            footerClock.innerHTML = '<i class="far fa-clock"></i> ' + window.formatPetronDateTime(now, true, true);
        } else {
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            footerClock.innerHTML = '<i class="far fa-clock"></i> ' + now.toLocaleDateString('en-US', options);
        }
    }
    setInterval(updateFooterClock, 1000);
    updateFooterClock();
    
    // Toggle Scroll Button — targets .main (the real scrollable container on desktop)
    (function () {
        'use strict';

        var btn = document.getElementById('toggleScrollBtn');
        if (!btn) return;

        // The scrollable container is <main class="main"> on desktop (body overflow:hidden).
        // On mobile the page itself scrolls, so we fall back to document.documentElement.
        function getScroller() {
            var main = document.querySelector('main.main');
            if (main && main.scrollHeight > main.clientHeight) return main;
            // fallback: window / documentElement
            return null;
        }

        var isVisible  = false;
        var isAtBottom = false;

        function checkScrollNeeded(scroller) {
            if (scroller) {
                return scroller.scrollHeight > scroller.clientHeight + 4;
            }
            return document.documentElement.scrollHeight > window.innerHeight + 4;
        }

        function getScrollTop(scroller) {
            return scroller ? scroller.scrollTop
                            : (window.pageYOffset || document.documentElement.scrollTop);
        }

        function getScrollMax(scroller) {
            if (scroller) return scroller.scrollHeight - scroller.clientHeight;
            return document.documentElement.scrollHeight - window.innerHeight;
        }

        function update() {
            var scroller   = getScroller();
            var needed     = checkScrollNeeded(scroller);
            var scrollTop  = getScrollTop(scroller);
            var scrollMax  = getScrollMax(scroller);

            // Hide entirely when content fits on screen
            if (!needed) {
                if (isVisible) { btn.classList.remove('visible'); isVisible = false; }
                return;
            }

            // At bottom = within 6px of max scroll
            isAtBottom = scrollTop >= scrollMax - 6;

            // Show button whenever scroll is possible
            if (!isVisible) { btn.classList.add('visible'); isVisible = true; }

            var icon = btn.querySelector('i');

            if (isAtBottom) {
                // User is at the BOTTOM → arrow points UP → click will scroll to top
                btn.classList.add('arrow-up');
                btn.setAttribute('aria-label', 'Scroll to top');
                btn.setAttribute('title', 'Scroll to top');
                if (icon) { icon.className = 'fas fa-arrow-up'; icon.style.transform = ''; }
            } else {
                // User is at the TOP or middle → arrow points DOWN → click will scroll to bottom
                btn.classList.remove('arrow-up');
                btn.setAttribute('aria-label', 'Scroll to bottom');
                btn.setAttribute('title', 'Scroll to bottom');
                if (icon) { icon.className = 'fas fa-arrow-down'; icon.style.transform = ''; }
            }
        }

        // Red highlight while scrolling is in progress
        var scrollingTimer = null;
        function markScrolling() {
            btn.classList.add('scrolling');
            clearTimeout(scrollingTimer);
            scrollingTimer = setTimeout(function () {
                btn.classList.remove('scrolling');
            }, 600); // stays red for 600ms after scroll stops
        }

        function doScroll() {
            var scroller = getScroller();
            if (isAtBottom) {
                if (scroller) scroller.scrollTo({ top: 0, behavior: 'smooth' });
                else window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                if (scroller) scroller.scrollTo({ top: scroller.scrollHeight, behavior: 'smooth' });
                else window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
            }
            // Flip icon immediately after click so it feels responsive
            setTimeout(update, 400);
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            doScroll();
        }, false);
        
        // FORCE button to stay clickable - ABSOLUTE MAXIMUM PRIORITY (except when printing)
        setInterval(function() {
            if (window.matchMedia && window.matchMedia('print').matches) {
                if (btn) btn.style.setProperty('display', 'none', 'important');
                return;
            }
            if (btn.style.pointerEvents !== 'auto' || btn.style.zIndex !== '2147483647') {
                btn.style.cssText = 'pointer-events: auto !important; cursor: pointer !important; z-index: 2147483647 !important; position: fixed !important; opacity: 1 !important; visibility: visible !important; display: flex !important;';
            }
        }, 500); // Check every 500ms

        window.addEventListener('beforeprint', function() {
            if (btn) btn.style.setProperty('display', 'none', 'important');
        });
        window.addEventListener('afterprint', function() {
            if (btn && (!window.matchMedia || !window.matchMedia('print').matches)) {
                btn.style.setProperty('display', 'flex', 'important');
            }
        });

        // Attach scroll listener to the right target
        function attachScrollListener() {
            var scroller = document.querySelector('main.main');
            if (scroller) {
                scroller.addEventListener('scroll', function () {
                    markScrolling();
                    update();
                }, { passive: true });
            }
            // Also listen on window for mobile fallback
            window.addEventListener('scroll', function () {
                markScrolling();
                update();
            }, { passive: true });
        }

        // Re-check on resize (content height may change)
        window.addEventListener('resize', function () { setTimeout(update, 80); }, { passive: true });

        // Init
        attachScrollListener();
        // Run after DOM + any dynamic content settles
        setTimeout(update, 150);
        setTimeout(update, 600);
    })();

    // ══ Universal Export & Pagination Helpers ══
    function exportTableToCSV(tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) return;
        var csv = [];
        var headers = [];
        var ths = table.querySelectorAll('thead th');
        ths.forEach(function(th) {
            if (th.textContent.trim().toLowerCase() === 'actions' || th.textContent.trim() === '') return;
            headers.push('"' + th.textContent.trim().replace(/"/g, '""') + '"');
        });
        csv.push(headers.join(','));
        
        var rows = table.querySelectorAll('tbody tr');
        rows.forEach(function(row) {
            if (row.style.display === 'none' || row.classList.contains('search-hidden')) return;
            var cols = row.querySelectorAll('td');
            if (cols.length === 0) return;
            var rowData = [];
            var skipIdx = -1;
            ths.forEach(function(th, idx) {
                if (th.textContent.trim().toLowerCase() === 'actions' || th.textContent.trim() === '') {
                    skipIdx = idx;
                }
            });
            cols.forEach(function(col, idx) {
                if (idx === skipIdx) return;
                var text = col.innerText || col.textContent;
                text = text.trim().replace(/\s+/g, ' ');
                rowData.push('"' + text.replace(/"/g, '""') + '"');
            });
            csv.push(rowData.join(','));
        });
        
        var blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.setAttribute("download", filename || 'export.csv');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function exportTableToExcel(tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) return;
        
        // Clone table and strip off action column
        var clone = table.cloneNode(true);
        var headers = clone.querySelectorAll('thead th');
        var skipIdx = -1;
        headers.forEach(function(th, idx) {
            if (th.textContent.trim().toLowerCase() === 'actions' || th.textContent.trim() === '') {
                skipIdx = idx;
                th.remove();
            }
        });
        if (skipIdx !== -1) {
            clone.querySelectorAll('tbody tr').forEach(function(tr) {
                var tds = tr.querySelectorAll('td');
                if (tds[skipIdx]) tds[skipIdx].remove();
            });
        }
        
        var html = clone.outerHTML;
        var blob = new Blob(['\ufeff' + html], {
            type: 'application/vnd.ms-excel'
        });
        var link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.setAttribute("download", filename || 'export.xls');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function reportPdfEndpoint() {
        var path = window.location.pathname || '';
        var base = path.substring(0, path.lastIndexOf('/') + 1);
        if (/\/reports\/$/i.test(base)) {
            base = base.replace(/\/reports\/$/i, '/');
        }
        return base + 'report_pdf_download.php';
    }

    function reportPdfText(node) {
        if (!node) return '';
        return (node.innerText || node.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function reportPdfFilename(value) {
        var name = (value || 'report').toString().trim().replace(/[^A-Za-z0-9._-]+/g, '_').replace(/^_+|_+$/g, '');
        if (!name) name = 'report';
        if (!/\.pdf$/i.test(name)) name += '.pdf';
        return name;
    }

    function reportPdfVisible(el) {
        if (!el) return false;
        var current = el;
        while (current && current.nodeType === 1) {
            if (current.classList && (current.classList.contains('search-hidden') || current.classList.contains('no-print'))) return false;
            var style = window.getComputedStyle ? window.getComputedStyle(current) : null;
            if (style && (style.display === 'none' || style.visibility === 'hidden')) return false;
            current = current.parentElement;
        }
        return true;
    }

    function reportPdfRowExportable(row) {
        if (!row || !row.classList) return true;
        if (row.classList.contains('search-hidden') || row.classList.contains('no-print') || row.classList.contains('no-export')) return false;
        if (row.classList.contains('hist-expand-row') || row.classList.contains('detail-row')) return false;
        return true;
    }

    function reportPdfSectionTitle(table, root, fallback) {
        var current = table;
        while (current && current !== root) {
            var prev = current.previousElementSibling;
            while (prev) {
                if (prev.matches && prev.matches('.section-title,.rpt-section-heading,h1,h2,h3,h4')) {
                    var direct = reportPdfText(prev);
                    if (direct) return direct;
                }
                var nested = prev.querySelector ? prev.querySelector('.section-title,.rpt-section-heading,h1,h2,h3,h4') : null;
                var nestedText = reportPdfText(nested);
                if (nestedText) return nestedText;
                prev = prev.previousElementSibling;
            }
            current = current.parentElement;
        }
        return fallback || 'Report Data';
    }

    function reportPdfTableSection(table, root, fallbackTitle) {
        var headerCells = table.querySelectorAll('thead th');
        if (!headerCells.length) {
            headerCells = table.querySelectorAll('tr:first-child th, tr:first-child td');
        }

        var skip = {};
        var headers = [];
        Array.prototype.forEach.call(headerCells, function(cell, idx) {
            var text = reportPdfText(cell);
            if (!text || /^actions?$/i.test(text)) {
                skip[idx] = true;
                return;
            }
            headers.push(text);
        });

        var rows = [];
        var rowNodes = table.querySelectorAll('tbody tr, tfoot tr');
        if (!rowNodes.length) rowNodes = table.querySelectorAll('tr');
        Array.prototype.forEach.call(rowNodes, function(row, rowIndex) {
            if (!reportPdfRowExportable(row)) return;
            if (!table.querySelector('tbody') && rowIndex === 0 && headerCells.length) return;
            var cells = row.querySelectorAll('td,th');
            if (!cells.length) return;
            var data = [];
            var colCursor = 0;
            Array.prototype.forEach.call(cells, function(cell) {
                var cSpan = parseInt(cell.getAttribute('colspan') || 1, 10);
                var text = reportPdfText(cell);
                if (skip[colCursor]) {
                    colCursor += cSpan;
                    return;
                }
                data.push(text || '');
                for (var s = 1; s < cSpan; s++) {
                    data.push('');
                }
                colCursor += cSpan;
            });
            if (data.length) rows.push(data);
        });

        return {
            title: reportPdfSectionTitle(table, root, fallbackTitle),
            headers: headers,
            rows: rows
        };
    }

    function collectReportPdfPayload(rootSelector, title, filename) {
        var root = typeof rootSelector === 'string' ? document.querySelector(rootSelector) : rootSelector;
        if (!root) {
            alert('No report content found to export.');
            return null;
        }

        var reportTitle = title || reportPdfText(root.querySelector('h1,h2')) || document.title || 'Report';
        var meta = [];
        // Capture station address, date range, and sub-titles for the PDF header
        Array.prototype.forEach.call(root.querySelectorAll('.rpt-address, .rpt-date-range, .report-address, .report-date-range, .rpt-header-title h4, .rpt-header-title p'), function(node) {
            var text = reportPdfText(node);
            if (text && meta.indexOf(text) === -1) meta.push(text);
        });
        Array.prototype.forEach.call(root.querySelectorAll('.header div, .header p, .report-meta, .summary-card, .summary-item'), function(node) {
            var text = reportPdfText(node);
            if (text && meta.indexOf(text) === -1) meta.push(text);
        });

        var tables = root.tagName && root.tagName.toLowerCase() === 'table' ? [root] : root.querySelectorAll('table');
        var sections = [];
        Array.prototype.forEach.call(tables, function(table) {
            if (!reportPdfVisible(table)) return;
            var section = reportPdfTableSection(table, root, reportTitle);
            if ((section.headers && section.headers.length) || (section.rows && section.rows.length)) {
                sections.push(section);
            }
        });

        return {
            title: reportTitle,
            filename: reportPdfFilename(filename || reportTitle + '_' + new Date().toISOString().slice(0, 10)),
            meta: meta,
            sections: sections
        };
    }

    function downloadReportPdf(payload, trigger) {
        if (!payload) return;
        var btn = trigger && trigger.tagName ? trigger : null;
        var original = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
        }

        fetch(reportPdfEndpoint(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function(response) {
            if (!response.ok) {
                return response.text().then(function(text) {
                    throw new Error(text || 'PDF export failed.');
                });
            }
            return response.blob();
        }).then(function(blob) {
            var blobUrl = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }));
            // Download PDF directly
            var link = document.createElement('a');
            link.href = blobUrl;
            link.download = reportPdfFilename(payload.filename);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            setTimeout(function() { URL.revokeObjectURL(blobUrl); }, 120000);
        }).catch(function(error) {
            alert('Unable to export PDF. Please try again.\n' + (error && error.message ? error.message : ''));
        }).finally(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        });
    }

    function exportPrintableAreaToPDF(rootSelector, title, filename, trigger) {
        downloadReportPdf(collectReportPdfPayload(rootSelector, title, filename), trigger || document.activeElement);
    }

    function exportTableToPDF(tableId, title, filename) {
        var table = document.getElementById(tableId);
        if (!table) return;
        downloadReportPdf(collectReportPdfPayload(table, title || 'Report', filename || tableId + '_' + new Date().toISOString().slice(0, 10)), document.activeElement);
    }

    function resolveReportPrintRoot(rootSelector) {
        if (rootSelector && rootSelector.nodeType === 1) return rootSelector;
        if (typeof rootSelector === 'string' && rootSelector.trim()) {
            var selected = document.querySelector(rootSelector);
            if (selected) return selected;
        }

        var preferredSelectors = [
            '.print-area',
            '.rpt-printable .active',
            '.rpt-printable',
            '.mp-printable .active',
            '.mp-printable',
            '.pr-printable .active',
            '.pr-printable',
            '.report-container',
            '.stock-page',
            '.fuel-section.visible',
            '.afdo-table-card',
            '.afto-table-card',
            '.mcr-table-card',
            '.tbl-card',
            '.table-card',
            '.card-table-wrap',
            '.po-table-wrap',
            '.table-wrap',
            'table'
        ];

        for (var i = 0; i < preferredSelectors.length; i++) {
            var candidate = document.querySelector(preferredSelectors[i]);
            if (candidate && reportPdfVisible(candidate)) return candidate;
        }
        return null;
    }

    function printReportArea(rootSelector) {
        var root = resolveReportPrintRoot(rootSelector);
        if (!root) {
            window.print();
            return;
        }

        var existing = document.getElementById('report-print-root');
        if (existing) existing.remove();

        var printRoot = document.createElement('div');
        printRoot.id = 'report-print-root';
        printRoot.appendChild(root.cloneNode(true));
        document.body.appendChild(printRoot);
        document.body.classList.add('report-printing');

        var cleanup = function() {
            document.body.classList.remove('report-printing');
            var node = document.getElementById('report-print-root');
            if (node) node.remove();
            window.removeEventListener('afterprint', cleanup);
        };
        window.addEventListener('afterprint', cleanup);
        window.print();
        setTimeout(cleanup, 1200);
    }

    function setupTablePagination(tableId, selectId, paginationContainerId, defaultRows) {
        var table = document.getElementById(tableId);
        if (!table) return;
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        var container = document.getElementById(paginationContainerId);
        if (container) {
            container.innerHTML = '';
            container.style.display = 'none';
        }
        
        function updatePagination() {
            var allRows = Array.from(tbody.querySelectorAll('tr'));
            allRows.forEach(function(row) {
                if (row.id.indexOf('NoResults') !== -1 || row.classList.contains('no-paginate') || row.classList.contains('no-results')) {
                    return;
                }
                if (row.classList.contains('search-hidden')) {
                    row.style.display = 'none';
                } else {
                    row.style.display = '';
                }
            });
            
            var catHeaders = tbody.querySelectorAll('.cat-header, .category-header');
            if (catHeaders.length > 0) {
                catHeaders.forEach(function(header) {
                    var next = header.nextElementSibling;
                    var hasVisibleItem = false;
                    while (next && !next.classList.contains('cat-header') && !next.classList.contains('category-header')) {
                        if (!next.classList.contains('search-hidden')) {
                            hasVisibleItem = true;
                            break;
                        }
                        next = next.nextElementSibling;
                    }
                    header.style.display = (hasVisibleItem && !header.classList.contains('search-hidden')) ? '' : 'none';
                });
            }
        }

        
        window.setTablePage = function(tId, page) {
            if (tId === tableId) {
                currentPage = page;
                updatePagination();
            }
        };
        
        // Expose a manual trigger for updates
        if (!window.tablePaginationTriggers) {
            window.tablePaginationTriggers = {};
        }
        window.tablePaginationTriggers[tableId] = function() {
            currentPage = 1;
            updatePagination();
        };
        
        // Re-run setup check regularly to integrate search filter classes
        var searchInput = document.querySelector('input[type="text"][oninput*="ft"], input[id="sq"], input[id="encodeSearch"]');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                setTimeout(function() {
                    tbody.querySelectorAll('tr').forEach(function(row) {
                        if (row.style.display === 'none') {
                            row.classList.add('search-hidden');
                        } else {
                            row.classList.remove('search-hidden');
                        }
                    });
                    currentPage = 1;
                    updatePagination();
                }, 10);
            });
        }
        
        var selectFilters = document.querySelectorAll('select[onchange*="ft"], select[id="cf"], select[id="sf"]');
        selectFilters.forEach(function(sel) {
            sel.addEventListener('change', function() {
                setTimeout(function() {
                    tbody.querySelectorAll('tr').forEach(function(row) {
                        if (row.style.display === 'none') {
                            row.classList.add('search-hidden');
                        } else {
                            row.classList.remove('search-hidden');
                        }
                    });
                    currentPage = 1;
                    updatePagination();
                }, 10);
            });
        });
        
        updatePagination();
    }

    // --- PWA OFFLINE / ONLINE DETECTION SYSTEM ---
    // Connection status banner disabled for a cleaner, seamless system experience
    function showConnectionStatus(online) {
        // Silent handling — no popups
        return;
    }

    window.addEventListener('online', () => showConnectionStatus(true));
    window.addEventListener('offline', () => showConnectionStatus(false));

    // Check initial status on load (only show if offline initially)
    if (!navigator.onLine) {
        showConnectionStatus(false);
    }

    // --- UNREGISTER ALL SERVICE WORKERS TO PREVENT STALE CACHING ---
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistrations().then(function(registrations) {
            for (let registration of registrations) {
                registration.unregister();
            }
        });
    }
    if ('caches' in window) {
        caches.keys().then(function(names) {
            for (let name of names) {
                caches.delete(name);
            }
        });
    }
  </script>

  <!-- ── GLOBAL LOGOUT CONFIRMATION MODAL ── -->
  <div id="globalLogoutModal" style="display:none;position:fixed;inset:0;background:rgba(0,15,35,0.6);backdrop-filter:blur(3px);z-index:999999;align-items:center;justify-content:center;padding:16px;">
      <div style="background:#ffffff;border-radius:12px;max-width:320px;width:100%;padding:20px 18px 16px;text-align:center;box-shadow:0 12px 36px rgba(0,0,0,0.22);animation:modalPop 0.2s cubic-bezier(0.16,1,0.3,1);border:1px solid #e2e8f0;">
          <div style="width:40px;height:40px;border-radius:50%;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:18px;margin:0 auto 12px;">
              <i class="fas fa-sign-out-alt"></i>
          </div>
          <h3 style="margin:0 0 6px;font-size:15px;font-weight:700;color:#002244;">Log Out Confirmation</h3>
          <p style="margin:0 0 16px;font-size:12.5px;color:#64748b;line-height:1.4;">Are you sure you want to end your session?</p>
          <div style="display:flex;gap:8px;justify-content:center;">
              <button type="button" onclick="closeGlobalLogoutModal()" style="flex:1;height:34px;padding:0 12px;border:1px solid #cbd5e1;background:#f8fafc;color:#475569;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;transition:all 0.15s;">
                  Cancel
              </button>
              <a href="<?= isset($public_base_url) ? htmlspecialchars($public_base_url . '/logout.php') : 'logout.php' ?>" style="flex:1;height:34px;padding:0 12px;border:none;background:#dc2626;color:#ffffff;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:5px;transition:all 0.15s;">
                  <i class="fas fa-sign-out-alt" style="font-size:11px;"></i> Log Out
              </a>
          </div>
      </div>
  </div>

  <!-- Session Inactivity Warning Modal (14 min warning / 15 min timeout) -->
  <div id="sessionTimeoutModal" style="display:none;position:fixed;inset:0;background:rgba(0,15,35,0.6);backdrop-filter:blur(3px);z-index:999999;align-items:center;justify-content:center;padding:16px;">
      <div style="background:#ffffff;border-radius:12px;box-shadow:0 16px 40px rgba(0,0,0,0.22);width:100%;max-width:320px;padding:20px 18px 16px;text-align:center;animation:modalPop 0.2s cubic-bezier(0.16,1,0.3,1);border:1px solid #e2e8f0;position:relative;">
          <div style="width:40px;height:40px;border-radius:50%;background:#fef3c7;border:1px solid #fde68a;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;">
              <i class="fas fa-exclamation-triangle" style="font-size:18px;color:#d97706;"></i>
          </div>
          <h3 style="margin:0 0 4px;font-size:14px;font-weight:700;color:#002244;letter-spacing:0.3px;">Session Timeout Warning</h3>
          <p style="margin:0 0 10px;font-size:12px;color:#64748b;line-height:1.4;">
              Inactive session will expire automatically in:
          </p>
          <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 12px;margin:0 auto 12px;display:inline-flex;align-items:center;gap:6px;">
              <i class="fas fa-clock" style="color:#d97706;font-size:13px;"></i>
              <span id="sessionCountdownDisplay" style="font-size:20px;font-weight:800;color:#b45309;letter-spacing:1.5px;font-family:monospace;">
                  01:00
              </span>
          </div>
          <p style="margin:0 0 14px;font-size:11.5px;color:#64748b;line-height:1.3;">
              Stay active or save your work to prevent logout.
          </p>
          <div style="display:flex;gap:8px;justify-content:center;">
              <button type="button" id="btnStayLoggedIn" onclick="stayLoggedIn()" style="flex:1.2;height:34px;padding:0 12px;border:none;background:#002F6C;color:#ffffff;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:5px;box-shadow:0 2px 6px rgba(0,47,108,0.2);transition:all 0.15s;">
                  <i class="fas fa-redo-alt" style="font-size:11px;"></i> Stay Logged In
              </button>
              <a href="<?= isset($public_base_url) ? htmlspecialchars($public_base_url . '/logout.php?timeout=1') : 'logout.php?timeout=1' ?>" style="flex:0.8;height:34px;padding:0 10px;border:1px solid #cbd5e1;background:#f8fafc;color:#475569;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:5px;transition:all 0.15s;">
                  <i class="fas fa-sign-out-alt" style="font-size:11px;"></i> Log Out
              </a>
          </div>
      </div>
  </div>

  <style>
  @keyframes modalPop {
      from { opacity: 0; transform: scale(0.94); }
      to   { opacity: 1; transform: scale(1); }
  }
  </style>

  <script>
  function openGlobalLogoutModal() {
      window.location.href = "<?= isset($public_base_url) ? htmlspecialchars($public_base_url . '/logout.php') : 'logout.php' ?>";
  }
  function closeGlobalLogoutModal() {
      var m = document.getElementById('globalLogoutModal');
      if (m) m.style.display = 'none';
  }
  // Close on backdrop click
  document.addEventListener('DOMContentLoaded', function() {
      var m = document.getElementById('globalLogoutModal');
      if (m) {
          m.addEventListener('click', function(e) {
              if (e.target === this) closeGlobalLogoutModal();
          });
      }
  });

  // ── INACTIVITY TIMEOUT CONTROLLER (15 min timeout, 14 min warning / 1 min remaining) ──
  (function() {
      const TOTAL_TIMEOUT_SEC  = 900; // 15 minutes total (900 seconds)
      const WARNING_TIME_SEC   = 840; // 14 minutes before warning (60 seconds remaining)
      const KEEPALIVE_URL      = "<?= isset($app_base_path) ? $app_base_path : '' ?>/backend/api/session_keepalive.php";
      const LOGOUT_URL         = "<?= isset($public_base_url) ? htmlspecialchars($public_base_url . '/logout.php?timeout=1') : 'logout.php?timeout=1' ?>";

      let lastActivityTime = Date.now();
      let warningModalOpen = false;
      let countdownTimer   = null;

      function resetActivityTimer() {
          if (warningModalOpen) return; // Do not silently reset if user is currently looking at warning modal
          lastActivityTime = Date.now();
      }

      // Track authentic user interactions on the page
      ['mousedown', 'mousemove', 'keydown', 'touchstart', 'scroll', 'click'].forEach(function(evt) {
          window.addEventListener(evt, resetActivityTimer, { passive: true });
      });

      function formatMinutesSeconds(sec) {
          const m = Math.floor(sec / 60);
          const s = Math.floor(sec % 60);
          return (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
      }

      function showWarningModal() {
          warningModalOpen = true;
          const modal = document.getElementById('sessionTimeoutModal');
          const countdownEl = document.getElementById('sessionCountdownDisplay');
          if (modal) modal.style.display = 'flex';

          if (countdownTimer) clearInterval(countdownTimer);
          countdownTimer = setInterval(function() {
              const elapsedSec = Math.floor((Date.now() - lastActivityTime) / 1000);
              const remainingSec = Math.max(0, TOTAL_TIMEOUT_SEC - elapsedSec);

              if (countdownEl) {
                  countdownEl.textContent = formatMinutesSeconds(remainingSec);
              }

              if (remainingSec <= 0) {
                  clearInterval(countdownTimer);
                  window.location.href = LOGOUT_URL;
              }
          }, 1000);
      }

      function hideWarningModal() {
          warningModalOpen = false;
          const modal = document.getElementById('sessionTimeoutModal');
          if (modal) modal.style.display = 'none';
          if (countdownTimer) {
              clearInterval(countdownTimer);
              countdownTimer = null;
          }
      }

      window.stayLoggedIn = function() {
          const btn = document.getElementById('btnStayLoggedIn');
          if (btn) {
              btn.disabled = true;
              btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
          }

          fetch(KEEPALIVE_URL, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              credentials: 'same-origin'
          })
          .then(function(res) { return res.json(); })
          .then(function(data) {
              if (data && data.ok) {
                  lastActivityTime = Date.now();
                  hideWarningModal();
              } else {
                  window.location.href = LOGOUT_URL;
              }
          })
          .catch(function() {
              // If network fail, still reset local activity timer
              lastActivityTime = Date.now();
              hideWarningModal();
          })
          .finally(function() {
              if (btn) {
                  btn.disabled = false;
                  btn.innerHTML = '<i class="fas fa-redo-alt"></i> Stay Logged In';
              }
          });
      };

      // Periodic check every 2 seconds
      setInterval(function() {
          const elapsedSec = Math.floor((Date.now() - lastActivityTime) / 1000);

          if (elapsedSec >= TOTAL_TIMEOUT_SEC) {
              window.location.href = LOGOUT_URL;
          } else if (elapsedSec >= WARNING_TIME_SEC && !warningModalOpen) {
              showWarningModal();
          }
      }, 2000);
  })();
  </script>

  <script src="<?= isset($app_base_path) ? $app_base_path : '' ?>/assets/js/live_sync.js?v=<?= @filemtime(__DIR__ . '/../assets/js/live_sync.js') ?: '1' ?>"></script>
  <script src="<?= isset($app_base_path) ? $app_base_path : '' ?>/assets/js/global_draft_engine.js?v=<?= @filemtime(__DIR__ . '/../assets/js/global_draft_engine.js') ?: '1' ?>"></script>
</body>
</html>
