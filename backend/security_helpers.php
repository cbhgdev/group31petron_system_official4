<?php
/**
 * Petron Station Management System — Comprehensive Security Hardening Module
 * Features:
 * - SQL Injection Protection (PDO Prepared Statements Enforcement)
 * - XSS (Cross-Site Scripting) Input & Output Sanitization
 * - Rate Limiting & Brute-Force Protection
 * - CSRF Token Generation & Verification
 * - HTTP Security Headers Enforcement
 * - Authoritative Server-Side Security & Session Validation
 */

if (!function_exists('sec_apply_headers')) {
    function sec_apply_headers() {
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
        }
    }
}

// Automatically apply security headers when included
sec_apply_headers();

/**
 * XSS Protection: HTML Output Escaping
 */
if (!function_exists('sec_escape')) {
    function sec_escape($str) {
        if (is_null($str)) return '';
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Server-Side Input Sanitization (String)
 */
if (!function_exists('sec_sanitize_string')) {
    function sec_sanitize_string($data) {
        if (is_array($data)) {
            return array_map('sec_sanitize_string', $data);
        }
        $data = trim((string)$data);
        $data = strip_tags($data);
        return $data;
    }
}

/**
 * CSRF Token Generator
 */
if (!function_exists('sec_generate_csrf_token')) {
    function sec_generate_csrf_token() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/**
 * CSRF Token Verifier
 * Accepts explicit token or attempts auto-extraction from POST, Headers, or JSON payload
 */
if (!function_exists('sec_verify_csrf_token')) {
    function sec_verify_csrf_token($token = null) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $session_token = $_SESSION['csrf_token'] ?? '';
        if (empty($session_token)) {
            return false;
        }

        if (empty($token)) {
            // Check POST data
            $token = $_POST['csrf_token'] ?? $_POST['_csrf'] ?? $_POST['token'] ?? null;

            // Check Headers
            if (empty($token)) {
                $headers = function_exists('getallheaders') ? getallheaders() : [];
                $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? null;
            }

            // Check JSON Payload if applicable
            if (empty($token)) {
                $raw = file_get_contents('php://input');
                if ($raw) {
                    $json = json_decode($raw, true);
                    if (is_array($json)) {
                        $token = $json['csrf_token'] ?? $json['_csrf'] ?? $json['token'] ?? null;
                    }
                }
            }
        }

        if (empty($token)) {
            return false;
        }

        return hash_equals($session_token, (string)$token);
    }
}

/**
 * Rate Limiting & Brute-Force Protection
 */
if (!function_exists('sec_check_rate_limit')) {
    function sec_check_rate_limit($action, $max_attempts = 15, $decay_seconds = 60) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = "rate_limit_" . md5($ip . '_' . $action);
        $now = time();

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'attempts' => 1,
                'first_attempt' => $now
            ];
            return true;
        }

        $rate = &$_SESSION[$key];

        if (($now - $rate['first_attempt']) > $decay_seconds) {
            $rate['attempts'] = 1;
            $rate['first_attempt'] = $now;
            return true;
        }

        $rate['attempts']++;

        if ($rate['attempts'] > $max_attempts) {
            return false;
        }

        return true;
    }
}

/**
 * Database Re-Verification of Logged-In User
 * Ensures role, station, and active status are fetched directly from MySQL
 */
if (!function_exists('validate_server_session_user')) {
    function validate_server_session_user($pdo) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $user_id = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;
        if (!$user_id || !$pdo) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, username, email, role, station_id, status FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$dbUser) {
                return false;
            }

            // Check if user is disabled or archived
            $status = strtolower(trim((string)($dbUser['status'] ?? 'active')));
            if (in_array($status, ['disabled', 'archived', 'inactive', 'locked'], true)) {
                return false;
            }

            // Update session with authoritative database values
            $_SESSION['user_id'] = (int)$dbUser['id'];
            $_SESSION['user']['id'] = (int)$dbUser['id'];
            $_SESSION['user']['username'] = $dbUser['username'];
            $_SESSION['user']['role'] = $dbUser['role'];
            $_SESSION['user']['station_id'] = (int)$dbUser['station_id'];
            $_SESSION['user']['status'] = $dbUser['status'];

            return $_SESSION['user'];
        } catch (Exception $e) {
            error_log("Security user re-validation failed: " . $e->getMessage());
            return $_SESSION['user'] ?? false;
        }
    }
}

