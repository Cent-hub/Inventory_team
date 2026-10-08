<?php
/**
 * Layout Component: iOS-Inspired Grouped Settings Modal
 * InventoryTeam — Liquor Business Inventory Management System
 * 
 * Follows the clean inset-group visual structure of modern iOS settings,
 * faithfully adapted to the InventoryTeam deep navy, teal, and bronze palette.
 */

$currentUser = $currentUser ?? ($auth ? $auth->getCurrentUser() : []);

$dbPrefs = [];
if (!empty($currentUser['id']) && isset($pdo)) {
    try {
        $stmtP = $pdo->prepare("SELECT preferences_json FROM user_preferences WHERE user_id = ?");
        $stmtP->execute([(int)$currentUser['id']]);
        $rawP = $stmtP->fetchColumn();
        if ($rawP) {
            $dbPrefs = json_decode($rawP, true) ?: [];
        }
    } catch (Throwable $e) {}
}
if (!isset($_SESSION['user_preferences']) && !empty($dbPrefs)) {
    $_SESSION['user_preferences'] = $dbPrefs;
}

$userPrefs = array_merge([
    'inbound_receipts'    => true,
    'outbound_dispatches' => true,
    'daily_digest'        => false,
    'two_factor_auth'     => true,
], $dbPrefs, is_array($_SESSION['user_preferences'] ?? null) ? $_SESSION['user_preferences'] : []);

$currentWarehouseCode = $assignedWarehouse['warehouse_code'] ?? 'WH-MAIN';
$currentWarehouseName = $assignedWarehouse['warehouse_name'] ?? 'Main Warehouse';
$currentBranchLabel = htmlspecialchars($currentWarehouseCode . ' · ' . $currentWarehouseName);
$userRoleDisplay = htmlspecialchars(ucfirst(str_replace('_', ' ', $currentUser['role'] ?? 'admin')));
$userNameDisplay = htmlspecialchars($currentUser['name'] ?? 'Administrator');
$userEmailDisplay = htmlspecialchars($currentUser['email'] ?? 'admin@inventory.local');

