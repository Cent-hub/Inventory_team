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
<script src="<?= BASE_URL ?>assets/js/settings_modal.js?v=<?= file_exists(__DIR__ . '/../../assets/js/settings_modal.js') ? filemtime(__DIR__ . '/../../assets/js/settings_modal.js') : time() ?>"></script>

<script>
const APP_BASE_URL = <?= json_encode(BASE_URL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// Toggle Desktop Sidebar Collapse / Expand
function toggleSidebarCollapse() {
    const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
    try {
        localStorage.setItem('inventory_sidebar_collapsed', isCollapsed ? 'true' : 'false');
        document.cookie = "inventory_sidebar_collapsed=" + (isCollapsed ? "true" : "false") + "; path=/; max-age=31536000";
    } catch(e) {}

    // Close any lingering flyouts
    document.querySelectorAll('.nav-group.flyout-open').forEach(g => g.classList.remove('flyout-open'));

    updateSidebarToggleAttributes();

    // Trigger window resize event so responsive charts, grids, and tables reflow smoothly
    window.dispatchEvent(new Event('resize'));
}

function updateSidebarToggleAttributes() {
    const isCollapsed = document.body.classList.contains('sidebar-collapsed');
    const toggleBtn = document.getElementById('sidebarToggleBtn');
    if (toggleBtn) {
        toggleBtn.setAttribute('aria-expanded', !isCollapsed ? 'true' : 'false');
        toggleBtn.setAttribute('title', isCollapsed ? 'Expand sidebar (Ctrl+B)' : 'Collapse sidebar (Ctrl+B)');
        toggleBtn.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }
}

// Toggle Accordion Nav Group (Supports both Accordion in expanded state and Flyout in collapsed state)
function toggleNavGroup(groupId) {
    const group = document.getElementById(groupId);
    if (!group) return;

    if (document.body.classList.contains('sidebar-collapsed') && window.innerWidth > 992) {
        const isFlyoutOpen = group.classList.contains('flyout-open');
        document.querySelectorAll('.nav-group.flyout-open').forEach(g => {
            if (g !== group) g.classList.remove('flyout-open');
        });
        group.classList.toggle('flyout-open', !isFlyoutOpen);
        return;
    }

    const isOpen = group.classList.contains('open');
    group.classList.toggle('open');
    const headerBtn = group.querySelector('.nav-group-header');
    if (headerBtn) {
        headerBtn.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
    }
}

// Close open flyout popovers when clicking outside
document.addEventListener('click', function(e) {
    if (document.body.classList.contains('sidebar-collapsed')) {
        if (!e.target.closest('.nav-group')) {
            document.querySelectorAll('.nav-group.flyout-open').forEach(g => {
                g.classList.remove('flyout-open');
            });
        }
    }
});

// Keyboard shortcut: Ctrl+B or Cmd+B toggles sidebar collapse
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'B')) {
        const tag = (e.target.tagName || '').toLowerCase();
        if (tag !== 'input' && tag !== 'textarea' && !e.target.isContentEditable) {
            e.preventDefault();
            toggleSidebarCollapse();
        }
    }
});

// Initialize toggle button state on DOM load
document.addEventListener('DOMContentLoaded', function() {
    updateSidebarToggleAttributes();
});

// Header Notification Bell Dropdown Toggle
function toggleNotificationDropdown(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const wrapper = document.getElementById('headerNotificationWrapper');
    const btn = document.getElementById('notificationBellBtn');
    if (!wrapper || !btn) return;

    const isOpen = wrapper.classList.toggle('open');
    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}

// Storage helpers for individual notification read states
function getStoredReadNotifications() {
    try {
        const stored = localStorage.getItem('inventory_read_notifications');
        if (stored) {
            const parsed = JSON.parse(stored);
            if (Array.isArray(parsed)) return parsed;
        }
    } catch(err) {}
    return [];
}

