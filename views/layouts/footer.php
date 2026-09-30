<?php
/**
 * Layout: Footer & Global Scripts
 * InventoryTeam — Liquor Business Inventory Management System
 */
?>
    </main>

    <!-- App Bottom Footer -->
    <footer style="background: #ffffff; border-top: 1px solid var(--border); padding: 16px 28px; text-align: center; font-size: 12.5px; color: var(--gray); margin-top: auto;">
        <div style="display: flex; align-items: center; justify-content: space-between; max-width: 1540px; margin: 0 auto; flex-wrap: wrap; gap: 12px;">
            <span>&copy; <?= date('Y') ?> InventoryTeam &middot; Liquor Business Inventory Management System</span>
            <span style="display: flex; align-items: center; gap: 14px;">
                <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: var(--accent);"></span>
                <span style="color: var(--accent); font-weight: 600;">System Online</span>
            </span>
        </div>
    </footer>
</div><!-- /.main-content -->
</div><!-- /.app-shell -->

<?php require_once __DIR__ . '/settings_modal.php'; ?>

<script>
// Toggle Accordion Nav Group
function toggleNavGroup(groupId) {
    const group = document.getElementById(groupId);
    if (!group) return;
    const isOpen = group.classList.contains('open');
    group.classList.toggle('open');
    const headerBtn = group.querySelector('.nav-group-header');
    if (headerBtn) {
        headerBtn.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
    }
}

// Toggle Mobile Responsive Sidebar
function toggleMobileSidebar() {
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (!sidebar || !backdrop) return;
    sidebar.classList.toggle('open');
    backdrop.classList.toggle('active');
}

function toggleSidebar() {
    toggleMobileSidebar();
}

// Shared HTML-escaping helper used across modals and dynamic table renders
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

// Universal Client-side Table Filter with Pagination Integration
function filterTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;

    const term = input.value.toLowerCase().trim();
    const rows = table.querySelectorAll('tbody tr');

    rows.forEach(row => {
        if (row.classList.contains('no-filter') || row.querySelector('td[colspan]')) return;
        const text = row.textContent.toLowerCase();
        const matches = text.includes(term);
        if (matches) {
            delete row.dataset.filteredOut;
        } else {
            row.dataset.filteredOut = 'true';
        }
    });

    if (typeof table.paginationUpdate === 'function') {
        table.paginationUpdate(true);
    }
}

