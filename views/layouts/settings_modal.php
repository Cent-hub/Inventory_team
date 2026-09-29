<?php
/**
 * Layout Component: iOS-Inspired Grouped Settings Modal
 * InventoryTeam — Liquor Business Inventory Management System
 * 
 * Follows the clean inset-group visual structure of modern iOS settings,
 * faithfully adapted to the InventoryTeam deep navy, teal, and bronze palette.
 */

$currentUser = $currentUser ?? ($auth ? $auth->getCurrentUser() : []);
require_once __DIR__ . '/../../helpers/AccountabilityService.php';
$modalWarehouseId = (($currentUser['role'] ?? '') === 'super_admin')
    ? (int)($currentWarehouseId ?? 0)
    : (int)($currentWarehouseId ?? ($currentUser['warehouse_id'] ?? 1));
$modalAccountabilityLogs = AccountabilityService::getLogs($modalWarehouseId, ['limit' => 30]);

$userPrefs = array_merge([
    'inbound_receipts'    => true,
    'outbound_dispatches' => true,
    'daily_digest'        => false,
    'two_factor_auth'     => true,
], is_array($_SESSION['user_preferences'] ?? null) ? $_SESSION['user_preferences'] : []);

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
                
                <!-- Group 1: Profile & Identity (Static Display) -->
                <div class="settings-group">
                    <div class="settings-row static">
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
                    <div class="settings-row" onclick="switchSettingsView('accountability')" role="button" tabindex="0">
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
                            <span class="settings-badge" style="background: #1F7A6C;">Audit Trail</span>
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
                
                <div id="settingsPwdFeedbackBanner" role="status" aria-live="polite" style="display: none; padding: 11px 14px; border-radius: 10px; font-size: 12.5px; font-weight: 600;"></div>

                <div class="settings-group">
                    <form id="settingsDirectPwdForm" onsubmit="event.preventDefault(); handleSettingsDirectPasswordChange();" style="padding: 16px; display: flex; flex-direction: column; gap: 14px;">
                        <div>
                            <label for="settingsCurrentPwd" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">Current Password</label>
                            <input type="password" id="settingsCurrentPwd" aria-label="Current Password" class="search-box" style="width: 100%; padding-left: 14px;" placeholder="Enter current password" required>
                        </div>
                        <div>
                            <label for="settingsNewPwd" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">New Password</label>
                            <input type="password" id="settingsNewPwd" aria-label="New Password" class="search-box" style="width: 100%; padding-left: 14px;" placeholder="Minimum 6 characters" minlength="6" required>
                        </div>
                        <div>
                            <label for="settingsConfirmPwd" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">Confirm New Password</label>
                            <input type="password" id="settingsConfirmPwd" aria-label="Confirm New Password" class="search-box" style="width: 100%; padding-left: 14px;" placeholder="Re-enter new password" minlength="6" required>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px;">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <span>Update Password</span>
                            </button>
                        </div>
                    </form>
                </div>

                <div class="settings-group">
                    <div class="settings-row" onclick="openSettingsForgotPassword()" role="button" tabindex="0">
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
            <!-- VIEW: SECTION 2B — FORGOT PASSWORD (Login Page Design)     -->
            <!-- ========================================================== -->
            <div id="view-settings-forgot-password" class="settings-view">
                <div style="width:100%; background:#ffffff; border-radius:24px; padding:28px 24px; box-shadow:0 10px 30px -10px rgba(15,23,42,0.12); border:1px solid var(--border); position:relative; overflow:hidden;">
                    <!-- Header with Icon & Title (Matches Login Page Forgot Password Modal) -->
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;">
                        <div style="display:flex; align-items:center; gap:9px;">
                            <span style="display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:10px; background:rgba(31,122,108,0.1); color:var(--accent);">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </span>
                            <span style="font-family:var(--font-display); font-size:16px; font-weight:700; color:var(--panel-ink);">Reset Password</span>
                        </div>
                    </div>

                    <!-- Step Indicator Pills -->
                    <div id="settings-otp-step-pills" style="display:flex; gap:6px; margin-bottom:20px;">
                        <div id="settings-pill-step-1" style="flex:1; height:4px; border-radius:2px; background:var(--accent); transition:background 0.2s ease;"></div>
                        <div id="settings-pill-step-2" style="flex:1; height:4px; border-radius:2px; background:var(--border); transition:background 0.2s ease;"></div>
                        <div id="settings-pill-step-3" style="flex:1; height:4px; border-radius:2px; background:var(--border); transition:background 0.2s ease;"></div>
                    </div>

                    <!-- Dynamic Alert Box -->
                    <div id="settings-otp-alert" style="display:none; padding:10px 14px; border-radius:10px; font-size:12.5px; font-weight:600; line-height:1.4; margin-bottom:16px;"></div>

                    <!-- STEP 1: Enter Email -->
                    <div id="settings-otp-step-1" style="display:block;">
                        <h3 style="font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--panel-ink); margin:0 0 6px 0;">Forgot your password?</h3>
                        <p style="font-size:13px; color:var(--gray); line-height:1.5; margin:0 0 18px 0;">Enter your account email below. We'll send a 6-digit verification code to your Gmail inbox.</p>

                        <form id="settings-form-otp-step-1" onsubmit="event.preventDefault(); handleSettingsOtpStep1();">
                            <div style="display:flex; flex-direction:column; gap:6px; width:100%; margin-bottom:18px;">
                                <label style="font-size:13px; font-weight:600; color:var(--panel-ink);" for="settings-otp-input-email">Email Address</label>
                                <div style="position:relative; width:100%;">
                                    <input id="settings-otp-input-email" type="email" value="<?= $userEmailDisplay ?>" placeholder="e.g. storeowner@gmail.com" required autocomplete="email" style="width:100%; padding:11px 14px; border-radius:12px; border:1.5px solid var(--border); font-family:var(--font-body); font-size:14px; color:var(--panel-ink); background:#fff; outline:none; transition:border-color .15s ease, box-shadow .15s ease;" onfocus="this.style.borderColor='var(--accent)'; this.style.boxShadow='0 0 0 3px rgba(31, 122, 108, 0.14)';" onblur="this.style.borderColor='var(--border)'; this.style.boxShadow='none';" />
                                </div>
                            </div>
                            <button type="submit" id="settings-btn-send-otp" style="margin-top:4px; width:100%; display:flex; align-items:center; justify-content:center; gap:8px; padding:13px 0; border:none; border-radius:14px; background:var(--accent); color:#fff; font-family:var(--font-body); font-size:15px; font-weight:700; cursor:pointer; transition:background-color .15s ease, transform .1s ease;" onmouseover="this.style.background='var(--accent-hover)'" onmouseout="this.style.background='var(--accent)'">
                                <span id="settings-text-send-otp">Send 6-Digit Code</span>
                            </button>
                        </form>
                    </div>

                    <!-- STEP 2: Enter 6-Digit Code -->
                    <div id="settings-otp-step-2" style="display:none;">
                        <h3 style="font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--panel-ink); margin:0 0 6px 0;">Enter 6-Digit Code</h3>
                        <p style="font-size:13px; color:var(--gray); line-height:1.5; margin:0 0 16px 0;">
                            We sent a 6-digit code to <strong id="settings-otp-target-email" style="color:var(--panel-ink);"><?= $userEmailDisplay ?></strong>. It expires in 15 minutes.
                        </p>

                        <form id="settings-form-otp-step-2" onsubmit="event.preventDefault(); handleSettingsOtpStep2();">
                            <!-- 6-digit OTP Box Inputs -->
                            <div style="display:flex; justify-content:space-between; gap:6px; margin-bottom:16px;">
                                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="settings-otp-box" data-index="0" aria-label="Verification code digit 1" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="settings-otp-box" data-index="1" aria-label="Verification code digit 2" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="settings-otp-box" data-index="2" aria-label="Verification code digit 3" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="settings-otp-box" data-index="3" aria-label="Verification code digit 4" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="settings-otp-box" data-index="4" aria-label="Verification code digit 5" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="settings-otp-box" data-index="5" aria-label="Verification code digit 6" style="width:48px; height:54px; text-align:center; font-size:22px; font-weight:700; font-family:var(--font-display); border:1.5px solid var(--border); border-radius:12px; outline:none; background:#F8FAFC; transition:all 0.15s ease;" />
                            </div>

                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; font-size:12.5px;">
                                <button type="button" onclick="setSettingsOtpStep(1)" style="background:none; border:none; color:var(--gray); cursor:pointer; padding:0; text-decoration:underline; font-size:12.5px; font-family:var(--font-body);">Change email</button>
                                <button type="button" id="settings-btn-otp-resend" onclick="handleSettingsOtpResend()" style="background:none; border:none; color:var(--accent); font-weight:600; cursor:pointer; padding:0; font-size:12.5px; font-family:var(--font-body);">Resend code</button>
                            </div>

                            <button type="submit" id="settings-btn-verify-otp" style="margin-top:4px; width:100%; display:flex; align-items:center; justify-content:center; gap:8px; padding:13px 0; border:none; border-radius:14px; background:var(--accent); color:#fff; font-family:var(--font-body); font-size:15px; font-weight:700; cursor:pointer; transition:background-color .15s ease, transform .1s ease;" onmouseover="this.style.background='var(--accent-hover)'" onmouseout="this.style.background='var(--accent)'">
                                <span id="settings-text-verify-otp">Verify Code</span>
                            </button>
                        </form>
                    </div>

                    <!-- STEP 3: Set New Password -->
                    <div id="settings-otp-step-3" style="display:none;">
                        <h3 style="font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--panel-ink); margin:0 0 6px 0;">Create New Password</h3>
                        <p style="font-size:13px; color:var(--gray); line-height:1.5; margin:0 0 18px 0;">Code verified! Enter your new password below (minimum 6 characters).</p>

                        <form id="settings-form-otp-step-3" onsubmit="event.preventDefault(); handleSettingsOtpStep3();">
                            <div style="display:flex; flex-direction:column; gap:6px; width:100%; margin-bottom:14px;">
                                <label style="font-size:13px; font-weight:600; color:var(--panel-ink);" for="settings-otp-input-pwd">New Password</label>
                                <div style="position:relative; width:100%;">
                                    <input id="settings-otp-input-pwd" type="password" placeholder="Enter new password" required minlength="6" style="width:100%; padding:11px 40px 11px 14px; border-radius:12px; border:1.5px solid var(--border); font-family:var(--font-body); font-size:14px; color:var(--panel-ink); background:#fff; outline:none; transition:border-color .15s ease, box-shadow .15s ease;" onfocus="this.style.borderColor='var(--accent)'; this.style.boxShadow='0 0 0 3px rgba(31, 122, 108, 0.14)';" onblur="this.style.borderColor='var(--border)'; this.style.boxShadow='none';" />
                                    <button type="button" onclick="toggleSettingsOtpPwdVisibility('settings-otp-input-pwd', this)" aria-label="Toggle password visibility" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--gray); cursor:pointer; padding:4px; display:flex; align-items:center; justify-content:center; border-radius:6px;">
                                        <svg class="eye-on" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-off" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
                                    </button>
                                </div>
                            </div>

                            <div style="display:flex; flex-direction:column; gap:6px; width:100%; margin-bottom:20px;">
                                <label style="font-size:13px; font-weight:600; color:var(--panel-ink);" for="settings-otp-input-confirm-pwd">Confirm New Password</label>
                                <div style="position:relative; width:100%;">
                                    <input id="settings-otp-input-confirm-pwd" type="password" placeholder="Re-enter your new password" required minlength="6" style="width:100%; padding:11px 40px 11px 14px; border-radius:12px; border:1.5px solid var(--border); font-family:var(--font-body); font-size:14px; color:var(--panel-ink); background:#fff; outline:none; transition:border-color .15s ease, box-shadow .15s ease;" onfocus="this.style.borderColor='var(--accent)'; this.style.boxShadow='0 0 0 3px rgba(31, 122, 108, 0.14)';" onblur="this.style.borderColor='var(--border)'; this.style.boxShadow='none';" />
                                    <button type="button" onclick="toggleSettingsOtpPwdVisibility('settings-otp-input-confirm-pwd', this)" aria-label="Toggle confirm password visibility" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--gray); cursor:pointer; padding:4px; display:flex; align-items:center; justify-content:center; border-radius:6px;">
                                        <svg class="eye-on" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="eye-off" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
                                    </button>
                                </div>
                            </div>

                            <button type="submit" id="settings-btn-save-new-pwd" style="margin-top:4px; width:100%; display:flex; align-items:center; justify-content:center; gap:8px; padding:13px 0; border:none; border-radius:14px; background:var(--accent); color:#fff; font-family:var(--font-body); font-size:15px; font-weight:700; cursor:pointer; transition:background-color .15s ease, transform .1s ease;" onmouseover="this.style.background='var(--accent-hover)'" onmouseout="this.style.background='var(--accent)'">
                                <span id="settings-text-save-pwd">Save New Password</span>
                            </button>
                        </form>
                    </div>

                    <!-- STEP 4: Success View -->
                    <div id="settings-otp-step-success" style="display:none; text-align:center; padding:12px 0;">
                        <div style="width:60px; height:60px; border-radius:50%; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; margin:0 auto 16px auto;">
                            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6 9 17l-5-5"/>
                            </svg>
                        </div>
                        <h3 style="font-family:var(--font-display); font-size:20px; font-weight:700; color:var(--panel-ink); margin:0 0 8px 0;">Password Reset Complete!</h3>
                        <p style="font-size:13.5px; color:var(--gray); line-height:1.5; margin:0 0 22px 0;">
                            Your password has been successfully updated. You can now sign in with your new credentials.
                        </p>
                        <button type="button" onclick="switchSettingsView('password')" style="width:100%; display:flex; align-items:center; justify-content:center; gap:8px; padding:13px 0; border:none; border-radius:14px; background:var(--accent); color:#fff; font-family:var(--font-body); font-size:15px; font-weight:700; cursor:pointer; transition:background-color .15s ease;">
                            <span>Back to Change Password</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ========================================================== -->
            <!-- VIEW: SECTION 3 — NOTIFICATIONS (With iOS Toggles)         -->
            <!-- ========================================================== -->
            <div id="view-settings-notifications" class="settings-view">
                
                <div id="notifFeedbackBanner" role="status" aria-live="polite" style="display: none; background: var(--success-light); border: 1px solid var(--success-border); color: #14532D; border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 500;"></div>

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
                                <input type="checkbox" data-pref-key="inbound_receipts" <?= !empty($userPrefs['inbound_receipts']) ? 'checked' : '' ?> aria-label="Toggle Inbound Receipts notifications" onchange="toggleNotificationFeedback(this, 'inbound_receipts', 'Inbound Receipts')">
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
                                <input type="checkbox" data-pref-key="outbound_dispatches" <?= !empty($userPrefs['outbound_dispatches']) ? 'checked' : '' ?> aria-label="Toggle High Outbound Dispatches notifications" onchange="toggleNotificationFeedback(this, 'outbound_dispatches', 'Outbound Dispatches')">
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
                                <input type="checkbox" data-pref-key="daily_digest" <?= !empty($userPrefs['daily_digest']) ? 'checked' : '' ?> aria-label="Toggle Daily Stock Ledger Digest notifications" onchange="toggleNotificationFeedback(this, 'daily_digest', 'Daily Digest')">
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
                <div id="securityFeedbackBanner" role="status" aria-live="polite" style="display: none; background: var(--success-light); border: 1px solid var(--success-border); color: #14532D; border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 500;"></div>
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
                                <input type="checkbox" data-pref-key="two_factor_auth" <?= !empty($userPrefs['two_factor_auth']) ? 'checked' : '' ?> aria-label="Toggle Two-Factor Authentication (2FA)" onchange="toggleNotificationFeedback(this, 'two_factor_auth', '2FA Authentication')">
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
                            <svg class="settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </div>
                    </div>

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

                <!-- Feedback Banner -->
                <div id="supportFeedbackBanner" role="status" aria-live="polite" style="display: none; background: var(--success-light); border: 1px solid var(--success-border); border-radius: 10px; padding: 12px 16px; align-items: center; gap: 10px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--success); flex-shrink: 0;">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <div style="font-size: 13px; color: #14532D; font-weight: 500;">
                        Your support inquiry has been queued for the <strong>Super Admin</strong>.
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
                            <input type="text" id="supportSubject" class="search-box" style="width: 100%; padding: 0 14px;" placeholder="e.g., Stock In Request Discrepancy — Main Warehouse" required>
                        </div>

                        <!-- Inquiry Category -->
                        <div>
                            <label for="supportCategory" style="font-size: 12.5px; font-weight: 600; color: var(--panel-ink); display: block; margin-bottom: 6px;">
                                Inquiry Category
                            </label>
                            <select id="supportCategory" class="select-filter" style="width: 100%;">
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
                            <textarea id="supportMessage" rows="5" class="search-box" style="width: 100%; height: auto; min-height: 110px; padding: 10px 14px; resize: vertical; line-height: 1.45;" placeholder="Describe your inquiry, affected raw materials, finished goods, or warehouse details..." required></textarea>
                        </div>

                        <!-- Buttons: Send Message + Cancel -->
                        <div style="display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 6px; padding-top: 12px; border-top: 1px solid var(--border);">
                            <button type="button" class="btn btn-secondary" onclick="switchSettingsView('main')">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" style="background: #7C3AED; border-color: #7C3AED;">
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
                        <a href="<?= BASE_URL ?>views/settings/accountability.php" class="btn btn-primary btn-sm" style="text-decoration: none;">
                            <span>Open Whole Page</span>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                <polyline points="15 3 21 3 21 9"/>
                                <line x1="10" y1="14" x2="21" y2="3"/>
                            </svg>
                        </a>
                        <span class="settings-badge teal" style="font-size: 11px; padding: 3px 9px;">Live Audit</span>
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
                            <input type="text" id="accountabilitySearch" aria-label="Search accountability log" class="search-box" placeholder="Search user, item, warehouse..." oninput="filterAccountabilityTable()">
                        </div>
                        <select id="accountabilityTeamFilter" aria-label="Filter accountability log by team" class="select-filter" style="min-width: 140px;" onchange="filterAccountabilityTable()">
                            <option value="all">All Teams</option>
                            <option value="Procurement">Procurement</option>
                            <option value="Production">Production</option>
                            <option value="Sales">Sales</option>
                            <option value="Inventory">Inventory</option>
                            <option value="Administration">Administration</option>
                        </select>
                    </div>
                    <span id="accountabilityEntryCount" aria-live="polite" style="font-size: 12px; color: var(--gray); font-weight: 500;">
                        Showing <?= count($modalAccountabilityLogs) ?> log entries
                    </span>
                </div>

                <!-- Accountability Table Container with Scrolling -->
                <div class="table-responsive" style="box-shadow: 0 1px 3px rgba(0,0,0,0.03); max-height: 440px; overflow-y: auto;">
                    <table id="accountabilityTable" class="no-paginate" style="margin: 0; min-width: 680px;">
                        <thead>
                            <tr style="position: sticky; top: 0; z-index: 2; background: #F8FAFC;">
                                <th style="min-width: 140px;">Date &amp; Time</th>
                                <th style="min-width: 130px;">User</th>
                                <th style="min-width: 105px;">Team</th>
                                <th style="min-width: 165px;">Action</th>
                                <th style="min-width: 150px;">Raw Material / Item</th>
                                <th style="min-width: 90px; text-align: right;">Quantity</th>
                                <th style="min-width: 120px;">Warehouse</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($modalAccountabilityLogs)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 30px; color: var(--gray);">
                                        No accountability log records found for this facility.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($modalAccountabilityLogs as $log): 
                                    $createdAt = strtotime($log['created_at']);
                                    $dateFormatted = date('M d, Y', $createdAt);
                                    $timeFormatted = date('h:i A', $createdAt);
                                    $initials = AccountabilityService::getUserInitials($log['user_name']);
                                    $team = htmlspecialchars($log['team']);
                                    $actionBadge = AccountabilityService::formatActionBadge($log['action_type'], $log['channel']);
                                    $teamBadge = AccountabilityService::formatTeamBadge($log['team']);
                                    $whName = htmlspecialchars($log['warehouse_name']);
                                    $destName = !empty($log['dest_name']) ? htmlspecialchars($log['dest_name']) : '';
                                ?>
                                <tr data-team="<?= $team ?>">
                                    <td style="font-size: 12px; color: var(--gray); white-space: nowrap;">
                                        <span style="font-weight: 600; color: var(--panel-ink);"><?= $dateFormatted ?></span><br>
                                        <span style="font-size: 11px;"><?= $timeFormatted ?></span>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div style="width: 26px; height: 26px; border-radius: 50%; background: #E2E8F0; color: #1E293B; font-weight: 700; font-size: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <?= htmlspecialchars($initials) ?>
                                            </div>
                                            <span style="font-weight: 600; font-size: 13px; color: var(--panel-ink);"><?= htmlspecialchars($log['user_name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?= $teamBadge ?>
                                    </td>
                                    <td>
                                        <?= $actionBadge ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($log['item_name'])): ?>
                                            <strong style="color: var(--panel-ink);"><?= htmlspecialchars($log['item_name']) ?></strong>
                                            <div style="font-size: 11px; color: var(--gray);"><?= htmlspecialchars($log['item_code'] ?? '') ?></div>
                                        <?php else: ?>
                                            <span style="color: var(--gray); font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if ((float)$log['quantity'] != 0): ?>
                                            <span style="font-weight: 700; font-size: 13.5px; color: var(--panel-ink);"><?= (float)$log['quantity'] > 0 ? '+' : '' ?><?= rtrim(rtrim(number_format((float)$log['quantity'], 4), '0'), '.') ?></span>
                                            <small style="color: var(--gray); font-weight: 500;"><?= htmlspecialchars($log['unit'] ?? '') ?></small>
                                        <?php else: ?>
                                            <span style="color: var(--gray); font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-wh wh-main"><?= $whName ?></span>
                                        <?php if (!empty($destName)): ?>
                                            <div style="font-size: 10px; color: var(--gray); margin-top: 1px;">➔ <?= $destName ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Bottom Note inside Accountability View -->
                <div style="padding-top: 4px;">
                    <span style="font-size: 11.5px; color: var(--gray);">
                        * Filtered and scoped to your assigned operating warehouse with operational audit integrity.
                    </span>
                </div>

            </div>

        </div><!-- /.settings-modal-body -->

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
        'main':            { id: 'view-settings-main',            title: 'Settings',            showBack: false, wide: false, backTo: 'main',     backLabel: 'Settings' },
        'account':         { id: 'view-settings-account',         title: 'My Account',          showBack: true,  wide: false, backTo: 'main',     backLabel: 'Settings' },
        'password':        { id: 'view-settings-password',        title: 'Change Password',     showBack: true,  wide: false, backTo: 'main',     backLabel: 'Settings' },
        'forgot_password': { id: 'view-settings-forgot-password', title: 'Reset Password',      showBack: true,  wide: false, backTo: 'password', backLabel: 'Change Password' },
        'notifications':   { id: 'view-settings-notifications',   title: 'Notifications',       showBack: true,  wide: false, backTo: 'main',     backLabel: 'Settings' },
        'security':        { id: 'view-settings-security',        title: 'Security',            showBack: true,  wide: false, backTo: 'main',     backLabel: 'Settings' },
        'backup':          { id: 'view-settings-backup',          title: 'Backup & Export',     showBack: true,  wide: false, backTo: 'main',     backLabel: 'Settings' },
        'contact_support': { id: 'view-settings-contact-support', title: 'Contact Support',     showBack: true,  wide: false, backTo: 'main',     backLabel: 'Settings' },
        'accountability':  { id: 'view-settings-accountability',  title: 'Accountability Log',  showBack: true,  wide: true,  backTo: 'main',     backLabel: 'Settings' }
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
        backBtn.setAttribute('onclick', `switchSettingsView('${target.backTo || 'main'}')`);
        const backSpan = backBtn.querySelector('span');
        if (backSpan) backSpan.textContent = target.backLabel || 'Settings';
    }

    // Scroll body back to top
    const modalBody = document.querySelector('.settings-modal-body');
    if (modalBody) {
        modalBody.scrollTop = 0;
    }
}