function saveReadNotifications(readIds) {
    try {
        localStorage.setItem('inventory_read_notifications', JSON.stringify(readIds));
        document.cookie = 'inventory_read_notifications=' + encodeURIComponent(JSON.stringify(readIds)) + '; path=/; max-age=2592000; SameSite=Lax';
    } catch(err) {}
}

function addNotificationToReadList(notifId) {
    if (!notifId) return;
    const readIds = getStoredReadNotifications();
    if (!readIds.includes(notifId)) {
        readIds.push(notifId);
        saveReadNotifications(readIds);
    }
}

function removeNotificationFromReadList(notifId) {
    if (!notifId) return;
    let readIds = getStoredReadNotifications();
    readIds = readIds.filter(function(id) { return id !== notifId; });
    saveReadNotifications(readIds);
}

// Notification Filter State
let currentNotificationFilter = 'all';

// Universal Filter for Notification Dropdown Tabs (All, Unread, Stock, Messages)
function filterNotifications(filter, btn) {
    currentNotificationFilter = filter || 'all';

    // Update active tab buttons visual state
    const tabs = document.querySelectorAll('.notif-tabs .notif-tab');
    tabs.forEach(function(t) {
        const isCurrent = (t === btn || t.getAttribute('data-filter') === currentNotificationFilter);
        t.classList.toggle('active', isCurrent);
        t.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
    });

    const items = document.querySelectorAll('#notifListContainer .notif-item');
    let visibleCount = 0;

    items.forEach(function(item) {
        const notifType = item.getAttribute('data-type') || '';
        const isUnread = item.classList.contains('unread');
        let show = false;

        if (currentNotificationFilter === 'all') {
            show = true;
        } else if (currentNotificationFilter === 'unread') {
            show = isUnread;
        } else if (currentNotificationFilter === 'stock') {
            show = ['low_stock', 'pending_adj', 'pending_transfer', 'super_admin_stock'].includes(notifType);
        } else if (currentNotificationFilter === 'messages') {
            show = (notifType === 'support_message');
        }

        item.style.display = show ? 'flex' : 'none';
        if (show) visibleCount++;
    });

    // Toggle category section headers based on whether any child item is visible
    const sections = document.querySelectorAll('#notifListContainer .notif-section');
    sections.forEach(function(sec) {
        const hasVisibleChild = Array.from(sec.querySelectorAll('.notif-item')).some(function(item) {
            return item.style.display !== 'none';
        });
        sec.style.display = hasVisibleChild ? '' : 'none';
    });

    // Toggle filter empty state
    const filterEmpty = document.getElementById('notifFilterEmpty');
    if (filterEmpty) {
        if (items.length > 0 && visibleCount === 0) {
            filterEmpty.style.display = 'block';
        } else {
            filterEmpty.style.display = 'none';
        }
    }
}

// Update notification badge, dropdown counter, and accessibility labels
function updateNotificationBadgeDisplay(count) {
    const badge = document.getElementById('notificationBadge');
    const countTag = document.getElementById('notifHeaderCount');
    const markAllBtn = document.getElementById('btnMarkAllRead') || document.querySelector('.btn-mark-all-read');
    const btn = document.getElementById('notificationBellBtn');
    const tabUnread = document.getElementById('tabCountUnread');

    if (tabUnread) {
        tabUnread.textContent = count;
    }

    if (count <= 0) {
        if (badge) {
            badge.style.display = 'none';
            badge.textContent = '0';
        }
        if (countTag) {
            countTag.style.display = 'none';
            countTag.textContent = '0 New';
        }
        if (markAllBtn) {
            markAllBtn.style.display = 'none';
        }
        if (btn) {
            btn.setAttribute('aria-label', 'Notifications');
        }
    } else {
        if (badge) {
            badge.style.display = 'inline-flex';
            badge.textContent = count > 99 ? '99+' : count;
        }
        if (countTag) {
            countTag.style.display = 'inline-block';
            countTag.textContent = count + ' New';
        }
        if (markAllBtn) {
            markAllBtn.style.display = 'inline-block';
        }
        if (btn) {
            btn.setAttribute('aria-label', 'Notifications (' + count + ' unread)');
        }
    }

    // If currently filtered by unread, re-evaluate item visibility
    if (currentNotificationFilter === 'unread') {
        filterNotifications('unread');
    }
}

