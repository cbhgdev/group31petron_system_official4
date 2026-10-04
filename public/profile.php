<?php
$page_id = 'profile';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me            = current_user();
$my_role       = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

function col_exists(PDO $pdo, string $table, string $col): bool {
    try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch(); }
    catch (Exception $e) { return false; }
}

$has_first_name      = col_exists($pdo, 'users', 'first_name');
$has_last_name       = col_exists($pdo, 'users', 'last_name');
$has_phone           = col_exists($pdo, 'users', 'phone_number');
$has_profile_picture = col_exists($pdo, 'users', 'profile_picture');

if (!$has_first_name)      { try { $pdo->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) DEFAULT NULL AFTER id");        $has_first_name = true;      } catch (Exception $e) {} }
if (!$has_last_name)       { try { $pdo->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) DEFAULT NULL AFTER first_name"); $has_last_name = true;       } catch (Exception $e) {} }
if (!$has_profile_picture) { try { $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL");             $has_profile_picture = true; } catch (Exception $e) {} }

// Refresh current user data from database
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$me['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { 
        unset($row['password_hash']); 
        $me = $row; 
        $_SESSION['user'] = array_merge($_SESSION['user'], $me); 
    }
} catch (Exception $e) {}

$raw_role = strtolower(trim((string)($me['role'] ?? 'staff')));

// Determine standard role details & station details per specification
$station_name = 'Petron Station — Vamenta Blvd.';
$branch_location = 'Vamenta Blvd., Carmen, Cagayan de Oro City, Misamis Oriental';

$role_display_title = 'OPERATIONS STAFF';
$role_badge = 'Operations Staff';
$shift_label = '';
$schedule_label = '';

if ($raw_role === 'admin') {
    $role_display_title = 'ADMIN / OWNER';
    $role_badge = 'Admin / Owner';
} elseif ($raw_role === 'manager') {
    $role_display_title = 'MANAGER';
    $role_badge = 'Manager';
} elseif ($raw_role === 'superadmin' || $raw_role === 'developer') {
    $role_display_title = 'DEVELOPER';
    $role_badge = 'Developer';
} else {
    // Operations Staff Real-Time Clock Shift Detection (STRICTLY REAL-TIME CLOCK DRIVEN)
    petron_init_dynamic_timezone();
    $cur_hour = (int)date('G');
    if ($cur_hour >= 6 && $cur_hour < 14) {
        $shift_label    = 'Shift 1';
        $schedule_label = '6:00 AM – 2:00 PM';
    } else {
        $shift_label    = 'Shift 2';
        $schedule_label = '2:00 PM – 12:00 MN';
    }
    $role_display_title = 'OPERATIONS STAFF — ' . strtoupper($shift_label);
    $role_badge         = 'Operations Staff';
}

$last_login = 'Never';
try {
    $stmt = $pdo->prepare("SELECT created_at FROM activity_logs WHERE user_id = ? AND (action = 'Login' OR action LIKE '%Login%') ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$me['id']]);
    $lr = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lr) {
        $last_login = date('M j, Y \a\t h:i A', strtotime($lr['created_at']));
    } else {
        $last_login = date('M j, Y \a\t h:i A');
    }
} catch (Exception $e) {
    $last_login = date('M j, Y \a\t h:i A');
}

$msg = ''; $msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Update Profile Form (Full Name / First Name / Last Name / Phone / Email if permitted)
    if ($action === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $phone      = trim($_POST['phone']      ?? '');

        if ($first_name === '' && $last_name === '') {
            $msg = 'First name or Last name is required.'; 
            $msg_type = 'error';
        } else {
            try {
                $full_name = trim("$first_name $last_name");
                $sets = []; 
                $params = [];

                if ($has_first_name) { $sets[] = "first_name = ?"; $params[] = $first_name; }
                if ($has_last_name)  { $sets[] = "last_name = ?";  $params[] = $last_name;  }
                if ($has_phone)      { $sets[] = "phone_number = ?"; $params[] = $phone; }
                
                if (!empty($sets)) {
                    $params[] = $me['id'];
                    $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                }

                $_SESSION['user']['name']         = $full_name;
                $_SESSION['user']['first_name']   = $first_name;
                $_SESSION['user']['last_name']    = $last_name;
                if ($has_phone) $_SESSION['user']['phone_number'] = $phone;

                $me['name']         = $full_name; 
                $me['first_name']   = $first_name;
                $me['last_name']    = $last_name; 
                $me['phone_number'] = $phone;

                // Log change to activity and audit logs
                try { 
                    $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Profile Update', 'User updated profile information', ?)")
                        ->execute([$me['id'], $_SERVER['REMOTE_ADDR']]); 
                } catch (Exception $e) {}

                $msg = 'Profile updated successfully!';
            } catch (Exception $e) { 
                $msg = 'Error: ' . $e->getMessage(); 
                $msg_type = 'error'; 
            }
        }
    }

    // 2. Upload / Replace Profile Picture
    if ($action === 'upload_picture') {
        if (!$has_profile_picture) {
            $msg = 'Profile picture feature not available.'; 
            $msg_type = 'error';
        } elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_picture'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                $msg = 'Only JPG, JPEG, PNG, GIF, or WebP images are allowed.'; 
                $msg_type = 'error';
            } elseif ($file['size'] > 3 * 1024 * 1024) {
                $msg = 'Image must be under 3 MB.'; 
                $msg_type = 'error';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) $ext = 'jpg';
                
                $filename = 'profile_' . $me['id'] . '_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                    $old = $me['profile_picture'] ?? '';
                    if ($old && file_exists(__DIR__ . '/../' . ltrim($old, '/'))) {
                        @unlink(__DIR__ . '/../' . ltrim($old, '/'));
                    }
                    $rel = 'uploads/profiles/' . $filename;
                    $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?")->execute([$rel, $me['id']]);
                    $_SESSION['user']['profile_picture'] = $rel;
                    $me['profile_picture'] = $rel;
                    $msg = 'Profile photo updated successfully!';
                } else { 
                    $msg = 'Failed to save uploaded photo.'; 
                    $msg_type = 'error'; 
                }
            }
        } else {
            $msg = 'No photo uploaded or upload error occurred.'; 
            $msg_type = 'error';
        }
    }

    // 3. Remove Profile Picture (Return to default avatar)
    if ($action === 'remove_picture') {
        $old = $me['profile_picture'] ?? '';
        if ($old && file_exists(__DIR__ . '/../' . ltrim($old, '/'))) {
            @unlink(__DIR__ . '/../' . ltrim($old, '/'));
        }
        $pdo->prepare("UPDATE users SET profile_picture = NULL WHERE id = ?")->execute([$me['id']]);
        $_SESSION['user']['profile_picture'] = null;
        $me['profile_picture'] = null;
        $msg = 'Profile photo removed. Returned to default avatar.';
    }
}

