/**
 * Settings Modal Controller
 * InventoryTeam — Liquor Business Inventory Management System
 * Modular client-side controller for iOS-style grouped settings modal,
 * OTP verification, password change, preferences, and support inquiries.
 */

// Helper to resolve BASE_URL cleanly
function getSettingsBaseUrl() {
    if (typeof window.BASE_URL === 'string' && window.BASE_URL !== '') {
        return window.BASE_URL;
    }
    const metaBase = document.querySelector('meta[name="base-url"]');
    if (metaBase && metaBase.getAttribute('content')) {
        return metaBase.getAttribute('content');
    }
    return '/';
}

// Helper to resolve CSRF token
function getSettingsCsrfToken() {
    const metaCsrf = document.querySelector('meta[name="csrf-token"]');
    return metaCsrf ? (metaCsrf.getAttribute('content') || '') : '';
}

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
        'accountability':  { id: 'view-settings-accountability',  title: 'Accountability Log',  showBack: true,  wide: false, backTo: 'main',     backLabel: 'Settings' }
    };

    const target = views[sectionId] || views['main'];

    // Hide all views
    document.querySelectorAll('.settings-view').forEach(v => v.classList.remove('active'));

    // Show target view
    const targetEl = document.getElementById(target.id);
    if (targetEl) {
        targetEl.classList.add('active');
    }

    // Adjust modal width for wide table views if needed
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

    const baseUrl = getSettingsBaseUrl();
    fetch(`${baseUrl}api/auth/password_reset_otp.php`, {
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

    const baseUrl = getSettingsBaseUrl();
    fetch(`${baseUrl}api/auth/password_reset_otp.php`, {
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

    const baseUrl = getSettingsBaseUrl();
    fetch(`${baseUrl}api/auth/password_reset_otp.php`, {
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

    const baseUrl = getSettingsBaseUrl();
    fetch(`${baseUrl}api/auth/password_reset_otp.php`, {
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

    const baseUrl = getSettingsBaseUrl();
    fetch(`${baseUrl}api/auth/password_reset_otp.php`, {
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

    const baseUrl = getSettingsBaseUrl();
    const csrfToken = getSettingsCsrfToken();
    fetch(`${baseUrl}api/settings/preferences.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': csrfToken
        },
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

function toggleNotificationFeedback(checkbox, prefKey, label) {
    const key = (label ? prefKey : prefKey.toLowerCase().replace(/[^a-z0-9]+/g, '_'));
    const displayLabel = label || prefKey;
    const enabled = !!checkbox.checked;
    try {
        localStorage.setItem('inventoryteam_pref_' + key, enabled ? '1' : '0');
    } catch (e) {}

    const bannerId = (key === 'two_factor_auth') ? 'securityFeedbackBanner' : 'notifFeedbackBanner';
    const banner = document.getElementById(bannerId);

    const baseUrl = getSettingsBaseUrl();
    const csrfToken = getSettingsCsrfToken();
    fetch(`${baseUrl}api/settings/preferences.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-Token': csrfToken
        },
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
