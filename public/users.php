<?php
$page_id = 'users';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/station_management.php';
require_once __DIR__ . '/../backend/ui_config.php';
require_once __DIR__ . '/../config/email_config.php';
require_login();

// Dynamic Column Detection
$user_cols = [];
try {
    $col_query = $pdo->query("SHOW COLUMNS FROM users");
    while ($col = $col_query->fetch(PDO::FETCH_ASSOC)) {
        $user_cols[] = $col['Field'];
    }
} catch (Exception $e) { /* ignore */ }

$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

// Access Control:
// Service Staff CANNOT access user management (redirect to dashboard)
// Manager & Admin/Superadmin can view.
if ($my_role === 'staff') {
    header("Location: dashboard.php");
    exit;
}
if (!in_array($my_role, ['manager', 'admin', 'superadmin'], true)) {
    header("Location: dashboard.php");
    exit;
}

$current_tab = $_GET['tab'] ?? 'active';
if (!in_array($current_tab, ['active', 'archived'], true)) {
    $current_tab = 'active';
}

function can_manage_role(string $actor_role, string $target_role): bool {
    $actor = role_key($actor_role);
    $target = role_key($target_role);

    if ($actor === 'superadmin') return true;
    if ($actor === 'admin') return in_array($target, ['staff', 'manager'], true);
    return false; // Manager cannot manage any role
}

if (!function_exists('is_user_archived_status')) {
    function is_user_archived_status(?string $status): bool {
        $normalized = strtolower(trim((string)$status));
        return in_array($normalized, ['disabled', 'archived', 'inactive', 'locked'], true);
    }
}

