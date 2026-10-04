<?php
$page_id = 'update_password';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me    = current_user();
$msg   = '';
$error = '';

// ── Load dynamic security policy from system_settings (never hardcoded) ──
$sec_policy          = function_exists('petron_get_security_policy') ? petron_get_security_policy($me['station_id'] ?? null) : [];
$sec_min_pass_len    = (int)($sec_policy['min_password_length'] ?? 8);
$sec_req_upper       = !empty($sec_policy['require_uppercase']);
$sec_req_numbers     = !empty($sec_policy['require_numbers']);
$sec_req_special     = !empty($sec_policy['require_special_chars']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password']     ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password))                        throw new Exception('Current password is required.');
        if (empty($new_password))                            throw new Exception('New password is required.');
        if (function_exists('petron_validate_password_policy')) {
            $valRes = petron_validate_password_policy($new_password, [
                'min_password_length'   => $sec_min_pass_len,
                'require_uppercase'     => $sec_req_upper,
                'require_numbers'       => $sec_req_numbers,
                'require_special_chars' => $sec_req_special
            ]);
            if (!$valRes['valid']) {
                throw new Exception($valRes['errors'][0] ?? 'New password does not satisfy security requirements.');
            }
        } else {
            if (strlen($new_password) < $sec_min_pass_len)       throw new Exception("New password must be at least {$sec_min_pass_len} characters long.");
            if ($sec_req_upper && !preg_match('/[A-Z]/', $new_password)) throw new Exception('New password must contain at least one uppercase letter (A-Z).');
            if ($sec_req_numbers && !preg_match('/[0-9]/', $new_password)) throw new Exception('New password must contain at least one number (0-9).');
            if ($sec_req_special && !preg_match('/[!@#$%^&*(),.?":{}|<>\-_]/', $new_password)) throw new Exception('New password must contain at least one special character (!@#$%^&* etc.).');
        }
        if ($new_password !== $confirm_password)             throw new Exception('New passwords do not match.');
        if ($current_password === $new_password)             throw new Exception('New password must be different from current password.');

        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$me['id']]);
        $row = $stmt->fetch();
        if (!$row)                                           throw new Exception('User not found.');
        if (!password_verify($current_password, $row['password_hash'])) throw new Exception('Current password is incorrect.');

        $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
            ->execute([password_hash($new_password, PASSWORD_DEFAULT), $me['id']]);

        // Save successful change to Audit Trail
        try {
            $user_role_disp = ucfirst(strtolower($me['role'] ?? 'staff'));
            $user_name_disp = $me['name'] ?? $me['username'] ?? "User #{$me['id']}";
            $audit_detail   = "{$user_name_disp} ({$user_role_disp}) successfully changed account password";

            // 1. Log to audit_logs
            $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
            if (!empty($tables)) {
                $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
                               VALUES (?, 'authentication', 'Password Change', ?, 'users', ?, 'Success', ?, ?, NOW())")
                    ->execute([
                        $me['id'],
                        $audit_detail,
                        $me['id'],
                        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
            }

            // 2. Log to activity_logs
            $tables_act = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
            if (!empty($tables_act)) {
                $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Password Change', ?, ?)")
                    ->execute([$me['id'], $audit_detail, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            }
        } catch (Exception $e) {}

        $msg = 'Password changed successfully! Your account security has been updated.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Derive display name for header
$disp_first = trim($me['first_name'] ?? '');
$disp_last  = trim($me['last_name']  ?? '');
if ($disp_first === '' && $disp_last === '' && !empty($me['name'])) {
    $parts = explode(' ', trim($me['name']), 2);
    $disp_first = $parts[0] ?? '';
    $disp_last  = $parts[1] ?? '';
}
$disp_full = trim("$disp_first $disp_last") ?: ($me['name'] ?? $me['username'] ?? 'User');
$disp_role = strtoupper(normalize_role($me['role'] ?? 'Staff'));

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ═══════════════════════════════════════════
   CHANGE PASSWORD PAGE — dark blue theme
   ═══════════════════════════════════════════ */
.cp-page {
    max-width: 520px;
    margin: 0 auto 80px;
    padding: 0 4px;
}

/* ── Header banner ── */
.cp-banner {
    background: #ffffff;
    border-radius: 14px 14px 0 0;
    padding: 26px 28px 18px;
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    border-bottom: 1px solid #f1f5f9;
}
.cp-banner-icon {
    width: 48px; height: 48px; border-radius: 12px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #00264D; flex-shrink: 0;
    z-index: 1;
}
.cp-banner-text { z-index: 1; }
.cp-banner-title {
    font-size: 18px; font-weight: 800; color: #00264D;
    letter-spacing: 0.3px; margin-bottom: 3px;
    text-transform: uppercase;
}
.cp-banner-sub {
    font-size: 12px; color: #64748b;
    display: flex; align-items: center; gap: 6px;
}
.cp-banner-sub strong { color: #1e293b; }

/* ── Card body ── */
.cp-body {
    background: #fff;
    border-radius: 0 0 14px 14px;
    box-shadow: 0 6px 24px rgba(0,0,0,0.09);
    padding: 26px 28px 28px;
}

/* ── Alert ── */
.cp-alert {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 13px 16px; border-radius: 10px;
    margin-bottom: 20px; font-size: 13px; font-weight: 600;
    border: 1.5px solid;
}
.cp-alert-icon {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0;
}
.cp-alert.success {
    background: #edfaf2; color: #155724;
    border-color: #b8dfc4;
}
.cp-alert.success .cp-alert-icon { background: #28a745; color: #fff; }
.cp-alert.error {
    background: #fff0f0; color: #721c24;
    border-color: #f5c6cb;
}
.cp-alert.error .cp-alert-icon { background: #CC0000; color: #fff; }

/* ── Field group ── */
.cp-fg { margin-bottom: 18px; }
.cp-fg label {
    display: block; margin-bottom: 6px;
    font-size: 11px; font-weight: 800; color: #00264D;
    text-transform: uppercase; letter-spacing: 0.6px;
}
.cp-input-wrap {
    position: relative; display: flex; align-items: center;
}
.cp-input-icon {
    position: absolute; left: 13px;
    color: #00264D; font-size: 14px; opacity: 0.5;
    pointer-events: none;
}
.cp-input {
    width: 100%; padding: 11px 42px 11px 38px;
    border: 1.5px solid #d0d8e4; border-radius: 9px;
    font-size: 14px; color: #1a1a2e;
    box-sizing: border-box;
    background: #f8fafd;
    transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
}
.cp-input:focus {
    outline: none;
    border-color: #00264D;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(0,38,77,0.12);
}
.cp-input.valid   { border-color: #28a745; background: #fff; }
.cp-input.invalid { border-color: #CC0000; background: #fff; }


/* Disable Edge/Browser built-in password reveal button so only single custom eye icon shows */
input[type="password"]::-ms-reveal,
input[type="password"]::-ms-clear,
input[type="password"]::-webkit-contacts-auto-fill-button,
input[type="password"]::-webkit-credentials-auto-fill-button {
    display: none !important;
    width: 0 !important;
    height: 0 !important;
    visibility: hidden !important;
    pointer-events: none !important;
}

.cp-eye {
    position: absolute; right: 12px;
    background: none !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
    cursor: pointer;
    color: #aab4c0;
    font-size: 14px;
    padding: 4px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    -webkit-appearance: none;
    appearance: none;
    transition: color 0.2s;
    z-index: 2;
}
.cp-eye:hover { color: #00264D !important; background: none !important; }

/* ── Strength bar ── */
.cp-strength { margin-top: 8px; }
.cp-strength-bar {
    height: 4px; border-radius: 4px;
    background: #e9ecef; overflow: hidden; margin-bottom: 4px;
}
.cp-strength-fill {
    height: 100%; border-radius: 4px;
    transition: width 0.3s ease, background 0.3s ease;
    width: 0%;
}
.cp-strength-label {
    font-size: 11px; font-weight: 600; color: #888;
}

/* ── Requirements checklist ── */
.cp-reqs {
    background: #f4f7fb;
    border: 1.5px solid #dce4f0;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 22px;
}
.cp-reqs-title {
    font-size: 11px; font-weight: 800; color: #00264D;
    text-transform: uppercase; letter-spacing: 0.5px;
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.cp-req-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; }
.cp-req {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 600; color: #888;
    transition: color 0.2s;
}
.cp-req-dot {
    width: 18px; height: 18px; border-radius: 50%;
    border: 2px solid #ccc;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; color: transparent;
    transition: all 0.2s; flex-shrink: 0;
}
.cp-req.met { color: #28a745; }
.cp-req.met .cp-req-dot {
    background: #28a745; border-color: #28a745; color: #fff;
}
.cp-req.fail { color: #CC0000; }
.cp-req.fail .cp-req-dot {
    background: #CC0000; border-color: #CC0000; color: #fff;
}

/* ── Divider ── */
.cp-divider {
    height: 1px; background: #eef0f4;
    margin: 22px 0;
}

/* ── Buttons ── */
.cp-btn-row { display: flex; gap: 10px; }
.cp-btn {
    flex: 1; padding: 12px 16px;
    border: none; border-radius: 9px;
    font-size: 14px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all 0.2s; text-decoration: none;
    letter-spacing: 0.2px;
}
.cp-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.15); }
.cp-btn:active { transform: translateY(0); box-shadow: none; }

.cp-btn-primary {
    background: linear-gradient(135deg, #00264D 0%, #003a70 100%);
    color: #fff;
}
.cp-btn-primary:hover { background: linear-gradient(135deg, #003a70 0%, #004d99 100%); }
.cp-btn-primary:disabled {
    background: #b0bec5; cursor: not-allowed;
    transform: none; box-shadow: none;
}

.cp-btn-back {
    background: #f4f7fb;
    color: #00264D;
    border: 1.5px solid #dce4f0;
    flex: 0 0 auto;
    padding: 12px 20px;
}
.cp-btn-back:hover { background: #e8eef7; border-color: #00264D; }
</style>

<div class="cp-page">

    <?php if ($msg): ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof window.showPetronFlash === "function") {
            window.showPetronFlash(<?php echo json_encode($msg); ?>, "success", 5000);
        } else if (typeof window.showTxnAlert === "function") {
            window.showTxnAlert(<?php echo json_encode($msg); ?>, "success");
        }
    });
    </script>
    <?php endif; ?>

    <?php if ($error): ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof window.showPetronFlash === "function") {
            window.showPetronFlash(<?php echo json_encode($error); ?>, "error", 5000);
        } else if (typeof window.showTxnAlert === "function") {
            window.showTxnAlert(<?php echo json_encode($error); ?>, "error");
        }
        var curInput = document.getElementById("current_password");
        var errText  = <?php echo json_encode(strtolower($error)); ?>;
        if (curInput && (errText.indexOf("current password") !== -1 || errText.indexOf("incorrect") !== -1)) {
            curInput.style.setProperty("border-color", "#dc2626", "important");
            curInput.style.setProperty("box-shadow", "0 0 0 3px rgba(220, 38, 38, 0.2)", "important");
            curInput.focus();
        }
    });
    </script>
    <?php endif; ?>

    <!-- Banner -->
    <div class="cp-banner">
        <div class="cp-banner-icon">
            <i class="fas fa-lock"></i>
        </div>
        <div class="cp-banner-text">
            <div class="cp-banner-title">Change Password</div>
            <div class="cp-banner-sub">
                <i class="fas fa-user-circle"></i>
                <strong><?php echo htmlspecialchars(strtoupper($disp_full)); ?></strong>
                &nbsp;·&nbsp; <?php echo htmlspecialchars($disp_role); ?>
            </div>
        </div>
    </div>

    <!-- Body -->
    <div class="cp-body">

        <form method="POST" id="cpForm" autocomplete="off" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo function_exists('sec_generate_csrf_token') ? sec_generate_csrf_token() : ''; ?>">

            <!-- Current Password -->
            <div class="cp-fg">
                <label for="current_password">Current Password</label>
                <div class="cp-input-wrap">
                    <span class="cp-input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" id="current_password" name="current_password"
                           class="cp-input" placeholder="Enter your current password" required
                           autocomplete="current-password">
                    <button type="button" class="cp-eye" onclick="toggleEye('current_password','eye0')" tabindex="-1">
                        <i class="fas fa-eye" id="eye0"></i>
                    </button>
                </div>
            </div>

            <div class="cp-divider"></div>

            <!-- New Password -->
            <div class="cp-fg">
                <label for="new_password">New Password</label>
                <div class="cp-input-wrap">
                    <span class="cp-input-icon"><i class="fas fa-key"></i></span>
                    <input type="password" id="new_password" name="new_password"
                           class="cp-input" placeholder="Enter your new password" required
                           autocomplete="new-password" oninput="checkStrength(); checkReqs();">
                    <button type="button" class="cp-eye" onclick="toggleEye('new_password','eye1')" tabindex="-1">
                        <i class="fas fa-eye" id="eye1"></i>
                    </button>
                </div>
                <!-- Strength bar -->
                <div class="cp-strength">
                    <div class="cp-strength-bar">
                        <div class="cp-strength-fill" id="strengthFill"></div>
                    </div>
                    <span class="cp-strength-label" id="strengthLabel">Enter a password</span>
                </div>
            </div>

            <!-- Confirm Password -->
            <div class="cp-fg">
                <label for="confirm_password">Confirm New Password</label>
                <div class="cp-input-wrap">
                    <span class="cp-input-icon"><i class="fas fa-check-circle"></i></span>
                    <input type="password" id="confirm_password" name="confirm_password"
                           class="cp-input" placeholder="Re-enter your new password" required
                           autocomplete="new-password" oninput="checkReqs();">
                    <button type="button" class="cp-eye" onclick="toggleEye('confirm_password','eye2')" tabindex="-1">
                        <i class="fas fa-eye" id="eye2"></i>
                    </button>
                </div>
            </div>

            <!-- Requirements checklist -->
            <div class="cp-reqs">
                <div class="cp-reqs-title">
                    <i class="fas fa-shield-alt"></i> Password Requirements
                </div>
                <ul class="cp-req-list">
                    <li class="cp-req" id="req-len">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        At least <?= (int)$sec_min_pass_len ?> characters long
                    </li>
                    <li class="cp-req" id="req-diff">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        Different from current password
                    </li>
                    <li class="cp-req" id="req-match">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        Both passwords match
                    </li>
                    <?php if ($sec_req_upper): ?>
                    <li class="cp-req" id="req-upper">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        Contains uppercase letter (A-Z)
                    </li>
                    <?php endif; ?>
                    <?php if ($sec_req_numbers): ?>
                    <li class="cp-req" id="req-num">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        Contains a number (0-9)
                    </li>
                    <?php endif; ?>
                    <?php if ($sec_req_special): ?>
                    <li class="cp-req" id="req-special">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        Contains special character (!@#$%^&*)
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Buttons -->
            <div class="cp-btn-row">
                <a href="profile.php" class="cp-btn cp-btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <button type="submit" class="cp-btn cp-btn-primary" id="submitBtn">
                    <i class="fas fa-lock"></i> Update Password
                </button>
            </div>

        </form>
    </div><!-- /cp-body -->
</div><!-- /cp-page -->

<script>
window.SYSTEM_SECURITY_CONFIG = {
    min_password_length: <?= (int)$sec_min_pass_len ?>,
    require_uppercase: <?= $sec_req_upper ? 'true' : 'false' ?>,
    require_numbers: <?= $sec_req_numbers ? 'true' : 'false' ?>,
    require_special_chars: <?= $sec_req_special ? 'true' : 'false' ?>
};

/* ── Toggle password visibility ── */
function toggleEye(inputId, iconId) {
    var inp  = document.getElementById(inputId);
    var icon = document.getElementById(iconId);
    if (!inp || !icon) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

/* ── Password strength ── */
function checkStrength() {
    var val  = document.getElementById('new_password').value;
    var fill = document.getElementById('strengthFill');
    var lbl  = document.getElementById('strengthLabel');
    if (!fill || !lbl) return;

    var minLen = window.SYSTEM_SECURITY_CONFIG ? window.SYSTEM_SECURITY_CONFIG.min_password_length : 8;
    var score = 0;
    if (val.length >= minLen)          score++;
    if (val.length >= minLen + 4)      score++;
    if (/[A-Z]/.test(val))            score++;
    if (/[0-9]/.test(val))            score++;
    if (/[^A-Za-z0-9]/.test(val))     score++;

    var pct   = ['0%','20%','40%','65%','85%','100%'][score];
    var color = ['#e0e0e0','#CC0000','#ff8c00','#f0c040','#28a745','#00264D'][score];
    var text  = ['Enter a password','Very Weak','Weak','Fair','Strong','Very Strong'][score];

    fill.style.width     = pct;
    fill.style.background = color;
    lbl.textContent      = text;
    lbl.style.color      = color;
}

/* ── Requirements live check ── */
function checkReqs() {
    var np  = document.getElementById('new_password').value;
    var cp  = document.getElementById('confirm_password').value;
    var cur = document.getElementById('current_password').value;
    var cfg = window.SYSTEM_SECURITY_CONFIG || { min_password_length: 8, require_uppercase: true, require_numbers: true, require_special_chars: true };

    setReq('req-len',     np.length >= cfg.min_password_length);
    setReq('req-diff',    np.length > 0 && cur.length > 0 && np !== cur);
    setReq('req-match',   np.length > 0 && cp.length > 0 && np === cp);
    setReq('req-upper',   /[A-Z]/.test(np));
    setReq('req-num',     /[0-9]/.test(np));
    setReq('req-special', /[!@#$%^&*(),.?":{}|<>\_\-]/.test(np));

    /* Visual feedback on confirm field */
    var conf = document.getElementById('confirm_password');
    if (cp.length > 0) {
        conf.classList.toggle('valid',   np === cp);
        conf.classList.toggle('invalid', np !== cp);
    } else {
        conf.classList.remove('valid','invalid');
    }
}

function setReq(id, met) {
    var el = document.getElementById(id);
    if (!el) return;
    var icon = el.querySelector('.cp-req-dot i');
    var npVal = document.getElementById('new_password').value;
    var isFail = !met && npVal.length > 0;

    el.classList.toggle('met',  met);
    el.classList.toggle('fail', isFail);

    if (icon) {
        if (met) {
            icon.className = 'fas fa-check';
        } else if (isFail) {
            icon.className = 'fas fa-times';
        } else {
            icon.className = 'fas fa-check';
        }
    }
    if (!npVal.length) {
        el.classList.remove('fail');
        if (icon) icon.className = 'fas fa-check';
    }
}

window.resetCpFieldValidation = function(el) {
    if (!el) return;
    el.style.borderColor = '';
    el.style.boxShadow = '';
};

function highlightCpError(fieldId, msg) {
    ['current_password', 'new_password', 'confirm_password'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.style.borderColor = '';
            el.style.boxShadow = '';
        }
    });
    var target = document.getElementById(fieldId);
    if (target) {
        target.style.setProperty('border-color', '#dc2626', 'important');
        target.style.setProperty('box-shadow', '0 0 0 3px rgba(220, 38, 38, 0.2)', 'important');
        target.focus();
    }
    if (typeof window.showPetronFlash === 'function') {
        window.showPetronFlash(msg, 'error', 4500);
    } else if (typeof window.showTxnAlert === 'function') {
        window.showTxnAlert(msg, 'error');
    } else {
        alert(msg);
    }
}

/* ── Form submit guard ── */
document.getElementById('cpForm').addEventListener('submit', function(e) {
    var cur = (document.getElementById('current_password')?.value || '').trim();
    var np  = (document.getElementById('new_password')?.value || '').trim();
    var cp  = (document.getElementById('confirm_password')?.value || '').trim();
    var cfg = window.SYSTEM_SECURITY_CONFIG || { min_password_length: 8, require_uppercase: true, require_numbers: true, require_special_chars: true };

    if (!cur) {
        e.preventDefault();
        highlightCpError('current_password', 'Please enter your current password.');
        return false;
    }
    if (!np || np.length < cfg.min_password_length) {
        e.preventDefault();
        highlightCpError('new_password', 'New password must be at least ' + cfg.min_password_length + ' characters long.');
        return false;
    }
    if (cfg.require_uppercase && !/[A-Z]/.test(np)) {
        e.preventDefault();
        highlightCpError('new_password', 'New password must contain at least one uppercase letter (A-Z).');
        return false;
    }
    if (cfg.require_numbers && !/[0-9]/.test(np)) {
        e.preventDefault();
        highlightCpError('new_password', 'New password must contain at least one number (0-9).');
        return false;
    }
    if (cfg.require_special_chars && !/[!@#$%^&*(),.?":{}|<>\_\-]/.test(np)) {
        e.preventDefault();
        highlightCpError('new_password', 'New password must contain at least one special character (!@#$%^&* etc.).');
        return false;
    }
    if (np === cur) {
        e.preventDefault();
        highlightCpError('new_password', 'New password must be different from current password.');
        return false;
    }
    if (np !== cp) {
        e.preventDefault();
        highlightCpError('confirm_password', 'Passwords do not match.');
        return false;
    }

    var btn = document.getElementById('submitBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    }
});

/* ── Auto-dismiss success alert ── */
setTimeout(function() {
    var a = document.getElementById('cpAlert');
    if (a && a.classList.contains('success')) {
        a.style.transition = 'opacity 0.5s';
        a.style.opacity = '0';
        setTimeout(function() { a.remove(); }, 500);
    }
}, 5000);
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