// Universal Client-side HTML Table to CSV Exporter
function exportTableToCsv(tableId, filenamePrefix) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const csv = [];
    const rows = table.querySelectorAll('tr');

    for (let i = 0; i < rows.length; i++) {
        if (rows[i].dataset.filteredOut === 'true' || rows[i].classList.contains('empty-filter-row')) {
            continue;
        }
        const cols = rows[i].querySelectorAll('td, th');
        if (cols.length === 1 && cols[0].hasAttribute('colspan')) {
            continue;
        }
        const row = [];
        for (let j = 0; j < cols.length; j++) {
            let text = (cols[j].textContent || '').replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ').trim();
            text = text.replace(/"/g, '""');
            row.push('"' + text + '"');
        }
        if (row.length > 0) {
            csv.push(row.join(','));
        }
    }

    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = (filenamePrefix || 'export') + '_report_' + new Date().toISOString().slice(0, 10) + '.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// Universal Client-side Column Sorting Engine
function initTableSorting(table) {
    if (!table || table.classList.contains('no-sort')) return;
    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    if (!thead || !tbody) return;

    const headers = Array.from(thead.querySelectorAll('tr:first-child th'));
    headers.forEach((th, colIdx) => {
        const label = th.textContent.trim().toLowerCase();
        if (!label || label === 'actions' || label === 'action' || label === 'audit access' || th.classList.contains('no-sort')) {
            return;
        }
        th.classList.add('sortable-th');
        th.setAttribute('tabindex', '0');
        th.setAttribute('role', 'columnheader');
        th.setAttribute('aria-sort', 'none');

        const triggerSort = () => {
            const currentDir = th.classList.contains('sort-asc') ? 'asc' : (th.classList.contains('sort-desc') ? 'desc' : 'none');
            const nextDir = currentDir === 'asc' ? 'desc' : 'asc';

            headers.forEach(h => {
                h.classList.remove('sort-asc', 'sort-desc');
                if (h.classList.contains('sortable-th')) h.setAttribute('aria-sort', 'none');
            });
            th.classList.add(nextDir === 'asc' ? 'sort-asc' : 'sort-desc');
            th.setAttribute('aria-sort', nextDir === 'asc' ? 'ascending' : 'descending');

            const rows = Array.from(tbody.querySelectorAll('tr')).filter(tr => !tr.querySelector('td[colspan]'));
            if (rows.length <= 1) return;

            rows.sort((rowA, rowB) => {
                const cellA = (rowA.children[colIdx]?.innerText || '').trim();
                const cellB = (rowB.children[colIdx]?.innerText || '').trim();

                const numA = parseFloat(cellA.replace(/[^0-9.-]+/g, ''));
                const numB = parseFloat(cellB.replace(/[^0-9.-]+/g, ''));
                const isNumeric = !isNaN(numA) && !isNaN(numB) && /^[\+\-\$₱]?\s*[\d,]+(\.\d+)?(\s*[a-zA-Z%]+)?$/.test(cellA) && /^[\+\-\$₱]?\s*[\d,]+(\.\d+)?(\s*[a-zA-Z%]+)?$/.test(cellB);

                if (isNumeric) {
                    return nextDir === 'asc' ? (numA - numB) : (numB - numA);
                }

                const dateA = Date.parse(cellA);
                const dateB = Date.parse(cellB);
                if (!isNaN(dateA) && !isNaN(dateB) && /\d{4}/.test(cellA) && /\d{4}/.test(cellB)) {
                    return nextDir === 'asc' ? (dateA - dateB) : (dateB - dateA);
                }

                return nextDir === 'asc'
                    ? cellA.localeCompare(cellB, undefined, { numeric: true, sensitivity: 'base' })
                    : cellB.localeCompare(cellA, undefined, { numeric: true, sensitivity: 'base' });
            });

            rows.forEach(r => tbody.appendChild(r));
            if (typeof table.paginationUpdate === 'function') {
                table.paginationUpdate(true);
            }
        };

        th.addEventListener('click', triggerSort);
        th.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                triggerSort();
            }
        });
    });
}