// Handle tapping a single notification message link (decreases count & navigates)
function handleNotificationClick(e, item) {
    if (!item) return;
    const notifId = item.getAttribute('data-notif-id');
    if (!notifId) return;

    // If already read, permit navigation without decrementing
    if (item.classList.contains('read') && !item.classList.contains('unread')) {
        return;
    }

    // Mark visual state in DOM immediately
    item.classList.remove('unread');
    item.classList.add('read');

    const markBtn = item.querySelector('.notif-mark-btn');
    if (markBtn) {
        markBtn.classList.add('is-read');
        markBtn.setAttribute('title', 'Mark as unread');
        markBtn.setAttribute('aria-label', 'Mark as unread');
    }

    // Synchronously persist read status in localStorage and Cookie
    addNotificationToReadList(notifId);

    // Decrement unread counter immediately
    const remainingUnread = document.querySelectorAll('#notifListContainer .notif-item.unread').length;
    updateNotificationBadgeDisplay(remainingUnread);
}

// Toggle read/unread state on dot button without navigating away
function toggleSingleNotificationRead(e, item) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    if (!item) return;
    const notifId = item.getAttribute('data-notif-id');
    if (!notifId) return;

    const isCurrentlyRead = item.classList.contains('read') && !item.classList.contains('unread');
    const markBtn = item.querySelector('.notif-mark-btn');

    if (isCurrentlyRead) {
        // Toggle back to unread
        item.classList.remove('read');
        item.classList.add('unread');
        if (markBtn) {
            markBtn.classList.remove('is-read');
            markBtn.setAttribute('title', 'Mark as read');
            markBtn.setAttribute('aria-label', 'Mark as read');
        }
        removeNotificationFromReadList(notifId);
    } else {
        // Mark as read
        item.classList.remove('unread');
        item.classList.add('read');
        if (markBtn) {
            markBtn.classList.add('is-read');
            markBtn.setAttribute('title', 'Mark as unread');
            markBtn.setAttribute('aria-label', 'Mark as unread');
        }
        addNotificationToReadList(notifId);
    }

    const remainingUnread = document.querySelectorAll('#notifListContainer .notif-item.unread').length;
    updateNotificationBadgeDisplay(remainingUnread);
}

// Mark all notifications as read
function markAllNotificationsRead(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const items = document.querySelectorAll('#notifListContainer .notif-item');
    const readIds = getStoredReadNotifications();

    items.forEach(function(item) {
        item.classList.remove('unread');
        item.classList.add('read');
        const markBtn = item.querySelector('.notif-mark-btn');
        if (markBtn) {
            markBtn.classList.add('is-read');
            markBtn.setAttribute('title', 'Mark as unread');
            markBtn.setAttribute('aria-label', 'Mark as unread');
        }
        const id = item.getAttribute('data-notif-id');
        if (id && !readIds.includes(id)) {
            readIds.push(id);
        }
    });

    saveReadNotifications(readIds);
    updateNotificationBadgeDisplay(0);
}

// Sync notification visual states from localStorage on DOMContentLoaded
function syncNotificationStateFromStorage() {
    try {
        // Clean up deprecated legacy flag if present
        if (localStorage.getItem('inventory_notifications_read')) {
            localStorage.removeItem('inventory_notifications_read');
        }
    } catch(err) {}

    const items = document.querySelectorAll('#notifListContainer .notif-item');
    if (!items.length) return;

    const readIds = getStoredReadNotifications();
    let unreadCount = 0;
    const activeDomIds = [];

    items.forEach(function(item) {
        const id = item.getAttribute('data-notif-id');
        if (id) {
            activeDomIds.push(id);
            if (readIds.includes(id)) {
                item.classList.remove('unread');
                item.classList.add('read');
                const markBtn = item.querySelector('.notif-mark-btn');
                if (markBtn) {
                    markBtn.classList.add('is-read');
                    markBtn.setAttribute('title', 'Mark as unread');
                    markBtn.setAttribute('aria-label', 'Mark as unread');
                }
            } else {
                item.classList.remove('read');
                item.classList.add('unread');
                unreadCount++;
            }
        }
    });

    // Prune stale IDs from storage if any alerts are no longer present
    if (activeDomIds.length > 0 && readIds.length > 0) {
        const pruned = readIds.filter(function(id) { return activeDomIds.includes(id); });
        if (pruned.length !== readIds.length) {
            saveReadNotifications(pruned);
        }
    }

    updateNotificationBadgeDisplay(unreadCount);
}

