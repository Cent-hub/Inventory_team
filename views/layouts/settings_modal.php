<?php
/**
 * Layout Component: iOS-Inspired Grouped Settings Modal
 * StockPilot — Liquor Business Inventory Management System
 * 
 * Follows the clean inset-group visual structure of modern iOS settings,
 * faithfully adapted to the StockPilot deep navy, teal, and bronze palette.
 */

$currentUser = $currentUser ?? ($auth ? $auth->getCurrentUser() : []);
$currentWarehouseCode = $assignedWarehouse['warehouse_code'] ?? 'WH-MAIN';
$currentWarehouseName = $assignedWarehouse['warehouse_name'] ?? 'Main Warehouse';
$currentBranchLabel = htmlspecialchars($currentWarehouseCode . ' · ' . $currentWarehouseName);
$userRoleDisplay = htmlspecialchars(ucfirst(str_replace('_', ' ', $currentUser['role'] ?? 'admin')));
$userNameDisplay = htmlspecialchars($currentUser['name'] ?? 'Administrator');
$userEmailDisplay = htmlspecialchars($currentUser['email'] ?? 'admin@inventory.local');
?>

<!-- Settings Master Modal -->
<div id="settingsModal" class="settings-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="settingsModalTitle" onclick="if(event.target === this) closeSettingsModal()">
    <div class="settings-modal-card">
        
        <!-- Dynamic Modal Header with Back Button and Close Button -->
        <div class="settings-modal-header">
            <div class="settings-modal-title-wrap">
                <button type="button" id="settingsBtnBack" class="settings-btn-back" style="display: none;" onclick="switchSettingsView('main')" aria-label="Back to Settings">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                    <span>Settings</span>
                </button>
                <h3 id="settingsModalTitle" class="settings-modal-title">Settings</h3>
            </div>
            <button type="button" class="settings-btn-close" onclick="closeSettingsModal()" aria-label="Close settings">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <!-- Scrollable Modal Canvas Body -->
        <div class="settings-modal-body">

            <!-- ========================================================== -->
            <!-- VIEW: MAIN SETTINGS OVERVIEW (iOS Grouped Layout)         -->
            <!-- ========================================================== -->
            <div id="view-settings-main" class="settings-view active">
                
                <!-- Group 1: Profile & Identity (Matches Reference Top Card) -->
                <div class="settings-group">
                    <div class="settings-row" onclick="switchSettingsView('account')" role="button" tabindex="0" title="Manage Account Profile">
                        <div class="settings-row-left">
                            <div class="user-avatar" style="width: 46px; height: 46px; font-size: 18px; background: #14213D; color: #FFFFFF; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                                <?= strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)) ?>
                            </div>
                            <div>
                                <div style="font-size: 15px; font-weight: 700; color: var(--panel-ink); line-height: 1.2;">
                                    <?= $userNameDisplay ?>
                                </div>
                                <div class="settings-row-subtitle">
                                    <?= $userRoleDisplay ?> &middot; <?= $userEmailDisplay ?>
                                </div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>
                    <div class="settings-divider"></div>
                    <div class="settings-row" onclick="switchSettingsView('account')" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle teal" style="width: 28px; height: 28px; border-radius: 7px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                                    <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                                    <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Assigned Branch</div>
                                <div class="settings-row-subtitle"><?= $currentBranchLabel ?></div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-badge teal" style="font-size: 11px; padding: 2px 8px;">Active</span>
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-round="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Group 2: Core Submenus (Inset Group with Squircle Icons) -->
                <div class="settings-group">
                    
                    <!-- 1. My Account -->
                    <div class="settings-row" onclick="switchSettingsView('account')" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle teal">
                                <!-- Lucide User Icon -->
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">My Account</div>
                                <div class="settings-row-subtitle">Profile details, facility &amp; credentials</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value"><?= $userRoleDisplay ?></span>
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- 2. Change Password -->
                    <div class="settings-row" onclick="switchSettingsView('password')" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle bronze">
                                <!-- Lucide Key Icon -->
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="7.5" cy="15.5" r="5.5"/>
                                    <path d="m21 2-9.6 9.6"/>
                                    <path d="m15.5 7.5 3 3L22 7l-3-3"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Change Password</div>
                                <div class="settings-row-subtitle">Update password &amp; PIN recovery</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- 3. Notifications -->
                    <div class="settings-row" onclick="switchSettingsView('notifications')" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle amber">
                                <!-- Lucide Bell Icon -->
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
                                    <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Notifications</div>
                                <div class="settings-row-subtitle">Low-stock replenishment alerts</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-badge amber">Live</span>
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- 4. Security -->
                    <div class="settings-row" onclick="switchSettingsView('security')" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle navy">
                                <!-- Lucide ShieldCheck Icon -->
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                    <path d="m9 12 2 2 4-4"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Security</div>
                                <div class="settings-row-subtitle">Session Guard, audit &amp; rate limiter</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value" style="color: #15803D; font-weight: 500;">Guarded</span>
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- 5. Backup & Export -->
                    <div class="settings-row" onclick="switchSettingsView('backup')" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle green">
                                <!-- Lucide Download Icon -->
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <polyline points="7 10 12 15 17 10"/>
                                    <line x1="12" y1="15" x2="12" y2="3"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Backup &amp; Export</div>
                                <div class="settings-row-subtitle">Warehouse CSV reports &amp; ledgers</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value"><?= htmlspecialchars($currentWarehouseCode) ?></span>
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

                </div>

                <!-- Group 3: System & Security Status -->
                <div class="settings-group">
                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle blue" style="width: 28px; height: 28px; border-radius: 7px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="16" x2="12" y2="12"/>
                                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">StockPilot Liquor Inventory</div>
                                <div class="settings-row-subtitle">System Version 2.4.0 (Enterprise)</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value">Online</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ========================================================== -->
            <!-- VIEW: SECTION 1 — MY ACCOUNT                               -->
            <!-- ========================================================== -->
            <div id="view-settings-account" class="settings-view">
                
                <div class="settings-group">
                    <div style="padding: 20px 16px; display: flex; align-items: center; gap: 16px; background: #ffffff;">
                        <div class="user-avatar" style="width: 58px; height: 58px; font-size: 24px; background: #14213D; color: #FFFFFF; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                            <?= strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)) ?>
                        </div>
                        <div>
                            <div style="font-size: 17px; font-weight: 700; color: var(--panel-ink); margin-bottom: 2px;">
                                <?= $userNameDisplay ?>
                            </div>
                            <div style="font-size: 13px; color: var(--gray); font-family: monospace;">
                                <?= $userEmailDisplay ?>
                            </div>
                            <div style="margin-top: 6px; display: flex; align-items: center; gap: 6px;">
                                <span class="badge badge-teal" style="font-size: 11px; padding: 2px 8px;">
                                    <?= $userRoleDisplay ?>
                                </span>
                                <span class="badge badge-navy" style="font-size: 11px; padding: 2px 8px;">
                                    ID: #<?= (int)($currentUser['id'] ?? 1) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle teal">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                                    <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                                    <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Warehouse</div>
                                <div class="settings-row-subtitle"><?= $currentBranchLabel ?></div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-badge teal">Authorized</span>
                        </div>
                    </div>
                    <div class="settings-divider"></div>
                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle navy">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect width="18" height="18" x="3" y="3" rx="2"/>
                                    <path d="M7 7h10M7 12h10M7 17h10"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Assigned Team</div>
                                <div class="settings-row-subtitle">Distillery &amp; Inventory Operations</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value">Internal</span>
                        </div>
                    </div>
                    <div class="settings-divider"></div>
                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle bronze">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 16 14"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Active Session</div>
                                <div class="settings-row-subtitle">Authenticated via Secure Session Guard</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value" style="color: #15803D;">Active</span>
                        </div>
                    </div>
                </div>

               
            </div>

            <!-- ========================================================== -->
            <!-- VIEW: SECTION 2 — CHANGE PASSWORD                          -->
            <!-- ========================================================== -->
            <div id="view-settings-password" class="settings-view">
                
                <div class="settings-group">
                    <form onsubmit="event.preventDefault(); alert('Password change request simulated. Use OTP password reset for credential recovery.');" style="padding: 16px; display: flex; flex-direction: column; gap: 14px;">
                        <div>
                            <label style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">Current Password</label>
                            <input type="password" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" placeholder="Enter current password" required>
                        </div>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">New Password</label>
                            <input type="password" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" placeholder="Minimum 6 characters" minlength="6" required>
                        </div>
                        <div>
                            <label style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">Confirm New Password</label>
                            <input type="password" class="search-box" style="width: 100%; height: 40px; border-radius: 8px;" placeholder="Re-enter new password" minlength="6" required>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px;">
                            <button type="submit" class="btn btn-primary btn-sm" style="height: 36px; padding: 0 16px;">
                                <span>Update Password</span>
                            </button>
                        </div>
                    </form>
                </div>

                <div class="settings-group">
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/auth/reset-password.php'" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle bronze">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Forgot or Lost Password?</div>
                                <div class="settings-row-subtitle">Open 3-step OTP password reset portal</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ========================================================== -->
            <!-- VIEW: SECTION 3 — NOTIFICATIONS (With iOS Toggles)         -->
            <!-- ========================================================== -->
            <div id="view-settings-notifications" class="settings-view">
                
                <span class="settings-group-label">Replenishment &amp; Movement Alerts</span>
                <div class="settings-group">
                    
                    <!-- Row 1: Low Stock Alerts -->
                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle amber">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                                    <line x1="12" y1="9" x2="12" y2="13"/>
                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Low Stock Alerts</div>
                                <div class="settings-row-subtitle">Trigger alert when below reorder level</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <label class="ios-switch">
                                <input type="checkbox" checked onchange="toggleNotificationFeedback(this, 'Low Stock Alerts')">
                                <span class="ios-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- Row 2: Inbound Dispatches -->
                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle teal">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <polyline points="7 10 12 15 17 10"/>
                                    <line x1="12" y1="15" x2="12" y2="3"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Inbound Receipts (Stock In)</div>
                                <div class="settings-row-subtitle">Procurement deliveries &amp; returns</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <label class="ios-switch">
                                <input type="checkbox" checked onchange="toggleNotificationFeedback(this, 'Inbound Receipts')">
                                <span class="ios-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- Row 3: Outbound Alerts -->
                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle rose">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <polyline points="17 8 12 3 7 8"/>
                                    <line x1="12" y1="3" x2="12" y2="15"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">High Outbound Dispatches</div>
                                <div class="settings-row-subtitle">Sales delivery &amp; materials issued</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <label class="ios-switch">
                                <input type="checkbox" checked onchange="toggleNotificationFeedback(this, 'Outbound Dispatches')">
                                <span class="ios-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- Row 4: Daily Stock Balance Digest -->
                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle blue">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <line x1="16" y1="13" x2="8" y2="13"/>
                                    <line x1="16" y1="17" x2="8" y2="17"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Daily Stock Ledger Digest</div>
                                <div class="settings-row-subtitle">End-of-shift balance summary</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <label class="ios-switch">
                                <input type="checkbox" onchange="toggleNotificationFeedback(this, 'Daily Digest')">
                                <span class="ios-slider"></span>
                            </label>
                        </div>
                    </div>

                </div>

                <div class="settings-group">
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/dashboard/index.php#notifications'" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle teal">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Inspect Live Dashboard Alerts</div>
                                <div class="settings-row-subtitle">Scoped to <?= $currentBranchLabel ?></div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ========================================================== -->
            <!-- VIEW: SECTION 4 — SECURITY                                 -->
            <!-- ========================================================== -->
            <div id="view-settings-security" class="settings-view">
                
                <span class="settings-group-label">Active Session &amp; Defenses</span>
                <div class="settings-group">
                    
                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle navy">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                    <path d="m9 12 2 2 4-4"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Secure Session Guard</div>
                                <div class="settings-row-subtitle">HTTPOnly, SameSite=Lax &amp; Session Fixation Armor</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-badge teal">Enforced</span>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle teal">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect width="18" height="18" x="3" y="3" rx="2"/>
                                    <path d="M3 9h18M9 21V9"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Cache Snooping Barrier</div>
                                <div class="settings-row-subtitle">No-store / no-cache headers on authenticated pages</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-badge teal">Active</span>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle bronze">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="6" x2="12" y2="12"/>
                                    <line x1="12" y1="12" x2="16" y2="14"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Rate Limiting Defense</div>
                                <div class="settings-row-subtitle">Max 3 attempts per 300s window on auth endpoints</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value">3 attempts / 5m</span>
                        </div>
                    </div>

                </div>

                <span class="settings-group-label">Credentials &amp; Access Controls</span>
                <div class="settings-group">
                    
                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle purple">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Two-Factor Authentication (2FA)</div>
                                <div class="settings-row-subtitle">Require OTP verification upon signing in</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <label class="ios-switch">
                                <input type="checkbox" checked onchange="toggleNotificationFeedback(this, '2FA Authentication')">
                                <span class="ios-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <div class="settings-row static">
                        <div class="settings-row-left">
                            <div class="settings-squircle blue">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect width="20" height="14" x="2" y="5" rx="2"/>
                                    <line x1="2" y1="10" x2="22" y2="10"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Current Client IP</div>
                                <div class="settings-row-subtitle">Authenticated terminal network address</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value" style="font-family: monospace; font-size: 12px;">
                                <?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?>
                            </span>
                        </div>
                    </div>

                </div>

            </div>

            <!-- ========================================================== -->
            <!-- VIEW: SECTION 5 — BACKUP & EXPORT                           -->
            <!-- ========================================================== -->
            <div id="view-settings-backup" class="settings-view">
                
                <!-- Warehouse Access Restriction Notice -->
                <div style="background: rgba(31, 122, 108, 0.08); border: 1px solid var(--accent-border); border-radius: 12px; padding: 12px 16px; display: flex; align-items: flex-start; gap: 10px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent); flex-shrink: 0; margin-top: 1px;">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="16" x2="12" y2="12"/>
                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                    </svg>
                    <div style="font-size: 12.5px; color: var(--panel-ink); line-height: 1.4;">
                        <strong>Warehouse Isolation Enforced:</strong> All reports and ledger exports below are strictly bounded to your assigned warehouse <strong><?= $currentBranchLabel ?></strong>.
                    </div>
                </div>

                <span class="settings-group-label">One-Click CSV Ledger Exports</span>
                <div class="settings-group">
                    
                    <!-- 1. Current Stock -->
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/reports/index.php?type=current_stock'" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle teal">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l6 3.43a2 2 0 0 0 2.06 0l6-3.43a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71l-6-3.43a2 2 0 0 0-2.06 0l-6 3.43Z"/>
                                    <path d="m12 11.5 6.63-3.79M12 11.5-6.63-3.79M12 11.5v7"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Current Inventory Balance</div>
                                <div class="settings-row-subtitle">Full stock roster &amp; reorder levels</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value">CSV</span>
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- 2. Raw Materials -->
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/reports/index.php?type=raw_materials'" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle bronze">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Raw Materials Ledger</div>
                                <div class="settings-row-subtitle">Grains, hops, botanicals &amp; barrels</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value">CSV</span>
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- 3. Finished Goods -->
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/reports/index.php?type=finished_goods'" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle green">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect width="18" height="18" x="3" y="3" rx="2"/>
                                    <path d="M3 9h18"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Finished Goods Ledger</div>
                                <div class="settings-row-subtitle">Bottled liquor, packaging &amp; ready inventory</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value">CSV</span>
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- 4. Stock Movements -->
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/reports/index.php?type=stock_movements'" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle blue">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Stock Movements Audit Ledger</div>
                                <div class="settings-row-subtitle">Chronological ledger of receipts &amp; issues</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value">CSV</span>
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

                </div>

                <div style="display: flex; justify-content: flex-end;">
                    <a href="<?= BASE_URL ?>views/reports/index.php" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                        <span>Open Comprehensive Reports Suite</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </a>
                </div>

            </div>

        </div><!-- /.settings-modal-body -->

        <!-- Modal Footer -->
        <div class="settings-modal-header" style="background: #F8FAFC; border-top: 1px solid var(--border); border-bottom: none; padding: 12px 20px;">
            <span style="font-size: 12px; color: var(--gray);">
                StockPilot Security Protocol active
            </span>
            <button type="button" onclick="closeSettingsModal()" style="background: #ffffff; border: 1px solid #CBD5E1; color: #0F172A; font-size: 13px; font-weight: 600; padding: 5px 18px; border-radius: 20px; cursor: pointer; transition: all 0.15s ease;" onmouseover="this.style.background='#F1F5F9'" onmouseout="this.style.background='#ffffff'">
                Close
            </button>
        </div>

    </div>