/**
 * Authoritative Server-Side Security Enforcer
 * Enforces session, 5-min inactivity, DB user status, RBAC permission, branch isolation, and CSRF token.
 * Rejects tampered or unauthorized requests with HTTP 403.
 */
if (!function_exists('enforce_server_security')) {
    function enforce_server_security($required_permission = null, $target_station_id = null, $require_csrf = true) {
        global $pdo;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // 1. Session & Inactivity Timeout (Dynamically loaded from system_settings)
        $user_st_id = (int)($_SESSION['user']['station_id'] ?? $_SESSION['station_id'] ?? 0);
        $timeout = function_exists('get_system_session_timeout_seconds') 
            ? get_system_session_timeout_seconds($pdo, $user_st_id) 
            : 1800;

        if (empty($_SESSION['user']) || empty($_SESSION['user_id'])) {
            sec_reject_request(401, 'Unauthorized access. Please log in.');
        }

        // NOTE: Superadmin and Developer are strictly exempt from session timeout
        $__current_role = '';
        if (!empty($_SESSION['user']['role'])) {
            $__current_role = function_exists('role_key') ? role_key($_SESSION['user']['role']) : strtolower(trim($_SESSION['user']['role']));
        } elseif (!empty($_SESSION['role'])) {
            $__current_role = function_exists('role_key') ? role_key($_SESSION['role']) : strtolower(trim($_SESSION['role']));
        }
        $__is_exempt = in_array($__current_role, ['superadmin', 'developer'], true);

        if (!$__is_exempt && isset($_SESSION['last_activity'])) {
            $inactive = time() - (int)$_SESSION['last_activity'];
            if ($inactive >= $timeout) {
                $_SESSION = [];
                session_destroy();
                sec_reject_request(401, 'Session expired due to inactivity. Please log in again.');
            }
        }

        // Only update last activity timestamp on REAL user requests, NOT background polling/heartbeats
        $is_background_poll = !empty($_SERVER['HTTP_X_PETRON_BACKGROUND']) 
            || (!empty($_GET['ajax_sss'])) 
            || (!empty($_GET['ajax'])) 
            || (!empty($_GET['poll'])) 
            || (isset($_GET['action']) && in_array(strtolower((string)$_GET['action']), ['check_maintenance', 'unread_count', 'get_unread_count', 'ping', 'heartbeat', 'notifications_count', 'badge_count'], true))
            || (isset($_SERVER['SCRIPT_NAME']) && (
                strpos($_SERVER['SCRIPT_NAME'], '_generator.php') !== false ||
                strpos($_SERVER['SCRIPT_NAME'], 'maintenance_status.php') !== false ||
                strpos($_SERVER['SCRIPT_NAME'], 'notifications_api.php') !== false
            ));

        if (!$is_background_poll) {
            $_SESSION['last_activity'] = time();
        }

        // 2. Database Re-verification of User
        $user = validate_server_session_user($pdo);
        if (!$user) {
            $_SESSION = [];
            session_destroy();
            sec_reject_request(403, 'User account is inactive or invalid.');
        }

        // 3. CSRF Verification for state-changing requests
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($require_csrf && in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            if (!sec_verify_csrf_token()) {
                sec_reject_request(403, 'CSRF token validation failed. Request rejected.');
            }
        }

        // 4. RBAC Permission Check
        $role = function_exists('role_key') ? role_key($user['role'] ?? '') : strtolower(trim($user['role'] ?? 'staff'));
        if ($required_permission !== null) {
            if (function_exists('has_permission') && !has_permission($required_permission, $role)) {
                if (function_exists('get_user_permissions')) {
                    $perms = get_user_permissions($role);
                    if (!in_array($required_permission, $perms, true) && !in_array($role, ['superadmin', 'admin'], true)) {
                        sec_reject_request(403, 'Forbidden: Insufficient permissions for action (' . $required_permission . ').');
                    }
                }
            }
        }

        // 5. Branch / Station Isolation Check
        if ($target_station_id !== null && !in_array($role, ['superadmin', 'super admin'], true)) {
            $user_station = (int)($user['station_id'] ?? 0);
            if ($user_station > 0 && (int)$target_station_id !== $user_station) {
                sec_reject_request(403, 'Forbidden: Branch access violation. Target station ID mismatch.');
            }
        }

        return $user;
    }
}