function generateEmployeeID($pdo, $role) {
    $role = strtolower(trim($role));
    $prefix = 'STF';
    if ($role === 'superadmin') $prefix = 'SA';
    elseif ($role === 'admin') $prefix = 'ADM';
    elseif ($role === 'manager') $prefix = 'MGR';
    
    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE employee_id LIKE ? ORDER BY employee_id DESC LIMIT 1");
    $stmt->execute([$prefix . '-%']);
    $last_id = $stmt->fetchColumn();
    
    $num = 1;
    if ($last_id) {
        $parts = explode('-', $last_id);
        $last_num = (int)end($parts);
        $num = $last_num + 1;
    }
    
    return $prefix . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

// ── Load dynamic security policy from system_settings (never hardcoded) ──
$sec_policy          = function_exists('petron_get_security_policy') ? petron_get_security_policy($my_station_id) : [];
$sec_min_pass_len    = (int)($sec_policy['min_password_length'] ?? 8);
$sec_req_upper       = !empty($sec_policy['require_uppercase']);
$sec_req_numbers     = !empty($sec_policy['require_numbers']);
$sec_req_special     = !empty($sec_policy['require_special_chars']);

function check_user_password_policy(string $password, int $min_len, bool $req_upper, bool $req_num, bool $req_special): void {
    if (function_exists('petron_validate_password_policy')) {
        $res = petron_validate_password_policy($password, [
            'min_password_length'   => $min_len,
            'require_uppercase'     => $req_upper,
            'require_numbers'       => $req_num,
            'require_special_chars' => $req_special
        ]);
        if (!$res['valid']) {
            throw new Exception($res['errors'][0] ?? 'Password does not meet security requirements.');
        }
        return;
    }
    if (strlen($password) < $min_len) {
        throw new Exception("Password must be at least {$min_len} characters long.");
    }
    if ($req_upper && !preg_match('/[A-Z]/', $password)) {
        throw new Exception('Password must contain at least one uppercase letter (A-Z).');
    }
    if ($req_num && !preg_match('/[0-9]/', $password)) {
        throw new Exception('Password must contain at least one number (0-9).');
    }
    if ($req_special && !preg_match('/[!@#$%^&*(),.?":{}|<>\-_]/', $password)) {
        throw new Exception('Password must contain at least one special character (!@#$%^&* etc.).');
    }
}

$msg = '';

// ── AJAX HANDLER FOR EMPLOYEE DETAILS & DOCUMENTS ─────────────────────────
if (isset($_GET['ajax_emp_details']) && !empty($_GET['user_id'])) {
    header('Content-Type: application/json');
    $uid = (int)$_GET['user_id'];
    
    if ($my_role !== 'superadmin') {
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND station_id = ?");
        $chk->execute([$uid, $my_station_id]);
        if (!$chk->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized station access.']);
            exit;
        }
    }
    
    $stmt = $pdo->prepare("SELECT u.*, s.name AS station_name FROM users u LEFT JOIN stations s ON u.station_id = s.id WHERE u.id = ?");
    $stmt->execute([$uid]);
    $emp_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$emp_info) {
        echo json_encode(['success' => false, 'error' => 'Employee record not found.']);
        exit;
    }
    
    unset($emp_info['password_hash']);
    
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            doc_type VARCHAR(100) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'Complete',
            file_name VARCHAR(255) DEFAULT NULL,
            file_path VARCHAR(255) DEFAULT NULL,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_emp_doc_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}

    $docsStmt = $pdo->prepare("SELECT * FROM employee_documents WHERE user_id = ? ORDER BY id ASC");
    $docsStmt->execute([$uid]);
    $existing_docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

    $docs_by_type = [];
    foreach ($existing_docs as $ed) {
        $docs_by_type[$ed['doc_type']] = $ed;
    }
    
    $default_types = ['SSS', 'PhilHealth', 'Pag-IBIG', 'TIN', 'Valid ID'];
    $docs = [];
    foreach ($default_types as $dt) {
        if (isset($docs_by_type[$dt])) {
            $rec = $docs_by_type[$dt];
            if (empty($rec['file_name']) && strtolower($rec['status']) === 'complete') {
                $rec['status'] = 'Missing';
            }
            $docs[] = $rec;
        } else {
            $docs[] = [
                'user_id' => $uid,
                'doc_type' => $dt,
                'status' => 'Missing',
                'file_name' => null,
                'file_path' => null,
                'uploaded_at' => null
            ];
        }
    }
    
    $login_logs = [];
    try {
        $loginStmt = $pdo->prepare("SELECT action, created_at, shift_period FROM audit_trail WHERE user_id = ? AND action LIKE '%login%' ORDER BY created_at DESC LIMIT 20");
        $loginStmt->execute([$uid]);
        $login_logs = $loginStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($login_logs)) {
            $loginStmt2 = $pdo->prepare("SELECT action, created_at FROM activity_logs WHERE user_id = ? AND (action LIKE '%login%' OR action LIKE '%logout%') ORDER BY created_at DESC LIMIT 20");
            $loginStmt2->execute([$uid]);
            $login_logs = $loginStmt2->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
    
    $activities = [];
    try {
        $actStmt = $pdo->prepare("SELECT action, transaction_id, or_number, created_at FROM audit_trail WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
        $actStmt->execute([$uid]);
        $activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($activities)) {
            $actStmt2 = $pdo->prepare("SELECT action, details, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
            $actStmt2->execute([$uid]);
            $activities = $actStmt2->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
    
    try {
        log_activity($pdo, $me['id'], 'Viewed Employee Details', "Viewed employee #$uid ({$emp_info['username']})");
    } catch (Exception $e) {}
    
    echo json_encode([
        'success' => true,
        'info' => $emp_info,
        'documents' => $docs,
        'login_history' => $login_logs,
        'activity_logs' => $activities
    ]);
    exit;
}


if (!function_exists('sanitize_optional_field')) {
    function sanitize_optional_field(?string $val): string {
        if ($val === null) return 'N/A';
        $trimmed = trim($val);
        if ($trimmed === '') return 'N/A';
        $lower = strtolower($trimmed);
        $invalid_placeholders = ['none', 'null', 'n/a', '-', 'unknown', 'not available', 'not_available', 'undefined', 'n.a.', 'n/a.'];
        if (in_array($lower, $invalid_placeholders, true)) {
            return 'N/A';
        }
        return $trimmed;
    }
}

$is_error = false;

// --- ACTION HANDLER (Admin / Superadmin only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($my_role === 'manager') {
        $msg = "Managers have read-only access to user lists and cannot perform modifications.";
        $is_error = true;
    } else {
        try {
            // 1. Add User & Automatically Send Email Credentials
            if ($action === 'add_user') {
                $first_name_input  = trim($_POST['first_name']      ?? '');
                $last_name_input   = trim($_POST['last_name']       ?? '');
                $role_key_input    = trim($_POST['role']            ?? '');
                $contact_raw       = trim($_POST['contact_number']  ?? '');
                $email_input       = trim($_POST['email']           ?? '');
                $username_raw      = trim($_POST['username']        ?? '');
                $raw_password      = trim($_POST['new_password']    ?? '');
                $confirm_password  = trim($_POST['confirm_password']?? '');
                $status_input      = 'Active';

                // ── 1. FIRST NAME Validation (Required, no N/A, valid letters) ──
                if (empty($first_name_input)) {
                    throw new Exception('First Name is required.');
                }
                if (in_array(strtolower($first_name_input), ['n/a', 'none', 'null', '-'], true)) {
                    throw new Exception('First Name cannot be N/A or a placeholder value.');
                }
                if (!preg_match("/^[a-zA-Z\s\-\'\.\p{L}]+$/u", $first_name_input)) {
                    throw new Exception('First Name contains invalid characters. Only letters, spaces, hyphens, and apostrophes are allowed.');
                }

                // ── 2. LAST NAME Validation (Required, no N/A, valid letters) ──
                if (empty($last_name_input)) {
                    throw new Exception('Last Name is required.');
                }
                if (in_array(strtolower($last_name_input), ['n/a', 'none', 'null', '-'], true)) {
                    throw new Exception('Last Name cannot be N/A or a placeholder value.');
                }
                if (!preg_match("/^[a-zA-Z\s\-\'\.\p{L}]+$/u", $last_name_input)) {
                    throw new Exception('Last Name contains invalid characters. Only letters, spaces, hyphens, and apostrophes are allowed.');
                }

                // ── 3. CONTACT NUMBER Validation (Optional -> N/A if empty) ──
                $contact_input = sanitize_optional_field($contact_raw);
                if ($contact_input !== 'N/A') {
                    $clean_contact = preg_replace('/[\s\-\(\)\.]/', '', $contact_input);
                    if (!preg_match('/^(09\d{9}|\+639\d{9}|639\d{9})$/', $clean_contact)) {
                        throw new Exception('Invalid Philippine contact number. Must be an 11-digit mobile number starting with 09 (e.g. 09171234567 or +639171234567).');
                    }
                    if (str_starts_with($clean_contact, '+639')) {
                        $contact_input = '09' . substr($clean_contact, 4);
                    } elseif (str_starts_with($clean_contact, '639')) {
                        $contact_input = '09' . substr($clean_contact, 3);
                    } else {
                        $contact_input = $clean_contact;
                    }
                }

                // ── 4. EMAIL ADDRESS Validation (Required, valid email format) ──
                if (empty($email_input)) {
                    throw new Exception('Email Address is required.');
                }
                if (in_array(strtolower($email_input), ['n/a', 'none', 'null', '-'], true)) {
                    throw new Exception('Email Address cannot be N/A or a placeholder value.');
                }
                if (!filter_var($email_input, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email address format.');
                }
                $chk_email = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
                $chk_email->execute([$email_input]);
                if ($chk_email->fetch()) {
                    throw new Exception('Email Address is already in use by another account.');
                }
                $email = $email_input;

                // ── 5. USERNAME Handling (Optional -> Auto-generate unique username if empty) ──
                $cleaned_username_input = sanitize_optional_field($username_raw);
                if ($cleaned_username_input !== 'N/A') {
                    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $cleaned_username_input)) {
                        throw new Exception('Username can only contain letters, numbers, dots, hyphens, and underscores (no spaces or special characters).');
                    }
                    if (strlen($cleaned_username_input) < 3 || strlen($cleaned_username_input) > 50) {
                        throw new Exception('Username must be between 3 and 50 characters.');
                    }
                    $chk_u = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?)");
                    $chk_u->execute([$cleaned_username_input]);
                    if ($chk_u->fetch()) {
                        throw new Exception('Username is already taken by another account.');
                    }
                    $username = $cleaned_username_input;
                } else {
                    // Auto-generate unique username for database integrity and login compatibility
                    $email_parts = explode('@', $email_input);
                    $base_user = strtolower(preg_replace('/[^a-zA-Z0-9_\.]/', '', $email_parts[0]));
                    if (strlen($base_user) < 3) {
                        $base_user = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $first_name_input . $last_name_input));
                    }
                    if (empty($base_user)) {
                        $base_user = 'user';
                    }
                    $username = $base_user;
                    $counter = 1;
                    while (true) {
                        $chk_u = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?)");
                        $chk_u->execute([$username]);
                        if (!$chk_u->fetch()) {
                            break;
                        }
                        $username = $base_user . $counter;
                        $counter++;
                    }
                }

                // ── 6. ROLE Validation (Required, server-side RBAC enforced) ──
                if (empty($role_key_input)) {
                    throw new Exception('Role selection is required.');
                }
                $role = role_key($role_key_input);
                if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'], true)) {
                    throw new Exception('Invalid role selected. Please select a valid role from the list.');
                }
                if ($my_role === 'admin' && !in_array($role, ['staff', 'manager'], true)) {
                    throw new Exception('As Admin, you can only create Staff or Manager users.');
                }
                if ($my_role === 'superadmin' && $role !== 'admin') {
                    throw new Exception('As Superadmin, you can only create Admin/Owner users.');
                }

                $employee_id_input = generateEmployeeID($pdo, $role);

                // ── 7. PASSWORD & CONFIRM PASSWORD Rules (DB-driven policy) ──
                if (empty($raw_password)) {
                    $password = generateSecurePassword(max(12, $sec_min_pass_len));
                } else {
                    if ($raw_password !== $confirm_password) {
                        throw new Exception('Passwords do not match. Please ensure both passwords are identical.');
                    }
                    check_user_password_policy($raw_password, $sec_min_pass_len, $sec_req_upper, $sec_req_numbers, $sec_req_special);
                    $password = $raw_password;
                }

                if (!empty($employee_id_input) && in_array('employee_id', $user_cols)) {
                    $chk_emp = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
                    $chk_emp->execute([$employee_id_input]);
                    if ($chk_emp->fetch()) throw new Exception('Employee ID is already assigned to another account.');
                }

                // Station assignment
                $station_target = null;
                if ($my_role === 'superadmin') {
                    if (empty($_POST['station_id'])) throw new Exception('Station selection is required.');
                    $station_target = (int)$_POST['station_id'];
                } elseif ($my_role === 'admin') {
                    $station_target = $my_station_id;
                }

                // ── USER LIMITS PER STATION ──
                // Admin/Owner: Maximum 1 active account per station
                if ($role === 'admin' && $station_target) {
                    $ca = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role)='admin' AND station_id=? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $ca->execute([$station_target]);
                    if ((int)$ca->fetchColumn() > 0) {
                        throw new Exception('An Admin/Owner account already exists for this station. Only one Admin/Owner account is allowed per station.');
                    }
                }

                // Manager: Maximum 1 active account per station
                if ($role === 'manager' && $station_target) {
                    $cm = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role)='manager' AND station_id=? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $cm->execute([$station_target]);
                    if ((int)$cm->fetchColumn() > 0) {
                        throw new Exception('A Manager account already exists for this station. Only one Manager account is allowed per station.');
                    }
                }

                // Staff / Operations Staff: NO FIXED ACCOUNT LIMIT (Admin/Owner may create as many Staff accounts as needed)

                $hashed = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("INSERT INTO users
                    (first_name, last_name, username, role, email, password_hash, station_id, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$first_name_input, $last_name_input, $username, $role, $email, $hashed, $station_target, $status_input]);
                $new_user_id = (int)$pdo->lastInsertId();

                $extra_sets = []; $extra_vals = [];
                if (!empty($employee_id_input) && in_array('employee_id', $user_cols)) { $extra_sets[] = 'employee_id = ?'; $extra_vals[] = $employee_id_input; }
                if (in_array('phone_number', $user_cols)) { $extra_sets[] = 'phone_number = ?'; $extra_vals[] = $contact_input; }
                if ($extra_sets) {
                    $extra_vals[] = $new_user_id;
                    $pdo->prepare("UPDATE users SET " . implode(', ', $extra_sets) . " WHERE id = ?")->execute($extra_vals);
                }

                // Station name for email
                $station_name_for_email = 'Petron Service Station';
                if ($station_target) {
                    $stn = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
                    $stn->execute([$station_target]);
                    $stn_row = $stn->fetch(PDO::FETCH_ASSOC);
                    if ($stn_row) $station_name_for_email = $stn_row['name'];
                }

                // AUTOMATIC EMAIL CREDENTIALS SENDING
                $full_name_for_email = trim($first_name_input . ' ' . $last_name_input);
                $email_sent = false;
                $email_error_detail = '';
                if (!empty($email)) {
                    try {
                        $email_sent = (bool)sendAdminCredentialsEmail(
                            $email, $full_name_for_email, $station_name_for_email,
                            $username, $password, $me['role'], $role, $employee_id_input
                        );
                    } catch (Exception $mailEx) {
                        $email_sent = false;
                        $email_error_detail = $mailEx->getMessage();
                        error_log("User credentials email FAILED for $email — " . $email_error_detail);
                    }
                }

                log_activity($pdo, $me['id'], 'Add User',
                    "Created user '$username' ($role)" . ($email_sent ? " (Email sent to $email)" : " (Email NOT sent to $email)") . ($employee_id_input ? " EmpID:$employee_id_input" : ''));

                if ($email_sent) {
                    $msg = "User <strong>" . htmlspecialchars($full_name_for_email) . "</strong> created successfully! Login credentials have been automatically emailed to <strong>" . htmlspecialchars($email) . "</strong>.";
                } else {
                    $msg = "User <strong>" . htmlspecialchars($full_name_for_email) . "</strong> created successfully. Email could not be sent automatically. Initial Temp Password: <strong>" . htmlspecialchars($password) . "</strong> — please share this manually with the employee.";
                    $is_error = false;
                }

                // AJAX: return JSON response and exit
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'message' => $msg]);
                    exit;
                }
            }
            
            // 2. Edit User
            elseif ($action === 'edit_user') {
                $id            = (int)$_POST['user_id'];
                $first_name    = trim($_POST['first_name'] ?? '');
                $last_name     = trim($_POST['last_name'] ?? '');
                $login_id      = trim($_POST['login_id'] ?? '');
                $contact_input = trim($_POST['contact_number'] ?? '');
                $role          = strtolower(trim($_POST['role'] ?? 'staff'));
                $assigned_shift= null;

                if (empty($first_name)) throw new Exception('First Name is required.');
                if (empty($last_name))  throw new Exception('Last Name is required.');
                if (empty($login_id))   throw new Exception('Login ID (Username or Email) is required.');

                $email    = null;
                $username = $login_id;
                if (strpos($login_id, '@') !== false) {
                    $email = $login_id;
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address format.');
                }

                // Philippine Phone Validation
                if (!empty($contact_input)) {
                    $clean_contact = preg_replace('/[\s\-\(\)\.]/', '', $contact_input);
                    if (!preg_match('/^(09\d{9}|\+639\d{9}|639\d{9})$/', $clean_contact)) {
                        throw new Exception('Invalid Philippine contact number. Must be an 11-digit mobile number starting with 09 (e.g. 09171234567 or +639171234567).');
                    }
                    if (str_starts_with($clean_contact, '+639')) {
                        $contact_input = '09' . substr($clean_contact, 4);
                    } elseif (str_starts_with($clean_contact, '639')) {
                        $contact_input = '09' . substr($clean_contact, 3);
                    } else {
                        $contact_input = $clean_contact;
                    }
                }
                
                if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
                    throw new Exception('Invalid role selected.');
                }
                
                if ($my_role !== 'superadmin' && in_array($role, ['admin', 'superadmin'])) {
                    throw new Exception('You cannot assign Admin or Super Admin roles.');
                }

                if ($my_role !== 'superadmin') {
                    $chk = $pdo->prepare("SELECT id, station_id, role FROM users WHERE id = ? AND station_id = ?");
                    $chk->execute([$id, $my_station_id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$target_user) throw new Exception("Unauthorized access to target user.");
                    if (!can_manage_role($my_role, (string)($target_user['role'] ?? 'staff'))) {
                        throw new Exception("You cannot modify this user's role.");
                    }
                } else {
                    $chk = $pdo->prepare("SELECT id, station_id, role FROM users WHERE id = ?");
                    $chk->execute([$id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$target_user) throw new Exception("Target user not found.");
                }
                
                $target_stn = !empty($target_user['station_id']) ? (int)$target_user['station_id'] : ($my_role === 'admin' ? $my_station_id : 0);
                // Station limits enforcement for edit
                if ($role === 'admin' && $target_stn > 0) {
                    $checkAdmin = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'admin' AND station_id = ? AND id != ? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $checkAdmin->execute([$target_stn, $id]);
                    if ((int)$checkAdmin->fetchColumn() > 0) {
                        throw new Exception("An Admin/Owner account already exists for this station. Only one Admin/Owner account is allowed per station.");
                    }
                }
                if ($role === 'manager' && $target_stn > 0) {
                    $checkMgr = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'manager' AND station_id = ? AND id != ? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $checkMgr->execute([$target_stn, $id]);
                    if ((int)$checkMgr->fetchColumn() > 0) {
                        throw new Exception("A Manager account already exists for this station. Only one Manager account is allowed per station.");
                    }
                }

                $dup_sql = 'SELECT id FROM users WHERE username = ? AND id != ?';
                $dup_params = [$username, $id];
                if (!empty($email)) { $dup_sql .= ' OR (email = ? AND id != ?)'; $dup_params[] = $email; $dup_params[] = $id; }
                $stmt = $pdo->prepare($dup_sql);
                $stmt->execute($dup_params);
                if ($stmt->fetch()) throw new Exception('This Username or Email is already registered to another account.');

                $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, role = ?, username = ?, email = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$first_name, $last_name, $role, $username, $email, $id]);

                if (in_array('phone_number', $user_cols)) {
                    $clean_phone = sanitize_optional_field($contact_input);
                    $pdo->prepare("UPDATE users SET phone_number = ? WHERE id = ?")->execute([$clean_phone, $id]);
                }

                if (in_array('assigned_shift', $user_cols)) {
                    $pdo->prepare("UPDATE users SET assigned_shift = NULL WHERE id = ?")->execute([$id]);
                }
                if (in_array('shift_assignment', $user_cols)) {
                    $pdo->prepare("UPDATE users SET shift_assignment = NULL WHERE id = ?")->execute([$id]);
                }
                
                log_activity($pdo, $me['id'], 'Edit User', "Updated details & role for user #$id ($username, $role)");
                $msg = "User details for <strong>" . htmlspecialchars($first_name . ' ' . $last_name) . "</strong> updated successfully.";
            }
            
            // 3. Reset Password
            elseif ($action === 'reset_password') {
                $id = (int)$_POST['user_id'];
                $new_pass = trim($_POST['new_password'] ?? '');
                if (empty($new_pass)) {
                    $new_pass = generateSecurePassword(max(12, $sec_min_pass_len));
                } else {
                    if (in_array(strtolower($new_pass), ['n/a', 'none', 'null', '-'], true)) {
                        throw new Exception('Password cannot be N/A or a placeholder value.');
                    }
                    check_user_password_policy($new_pass, $sec_min_pass_len, $sec_req_upper, $sec_req_numbers, $sec_req_special);
                }

                if ($my_role !== 'superadmin') {
                    $chk = $pdo->prepare("SELECT id, role, email, username, first_name, last_name, employee_id, station_id FROM users WHERE id = ? AND station_id = ?");
                    $chk->execute([$id, $my_station_id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$target_user) throw new Exception("Unauthorized access to user.");
                } else {
                    $chk = $pdo->prepare("SELECT id, role, email, username, first_name, last_name, employee_id, station_id FROM users WHERE id = ?");
                    $chk->execute([$id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                }
                
                $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashed, $id]);

                // Station name for email
                $station_name_for_email = 'Petron Service Station';
                if (!empty($target_user['station_id'])) {
                    $stn = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
                    $stn->execute([$target_user['station_id']]);
                    $stn_row = $stn->fetch(PDO::FETCH_ASSOC);
                    if ($stn_row) $station_name_for_email = $stn_row['name'];
                }

                // Automatically send reset credentials email if email is present
                $full_name_reset = trim(($target_user['first_name'] ?? '') . ' ' . ($target_user['last_name'] ?? '')) ?: $target_user['username'];
                $email_sent = false;
                if (!empty($target_user['email'])) {
                    try {
                        $email_sent = (bool)sendAdminCredentialsEmail(
                            $target_user['email'], $full_name_reset, $station_name_for_email,
                            $target_user['username'], $new_pass, $me['role'], $target_user['role'], $target_user['employee_id'] ?? ''
                        );
                    } catch (Exception $mEx) { $email_sent = false; }
                }
                
                log_activity($pdo, $me['id'], 'Reset Password', "Reset password for user #$id ({$target_user['username']})");
                
                if ($email_sent) {
                    $msg = "Password reset successfully. New temporary password has been emailed to <strong>" . htmlspecialchars($target_user['email']) . "</strong>.";
                } else {
                    $msg = "Password reset successfully. Temporary password: <strong>" . htmlspecialchars($new_pass) . "</strong>";
                }
            }
            
            // 4. Archive User (Move to Inactive/Archived state)
            elseif ($action === 'archive_user') {
                $id = (int)$_POST['user_id'];
                
                if ($id == $me['id']) throw new Exception("You cannot archive your own account.");
                
                if ($my_role !== 'superadmin') {
                    $chk = $pdo->prepare("SELECT id, role, username FROM users WHERE id = ? AND station_id = ?");
                    $chk->execute([$id, $my_station_id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$target_user) throw new Exception("Unauthorized access to user.");
                } else {
                    $chk = $pdo->prepare("SELECT id, role, username FROM users WHERE id = ?");
                    $chk->execute([$id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                }
                
                $stmt = $pdo->prepare("UPDATE users SET status = 'Archived', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                
                log_activity($pdo, $me['id'], 'Archive User', "Archived user #$id ({$target_user['username']})");
                $msg = "User <strong>" . htmlspecialchars($target_user['username']) . "</strong> has been archived. They can no longer log in, but all records are saved forever.";
            }

            // 5. Restore User (Move back to Active state)
            elseif ($action === 'restore_user') {
                $id = (int)$_POST['user_id'];
                
                if ($my_role !== 'superadmin') {
                    $chk = $pdo->prepare("SELECT id, role, station_id, username FROM users WHERE id = ? AND station_id = ?");
                    $chk->execute([$id, $my_station_id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$target_user) throw new Exception("Unauthorized access to user.");
                } else {
                    $chk = $pdo->prepare("SELECT id, role, station_id, username FROM users WHERE id = ?");
                    $chk->execute([$id]);
                    $target_user = $chk->fetch(PDO::FETCH_ASSOC);
                }
                
                $target_role = strtolower(trim($target_user['role'] ?? 'staff'));
                $target_stn  = (int)($target_user['station_id'] ?? 0);

                // Station limits enforcement for restore
                if ($target_role === 'admin' && $target_stn > 0) {
                    $chkAdm = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'admin' AND station_id = ? AND id != ? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $chkAdm->execute([$target_stn, $id]);
                    if ((int)$chkAdm->fetchColumn() > 0) {
                        throw new Exception("An Admin/Owner account already exists for this station. Only one Admin/Owner account is allowed per station.");
                    }
                }
                if ($target_role === 'manager' && $target_stn > 0) {
                    $chkMgr = $pdo->prepare("SELECT COUNT(*) FROM users WHERE LOWER(role) = 'manager' AND station_id = ? AND id != ? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
                    $chkMgr->execute([$target_stn, $id]);
                    if ((int)$chkMgr->fetchColumn() > 0) {
                        throw new Exception("A Manager account already exists for this station. Only one Manager account is allowed per station.");
                    }
                }

                $stmt = $pdo->prepare("UPDATE users SET status = 'Active', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                
                log_activity($pdo, $me['id'], 'Restore User', "Restored archived user #$id ({$target_user['username']}) to Active status");
                $msg = "User <strong>" . htmlspecialchars($target_user['username']) . "</strong> has been restored to Active status.";
            }

            // Permanent Delete Attempt Check
            elseif ($action === 'delete_user') {
                throw new Exception('User deletion is permanently disabled. Inactive accounts are archived to preserve data integrity.');
            }
            
        } catch (Exception $e) {
            $msg = $e->getMessage();
            $is_error = true;
            // AJAX: return JSON error and exit
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => $msg]);
                exit;
            }
        }
    }
}

// --- FETCH USERS FOR DISPLAY ---
$user_list_columns = "
    u.id,
    u.employee_id,
    u.first_name,
    u.last_name,
    CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS name,
    u.username,
    u.role,
    u.email,
    u.phone_number,
    u.station_id,
    u.assigned_shift,
    u.status,
    u.created_at,
    u.updated_at,
    s.name AS station_name
";

$all_users = [];
if ($my_role === 'superadmin') {
    $stmt = $pdo->query("
        SELECT {$user_list_columns}
        FROM users u
        LEFT JOIN stations s ON u.station_id = s.id
        ORDER BY u.created_at DESC
    ");
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("
        SELECT {$user_list_columns}
        FROM users u
        LEFT JOIN stations s ON u.station_id = s.id
        WHERE u.station_id = ?
          AND LOWER(u.role) IN ('manager', 'staff', 'operations_staff', 'operations staff')
        ORDER BY u.role, u.username
    ");
    $stmt->execute([$my_station_id]);
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Separate Active vs Archived lists
$active_users   = [];
$archived_users = [];

foreach ($all_users as $u) {
    if (is_user_archived_status($u['status'] ?? '')) {
        $archived_users[] = $u;
    } else {
        $active_users[] = $u;
    }
}

// Fetch Station Name for Header/Modal
$station_name = '';
if ($my_station_id) {
    $stn_stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $stn_stmt->execute([$my_station_id]);
    $station_name = $stn_stmt->fetchColumn() ?: 'Station #' . $my_station_id;
}

// ── Check active Admin & Manager count per station (1-account-per-station enforcement) ──
$station_manager_count = 0;
$station_manager_name  = '';
$station_admin_count   = 0;
$station_admin_name    = '';
if ($my_station_id) {
    try {
        $mgr_chk = $pdo->prepare("SELECT COUNT(*), CONCAT(first_name,' ',last_name) AS mgr_name FROM users WHERE LOWER(role)='manager' AND station_id=? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
        $mgr_chk->execute([$my_station_id]);
        $mgr_row = $mgr_chk->fetch(PDO::FETCH_NUM);
        $station_manager_count = (int)($mgr_row[0] ?? 0);
        $station_manager_name  = trim((string)($mgr_row[1] ?? ''));

        $adm_chk = $pdo->prepare("SELECT COUNT(*), CONCAT(first_name,' ',last_name) AS adm_name FROM users WHERE LOWER(role)='admin' AND station_id=? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
        $adm_chk->execute([$my_station_id]);
        $adm_row = $adm_chk->fetch(PDO::FETCH_NUM);
        $station_admin_count = (int)($adm_row[0] ?? 0);
        $station_admin_name  = trim((string)($adm_row[1] ?? ''));
    } catch (Exception $e) {}
}
$manager_slot_taken = ($station_manager_count >= 1);
$admin_slot_taken   = ($station_admin_count >= 1);

// Get UI Config
try {
    $station_ui_config = StationManager::getStationUIConfig($my_role, $my_station_id, $station_name);
} catch (Exception $e) {
    $station_ui_config = [
        'type' => 'readonly_field',
        'value' => $station_name ?: 'Unknown Station',
        'hidden_input_value' => $my_station_id,
        'readonly' => true,
        'help_text' => 'Station assignment'
    ];
}

// ── AJAX JSON POLLING ENDPOINT FOR USER MANAGEMENT ─────────────────
if (isset($_GET['ajax_um']) && $_GET['ajax_um'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'counts' => [
            'active'   => count($active_users),
            'archived' => count($archived_users),
            'total'    => count($all_users)
        ]
    ]);
    exit;
}

// ── AJAX: Check role slot availability for a given station (Superadmin use) ──
if (isset($_GET['ajax_check_slots']) && !empty($_GET['station_id'])) {
    header('Content-Type: application/json');
    $chk_stn = (int)$_GET['station_id'];
    try {
        // Manager count
        $mgrQ = $pdo->prepare("SELECT COUNT(*), CONCAT(first_name,' ',last_name) FROM users WHERE LOWER(role)='manager' AND station_id=? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
        $mgrQ->execute([$chk_stn]);
        $mgrRow = $mgrQ->fetch(PDO::FETCH_NUM);
        $mgrCount = (int)($mgrRow[0] ?? 0);
        $mgrName  = trim((string)($mgrRow[1] ?? ''));

        // Admin count
        $admQ = $pdo->prepare("SELECT COUNT(*), CONCAT(first_name,' ',last_name) FROM users WHERE LOWER(role)='admin' AND station_id=? AND LOWER(status) NOT IN ('disabled','archived','inactive')");
        $admQ->execute([$chk_stn]);
        $admRow = $admQ->fetch(PDO::FETCH_NUM);
        $admCount = (int)($admRow[0] ?? 0);
        $admName  = trim((string)($admRow[1] ?? ''));

        echo json_encode([
            'success'       => true,
            'manager_taken' => ($mgrCount >= 1),
            'manager_name'  => $mgrName,
            'admin_taken'   => ($admCount >= 1),
            'admin_name'    => $admName,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* --- CLEAN MODAL OVERLAY & CONTAINER BOX (With Ample Header & Footer Space) --- */
.modal {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(15, 23, 42, 0.65) !important;
    backdrop-filter: blur(4px) !important;
    z-index: 99999 !important;
    display: none;
    align-items: center !important;
    justify-content: center !important;
    padding-top: 85px !important;   /* Clear top navbar with clean visible space */
    padding-bottom: 75px !important;/* Clear bottom footer */
    padding-left: 20px !important;
    padding-right: 20px !important;
    box-sizing: border-box !important;
}

.modal-content {
    background: #ffffff !important;
    border-radius: 14px !important;
    width: 100% !important;
    max-width: 580px !important;
    max-height: calc(100vh - 170px) !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3) !important;
    border: 1px solid #cbd5e1 !important;
    overflow: hidden !important;
    margin: auto !important;
    animation: modalSlideUp .2s ease-out;
}

@keyframes modalSlideUp {
    from { transform: translateY(14px); opacity: 0; }
    to   { transform: translateY(0); opacity: 1; }
}

@keyframes toastSlideIn {
    from { transform: translateX(50px); opacity: 0; }
    to   { transform: translateX(0); opacity: 1; }
}

.modal-header {
    background: #f8fafc !important;
    border-bottom: 1px solid #e2e8f0 !important;
    padding: 14px 20px !important;
    flex-shrink: 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
}

.modal-title {
    color: #002F70 !important;
    font-weight: 800 !important;
    font-size: 15px !important;
    margin: 0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
}

.modal-close {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    width: 28px !important;
    height: 28px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 18px !important;
    color: #64748b !important;
    cursor: pointer !important;
    line-height: 1 !important;
    padding: 0 !important;
    transition: all 0.15s !important;
}
.modal-close:hover {
    background: #fee2e2 !important;
    color: #dc2626 !important;
    border-color: #fca5a5 !important;
}

.modal-body {
    padding: 20px 22px !important;
    overflow-y: auto !important;
    flex: 1 !important;
    min-height: 0 !important;
}

.modal-footer {
    background: #f8fafc !important;
    border-top: 1px solid #f1f5f9 !important;
    padding: 12px 20px !important;
    flex-shrink: 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 10px !important;
}

/* --- FORM & INPUT STYLES --- */
.form-section-title {
    font-size: 13px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.6px !important;
    color: #002F70 !important;
    margin: 16px 0 10px !important;
    padding-bottom: 4px !important;
    border-bottom: 1px solid #e2e8f0 !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.form-section-title:first-child {
    margin-top: 0 !important;
}

.form-grid-2 {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 12px !important;
    margin-bottom: 10px !important;
}
@media(max-width: 520px) {
    .form-grid-2 { grid-template-columns: 1fr !important; }
}

.form-group {
    display: flex !important;
    flex-direction: column !important;
    gap: 5px !important;
    margin-bottom: 12px !important;
}

.form-group .lbl,
.lbl {
    font-size: 13px !important;
    font-weight: 700 !important;
    color: #475569 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.4px !important;
    margin-bottom: 3px !important;
}

.form-group input.inp,
.form-group select.inp,
.inp {
    height: 42px !important;
    padding: 0 14px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    font-size: 14px !important;
    color: #1e293b !important;
    background: #ffffff !important;
    outline: none !important;
    width: 100% !important;
    box-sizing: border-box !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}

.form-group input.inp:focus,
.form-group select.inp:focus,
.inp:focus {
    border-color: #002F70 !important;
    box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1) !important;
}

.btn-dice {
    height: 42px !important;
    width: 42px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    background: #f8fafc !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    color: #002F70 !important;
    font-size: 16px !important;
    cursor: pointer !important;
    flex-shrink: 0 !important;
    transition: all 0.15s !important;
}
.btn-dice:hover {
    background: #002F70 !important;
    color: #ffffff !important;
    border-color: #002F70 !important;
}

/* --- BUTTON STYLES --- */
.btn-plain-cancel {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #334155 !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    padding: 0 18px !important;
    height: 40px !important;
    border-radius: 7px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: all 0.15s !important;
}
.btn-plain-cancel:hover {
    background: #f8fafc !important;
    color: #0f172a !important;
    border-color: #94a3b8 !important;
}

.btn-header-add {
    background: #002F70 !important;
    background-color: #002F70 !important;
    color: #ffffff !important;
    border: 1px solid #002F70 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    padding: 0 20px !important;
    height: 40px !important;
    border-radius: 7px !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all .15s ease-in-out !important;
    white-space: nowrap !important;
    box-shadow: 0 2px 5px rgba(0,47,112,0.2) !important;
    text-decoration: none !important;
}
.btn-header-add i,
.btn-header-add span {
    color: #ffffff !important;
}
.btn-header-add:hover {
    background: #001f4d !important;
    background-color: #001f4d !important;
    color: #ffffff !important;
    border-color: #001f4d !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 8px rgba(0,47,112,0.3) !important;
}

.btn-plain-submit {
    background: #002F70 !important;
    background-color: #002F70 !important;
    border: 1px solid #002F70 !important;
    color: #ffffff !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    padding: 10px 22px !important;
    height: 40px !important;
    border-radius: 7px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    transition: all 0.15s !important;
}
.btn-plain-submit i,
.btn-plain-submit span {
    color: #ffffff !important;
}
.btn-plain-submit:hover {
    background: #001f4d !important;
    background-color: #001f4d !important;
    color: #ffffff !important;
    border-color: #001f4d !important;
}

.btn-plain-danger {
    background: transparent !important;
    border: 1px solid #dc3545 !important;
    color: #dc3545 !important;
    font-size: 13.5px !important;
    font-weight: 700 !important;
    padding: 9px 20px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: all 0.2s !important;
}
.btn-plain-danger:hover {
    background: #dc3545 !important;
    color: #ffffff !important;
}

.btn-plain-success {
    background: transparent !important;
    border: 1px solid #16a34a !important;
    color: #16a34a !important;
    font-size: 13.5px !important;
    font-weight: 700 !important;
    padding: 9px 20px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: all 0.2s !important;
}
.btn-plain-success:hover {
    background: #16a34a !important;
    color: #ffffff !important;
}

/* Tabs - Reports-style boxed design */
.um-tabs {
    display: flex !important; flex-wrap: wrap !important;
    margin-bottom: 22px !important;
    border: 1px solid #d1d9e6 !important; border-radius: 0 !important;
    overflow: hidden !important; border-bottom: 3px solid #00264D !important;
    gap: 0 !important; background: transparent !important;
    padding: 0 !important; width: 100% !important;
}
.um-tab-btn {
    flex: 1 !important; min-width: 140px !important;
    padding: 14px 20px !important; font-size: 14px !important; font-weight: 700 !important;
    color: #334155 !important; background: #ffffff !important;
    border: none !important; border-right: 1px solid #d1d9e6 !important;
    border-radius: 0 !important; text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important; align-items: center !important;
    justify-content: center !important; gap: 8px !important;
    text-transform: uppercase !important; letter-spacing: 0.3px !important;
    text-align: center !important; cursor: pointer !important;
    margin-bottom: 0 !important; box-shadow: none !important;
}
.um-tab-btn:last-child { border-right: none !important; }
.um-tab-btn:hover { background: #f1f5f9 !important; color: #00264D !important; text-decoration: none !important; }
.um-tab-btn.active {
    background: #00264D !important; color: #ffffff !important;
    font-weight: 800 !important; box-shadow: none !important;
}
.um-badge-cnt {
    background: #dc2626 !important;
    color: #ffffff !important;
    padding: 3px 9px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
}
/* Table Action Buttons: ALL PLAIN OUTLINE (No filled background color) */
.action-btn,
.btn-archive,
.btn-restore {
    border-radius: 6px !important;
    padding: 6px 14px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all .2s !important;
    background: transparent !important;
    box-shadow: none !important;
    width: 88px !important;
    text-align: center !important;
}

/* <i class="fas fa-eye"></i> View Button */
.btn-view,
.action-btn.btn-view {
    border: 1px solid #16a34a !important;
    color: #16a34a !important;
    background: transparent !important;
}
.btn-view:hover,
.action-btn.btn-view:hover {
    background: #16a34a !important;
    color: #ffffff !important;
}

/* <i class="fas fa-pencil-alt"></i>️ Edit Button */
.btn-edit,
.action-btn.btn-edit {
    border: 1px solid #16a34a !important;
    color: #16a34a !important;
    background: transparent !important;
}
.btn-edit:hover,
.action-btn.btn-edit:hover {
    background: #16a34a !important;
    color: #ffffff !important;
}

/* <i class="fas fa-key"></i> Reset Button */
.btn-reset,
.action-btn.btn-reset {
    border: 1px solid #00264D !important;
    color: #00264D !important;
    background: transparent !important;
}
.btn-reset:hover,
.action-btn.btn-reset:hover {
    background: #00264D !important;
    color: #ffffff !important;
}

/* <i class="fas fa-box"></i> Archive Button */
.btn-archive,
.action-btn.btn-archive {
    border: 1px solid #dc3545 !important;
    color: #dc3545 !important;
    background: transparent !important;
}
.btn-archive:hover,
.action-btn.btn-archive:hover {
    background: #dc3545 !important;
    color: #ffffff !important;
}

.btn-restore:hover,
.action-btn.btn-restore:hover {
    background: #16a34a !important;
    color: #ffffff !important;
}

.page-head {
    display:flex; justify-content:space-between; gap:16px; align-items:center;
    margin-top: 0 !important; margin-bottom: 16px !important;
    padding:0 !important; border:none !important; width:100%;
}
.page-head h1, .page-head .h1 {
    margin:0; color:#002f70 !important; font-size:24px !important;
    font-weight:700 !important; text-transform:uppercase !important;
    letter-spacing:0.5px !important;
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif !important;
    display:flex !important; align-items:center !important; gap:10px !important; line-height:1.2 !important;
}
.um-wrap {
    padding: 0 !important;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}

/* COMPREHENSIVE VIEW EMPLOYEE DETAILS MODAL STYLES (Reports-style sub-tabs) */
.vtab-nav {
    display: flex !important;
    flex-wrap: wrap !important;
    margin-bottom: 18px !important;
    border: 1px solid #cbd5e1 !important;
    border-bottom: 3px solid #00264D !important;
    border-radius: 6px !important;
    overflow: hidden !important;
    gap: 0 !important;
    background: #ffffff !important;
    padding: 0 !important;
    width: 100% !important;
}
.vtab-btn {
    flex: 1 !important;
    padding: 11px 16px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #334155 !important;
    background: #ffffff !important;
    border: none !important;
    border-right: 1px solid #cbd5e1 !important;
    border-radius: 0 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    transition: all 0.15s ease !important;
}
.vtab-btn:last-child {
    border-right: none !important;
}
.vtab-btn:hover {
    background: #f1f5f9 !important;
    color: #00264D !important;
}
.vtab-btn.active {
    background: #00264D !important;
    color: #ffffff !important;
    font-weight: 800 !important;
}
.vtab-btn.active i,
.vtab-btn.active span {
    color: #ffffff !important;
}
.vtab-btn i {
    font-size: 13px !important;
}
.vtab-pane {
    display: none;
}
.vtab-pane.active {
    display: block;
}
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
@media(max-width: 580px) {
    .info-grid { grid-template-columns: 1fr; }
}
.info-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 16px;
}
.info-lbl {
    font-size: 12.5px !important;
    font-weight: 700 !important;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    display: block;
    margin-bottom: 4px;
}
.info-val {
    font-size: 15px !important;
    color: #0f172a;
    font-weight: 700 !important;
}


/* Reports-Style Export Bar & Buttons (Identical to Reports Module) */
.rpt-export-group {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    margin-left: auto !important;
    white-space: nowrap !important;
}
.rpt-export-btn {
    padding: 8px 14px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: #ffffff !important;
    border: 1px solid !important;
    transition: all 0.18s !important;
    text-decoration: none !important;
}
.rpt-btn-print  { color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; color: #475569 !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; color: #dc2626 !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; color: #16a34a !important; }
.rpt-btn-csv   { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdf4 !important; color: #16a34a !important; }


/* Exact Horizontal Filter Bar Alignment Overrides */
.um-filter-row {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    margin-bottom: 22px !important;
    background: transparent !important;
    padding: 0 !important;
    border: none !important;
    flex-wrap: wrap !important;
    width: 100% !important;
}
.um-filter-left {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    flex-wrap: wrap !important;
}
.um-filter-right {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    flex-wrap: wrap !important;
    margin-left: auto !important;
}
.um-flt-item {
    height: 38px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    font-size: 13.5px !important;
    background: #ffffff !important;
    color: #334155 !important;
    display: inline-block !important;
    box-sizing: border-box !important;
    margin: 0 !important;
}


/* Filter Button High Contrast White Text */
.rpt-btn-apply {
    height: 38px !important;
    font-size: 13.5px !important;
    font-weight: 700 !important;
    border-radius: 6px !important;
    background: #00264D !important;
    background-color: #00264D !important;
    color: #ffffff !important;
    border: 1px solid #00264D !important;
}
.rpt-btn-apply i,
.rpt-btn-apply span,
.rpt-btn-apply * {
    color: #ffffff !important;
}

</style>

<div class="um-wrap">
<div class="page-head">
    <div>
        <h1 class="h1">USER MANAGEMENT</h1>
    </div>
</div>

<?php if ($msg): ?>
<!-- Floating Top-Right Toast Notification (Clearly Below Header) -->
<div id="floatingToastMsg" style="position: fixed; top: 95px; right: 24px; z-index: 2147483647; max-width: 450px; background: #ffffff; border: 2px solid <?php echo $is_error ? '#fca5a5' : '#10b981'; ?>; border-radius: 12px; box-shadow: 0 12px 35px rgba(0,0,0,0.18); padding: 14px 18px; display: flex; align-items: flex-start; gap: 12px; animation: toastSlideIn .3s ease-out;">
    <div style="width: 36px; height: 36px; border-radius: 50%; background: <?php echo $is_error ? '#fef2f2' : '#ecfdf5'; ?>; color: <?php echo $is_error ? '#dc2626' : '#059669'; ?>; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; border: 1.5px solid <?php echo $is_error ? '#fca5a5' : '#a7f3d0'; ?>;">
        <i class="fas <?php echo $is_error ? 'fa-exclamation' : 'fa-check'; ?>"></i>
    </div>
    <div style="flex: 1; min-width: 0;">
        <div style="font-weight: 800; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; color: <?php echo $is_error ? '#dc2626' : '#059669'; ?>;">
            <?php echo $is_error ? 'System Notice / Error' : 'Success Notification'; ?>
        </div>
        <div style="font-size: 13.5px; font-weight: 700; color: #002F70; line-height: 1.4;">
            <?php echo $msg; ?>
        </div>
    </div>
</div>
<script>
setTimeout(function() {
    var toast = document.getElementById('floatingToastMsg');
    if (toast) {
        toast.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(function() { toast.remove(); }, 500);
    }
}, 8000);
</script>
<?php endif; ?>

<!-- EXACT USER MANAGEMENT FILTER & EXPORT ARRANGEMENT -->
<div class="um-filter-row">
    <!-- LEFT SIDE: Search employee, All Roles, All Status, Filter, Clear -->
    <div class="um-filter-left">
        <!-- 1. Search employee -->
        <div style="position: relative; width: 220px; display: inline-block;">
            <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px;"></i>
            <input type="text" id="empSearchInput" onkeyup="filterEmployeeTable()" placeholder="Search employee..." class="um-flt-item" style="padding-left: 34px; width: 220px !important;">
        </div>
        
        <!-- 2. All Roles -->
        <select id="empRoleFilter" onchange="filterEmployeeTable()" class="um-flt-item" style="width: 125px !important; padding: 0 10px;">
            <option value="">All Roles</option>
            <option value="manager">Manager</option>
            <option value="staff">Staff</option>
        </select>
        
        <!-- 3. All Status -->
        <select id="empStatusFilter" onchange="filterEmployeeTable()" class="um-flt-item" style="width: 125px !important; padding: 0 10px;">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="archived">Archived</option>
        </select>

        <!-- 4. Filter Button -->
        <button type="button" onclick="filterEmployeeTable()" class="rpt-btn-apply" style="height: 38px !important; padding: 0 16px !important; font-size: 13.5px !important; font-weight: 700 !important; border-radius: 6px !important; background: #00264D !important; color: #ffffff !important; border: 1px solid #00264D !important; display: inline-flex !important; align-items: center !important; gap: 7px !important;">
            <i class="fas fa-filter" style="color: #ffffff !important;"></i> <span style="color: #ffffff !important;">Filter</span>
        </button>

        <!-- 5. Clear Button -->
        <button type="button" onclick="clearEmployeeFilters()" class="btn-plain-cancel" style="height: 38px !important; padding: 0 14px !important; font-size: 13.5px !important; border-radius: 6px !important;" title="Reset all filters">
            <i class="fas fa-undo"></i> Clear
        </button>
    </div>

    <!-- RIGHT SIDE: Print, PDF, Excel, CSV ONLY -->
    <div class="um-filter-right">
        <div class="rpt-export-group" style="margin-left: 0 !important; gap: 6px !important;">
            <button type="button" class="rpt-export-btn rpt-btn-print" onclick="triggerEmployeeExport('print')">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" class="rpt-export-btn rpt-btn-pdf" onclick="triggerEmployeeExport('pdf')">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <button type="button" class="rpt-export-btn rpt-btn-excel" onclick="triggerEmployeeExport('excel')">
                <i class="fas fa-file-excel"></i> Excel
            </button>
            <button type="button" class="rpt-export-btn rpt-btn-csv" onclick="triggerEmployeeExport('csv')">
                <i class="fas fa-file-csv"></i> CSV
            </button>
        </div>
    </div>
</div>

<!-- ADD NEW USER BUTTON (BELOW FILTERS & EXPORTS, RIGHT ABOVE TABLE/TABS) -->
<?php if ($my_role === 'admin' || $my_role === 'superadmin'): ?>
<div style="display: flex; justify-content: flex-end; margin-bottom: 14px;">
    <button type="button" onclick="openAddModal()" class="btn-header-add">
        <i class="fas fa-plus"></i> <span>Add New User</span>
    </button>
</div>
<?php endif; ?>

<!-- DUAL TAB NAVIGATION -->
<div class="um-tabs">
    <a href="users.php?tab=active" class="um-tab-btn <?php echo $current_tab === 'active' ? 'active' : ''; ?>">
        <i class="fas fa-user-check"></i> Active Users <span class="um-badge-cnt" id="um_badge_active"><?php echo count($active_users); ?></span>
    </a>
    <a href="users.php?tab=archived" class="um-tab-btn <?php echo $current_tab === 'archived' ? 'active' : ''; ?>">
        <i class="fas fa-archive"></i> Archived Users <span class="um-badge-cnt" id="um_badge_archived"><?php echo count($archived_users); ?></span>
    </a>
</div>

<?php if ($current_tab === 'active' || $current_tab === 'archived'): ?>
    <?php 
    $display_list = ($current_tab === 'active') ? $active_users : $archived_users; 
    ?>
    <div class="card" style="border-radius:10px; overflow:hidden; border:1px solid #cbd5e1; box-shadow: 0 2px 8px rgba(0,0,0,.06);">
        <div class="table-wrap" style="overflow-x:hidden !important; width:100% !important;">
            <table class="table no-min-width" style="width:100%; table-layout:fixed; border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="width:<?php echo $my_role === 'superadmin' ? '9%' : '10%'; ?>; padding:13px 12px; font-size:14px; font-weight:800; white-space:nowrap;">EMPLOYEE ID</th>
                        <th style="width:<?php echo $my_role === 'superadmin' ? '23%' : '26%'; ?>; padding:13px 12px; font-size:14px; font-weight:800;">NAME</th>
                        <th style="width:<?php echo $my_role === 'superadmin' ? '23%' : '27%'; ?>; padding:13px 12px; font-size:14px; font-weight:800;">USERNAME</th>
                        <th style="width:<?php echo $my_role === 'superadmin' ? '9%' : '11%'; ?>; padding:13px 12px; font-size:14px; font-weight:800; white-space:nowrap;">ROLE</th>
                        <?php if($my_role === 'superadmin'): ?><th style="width:12%; padding:13px 12px; font-size:14px; font-weight:800;">STATION</th><?php endif; ?>
                        <th class="text-center" style="width:<?php echo $my_role === 'superadmin' ? '10%' : '12%'; ?>; padding:13px 12px; font-size:14px; font-weight:800; text-align:center; white-space:nowrap;">STATUS</th>
                        <th class="text-center" style="width:14%; padding:13px 12px; font-size:14px; font-weight:800; text-align:center; white-space:nowrap;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($display_list as $u): 
                        $isArchived  = is_user_archived_status($u['status'] ?? '');
                        $rawStatus   = strtolower(trim($u['status'] ?? 'active'));
                        $statusLabel = ucfirst($rawStatus);
                        if ($rawStatus === 'active') {
                            $statusStyle = 'background:#16a34a!important;color:#fff;font-weight:700;padding:5px 14px;border-radius:6px;font-size:13px;display:inline-block;letter-spacing:0.3px;white-space:nowrap;';
                        } elseif ($rawStatus === 'inactive') {
                            $statusStyle = 'background:#dc2626!important;color:#fff;font-weight:700;padding:5px 14px;border-radius:6px;font-size:13px;display:inline-block;letter-spacing:0.3px;white-space:nowrap;';
                        } elseif (in_array($rawStatus, ['archived','disabled','locked'], true)) {
                            $statusStyle = 'background:#dc2626!important;color:#fff;font-weight:700;padding:5px 14px;border-radius:6px;font-size:13px;display:inline-block;letter-spacing:0.3px;white-space:nowrap;';
                        } else {
                            $statusStyle = 'background:#64748b!important;color:#fff;font-weight:700;padding:5px 14px;border-radius:6px;font-size:13px;display:inline-block;letter-spacing:0.3px;white-space:nowrap;';
                        }
                        $roleKey     = role_key($u['role'] ?? 'staff');
                        $roleLabel   = normalize_role($u['role'] ?? $roleKey);
                        if ($roleLabel === '') { $roleLabel = ucfirst($roleKey); }
                        $roleClass   = in_array($roleKey, ['manager','admin','superadmin'], true) ? 'primary' : 'secondary';
                        
                        $fullName = trim(($u['name'] ?? '') ?: (($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')));
                        if (empty($fullName)) $fullName = $u['username'] ?? 'Unknown';
                    ?>
                    <tr>
                        <td style="font-family: monospace; font-weight: 700; color: #0f172a; font-size: 14px; white-space: nowrap;"><?php echo htmlspecialchars($u['employee_id'] ?? '—'); ?></td>
                        <td>
                            <div style="font-weight:700; color:#0f172a; font-size:15px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($fullName); ?>"><?php echo htmlspecialchars($fullName); ?></div>
                            <div class="muted" style="font-size:13px; color:#64748b; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($u['email'] ?? ''); ?>"><?php echo htmlspecialchars($u['email'] ?? ''); ?></div>
                        </td>
                        <td style="font-weight: 600; color: #475569; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="@<?php echo htmlspecialchars($u['username'] ?? '—'); ?>">@<?php echo htmlspecialchars($u['username'] ?? '—'); ?></td>
                        <td style="font-weight:600; color:#334155; font-size:14.5px; white-space:nowrap;"><?php echo htmlspecialchars($roleLabel); ?></td>

                        <?php if($my_role === 'superadmin'): ?>
                            <td style="font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($u['station_name'] ?? 'Unassigned'); ?></td>
                        <?php endif; ?>
                        <td class="text-center" style="text-align:center; white-space:nowrap;">
                            <span style="<?php echo $statusStyle; ?>">
                                <?php echo htmlspecialchars($statusLabel); ?>
                            </span>
                        </td>

                        <td class="text-center" style="text-align:center;">
                            <div style="display:flex; flex-direction:column; gap:6px; align-items:center; justify-content:center;">

                                <button class="action-btn btn-view" onclick="openViewEmployeeModal(<?php echo (int)$u['id']; ?>)" title="View Employee Details">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <!-- ADMIN / SUPERADMIN CONTROLS -->
                                <?php if ($my_role === 'admin' || $my_role === 'superadmin'): ?>
                                    
                                    <?php if (!$isArchived): ?>
                                        <!-- Active Tab Controls -->
                                        <button class="action-btn btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="Edit User">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="action-btn btn-reset" onclick="openResetModal(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['username'])); ?>')" title="Reset Password">
                                            <i class="fas fa-key"></i> Reset
                                        </button>
                                        <?php if ($u['id'] != $me['id']): ?>
                                            <button class="btn-archive" onclick="openArchiveModal(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($fullName)); ?>')" title="Archive User">
                                                <i class="fas fa-archive"></i> Archive
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Archived Tab Controls -->
                                        <button class="btn-restore" onclick="openRestoreModal(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($fullName)); ?>')" title="Restore Account">
                                            <i class="fas fa-undo"></i> Restore
                                        </button>
                                    <?php endif; ?>

                                <?php endif; ?>

                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($display_list)): ?>
                        <tr>
                            <td colspan="<?php echo $my_role === 'superadmin' ? 8 : 7; ?>" style="text-align:center; padding:30px; color:#64748b;">
                                <i class="fas fa-inbox" style="font-size: 24px; margin-bottom: 8px; display:block; color:#94a3b8;"></i>
                                No <?php echo $current_tab === 'active' ? 'active' : 'archived'; ?> users found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>
</div><!-- /.um-wrap -->

<!-- MODAL: Add User -->
<div class="modal" id="addModal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-user-plus"></i> Add New User</span>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form id="addUserForm" data-draft-module="user_creation_form" onsubmit="handleAddUserSubmit(event);" autocomplete="off" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_user">
                <!-- Inline error banner -->
                <div id="addUserErrorBanner" style="display:none; background:#fef2f2; border:1.5px solid #fca5a5; border-radius:8px; padding:10px 14px; margin-bottom:12px; color:#991b1b; font-size:13px; font-weight:700; display:none; align-items:center; gap:8px;">
                    <i class="fas fa-exclamation-circle" style="color:#dc2626; font-size:15px; flex-shrink:0;"></i>
                    <span id="addUserErrorText"></span>
                </div>

                <div class="form-section-title"><i class="fas fa-id-card"></i> Personal Details</div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="first_name" id="add_first_name" class="inp" required placeholder="e.g. Judy" autocomplete="off" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\'\.\u00C0-\u024F]/g, '');">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="last_name" id="add_last_name" class="inp" required placeholder="e.g. Lastimosa" autocomplete="off" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\'\.\u00C0-\u024F]/g, '');">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">Contact Number <span class="muted">(PH Format)</span></label>
                        <input type="tel" name="contact_number" id="add_contact_number" class="inp"
                               placeholder="e.g. 0917xxxxxxx" maxlength="13"
                               oninput="validatePhoneRealtime(this, 'add_phone_hint')"
                               autocomplete="off">
                        <small id="add_phone_hint" style="font-size: 11px; color: #64748b; margin-top: 2px; display: block;">Format: 09XXXXXXXXX</small>
                    </div>
                    <div class="form-group">
                        <label class="lbl">Email Address <span style="color:#dc2626;">*</span></label>
                        <input type="email" name="email" id="add_email" class="inp" required placeholder="e.g. judy@email.com" autocomplete="new-password">
                    </div>
                </div>

                <div class="form-section-title"><i class="fas fa-shield-alt"></i> Account & Role</div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">Username <span class="muted">(optional)</span></label>
                        <input type="text" name="username" id="add_username" class="inp" placeholder="e.g. judy.lastimosa" autocomplete="off" oninput="this.value = this.value.replace(/[^a-zA-Z0-9_\-\.]/g, '');">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Role <span style="color:#dc2626;">*</span></label>
                        <select name="role" id="user_role_add" class="inp" required onchange="toggleShiftField('add');onRoleChangeAddUser(this)">
                            <option value="">Select role</option>
                            <?php if ($my_role === 'superadmin'): ?>
                                <option value="admin">Admin/Owner</option>
                            <?php elseif ($my_role === 'admin'): ?>
                                <option value="staff">Staff</option>
                                <option value="manager">Manager</option>
                            <?php endif; ?>
                        </select>
                        <div id="role_limit_note_add" style="display:none; margin-top:8px; padding:10px 14px; background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; color:#991b1b; font-size:12.5px; font-weight:600; line-height:1.45; align-items:flex-start; gap:8px;">
                            <i class="fas fa-exclamation-triangle" style="color:#dc2626; font-size:15px; margin-top:2px; flex-shrink:0;"></i>
                            <span id="role_limit_note_text_add"></span>
                        </div>
                    </div>
                </div>

                <div class="form-grid-2" id="dynamic_role_fields_add">

                    <?php if ($my_role === 'superadmin'): ?>
                    <div class="form-group" id="station_field_group_add">
                        <label class="lbl">Station Assignment <span style="color:#dc2626;">*</span></label>
                        <select name="station_id" id="add_station_id" class="inp" required onchange="onStationChangeCheckSlots(this.value)">
                            <option value="">Select station</option>
                            <?php 
                            $stns = $pdo->query("SELECT id, name FROM stations WHERE status='active' ORDER BY name")->fetchAll();
                            foreach($stns as $stn) {
                                echo '<option value="' . $stn['id'] . '">' . htmlspecialchars($stn['name']) . '</option>';
                            }
                            ?>
                        </select>
                        <div id="sa_slot_badge" style="margin-top:6px; font-size:11.5px; font-weight:700; padding:5px 10px; border-radius:6px; display:none; align-items:center; gap:6px; background:#f0f9ff; color:#0369a1; border:1px solid #7dd3fc;">
                            <i class="fas fa-info-circle"></i> <span id="sa_slot_badge_text">Select a station to check role availability.</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="form-section-title"><i class="fas fa-lock"></i> Temporary Password</div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">Password <span class="muted">(auto if empty)</span></label>
                        <div style="display: flex; gap: 6px; align-items: center;">
                            <input type="text" name="new_password" id="new_password" class="inp" placeholder="Leave empty to generate" autocomplete="new-password">
                            <button type="button" class="btn-dice" onclick="generateSimplePassword()" title="Generate secure password">
                                <i class="fas fa-dice"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="lbl">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="inp" placeholder="Re-enter password" autocomplete="new-password">
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" id="btnSubmitAddUser" class="btn-header-add" style="height:36px;padding:0 18px;"><i class="fas fa-paper-plane"></i> <span>Create & Send Credentials</span></button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Edit User -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-user-edit"></i> Edit User Details</span>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="post" onsubmit="return validateEditForm();" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <div class="form-section-title"><i class="fas fa-id-card"></i> Personal Details</div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="first_name" id="edit_first_name" class="inp" required placeholder="e.g. Judy" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\'\.\u00C0-\u024F]/g, '');">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="last_name" id="edit_last_name" class="inp" required placeholder="e.g. Lastimosa" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\'\.\u00C0-\u024F]/g, '');">
                    </div>
                </div>
                
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">Login ID / Email <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="login_id" id="edit_login_id" class="inp" required placeholder="Email or Username" oninput="this.value = this.value.replace(/[^a-zA-Z0-9@_\-\.]/g, '');">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Contact Number <span class="muted">(PH format)</span></label>
                        <input type="tel" name="contact_number" id="edit_contact_number" class="inp"
                               placeholder="e.g. 0917xxxxxxx" maxlength="13"
                               oninput="validatePhoneRealtime(this, 'edit_phone_hint')"
                               autocomplete="off">
                        <small id="edit_phone_hint" style="font-size: 11px; color: #64748b; margin-top: 2px; display: block;">Format: 09XXXXXXXXX</small>
                    </div>
                </div>

                <div class="form-section-title"><i class="fas fa-shield-alt"></i> Account & Role</div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="lbl">Role <span style="color:#dc2626;">*</span></label>
                        <select name="role" id="user_role_edit" class="inp" required onchange="toggleShiftField('edit');onRoleChangeEditUser(this)">
                            <?php if ($my_role === 'superadmin'): ?>
                                <option value="admin">Admin/Owner</option>
                            <?php elseif ($my_role === 'admin'): ?>
                                <option value="staff">Staff</option>
                                <option value="manager">Manager</option>
                            <?php endif; ?>
                        </select>
                        <div id="role_limit_note_edit" style="display:none; margin-top:8px; padding:10px 14px; background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; color:#991b1b; font-size:12.5px; font-weight:600; line-height:1.45; align-items:flex-start; gap:8px;">
                            <i class="fas fa-exclamation-triangle" style="color:#dc2626; font-size:15px; margin-top:2px; flex-shrink:0;"></i>
                            <span id="role_limit_note_text_edit"></span>
                        </div>
                    </div>

                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-header-add" style="height:36px;padding:0 18px;"><i class="fas fa-save"></i> <span>Save Changes</span></button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Reset Password -->
<div class="modal" id="resetModal">
    <div class="modal-content" style="max-width: 440px;">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-key"></i> Reset Password</span>
            <button class="modal-close" onclick="closeModal('resetModal')">&times;</button>
        </div>
        <form method="post" onsubmit="return validateResetForm();" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
            <div class="modal-body">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset_user_id">
                <p style="font-size:13.5px; color:#334155; margin-bottom:14px;">Reset password for user <strong id="reset_username" style="color:#002F70;"></strong>?</p>
                <div class="form-group">
                    <label class="lbl">New Password <span class="muted">(optional)</span></label>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <input type="text" name="new_password" id="reset_password_field" class="inp" placeholder="Auto-generate if empty">
                        <button type="button" class="btn-dice" onclick="generateResetPassword()" title="Generate password">
                            <i class="fas fa-dice"></i>
                        </button>
                    </div>
                    <small style="font-size:11px; color:#64748b; margin-top:4px; display:block;">Leave empty to auto-generate a secure password. Credentials will be sent via email.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain-cancel" onclick="closeModal('resetModal')">Cancel</button>
                <button type="submit" class="btn-header-add" style="height:36px;padding:0 18px;"><i class="fas fa-key"></i> <span>Reset Password</span></button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Archive Confirmation -->
<div class="modal" id="archiveModal">
    <div class="modal-content" style="max-width: 440px;">
        <div class="modal-header">
            <span class="modal-title" style="color:#dc2626!important;"><i class="fas fa-archive"></i> Archive User</span>
            <button class="modal-close" onclick="closeModal('archiveModal')">&times;</button>
        </div>
        <form method="post" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
            <div class="modal-body">
                <input type="hidden" name="action" value="archive_user">
                <input type="hidden" name="user_id" id="archive_user_id">
                <p style="font-size:13.5px; color:#334155; margin-bottom:12px;">Are you sure you want to archive user <strong id="archive_user_name" style="color:#0f172a;"></strong>?</p>
                <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 12px; font-size:12px; color:#991b1b; display:flex; gap:8px; align-items:flex-start;">
                    <i class="fas fa-info-circle" style="margin-top:2px;"></i>
                    <span>This user will no longer be able to log in. All activity records and history will be saved forever (No permanent deletion). You can restore this account anytime.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain-cancel" onclick="closeModal('archiveModal')">Cancel</button>
                <button type="submit" class="btn-plain-danger"><i class="fas fa-archive"></i> Yes, Archive User</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: Restore Confirmation -->
<div class="modal" id="restoreModal">
    <div class="modal-content" style="max-width: 440px;">
        <div class="modal-header">
            <span class="modal-title" style="color:#16a34a!important;"><i class="fas fa-undo"></i> Restore User</span>
            <button class="modal-close" onclick="closeModal('restoreModal')">&times;</button>
        </div>
        <form method="post" style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
            <div class="modal-body">
                <input type="hidden" name="action" value="restore_user">
                <input type="hidden" name="user_id" id="restore_user_id">
                <p style="font-size:13.5px; color:#334155; margin-bottom:12px;">Bring <strong id="restore_user_name" style="color:#0f172a;"></strong> back to Active status?</p>
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 12px; font-size:12px; color:#166534;">
                    <i class="fas fa-check-circle"></i> Once restored, this user will be able to log in and access their account again.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-plain-cancel" onclick="closeModal('restoreModal')">Cancel</button>
                <button type="submit" class="btn-plain-success"><i class="fas fa-undo"></i> Yes, Restore Account</button>
            </div>
        </form>
    </div>
</div>

<!-- COMPREHENSIVE VIEW EMPLOYEE DETAILS MODAL -->
<div id="viewModal" class="modal">
    <div class="modal-content" style="max-width: 760px !important;">
        <div class="modal-header">
            <div>
                <span class="modal-title" id="vmodal_title"><i class="fas fa-user-circle"></i> Employee Details</span>
            </div>
            <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
        </div>

        <div class="modal-body" style="padding: 16px 20px;">
            <!-- Loader -->
            <div id="vmodal_loader" style="text-align: center; padding: 30px; color: #64748b;">
                <i class="fas fa-spinner fa-spin fa-2x" style="color: #002F70;"></i>
                <div style="margin-top: 10px; font-weight: 600;">Loading employee records...</div>
            </div>

            <div id="vmodal_body_wrap" style="display: none;">
                <!-- EMPLOYEE INFORMATION -->
                <div id="vtab_info" style="display: block;">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-lbl">Employee ID</span>
                            <span class="info-val" id="vi_emp_id" style="font-family: monospace; color: #002F70; font-weight: 700;">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Full Name</span>
                            <span class="info-val" id="vi_full_name">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Username</span>
                            <span class="info-val" id="vi_username">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Role</span>
                            <span class="info-val" id="vi_role">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Station / Branch</span>
                            <span class="info-val" id="vi_station">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Account Status</span>
                            <span class="info-val" id="vi_status">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Date Created</span>
                            <span class="info-val" id="vi_created">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Email Address</span>
                            <span class="info-val" id="vi_email">—</span>
                        </div>
                        <div class="info-item">
                            <span class="info-lbl">Contact Number</span>
                            <span class="info-val" id="vi_phone">—</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer" style="padding: 12px 20px; display: flex; justify-content: flex-end; background: #f8fafc; border-top: 1px solid #e2e8f0; border-radius: 0 0 12px 12px;">
            <button type="button" class="btn-plain-cancel" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
window.SYSTEM_SECURITY_CONFIG = {
    min_password_length: <?= (int)$sec_min_pass_len ?>,
    require_uppercase: <?= $sec_req_upper ? 'true' : 'false' ?>,
    require_numbers: <?= $sec_req_numbers ? 'true' : 'false' ?>,
    require_special_chars: <?= $sec_req_special ? 'true' : 'false' ?>
};

window.stationRoleLimits = {
    station_id: <?= json_encode($my_station_id) ?>,
    admin_taken: <?= ($station_admin_count >= 1) ? 'true' : 'false' ?>,
    admin_name: <?= json_encode($station_admin_name) ?>,
    manager_taken: <?= ($station_manager_count >= 1) ? 'true' : 'false' ?>,
    manager_name: <?= json_encode($station_manager_name) ?>
};

function validatePasswordString(val) {
    const cfg = window.SYSTEM_SECURITY_CONFIG || { min_password_length: 8, require_uppercase: true, require_numbers: true, require_special_chars: true };
    if (val.length < cfg.min_password_length) {
        return `Password must be at least ${cfg.min_password_length} characters long.`;
    }
    if (cfg.require_uppercase && !/[A-Z]/.test(val)) {
        return 'Password must contain at least one uppercase letter (A-Z).';
    }
    if (cfg.require_numbers && !/[0-9]/.test(val)) {
        return 'Password must contain at least one number (0-9).';
    }
    if (cfg.require_special_chars && !/[!@#$%^&*(),.?":{}|<>\_\-]/.test(val)) {
        return 'Password must contain at least one special character (!@#$%^&* etc.).';
    }
    return null;
}

function toggleShiftField(context) {
    const roleSelect = document.getElementById(context === 'add' ? 'user_role_add' : 'user_role_edit');
    const shiftGroup = document.getElementById(context === 'add' ? 'shift_field_group_add' : 'shift_field_group_edit');
    const shiftInput = document.getElementById(context === 'add' ? 'add_assigned_shift' : 'edit_assigned_shift');
    const selectedRole = roleSelect ? (roleSelect.value || '').toLowerCase().trim() : '';

    if (shiftGroup) {
        if (selectedRole === 'staff') {
            shiftGroup.style.setProperty('display', 'block', 'important');
            shiftGroup.removeAttribute('hidden');
            if (shiftInput) shiftInput.required = true;
        } else {
            shiftGroup.style.setProperty('display', 'none', 'important');
            shiftGroup.setAttribute('hidden', 'hidden');
            if (shiftInput) {
                shiftInput.required = false;
                shiftInput.value = '';
            }
        }
    }
}

function validatePhoneRealtime(input, hintId) {
    input.value = input.value.replace(/[^0-9+]/g, '');
    const val = input.value;
    const hint = document.getElementById(hintId);
    if (!hint) return;

    if (val === '') {
        hint.style.color = '#64748b';
        hint.textContent = 'Format: 11-digit PH mobile number starting with 09 (e.g. 0917xxxxxxx) or +639';
        input.style.borderColor = '#cbd5e1';
        return;
    }

    const isValid = /^(09\d{9}|\+639\d{9}|639\d{9})$/.test(val);
    if (isValid) {
        hint.style.color = '#16a34a';
        hint.innerHTML = '<i class="fas fa-check-circle"></i> Valid Philippine mobile number';
        input.style.borderColor = '#16a34a';
    } else {
        hint.style.color = '#dc2626';
        hint.innerHTML = '<i class="fas fa-exclamation-circle"></i> Must be 11 digits starting with 09 (e.g. 09171234567) or +639';
        input.style.borderColor = '#dc2626';
    }
}

function isValidPhilippineNumber(val) {
    if (!val) return true;
    const clean = val.replace(/[\s\-\(\)\.]/g, '');
    if (clean === '') return true;
    return /^(09\d{9}|\+639\d{9}|639\d{9})$/.test(clean);
}

function generateSimplePassword() {
    const cfg = window.SYSTEM_SECURITY_CONFIG || { min_password_length: 8 };
    const upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower   = 'abcdefghijkmnopqrstuvwxyz';
    const digits  = '23456789';
    const symbols = '!@#$%^&*';
    const all     = upper + lower + digits + symbols;

    let passArr = [
        upper.charAt(Math.floor(Math.random() * upper.length)),
        lower.charAt(Math.floor(Math.random() * lower.length)),
        digits.charAt(Math.floor(Math.random() * digits.length)),
        symbols.charAt(Math.floor(Math.random() * symbols.length))
    ];

    const targetLen = Math.max(10, cfg.min_password_length || 8);
    const extraNeeded = Math.max(0, targetLen - passArr.length);
    for (let i = 0; i < extraNeeded; i++) {
        passArr.push(all.charAt(Math.floor(Math.random() * all.length)));
    }

    for (let i = passArr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [passArr[i], passArr[j]] = [passArr[j], passArr[i]];
    }

    const pass = passArr.join('');
    const newPassInp = document.getElementById('new_password');
    const confPassInp = document.getElementById('confirm_password');
    if (newPassInp) newPassInp.value = pass;
    if (confPassInp) confPassInp.value = pass;
}

function generateResetPassword() {
    const cfg = window.SYSTEM_SECURITY_CONFIG || { min_password_length: 8 };
    const upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower   = 'abcdefghijkmnopqrstuvwxyz';
    const digits  = '23456789';
    const symbols = '!@#$%^&*';
    const all     = upper + lower + digits + symbols;

    let passArr = [
        upper.charAt(Math.floor(Math.random() * upper.length)),
        lower.charAt(Math.floor(Math.random() * lower.length)),
        digits.charAt(Math.floor(Math.random() * digits.length)),
        symbols.charAt(Math.floor(Math.random() * symbols.length))
    ];

    const targetLen = Math.max(10, cfg.min_password_length || 8);
    const extraNeeded = Math.max(0, targetLen - passArr.length);
    for (let i = 0; i < extraNeeded; i++) {
        passArr.push(all.charAt(Math.floor(Math.random() * all.length)));
    }

    for (let i = passArr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [passArr[i], passArr[j]] = [passArr[j], passArr[i]];
    }

    const pass = passArr.join('');
    const resetInp = document.getElementById('reset_password_field');
    if (resetInp) resetInp.value = pass;
}

function validateAddForm() {
    const fnEl   = document.getElementById('add_first_name');
    const lnEl   = document.getElementById('add_last_name');
    const emEl   = document.getElementById('add_email');
    const roleEl = document.getElementById('user_role_add');
    const phEl   = document.getElementById('add_contact_number');
    const unEl   = document.getElementById('add_username');
    const passEl = document.getElementById('new_password');
    const confEl = document.getElementById('confirm_password');

    const fn   = (fnEl?.value || '').trim();
    const ln   = (lnEl?.value || '').trim();
    const em   = (emEl?.value || '').trim();
    const role = (roleEl?.value || '').trim();
    const ph   = (phEl?.value || '').trim();
    const un   = (unEl?.value || '').trim();
    const pass = passEl?.value || '';
    const conf = confEl?.value || '';

    const placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];

    // 1. FIRST NAME (Required)
    if (!fn || placeholders.includes(fn.toLowerCase())) {
        alert('First Name is required and cannot be N/A or a placeholder.');
        if (fnEl) fnEl.focus();
        return false;
    }

    // 2. LAST NAME (Required)
    if (!ln || placeholders.includes(ln.toLowerCase())) {
        alert('Last Name is required and cannot be N/A or a placeholder.');
        if (lnEl) lnEl.focus();
        return false;
    }

    // 3. EMAIL ADDRESS (Required)
    if (!em || placeholders.includes(em.toLowerCase())) {
        alert('Email Address is required and cannot be N/A or a placeholder.');
        if (emEl) emEl.focus();
        return false;
    }
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(em)) {
        alert('Invalid Email Address format.');
        if (emEl) emEl.focus();
        return false;
    }

    // 4. CONTACT NUMBER (Optional)
    if (ph !== '' && !placeholders.includes(ph.toLowerCase())) {
        if (!isValidPhilippineNumber(ph)) {
            alert('Invalid Contact Number: Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g. 09171234567 or +639171234567).');
            if (phEl) phEl.focus();
            return false;
        }
    }

    // 5. USERNAME (Optional)
    if (un !== '' && !placeholders.includes(un.toLowerCase())) {
        const userPattern = /^[a-zA-Z0-9_\-\.]+$/;
        if (!userPattern.test(un)) {
            alert('Username can only contain letters, numbers, dots, hyphens, and underscores (no spaces or special characters).');
            if (unEl) unEl.focus();
            return false;
        }
    }

    // 6. ROLE (Required)
    if (!role) {
        alert('Role selection is required. Please select a role from the dropdown.');
        if (roleEl) roleEl.focus();
        return false;
    }

    if (role.toLowerCase() === 'admin' && window.stationRoleLimits && window.stationRoleLimits.admin_taken) {
        alert('An Admin/Owner account already exists for this station. Only one Admin/Owner account is allowed per station.');
        if (roleEl) roleEl.focus();
        return false;
    }
    if (role.toLowerCase() === 'manager' && window.stationRoleLimits && window.stationRoleLimits.manager_taken) {
        alert('A Manager account already exists for this station. Only one Manager account is allowed per station.');
        if (roleEl) roleEl.focus();
        return false;
    }

    // 6c. Superadmin must pick a station before submitting
    const stationSel = document.getElementById('add_station_id');
    if (stationSel && !stationSel.value) {
        alert('Please select a Station before creating this user.');
        stationSel.focus();
        return false;
    }

    // 7. PASSWORD & CONFIRM PASSWORD (Optional if empty, confirm required if manually entered)
    if (pass !== '') {
        if (conf === '') {
            alert('Please re-enter your password in the Confirm Password field.');
            if (confEl) confEl.focus();
            return false;
        }
        if (pass !== conf) {
            alert('Passwords do not match. Please ensure both passwords are identical.');
            if (confEl) confEl.focus();
            return false;
        }
        const passErr = validatePasswordString(pass);
        if (passErr) {
            showAddUserError(passErr);
            if (passEl) passEl.focus();
            return false;
        }
    }

    return true; // used only if called directly
}

// ── Show/hide inline error inside Add User modal ──────────────────────────
function showAddUserError(msg) {
    const banner = document.getElementById('addUserErrorBanner');
    const text   = document.getElementById('addUserErrorText');
    if (banner && text) {
        text.textContent = msg;
        banner.style.display = 'flex';
        banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}
function clearAddUserError() {
    const banner = document.getElementById('addUserErrorBanner');
    if (banner) banner.style.display = 'none';
}

// ── Show success toast (top-right) ────────────────────────────────────────
function showUserSuccessToast(html) {
    const existing = document.getElementById('userSuccessToast');
    if (existing) existing.remove();

    const t = document.createElement('div');
    t.id = 'userSuccessToast';
    t.style.cssText = 'position:fixed;top:96px;right:24px;z-index:2147483647;max-width:420px;background:#ffffff;border:2px solid #10b981;border-radius:12px;box-shadow:0 12px 35px rgba(0,0,0,0.18);padding:14px 18px;display:flex;align-items:flex-start;gap:12px;animation:toastSlideIn .3s ease-out;';
    t.innerHTML = `
        <div style="width:36px;height:36px;border-radius:50%;background:#ecfdf5;color:#059669;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;border:1.5px solid #a7f3d0;">
            <i class="fas fa-check"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:11px;font-weight:800;color:#059669;text-transform:uppercase;letter-spacing:0.5px;">User Created</div>
            <div style="font-size:13.5px;font-weight:700;color:#002F70;margin:3px 0;line-height:1.4;">${html}</div>
        </div>
    `;
    document.body.appendChild(t);
    setTimeout(() => {
        if (t.parentNode) {
            t.style.transition = 'opacity 0.5s ease';
            t.style.opacity = '0';
            setTimeout(() => t.remove(), 500);
        }
    }, 8000);
}

// ── AJAX submit handler for Add User modal ────────────────────────────────
async function handleAddUserSubmit(e) {
    e.preventDefault();
    clearAddUserError();

    // Run client-side validation first (reuse existing checks but show inline)
    const fnEl   = document.getElementById('add_first_name');
    const lnEl   = document.getElementById('add_last_name');
    const emEl   = document.getElementById('add_email');
    const roleEl = document.getElementById('user_role_add');
    const phEl   = document.getElementById('add_contact_number');
    const unEl   = document.getElementById('add_username');
    const passEl = document.getElementById('new_password');
    const confEl = document.getElementById('confirm_password');

    const fn   = (fnEl?.value || '').trim();
    const ln   = (lnEl?.value || '').trim();
    const em   = (emEl?.value || '').trim();
    const role = (roleEl?.value || '').trim();
    const ph   = (phEl?.value || '').trim();
    const un   = (unEl?.value || '').trim();
    const pass = passEl?.value || '';
    const conf = confEl?.value || '';

    const placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];

    if (!fn || placeholders.includes(fn.toLowerCase())) { showAddUserError('First Name is required and cannot be N/A or a placeholder.'); fnEl?.focus(); return; }
    if (!ln || placeholders.includes(ln.toLowerCase())) { showAddUserError('Last Name is required and cannot be N/A or a placeholder.'); lnEl?.focus(); return; }
    if (!em || placeholders.includes(em.toLowerCase())) { showAddUserError('Email Address is required.'); emEl?.focus(); return; }
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(em)) { showAddUserError('Invalid Email Address format.'); emEl?.focus(); return; }
    if (ph !== '' && !placeholders.includes(ph.toLowerCase()) && !isValidPhilippineNumber(ph)) { showAddUserError('Invalid Contact Number: must be 11-digit PH mobile starting with 09.'); phEl?.focus(); return; }
    if (!role) { showAddUserError('Role selection is required. Please select a role from the dropdown.'); roleEl?.focus(); return; }

    if (role.toLowerCase() === 'admin' && window.stationRoleLimits && window.stationRoleLimits.admin_taken) {
        showAddUserError('An Admin/Owner account already exists for this station. Only one Admin/Owner account is allowed per station.');
        roleEl?.focus();
        return;
    }
    if (role.toLowerCase() === 'manager' && window.stationRoleLimits && window.stationRoleLimits.manager_taken) {
        showAddUserError('A Manager account already exists for this station. Only one Manager account is allowed per station.');
        roleEl?.focus();
        return;
    }

    const stationSel = document.getElementById('add_station_id');
    if (stationSel && !stationSel.value) { showAddUserError('Please select a Station before creating this user.'); stationSel.focus(); return; }

    if (pass !== '') {
        if (conf === '') { showAddUserError('Please re-enter your password in the Confirm Password field.'); confEl?.focus(); return; }
        if (pass !== conf) { showAddUserError('Passwords do not match. Please ensure both passwords are identical.'); confEl?.focus(); return; }
        const passErr = validatePasswordString(pass);
        if (passErr) { showAddUserError(passErr); passEl?.focus(); return; }
    }

    const submitBtn = document.getElementById('btnSubmitAddUser');
    const origLabel = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Creating &amp; Sending...</span>'; }

    try {
        const form = document.getElementById('addUserForm');
        const formData = new FormData(form);

        const res  = await fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            closeModal('addModal');
            const modalEl = document.getElementById('addModal');
            if (modalEl) modalEl.style.setProperty('display', 'none', 'important');
            clearAddUserForm();
            showUserSuccessToast(data.message || 'User created successfully!');
            // Reload table after short delay so new user appears
            setTimeout(() => location.reload(), 1800);
        } else {
            showAddUserError(data.error || 'An error occurred. Please try again.');
        }
    } catch (err) {
        showAddUserError('Network error: ' + err.message);
    } finally {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = origLabel; }
    }
}

function validateEditForm() {
    const fnEl   = document.getElementById('edit_first_name');
    const lnEl   = document.getElementById('edit_last_name');
    const idEl   = document.getElementById('edit_login_id');
    const roleEl = document.getElementById('user_role_edit');
    const phEl   = document.getElementById('edit_contact_number');

    const fn   = (fnEl?.value || '').trim();
    const ln   = (lnEl?.value || '').trim();
    const login= (idEl?.value || '').trim();
    const role = (roleEl?.value || '').trim();
    const ph   = (phEl?.value || '').trim();

    const placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];

    // 1. FIRST NAME (Required)
    if (!fn || placeholders.includes(fn.toLowerCase())) {
        alert('First Name is required and cannot be N/A or a placeholder.');
        if (fnEl) fnEl.focus();
        return false;
    }

    // 2. LAST NAME (Required)
    if (!ln || placeholders.includes(ln.toLowerCase())) {
        alert('Last Name is required and cannot be N/A or a placeholder.');
        if (lnEl) lnEl.focus();
        return false;
    }

    // 3. LOGIN ID / EMAIL (Required)
    if (!login || placeholders.includes(login.toLowerCase())) {
        alert('Login ID (Email or Username) is required and cannot be N/A or a placeholder.');
        if (idEl) idEl.focus();
        return false;
    }

    // 4. CONTACT NUMBER (Optional)
    if (ph !== '' && !placeholders.includes(ph.toLowerCase())) {
        if (!isValidPhilippineNumber(ph)) {
            alert('Invalid Contact Number: Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g. 09171234567 or +639171234567).');
            if (phEl) phEl.focus();
            return false;
        }
    }

    // 5. ROLE (Required)
    if (!role) {
        alert('Role selection is required. Please select a role from the dropdown.');
        if (roleEl) roleEl.focus();
        return false;
    }

    const curRole = (window.currentEditingUser?.role || '').toLowerCase().trim();
    if (role.toLowerCase() === 'admin' && curRole !== 'admin' && window.stationRoleLimits && window.stationRoleLimits.admin_taken) {
        alert('An Admin/Owner account already exists for this station. Only one Admin/Owner account is allowed per station.');
        if (roleEl) roleEl.focus();
        return false;
    }
    if (role.toLowerCase() === 'manager' && curRole !== 'manager' && window.stationRoleLimits && window.stationRoleLimits.manager_taken) {
        alert('A Manager account already exists for this station. Only one Manager account is allowed per station.');
        if (roleEl) roleEl.focus();
        return false;
    }

    return true;
}

function validateResetForm() {
    const field = document.getElementById('reset_password_field');
    const val   = (field?.value || '').trim();
    if (val === '') {
        return true; // Auto-generate password
    }
    const placeholders = ['n/a', 'none', 'null', '-', 'unknown', 'not available'];
    if (placeholders.includes(val.toLowerCase())) {
        alert('Password cannot be N/A or a placeholder value.');
        if (field) field.focus();
        return false;
    }
    const passErr = validatePasswordString(val);
    if (passErr) {
        alert(passErr);
        if (field) field.focus();
        return false;
    }
    return true;
}

function clearAddUserForm() {
    const form = document.getElementById('addUserForm');
    if (form) form.reset();

    // Hide inline error banner
    const errBanner = document.getElementById('addUserErrorBanner');
    if (errBanner) errBanner.style.display = 'none';

    const ids = [
        'add_first_name', 'add_last_name', 'add_contact_number',
        'add_email', 'add_username', 'user_role_add',
        'add_assigned_shift', 'add_station_id', 'new_password', 'confirm_password'
    ];
    ids.forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.value = '';
            el.style.borderColor = '#cbd5e1';
        }
    });

    const hint = document.getElementById('add_phone_hint');
    if (hint) {
        hint.style.color = '#64748b';
        hint.textContent = 'Format: 11-digit PH mobile number starting with 09 (e.g. 0917xxxxxxx) or +639';
    }

    const noteBox = document.getElementById('role_limit_note_add');
    if (noteBox) noteBox.style.display = 'none';

    const submitBtn = document.getElementById('btnSubmitAddUser');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
        submitBtn.style.pointerEvents = 'auto';
        submitBtn.removeAttribute('title');
    }

    const shiftGroup = document.getElementById('shift_field_group_add');
    if (shiftGroup) shiftGroup.style.display = 'none';
}

function openAddModal() {
    clearAddUserForm();
    toggleShiftField('add');
    const roleSel = document.getElementById('user_role_add');
    if (roleSel) onRoleChangeAddUser(roleSel);
    document.getElementById('addModal').style.display = 'flex';
    setTimeout(function() {
        clearAddUserForm();
        toggleShiftField('add');
        if (roleSel) onRoleChangeAddUser(roleSel);
    }, 50);
}

// ── Role limits enforcement on role change (Add User modal) ──
function onRoleChangeAddUser(selectEl) {
    if (!selectEl) return;
    const role = (selectEl.value || '').toLowerCase().trim();
    const noteBox = document.getElementById('role_limit_note_add');
    const noteText = document.getElementById('role_limit_note_text_add');
    const submitBtn = document.getElementById('btnSubmitAddUser');

    let isBlocked = false;
    let blockMessage = '';

    if (role === 'admin') {
        if (window.stationRoleLimits && window.stationRoleLimits.admin_taken) {
            isBlocked = true;
            blockMessage = 'An Admin/Owner account already exists for this station. Only one Admin/Owner account is allowed per station.';
        }
    } else if (role === 'manager') {
        if (window.stationRoleLimits && window.stationRoleLimits.manager_taken) {
            isBlocked = true;
            blockMessage = 'A Manager account already exists for this station. Only one Manager account is allowed per station.';
        }
    } else if (role === 'staff') {
        // Staff / Operations Staff: NO FIXED ACCOUNT LIMIT
        isBlocked = false;
    }

    if (isBlocked) {
        if (noteBox && noteText) {
            noteText.textContent = blockMessage;
            noteBox.style.display = 'flex';
        }
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            submitBtn.style.cursor = 'not-allowed';
            submitBtn.style.pointerEvents = 'none';
            submitBtn.title = blockMessage;
        }
    } else {
        if (noteBox) {
            noteBox.style.display = 'none';
        }
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
            submitBtn.style.pointerEvents = 'auto';
            submitBtn.removeAttribute('title');
        }
    }
}

// ── Role limits enforcement on role change (Edit User modal) ──
let currentEditingUser = null;
function onRoleChangeEditUser(selectEl) {
    if (!selectEl) return;
    const newRole = (selectEl.value || '').toLowerCase().trim();
    const curRole = (window.currentEditingUser?.role || '').toLowerCase().trim();
    const noteBox = document.getElementById('role_limit_note_edit');
    const noteText = document.getElementById('role_limit_note_text_edit');
    const submitBtn = document.querySelector('#editModal button[type="submit"]');

    let isBlocked = false;
    let blockMessage = '';

    if (newRole === 'admin' && curRole !== 'admin') {
        if (window.stationRoleLimits && window.stationRoleLimits.admin_taken) {
            isBlocked = true;
            blockMessage = 'An Admin/Owner account already exists for this station. Only one Admin/Owner account is allowed per station.';
        }
    } else if (newRole === 'manager' && curRole !== 'manager') {
        if (window.stationRoleLimits && window.stationRoleLimits.manager_taken) {
            isBlocked = true;
            blockMessage = 'A Manager account already exists for this station. Only one Manager account is allowed per station.';
        }
    }

    if (isBlocked) {
        if (noteBox && noteText) {
            noteText.textContent = blockMessage;
            noteBox.style.display = 'flex';
        }
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            submitBtn.style.cursor = 'not-allowed';
            submitBtn.style.pointerEvents = 'none';
            submitBtn.title = blockMessage;
        }
    } else {
        if (noteBox) {
            noteBox.style.display = 'none';
        }
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
            submitBtn.style.pointerEvents = 'auto';
            submitBtn.removeAttribute('title');
        }
    }
}