// Close notification dropdown when clicking outside
document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('headerNotificationWrapper');
    if (wrapper && wrapper.classList.contains('open')) {
        if (!e.target.closest('#headerNotificationWrapper')) {
            wrapper.classList.remove('open');
            const btn = document.getElementById('notificationBellBtn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }
    }
});

// Close notification dropdown on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const wrapper = document.getElementById('headerNotificationWrapper');
        if (wrapper && wrapper.classList.contains('open')) {
            wrapper.classList.remove('open');
            const btn = document.getElementById('notificationBellBtn');
            if (btn) {
                btn.setAttribute('aria-expanded', 'false');
                btn.focus();
            }
        }
    }

    // Keyboard navigation for notification filter tabs (Arrow keys, Home, End)
    const activeTab = document.activeElement;
    if (activeTab && activeTab.classList.contains('notif-tab')) {
        const tabs = Array.from(document.querySelectorAll('.notif-tabs .notif-tab'));
        const idx = tabs.indexOf(activeTab);
        if (idx !== -1) {
            let nextIdx = -1;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                nextIdx = (idx + 1) % tabs.length;
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                nextIdx = (idx - 1 + tabs.length) % tabs.length;
            } else if (e.key === 'Home') {
                nextIdx = 0;
            } else if (e.key === 'End') {
                nextIdx = tabs.length - 1;
            }
            if (nextIdx !== -1) {
                e.preventDefault();
                tabs[nextIdx].focus();
                tabs[nextIdx].click();
            }
        }
    }
});

// Web Audio API Synthesized Chime (Crisp 2-Tone Notification Chime)
function playNotificationChime(force) {
    try {
        if (!force) {
            // Check sound preferences
            const soundPref = localStorage.getItem('inventoryteam_pref_notif_sound');
            if (soundPref === '0') return;

            const focusOnlyPref = localStorage.getItem('inventoryteam_pref_sound_focus_only');
            if (focusOnlyPref !== '0' && (document.hidden || document.visibilityState === 'hidden')) {
                return;
            }
        }

        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;

        // F-43: Reuse singleton AudioContext to prevent browser 6-instance limit exhaustion
        if (!window._appAudioContext || window._appAudioContext.state === 'closed') {
            window._appAudioContext = new AudioContextClass();
        }
        const ctx = window._appAudioContext;
        if (ctx.state === 'suspended') {
            ctx.resume();
        }

        const now = ctx.currentTime;

        // Tone 1: D5 (587.33 Hz)
        const osc1 = ctx.createOscillator();
        const gain1 = ctx.createGain();
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(587.33, now);
        gain1.gain.setValueAtTime(0.18, now);
        gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
        osc1.connect(gain1);
        gain1.connect(ctx.destination);
        osc1.start(now);
        osc1.stop(now + 0.35);

        // Tone 2: A5 (880 Hz)
        const osc2 = ctx.createOscillator();
        const gain2 = ctx.createGain();
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(880, now + 0.12);
        gain2.gain.setValueAtTime(0.22, now + 0.12);
        gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.6);
        osc2.connect(gain2);
        gain2.connect(ctx.destination);
        osc2.start(now + 0.12);
        osc2.stop(now + 0.6);
    } catch (e) {
        // AudioContext could be blocked by browser autoplay policy before user interaction
    }
}