$userTeamRaw = trim((string)($currentUser['team'] ?? ($_SESSION['user_team'] ?? '')));
if ($userTeamRaw === '' && isset($pdo) && !empty($currentUser['id'])) {
    try {
        $stmtUserTeam = $pdo->prepare("SELECT team FROM users WHERE user_id = ? LIMIT 1");
        $stmtUserTeam->execute([(int)$currentUser['id']]);
        $dbTeam = trim((string)$stmtUserTeam->fetchColumn());
        if ($dbTeam !== '') {
            $userTeamRaw = $dbTeam;
            $_SESSION['user_team'] = $dbTeam;
        }
    } catch (Exception $e) {
        // Fallback to session/default team
    }
}
if ($userTeamRaw === '') {
    $userTeamRaw = 'Inventory';
}
$teamSubtitleMap = [
    'Inventory'   => 'Distillery & Inventory Operations',
    'Procurement' => 'Procurement & Supplier Operations',
    'Production'  => 'Production & Distillation Operations',
    'Sales'       => 'Commercial Sales & Dispatch Operations',
];
$userTeamDisplay  = htmlspecialchars($userTeamRaw . ' Team');
$userTeamSubtitle = htmlspecialchars($teamSubtitleMap[$userTeamRaw] ?? ($userTeamRaw . ' Department'));
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
                        <div id="settingsAccountAvatar" class="user-avatar" style="width: 58px; height: 58px; font-size: 24px; background: #14213D; color: #FFFFFF; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                            <?= strtoupper(substr($currentUser['name'] ?? 'A', 0, 1)) ?>
                        </div>
                        <div>
                            <div id="settingsAccountNameText" style="font-size: 17px; font-weight: 700; color: var(--panel-ink); margin-bottom: 2px;">
                                <?= $userNameDisplay ?>
                            </div>
                            <div id="settingsAccountEmailText" style="font-size: 13px; color: var(--gray); font-family: monospace;">
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

                <span class="settings-group-label">Facility &amp; Session Scope</span>
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
                                <div class="settings-row-subtitle"><?= $userTeamSubtitle ?></div>
                            </div>
                        </div>
                        <div class="settings-row-right">
                            <span class="settings-row-value"><?= $userTeamDisplay ?></span>
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
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/reports/index.php?type=current_stock&export=csv'" role="button" tabindex="0">
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
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/reports/index.php?type=raw_materials&export=csv'" role="button" tabindex="0">
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
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/reports/index.php?type=finished_goods&export=csv'" role="button" tabindex="0">
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
                    <div class="settings-row" onclick="window.location.href='<?= BASE_URL ?>views/reports/index.php?type=stock_movements&export=csv'" role="button" tabindex="0">
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
                
                <!-- Accountability Hub Summary Card (Phase 3 UX Consolidation) -->
                <div class="settings-group" style="padding: 20px; text-align: left; display: flex; flex-direction: column; gap: 14px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div class="settings-squircle navy" style="width: 40px; height: 40px;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                                <rect width="8" height="4" x="8" y="2" rx="1" ry="1"/>
                                <path d="m9 14 2 2 4-4"/>
                            </svg>
                        </div>
                        <div>
                            <div style="font-size: 15px; font-weight: 700; color: var(--panel-ink);">
                                Central Accountability Audit Log
                            </div>
                            <div style="font-size: 12px; color: var(--gray); margin-top: 1px;">
                                Immutable cross-team operational audit trail
                            </div>
                        </div>
                    </div>

                    <p style="font-size: 13px; color: var(--panel-ink); line-height: 1.5; margin: 0;">
                        The Accountability Audit Log tracks every physical inventory movement across all teams:
                    </p>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; font-size: 12px;">
                            <div style="font-weight: 700; color: #92400E; margin-bottom: 2px;">Procurement</div>
                            <span style="color: var(--gray);">Raw material inbound receipts &amp; supplier PO dispatches</span>
                        </div>
                        <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; font-size: 12px;">
                            <div style="font-weight: 700; color: #6B21A8; margin-bottom: 2px;">Production</div>
                            <span style="color: var(--gray);">Raw material consumption &amp; finished goods stock-in</span>
                        </div>
                        <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; font-size: 12px;">
                            <div style="font-weight: 700; color: #0369A1; margin-bottom: 2px;">Sales</div>
                            <span style="color: var(--gray);">Finished goods reservation &amp; commercial dispatch</span>
                        </div>
                        <div style="background: #F8FAFC; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; font-size: 12px;">
                            <div style="font-weight: 700; color: #165B50; margin-bottom: 2px;">Inventory</div>
                            <span style="color: var(--gray);">Warehouse transfers, adjustments &amp; damaged write-offs</span>
                        </div>
                    </div>

                    <div style="background: rgba(31, 122, 108, 0.08); border: 1px solid var(--accent-border); border-radius: 10px; padding: 10px 14px; font-size: 12px; color: var(--panel-ink); display: flex; align-items: center; gap: 8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent); flex-shrink: 0;">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                        <span>Operating Facility: <strong><?= $currentBranchLabel ?></strong> (Strict Warehouse Isolation Active)</span>
                    </div>

                    <a href="<?= BASE_URL ?>views/settings/accountability.php" class="btn btn-primary" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 11px 16px; font-weight: 600; text-decoration: none; margin-top: 4px;">
                        <span>Launch Full Accountability Audit Hub</span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15 3 21 3 21 9"/>
                            <line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                    </a>

                    <div style="font-size: 11.5px; color: var(--gray); text-align: center;">
                        Includes advanced date-range filters, user search, team filtering, and one-click compliance CSV export.
                    </div>
                </div>

            </div>

        </div><!-- /.settings-modal-body -->

    </div>
</div>