// ── Superadmin: Check role slot availability when station changes ──
function onStationChangeCheckSlots(stationId) {
    const roleSelect  = document.getElementById('user_role_add');
    const badge       = document.getElementById('sa_slot_badge');
    const badgeText   = document.getElementById('sa_slot_badge_text');

    if (!stationId) return;

    if (badge) { badge.style.display = 'inline-flex'; }
    if (badgeText) { badgeText.textContent = 'Checking slot availability...'; }

    fetch('users.php?ajax_check_slots=1&station_id=' + encodeURIComponent(stationId), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;

            window.stationRoleLimits = window.stationRoleLimits || {};
            window.stationRoleLimits.station_id = stationId;
            window.stationRoleLimits.admin_taken = !!data.admin_taken;
            window.stationRoleLimits.admin_name = data.admin_name || '';
            window.stationRoleLimits.manager_taken = !!data.manager_taken;
            window.stationRoleLimits.manager_name = data.manager_name || '';

            // Update role notes and buttons immediately
            if (roleSelect) {
                onRoleChangeAddUser(roleSelect);
            }

            let messages = [];
            if (data.admin_taken) {
                messages.push('Admin: Slot Full (1 already assigned)');
            } else {
                messages.push('Admin: Available');
            }
            if (data.manager_taken) {
                messages.push('Manager: Slot Full (1 already assigned)');
            } else {
                messages.push('Manager: Available');
            }
            messages.push('Staff: Unlimited');

            if (badge && badgeText) {
                badge.style.display = 'inline-flex';
                badgeText.textContent = messages.join('  |  ');

                const hasFull = data.admin_taken || data.manager_taken;
                badge.style.background = hasFull ? '#fff7ed' : '#f0fdf4';
                badge.style.color      = hasFull ? '#c2410c' : '#16a34a';
                badge.style.border     = hasFull ? '1px solid #fdba74' : '1px solid #86efac';
            }
        })
        .catch(() => {
            if (badgeText) badgeText.textContent = 'Could not load slot info.';
        });
}