// Trigger Header Bell Icon Pulse
function pulseNotificationBell() {
    const bellBtn = document.getElementById('notificationBellBtn');
    if (bellBtn) {
        bellBtn.classList.remove('pulse');
        void bellBtn.offsetWidth; // Force CSS reflow
        bellBtn.classList.add('pulse');
        setTimeout(function() {
            bellBtn.classList.remove('pulse');
        }, 3600);
    }
}

// Synchronize Real-Time Dashboard KPI Cards (e.g. Low Stock Items count)
function updateDashboardKpi(kpi) {
    if (!kpi) return;
    const valLowStock = document.getElementById('dashboardValLowStock');
    const cardLowStock = document.getElementById('dashboardCardLowStock');
    const metaLowStock = document.getElementById('dashboardMetaLowStock');

    if (valLowStock && typeof kpi.low_stock_count !== 'undefined') {
        const count = parseInt(kpi.low_stock_count, 10);
        const oldCount = parseInt(valLowStock.textContent.trim(), 10) || 0;

        if (oldCount !== count) {
            valLowStock.textContent = count;
            valLowStock.classList.remove('stat-value-pulse');
            void valLowStock.offsetWidth; // Force CSS reflow
            valLowStock.classList.add('stat-value-pulse');
            setTimeout(function() {
                valLowStock.classList.remove('stat-value-pulse');
            }, 1200);

            if (cardLowStock) {
                if (count > 0) {
                    cardLowStock.classList.add('stat-alert');
                } else {
                    cardLowStock.classList.remove('stat-alert');
                }
            }

            if (metaLowStock) {
                if (count > 0) {
                    const baseUrl = (typeof APP_BASE_URL !== 'undefined' ? APP_BASE_URL : '');
                    metaLowStock.innerHTML = '<a href="' + baseUrl + 'views/inbound_outbound/index.php" style="color: var(--error); font-weight: 600; text-decoration: underline;">Replenish via Stock In &rarr;</a>';
                } else {
                    metaLowStock.textContent = 'All items at healthy levels';
                }
            }
        }
    }
}

// Dismiss a toast alert with fade/slide animation
function dismissNotificationToast(toast) {
    if (!toast) return;
    toast.classList.add('hiding');
    setTimeout(function() {
        if (toast && toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 280);
}

// Display real-time toast alert pop-up at bottom-right
function showNotificationToast(alert) {
    if (!alert || !alert.title) return;

    // Check toast alert preference
    const toastPref = localStorage.getItem('inventoryteam_pref_toast_alerts');
    const allowToast = (toastPref !== '0');

    // Trigger audio chime & bell pulse
    playNotificationChime(false);
    pulseNotificationBell();

    if (!allowToast) {
        return;
    }

    const container = document.getElementById('notifToastContainer') || (function() {
        const c = document.createElement('div');
        c.className = 'notif-toast-container';
        c.id = 'notifToastContainer';
        document.body.appendChild(c);
        return c;
    })();

    const toast = document.createElement('div');
    const allowedSevs = ['critical', 'warning', 'shield', 'message', 'info'];
    const sev = allowedSevs.indexOf(alert.severity) !== -1 ? alert.severity : 'info';
    toast.className = 'notif-toast';
    toast.setAttribute('data-toast-id', String(alert.id || ''));

    // Icon SVG based on severity
    let iconSvg = '';
    if (sev === 'critical') {
        iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    } else if (sev === 'warning') {
        iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    } else if (sev === 'shield') {
        iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>';
    } else if (sev === 'message') {
        iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    } else {
        iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
    }

    const escFn = window.escapeHtml || function(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };
    const safeTitle = escFn(alert.title);
    const safeMsg = escFn(alert.message);
    const rawLink = String(alert.link || '#').trim();
    const safeHref = (rawLink.indexOf('/') === 0 || rawLink.indexOf('http://') === 0 || rawLink.indexOf('https://') === 0 || rawLink.indexOf('#') === 0 || rawLink.indexOf('views/') === 0)
        ? rawLink
        : '#';

    toast.innerHTML = `
        <div class="notif-toast-icon ${sev}">
            ${iconSvg}
        </div>
        <div class="notif-toast-body">
            <div class="notif-toast-title">${safeTitle}</div>
            <div class="notif-toast-desc">${safeMsg}</div>
        </div>
        <div class="notif-toast-actions">
            <a href="#" class="notif-toast-btn">View</a>
            <button type="button" class="notif-toast-close" title="Dismiss" aria-label="Dismiss">&times;</button>
        </div>
        <div class="notif-toast-progress"></div>
    `;

    const viewBtn = toast.querySelector('.notif-toast-btn');
    if (viewBtn) {
        viewBtn.setAttribute('href', safeHref);
        viewBtn.addEventListener('click', function() {
            addNotificationToReadList(String(alert.id || ''));
        });
    }

    const closeBtn = toast.querySelector('.notif-toast-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            dismissNotificationToast(toast);
        });
    }

    container.appendChild(toast);

    // Auto dismiss after 6 seconds
    setTimeout(function() {
        dismissNotificationToast(toast);
    }, 6000);
}