// Prepare display values
$disp_first = htmlspecialchars($me['first_name'] ?? '');
$disp_last  = htmlspecialchars($me['last_name']  ?? '');
if ($disp_first === '' && $disp_last === '' && !empty($me['name'])) {
    $parts = explode(' ', trim($me['name']), 2);
    $disp_first = htmlspecialchars($parts[0] ?? '');
    $disp_last  = htmlspecialchars($parts[1] ?? '');
}
$disp_full = trim(strip_tags($disp_first) . ' ' . strip_tags($disp_last)) ?: htmlspecialchars($me['name'] ?? $me['username'] ?? 'User');

$pic_url = '';
if (!empty($me['profile_picture'])) {
    $sn = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pp = strpos($sn, '/public/');
    $ab = $pp !== false ? substr($sn, 0, $pp) : rtrim(dirname($sn), '/');
    $pic_url = $ab . '/' . ltrim($me['profile_picture'], '/');
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* ═══════════════════════════════════════════════
   MY PROFILE — ULTRA PREMIUM PROFESSIONAL DESIGN
   ═══════════════════════════════════════════════ */

.pf-page {
    max-width: 920px;
    margin: 0 auto 80px;
    padding: 0 12px;
}

/* ── 1. Super Compact White Hero Header Card ── */
.pf-header-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 14px 18px 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
    margin-bottom: 16px;
    border: 1px solid #e2e8f0;
    width: 100%;
    box-sizing: border-box;
}

