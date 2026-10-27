/**
 * StockPilot Utilities
 * InventoryTeam — Liquor Business Inventory Management System
 * Shared client-side helpers for formatting, escaping, and clipboard actions.
 */

(function (window, document) {
    'use strict';

    /**
     * Safely escapes HTML special characters to prevent XSS.
     * @param {*} str
     * @returns {string}
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>"']/g, function (m) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[m];
        });
    }

    /**
     * Formats a quantity value, removing trailing .00 or trailing zeros.
     * Matches the server-side formatQty() helper in header.php.
     * @param {*} val
     * @param {number} [decimals=2]
     * @returns {string}
     */
    function formatQty(val, decimals = 2) {
        if (val === null || val === undefined || val === '') return '0';
        const cleaned = typeof val === 'string' ? val.replace(/,/g, '').trim() : val;
        const num = parseFloat(cleaned);
        if (isNaN(num)) return '0';
        return num.toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: decimals
        });
    }

    /**
     * Copies text to system clipboard with modern and legacy fallbacks.
     * @param {string} text
     * @param {Function} [onSuccess]
     * @param {Function} [onError]
     */
    function copyToClipboard(text, onSuccess, onError) {
        if (!text) return;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => {
                if (typeof onSuccess === 'function') onSuccess();
            }).catch(err => {
                if (typeof onError === 'function') onError(err);
            });
        } else {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '-9999px';
            textarea.setAttribute('readonly', '');
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            try {
                const successful = document.execCommand('copy');
                if (successful && typeof onSuccess === 'function') {
                    onSuccess();
                } else if (!successful && typeof onError === 'function') {
                    onError(new Error('Copy command unsuccessful'));
                }
            } catch (err) {
                if (typeof onError === 'function') onError(err);
            }
            document.body.removeChild(textarea);
        }
    }


    /**
     * Highlights matched search queries inside a text string.
     * @param {string} text
     * @param {string} query
     * @returns {string}
     */
    function highlightMatches(text, query) {
        if (!query || !text) return escapeHtml(text || '');
        const escapedText = escapeHtml(text);
        const escapedQuery = escapeHtml(query);
        const regex = new RegExp('(' + escapedQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return escapedText.replace(regex, '<span class="search-match-highlight">$1</span>');
    }

    let activeAutocomplete = null;

    /**
     * Initializes a searchable item autocomplete dropdown.
     * Supports real-time filtering, keyword highlighting, ARIA accessibility,
     * keyboard navigation (ArrowUp, ArrowDown, Enter, Escape), and form submission.
     *
     * @param {Object} options
     * @param {Array<Object>} options.items - Array of item objects {id, code, name, type, unit}
     * @param {string} [options.inputId='itemSearchInput']
     * @param {string} [options.hiddenId='selectedItemId']
     * @param {string} [options.dropdownId='itemDropdownList']
     * @param {string} [options.clearBtnId='clearItemSearchBtn']
     * @param {string} [options.wrapperId='itemSearchWrapper']
     * @param {string} [options.formId='stockCardForm']
     * @param {Function} [options.onSelect] - Optional custom select callback(item)
     * @returns {Object}
     */
    function initItemAutocomplete(options) {
        if (!options || !Array.isArray(options.items)) return null;

        const config = Object.assign({
            items: [],
            inputId: 'itemSearchInput',
            hiddenId: 'selectedItemId',
            dropdownId: 'itemDropdownList',
            clearBtnId: 'clearItemSearchBtn',
            wrapperId: 'itemSearchWrapper',
            formId: 'stockCardForm',
            onSelect: null
        }, options);

        const input = document.getElementById(config.inputId);
        const hidden = document.getElementById(config.hiddenId);
        const list = document.getElementById(config.dropdownId);
        const clearBtn = document.getElementById(config.clearBtnId);
        const wrapper = document.getElementById(config.wrapperId);
        const form = config.formId ? document.getElementById(config.formId) : null;

        let currentHighlightedIndex = -1;
        let currentFilteredItems = [];

        const instance = {
            config: config,
            items: config.items,
            render: function(matches, query) {
                if (!list) return;
                currentFilteredItems = matches || [];
                currentHighlightedIndex = -1;

                if (!matches || matches.length === 0) {
                    list.innerHTML = '<div style="padding: 14px 16px; text-align: center; color: var(--gray); font-size: 13px; font-weight: 600;">No inventory items found.</div>';
                    list.style.display = 'block';
                    return;
                }

                const selectedId = hidden ? parseInt(hidden.value, 10) : NaN;
                let html = '';
                matches.forEach((item, index) => {
                    const isSelected = (item.id === selectedId);
                    const typeBadge = item.type === 'finished_good' 
                        ? '<span class="badge-type type-fg" style="font-size: 10px; padding: 2px 6px;">Finished</span>'
                        : '<span class="badge-type type-raw" style="font-size: 10px; padding: 2px 6px;">Raw</span>';

                    html += `
                        <div class="item-result-row ${isSelected ? 'selected' : ''}" 
                             id="item-opt-${index}"
                             role="option"
                             aria-selected="${isSelected ? 'true' : 'false'}"
                             data-index="${index}"
                             onclick="selectInventoryItem(${item.id})">
                            <div style="display: flex; flex-direction: column;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-weight: 700; color: var(--panel-ink); font-size: 13px;">${highlightMatches(item.name, query)}</span>
                                    <span style="color: var(--gray); font-size: 12px;">—</span>
                                    <span style="font-family: monospace; font-size: 12px; font-weight: 600; color: #475569;">${highlightMatches(item.code, query)}</span>
                                </div>
                                <small style="color: var(--gray); font-size: 11px;">Item ID: ${item.id} ${item.unit ? '&middot; Unit: ' + escapeHtml(item.unit) : ''}</small>
                            </div>
                            ${typeBadge}
                        </div>
                    `;
                });

                list.innerHTML = html;
                list.style.display = 'block';
            },
            filter: function(query) {
                const q = (query || '').trim().toLowerCase();
                if (clearBtn) {
                    clearBtn.style.display = (query && query.length > 0) ? 'inline-block' : 'none';
                }

                if (!q) {
                    instance.render(config.items, '');
                    return;
                }

                const matches = config.items.filter(item => {
                    const nameMatch = (item.name || '').toLowerCase().includes(q);
                    const codeMatch = (item.code || '').toLowerCase().includes(q);
                    const idMatch   = String(item.id).includes(q) || ('rm-' + item.id).includes(q) || ('fg-' + item.id).includes(q);
                    return nameMatch || codeMatch || idMatch;
                });

                instance.render(matches, query);
            },
            open: function() {
                if (!input) return;
                input.setAttribute('aria-expanded', 'true');
                input.select();
                instance.filter('');
            },
            close: function() {
                if (list) {
                    list.style.display = 'none';
                }
                if (input) {
                    input.setAttribute('aria-expanded', 'false');
                }
                currentHighlightedIndex = -1;
            },
            select: function(itemId) {
                const item = config.items.find(it => it.id === itemId);
                if (!item) return;

                if (hidden) hidden.value = item.id;
                if (input) input.value = item.name + ' — ' + item.code;
                if (clearBtn) clearBtn.style.display = 'inline-block';
                instance.close();

                if (typeof config.onSelect === 'function') {
                    config.onSelect(item);
                } else if (form) {
                    form.submit();
                }
            },
            clear: function() {
                if (!input) return;
                input.value = '';
                if (clearBtn) clearBtn.style.display = 'none';
                input.focus();
                instance.filter('');
            },
            restoreDisplay: function() {
                if (!hidden || !input) return;
                const selectedId = parseInt(hidden.value, 10);
                const item = config.items.find(it => it.id === selectedId);
                if (item) {
                    input.value = item.name + ' — ' + item.code;
                    if (clearBtn) clearBtn.style.display = 'inline-block';
                }
            },
            handleKeydown: function(e) {
                const isOpen = (list && list.style.display === 'block');

                if (!isOpen) {
                    if (e.key === 'ArrowDown' || e.key === 'Enter') {
                        instance.open();
                        e.preventDefault();
                    }
                    return;
                }

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (currentFilteredItems.length === 0) return;
                    currentHighlightedIndex = (currentHighlightedIndex + 1) % currentFilteredItems.length;
                    instance.updateHighlighted();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (currentFilteredItems.length === 0) return;
                    currentHighlightedIndex = (currentHighlightedIndex - 1 + currentFilteredItems.length) % currentFilteredItems.length;
                    instance.updateHighlighted();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentHighlightedIndex >= 0 && currentHighlightedIndex < currentFilteredItems.length) {
                        instance.select(currentFilteredItems[currentHighlightedIndex].id);
                    } else if (currentFilteredItems.length === 1) {
                        instance.select(currentFilteredItems[0].id);
                    }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    instance.close();
                    instance.restoreDisplay();
                }
            },
            updateHighlighted: function() {
                if (!list) return;
                list.querySelectorAll('.item-result-row').forEach((row, idx) => {
                    if (idx === currentHighlightedIndex) {
                        row.classList.add('highlighted');
                        row.scrollIntoView({ block: 'nearest' });
                    } else {
                        row.classList.remove('highlighted');
                    }
                });
            }
        };

        activeAutocomplete = instance;

        // Click outside listener for item search dropdown
        document.addEventListener('click', function(e) {
            if (wrapper && !wrapper.contains(e.target)) {
                if (list && list.style.display === 'block') {
                    instance.close();
                    instance.restoreDisplay();
                }
            }
        });

        return instance;
    }

    // Global delegates routing to activeAutocomplete
    function highlightMatchesGlobal(text, query) { return highlightMatches(text, query); }
    function renderItemDropdown(matches, query) { if (activeAutocomplete) activeAutocomplete.render(matches, query); }
    function filterItemDropdown(query) { if (activeAutocomplete) activeAutocomplete.filter(query); }
    function openItemDropdown() { if (activeAutocomplete) activeAutocomplete.open(); }
    function closeItemDropdown() { if (activeAutocomplete) activeAutocomplete.close(); }
    function selectInventoryItem(itemId) { if (activeAutocomplete) activeAutocomplete.select(itemId); }
    function clearItemSearch() { if (activeAutocomplete) activeAutocomplete.clear(); }
    function restoreSelectedItemDisplay() { if (activeAutocomplete) activeAutocomplete.restoreDisplay(); }
    function handleItemDropdownKeydown(e) { if (activeAutocomplete) activeAutocomplete.handleKeydown(e); }
    function updateHighlightedRow() { if (activeAutocomplete) activeAutocomplete.updateHighlighted(); }

    // Expose helpers globally
    window.escapeHtml = escapeHtml;
    window.formatQty = formatQty;
    window.copyToClipboard = copyToClipboard;
    window.highlightMatches = highlightMatches;
    window.initItemAutocomplete = initItemAutocomplete;
    window.renderItemDropdown = renderItemDropdown;
    window.filterItemDropdown = filterItemDropdown;
    window.openItemDropdown = openItemDropdown;
    window.closeItemDropdown = closeItemDropdown;
    window.selectInventoryItem = selectInventoryItem;
    window.clearItemSearch = clearItemSearch;
    window.restoreSelectedItemDisplay = restoreSelectedItemDisplay;
    window.handleItemDropdownKeydown = handleItemDropdownKeydown;
    window.updateHighlightedRow = updateHighlightedRow;

    // Attach to StockPilot namespace
    window.StockPilot = window.StockPilot || {};
    window.StockPilot.escapeHtml = escapeHtml;
    window.StockPilot.formatQty = formatQty;
    window.StockPilot.copyToClipboard = copyToClipboard;
    window.StockPilot.highlightMatches = highlightMatches;
    window.StockPilot.initItemAutocomplete = initItemAutocomplete;

})(window, document);