// Background Polling Engine for Real-Time Header Notifications
let lastKnownNotifId = '';
let notifPollInterval = null;

function initNotificationPolling() {
    const firstItem = document.querySelector('#notifListContainer .notif-item');
    if (firstItem) {
        lastKnownNotifId = firstItem.getAttribute('data-notif-id') || '';
    }

    // Run periodic poll every 25 seconds
    if (notifPollInterval) clearInterval(notifPollInterval);
    notifPollInterval = setInterval(pollNotifications, 25000);
}

function pollNotifications() {
    // Skip if page is running in background or minimized
    if (document.hidden || document.visibilityState === 'hidden') {
        return;
    }

    const pollUrl = (typeof APP_BASE_URL !== 'undefined' ? APP_BASE_URL : '') + 'api/inventory/notifications_poll.php' +
        '?last_known_id=' + encodeURIComponent(lastKnownNotifId);

    fetch(pollUrl, { credentials: 'same-origin' })
        .then(function(res) {
            if (!res.ok) throw new Error('Poll failed: ' + res.status);
            return res.json();
        })
        .then(function(data) {
            if (!data || !data.success) return;

            // Sync unread badge count
            updateNotificationBadgeDisplay(data.unread_count);

            // Sync tab counts
            const tabUnread = document.getElementById('tabCountUnread');
            if (tabUnread && typeof data.unread_count !== 'undefined') tabUnread.textContent = data.unread_count;

            const tabAll = document.getElementById('tabCountAll');
            if (tabAll && typeof data.total_count !== 'undefined') tabAll.textContent = data.total_count;

            const tabStock = document.getElementById('tabCountStock');
            if (tabStock && typeof data.total_stock_count !== 'undefined') tabStock.textContent = data.total_stock_count;

            const tabMsg = document.getElementById('tabCountMessages');
            if (tabMsg && typeof data.total_message_count !== 'undefined') tabMsg.textContent = data.total_message_count;

            // Sync Dashboard KPI cards live if present on page
            if (data.kpi) {
                updateDashboardKpi(data.kpi);
            }

            // If brand-new alert arrived, display floating toast alert
            if (data.has_new_alert && data.new_alert) {
                showNotificationToast(data.new_alert);
                lastKnownNotifId = data.latest_id || data.new_alert.id;
            } else if (data.latest_id) {
                lastKnownNotifId = data.latest_id;
            }
        })
        .catch(function() {
            // Silently absorb polling network hiccups
        });
}

// Sync notifications on DOM load
document.addEventListener('DOMContentLoaded', function() {
    syncNotificationStateFromStorage();
    initNotificationPolling();
});

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

