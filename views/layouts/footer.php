<?php
/**
 * Layout: Footer & Global Scripts
 * StockPilot — Liquor Business Inventory Management System
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
                <div class="pagination-info">Showing <strong>0</strong> of <strong>0</strong> records</div>
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
            <div class="pagination-info">
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

// Auto-initialize pagination on all data tables
document.addEventListener('DOMContentLoaded', function() {
    const tables = document.querySelectorAll('table');
    tables.forEach(table => {
        if (table.classList.contains('no-paginate') || table.closest('.modal')) return;
        initTablePagination(table, 10);
    });
});
</script>
</body>
</html>