/**
 * Rejects request with HTTP status code and clean JSON or error text
 */
if (!function_exists('sec_reject_request')) {
    function sec_reject_request($code = 403, $message = 'Forbidden') {
        http_response_code($code);

        // Check if request expects JSON or is API
        $is_json = (
            (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
            (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
            (isset($_SERVER['SCRIPT_NAME']) && (strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/backend/') !== false)) ||
            (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        );

        if ($is_json) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false, 'ok' => false, 'error' => $message, 'message' => $message]);
            exit;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html><head><title>' . $code . ' Forbidden</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background:#f8fafc;color:#1e293b;} .box{max-width:480px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);}</style></head><body><div class="box"><h2 style="color:#dc2626;">HTTP ' . $code . ' - Request Rejected</h2><p>' . htmlspecialchars($message) . '</p><p><a href="/public/index.php" style="color:#002F6C;">Return to Safety</a></p></div></body></html>';
        exit;
    }
}

/**
 * Retrieves the authoritative price directly from MySQL DB tables, discarding client-side submitted prices.
 */
if (!function_exists('get_authoritative_item_price')) {
    function get_authoritative_item_price($pdo, $product_id, $product_type = 'merchandise') {
        if (!$pdo || empty($product_id)) return 0.00;

        // 1. Check inventory_products table
        try {
            $stmt = $pdo->prepare("SELECT unit_price FROM inventory_products WHERE id = ? OR sku = ? LIMIT 1");
            $stmt->execute([$product_id, $product_id]);
            $p = $stmt->fetchColumn();
            if ($p !== false && $p !== null && (float)$p > 0) {
                return (float)$p;
            }
        } catch (Exception $e) {}

        // 2. Check products table (unit_price or price)
        try {
            $stmt = $pdo->prepare("SELECT unit_price FROM products WHERE id = ? OR sku = ? LIMIT 1");
            $stmt->execute([$product_id, $product_id]);
            $p = $stmt->fetchColumn();
            if ($p !== false && $p !== null && (float)$p > 0) {
                return (float)$p;
            }
        } catch (Exception $e) {}

        // 3. Check fuel_types table (price_per_liter)
        try {
            $stmt = $pdo->prepare("SELECT price_per_liter FROM fuel_types WHERE id = ? OR name = ? LIMIT 1");
            $stmt->execute([$product_id, $product_id]);
            $p = $stmt->fetchColumn();
            if ($p !== false && $p !== null && (float)$p > 0) {
                return (float)$p;
            }
        } catch (Exception $e) {}

        // 4. Check services table
        try {
            $stmt = $pdo->prepare("SELECT price FROM services WHERE id = ? OR name = ? LIMIT 1");
            $stmt->execute([$product_id, $product_id]);
            $p = $stmt->fetchColumn();
            if ($p !== false && $p !== null && (float)$p > 0) {
                return (float)$p;
            }
        } catch (Exception $e) {}

        return 0.00;
    }
}