.pf-avatar-container {
    width: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    margin-bottom: 10px;
}

.pf-avatar-frame {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    border: 2.5px solid #00264D;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    color: #00264D;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    transition: transform 0.2s ease;
}
.pf-avatar-frame:hover {
    transform: scale(1.04);
}

.pf-avatar-frame img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.pf-header-info {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.pf-full-name {
    font-size: 16px;
    font-weight: 800;
    color: #00264D;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    margin-bottom: 2px;
    word-break: break-word;
    max-width: 100%;
}

.pf-role-line {
    margin-bottom: 4px;
}

.pf-role-badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #fff0f1;
    border: 1px solid #fecaca;
    color: #E30613;
    padding: 2px 10px;
    border-radius: 16px;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 0.4px;
    text-transform: uppercase;
}

.pf-station-tagline {
    font-size: 11.5px;
    font-weight: 500;
    color: #64748b;
    margin-bottom: 10px;
    word-break: break-word;
}

.pf-photo-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    width: 100%;
}

.pf-btn-photo {
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
    text-decoration: none;
    border: none;
}

.pf-btn-photo-change {
    background: #f1f5f9 !important;
    color: #00264D !important;
    border: 1px solid #cbd5e1 !important;
}
.pf-btn-photo-change:hover {
    background: #e2e8f0 !important;
}

.pf-btn-photo-remove {
    background: #fee2e2 !important;
    color: #dc2626 !important;
    border: 1px solid #fecaca !important;
}
.pf-btn-photo-remove:hover {
    background: #fca5a5 !important;
}

/* ── 2. Navigation Tabs (Pill Segmented Control) ── */
.pf-tabs {
    display: flex !important;
    background: #e2e8f0 !important;
    border-radius: 16px !important;
    padding: 5px !important;
    gap: 6px !important;
    margin-bottom: 24px !important;
    border: 1px solid #cbd5e1 !important;
}

.pf-tab {
    flex: 1 !important;
    padding: 12px 16px !important;
    text-align: center !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #475569 !important;
    border-radius: 12px !important;
    border: none !important;
    background: transparent !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    cursor: pointer !important;
}

.pf-tab:hover {
    color: #00264D !important;
    background: rgba(255, 255, 255, 0.6) !important;
}

.pf-tab.active {
    background: #00264D !important;
    color: #ffffff !important;
    box-shadow: 0 4px 14px rgba(0, 38, 77, 0.25) !important;
}

.pf-tab i, .pf-tab span {
    color: inherit !important;
}

/* ── 3. Panels & Card Grid Layout ── */
.pf-panel {
    display: none;
}
.pf-panel.active {
    display: block;
    animation: fadeIn 0.25s ease-in-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

.pf-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.pf-info-card {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    padding: 24px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.pf-info-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.07);
}

.pf-sec-title {
    font-size: 13px;
    font-weight: 800;
    color: #00264D;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 18px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 10px;
}
.pf-sec-title i {
    color: #E30613;
    font-size: 15px;
}

/* Field Item Block */
.pf-field-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.pf-field-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: #f8fafc;
    border-radius: 14px;
    border: 1px solid #f1f5f9;
}