// =========================================================================
// 3-STEP 6-DIGIT OTP FORGOT PASSWORD CONTROLLER (Matches Login Page Design)
// =========================================================================
let settingsResetEmail = '';
let settingsResetToken = '';
let settingsResendCountdown = 0;
let settingsResendInterval = null;

function openSettingsForgotPassword() {
    setSettingsOtpStep(1);
    switchSettingsView('forgot_password');
}

function showSettingsOtpAlert(type, message) {
    const alertEl = document.getElementById('settings-otp-alert');
    if (!alertEl) return;
    alertEl.textContent = message;
    alertEl.style.display = 'block';
    if (type === 'success') {
        alertEl.style.background = '#dcfce7';
        alertEl.style.color = '#15803d';
        alertEl.style.border = '1px solid #bbf7d0';
    } else {
        alertEl.style.background = '#fef2f2';
        alertEl.style.color = '#dc2626';
        alertEl.style.border = '1px solid #fecaca';
    }
}

function clearSettingsOtpAlert() {
    const alertEl = document.getElementById('settings-otp-alert');
    if (alertEl) {
        alertEl.style.display = 'none';
        alertEl.textContent = '';
    }
}

function setSettingsOtpStep(step) {
    clearSettingsOtpAlert();
    const stepPills = document.getElementById('settings-otp-step-pills');
    const pill1 = document.getElementById('settings-pill-step-1');
    const pill2 = document.getElementById('settings-pill-step-2');
    const pill3 = document.getElementById('settings-pill-step-3');
    const step1 = document.getElementById('settings-otp-step-1');
    const step2 = document.getElementById('settings-otp-step-2');
    const step3 = document.getElementById('settings-otp-step-3');
    const stepSuccess = document.getElementById('settings-otp-step-success');
    const otpBoxes = Array.from(document.querySelectorAll('.settings-otp-box'));

    if (stepPills) stepPills.style.display = (step === 4) ? 'none' : 'flex';

    if (step === 1) {
        if (pill1) pill1.style.background = 'var(--accent)';
        if (pill2) pill2.style.background = 'var(--border)';
        if (pill3) pill3.style.background = 'var(--border)';
        if (step1) step1.style.display = 'block';
        if (step2) step2.style.display = 'none';
        if (step3) step3.style.display = 'none';
        if (stepSuccess) stepSuccess.style.display = 'none';
        const emailInput = document.getElementById('settings-otp-input-email');
        setTimeout(() => emailInput && emailInput.focus(), 100);
    } else if (step === 2) {
        if (pill1) pill1.style.background = 'var(--accent)';
        if (pill2) pill2.style.background = 'var(--accent)';
        if (pill3) pill3.style.background = 'var(--border)';
        if (step1) step1.style.display = 'none';
        if (step2) step2.style.display = 'block';
        if (step3) step3.style.display = 'none';
        if (stepSuccess) stepSuccess.style.display = 'none';
        otpBoxes.forEach(b => b.value = '');
        setTimeout(() => otpBoxes[0] && otpBoxes[0].focus(), 100);
    } else if (step === 3) {
        if (pill1) pill1.style.background = 'var(--accent)';
        if (pill2) pill2.style.background = 'var(--accent)';
        if (pill3) pill3.style.background = 'var(--accent)';
        if (step1) step1.style.display = 'none';
        if (step2) step2.style.display = 'none';
        if (step3) step3.style.display = 'block';
        if (stepSuccess) stepSuccess.style.display = 'none';
        const pwdInput = document.getElementById('settings-otp-input-pwd');
        setTimeout(() => pwdInput && pwdInput.focus(), 100);
    } else if (step === 4) {
        if (step1) step1.style.display = 'none';
        if (step2) step2.style.display = 'none';
        if (step3) step3.style.display = 'none';
        if (stepSuccess) stepSuccess.style.display = 'block';
    }
}