// Universal 10-Rows-Per-Page Table Pagination Engine
function initTablePagination(table, pageSize = 10) {
    if (!table) return;

    const tableId = table.id || ('table_' + Math.random().toString(36).substring(2, 9));
    if (!table.id) table.id = tableId;

    let paginationContainer = document.getElementById('pagination_' + tableId);
    if (!paginationContainer) {
        paginationContainer = document.createElement('div');
        paginationContainer.className = 'table-pagination';
        paginationContainer.id = 'pagination_' + tableId;

        const parentWrapper = table.closest('.table-responsive') || table;
        parentWrapper.parentNode.insertBefore(paginationContainer, parentWrapper.nextSibling);
    }

    let currentPage = 1;

    function renderPagination() {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const allRows = Array.from(tbody.querySelectorAll('tr')).filter(tr => {
            return !tr.querySelector('td[colspan]') && !tr.classList.contains('no-paginate');
        });

        // If no data rows (empty state)
        if (allRows.length === 0) {
            paginationContainer.style.display = 'none';
            return;
        }

        const visibleRows = allRows.filter(tr => tr.dataset.filteredOut !== 'true');
        const totalItems = visibleRows.length;

        if (totalItems === 0) {
            paginationContainer.style.display = 'flex';
            paginationContainer.innerHTML = `
                <div class="pagination-info" aria-live="polite">Showing <strong>0</strong> of <strong>0</strong> records</div>
                <div class="pagination-controls"></div>
            `;
            allRows.forEach(tr => tr.style.display = 'none');
            return;
        }

        paginationContainer.style.display = 'flex';

        const totalPages = Math.ceil(totalItems / pageSize) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const startIndex = (currentPage - 1) * pageSize;
        const endIndex = Math.min(startIndex + pageSize, totalItems);

        let currentVisibleIndex = 0;
        allRows.forEach(tr => {
            if (tr.dataset.filteredOut === 'true') {
                tr.style.display = 'none';
            } else {
                if (currentVisibleIndex >= startIndex && currentVisibleIndex < endIndex) {
                    tr.style.display = '';
                } else {
                    tr.style.display = 'none';
                }
                currentVisibleIndex++;
            }
        });

        // Controls HTML
        let controlsHtml = `
            <button type="button" class="btn-page prev-page" ${currentPage === 1 ? 'disabled' : ''} aria-label="Previous page">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                <span>Previous</span>
            </button>
        `;

        const maxButtons = 5;
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, startPage + maxButtons - 1);
        if (endPage - startPage < maxButtons - 1) {
            startPage = Math.max(1, endPage - maxButtons + 1);
        }

        if (startPage > 1) {
            controlsHtml += `<button type="button" class="btn-page page-num" data-page="1">1</button>`;
            if (startPage > 2) {
                controlsHtml += `<span class="page-ellipsis">...</span>`;
            }
        }

        for (let p = startPage; p <= endPage; p++) {
            controlsHtml += `<button type="button" class="btn-page page-num ${p === currentPage ? 'active' : ''}" data-page="${p}">${p}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                controlsHtml += `<span class="page-ellipsis">...</span>`;
            }
            controlsHtml += `<button type="button" class="btn-page page-num" data-page="${totalPages}">${totalPages}</button>`;
        }

        controlsHtml += `
            <button type="button" class="btn-page next-page" ${currentPage === totalPages ? 'disabled' : ''} aria-label="Next page">
                <span>Next</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
        `;

        const filterExtra = (allRows.length !== totalItems) ? ` <span style="color: var(--gray); font-size: 11.5px;">(filtered from ${allRows.length} total)</span>` : '';

        paginationContainer.innerHTML = `
            <div class="pagination-info" aria-live="polite">
                Showing <strong>${startIndex + 1}</strong> to <strong>${endIndex}</strong> of <strong>${totalItems}</strong> records${filterExtra}
            </div>
            <div class="pagination-controls">
                ${controlsHtml}
            </div>
        `;

        const prevBtn = paginationContainer.querySelector('.prev-page');
        if (prevBtn && !prevBtn.disabled) {
            prevBtn.onclick = () => { currentPage--; renderPagination(); };
        }

        const nextBtn = paginationContainer.querySelector('.next-page');
        if (nextBtn && !nextBtn.disabled) {
            nextBtn.onclick = () => { currentPage++; renderPagination(); };
        }

        paginationContainer.querySelectorAll('.page-num').forEach(btn => {
            btn.onclick = () => {
                const p = parseInt(btn.dataset.page, 10);
                if (p && p !== currentPage) {
                    currentPage = p;
                    renderPagination();
                }
            };
        });
    }

    table.paginationUpdate = function(resetToPage1 = false) {
        if (resetToPage1) currentPage = 1;
        renderPagination();
    };

    renderPagination();
}

// Auto-initialize pagination & sorting on all data tables, plus global modal Escape & Focus Trap
document.addEventListener('DOMContentLoaded', function() {
    const tables = document.querySelectorAll('table');
    tables.forEach(table => {
        if (table.classList.contains('no-paginate') || table.closest('.modal, .modal-backdrop, .modal-card, .settings-modal-backdrop')) return;
        initTablePagination(table, 10);
        initTableSorting(table);
    });

    // Global Escape key dismissal & Tab focus trapping for all page modals
    document.addEventListener('keydown', function(e) {
        const openModals = Array.from(document.querySelectorAll('.modal-backdrop.open, .modal-overlay.open, .settings-modal-backdrop.open, .modal[style*="flex"], .modal-overlay[style*="flex"], .modal-backdrop[style*="flex"]'));
        if (openModals.length === 0) return;
        const activeModal = openModals[openModals.length - 1];

        if (e.key === 'Escape') {
            const closeBtn = activeModal.querySelector('.modal-close, .settings-btn-close, [onclick*="close"]');
            if (closeBtn) {
                closeBtn.click();
            } else {
                activeModal.classList.remove('open');
                if (activeModal.style.display === 'flex') activeModal.style.display = 'none';
                document.body.style.overflow = '';
            }
            return;
        }

        if (e.key === 'Tab') {
            const focusable = Array.from(activeModal.querySelectorAll(
                'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(el => el.offsetParent !== null);

            if (focusable.length === 0) return;
            const firstEl = focusable[0];
            const lastEl = focusable[focusable.length - 1];

            if (e.shiftKey && document.activeElement === firstEl) {
                e.preventDefault();
                lastEl.focus();
            } else if (!e.shiftKey && document.activeElement === lastEl) {
                e.preventDefault();
                firstEl.focus();
            }
        }
    });
});
</script>
</body>
</html>