function openEditModal(user) {
    window.currentEditingUser = user;
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_first_name').value = (user.first_name || '').trim();
    document.getElementById('edit_last_name').value = (user.last_name || '').trim();
    document.getElementById('edit_login_id').value = user.email || user.username || '';
    
    const contactInp = document.getElementById('edit_contact_number');
    if (contactInp) {
        contactInp.value = user.phone_number || '';
        validatePhoneRealtime(contactInp, 'edit_phone_hint');
    }

    var roleSel = document.getElementById('user_role_edit');
    if (roleSel) {
        roleSel.value = (user.role || 'staff').toLowerCase();
    }
    
    var shiftSel = document.getElementById('edit_assigned_shift');
    if (shiftSel) {
        shiftSel.value = user.assigned_shift || '';
    }
    
    toggleShiftField('edit');
    if (roleSel) onRoleChangeEditUser(roleSel);
    document.getElementById('editModal').style.display = 'flex';
}

function openResetModal(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username').innerText = username;
    document.getElementById('reset_password_field').value = '';
    document.getElementById('resetModal').style.display = 'flex';
}

function openArchiveModal(userId, fullName) {
    document.getElementById('archive_user_id').value = userId;
    document.getElementById('archive_user_name').innerText = fullName;
    document.getElementById('archiveModal').style.display = 'flex';
}