function startSettingsOtpResendTimer() {
    const btnResend = document.getElementById('settings-btn-otp-resend');
    if (!btnResend) return;
    if (settingsResendInterval) clearInterval(settingsResendInterval);
    settingsResendCountdown = 60;
    btnResend.disabled = true;
    btnResend.style.opacity = '0.6';
    btnResend.style.cursor = 'default';
    btnResend.textContent = `Resend code (${settingsResendCountdown}s)`;

    settingsResendInterval = setInterval(() => {
        settingsResendCountdown--;
        if (settingsResendCountdown <= 0) {
            clearInterval(settingsResendInterval);
            settingsResendInterval = null;
            btnResend.disabled = false;
            btnResend.style.opacity = '1';
            btnResend.style.cursor = 'pointer';
            btnResend.textContent = 'Resend code';
        } else {
            btnResend.textContent = `Resend code (${settingsResendCountdown}s)`;
        }
    }, 1000);
}

function handleSettingsOtpStep1() {
    clearSettingsOtpAlert();
    const emailInput = document.getElementById('settings-otp-input-email');
    const btnSend = document.getElementById('settings-btn-send-otp');
    const textSend = document.getElementById('settings-text-send-otp');
    const targetEmailText = document.getElementById('settings-otp-target-email');

    const emailVal = emailInput ? emailInput.value.trim() : '';
    if (!emailVal) {
        showSettingsOtpAlert('danger', 'Please enter your email address.');
        return;
    }

    if (btnSend) {
        btnSend.disabled = true;
        if (textSend) textSend.textContent = 'Sending Code...';
    }

    fetch('<?= BASE_URL ?>api/auth/password_reset_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'send_code', email: emailVal })
    })
    .then(res => res.json())
    .then(data => {
        if (btnSend) {
            btnSend.disabled = false;
            if (textSend) textSend.textContent = 'Send 6-Digit Code';
        }
        if (data.success) {
            settingsResetEmail = emailVal;
            if (targetEmailText) targetEmailText.textContent = data.masked_email || emailVal;
            setSettingsOtpStep(2);
            showSettingsOtpAlert('success', data.message || 'Verification code dispatched to your inbox.');
            startSettingsOtpResendTimer();
        } else {
            showSettingsOtpAlert('danger', data.message || 'Unable to send verification code.');
        }
    })
    .catch(() => {
        if (btnSend) {
            btnSend.disabled = false;
            if (textSend) textSend.textContent = 'Send 6-Digit Code';
        }
        showSettingsOtpAlert('danger', 'A network error occurred. Please try again.');
    });
}