</div>

<script>
/**
 * Settings Modal Navigation Controller
 */
function openSettingsModal(section = 'main') {
    const modal = document.getElementById('settingsModal');
    if (!modal) return;
    
    // Close mobile sidebar if open
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar && sidebar.classList.contains('open')) {
        sidebar.classList.remove('open');
    }
    if (backdrop && backdrop.classList.contains('active')) {
        backdrop.classList.remove('active');
    }

    switchSettingsView(section);
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeSettingsModal() {
    const modal = document.getElementById('settingsModal');
    if (!modal) return;
    
    modal.classList.remove('open');
    document.body.style.overflow = '';
}

function switchSettingsView(sectionId) {
    const views = {
        'main':          { id: 'view-settings-main',          title: 'Settings',          showBack: false },
        'account':       { id: 'view-settings-account',       title: 'My Account',        showBack: true  },
        'password':      { id: 'view-settings-password',      title: 'Change Password',   showBack: true  },
        'notifications': { id: 'view-settings-notifications', title: 'Notifications',     showBack: true  },
        'security':      { id: 'view-settings-security',      title: 'Security',          showBack: true  },
        'backup':        { id: 'view-settings-backup',        title: 'Backup & Export',   showBack: true  }
    };

    const target = views[sectionId] || views['main'];

    // Hide all views
    document.querySelectorAll('.settings-view').forEach(v => v.classList.remove('active'));

    // Show target view
    const targetEl = document.getElementById(target.id);
    if (targetEl) {
        targetEl.classList.add('active');
    }

    // Update title
    const titleEl = document.getElementById('settingsModalTitle');
    if (titleEl) {
        titleEl.textContent = target.title;
    }

    // Toggle Back Button
    const backBtn = document.getElementById('settingsBtnBack');
    if (backBtn) {
        backBtn.style.display = target.showBack ? 'inline-flex' : 'none';
    }

    // Scroll body back to top
    const modalBody = document.querySelector('.settings-modal-body');
    if (modalBody) {
        modalBody.scrollTop = 0;
    }
}

function toggleNotificationFeedback(checkbox, label) {
    const status = checkbox.checked ? 'enabled' : 'disabled';
    console.log(`Notification preference for "${label}": ${status}`);
}

// Global escape key listener to dismiss Settings modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('settingsModal');
        if (modal && modal.classList.contains('open')) {
            closeSettingsModal();
        }
    }
});

// Auto-open settings modal if URL hash or query parameter requests it
document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash;
    const urlParams = new URLSearchParams(window.location.search);
    
    if (hash === '#settings' || urlParams.get('settings') === '1' || urlParams.get('modal') === 'settings') {
        const section = urlParams.get('section') || 'main';
        openSettingsModal(section);
    } else if (hash === '#settings-account' || hash === '#account') {
        openSettingsModal('account');
    } else if (hash === '#settings-password' || hash === '#password') {
        openSettingsModal('password');
    } else if (hash === '#settings-notifications' || hash === '#notifications') {
        openSettingsModal('notifications');
    } else if (hash === '#settings-security' || hash === '#security') {
        openSettingsModal('security');
    } else if (hash === '#settings-backup' || hash === '#backup') {
        openSettingsModal('backup');
    }
});
</script>
