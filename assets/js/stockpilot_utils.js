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
        const num = parseFloat(val);
        if (isNaN(num)) return '0';
        const fixed = num.toFixed(decimals);
        return fixed.includes('.') ? fixed.replace(/\.?0+$/, '') : fixed;
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

    // Expose helpers globally
    window.escapeHtml = escapeHtml;
    window.formatQty = formatQty;
    window.copyToClipboard = copyToClipboard;

    // Attach to StockPilot namespace
    window.StockPilot = window.StockPilot || {};
    window.StockPilot.escapeHtml = escapeHtml;
    window.StockPilot.formatQty = formatQty;
    window.StockPilot.copyToClipboard = copyToClipboard;

})(window, document);