function handleSettingsOtpResend() {
    const btnResend = document.getElementById('settings-btn-otp-resend');
    if (settingsResendCountdown > 0 || !settingsResetEmail) return;
    clearSettingsOtpAlert();
    if (btnResend) btnResend.textContent = 'Sending...';

    fetch('<?= BASE_URL ?>api/auth/password_reset_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'send_code', email: settingsResetEmail })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showSettingsOtpAlert('success', data.message || 'New 6-digit verification code sent!');
            startSettingsOtpResendTimer();
        } else {
            if (btnResend) btnResend.textContent = 'Resend code';
            showSettingsOtpAlert('danger', data.message || 'Failed to resend code.');
        }
    })
    .catch(() => {
        if (btnResend) btnResend.textContent = 'Resend code';
        showSettingsOtpAlert('danger', 'Network error. Please try again.');
    });
}

function handleSettingsOtpStep2() {
    clearSettingsOtpAlert();
    const otpBoxes = Array.from(document.querySelectorAll('.settings-otp-box'));
    const btnVerify = document.getElementById('settings-btn-verify-otp');
    const textVerify = document.getElementById('settings-text-verify-otp');

    const code = otpBoxes.map(b => b.value.trim()).join('');
    if (code.length !== 6) {
        showSettingsOtpAlert('danger', 'Please enter all 6 digits of the verification code.');
        return;
    }

    if (btnVerify) {
        btnVerify.disabled = true;
        if (textVerify) textVerify.textContent = 'Verifying...';
    }

    fetch('<?= BASE_URL ?>api/auth/password_reset_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
            action: 'verify_code',
            email: settingsResetEmail,
            code: code
        })
    })
    .then(res => res.json())
    .then(data => {
        if (btnVerify) {
            btnVerify.disabled = false;
            if (textVerify) textVerify.textContent = 'Verify Code';
        }
        if (data.success && data.reset_token) {
            settingsResetToken = data.reset_token;
            setSettingsOtpStep(3);
            showSettingsOtpAlert('success', data.message || 'Code verified successfully!');
        } else {
            showSettingsOtpAlert('danger', data.message || 'Invalid code. Please try again.');
        }
    })
    .catch(() => {
        if (btnVerify) {
            btnVerify.disabled = false;
            if (textVerify) textVerify.textContent = 'Verify Code';
        }
        showSettingsOtpAlert('danger', 'A network error occurred while verifying the code.');
    });
}