function openRestoreModal(userId, fullName) {
    document.getElementById('restore_user_id').value = userId;
    document.getElementById('restore_user_name').innerText = fullName;
    document.getElementById('restoreModal').style.display = 'flex';
}



function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function switchViewTab(tabName) {
    document.querySelectorAll(".vtab-btn").forEach(btn => btn.classList.remove("active"));
    document.querySelectorAll(".vtab-pane").forEach(pane => pane.classList.remove("active"));

    const btn = document.getElementById("btn_vtab_" + tabName);
    const pane = document.getElementById("vtab_" + tabName);
    if (btn) btn.classList.add("active");
    if (pane) pane.classList.add("active");
}

function openViewEmployeeModal(userId) {
    document.getElementById("vmodal_loader").style.display = "block";
    document.getElementById("vmodal_body_wrap").style.display = "none";
    document.getElementById("viewModal").style.display = "flex";

    fetch("users.php?ajax_emp_details=1&user_id=" + userId)
        .then(r => r.json())
        .then(data => {
            document.getElementById("vmodal_loader").style.display = "none";
            if (!data.success) {
                alert(data.error || "Failed to load employee details.");
                closeModal("viewModal");
                return;
            }

            document.getElementById("vmodal_body_wrap").style.display = "block";

            const info = data.info || {};
            const fullName = ((info.first_name || "") + " " + (info.last_name || "")).trim() || info.username;
            document.getElementById("vmodal_title").innerHTML = '<i class="fas fa-user-circle" style="color:#002F70;"></i> ' + escapeHtml(fullName);

            document.getElementById("vi_emp_id").innerText = info.employee_id || "—";
            document.getElementById("vi_full_name").innerText = fullName;
            document.getElementById("vi_username").innerText = "@" + (info.username || "—");
            document.getElementById("vi_role").innerText = (info.role || "Staff").toUpperCase();
            document.getElementById("vi_station").innerText = info.station_name || "Petron Carmen";
            document.getElementById("vi_status").innerText = (info.status || "Active").toUpperCase();
            document.getElementById("vi_created").innerText = info.created_at ? info.created_at.substring(0, 10) : "—";
            if (document.getElementById("vi_last_login")) {
                document.getElementById("vi_last_login").innerText = info.updated_at ? info.updated_at : "—";
            }
            document.getElementById("vi_email").innerText = info.email || "Not set";
            document.getElementById("vi_phone").innerText = info.phone_number || "Not set";

            // Login History Table (if tab present)
            const loginBody = document.getElementById("vlogin_tbody");
            if (loginBody) {
                loginBody.innerHTML = "";
                if ((data.login_history || []).length === 0) {
                    loginBody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:#94a3b8; padding:12px;">No login records found.</td></tr>';
                } else {
                    data.login_history.forEach(log => {
                        const tr = document.createElement("tr");
                        tr.innerHTML = '<td>' + escapeHtml(log.created_at) + '</td><td><span style="color:#16a34a; font-weight:600;">' + escapeHtml(log.action) + '</span></td><td>' + escapeHtml(log.shift_period || "—") + '</td>';
                        loginBody.appendChild(tr);
                    });
                }
            }

            // Activity Logs Table (if tab present)
            const actBody = document.getElementById("vact_tbody");
            if (actBody) {
                actBody.innerHTML = "";
                if ((data.activity_logs || []).length === 0) {
                    actBody.innerHTML = '<tr><td colspan="3" style="text-align:center; color:#94a3b8; padding:12px;">No recent audit activity found.</td></tr>';
                } else {
                    data.activity_logs.forEach(act => {
                        const tr = document.createElement("tr");
                        const ref = act.or_number || act.transaction_id || act.details || "—";
                        tr.innerHTML = '<td>' + escapeHtml(act.created_at) + '</td><td><strong>' + escapeHtml(act.action) + '</strong></td><td>' + escapeHtml(ref) + '</td>';
                        actBody.appendChild(tr);
                    });
                }
            }
        })
        .catch(err => {
            console.error("AJAX Error:", err);
            document.getElementById("vmodal_loader").style.display = "none";
            alert("Error loading employee data.");
            closeModal("viewModal");
        });
}