// Shared HTML-escaping helper (delegates to StockPilot utility in stockpilot_utils.js)
window.escapeHtml = window.escapeHtml || (window.StockPilot && window.StockPilot.escapeHtml);

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
            // F-29: Prepend ' to prevent CSV formula injection if cell begins with =, +, -, @, tab, or CR
            if (/^[=+\-@\t\r]/.test(text)) {
                text = "'" + text;
            }
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
    const blobUrl = window.URL.createObjectURL(csvFile);
    downloadLink.href = blobUrl;
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
    setTimeout(() => {
        if (window.URL && typeof window.URL.revokeObjectURL === 'function') {
            window.URL.revokeObjectURL(blobUrl);
        }
    }, 1500);
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

// Universal Flash Alert Dismissal Engine with Smooth Fade & Collapse
function dismissFlashAlert(alertEl) {
    if (!alertEl || alertEl.dataset.dismissing === 'true') return;
    alertEl.dataset.dismissing = 'true';

    alertEl.style.transition = 'opacity 0.4s ease, max-height 0.4s ease, margin 0.4s ease, padding 0.4s ease';
    alertEl.style.overflow = 'hidden';
    alertEl.style.maxHeight = alertEl.scrollHeight + 'px';

    // Force repaint before animating out
    void alertEl.offsetHeight;

    alertEl.style.opacity = '0';
    alertEl.style.maxHeight = '0px';
    alertEl.style.marginTop = '0px';
    alertEl.style.marginBottom = '0px';
    alertEl.style.paddingTop = '0px';
    alertEl.style.paddingBottom = '0px';

    setTimeout(() => {
        if (alertEl.parentNode) {
            alertEl.remove();
        }
    }, 450);
}
window.dismissFlashAlert = dismissFlashAlert;

// Auto-initialize pagination & sorting on all data tables, plus global modal Escape & Focus Trap
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss all Throwable / Error & Flash Alert banners after 5 seconds
    function attachAlertAutoDismiss(alert) {
        if (!alert || alert.dataset.autoDismissAttached) return;
        alert.dataset.autoDismissAttached = 'true';

        let timer = setTimeout(() => {
            dismissFlashAlert(alert);
        }, 5000);

        // Pause countdown if user hovers to read the message; resume when mouse leaves
        alert.addEventListener('mouseenter', () => {
            if (timer) clearTimeout(timer);
        });
        alert.addEventListener('mouseleave', () => {
            if (alert.dataset.dismissing !== 'true') {
                timer = setTimeout(() => {
                    dismissFlashAlert(alert);
                }, 2000);
            }
        });
    }

    // F-28: Restrict auto-dismiss strictly to explicit .flash-alert banners not marked data-persistent.
    // Never auto-remove modal feedback banners (e.g. #notifFeedbackBanner, #securityFeedbackBanner, #supportFeedbackBanner).
    const initialAlerts = document.querySelectorAll(
        '.flash-alert:not([data-persistent]):not([role="status"])'
    );
    initialAlerts.forEach(attachAlertAutoDismiss);

    // Also observe dynamically injected alerts (e.g. AJAX or dynamic error popups)
    if (window.MutationObserver) {
        const alertObserver = new MutationObserver(mutations => {
            mutations.forEach(m => {
                m.addedNodes.forEach(node => {
                    if (node.nodeType === 1) { // Node.ELEMENT_NODE
                        if (node.matches && node.matches('.flash-alert:not([data-persistent]):not([role="status"])') && !node.closest('.modal, .modal-backdrop, .settings-modal-backdrop')) {
                            attachAlertAutoDismiss(node);
                        }
                        if (node.querySelectorAll) {
                            node.querySelectorAll('.flash-alert:not([data-persistent]):not([role="status"])').forEach(el => {
                                if (!el.closest('.modal, .modal-backdrop, .settings-modal-backdrop')) {
                                    attachAlertAutoDismiss(el);
                                }
                            });
                        }
                    }
                });
            });
        });
        alertObserver.observe(document.body, { childList: true, subtree: true });
    }

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
<!-- F-38: Real-Time Toast Alert Container at root body level (escapes page-header stacking trap) -->
<div class="notif-toast-container" id="notifToastContainer" aria-live="polite" aria-atomic="true"></div>
</body>
</html>