function handleSettingsOtpStep3() {
    clearSettingsOtpAlert();
    const inputPwd = document.getElementById('settings-otp-input-pwd');
    const inputConfirmPwd = document.getElementById('settings-otp-input-confirm-pwd');
    const btnSave = document.getElementById('settings-btn-save-new-pwd');
    const textSave = document.getElementById('settings-text-save-pwd');

    const newPwd = inputPwd ? inputPwd.value : '';
    const confirmPwd = inputConfirmPwd ? inputConfirmPwd.value : '';

    if (!newPwd || !confirmPwd) {
        showSettingsOtpAlert('danger', 'Please fill in both password fields.');
        return;
    }
    if (newPwd.length < 6) {
        showSettingsOtpAlert('danger', 'Password must be at least 6 characters.');
        return;
    }
    if (newPwd !== confirmPwd) {
        showSettingsOtpAlert('danger', 'Passwords do not match. Please re-enter.');
        return;
    }

    if (btnSave) {
        btnSave.disabled = true;
        if (textSave) textSave.textContent = 'Saving Password...';
    }

    fetch('<?= BASE_URL ?>api/auth/password_reset_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
            action: 'reset_password',
            email: settingsResetEmail,
            reset_token: settingsResetToken,
            password: newPwd,
            confirm_password: confirmPwd
        })
    })
    .then(res => res.json())
    .then(data => {
        if (btnSave) {
            btnSave.disabled = false;
            if (textSave) textSave.textContent = 'Save New Password';
        }
        if (data.success) {
            setSettingsOtpStep(4);
        } else {
            showSettingsOtpAlert('danger', data.message || 'Failed to update password.');
        }
    })
    .catch(() => {
        if (btnSave) {
            btnSave.disabled = false;
            if (textSave) textSave.textContent = 'Save New Password';
        }
        showSettingsOtpAlert('danger', 'A network error occurred while updating password.');
    });
}

function toggleSettingsOtpPwdVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input || !btn) return;
    const isPwd = input.type === 'password';
    input.type = isPwd ? 'text' : 'password';
    const eyeOn = btn.querySelector('.eye-on');
    const eyeOff = btn.querySelector('.eye-off');
    if (eyeOn) eyeOn.style.display = isPwd ? 'none' : 'block';
    if (eyeOff) eyeOff.style.display = isPwd ? 'block' : 'none';
}

function handleSettingsDirectPasswordChange() {
    const curPwd = document.getElementById('settingsCurrentPwd');
    const newPwd = document.getElementById('settingsNewPwd');
    const cfmPwd = document.getElementById('settingsConfirmPwd');
    const banner = document.getElementById('settingsPwdFeedbackBanner');
    const submitBtn = document.querySelector('#settingsDirectPwdForm button[type="submit"]');
    if (!banner) return;

    const currentVal = curPwd ? curPwd.value : '';
    const newVal = newPwd ? newPwd.value : '';
    const confirmVal = cfmPwd ? cfmPwd.value : '';

    if (!currentVal || !newVal || !confirmVal) {
        banner.style.display = 'block';
        banner.style.background = 'var(--error-light)';
        banner.style.color = 'var(--error)';
        banner.style.border = '1px solid var(--error-border)';
        banner.textContent = 'Please fill in all password fields.';
        return;
    }

    if (newVal.length < 6) {
        banner.style.display = 'block';
        banner.style.background = 'var(--error-light)';
        banner.style.color = 'var(--error)';
        banner.style.border = '1px solid var(--error-border)';
        banner.textContent = 'New password must be at least 6 characters long.';
        return;
    }

    if (newVal !== confirmVal) {
        banner.style.display = 'block';
        banner.style.background = 'var(--error-light)';
        banner.style.color = 'var(--error)';
        banner.style.border = '1px solid var(--error-border)';
        banner.textContent = 'New password and confirmation password do not match.';
        return;
    }

    if (submitBtn) submitBtn.disabled = true;

    fetch('<?= BASE_URL ?>api/auth/password_reset_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
            action: 'change_password',
            current_password: currentVal,
            new_password: newVal,
            confirm_password: confirmVal
        })
    })
    .then(res => res.json())
    .then(data => {
        if (submitBtn) submitBtn.disabled = false;
        banner.style.display = 'block';
        if (data.success) {
            banner.style.background = 'var(--success-light)';
            banner.style.color = 'var(--success)';
            banner.style.border = '1px solid var(--success-border)';
            banner.textContent = data.message || 'Your password has been updated and saved to the database.';
            if (curPwd) curPwd.value = '';
            if (newPwd) newPwd.value = '';
            if (cfmPwd) cfmPwd.value = '';
        } else {
            banner.style.background = 'var(--error-light)';
            banner.style.color = 'var(--error)';
            banner.style.border = '1px solid var(--error-border)';
            banner.textContent = data.message || 'Failed to update password. Please check your current password.';
        }
    })
    .catch(() => {
        if (submitBtn) submitBtn.disabled = false;
        banner.style.display = 'block';
        banner.style.background = 'var(--error-light)';
        banner.style.color = 'var(--error)';
        banner.style.border = '1px solid var(--error-border)';
        banner.textContent = 'A network error occurred while updating your password.';
    });
}