function openViewModal(user) {
    if (typeof user === "object" && user.id) {
        openViewEmployeeModal(user.id);
    } else {
        openViewEmployeeModal(user);
    }
}


function closeModal(modalId) {
    if (modalId === 'addModal') {
        clearAddUserForm();
        clearAddUserError();
    }
    const modalEl = document.getElementById(modalId);
    if (modalEl) {
        modalEl.style.setProperty('display', 'none', 'important');
    }
}

window.onclick = function(event) {
    var modals = ['addModal', 'editModal', 'resetModal', 'archiveModal', 'restoreModal', 'viewModal'];
    modals.forEach(function(m) {
        var el = document.getElementById(m);
        if (event.target == el) {
            el.style.display = 'none';
        }
    });
};

document.addEventListener('DOMContentLoaded', function() {
    var roleAdd = document.getElementById('user_role_add');
    if (roleAdd) {
        roleAdd.addEventListener('change', function() { toggleShiftField('add'); });
        roleAdd.addEventListener('input', function() { toggleShiftField('add'); });
    }
    var roleEdit = document.getElementById('user_role_edit');
    if (roleEdit) {
        roleEdit.addEventListener('change', function() { toggleShiftField('edit'); });
        roleEdit.addEventListener('input', function() { toggleShiftField('edit'); });
    }
    toggleShiftField('add');
    toggleShiftField('edit');
});
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
let lastUmTotalCount = null;
function autoRefreshUserManagement() {
    // Pause polling if any modal is open
    const openModal = Array.from(document.querySelectorAll('.modal')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden';
    });
    if (openModal) return;

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_um', '1');

    fetch(currentUrl.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success && data.counts) {
                if (document.getElementById('um_badge_active'))   document.getElementById('um_badge_active').textContent   = data.counts.active;
                if (document.getElementById('um_badge_archived')) document.getElementById('um_badge_archived').textContent = data.counts.archived;
                
                if (lastUmTotalCount !== null && lastUmTotalCount !== data.counts.total) {
                    window.location.reload();
                }
                lastUmTotalCount = data.counts.total;
            }
        })
        .catch(() => {});
}
// ── CLIENT-SIDE REALTIME TABLE FILTERING & EXPORT LINK SYNC ─────────────────

