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

                    <div class="settings-divider"></div>

                    <!-- 6. Accountability -->
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/settings/accountability.php'" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle navy">
                                <!-- Lucide ClipboardList / History Icon -->
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                                    <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                                    <path d="m9 14 2 2 4-4"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Accountability Log</div>
                                <div class="settings-row-subtitle">Cross-team activity, operators &amp; warehouse trail</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-badge" style="background: #1F7A6C;">Whole Page</span>
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

                    <div class="settings-divider"></div>

                    <!-- 7. Contact Support -->
                    <div class="settings-row" onclick="switchSettingsView('contact_support')" role="button" tabindex="0">
                        <div class="settings-row-left">
                            <div class="settings-squircle purple">
                                <!-- Lucide MessageSquare / Headset Icon -->
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                                    <line x1="8" y1="10" x2="16" y2="10"/>
                                    <line x1="8" y1="14" x2="13" y2="14"/>
                                </svg>
                            </div>
                            <div>
                                <div class="settings-row-title">Contact Support</div>
                                <div class="settings-row-subtitle">Compose inquiry or message to Super Admin</div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-badge" style="background: #7C3AED;">Direct</span>
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

            <!-- ========================================================== -->
            <!-- VIEW: SECTION 6 — CONTACT SUPPORT (Message to Super Admin) -->
            <!-- ========================================================== -->
            <div id="view-settings-contact-support" class="settings-view">
                
                <!-- Info Capsule -->
                <div style="background: rgba(124, 58, 237, 0.07); border: 1px solid rgba(124, 58, 237, 0.25); border-radius: 12px; padding: 14px 16px; display: flex; align-items: flex-start; gap: 12px;">
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: #7C3AED; color: #ffffff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            <line x1="8" y1="10" x2="16" y2="10"/>
                            <line x1="8" y1="14" x2="13" y2="14"/>
                        </svg>
                    </div>
                    <div style="font-size: 12.5px; color: var(--panel-ink); line-height: 1.45;">
                        <strong>Direct Admin Support Channel:</strong> Compose and submit inquiries directly to the <strong>Super Admin</strong> for inventory discrepancies, permission adjustments, or operational inquiries.
                    </div>
                </div>

                <!-- Feedback Banner (shown upon demo submission) -->
                <div id="supportFeedbackBanner" style="display: none; background: var(--success-light); border: 1px solid var(--success-border); border-radius: 10px; padding: 12px 16px; align-items: center; gap: 10px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--success); flex-shrink: 0;">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <div style="font-size: 13px; color: #14532D; font-weight: 500;">
                        Your message has been composed and simulated to the <strong>Super Admin</strong>. (UI Simulation Mode)
                    </div>
                </div>

                <!-- Compose Message Panel -->
                <div class="settings-group">
                    <form id="contactSupportForm" onsubmit="event.preventDefault(); handleSimulateSupportSend();" style="padding: 18px; display: flex; flex-direction: column; gap: 14px;">
                        
                        <!-- Recipient Field (Fixed to Super Admin) -->
                        <div>
                            <label style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">
                                Recipient
                            </label>
                            <div class="support-recipient-capsule">
                                <div style="display: flex; align-items: center; gap: 8px; flex: 1;">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--gray);">
                                        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                    <span style="font-weight: 600; color: var(--panel-ink);">Super Admin</span>
                                    <span style="font-size: 12px; color: var(--gray);">&lt;superadmin@centhub.local&gt;</span>
                                </div>
                                <span class="support-recipient-badge">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                    </svg>
                                    Super Admin
                                </span>
                            </div>
                            <small style="font-size: 11.5px; color: var(--gray); margin-top: 4px; display: block;">Recipient is automatically routed to the system-wide Super Administrator.</small>
                        </div>

                        <!-- Subject Field -->
                        <div>
                            <label for="supportSubject" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">
                                Subject <span style="color: var(--error);">*</span>
                            </label>
                            <input type="text" id="supportSubject" class="search-box" style="width: 100%; height: 40px; padding: 0 14px; border-radius: 8px;" placeholder="e.g., Stock In Request Discrepancy — Main Warehouse" required>
                        </div>

                        <!-- Inquiry Category -->
                        <div>
                            <label for="supportCategory" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">
                                Inquiry Category
                            </label>
                            <select id="supportCategory" class="select-filter" style="width: 100%; height: 40px; border-radius: 8px;">
                                <option value="discrepancy">Stock Discrepancy / Cycle Count Dispute</option>
                                <option value="requisition">Procurement &amp; Inbound Requisition</option>
                                <option value="transfer">Inter-Warehouse Movement Assistance</option>
                                <option value="access">Account Permissions &amp; Facility Access</option>
                                <option value="general" selected>General System Inquiry</option>
                            </select>
                        </div>

                        <!-- Message Body -->
                        <div>
                            <label for="supportMessage" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">
                                Message <span style="color: var(--error);">*</span>
                            </label>
                            <textarea id="supportMessage" rows="5" class="search-box" style="width: 100%; height: auto; min-height: 110px; padding: 10px 14px; border-radius: 8px; resize: vertical; line-height: 1.45;" placeholder="Describe your inquiry, affected raw materials, finished goods, or warehouse details..." required></textarea>
                        </div>

                        <!-- Buttons: Send Message + Cancel/Close -->
                        <div style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 6px; padding-top: 12px; border-top: 1px solid var(--border);">
                            <button type="button" class="btn btn-secondary" onclick="switchSettingsView('main')" style="height: 38px; padding: 0 16px;">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" style="height: 38px; padding: 0 20px; background: #7C3AED; border-color: #7C3AED;">
                                <!-- Lucide Send Icon -->
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="22" y1="2" x2="11" y2="13"/>
                                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                                </svg>
                                <span>Send Message</span>
                            </button>
                        </div>

                    </form>
                </div>

            </div>

            <!-- ========================================================== -->
            <!-- VIEW: SECTION 7 — ACCOUNTABILITY (Cross-Team Audit Ledger)  -->
            <!-- ========================================================== -->
            <div id="view-settings-accountability" class="settings-view">
                
                <!-- Accountability Description & Isolation Banner -->
                <div style="background: rgba(20, 33, 61, 0.04); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: flex-start; gap: 12px;">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: #14213D; color: #ffffff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px;">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                                <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                                <path d="m9 14 2 2 4-4"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size: 13.5px; font-weight: 700; color: var(--panel-ink);">
                                Accountability Audit Log
                            </div>
                            <div style="font-size: 12px; color: var(--gray); line-height: 1.4; margin-top: 2px;">
                                Tracks who performed inventory actions, what materials were touched, quantities affected, and warehouse destinations across <strong>Procurement, Production, Sales, and Inventory</strong>.
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <a href="<?= BASE_URL ?>views/settings/accountability.php" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; height: 28px; padding: 0 10px; text-decoration: none;">
                            <span>Open Whole Page</span>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                <polyline points="15 3 21 3 21 9"/>
                                <line x1="10" y1="14" x2="21" y2="3"/>
                            </svg>
                        </a>
                        <span class="settings-badge teal" style="font-size: 11px; padding: 3px 9px;">UI Demo Data</span>
                    </div>
                </div>

                <!-- Filter & Search Toolbar -->
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 240px;">
                        <div class="search-wrap" style="width: 100%; max-width: 280px;">
                            <span class="search-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"/>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                                </svg>
                            </span>
                            <input type="text" id="accountabilitySearch" class="search-box" style="height: 36px; font-size: 12.5px;" placeholder="Search user, item, warehouse..." oninput="filterAccountabilityTable()">
                        </div>
                        <select id="accountabilityTeamFilter" class="select-filter" style="height: 36px; font-size: 12.5px; min-width: 140px;" onchange="filterAccountabilityTable()">
                            <option value="all">All Teams</option>
                            <option value="Procurement">Procurement</option>
                            <option value="Production">Production</option>
                            <option value="Sales">Sales</option>
                            <option value="Inventory">Inventory</option>
                        </select>
                    </div>
                    <span id="accountabilityEntryCount" style="font-size: 12px; color: var(--gray); font-weight: 500;">
                        Showing 7 log entries
                    </span>
                </div>

                <!-- Accountability Table Container with Scrolling -->
                <div class="table-responsive" style="box-shadow: 0 1px 3px rgba(0,0,0,0.03); max-height: 480px; overflow-y: auto;">
                    <table id="accountabilityTable" style="margin: 0; min-width: 780px;">
                        <thead>
                            <tr style="position: sticky; top: 0; z-index: 2; background: #F8FAFC;">
                                <th style="min-width: 155px;">Date &amp; Time</th>
                                <th style="min-width: 140px;">User</th>
                                <th style="min-width: 110px;">Team</th>
                                <th style="min-width: 185px;">Action</th>
                                <th style="min-width: 160px;">Raw Material / Item</th>
                                <th style="min-width: 95px; text-align: right;">Quantity</th>
                                <th style="min-width: 140px;">Warehouse</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- 1. Procurement (Potatoes) -->
                            <tr data-team="Procurement">
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                    <span style="font-weight: 600; color: var(--panel-ink);">Sept 19, 2026</span><br>
                                    <span style="font-size: 11px;">09:15 AM</span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 26px; height: 26px; border-radius: 50%; background: #FEF3C7; color: #92400E; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                            JD
                                        </div>
                                        <span style="font-weight: 600; font-size: 13px; color: var(--panel-ink);">Juan Dela Cruz</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-team procurement">Procurement</span>
                                </td>
                                <td>
                                    <span class="badge-action inbound">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="12" y1="5" x2="12" y2="19"/>
                                            <polyline points="19 12 12 19 5 12"/>
                                        </svg>
                                        Stock In Request
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: var(--panel-ink);">Potatoes</strong>
                                    <div style="font-size: 11px; color: var(--gray);">Raw Material &middot; RM-POT-01</div>
                                </td>
                                <td style="text-align: right;">
                                    <span style="font-weight: 700; font-size: 13.5px; color: var(--panel-ink);">50</span>
                                    <small style="color: var(--gray); font-weight: 500;">kg</small>
                                </td>
                                <td>
                                    <span class="badge-wh wh-main">Main Warehouse</span>
                                </td>
                            </tr>

                            <!-- 2. Procurement (Salt) -->
                            <tr data-team="Procurement">
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                    <span style="font-weight: 600; color: var(--panel-ink);">Sept 19, 2026</span><br>
                                    <span style="font-size: 11px;">10:32 AM</span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 26px; height: 26px; border-radius: 50%; background: #FEF3C7; color: #92400E; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                            MS
                                        </div>
                                        <span style="font-weight: 600; font-size: 13px; color: var(--panel-ink);">Maria Santos</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-team procurement">Procurement</span>
                                </td>
                                <td>
                                    <span class="badge-action inbound">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="12" y1="5" x2="12" y2="19"/>
                                            <polyline points="19 12 12 19 5 12"/>
                                        </svg>
                                        Stock In Request
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: var(--panel-ink);">Salt</strong>
                                    <div style="font-size: 11px; color: var(--gray);">Raw Material &middot; RM-SLT-04</div>
                                </td>
                                <td style="text-align: right;">
                                    <span style="font-weight: 700; font-size: 13.5px; color: var(--panel-ink);">20</span>
                                    <small style="color: var(--gray); font-weight: 500;">kg</small>
                                </td>
                                <td>
                                    <span class="badge-wh wh-main">Main Warehouse</span>
                                </td>
                            </tr>

                            <!-- 3. Production (Took Raw Materials) -->
                            <tr data-team="Production">
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                    <span style="font-weight: 600; color: var(--panel-ink);">Sept 19, 2026</span><br>
                                    <span style="font-size: 11px;">11:45 AM</span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 26px; height: 26px; border-radius: 50%; background: #F3E8FF; color: #6B21A8; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                            RR
                                        </div>
                                        <span style="font-weight: 600; font-size: 13px; color: var(--panel-ink);">Ricardo Ramos</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-team production">Production</span>
                                </td>
                                <td>
                                    <span class="badge-action issue">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="16 16 12 12 8 16"/>
                                            <line x1="12" y1="12" x2="12" y2="21"/>
                                            <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
                                        </svg>
                                        Material Issued (Took Raw)
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: var(--panel-ink);">Premium Malted Barley</strong>
                                    <div style="font-size: 11px; color: var(--gray);">Raw Material &middot; RM-BRL-02</div>
                                </td>
                                <td style="text-align: right;">
                                    <span style="font-weight: 700; font-size: 13.5px; color: #92400E;">120</span>
                                    <small style="color: var(--gray); font-weight: 500;">kg</small>
                                </td>
                                <td>
                                    <span class="badge-wh wh-bond">Bonded Distillery</span>
                                </td>
                            </tr>

                            <!-- 4. Production (Stocked in Finished Goods) -->
                            <tr data-team="Production">
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                    <span style="font-weight: 600; color: var(--panel-ink);">Sept 19, 2026</span><br>
                                    <span style="font-size: 11px;">01:20 PM</span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 26px; height: 26px; border-radius: 50%; background: #F3E8FF; color: #6B21A8; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                            EG
                                        </div>
                                        <span style="font-weight: 600; font-size: 13px; color: var(--panel-ink);">Elena Gomez</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-team production">Production</span>
                                </td>
                                <td>
                                    <span class="badge-action inbound">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                            <polyline points="7 10 12 15 17 10"/>
                                            <line x1="12" y1="15" x2="12" y2="3"/>
                                        </svg>
                                        Stock In Finished Goods
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: var(--panel-ink);">Barrel Reserve Rum 750ml</strong>
                                    <div style="font-size: 11px; color: var(--gray);">Finished Good &middot; FG-RUM-01</div>
                                </td>
                                <td style="text-align: right;">
                                    <span style="font-weight: 700; font-size: 13.5px; color: #15803D;">350</span>
                                    <small style="color: var(--gray); font-weight: 500;">bottles</small>
                                </td>
                                <td>
                                    <span class="badge-wh wh-bott">Bottling &amp; Packaging</span>
                                </td>
                            </tr>

                            <!-- 5. Sales (Finished Goods Stock Out) -->
                            <tr data-team="Sales">
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                    <span style="font-weight: 600; color: var(--panel-ink);">Sept 19, 2026</span><br>
                                    <span style="font-size: 11px;">02:40 PM</span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 26px; height: 26px; border-radius: 50%; background: #E0F2FE; color: #0369A1; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                            CM
                                        </div>
                                        <span style="font-weight: 600; font-size: 13px; color: var(--panel-ink);">Carlo Mendoza</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-team sales">Sales</span>
                                </td>
                                <td>
                                    <span class="badge-action outbound">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="12" y1="19" x2="12" y2="5"/>
                                            <polyline points="5 12 12 5 19 12"/>
                                        </svg>
                                        Stock Out (Sales Dispatch)
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: var(--panel-ink);">Single Malt Whisky 700ml</strong>
                                    <div style="font-size: 11px; color: var(--gray);">Finished Good &middot; FG-WHK-02</div>
                                </td>
                                <td style="text-align: right;">
                                    <span style="font-weight: 700; font-size: 13.5px; color: #991B1B;">60</span>
                                    <small style="color: var(--gray); font-weight: 500;">cases</small>
                                </td>
                                <td>
                                    <span class="badge-wh wh-main">Main Warehouse</span>
                                </td>
                            </tr>

                            <!-- 6. Inventory (Stock Adjustment) -->
                            <tr data-team="Inventory">
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                    <span style="font-weight: 600; color: var(--panel-ink);">Sept 19, 2026</span><br>
                                    <span style="font-size: 11px;">03:15 PM</span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 26px; height: 26px; border-radius: 50%; background: #E6F4F1; color: #165B50; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                            VS
                                        </div>
                                        <span style="font-weight: 600; font-size: 13px; color: var(--panel-ink);">Vincent Santos</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-team inventory">Inventory</span>
                                </td>
                                <td>
                                    <span class="badge-action adjustment">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="3"/>
                                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                                        </svg>
                                        Cycle Count Adjustment
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: var(--panel-ink);">Neutral Cane Spirit</strong>
                                    <div style="font-size: 11px; color: var(--gray);">Raw Material &middot; RM-NCS-03</div>
                                </td>
                                <td style="text-align: right;">
                                    <span style="font-weight: 700; font-size: 13.5px; color: #15803D;">+15</span>
                                    <small style="color: var(--gray); font-weight: 500;">L</small>
                                </td>
                                <td>
                                    <span class="badge-wh wh-bond">Bonded Distillery</span>
                                </td>
                            </tr>

                            <!-- 7. Inventory (Transfer) -->
                            <tr data-team="Inventory">
                                <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                    <span style="font-weight: 600; color: var(--panel-ink);">Sept 19, 2026</span><br>
                                    <span style="font-size: 11px;">04:05 PM</span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div style="width: 26px; height: 26px; border-radius: 50%; background: #E6F4F1; color: #165B50; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                            TR
                                        </div>
                                        <span style="font-weight: 600; font-size: 13px; color: var(--panel-ink);">Teresa Reyes</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-team inventory">Inventory</span>
                                </td>
                                <td>
                                    <span class="badge-action transfer">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="17 1 21 5 17 9"/>
                                            <path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                                            <polyline points="7 23 3 19 7 15"/>
                                            <path d="M21 13v2a4 4 0 0 1-4 4H3"/>
                                        </svg>
                                        Inter-Warehouse Transfer
                                    </span>
                                </td>
                                <td>
                                    <strong style="color: var(--panel-ink);">French Oak Chips</strong>
                                    <div style="font-size: 11px; color: var(--gray);">Raw Material &middot; RM-FOC-09</div>
                                </td>
                                <td style="text-align: right;">
                                    <span style="font-weight: 700; font-size: 13.5px; color: #1D4ED8;">40</span>
                                    <small style="color: var(--gray); font-weight: 500;">kg</small>
                                </td>
                                <td>
                                    <span class="badge-wh wh-main">Laguna Central Hub</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Bottom Action Bar inside Accountability View -->
                <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 4px;">
                    <span style="font-size: 11.5px; color: var(--gray);">
                        * Static demonstration data for future audit pipeline integration.
                    </span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="switchSettingsView('main')">
                        <span>Back to Settings</span>
                    </button>
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
        'main':            { id: 'view-settings-main',            title: 'Settings',            showBack: false, wide: false },
        'account':         { id: 'view-settings-account',         title: 'My Account',          showBack: true,  wide: false },
        'password':        { id: 'view-settings-password',        title: 'Change Password',     showBack: true,  wide: false },
        'notifications':   { id: 'view-settings-notifications',   title: 'Notifications',       showBack: true,  wide: false },
        'security':        { id: 'view-settings-security',        title: 'Security',            showBack: true,  wide: false },
        'backup':          { id: 'view-settings-backup',          title: 'Backup & Export',     showBack: true,  wide: false },
        'contact_support': { id: 'view-settings-contact-support', title: 'Contact Support',     showBack: true,  wide: false },
        'accountability':  { id: 'view-settings-accountability',  title: 'Accountability Log',  showBack: true,  wide: true  }
    };

    const target = views[sectionId] || views['main'];

    // Hide all views
    document.querySelectorAll('.settings-view').forEach(v => v.classList.remove('active'));

    // Show target view
    const targetEl = document.getElementById(target.id);
    if (targetEl) {
        targetEl.classList.add('active');
    }

    // Adjust modal width for wide table views like Accountability
    const modalCard = document.querySelector('.settings-modal-card');
    if (modalCard) {
        if (target.wide) {
            modalCard.classList.add('modal-card-wide');
        } else {
            modalCard.classList.remove('modal-card-wide');
        }
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

function handleSimulateSupportSend() {
    const subject = document.getElementById('supportSubject');
    const msg = document.getElementById('supportMessage');
    const banner = document.getElementById('supportFeedbackBanner');
    
    if (banner) {
        banner.style.display = 'flex';
        setTimeout(() => {
            if (banner) banner.style.display = 'none';
        }, 6000);
    }
    
    if (subject) subject.value = '';
    if (msg) msg.value = '';
}

function filterAccountabilityTable() {
    const query = (document.getElementById('accountabilitySearch')?.value || '').toLowerCase().trim();
    const team = (document.getElementById('accountabilityTeamFilter')?.value || 'all');
    const rows = document.querySelectorAll('#accountabilityTable tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        const rowTeam = row.getAttribute('data-team') || '';
        const rowText = row.innerText.toLowerCase();

        const matchesTeam = (team === 'all' || rowTeam.toLowerCase() === team.toLowerCase());
        const matchesQuery = !query || rowText.includes(query);

        if (matchesTeam && matchesQuery) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const countEl = document.getElementById('accountabilityEntryCount');
    if (countEl) {
        countEl.textContent = `Showing ${visibleCount} of ${rows.length} log entries`;
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
    } else if (hash === '#settings-support' || hash === '#contact-support' || hash === '#support' || urlParams.get('section') === 'contact_support') {
        openSettingsModal('contact_support');
    } else if (hash === '#settings-accountability' || hash === '#accountability' || urlParams.get('section') === 'accountability') {
        openSettingsModal('accountability');
    }
});
</script>