.pf-field-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.pf-field-icon {
    width: 36px;
    height: 36px;
    border-radius: 12px;
    background: #e0f2fe;
    color: #0284c7;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}

.pf-field-label {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
}

.pf-field-val {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    margin-top: 1px;
    word-break: break-word;
}

.pf-status-online {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #dcfce7;
    color: #15803d;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 12px;
}
.pf-status-online i {
    font-size: 7px;
    animation: pulse 1.8s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.4; transform: scale(1.3); }
}

/* Edit Form Styles */
.pf-edit-card {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    padding: 28px;
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.05);
}

.pf-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-bottom: 24px;
}

.pf-fg {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.pf-fg label {
    font-size: 11.5px;
    font-weight: 800;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.pf-fg label span { color: #E30613; }

.pf-input {
    width: 100%;
    padding: 11px 15px;
    border: 1.5px solid #cbd5e1;
    border-radius: 12px;
    font-size: 13.5px;
    color: #1e293b;
    box-sizing: border-box;
    transition: all 0.2s ease;
    background: #ffffff;
}

.pf-input:focus {
    outline: none;
    border-color: #00264D;
    box-shadow: 0 0 0 3.5px rgba(0, 38, 77, 0.12);
}

.pf-input[readonly] {
    background: #f8fafc;
    color: #64748b;
    cursor: not-allowed;
    border-color: #e2e8f0;
}

.pf-readonly-badge {
    font-size: 10.5px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 4px;
    font-weight: 500;
}

/* Action Buttons Bar */
.pf-actions {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.pf-btn {
    padding: 11px 24px !important;
    border-radius: 12px !important;
    font-size: 13px !important;
    font-weight: 800 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    transition: all 0.2s ease !important;
    text-decoration: none !important;
}

.pf-btn-primary {
    background: #00264D !important;
    color: #ffffff !important;
    border: 2px solid #00264D !important;
    box-shadow: 0 4px 14px rgba(0, 38, 77, 0.22) !important;
}
.pf-btn-primary i, .pf-btn-primary span {
    color: #ffffff !important;
}
.pf-btn-primary:hover {
    background: #001833 !important;
    border-color: #001833 !important;
    color: #ffffff !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 20px rgba(0, 38, 77, 0.32) !important;
}

.pf-btn-secondary {
    background: #ffffff !important;
    color: #00264D !important;
    border: 2px solid #00264D !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
}
.pf-btn-secondary i, .pf-btn-secondary span {
    color: #00264D !important;
}
.pf-btn-secondary:hover {
    background: #00264D !important;
    color: #ffffff !important;
    border-color: #00264D !important;
    transform: translateY(-2px) !important;
}
.pf-btn-secondary:hover i, .pf-btn-secondary:hover span {
    color: #ffffff !important;
}

/* Modal for Photo Preview */
.pf-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.75);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 20px;
    backdrop-filter: blur(4px);
}
.pf-modal-backdrop.open {
    display: flex;
}

.pf-modal-box {
    background: #ffffff;
    border-radius: 20px;
    max-width: 440px;
    width: 100%;
    padding: 28px;
    text-align: center;
    box-shadow: 0 16px 50px rgba(0,0,0,0.45);
    animation: modalPop 0.25s ease;
}
@keyframes modalPop {
    from { opacity: 0; transform: scale(0.92); }
    to   { opacity: 1; transform: scale(1); }
}

.pf-modal-preview-img {
    width: 190px;
    height: 190px;
    border-radius: 50%;
    object-fit: cover;
    margin: 18px auto;
    border: 4px solid #00264D;
    display: block;
    box-shadow: 0 6px 20px rgba(0,0,0,0.25);
}

/* Alert Notification */
/* Floating Alert Notification on Right Side */
.pf-alert {
    position: fixed;
    top: 84px;
    right: 24px;
    z-index: 999999;
    min-width: 280px;
    max-width: 420px;
    padding: 14px 18px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13.5px;
    font-weight: 600;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
    animation: pfSlideInRight 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    transition: opacity 0.3s ease, transform 0.3s ease;
}
.pf-alert.success { background: #ffffff; color: #15803d; border: 1px solid #bbf7d0; }
.pf-alert.error   { background: #ffffff; color: #b91c1c; border: 1px solid #fecaca; }

@keyframes pfSlideInRight {
    from { opacity: 0; transform: translateX(50px); }
    to { opacity: 1; transform: translateX(0); }
}

@media (max-width: 650px) {
    .pf-form-grid { grid-template-columns: 1fr; }
    .pf-alert { right: 12px; left: 12px; max-width: none; }
}
</style>

<div class="pf-page">

    <?php if ($msg): ?>
    <div class="pf-alert <?php echo $msg_type; ?>" id="pfAlert">
        <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>" style="font-size:18px; flex-shrink:0;"></i>
        <span style="flex:1;"><?php echo htmlspecialchars($msg); ?></span>
    </div>
    <script>
    setTimeout(function() {
        var a = document.getElementById('pfAlert');
        if (a) {
            a.style.opacity = '0';
            a.style.transform = 'translateX(50px)';
            setTimeout(function() { if (a && a.parentNode) a.remove(); }, 300);
        }
    }, 4500);
    </script>
    <?php endif; ?>

    <!-- ── 1. HERO PROFILE HEADER CARD ── -->
    <div class="pf-header-card">
        <div class="pf-avatar-container">
            <div class="pf-avatar-frame" id="headerAvatarFrame" onclick="previewCurrentPhoto()" style="cursor:pointer;" title="Click to view full photo">
                <?php if ($pic_url): ?>
                    <img src="<?php echo htmlspecialchars($pic_url); ?>" alt="<?php echo htmlspecialchars($disp_full); ?>" id="avatarImg">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
        </div>

        <div class="pf-header-info">
            <div class="pf-full-name"><?php echo htmlspecialchars(strtoupper($disp_full)); ?></div>
            <div class="pf-role-line">
                <span class="pf-role-badge-pill">
                    <i class="fas fa-user-shield"></i>
                    <span><?php echo htmlspecialchars($role_display_title); ?></span>
                </span>
            </div>
            <div class="pf-station-tagline">
                <i class="fas fa-gas-pump me-1"></i> Petron Station &amp; Service Center &nbsp;•&nbsp; <?php echo htmlspecialchars($station_name); ?>
            </div>
        </div>

        <div class="pf-photo-actions">
            <button type="button" class="pf-btn-photo pf-btn-photo-change" onclick="document.getElementById('photoFileInput').click();">
                <i class="fas fa-camera"></i> Change Photo
            </button>
            <?php if ($pic_url): ?>
            <button type="button" class="pf-btn-photo pf-btn-photo-change" onclick="previewCurrentPhoto()">
                <i class="fas fa-eye"></i> Preview
            </button>
            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove your profile photo and return to default avatar?');">
                <input type="hidden" name="action" value="remove_picture">
                <button type="submit" class="pf-btn-photo pf-btn-photo-remove">
                    <i class="fas fa-trash-alt"></i> Remove
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Hidden Photo Upload Form -->
    <form method="post" enctype="multipart/form-data" id="photoUploadForm" style="display:none;">
        <input type="hidden" name="action" value="upload_picture">
        <input type="file" id="photoFileInput" name="profile_picture"
               accept=".jpg,.jpeg,.png,.webp"
               onchange="handlePhotoSelected(this);">
    </form>

    <!-- ── 2. SEGMENTED NAVIGATION TABS ── -->
    <div class="pf-tabs" role="tablist">
        <button class="pf-tab active" onclick="switchProfileTab('info')" id="tab-info" role="tab" aria-selected="true">
            <i class="fas fa-id-card"></i>
            <span>Profile Info</span>
        </button>
        <button class="pf-tab" onclick="switchProfileTab('edit')" id="tab-edit" role="tab" aria-selected="false">
            <i class="fas fa-user-edit"></i>
            <span>Edit Profile</span>
        </button>
        <button class="pf-tab" onclick="switchProfileTab('activity')" id="tab-activity" role="tab" aria-selected="false">
            <i class="fas fa-history"></i>
            <span>Account Activity</span>
        </button>
    </div>

    <!-- ── 3. TAB PANELS ── -->

    <!-- TAB 1: Profile Information Cards -->
    <div class="pf-panel active" id="panel-info" role="tabpanel">
        <div class="pf-card-grid">

            <!-- Card A: Personal Details -->
            <div class="pf-info-card">
                <div class="pf-sec-title">
                    <i class="fas fa-user"></i>
                    <span>Personal Details</span>
                </div>
                <div class="pf-field-list">
                    <div class="pf-field-item">
                        <div class="pf-field-left">
                            <div class="pf-field-icon" style="background:#e0f2fe; color:#0284c7;">
                                <i class="fas fa-user-tag"></i>
                            </div>
                            <div>
                                <p class="pf-field-label">Full Name</p>
                                <p class="pf-field-val"><?php echo htmlspecialchars($disp_full); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="pf-field-item">
                        <div class="pf-field-left">
                            <div class="pf-field-icon" style="background:#f0fdf4; color:#16a34a;">
                                <i class="fas fa-at"></i>
                            </div>
                            <div>
                                <p class="pf-field-label">Username</p>
                                <p class="pf-field-val"><?php echo htmlspecialchars($me['username'] ?? '—'); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="pf-field-item">
                        <div class="pf-field-left">
                            <div class="pf-field-icon" style="background:#fef3c7; color:#d97706;">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div>
                                <p class="pf-field-label">Email Address</p>
                                <p class="pf-field-val"><?php echo htmlspecialchars($me['email'] ?? 'Not set'); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="pf-field-item">
                        <div class="pf-field-left">
                            <div class="pf-field-icon" style="background:#fae8ff; color:#c026d3;">
                                <i class="fas fa-phone-alt"></i>
                            </div>
                            <div>
                                <p class="pf-field-label">Contact Number</p>
                                <p class="pf-field-val"><?php echo htmlspecialchars($me['phone_number'] ?? 'Not set'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card B: Role & Station Assignment -->
            <div class="pf-info-card">
                <div class="pf-sec-title">
                    <i class="fas fa-shield-alt"></i>
                    <span>Role &amp; Assignment</span>
                </div>
                <div class="pf-field-list">
                    <div class="pf-field-item">
                        <div class="pf-field-left">
                            <div class="pf-field-icon" style="background:#e0e7ff; color:#4338ca;">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div>
                                <p class="pf-field-label">Assigned Role</p>
                                <p class="pf-field-val" style="color:#00264D; font-weight:800;"><?php echo htmlspecialchars($role_badge); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="pf-field-item">
                        <div class="pf-field-left">
                            <div class="pf-field-icon" style="background:#ffedd5; color:#ea580c;">
                                <i class="fas fa-building"></i>
                            </div>
                            <div>
                                <p class="pf-field-label">Branch Station</p>
                                <p class="pf-field-val"><?php echo htmlspecialchars($station_name); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php if ($shift_label): ?>
                    <div class="pf-field-item">
                        <div class="pf-field-left">
                            <div class="pf-field-icon" style="background:#f0fdf4; color:#15803d;">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <p class="pf-field-label">Shift Assignment</p>
                                <p class="pf-field-val"><?php echo htmlspecialchars($shift_label); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="pf-field-item">
                        <div class="pf-field-left">
                            <div class="pf-field-icon" style="background:#e0f2fe; color:#0369a1;">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div>
                                <p class="pf-field-label">Work Schedule</p>
                                <p class="pf-field-val"><?php echo htmlspecialchars($schedule_label); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="pf-field-item">
                        <div class="pf-field-left">
                            <div class="pf-field-icon" style="background:#dcfce7; color:#15803d;">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div>
                                <p class="pf-field-label">Account Status</p>
                                <p class="pf-field-val">
                                    <span class="pf-status-online"><i class="fas fa-circle"></i> Active</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="pf-actions">
            <button type="button" class="pf-btn pf-btn-primary" onclick="switchProfileTab('edit')">
                <i class="fas fa-user-edit"></i> <span>Edit Profile Information</span>
            </button>
            <a href="update_password.php" class="pf-btn pf-btn-secondary">
                <i class="fas fa-key"></i> <span>Change Password</span>
            </a>
        </div>
    </div>

    <!-- TAB 2: Edit Profile -->
    <div class="pf-panel" id="panel-edit" role="tabpanel">
        <div class="pf-edit-card">
            <form method="post" id="editProfileForm" autocomplete="off">
                <input type="hidden" name="action" value="update_profile">

                <div class="pf-sec-title">
                    <i class="fas fa-edit"></i>
                    <span>Editable Profile Information</span>
                </div>

                <div class="pf-form-grid">
                    <div class="pf-fg">
                        <label for="first_name">First Name <span>*</span></label>
                        <input type="text" id="first_name" name="first_name" class="pf-input"
                               value="<?php echo htmlspecialchars(strip_tags($disp_first)); ?>"
                               oninput="this.value = this.value.replace(/[^a-zA-Z\s\-'\.\u00C0-\u024F]/g, ''); resetProfileFieldValidation(this);"
                               required placeholder="e.g. Edgar">
                    </div>
                    <div class="pf-fg">
                        <label for="last_name">Last Name <span>*</span></label>
                        <input type="text" id="last_name" name="last_name" class="pf-input"
                               value="<?php echo htmlspecialchars(strip_tags($disp_last)); ?>"
                               oninput="this.value = this.value.replace(/[^a-zA-Z\s\-'\.\u00C0-\u024F]/g, ''); resetProfileFieldValidation(this);"
                               required placeholder="e.g. Eslit">
                    </div>
                    <div class="pf-fg" style="grid-column: 1 / -1;">
                        <label for="phone">Contact Number</label>
                        <input type="text" id="phone" name="phone" class="pf-input"
                               value="<?php echo htmlspecialchars($me['phone_number'] ?? ''); ?>"
                               oninput="this.value = this.value.replace(/[^0-9+]/g, ''); if (this.value.length > 11) this.value = this.value.slice(0, 11); resetProfileFieldValidation(this);"
                               placeholder="e.g. 09452136587">
                    </div>
                </div>

                <div class="pf-sec-title" style="margin-top: 10px;">
                    <i class="fas fa-lock"></i>
                    <span>System Controlled Information</span>
                </div>

                <div class="pf-form-grid">
                    <div class="pf-fg">
                        <label>Username</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($me['username'] ?? ''); ?>" readonly>
                        <span class="pf-readonly-badge"><i class="fas fa-lock"></i> Cannot be changed directly</span>
                    </div>
                    <div class="pf-fg">
                        <label>Email Address</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($me['email'] ?? ''); ?>" readonly>
                        <span class="pf-readonly-badge"><i class="fas fa-lock"></i> Read-only</span>
                    </div>
                    <div class="pf-fg">
                        <label>Role</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($role_badge); ?>" readonly>
                        <span class="pf-readonly-badge"><i class="fas fa-lock"></i> Assigned by Administrator</span>
                    </div>
                    <?php if ($shift_label): ?>
                    <div class="pf-fg">
                        <label>Shift Assignment</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($shift_label . ' (' . $schedule_label . ')'); ?>" readonly>
                        <span class="pf-readonly-badge"><i class="fas fa-lock"></i> Managed by System</span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="pf-actions">
                    <button type="submit" class="pf-btn pf-btn-primary">
                        <i class="fas fa-save"></i> <span>Save Changes</span>
                    </button>
                    <button type="button" class="pf-btn pf-btn-secondary" onclick="switchProfileTab('info')">
                        <i class="fas fa-times"></i> <span>Cancel</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- TAB 3: Account Activity -->
    <div class="pf-panel" id="panel-activity" role="tabpanel">
        <div class="pf-edit-card">
            <div class="pf-sec-title">
                <i class="fas fa-history"></i>
                <span>Recent Account Activity</span>
            </div>

            <div class="pf-field-list mb-4">
                <div class="pf-field-item">
                    <div class="pf-field-left">
                        <div class="pf-field-icon" style="background:#e0f2fe; color:#0284c7;">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                        <div>
                            <p class="pf-field-label">Last Login Timestamp</p>
                            <p class="pf-field-val"><?php echo htmlspecialchars($last_login); ?></p>
                        </div>
                    </div>
                </div>
                <div class="pf-field-item">
                    <div class="pf-field-left">
                        <div class="pf-field-icon" style="background:#f0fdf4; color:#16a34a;">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div>
                            <p class="pf-field-label">Account Registration Date</p>
                            <p class="pf-field-val"><?php echo !empty($me['created_at']) ? date('F j, Y', strtotime($me['created_at'])) : 'N/A'; ?></p>
                        </div>
                    </div>
                </div>
                <div class="pf-field-item">
                    <div class="pf-field-left">
                        <div class="pf-field-icon" style="background:#fef3c7; color:#d97706;">
                            <i class="fas fa-desktop"></i>
                        </div>
                        <div>
                            <p class="pf-field-label">Current Session IP</p>
                            <p class="pf-field-val"><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '::1'); ?></p>
                        </div>
                    </div>
                </div>
            </div>



        </div>
    </div>

</div>

<!-- ── Photo Preview Modal ── -->
<div class="pf-modal-backdrop" id="photoPreviewModal">
    <div class="pf-modal-box">
        <h3 style="margin:0 0 10px;font-size:16px;color:#00264D;font-weight:800;">PROFILE PHOTO PREVIEW</h3>
        <img src="<?php echo htmlspecialchars($pic_url ?: '../assets/img/default-avatar.png'); ?>" alt="Profile Preview" class="pf-modal-preview-img" id="modalPreviewImg">
        <p style="font-size:12px;color:#64748b;margin:0 0 16px;">Allowed formats: JPG, JPEG, PNG, WebP</p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button type="button" class="pf-btn pf-btn-primary" onclick="document.getElementById('photoFileInput').click();closePreviewModal();">
                <i class="fas fa-camera"></i> Upload / Replace
            </button>
            <button type="button" class="pf-btn pf-btn-secondary" onclick="closePreviewModal();">
                Close
            </button>
        </div>
    </div>
</div>

<script>
function switchProfileTab(name) {
    document.querySelectorAll('.pf-tab').forEach(function(t) {
        t.classList.remove('active');
        t.setAttribute('aria-selected', 'false');
    });
    document.querySelectorAll('.pf-panel').forEach(function(p) {
        p.classList.remove('active');
    });
    var tab = document.getElementById('tab-' + name);
    var panel = document.getElementById('panel-' + name);
    if (tab)   { tab.classList.add('active');   tab.setAttribute('aria-selected', 'true'); }
    if (panel) { panel.classList.add('active'); }
}

function previewCurrentPhoto() {
    document.getElementById('photoPreviewModal').classList.add('open');
}

function closePreviewModal() {
    document.getElementById('photoPreviewModal').classList.remove('open');
}

function handlePhotoSelected(input) {
    if (input.files && input.files[0]) {
        var file = input.files[0];
        var validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!validTypes.includes(file.type)) {
            alert('Please select a valid image file (JPG, JPEG, PNG, or WebP).');
            return;
        }
        if (file.size > 3 * 1024 * 1024) {
            alert('File size exceeds 3MB limit.');
            return;
        }
        document.getElementById('photoUploadForm').submit();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('photoPreviewModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closePreviewModal();
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