function triggerEmployeeExport(format) {
    const q = (document.getElementById("empSearchInput") ? document.getElementById("empSearchInput").value : "").trim();
    const role = (document.getElementById("empRoleFilter") ? document.getElementById("empRoleFilter").value : "").trim();
    const status = (document.getElementById("empStatusFilter") ? document.getElementById("empStatusFilter").value : "").trim();

    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get("tab") || "active";

    const params = new URLSearchParams();
    params.set("format", format);
    params.set("tab", tab);
    if (q) params.set("q", q);
    if (role) params.set("role", role);
    if (status) params.set("status", status);
    params.set("_ts", Date.now());

    const exportUrl = "export_employee_list.php?" + params.toString();

    if (format === "print") {
        let iframe = document.getElementById("printReportIframe");
        if (!iframe) {
            iframe = document.createElement("iframe");
            iframe.id = "printReportIframe";
            iframe.style.position = "fixed";
            iframe.style.right = "0";
            iframe.style.bottom = "0";
            iframe.style.width = "0";
            iframe.style.height = "0";
            iframe.style.border = "0";
            document.body.appendChild(iframe);
        }
        iframe.src = exportUrl;
    } else {
        window.location.href = exportUrl;
    }
}

function filterEmployeeTable() {
    const q = (document.getElementById("empSearchInput") ? document.getElementById("empSearchInput").value : "").toLowerCase().trim();
    const role = (document.getElementById("empRoleFilter") ? document.getElementById("empRoleFilter").value : "").toLowerCase().trim();
    const status = (document.getElementById("empStatusFilter") ? document.getElementById("empStatusFilter").value : "").toLowerCase().trim();

    // 1. Filter table rows
    const table = document.querySelector(".table-wrap table.table");
    if (table) {
        const rows = table.querySelectorAll("tbody tr");
        rows.forEach(tr => {
            if (tr.cells.length < 5) return; // skip empty state row
            
            const empIdText  = (tr.cells[0] ? tr.cells[0].innerText : "").toLowerCase();
            const nameText   = (tr.cells[1] ? tr.cells[1].innerText : "").toLowerCase();
            const userText   = (tr.cells[2] ? tr.cells[2].innerText : "").toLowerCase();
            const roleText   = (tr.cells[3] ? tr.cells[3].innerText : "").toLowerCase();
            
            let statusCellIdx = 4;
            if (tr.cells.length >= 7) statusCellIdx = 5;
            const statusText = (tr.cells[statusCellIdx] ? tr.cells[statusCellIdx].innerText : "").toLowerCase();

            // Match query (Employee ID, Name, or Username)
            const matchQ = (q === "") || empIdText.includes(q) || nameText.includes(q) || userText.includes(q);
            
            // Match role (Manager or Staff)
            const matchRole = (role === "") || roleText.includes(role);
            
            // Match status (Active, Inactive, Archived)
            const matchStatus = (status === "") || statusText.includes(status);

            if (matchQ && matchRole && matchStatus) {
                tr.style.display = "";
            } else {
                tr.style.display = "none";
            }
        });
    }

    // 2. Synchronize Export URLs with active filters
    const exportParams = new URLSearchParams();
    if (q) exportParams.set("q", q);
    if (role) exportParams.set("role", role);
    if (status) exportParams.set("status", status);

    const queryString = exportParams.toString() ? ("&" + exportParams.toString()) : "";

    const btnPrint = document.querySelector(".rpt-btn-print");
    if (btnPrint) {
        btnPrint.setAttribute("onclick", "window.open('export_employee_list.php?format=print" + queryString + "', '_blank')");
    }

    const btnPdf = document.querySelector(".rpt-btn-pdf");
    if (btnPdf) {
        btnPdf.setAttribute("href", "export_employee_list.php?format=pdf" + queryString);
    }

    const btnExcel = document.querySelector(".rpt-btn-excel");
    if (btnExcel) {
        btnExcel.setAttribute("href", "export_employee_list.php?format=excel" + queryString);
    }

    const btnCsv = document.querySelector(".rpt-btn-csv");
    if (btnCsv) {
        btnCsv.setAttribute("href", "export_employee_list.php?format=csv" + queryString);
    }
}

function clearEmployeeFilters() {
    if (document.getElementById("empSearchInput")) document.getElementById("empSearchInput").value = "";
    if (document.getElementById("empRoleFilter")) document.getElementById("empRoleFilter").value = "";
    if (document.getElementById("empStatusFilter")) document.getElementById("empStatusFilter").value = "";
    filterEmployeeTable();
}

setInterval(autoRefreshUserManagement, 10000);
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