function handleSimulateSupportSend() {
    const subjectEl = document.getElementById('supportSubject');
    const categoryEl = document.getElementById('supportCategory');
    const msgEl = document.getElementById('supportMessage');
    const banner = document.getElementById('supportFeedbackBanner');
    const submitBtn = document.querySelector('#contactSupportForm button[type="submit"]');

    const subject = subjectEl ? subjectEl.value.trim() : '';
    const category = categoryEl ? categoryEl.value : 'general';
    const message = msgEl ? msgEl.value.trim() : '';

    if (!subject || !message) return;

    if (submitBtn) submitBtn.disabled = true;

    fetch('<?= BASE_URL ?>api/settings/preferences.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
            action: 'submit_support',
            subject: subject,
            category: category,
            message: message
        })
    })
    .then(res => res.json())
    .then(data => {
        if (submitBtn) submitBtn.disabled = false;
        if (banner) {
            banner.style.display = 'flex';
            const textDiv = banner.querySelector('div');
            if (textDiv && data.message) {
                textDiv.textContent = data.message;
            }
            setTimeout(() => {
                if (banner) banner.style.display = 'none';
            }, 6000);
        }
        if (data.success) {
            if (subjectEl) subjectEl.value = '';
            if (msgEl) msgEl.value = '';
        }
    })
    .catch(() => {
        if (submitBtn) submitBtn.disabled = false;
        if (banner) {
            banner.style.display = 'flex';
            setTimeout(() => {
                if (banner) banner.style.display = 'none';
            }, 6000);
        }
    });
}

function filterAccountabilityTable() {
    const query = (document.getElementById('accountabilitySearch')?.value || '').toLowerCase().trim();
    const team = (document.getElementById('accountabilityTeamFilter')?.value || 'all');
    const rows = document.querySelectorAll('#accountabilityTable tbody tr');
    let visibleCount = 0;

    rows.forEach(row => {
        if (row.querySelector('td[colspan]')) return;
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

function toggleNotificationFeedback(checkbox, prefKey, label) {
    const key = (label ? prefKey : prefKey.toLowerCase().replace(/[^a-z0-9]+/g, '_'));
    const displayLabel = label || prefKey;
    const enabled = !!checkbox.checked;
    try {
        localStorage.setItem('inventoryteam_pref_' + key, enabled ? '1' : '0');
    } catch (e) {}

    const bannerId = (key === 'two_factor_auth') ? 'securityFeedbackBanner' : 'notifFeedbackBanner';
    const banner = document.getElementById(bannerId);

    fetch('<?= BASE_URL ?>api/settings/preferences.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
            action: 'save_preference',
            key: key,
            enabled: enabled
        })
    })
    .then(res => res.json())
    .then(data => {
        if (banner) {
            banner.textContent = data.message || `${displayLabel}: ${enabled ? 'Enabled' : 'Disabled'} and saved.`;
            banner.style.display = 'block';
            setTimeout(() => {
                if (banner) banner.style.display = 'none';
            }, 3500);
        }
    })
    .catch(() => {});
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
    // Hydrate saved toggle switches from localStorage if customized in browser
    document.querySelectorAll('input[type="checkbox"][data-pref-key]').forEach(cb => {
        const key = cb.getAttribute('data-pref-key');
        try {
            const saved = localStorage.getItem('inventoryteam_pref_' + key);
            if (saved === '1' || saved === '0') {
                cb.checked = (saved === '1');
            }
        } catch (e) {}
    });

    // Enable keyboard Enter / Space activation on clickable .settings-row[role="button"] items
    document.querySelectorAll('.settings-row[role="button"]').forEach(row => {
        row.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                row.click();
            }
        });
    });

    // OTP Box Input Group Logic (Auto-Advance, Backspace, Paste)
    const settingsOtpBoxes = Array.from(document.querySelectorAll('.settings-otp-box'));
    settingsOtpBoxes.forEach((box, idx) => {
        box.addEventListener('focus', function () {
            box.style.borderColor = 'var(--accent)';
            box.style.boxShadow = '0 0 0 3px rgba(31,122,108,0.15)';
            box.select();
        });
        box.addEventListener('blur', function () {
            box.style.borderColor = 'var(--border)';
            box.style.boxShadow = 'none';
        });
        box.addEventListener('input', function () {
            const val = box.value.replace(/[^0-9]/g, '');
            box.value = val ? val[0] : '';
            if (box.value && idx < settingsOtpBoxes.length - 1) {
                settingsOtpBoxes[idx + 1].focus();
            }
        });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !box.value && idx > 0) {
                settingsOtpBoxes[idx - 1].focus();
            } else if (e.key === 'ArrowLeft' && idx > 0) {
                settingsOtpBoxes[idx - 1].focus();
            } else if (e.key === 'ArrowRight' && idx < settingsOtpBoxes.length - 1) {
                settingsOtpBoxes[idx + 1].focus();
            }
        });
        box.addEventListener('paste', function (e) {
            e.preventDefault();
            const pasteData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
            if (!pasteData) return;
            for (let i = 0; i < settingsOtpBoxes.length; i++) {
                if (pasteData[i]) {
                    settingsOtpBoxes[i].value = pasteData[i];
                }
            }
            const nextIdx = Math.min(pasteData.length, settingsOtpBoxes.length - 1);
            settingsOtpBoxes[nextIdx].focus();
        });
    });

    const hash = window.location.hash;
    const urlParams = new URLSearchParams(window.location.search);
    
    if (hash === '#settings' || urlParams.get('settings') === '1' || urlParams.get('modal') === 'settings') {
        const section = urlParams.get('section') || 'main';
        openSettingsModal(section);
    } else if (hash === '#settings-account' || hash === '#account') {
        openSettingsModal('account');
    } else if (hash === '#settings-password' || hash === '#password') {
        openSettingsModal('password');
    } else if (hash === '#settings-forgot-password' || urlParams.get('section') === 'forgot_password') {
        openSettingsModal('forgot_password');
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
