<?php

// ── DYNAMIC TIMEZONE & REGIONAL SYSTEM SETTINGS HELPERS ──
if (!function_exists('petron_get_setting_value')) {
    function petron_get_setting_value(string $key, ?int $station_id = null, string $default = ''): string {
        global $pdo;
        try {
            if (!isset($pdo) || !$pdo) {
                require_once __DIR__ . '/../public/db_connect.php';
            }
            if ($station_id === null && function_exists('user_station_id')) {
                $station_id = (int)user_station_id();
            }
            if ($station_id !== null && $station_id > 0) {
                $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? AND station_id = ? AND setting_value IS NOT NULL AND setting_value != '' LIMIT 1");
                $stmt->execute([$key, $station_id]);
                $val = $stmt->fetchColumn();
                if ($val !== false && $val !== null && $val !== '') {
                    return (string)$val;
                }
            }
            $stmt0 = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? AND station_id = 0 LIMIT 1");
            $stmt0->execute([$key]);
            $val0 = $stmt0->fetchColumn();
            if ($val0 !== false && $val0 !== null && $val0 !== '') {
                return (string)$val0;
            }
        } catch (Throwable $e) {}
        return $default;
    }
}

if (!function_exists('petron_init_dynamic_timezone')) {
    function petron_init_dynamic_timezone(?int $station_id = null): void {
        global $pdo;
        try {
            if (!isset($pdo) || !$pdo) {
                require_once __DIR__ . '/../public/db_connect.php';
            }
            $storedTz = petron_get_setting_value('timezone', $station_id, 'Asia/Manila (UTC+8)');
            if ($storedTz) {
                $tzIdent = trim(explode(' ', $storedTz)[0]);
                if (in_array($tzIdent, timezone_identifiers_list(), true)) {
                    date_default_timezone_set($tzIdent);
                    try {
                        $offset = date('P');
                        $pdo->exec("SET time_zone = '{$offset}'");
                    } catch (Throwable $t) {}
                    return;
                }
            }
        } catch (Throwable $e) {}
        date_default_timezone_set('Asia/Manila');
    }
    petron_init_dynamic_timezone();
}

if (!function_exists('petron_currency_symbol')) {
    function petron_currency_symbol(?int $station_id = null): string {
        $raw = petron_get_setting_value('currency_symbol', $station_id, 'PHP (₱)');
        if ($raw && preg_match('/\((.*?)\)/', $raw, $m)) {
            return trim($m[1]) ?: '₱';
        }
        return trim($raw) ?: '₱';
    }
}

if (!function_exists('petron_date_format')) {
    function petron_date_format(?int $station_id = null): string {
        return petron_get_setting_value('date_format', $station_id, 'YYYY-MM-DD');
    }
}

if (!function_exists('petron_php_date_format')) {
    function petron_php_date_format(?int $station_id = null): string {
        $fmt = petron_date_format($station_id);
        switch (strtoupper(trim($fmt))) {
            case 'MM/DD/YYYY': return 'm/d/Y';
            case 'DD/MM/YYYY': return 'd/m/Y';
            case 'MMM DD, YYYY': return 'M d, Y';
            case 'YYYY-MM-DD':
            default:
                return 'Y-m-d';
        }
    }
}

if (!function_exists('petron_time_format')) {
    function petron_time_format(?int $station_id = null): string {
        $val = strtoupper(trim(petron_get_setting_value('time_format', $station_id, '12H')));
        return (strpos($val, '24') !== false) ? '24H' : '12H';
    }
}

if (!function_exists('petron_php_time_format')) {
    function petron_php_time_format(bool $with_seconds = true, ?int $station_id = null): string {
        $is24 = (petron_time_format($station_id) === '24H');
        if ($is24) {
            return $with_seconds ? 'H:i:s' : 'H:i';
        }
        return $with_seconds ? 'h:i:s A' : 'h:i A';
    }
}

if (!function_exists('petron_format_date')) {
    function petron_format_date($val, ?int $station_id = null): string {
        if (empty($val) || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') return 'N/A';
        $ts = is_numeric($val) ? (int)$val : strtotime((string)$val);
        if (!$ts) return (string)$val;
        return date(petron_php_date_format($station_id), $ts);
    }
}

if (!function_exists('petron_format_time')) {
    function petron_format_time($val, bool $with_seconds = true, ?int $station_id = null): string {
        if (empty($val) || $val === '00:00:00' || $val === '0000-00-00 00:00:00') return 'N/A';
        $ts = is_numeric($val) ? (int)$val : strtotime((string)$val);
        if (!$ts) return (string)$val;
        return date(petron_php_time_format($with_seconds, $station_id), $ts);
    }
}

if (!function_exists('petron_format_datetime')) {
    function petron_format_datetime($val, bool $with_seconds = true, ?int $station_id = null): string {
        if (empty($val) || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') return 'N/A';
        $ts = is_numeric($val) ? (int)$val : strtotime((string)$val);
        if (!$ts) return (string)$val;
        $d = date(petron_php_date_format($station_id), $ts);
        $t = date(petron_php_time_format($with_seconds, $station_id), $ts);
        return $d . ' ' . $t;
    }
}

if (!function_exists('petron_format_currency')) {
    function petron_format_currency($amount, int $decimals = 2, ?int $station_id = null): string {
        $sym = petron_currency_symbol($station_id);
        $num = is_numeric($amount) ? (float)$amount : 0.0;
        return $sym . ' ' . number_format($num, $decimals);
    }
}

require_once __DIR__ . '/security_helpers.php';

if (!function_exists('is_system_in_maintenance_mode')) {
    function is_system_in_maintenance_mode() {
        global $pdo;
        if (!isset($pdo) || !$pdo) {
            try {
                require_once __DIR__ . '/../public/db_connect.php';
            } catch (Exception $e) {
                return false;
            }
        }
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode' AND station_id = 0 LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            return ($val === '1' || $val === 1 || $val === 'true');
        } catch (Exception $e) {
            return false;
        }
    }
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

// ── CANONICAL PAYMENT TAXONOMY & HELPERS ────────────────────────────────────
if (!function_exists('get_allowed_payment_types')) {
    function get_allowed_payment_types(): array {
        return [
            'Cash',
            'Card',
            'E-Wallet',
            'Petron Fleet Card',
            'Credit Account',
            'Petron Loyalty Points',
        ];
    }
}

if (!function_exists('get_allowed_ewallet_providers')) {
    function get_allowed_ewallet_providers(): array {
        return [
            'GCash',
            'Maya',
        ];
    }
}

if (!function_exists('normalize_payment_type')) {
    /**
     * Standardizes any payment input or legacy record into the canonical taxonomy:
     * - Payment Types: Cash, Card, E-Wallet, Petron Fleet Card, Credit Account, Petron Loyalty Points
     * - E-Wallet Providers: GCash, Maya
     */
    function normalize_payment_type(?string $method, ?string $provider = null): array {
        $rawMethod = trim((string)$method);
        $rawProvider = trim((string)$provider);
        $lowerMethod = strtolower($rawMethod);
        $lowerProvider = strtolower($rawProvider);

        if ($rawMethod === '') {
            return [
                'payment_type' => 'Cash',
                'provider'     => null,
                'card_type'    => null,
                'is_ewallet'   => false,
            ];
        }

        // E-Wallet detection (including legacy GCash / Maya as top-level method)
        if ($lowerMethod === 'gcash' || $lowerProvider === 'gcash') {
            return [
                'payment_type' => 'E-Wallet',
                'provider'     => 'GCash',
                'card_type'    => null,
                'is_ewallet'   => true,
            ];
        }
        if ($lowerMethod === 'maya' || $lowerMethod === 'paymaya' || $lowerProvider === 'maya' || $lowerProvider === 'paymaya') {
            return [
                'payment_type' => 'E-Wallet',
                'provider'     => 'Maya',
                'card_type'    => null,
                'is_ewallet'   => true,
            ];
        }
        if ($lowerMethod === 'e-wallet' || $lowerMethod === 'ewallet' || strpos($lowerMethod, 'wallet') !== false) {
            $resolvedProvider = ($lowerProvider === 'maya' || $lowerProvider === 'paymaya') ? 'Maya' : 'GCash';
            if (empty($rawProvider)) {
                if (stripos($rawMethod, 'maya') !== false || stripos($rawMethod, 'paymaya') !== false) {
                    $resolvedProvider = 'Maya';
                } elseif (stripos($rawMethod, 'gcash') !== false) {
                    $resolvedProvider = 'GCash';
                } else {
                    $resolvedProvider = null;
                }
            }
            return [
                'payment_type' => 'E-Wallet',
                'provider'     => $resolvedProvider,
                'card_type'    => null,
                'is_ewallet'   => true,
            ];
        }

        // Card detection (including legacy Credit Card / Debit Card)
        if ($lowerMethod === 'card' || $lowerMethod === 'credit card' || $lowerMethod === 'debit card') {
            $cardType = ($lowerMethod === 'debit card') ? 'Debit Card' : (($lowerMethod === 'credit card') ? 'Credit Card' : null);
            return [
                'payment_type' => 'Card',
                'provider'     => null,
                'card_type'    => $cardType,
                'is_ewallet'   => false,
            ];
        }

        // Petron Fleet Card
        if (strpos($lowerMethod, 'fleet') !== false) {
            return [
                'payment_type' => 'Petron Fleet Card',
                'provider'     => null,
                'card_type'    => null,
                'is_ewallet'   => false,
            ];
        }

        // Credit Account
        if (strpos($lowerMethod, 'credit') !== false || strpos($lowerMethod, 'utang') !== false || strpos($lowerMethod, 'receivable') !== false) {
            return [
                'payment_type' => 'Credit Account',
                'provider'     => null,
                'card_type'    => null,
                'is_ewallet'   => false,
            ];
        }

        // Petron Loyalty Points
        if (strpos($lowerMethod, 'loyalt') !== false || strpos($lowerMethod, 'reward') !== false || strpos($lowerMethod, 'point') !== false) {
            return [
                'payment_type' => 'Petron Loyalty Points',
                'provider'     => null,
                'card_type'    => null,
                'is_ewallet'   => false,
            ];
        }

        // Cash
        if ($lowerMethod === 'cash') {
            return [
                'payment_type' => 'Cash',
                'provider'     => null,
                'card_type'    => null,
                'is_ewallet'   => false,
            ];
        }

        // Fallback
        return [
            'payment_type' => $rawMethod,
            'provider'     => $rawProvider ?: null,
            'card_type'    => null,
            'is_ewallet'   => false,
        ];
    }
}

if (!function_exists('format_payment_for_record')) {
    /**
     * Formats payment details cleanly for receipts, reports, dashboards, and modals
     */
    function format_payment_for_record(array $row): array {
        $norm = normalize_payment_type($row['payment_method'] ?? null, $row['ewallet_provider'] ?? null);
        $type = $norm['payment_type'];
        $provider = $norm['provider'] ?? ($row['ewallet_provider'] ?? null);
        $ref = $row['ewallet_reference'] ?? $row['card_reference'] ?? $row['fleet_card_number'] ?? $row['ref_no'] ?? null;
        $amt = (float)($row['total_amount'] ?? $row['amount_paid'] ?? $row['total'] ?? $row['amount'] ?? 0);
        $sym = function_exists('petron_currency_symbol') ? petron_currency_symbol() : '₱';

        $lines = ["Payment Type: {$type}"];
        if ($type === 'E-Wallet' && !empty($provider)) {
            $lines[] = "Provider: {$provider}";
        }
        $lines[] = "Amount: {$sym}" . number_format($amt, 2);
        if (!empty($ref) && $ref !== 'N/A') {
            $lines[] = "Reference No.: {$ref}";
        }

        return [
            'payment_type'     => $type,
            'provider'         => $provider,
            'reference_no'     => $ref,
            'amount'           => $amt,
            'amount_formatted' => $sym . number_format($amt, 2),
            'display_text'     => implode("\n", $lines),
            'display_inline'   => ($type === 'E-Wallet' && $provider) ? "{$type} ({$provider})" : $type,
        ];
    }
}


// Simple JSON-based storage helpers (no DB required)
function data_path($file){ return __DIR__ . '/../data/' . $file; }

function read_json($file, $default){
  $path = data_path($file);
  if(!file_exists($path)) return $default;
  $raw = file_get_contents($path);
  $data = json_decode($raw, true);
  return $data === null ? $default : $data;
}

function write_json($file, $data){
  $path = data_path($file);
  $tmp = $path . '.tmp';
  file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
  rename($tmp, $path);
}

function json_response($data, $code=200){
  http_response_code($code);
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($data);
  exit;
}

if (!function_exists('clean_mojibake')) {
    /**
     * Automatically cleans corrupted mojibake and encoding artifacts.
     * Restores CP437, Cyrillic CP866/1251, and Windows-1252 misinterpretations of UTF-8:
     * e.g. "ΓÇö" -> "—", "ГÇÖ" -> "—", "Γé▒" -> "₱", "â‚±" -> "₱", "â€”" -> "—"
     */
    function clean_mojibake(?string $str): string {
        if ($str === null || $str === '') {
            return '';
        }
        static $map = [
            // Em dash / En dash / hyphens
            'ΓÇö'  => '—',
            'ΓÇÖ'  => '—',
            'ГÇÖ'  => '—',
            'ГÇö'  => '—',
            'ΓÇô'  => '–',
            'â€”'  => '—',
            'â€“'  => '–',
            
            // Peso sign
            'Γé▒'  => '₱',
            'â‚±'  => '₱',
            
            // Single quotes / apostrophes
            'â€™'  => "'",
            'â€˜'  => "'",
            'ΓÇÿ'  => "'",
            'â€²'  => "'",
            
            // Double quotes
            'â€œ'  => '"',
            'â€'  => '"',
            'ΓÇ£'  => '"',
            'ΓÇ¥'  => '"',
            
            // Bullet / middot
            'â€¢'  => '•',
            'ΓÇó'  => '•',
            'Â·'   => '·',
            
            // Accented characters
            'Ã©'   => 'é',
            'Ã¨'   => 'è',
            'Ã±'   => 'ñ',
            'Ã‘'   => 'Ñ',
            'Ã¡'   => 'á',
            'Ã³'   => 'ó',
            'Ãº'   => 'ú',
            'Ã­'   => 'í',
        ];
        return strtr($str, $map);
    }
}

if (!function_exists('is_user_archived_status')) {
    function is_user_archived_status(?string $status): bool {
        $normalized = strtolower(trim((string)$status));
        return in_array($normalized, ['disabled', 'archived', 'inactive', 'locked'], true);
    }
}

function require_login(){
  if(session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }

  // ── Dynamic Session Timeout from system_settings ──
  $timeout = 1800; // fallback default = 30 minutes in seconds
  try {
    global $pdo;
    if (!isset($pdo) || !$pdo) {
      require_once __DIR__ . '/../public/db_connect.php';
    }
    $stmtTmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout' AND station_id = 0 LIMIT 1");
    $stmtTmt->execute();
    $storedTimeout = $stmtTmt->fetchColumn();
    if ($storedTimeout !== false && is_numeric($storedTimeout) && (int)$storedTimeout > 0) {
      $timeoutMinutes = max(1, (int)$storedTimeout); // Enforce whatever minutes are configured (min: 1 minute)
      $timeout = $timeoutMinutes * 60; // convert minutes to seconds
    }
  } catch (Exception $e) {
    // keep fallback default
  }
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $root = rtrim(dirname($script), '/\\');
  if($root === '' || $root === '.') $root = '/';
  $loginUrl = rtrim($root, '/') . '/login.php';

  // ── Maintenance Mode Gatekeeper ──
  if (is_system_in_maintenance_mode()) {
    $session_user = $_SESSION['user'] ?? null;
    $user_role = '';
    if ($session_user) {
      $rRaw = $session_user['role'] ?? '';
      $user_role = function_exists('role_key') ? role_key($rRaw) : strtolower(trim($rRaw));
    }
    // Only Superadmin & Developer are permitted during maintenance mode
    if (!in_array($user_role, ['superadmin', 'developer'])) {
      $_SESSION = [];
      if (ini_get('session.use_cookies') && !headers_sent()) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
      }
      if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
      }

      if (strpos($script, '/backend/') !== false || strpos($script, '/api/') !== false) {
        json_response(['ok' => false, 'maintenance' => true, 'error' => 'System is under maintenance'], 503);
      }

      if (!headers_sent()) {
        header('Location: ' . $loginUrl . '?maintenance=1');
      } else {
        echo '<script>window.location.href="' . htmlspecialchars($loginUrl . '?maintenance=1') . '";</script>';
      }
      exit;
    }
  }


  // Re-verify session user from database if DB connection exists
  if (!empty($_SESSION['user']) && function_exists('validate_server_session_user')) {
    global $pdo;
    if (isset($pdo) && $pdo) {
      $valid_user = validate_server_session_user($pdo);
      if (!$valid_user) {
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
          $p = session_get_cookie_params();
          setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
      }
    }
  }

  // Check if active user session has expired due to inactivity
  if (!empty($_SESSION['user'])) {
    if (isset($_SESSION['last_activity'])) {
      $inactive_time = time() - (int)$_SESSION['last_activity'];
      if ($inactive_time >= $timeout) {
        // Destroy session data
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
          $p = session_get_cookie_params();
          setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();

        // Prevent browser caching
        if (!headers_sent()) {
          header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
          header('Cache-Control: post-check=0, pre-check=0', false);
          header('Pragma: no-cache');
          header('Expires: 0');
        }

        // If called from /backend/* API, return JSON 401
        if (strpos($script, '/backend/') !== false) {
          json_response(['ok' => false, 'error' => 'Session expired due to inactivity', 'timeout' => true], 401);
        }

        if (!headers_sent()) {
          header('Location: ' . $loginUrl . '?timeout=1');
        } else {
          echo '<script>window.location.href="' . htmlspecialchars($loginUrl . '?timeout=1') . '";</script>';
        }
        exit;
      }
    }

    // Only update last activity timestamp on REAL user requests, NOT background polling/heartbeats
    $is_background_poll = !empty($_SERVER['HTTP_X_PETRON_BACKGROUND']) 
        || (!empty($_GET['ajax_sss'])) 
        || (isset($_GET['poll']) && $_GET['poll'] == '1')
        || (isset($_GET['action']) && in_array($_GET['action'], ['check_maintenance', 'unread_count', 'get_unread_count', 'ping', 'heartbeat'], true));

    if (!$is_background_poll) {
      $_SESSION['last_activity'] = time();
    }
  }

  if(empty($_SESSION['user'])){
    // If called from /backend/*, return JSON 401 to avoid fetch() HTML redirects.
    if(strpos($script, '/backend/') !== false){
      json_response(['ok'=>false,'error'=>'Unauthorized'], 401);
    }
    // Destroy any partial session remnants
    $_SESSION = [];
    if(ini_get('session.use_cookies') && !headers_sent()){
      $p = session_get_cookie_params();
      setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy();

    // Prevent browser/proxy from caching the protected page
    if (!headers_sent()) {
      header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
      header('Cache-Control: post-check=0, pre-check=0', false);
      header('Pragma: no-cache');
      header('Expires: 0');
      header('Location: ' . $loginUrl);
    } else {
      echo '<script>window.location.href="' . htmlspecialchars($loginUrl) . '";</script>';
    }
    exit;
  }

  // Active session: still send no-cache headers so protected pages are
  // never stored in browser history cache (prevents Back-button bypass after logout)
  if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
  }
}


function normalize_role($role){
  $r = strtolower(trim((string)$role));
  if($r === 'superadmin' || $r === 'super admin') return 'Super Admin';
  if($r === 'developer' || $r === 'dev') return 'Developer';
  if($r === 'admin' || $r === 'admin/manager' || $r === 'station admin') return 'Admin';
  if($r === 'manager') return 'Manager';
  // Remove operations_staff - all operational roles are now 'staff'
  return 'Staff';
}

// Canonical role key used for menus/routing.
// Keeps compatibility with the existing normalize_role() labels above.
function role_key($role){
  $r = strtolower(trim((string)$role));
  if(in_array($r, ['superadmin','super admin','super_admin'])) return 'superadmin';
  if(in_array($r, ['developer','dev'])) return 'developer';
  if(in_array($r, ['admin','station admin','station_admin','owner'])) return 'admin';
  if(in_array($r, ['manager','supervisor','manager / supervisor','manager/supervisor','supervisor/manager'])) return 'manager';
  // Remove operations_staff handling - all staff roles use 'staff'
  return 'staff';
}

/**
 * Get shift time range label based on a timestamp or time string
 * Returns: "6:00 AM - 2:00 PM", "2:00 PM - 12:00 AM", or "12:00 AM - 6:00 AM"
 * 
 * @param string|int $datetime DateTime string or Unix timestamp
 * @return string Shift time range label
 */
function get_shift_label($datetime) {
    $time = is_numeric($datetime) ? date('H:i:s', $datetime) : date('H:i:s', strtotime($datetime));
    $hour = (int)substr($time, 0, 2);
    
    // Shift 1: 6:00 AM - 2:00 PM (06:00 - 13:59)
    if ($hour >= 6 && $hour < 14) {
        return '6:00 AM - 2:00 PM';
    }
    // Shift 2: 2:00 PM - 12:00 AM (14:00 - 23:59)
    elseif ($hour >= 14 && $hour <= 23) {
        return '2:00 PM - 12:00 AM';
    }
    // Night Shift: 12:00 AM - 6:00 AM (00:00 - 05:59)
    else {
        return '12:00 AM - 6:00 AM';
    }
}

/**
 * Determine operational shift (Shift 1 vs Shift 2) under single Staff account.
 * Shift 1: 6:00 AM – 2:00 PM (06:00:00 – 13:59:59)
 * Shift 2: 2:00 PM – 12:00 MN (14:00:00 – 05:59:59)
 */
function get_active_operational_shift($datetime = null): string {
    $time = $datetime ? (is_numeric($datetime) ? date('H:i:s', (int)$datetime) : date('H:i:s', strtotime($datetime))) : date('H:i:s');
    return ($time >= '06:00:00' && $time < '14:00:00') ? 'Shift 1' : 'Shift 2';
}

/**
 * SQL CASE statement for determining shift based on time column
 * Use this in SQL queries to auto-assign shift labels
 * 
 * @param string $time_column The datetime/time column name
 * @return string SQL CASE statement
 */
function get_shift_sql_case($time_column) {
    return "CASE
        WHEN TIME($time_column) >= '06:00:00' AND TIME($time_column) < '14:00:00' 
            THEN '6:00 AM - 2:00 PM'
        WHEN TIME($time_column) >= '14:00:00' AND TIME($time_column) <= '23:59:59'
            THEN '2:00 PM - 12:00 AM'
        ELSE '12:00 AM - 6:00 AM'
    END";
}

/**
 * Log authentication audit trail events to audit_logs and activity_logs
 * Event types: LOGIN_SUCCESS, LOGIN_FAILED, PASSWORD_RESET_REQUESTED, PASSWORD_RESET_OTP_SENT, PASSWORD_RESET_OTP_FAILED, PASSWORD_RESET_OTP_VERIFIED, PASSWORD_RESET_COMPLETED
 */
if (!function_exists('log_auth_audit_trail')) {
function log_auth_audit_trail($pdo, $user_id, $email, $action, $status, $details = '') {
    if (!$pdo) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $clean_email = trim((string)$email);

    // 1. Log to activity_logs
    try {
        $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
        if (!empty($tables)) {
            $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id ?: 0, "Auth: {$action}", ($details ?: "Action: {$action} Status: {$status} Target: {$clean_email}"), $ip]);
        }
    } catch (Exception $e) {}

    // 2. Log to audit_logs
    try {
        $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
        if (!empty($tables)) {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'authentication', ?, ?, 'users', ?, ?, ?, ?, NOW())");
            $stmt->execute([$user_id ?: null, $action, ($details ?: "{$action} - Status: {$status} ({$clean_email})"), $user_id ?: 0, $status, $ip, $ua]);
        }
    } catch (Exception $e) {}
}
}

// ── Module Configuration Helpers ─────────────────────────────
// Maps module_key → page_id values that belong to that module.
// Used by sidebar filtering and page-level gate checks.
define('MODULE_PAGE_MAP', [
    'transactions'    => ['transactions', 'staff_transactions_hub', 'transactions_variance', 'transactions_shift'],
    'job_orders'      => ['job_orders'],
    'fuel_management' => ['fuel', 'fuel_readings', 'fuel_inventory', 'reconciliation',
                          'fuel_monitoring', 'fuel_shift_processing', 'manager_fuel_management'],
    'calendar'        => ['calendar', 'staff_calendar', 'admin_calendar'],
    'reports'         => ['reports', 'staff_reports', 'manager_reports', 'admin_reports',
                          'reports_audit_admin', 'report_job_orders', 'report_transactions',
                          'report_customers', 'report_activity', 'admin_procurement_reports',
                          'manager_procurement_reports', 'admin_inventory_history',
                          'admin_inventory_merch', 'admin_inventory_fuel'],
]);

// Maps module_key → sidebar item IDs that belong to that module.
define('MODULE_MENU_MAP', [
    // ── Core Operational Modules ──────────────────────────────────────────────
    'dashboard'             => [
        'dashboard', 'manager_dashboard', 'admin_dashboard', 'staff_dashboard'
    ],
    'transactions'          => [
        'transactions', 'admin_transactions', 'staff_new_transaction', 'staff_transaction_history', 'admin_all_transactions',
        'fuel_merch_transactions', 'variance_alerts',
        'shift_transactions_view', 'merchandise_transaction', 'shift_transactions',
        'staff_fuel_transactions', 'pending_transactions', 'pending_transactions_manager',
        'validated_transactions_manager', 'manager_request_data_management', 'manager_mechanics_management',
        'ato_oversight_dashboard', 'ato_variance_reports'
    ],
    'job_orders'            => [
        'job_orders', 'job_encode', 'job_tracker', 'report_jo_tracker', 'mgr_prod_services',
        'mgr_report_joborders', 'rpt_job_orders'
    ],
    'fuel_management'       => [
        'fuel', 'admin_fuel_management', 'fuel_meter_encoding', 'admin_fuel_oversight',
        'staff_fuel_deliveries_sub', 'staff_fuel_del_history',
        'staff_fuel_transactions', 'admin_fuel_transactions_oversight',
        'admin_fuel_adjustments_oversight', 'admin_pump_master_oversight', 'fuel_transactions_validation',
        'fuel_deliveries_validation', 'fuel_adjustments', 'fuel_pump_master', 'mgr_prod_fuel',
        'admin_inventory_fuel'
    ],
    // merchandise = Merchandise Deliveries (staff/manager recording)
    'merchandise'           => [
        'staff_deliveries', 'manager_deliveries', 'admin_merchandise_deliveries', 'mgr_del_record',
        'mgr_del_history', 'mgr_del_discrepancies', 'staff_record_del', 'staff_delivery_history'
    ],
    // merchandise_deliveries = alias used in station_modules table — same sidebar items
    'merchandise_deliveries' => [
        'staff_deliveries', 'manager_deliveries', 'admin_merchandise_deliveries', 'mgr_del_record',
        'mgr_del_history', 'mgr_del_discrepancies', 'staff_record_del', 'staff_delivery_history'
    ],
    // deliveries = generic alias used in station_modules table
    'deliveries'            => [
        'staff_deliveries', 'manager_deliveries', 'admin_merchandise_deliveries', 'mgr_del_record',
        'mgr_del_history', 'mgr_del_discrepancies', 'staff_record_del', 'staff_delivery_history'
    ],
    'inventory'             => [
        'inventory', 'admin_inventory', 'inv_merch', 'inv_fuel',
        'staff_stock_requests', 'staff_record_delivery', 'inv_stock_request', 'staff_stock_in',
        'mgr_stock_review', 'mgr_stock_in', 'inv_history', 'mgr_prod_merchandise',
        'mgr_prod_prices', 'mgr_inv_merch', 'mgr_inv_fuel', 'mgr_inv_stock_request',
        'mgr_inv_po_gen', 'mgr_del_validate', 'admin_inventory_merchandise', 'admin_purchase_orders',
        'admin_stock_requests', 'admin_stock_requests_monitor', 'admin_stock_in', 'admin_stock_in_oversight', 'admin_inventory_history'
    ],
    'product_management'    => [
        'product_management', 'mgr_prod_merchandise', 'mgr_prod_fuel', 'mgr_prod_services',
        'mgr_prod_prices', 'mgr_prod_adjustment', 'admin_product_pricing', 'mgr_product_pricing'
    ],
    'product_pricing'       => [
        'admin_product_pricing', 'mgr_product_pricing', 'product_management', 'mgr_prod_merchandise',
        'mgr_prod_fuel', 'mgr_prod_prices', 'mgr_prod_adjustment'
    ],
    'purchase_orders'       => [
        'mgr_inv_po_gen', 'admin_purchase_orders', 'purchase_orders'
    ],
    'calendar'              => [
        'calendar', 'admin_calendar'
    ],
    'reports'               => [
        'reports', 'admin_reports', 'manager_reports', 'report_daily_sales', 'report_deliveries',
        'report_payments', 'report_customers', 'report_activity', 'rpt_operations', 'rpt_finance',
        'rpt_procurement', 'rpt_compliance', 'mgr_operations_reports', 'mgr_finance_reports',
        'mgr_procurement_reports', 'mgr_compliance_reports', 'rpt_merch_inventory',
        'rpt_fuel_inventory', 'rpt_delivery_reports', 'rpt_inventory_history', 'rpt_audit_trail',
        'rpt_sales', 'rpt_balances', 'rpt_deliveries', 'rpt_staff', 'rpt_audit', 'mgr_report_sales',
        'mgr_report_balances', 'mgr_report_deliveries', 'mgr_report_staff', 'mgr_report_validation',
        'mgr_report_audit'
    ],
    'customers'             => [
        'customers', 'customer_add', 'customer_list', 'customer_history', 'mgr_customers'
    ],
    'payments'              => [
        'transactions', 'admin_transactions', 'pending_transactions_manager', 'validated_transactions_manager'
    ],
    'staff_management'      => [
        'staff_oversight_admin', 'users'
    ],
    'users'                 => [
        'users'
    ],
    'admin_unlock'          => [
    ],
]);

/**
 * Load all module enabled states from DB into a static cache.
 * Returns associative array: ['transactions' => true, 'job_orders' => false, ...]
 * Falls back to all-enabled if DB is unavailable.
 */
function get_module_states(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    global $pdo;
    
    // Default modules (all enabled by default)
    $cache = [
        'dashboard'              => true,
        'transactions'           => true,
        'job_orders'             => true,
        'fuel_management'        => true,
        'merchandise'            => true,
        'merchandise_deliveries' => true,
        'deliveries'             => true,
        'payments'               => true,
        'inventory'              => true,
        'product_management'     => true,
        'product_pricing'        => true,
        'purchase_orders'        => true,
        'calendar'               => true,
        'reports'                => true,
        'customers'              => true,
        'staff_management'       => true,
        'admin_unlock'           => true,
        'notifications'          => true,
        'backup_restore'         => true,
        'audit_trail'            => true,
        'api_integration'        => true,
        'users'                  => true,
    ];

    if (!isset($pdo)) return $cache;

    try {
        // 1. Read module_settings global table first
        $stmt = $pdo->query("SELECT module_key, is_enabled FROM module_settings");
        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $key => $val) {
                $cache[$key] = (bool)(int)$val;
            }
        }
        
        // 2. Override with station-dependent module settings if applicable
        $station_id = user_station_id();
        if ($station_id) {
            $stmt = $pdo->prepare("SELECT module_key, is_enabled FROM station_modules WHERE station_id = ?");
            $stmt->execute([$station_id]);
            $stRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($stRows as $key => $val) {
                $cache[$key] = (bool)(int)$val;
            }
        }
    } catch (Exception $e) { }

    return $cache;
}

/**
 * Check if a specific module is enabled.
 * SuperAdmin and Developer always see everything regardless of module state.
 */
function is_module_enabled(string $module_key): bool {
    $states = get_module_states();
    return $states[$module_key] ?? true; // default enabled if unknown
}

/**
 * Check if a user has access to a specific module based on their station.
 * This function checks station-dependent module configuration.
 * 
 * @param int $user_id User ID to check
 * @param string $module_key Module key (e.g., 'fuel_management', 'inventory')
 * @return bool True if module is enabled for user's station
 */
function hasModuleAccess(int $user_id, string $module_key): bool {
    global $pdo;
    
    if (!isset($pdo)) return true; // Default to enabled if no DB
    
    try {
        // Get user's role and station
        $stmt = $pdo->prepare("SELECT role, station_id FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) return false;
        
        // SuperAdmin and Developer always have access to all modules
        $role = role_key($user['role'] ?? '');
        if (in_array($role, ['superadmin', 'developer'], true)) {
            return true;
        }

        // Check module_settings user_access role restrictions (e.g., limit Staff)
        try {
            $mStmt = $pdo->prepare("SELECT user_access, is_enabled FROM module_settings WHERE module_key = ?");
            $mStmt->execute([$module_key]);
            $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
            if ($mRow) {
                if (isset($mRow['is_enabled']) && !(int)$mRow['is_enabled']) {
                    return false;
                }
                if (!empty($mRow['user_access'])) {
                    $allowedRoles = array_map('trim', explode(',', strtolower($mRow['user_access'])));
                    if (!in_array(strtolower($role), $allowedRoles, true)) {
                        return false;
                    }
                }
            }
        } catch (Exception $ex) {}
        
        // Check if user has a station assigned
        $station_id = $user['station_id'] ?? null;
        if (!$station_id) return true; // No station = assume enabled
        
        // Query station_modules table
        $stmt = $pdo->prepare("
            SELECT is_enabled 
            FROM station_modules 
            WHERE station_id = ? AND module_key = ?
        ");
        $stmt->execute([$station_id, $module_key]);
        $is_enabled = $stmt->fetchColumn();
        
        // Return the module status (default to enabled if not found)
        return ($is_enabled !== false) ? (bool)(int)$is_enabled : true;
        
    } catch (Exception $e) {
        // Table may not exist yet, default to enabled
        return true;
    }
}

/**
 * Get a specific module config value.
 * Supports station overrides (module_station_config), global settings (station_id = 'all'),
 * and module_config table with type conversion.
 */
function get_module_setting(string $module_key, string $config_key, $default = null, $station_id = null) {
    global $pdo;
    if (!isset($pdo)) return $default;

    // Resolve station_id if not explicitly provided
    if ($station_id === null && function_exists('current_user')) {
        $u = current_user();
        if (!empty($u['station_id'])) {
            $station_id = (string)$u['station_id'];
        }
    }

    // Alias helper for component setting names
    $aliases = [
        'enable_pdf' => 'enable_pdf_export',
        'enable_excel' => 'enable_excel_export',
        'enable_csv' => 'enable_csv_export',
        'paper_size' => 'default_paper_size',
        'enable_low_stock_alerts' => 'enable_low_stock_alert',
        'enable_expiration' => 'enable_expiration_monitoring',
    ];
    $alt_key = $aliases[$config_key] ?? null;

    try {
        // 1. Check station-specific override in module_station_config
        if ($station_id && $station_id !== 'all') {
            $stStmt = $pdo->prepare("SELECT config_data FROM module_station_config WHERE module_key = ? AND station_id = ? LIMIT 1");
            $stStmt->execute([$module_key, (string)$station_id]);
            $sData = $stStmt->fetchColumn();
            if ($sData) {
                $decoded = json_decode($sData, true);
                if (is_array($decoded)) {
                    if (array_key_exists($config_key, $decoded)) {
                        $v = $decoded[$config_key];
                        if ($v === 'true' || $v === true || $v === '1' || $v === 1) return true;
                        if ($v === 'false' || $v === false || $v === '0' || $v === 0) return false;
                        return $v;
                    }
                    if ($alt_key && array_key_exists($alt_key, $decoded)) {
                        $v = $decoded[$alt_key];
                        if ($v === 'true' || $v === true || $v === '1' || $v === 1) return true;
                        if ($v === 'false' || $v === false || $v === '0' || $v === 0) return false;
                        return $v;
                    }
                }
            }
        }

        // 2. Check global station config (station_id = 'all' or '0')
        $allStmt = $pdo->prepare("SELECT config_data FROM module_station_config WHERE module_key = ? AND (station_id = 'all' OR station_id = '0' OR station_id = '') ORDER BY (station_id = 'all') DESC LIMIT 1");
        $allStmt->execute([$module_key]);
        $allData = $allStmt->fetchColumn();
        if ($allData) {
            $decoded = json_decode($allData, true);
            if (is_array($decoded)) {
                if (array_key_exists($config_key, $decoded)) {
                    $v = $decoded[$config_key];
                    if ($v === 'true' || $v === true || $v === '1' || $v === 1) return true;
                    if ($v === 'false' || $v === false || $v === '0' || $v === 0) return false;
                    return $v;
                }
                if ($alt_key && array_key_exists($alt_key, $decoded)) {
                    $v = $decoded[$alt_key];
                    if ($v === 'true' || $v === true || $v === '1' || $v === 1) return true;
                    if ($v === 'false' || $v === false || $v === '0' || $v === 0) return false;
                    return $v;
                }
            }
        }

        // 3. Fallback to module_config table
        $stmt = $pdo->prepare("SELECT config_value, config_type FROM module_config WHERE module_key=? AND (config_key=? OR config_key=?) LIMIT 1");
        $stmt->execute([$module_key, $config_key, $alt_key ?: $config_key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            switch ($row['config_type']) {
                case 'boolean': return (bool)(int)$row['config_value'];
                case 'integer': return (int)$row['config_value'];
                case 'decimal': return (float)$row['config_value'];
                default:
                    $val = $row['config_value'];
                    if ($val === 'true' || $val === '1') return true;
                    if ($val === 'false' || $val === '0') return false;
                    return $val;
            }
        }
    } catch (Exception $e) { return $default; }

    return $default;
}

/**
 * Render a "module disabled" page and exit.
 * Called from page entry points when a module is disabled.
 * The header has NOT been included yet when this is called.
 */
function render_module_disabled_page(string $module_name): void {
    global $page_id;
    // Include the full layout (header + sidebar)
    include __DIR__ . '/../partials/header.php';
    echo '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:60vh;text-align:center;padding:40px 20px;">';
    echo '<div style="width:80px;height:80px;border-radius:50%;background:rgba(204,0,0,.08);display:flex;align-items:center;justify-content:center;margin-bottom:20px;">';
    echo '<i class="fas fa-ban" style="font-size:36px;color:#cc0000;opacity:.6;"></i>';
    echo '</div>';
    echo '<h2 style="font-size:20px;font-weight:700;color:#00264D;margin:0 0 10px;text-transform:uppercase;">Module Disabled</h2>';
    echo '<p style="font-size:14px;color:#666;max-width:420px;margin:0 0 24px;line-height:1.6;">The <strong>' . htmlspecialchars($module_name) . '</strong> module has been disabled by the SuperAdmin. Contact your system administrator to re-enable it.</p>';
    echo '<a href="javascript:history.back()" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#00264D;color:#fff;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;">';
    echo '<i class="fas fa-arrow-left"></i> Go Back</a>';
    echo '</div>';
    include __DIR__ . '/../partials/footer.php';
    exit;
}

/**
 * RBAC Permission Matrix
 * Defines what each role can access in the system
 * Based on: Managers handle day-to-day operations, Admins provide oversight
 */

/**
 * Get user permissions based on role
 * Returns array of permission strings that the role has access to
 */
function get_user_permissions($role) {
    $role = role_key($role);
    
    $permissions = [];
    
    switch($role) {
        case 'superadmin':
            // Superadmin: Developer and system administration only
            $permissions = [
                // Basic access
                'view_dashboard',
                
                // System administration permissions (developer only)
                'manage_system_settings',
                'view_audit_logs',
                'developer_access',
                
                // User management (full access)
                'manage_all_users',
                
                // Station management
                'manage_stations'
            ];
            break;
        case 'developer':
            // Developer: special system-level access for developer support and maintenance
            $permissions = [
                'view_dashboard',
                'manage_system_settings',
                'view_audit_logs',
                'developer_access',
                'manage_all_users',
                'manage_stations'
            ];
            break;
            
        case 'admin':
            // Admin: Full operational access (all staff capabilities) + administrative oversight & reports
            $permissions = [
                // Basic access
                'view_dashboard',
                'view_calendar',

                // Transaction permissions (Full operational + oversight)
                'create_transactions',
                'view_transactions',
                'approve_transactions',
                'handle_approvals',

                // Job order permissions
                'create_job_orders',
                'manage_job_orders',

                // Fuel management (Encoding + oversight)
                'encode_fuel',
                'manage_fuel',
                'view_fuel_variance',

                // Inventory permissions (Operational + oversight)
                'manage_inventory',
                'view_inventory',
                'manage_deliveries',
                'manage_purchase_orders',

                // Customer management permissions
                'manage_customers',
                'manage_customers_basic',

                // Staff & User management
                'manage_staff',
                'manage_staff_oversight',
                'assign_shifts',
                'manage_shifts',
                'manage_stations',
                'manage_all_users',
                'manage_users_station',
                'view_team_reports',

                // Reports (Personal, operational, financial, audit, all)
                'view_personal_reports',
                'view_operational_reports',
                'view_financial_reports',
                'view_nationwide_reports',
                'view_all_reports',
                'view_audit_logs',
                'view_audit_logs_station',
                'export_data',
                'audit_oversight',
                'manage_pricing',
                'manage_pricing_station',
                'manage_system_settings',
                'unlock_records'
            ];
            break;
            
        case 'manager':
            // Manager: Approval, oversight, and managerial reports
            $permissions = [
                'manage_staff_oversight',
                'assign_shifts',
                'view_team_reports',
                // Basic access
                'view_dashboard',
                'view_calendar',

                // Transaction approvals and viewing
                'view_transactions',
                'approve_transactions',
                'handle_approvals',

                // Fuel management
                'manage_fuel',

                // Inventory oversight
                'view_inventory',
                'manage_inventory',

                // Staff management
                'manage_staff',

                // User management (station-scoped, staff only enforced in users.php)
                'manage_users_station',

                // Job order management
                'manage_job_orders',

                // Managerial reports (operational oversight)
                'view_operational_reports',
                
                // Purchase orders and deliveries management
                'manage_purchase_orders',
                'manage_deliveries',

                // Audit trail — station-scoped (staff + manager actions only)
                'view_audit_logs_station',
            ];
            break;
            
        case 'staff':
                // Staff: Operational access (excluding system admin and financial reports)
                $permissions = [
                    'manage_staff_oversight',
                // Basic access
                'view_dashboard',
                'view_calendar',
                
                // Transaction permissions
                'create_transactions',
                
                // Job order permissions  
                'create_job_orders', 'manage_job_orders',
                
                // Fuel management permissions
                'encode_fuel', 'manage_fuel',
                
                // Inventory permissions
                'manage_inventory', 'view_inventory',
                
                // Customer management permissions
                'manage_customers', 'manage_customers_basic',
                
                // Staff management permissions
                'manage_staff', 'manage_shifts',
                
                // Report permissions (staff-related only)
                'view_personal_reports', 'view_operational_reports',
                
                // User management permissions (station only)
                'manage_users_station',
                
                // Additional operational permissions
                'export_data', 'audit_oversight', 'manage_pricing', 'manage_pricing_station',
                'unlock_records', 'view_audit_logs_station'
            ];
            break;
    }
    
    return $permissions;
}

/**
 * Check if user can access a specific menu item
 */
function user_can_access_menu($menu_id, $user_role, $required_permissions = []) {
    $role = role_key($user_role);
    if ($role === 'superadmin' || $role === 'admin') {
        return true;
    }

    $user_permissions = get_user_permissions($user_role);
    
    // If no specific permissions required, allow access
    if (empty($required_permissions)) {
        return true;
    }
    
    // Check if user has any of the required permissions
    foreach ($required_permissions as $permission) {
        if (in_array($permission, $user_permissions)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Check if user has station-specific access vs global access
 */
function is_station_specific_role($role) {
    $role = role_key($role);
    return in_array($role, ['admin', 'manager', 'staff']);
}

/**
 * Check if user can access global (all stations) data
 */
function has_global_access($role) {
    $role = role_key($role);
    return $role === 'superadmin';
}

// Individual capability functions for specific features

function can_manage_users($role) {
    return user_can_access_menu('users', $role, ['manage_all_users', 'manage_users_station']);
}

function can_approve_transactions($role) {
    return user_can_access_menu('approvals', $role, ['approve_transactions']);
}

function can_manage_inventory($role) {
    return user_can_access_menu('inventory', $role, ['manage_inventory', 'view_inventory', 'receive_inventory']);
}

function can_manage_fuel($role) {
    return user_can_access_menu('fuel', $role, ['manage_fuel', 'encode_fuel']);
}

function can_view_reports($role, $report_type = 'basic') {
    $permissions = [];
    switch($report_type) {
        case 'financial':
            $permissions = ['view_financial_reports', 'view_all_reports'];
            break;
        case 'operational':
            $permissions = ['view_operational_reports', 'view_all_reports'];
            break;
        case 'audit':
            $permissions = ['view_audit_logs', 'view_audit_logs_station'];
            break;
        default:
            $permissions = ['view_personal_reports', 'view_operational_reports', 'view_financial_reports', 'view_all_reports'];
    }
    
    return user_can_access_menu('reports', $role, $permissions);
}

function can_manage_job_orders($role) {
    return user_can_access_menu('job_orders', $role, ['manage_job_orders', 'create_job_orders']);
}

function can_manage_customers($role) {
    return user_can_access_menu('customers', $role, ['manage_customers', 'manage_customers_basic']);
}
function is_manager_or_above(){
  $u = current_user();
  if(!$u) return false;
  $role = role_key($u['role'] ?? 'staff');
  return in_array($role, ['manager', 'admin', 'superadmin']);
}

/**
 * Require Manager or Super Admin access
 * Throws 403 if user is not Manager or above
 */
function require_manager_or_above(){
  require_login();
  if(!is_manager_or_above()){
    json_response(['ok'=>false,'error'=>'Manager privileges required'], 403);
  }
}

/**
 * Check if Manager can finalize a record
 * Manager can finalize if not already finalized by someone else
 */
function can_manager_finalize(PDO $pdo, string $table, int $record_id): bool {
  try {
    $stmt = $pdo->prepare("SELECT is_locked, finalized_by FROM {$table} WHERE id = ?");
    $stmt->execute([$record_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$record) return false;
    
    // Can finalize if not locked
    return $record['is_locked'] == 0;
  } catch(Exception $e) {
    return false;
  }
}

function role_rank($role){
  $role = normalize_role($role);
  if($role === 'Super Admin') return 3;
  if($role === 'Admin') return 2;
  if($role === 'Manager') return 2;
  return 1; // Staff
}

function current_user(){
  if(session_status() !== PHP_SESSION_ACTIVE) session_start();
  return $_SESSION['user'] ?? null;
}

function has_role_at_least($minRole){
  $u = current_user();
  $ur = $u ? role_rank($u['role'] ?? 'Staff') : 0;
  return $ur >= role_rank($minRole);
}

function require_role($minRole){
  require_login();
  if(!has_role_at_least($minRole)){
    json_response(['ok'=>false,'error'=>'Forbidden'], 403);
  }
}

function user_station_id(){
  $u = current_user();
  if(!$u) return null;

  // Refresh station_id from database to ensure latest data after migrations
  // This ensures users who were migrated to a new station see the correct station
  try {
    global $pdo;
    if($pdo && isset($u['id'])) {
      $stmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ? LIMIT 1");
      $stmt->execute([$u['id']]);
      $result = $stmt->fetch(PDO::FETCH_ASSOC);
      if($result && isset($result['station_id'])) {
        // Update session with latest station_id
        $_SESSION['user']['station_id'] = $result['station_id'];
        return $result['station_id'];
      }
    }
  } catch(Exception $e) {
    // Fall back to session value if DB query fails
  }

  return $u['station_id'] ?? null;
}

/**
 * Get the name of the current user's assigned station.
 * Returns the station name string, or an empty string if not found.
 */
function user_station_name(): string {
  global $pdo;
  $station_id = user_station_id();
  if (!$station_id || !isset($pdo)) return '';
  try {
    $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
    $stmt->execute([$station_id]);
    return (string)($stmt->fetchColumn() ?: '');
  } catch (Exception $e) {
    return '';
  }
}

/**
 * Format merchandise unit of measure to official standardized labels.
 * Standard UOM list:
 *   Piece (pc), Bottle, Can, Tube, Pack, Box, Roll, Set, Gallon,
 *   Liter, Milliliter (mL), Kilogram (kg), Gram (g),
 *   Drum, Pail, Sack, Carton, Case, Pair
 */
function format_merch_unit($unit) {
    $u = strtolower(trim((string)$unit));
    // Piece
    if (in_array($u, ['pcs','pc','piece','pieces'])) return 'Piece (pc)';
    // Bottle
    if (in_array($u, ['bot','btl','bottle','bottles','jar','jars'])) return 'Bottle';
    // Can
    if (in_array($u, ['can','cnt','cans'])) return 'Can';
    // Tube
    if (in_array($u, ['tube','tubes','tbe'])) return 'Tube';
    // Pack
    if (in_array($u, ['pack','packs','pkg','pkgs','packet','packets','sachet','sachets','sct','bag','bags'])) return 'Pack';
    // Box
    if (in_array($u, ['box','bx','boxes'])) return 'Box';
    // Roll
    if (in_array($u, ['roll','rolls','rll'])) return 'Roll';
    // Set
    if (in_array($u, ['set','sets'])) return 'Set';
    // Gallon
    if (in_array($u, ['gal','gallon','gallons'])) return 'Gallon';
    // Liter
    if (in_array($u, ['ltr','litre','litres','liter','liters','l'])) return 'Liter';
    // Milliliter
    if (in_array($u, ['ml','milliliter','milliliters','millilitre','millilitres'])) return 'Milliliter (mL)';
    // Kilogram
    if (in_array($u, ['kg','kilogram','kilograms'])) return 'Kilogram (kg)';
    // Gram
    if (in_array($u, ['g','gram','grams'])) return 'Gram (g)';
    // Drum
    if (in_array($u, ['drum','drums'])) return 'Drum';
    // Pail
    if (in_array($u, ['pail','pails','tub','tubs'])) return 'Pail';
    // Sack
    if (in_array($u, ['sack','sacks'])) return 'Sack';
    // Carton
    if (in_array($u, ['carton','cartons','ctn'])) return 'Carton';
    // Case
    if (in_array($u, ['case','cases'])) return 'Case';
    // Pair
    if (in_array($u, ['pair','pairs','pr'])) return 'Pair';
    // Empty → default to Piece
    if ($u === '') return 'Piece (pc)';
    // Fallback: capitalize first letter
    return ucfirst($unit);
}

if (!function_exists('get_product_brand')) {
function get_product_brand($product_name, $category = '', $description = '') {
    $name = strtolower(trim((string)$product_name));
    $cat = strtolower(trim((string)$category));
    $desc = strtolower(trim((string)$description));
    $text = trim($name . ' ' . $cat . ' ' . $desc);

    if ($name === '') return 'Generic';

    $exact_contains = [
        'coke/ sprite / royal' => 'Coke/Sprite/Royal',
        'coke' => 'Coca-Cola',
        'sprite' => 'Sprite',
        'royal' => 'Royal',
        'gatorade' => 'Gatorade',
        'mineral water' => 'Mineral Water',
        'breadstix' => 'Breadstix',
        'butter coconut' => 'Butter Coconut',
        'cheese ring' => 'Cheese Ring',
        'chippy' => 'Chippy',
        'chiz curls' => 'Chiz Curls',
        'choco mucho' => 'Choco Mucho',
        'clover' => 'Clover',
        'cracklings' => 'Cracklings',
        'fita' => 'Fita',
        'jjampong' => 'Lucky Me',
        'sotanghon' => 'Lucky Me',
        'nova' => 'Nova',
        'oishi' => 'Oishi',
        'piattos' => 'Piattos',
        'potato fries' => 'Potato Fries',
        'presto' => 'Presto',
        'roller coaster' => 'Roller Coaster',
        'skyflakes' => 'Skyflakes',
        'sweetcorn' => 'Sweet Corn',
        'vic' => 'VIC',
        'sakura' => 'Sakura',
        'nomis' => 'Nomis',
        'fleetmax' => 'Fleetmax',
        'petromate' => 'Petron',
        'petron' => 'Petron',
        'blaze' => 'Blaze',
        'ultron' => 'Ultron',
        'sprint' => 'Sprint',
        'rev-x' => 'Rev-X',
        'rev x' => 'Rev-X',
        'revx' => 'Rev-X',
        'trekker' => 'Rev-X',
        'enduro' => 'Rev-X',
        'all terrain' => 'Rev-X',
        'powerburn' => 'Petron 2T',
        'autolube' => 'Petron 2T',
        'atf' => 'Petron ATF',
        'gep' => 'Petron GEP',
        'hydrotur' => 'Petron Hydrotur',
        'mp grease' => 'Petron MP Grease',
    ];

    foreach ($exact_contains as $needle => $brand) {
        if (str_contains($name, $needle)) return $brand;
    }

    if (preg_match('/\bfes[-\s]?\d+/i', $name) || preg_match('/\bffs[-\s]?\d+/i', $name)) {
        return 'Fleetmax';
    }
    if (preg_match('/\bhd\s*\d+/i', $name)) return 'Petron HD';
    if (preg_match('/\bmo\s*\d+/i', $name)) return 'Petron MO';
    if (str_contains($text, 'oil/fuel filter') || str_contains($name, 'oil filter') || str_contains($name, 'fuel filter')) {
        return 'Generic Filter';
    }
    if (str_contains($text, 'oil/lube/grease')) {
        return 'Petron';
    }

    $words = preg_split('/\s+/', trim((string)$product_name));
    $first = preg_replace('/[^A-Za-z0-9\-]/', '', $words[0] ?? '');
    return strlen($first) > 1 ? ucfirst(strtolower($first)) : 'Generic';
}
}

function format_product_unit_display($unit, $product_name = '', $category = '', $description = '') {
    $u = strtolower(trim((string)$unit));
    $name = strtolower(trim((string)$product_name));
    $cat = strtolower(trim((string)$category));
    $desc = strtolower(trim((string)$description));
    $text = trim($name . ' ' . $cat . ' ' . $desc);

    $standard = [
        'pc' => 'Piece (pc)', 'pcs' => 'Piece (pc)', 'piece' => 'Piece (pc)', 'pieces' => 'Piece (pc)',
        'btl' => 'Bottle', 'bot' => 'Bottle', 'bottle' => 'Bottle', 'bottles' => 'Bottle',
        'can' => 'Can', 'cans' => 'Can',
        'pack' => 'Pack', 'packs' => 'Pack', 'packet' => 'Pack', 'packets' => 'Pack',
        'box' => 'Box', 'boxes' => 'Box',
        'carton' => 'Carton', 'cartons' => 'Carton', 'ctn' => 'Carton',
        'case' => 'Case', 'cases' => 'Case',
        'bag' => 'Bag', 'bags' => 'Bag',
        'sachet' => 'Sachet', 'sachets' => 'Sachet',
        'cup' => 'Cup', 'cups' => 'Cup',
        'stick' => 'Stick', 'sticks' => 'Stick',
        'tube' => 'Tube', 'tubes' => 'Tube',
        'roll' => 'Roll', 'rolls' => 'Roll',
        'l' => 'Liter (L)', 'ltr' => 'Liter (L)', 'liter' => 'Liter (L)', 'liters' => 'Liter (L)', 'litre' => 'Liter (L)', 'litres' => 'Liter (L)',
        'ml' => 'Milliliter (mL)', 'milliliter' => 'Milliliter (mL)', 'milliliters' => 'Milliliter (mL)', 'millilitre' => 'Milliliter (mL)', 'millilitres' => 'Milliliter (mL)',
        'kg' => 'Kilogram (kg)', 'kilogram' => 'Kilogram (kg)', 'kilograms' => 'Kilogram (kg)',
        'g' => 'Gram (g)', 'gram' => 'Gram (g)', 'grams' => 'Gram (g)',
        'pair' => 'Pair', 'pairs' => 'Pair', 'pr' => 'Pair',
        'set' => 'Set', 'sets' => 'Set',
        'dozen' => 'Dozen', 'dz' => 'Dozen',
        'pail' => 'Pail', 'pails' => 'Pail',
    ];

    if ($u !== '' && !in_array($u, ['pc', 'pcs', 'piece', 'pieces'], true)) {
        return $standard[$u] ?? ucfirst((string)$unit);
    }

    if (preg_match('/\bp\s*\/\s*\d+\b/i', $name)) return 'Pail';
    if (preg_match('/\b\d+\s*\/\s*\d+\b/i', $name)) return 'Case';

    if (preg_match('/\b(dozen|dz)\b/i', $text)) return 'Dozen';
    if (preg_match('/\b(set|kit)\b/i', $text)) return 'Set';
    if (preg_match('/\b(pair|pairs)\b/i', $text)) return 'Pair';
    if (preg_match('/\b(box|carton|case|bag|sachet|cup|stick|tube|roll|pail)\b/i', $text, $m)) {
        return $standard[strtolower($m[1])] ?? ucfirst($m[1]);
    }
    if (preg_match('/\b(can|canned)\b/i', $text)) return 'Can';

    if (preg_match('/\b(coke|sprite|royal|gatorade|mineral water|water)\b/i', $text)) return 'Bottle';
    if (preg_match('/\b(chippy|piattos|nova|oishi|clover|sweetcorn|cracklings|cheese ring|chiz curls|roller coaster|potato fries)\b/i', $text)) return 'Bag';
    if (preg_match('/\b(breadstix|butter coconut|choco mucho|fita|presto|skyflakes|jjampong|sotanghon|snack|cookies|slugs|singles)\b/i', $text)) return 'Pack';
    if (preg_match('/\b(filter|fleetmax|sakura|vic)\b/i', $text)) return 'Piece (pc)';

    if (preg_match('/\b\d+(?:\.\d+)?\s*kg\b/i', $name)) return 'Kilogram (kg)';
    if (preg_match('/\b\d+(?:\.\d+)?\s*g\b/i', $name)) return 'Gram (g)';
    if (preg_match('/\b\d+(?:\.\d+)?\s*ml\b/i', $name)) return 'Milliliter (mL)';
    if (preg_match('/\b\d+(?:\.\d+)?\s*l\b/i', $name)) return 'Liter (L)';

    return 'Piece (pc)';
}

function format_product_category_display($category, $product_name = '', $description = '') {
    $cat = trim((string)$category);
    $cat_l = strtolower($cat);
    $name_l = strtolower(trim((string)$product_name));
    $desc_l = strtolower(trim((string)$description));
    $text = trim($name_l . ' ' . $desc_l);

    if (preg_match('/\b(vic)\b/i', $name_l) && preg_match('/filter/i', $name_l)) {
        return 'VIC Filters';
    }

    if (
        preg_match('/\b(filter|fleetmax|sakura|nomis)\b/i', $text) ||
        str_contains($desc_l, 'oil/fuel filter')
    ) {
        return 'Filters';
    }

    if (
        str_contains($desc_l, 'oil/lube/grease') ||
        preg_match('/\b(2t|atf|gep|hd|mo|mp grease|grease|ultron|sprint|blaze|rev-x|revx|trekker|enduro|hydrotur|powerburn|terrain)\b/i', $text)
    ) {
        return 'Oils/Lubes/Grease';
    }

    if (preg_match('/\b(coke|sprite|royal|gatorade|mineral water|bottled water)\b/i', $name_l)) {
        return 'Drinks/Food';
    }

    if (preg_match('/\b(breadstix|butter coconut|cheese ring|chippy|chiz curls|choco mucho|clover|cracklings|fita|jjampong|sotanghon|nova|oishi|piattos|potato fries|presto|roller coaster|skyflakes|sweetcorn|cookies|slugs|singles)\b/i', $name_l)) {
        return 'Snacks';
    }

    if (preg_match('/\b(wiper|mat|air freshener|accessory|accessories|tool|car care)\b/i', $text)) {
        return 'Car Accessories';
    }

    $valid = [
        'oils/lubes/grease' => 'Oils/Lubes/Grease',
        'filters' => 'Filters',
        'vic filters' => 'VIC Filters',
        'drinks/food' => 'Drinks/Food',
        'snacks' => 'Snacks',
        'car accessories' => 'Car Accessories',
        'merchandise' => 'Merchandise',
        'others' => 'Others',
    ];

    return $valid[$cat_l] ?? ($cat !== '' ? $cat : 'Others');
}

function ensure_product_category_id(PDO $pdo, string $category): int {
    $category = trim($category);
    if ($category === '') {
        $category = 'Others';
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM product_categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$category]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        $stmt = $pdo->prepare("INSERT INTO product_categories (name, created_at) VALUES (?, NOW())");
        $stmt->execute([$category]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        return 0;
    }
}

function normalize_merchandise_catalog_rows(array $rows): array {
    $normalized = [];
    $seen_keys = [];

    foreach ($rows as $row) {
        $name = trim((string)($row['product_name'] ?? $row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        // Deduplication key by ID and SKU/brand to preserve distinct products
        $row_id = (int)($row['id'] ?? 0);
        $norm_name_key = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $name)));
        $sku_key = strtolower(trim((string)($row['sku'] ?? '')));
        $brand_key = strtolower(trim((string)($row['brand'] ?? '')));
        $dedup_key = $row_id > 0 ? ('id_' . $row_id) : ('item_' . $norm_name_key . '_' . $sku_key . '_' . $brand_key);

        if (isset($seen_keys[$dedup_key])) {
            continue; // Skip exact duplicate product
        }
        $seen_keys[$dedup_key] = true;

        $description = (string)($row['description'] ?? '');
        $category = format_product_category_display(
            $row['category_name'] ?? $row['category'] ?? '',
            $name,
            $description
        );
        $unit = format_product_unit_display(
            $row['unit'] ?? 'pcs',
            $name,
            $category,
            $description
        );
        $stock = (float)($row['stock_quantity'] ?? $row['stock_level'] ?? $row['stock'] ?? 0);
        $capacity = (float)($row['capacity'] ?? 0);
        if ($capacity <= 0) {
            $capacity = 480;
        }

        $row['product_name'] = $name;
        $row['name'] = $name;
        $row['description'] = $description;
        $row['category'] = $category;
        $row['category_name'] = $category;
        $row['brand'] = get_product_brand($name, $category, $description);
        $row['unit'] = $unit;
        $row['supplier'] = 'Petron Corporation';
        $row['unit_cost'] = (float)($row['unit_cost'] ?? $row['cost'] ?? 0);
        $row['cost'] = $row['unit_cost'];
        $row['unit_price'] = (float)($row['unit_price'] ?? $row['price'] ?? 0);
        $row['price'] = $row['unit_price'];
        $row['stock_quantity'] = $stock;
        $row['stock_level'] = $stock;
        $row['stock'] = $stock;
        $row['capacity'] = $capacity;
        $row['reorder_level'] = (float)($row['reorder_level'] ?? 24);
        $row['critical_level'] = (float)($row['critical_level'] ?? 10);
        $row['physical_count'] = isset($row['physical_count']) && $row['physical_count'] !== null ? (float)$row['physical_count'] : null;
        $row['variance'] = isset($row['variance']) && $row['variance'] !== null ? (float)$row['variance'] : 0;
        $row['status'] = strtolower(trim((string)($row['status'] ?? 'active'))) ?: 'active';
        $row['last_updated'] = $row['last_updated'] ?? $row['updated_at'] ?? $row['created_at'] ?? '';
        $row['pending_cost'] = isset($row['pending_cost']) ? (float)$row['pending_cost'] : null;
        $row['pending_price'] = isset($row['pending_price']) ? (float)$row['pending_price'] : null;

        $normalized[] = $row;
    }

    usort($normalized, function ($a, $b) {
        return [$a['category_name'], $a['product_name']] <=> [$b['category_name'], $b['product_name']];
    });

    return $normalized;
}

function load_merchandise_pricing_catalog(PDO $pdo, int $station_id): array {
    $rows = [];

    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(ip.id, p.id, si.product_id) AS id,
                   COALESCE(ip.product_name, p.name, 'Unknown Product') AS product_name,
                   COALESCE(ip.product_name, p.name, 'Unknown Product') AS name,
                   COALESCE(ip.category, pc.name, 'Merchandise') AS category,
                   COALESCE(ip.category, pc.name, 'Merchandise') AS category_name,
                   COALESCE(p.description, '') AS description,
                   COALESCE(ip.sku, p.sku, CONCAT('P', LPAD(si.product_id, 4, '0'))) AS sku,
                   ip.barcode AS barcode,
                   COALESCE(ip.brand, p.brand, 'Petron Corporation') AS brand,
                   COALESCE(si.unit, ip.size, p.unit, 'pcs') AS unit,
                   COALESCE(si.cost, ip.unit_cost, p.cost, 0) AS unit_cost,
                   COALESCE(si.price, ip.unit_price, p.price, 0) AS unit_price,
                   COALESCE(si.status, ip.status, p.status, 'active') AS status,
                   COALESCE(si.stock_level, 0) AS stock_quantity,
                   COALESCE(si.capacity, ip.max_stock, p.capacity, 480) AS capacity,
                   COALESCE(si.reorder_level, ip.min_stock, p.min_stock_level, 24) AS reorder_level,
                   COALESCE(si.critical_level, 10) AS critical_level,
                   si.physical_count,
                   COALESCE(si.variance, 0) AS variance,
                   COALESCE(si.last_updated, NOW()) AS last_updated,
                   'Petron Corporation' AS supplier,
                   COALESCE(pa.new_cost, pa.new_value) AS pending_cost,
                   COALESCE(pa.new_price, pa.new_value) AS pending_price,
                   pa.status AS approval_status,
                   pa.id AS approval_id,
                   COALESCE(si.expiration_date, ip.expiration_date) AS expiration_date
            FROM station_inventory si
            LEFT JOIN inventory_products ip ON ip.id = si.product_id
            LEFT JOIN products p ON p.id = si.product_id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            LEFT JOIN pending_price_approvals pa
                   ON pa.product_id = si.product_id
                  AND pa.product_type = 'merchandise'
                  AND pa.status = 'pending'
                  AND pa.station_id = ?
            WHERE si.station_id = ?
              AND (LOWER(COALESCE(ip.category, pc.name, '')) NOT IN ('fuel', 'fuel products', 'services', 'service') OR (ip.category IS NULL AND pc.name IS NULL))
            ORDER BY category_name, product_name
        ");
        $stmt->execute([$station_id, $station_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }

    return normalize_merchandise_catalog_rows($rows);
}

function find_merchandise_pricing_item(PDO $pdo, int $station_id, int $product_id): ?array {
    foreach (load_merchandise_pricing_catalog($pdo, $station_id) as $item) {
        if ((int)($item['id'] ?? 0) === $product_id) {
            return $item;
        }
    }
    return null;
}

function today_key(){
  return date('Y-m-d');
}

function money($n){
  return number_format((float)$n, 2, '.', ',');
}

/**
 * Render a "no station assigned" page and exit.
 * Call this after require_login() when station_id is 0/null for a station-scoped role.
 */
function render_no_station_page(string $back_url = 'admin_dashboard.php'): void {
    if (!headers_sent()) {
        // Include header only if not already included
        $header = __DIR__ . '/../partials/header.php';
        if (file_exists($header)) {
            require_once $header;
        }
    }
    echo '
    <div style="max-width:520px;margin:80px auto;text-align:center;padding:40px 36px;
                background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);">
        <i class="fas fa-map-marker-alt" style="font-size:3rem;color:#CC0000;margin-bottom:16px;display:block;"></i>
        <h2 style="color:#00264D;margin:0 0 10px;font-size:1.3rem;">No Station Assigned</h2>
        <p style="color:#666;font-size:.93rem;line-height:1.6;margin:0 0 24px;">
            Your account has not been assigned to a station yet.<br>
            Please contact your <strong>SuperAdmin</strong> to assign you to a station before you can access this page.
        </p>
        <a href="' . htmlspecialchars($back_url) . '"
           style="display:inline-flex;align-items:center;gap:8px;background:#00264D;color:#fff;
                  padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;">
            <i class="fas fa-arrow-left"></i> Go Back
        </a>
    </div>';
    $footer = __DIR__ . '/../partials/footer.php';
    if (file_exists($footer)) {
        require_once $footer;
    }
    exit;
}

/**
 * Check whether a station has been configured with at least one fuel type (with a set price)
 * or at least one product. Returns false if station is empty/unconfigured.
 */
function is_station_configured(PDO $pdo, int $station_id): bool {
    if ($station_id <= 0) return false;
    try {
        // Check for any fuel inventory entry with a real price set
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM fuel_inventory WHERE station_id = ? AND price_per_liter > 0"
        );
        $stmt->execute([$station_id]);
        if ((int)$stmt->fetchColumn() > 0) return true;

        // Check for any products assigned to this station
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM products WHERE station_id = ? AND status != 'deleted'"
        );
        $stmt->execute([$station_id]);
        if ((int)$stmt->fetchColumn() > 0) return true;

        return false;
    } catch (Exception $e) {
        return true; // Fail open: don't block if DB error
    }
}

/**
 * Render a "Station Setup Required" page and exit.
 * Call this after require_login() when the station has no products/fuel configured.
 */
function render_station_setup_required_page(string $back_url = 'admin_dashboard.php'): void {
    if (!headers_sent()) {
        $header = __DIR__ . '/../partials/header.php';
        if (file_exists($header)) {
            require_once $header;
        }
    }
    echo '
    <div style="max-width:580px;margin:80px auto;text-align:center;padding:40px 36px;
                background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);">
        <div style="width:72px;height:72px;background:#FFF3CD;border-radius:50%;display:flex;
                    align-items:center;justify-content:center;margin:0 auto 20px;">
            <i class="fas fa-tools" style="font-size:2rem;color:#856404;"></i>
        </div>
        <h2 style="color:#00264D;margin:0 0 10px;font-size:1.4rem;">Station Setup Required</h2>
        <p style="color:#555;font-size:.95rem;line-height:1.7;margin:0 0 20px;">
            This station has not been configured yet.<br>
            Please set up your <strong>fuel types</strong> and <strong>products</strong>
            before performing transactions.
        </p>
        <div style="background:#FFF3CD;border:1px solid #FFEAA7;border-radius:10px;
                    padding:16px 20px;margin-bottom:28px;text-align:left;">
            <p style="margin:0 0 8px;font-weight:600;color:#856404;font-size:.92rem;">
                <i class="fas fa-list-check"></i>&nbsp; Setup Checklist
            </p>
            <ul style="margin:0;padding-left:18px;color:#666;font-size:.88rem;line-height:1.8;">
                <li>Add fuel types &amp; set prices in <strong>Fuel Inventory Setup</strong></li>
                <li>Add merchandise products in <strong>Product Management</strong></li>
                <li>Configure fuel pumps in <strong>Pump Setup</strong></li>
            </ul>
        </div>
        <a href="' . htmlspecialchars($back_url) . '"
           style="display:inline-flex;align-items:center;gap:8px;background:#00264D;color:#fff;
                  padding:10px 28px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;">
            <i class="fas fa-arrow-left"></i> Go to Dashboard
        </a>
    </div>';
    $footer = __DIR__ . '/../partials/footer.php';
    if (file_exists($footer)) {
        require_once $footer;
    }
    exit;
}

function log_activity($pdo, $user_id, $action, $details) {
  try {
    if(!($pdo instanceof PDO)) return;
    $details = function_exists('clean_mojibake') ? clean_mojibake((string)$details) : (string)$details;
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
  } catch (Exception $e) { /* Fail silently to not disrupt flow */ }
}

function darken_color($hex, $percent = 20) {
    $hex = ltrim($hex, '#');
    $r = (int)hexdec(substr($hex, 0, 2)) * (100 - $percent) / 100;
    $g = (int)hexdec(substr($hex, 2, 2)) * (100 - $percent) / 100;
    $b = (int)hexdec(substr($hex, 4, 2)) * (100 - $percent) / 100;
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

function get_theme_preview_css($config) {
    $primary = $config['primary_color'] ?? '#002F6C';
    return "/* Theme Preview Override */ .theme-preview { --primary-color: {$primary}; --gradient-primary: linear-gradient(135deg, {$primary}, " . darken_color($primary, 20) . "); }";
}
function is_shift_finalized(PDO $pdo, int $station_id, string $date, string $shift): bool {
  try {
    $stmt = $pdo->prepare("SELECT status FROM shift_reports WHERE station_id=? AND report_date=? AND shift=? LIMIT 1");
    $stmt->execute([$station_id, $date, $shift]);
    $st = $stmt->fetchColumn();
    return ($st === 'finalized');
  } catch(Exception $e) {
    return false;
  }
}

// --- RBAC HELPERS ---
function rbac_is_backend_request(): bool {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  return (strpos($script, '/backend/') !== false) || (strpos($script, '\\backend\\') !== false);
}

function rbac_default_matrix(): array {
  // Default permissions if DB/JSON not available
  $modules = [
    'dashboard.view',
    'users.create_admin','users.approve_manager','users.reset_passwords','users.deactivate','rbac.manage',
    'inventory.receiving','inventory.stock','reconciliation.fuel_reports',
    'pos.process_sales','receivables.manage','shift_reports.view',
    'joborder.create','joborder.assign_mechanics','joborder.parts_tracking','joborder.billing',
    'audit.logins','audit.password_changes','audit.account_actions','audit.recon_approvals','audit.settings_changes',
    'security.rbac_enforcement','security.account_lockouts','security.password_policy','security.unauthorized_attempts',
    'reports.daily','reports.monthly','reports.receivables_aging','reports.fuel_variance','reports.nationwide'
  ];
  $all = array_fill_keys($modules, 1);
  return [
    'superadmin' => $all,
    'admin' => [
      'dashboard.view'=>1,'users.reset_passwords'=>1,'users.deactivate'=>1,
      'inventory.receiving'=>1,'inventory.stock'=>1,
      'reconciliation.fuel_reports'=>1,
      'pos.process_sales'=>1,'receivables.manage'=>1,'shift_reports.view'=>1,
      'joborder.create'=>1,'joborder.assign_mechanics'=>1,'joborder.parts_tracking'=>1,'joborder.billing'=>1,
      'audit.logins'=>1,'audit.password_changes'=>1,'audit.account_actions'=>1,'audit.recon_approvals'=>1,
      'reports.daily'=>1,'reports.monthly'=>1
    ],
    'manager' => [
      'dashboard.view'=>1,'inventory.stock'=>1,'reconciliation.fuel_reports'=>1,'shift_reports.view'=>1,'audit.logins'=>1,'reports.daily'=>1
    ],
    'mechanic' => [ 'dashboard.view'=>1,'joborder.parts_tracking'=>1 ],
    'staff' => [ 'dashboard.view'=>1,'pos.process_sales'=>1 ]
  ];
}

function rbac_permissions(): array {
  if(session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (!empty($_SESSION['rbac_cache']) && is_array($_SESSION['rbac_cache'])) {
    $ts = (int)($_SESSION['rbac_cache']['ts'] ?? 0);
    if ($ts > (time() - 60)) return $_SESSION['rbac_cache']['data'];
  }

  $matrix = [];
  // Try DB
  try {
    global $pdo; // available in most pages after db_connect.php
    if ($pdo) {
      $stmt = $pdo->query("SELECT role, permission, allowed FROM role_permissions");
      $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
      foreach ($rows as $r) {
        $role = strtolower(trim($r['role']));
        if (!isset($matrix[$role])) $matrix[$role] = [];
        if ((int)$r['allowed'] === 1) $matrix[$role][$r['permission']] = 1;
      }
    }
  } catch(Exception $e) { /* ignore */ }

  if (empty($matrix)) {
    // Try JSON fallback
    $json = read_json('permissions.json', []);
    if (!empty($json['data']) && is_array($json['data'])) {
      // Normalize keys to lowercase roles
      foreach ($json['data'] as $role => $perms) {
        $matrix[strtolower(trim($role))] = $perms;
      }
    }
  }

  if (empty($matrix)) {
    $matrix = rbac_default_matrix();
  }

  $_SESSION['rbac_cache'] = ['ts' => time(), 'data' => $matrix];
  return $matrix;
}

function can(string $permission): bool {
  $u = current_user();
  $role = strtolower(trim((string)($u['role'] ?? 'staff')));
  if ($role === 'superadmin' || $role === 'super admin') return true;
  $perm = rbac_permissions();
  return !empty($perm[$role][$permission]);
}

function rbac_forbidden_html(string $message = 'Access denied by RBAC policy.'){
  http_response_code(403);
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
     . '<title>403 Forbidden</title>'
     . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f8fa;margin:0;padding:40px;} .card{max-width:720px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.05);} .h{margin:0;padding:16px 20px;border-bottom:1px solid #e5e7eb;font-size:18px;color:#111827;} .b{padding:18px 20px;color:#4b5563;} .b a{color:#002F6C;text-decoration:none;border:1px solid #002F6C;padding:6px 10px;border-radius:4px;}</style></head><body>'
     . '<div class="card"><div class="h">403 Forbidden</div><div class="b">' . htmlspecialchars($message) . '<div style="margin-top:12px;"><a href="index.php">Go to Home</a></div></div></div>'
     . '</body></html>';
  exit;
}

function require_permission(string $permission){
  require_login();
  if (can($permission)) return; // allowed
  // Log and respond
  try {
    global $pdo; $u = current_user();
    if (isset($pdo) && $pdo) log_activity($pdo, $u['id'] ?? 0, 'RBAC Deny', "Denied '$permission' on " . ($_SERVER['REQUEST_URI'] ?? ''));
  } catch(Exception $e) { }

  if (rbac_is_backend_request()) {
    json_response(['ok'=>false, 'error'=>'Forbidden', 'permission'=>$permission], 403);
  }
  rbac_forbidden_html();
}

if (!function_exists('generateSecurePassword')) {
function generateSecurePassword(int $length = 12): string {
  // Allowed symbols: _ . - ! @ #
  $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  $lower   = 'abcdefghijklmnopqrstuvwxyz';
  $digits  = '0123456789';
  $symbols = '_.-!@#';
  $all     = $upper . $lower . $digits . $symbols;

  // Guarantee at least one of each required type
  $password  = $upper[random_int(0, strlen($upper) - 1)];
  $password .= $lower[random_int(0, strlen($lower) - 1)];
  $password .= $digits[random_int(0, strlen($digits) - 1)];
  $password .= $symbols[random_int(0, strlen($symbols) - 1)];

  // Fill remaining characters
  for ($i = 4; $i < $length; $i++) {
    $password .= $all[random_int(0, strlen($all) - 1)];
  }

  return str_shuffle($password);
}
}

/**
 * Create role-specific notifications for users
 * @param PDO $pdo Database connection
 * @param string $targetRole Target role (superadmin, admin, manager, staff)
 * @param string $type Notification type (success, warning, error, info)
 * @param string $title Notification title
 * @param string $message Notification message
 * @param int|null $specificUserId Optional specific user ID within the role
 * @return int Number of notifications created
 */
function create_role_notification($pdo, $targetRole, $type, $title, $message, $specificUserId = null, $redirect_url = '') {
  try {
    ensure_notifications_table($pdo);
    $sql = "INSERT INTO notifications (user_id, type, title, message, redirect_url) 
            SELECT u.id, ?, ?, ?, ? FROM users u 
            WHERE u.role = ? AND u.status = 'Active'";
    $params = [$type, $title, $message, $redirect_url, $targetRole];
    
    if ($specificUserId) {
      $sql .= " AND u.id = ?";
      $params[] = $specificUserId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
  } catch (Exception $e) {
    error_log("Failed to create role notification: " . $e->getMessage());
    return 0;
  }
}

function ensure_notifications_table(PDO $pdo): void {
  static $ready = false;
  if ($ready) return;

  $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    type         ENUM('success','warning','error','info') NOT NULL DEFAULT 'info',
    title        VARCHAR(255) NOT NULL,
    message      TEXT NULL,
    event_type   VARCHAR(80) NOT NULL DEFAULT 'general',
    severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    source_key   VARCHAR(200) NULL,
    redirect_url VARCHAR(500) NULL,
    status       ENUM('unread','read') NOT NULL DEFAULT 'unread',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at      TIMESTAMP NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $existing = [];
  $stmt = $pdo->query("SHOW COLUMNS FROM notifications");
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
    $existing[strtolower($col['Field'])] = true;
  }

  $columns = [
    'recipient_role' => "ALTER TABLE notifications ADD COLUMN recipient_role VARCHAR(30) NULL AFTER user_id",
    'type'         => "ALTER TABLE notifications ADD COLUMN type ENUM('success','warning','error','info') NOT NULL DEFAULT 'info' AFTER user_id",
    'title'        => "ALTER TABLE notifications ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT 'Notification' AFTER type",
    'message'      => "ALTER TABLE notifications ADD COLUMN message TEXT NULL AFTER title",
    'event_type'   => "ALTER TABLE notifications ADD COLUMN event_type VARCHAR(80) NOT NULL DEFAULT 'general' AFTER message",
    'severity'     => "ALTER TABLE notifications ADD COLUMN severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium' AFTER event_type",
    'source_key'   => "ALTER TABLE notifications ADD COLUMN source_key VARCHAR(200) NULL AFTER severity",
    'redirect_url' => "ALTER TABLE notifications ADD COLUMN redirect_url VARCHAR(500) NULL AFTER source_key",
    'status'       => "ALTER TABLE notifications ADD COLUMN status ENUM('unread','read') NOT NULL DEFAULT 'unread' AFTER redirect_url",
    'created_at'   => "ALTER TABLE notifications ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status",
    'read_at'      => "ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL AFTER created_at",
  ];
  foreach ($columns as $name => $sql) {
    if (empty($existing[$name])) {
      try { $pdo->exec($sql); } catch (Exception $e) {}
    }
  }

  $indexes = [];
  try {
    $stmt = $pdo->query("SHOW INDEX FROM notifications");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $idx) {
      $indexes[$idx['Key_name']] = true;
    }
  } catch (Exception $e) {}

  foreach ([
    'idx_notif_user_status' => [
      "ALTER TABLE notifications ADD INDEX idx_notif_user_status (user_id, status)",
      ['idx_notif_user_status', 'idx_user_status']
    ],
    'idx_notif_created_at' => [
      "ALTER TABLE notifications ADD INDEX idx_notif_created_at (created_at)",
      ['idx_notif_created_at', 'idx_created_at']
    ],
    'idx_notif_event_type' => [
      "ALTER TABLE notifications ADD INDEX idx_notif_event_type (event_type)",
      ['idx_notif_event_type', 'idx_event_type']
    ],
    'idx_notif_source_key' => [
      "ALTER TABLE notifications ADD INDEX idx_notif_source_key (source_key)",
      ['idx_notif_source_key', 'idx_source_key']
    ],
  ] as $name => [$sql, $aliases]) {
    $hasIndex = false;
    foreach ($aliases as $alias) {
      if (!empty($indexes[$alias])) {
        $hasIndex = true;
        break;
      }
    }
    if (!$hasIndex) {
      try { $pdo->exec($sql); } catch (Exception $e) {}
    }
  }

  $ready = true;
}

/**
 * Get role-specific notification types and messages
 * @param string $role User role
 * @return array Role-specific notification configurations
 */
function get_role_notification_types($role) {
  $notifications = [
    'superadmin' => [
      'system_alerts' => ['type' => 'error', 'title' => 'System Alert', 'message' => 'Critical system issue detected'],
      'security_breaches' => ['type' => 'error', 'title' => 'Security Alert', 'message' => 'Security breach detected'],
      'database_issues' => ['type' => 'error', 'title' => 'Database Alert', 'message' => 'Database connectivity issue'],
      'failed_logins' => ['type' => 'warning', 'title' => 'Failed Login', 'message' => 'Multiple failed login attempts detected'],
      'admin_actions' => ['type' => 'info', 'title' => 'Admin Activity', 'message' => 'Administrative action performed']
    ],
    'admin' => [
      'user_management' => ['type' => 'info', 'title' => 'User Management', 'message' => 'User account changes made'],
      'station_management' => ['type' => 'info', 'title' => 'Station Management', 'message' => 'Station configuration updated'],
      'reconciliation_required' => ['type' => 'warning', 'title' => 'Reconciliation Required', 'message' => 'Daily reconciliation pending'],
      'inventory_alerts' => ['type' => 'warning', 'title' => 'Inventory Alert', 'message' => 'Low stock levels detected']
    ],
    'manager' => [
      'staff_performance' => ['type' => 'info', 'title' => 'Staff Performance', 'message' => 'Staff performance metrics available'],
      'shift_management' => ['type' => 'info', 'title' => 'Shift Management', 'message' => 'Shift scheduling update'],
      'pump_readings' => ['type' => 'info', 'title' => 'Pump Readings', 'message' => 'Pump readings require approval'],
      'customer_issues' => ['type' => 'warning', 'title' => 'Customer Issue', 'message' => 'Customer complaint or issue reported'],
      'maintenance_required' => ['type' => 'warning', 'title' => 'Maintenance', 'message' => 'Equipment maintenance required']
    ],
    'staff' => [
      'task_assignments' => ['type' => 'info', 'title' => 'Task Assignment', 'message' => 'New task assigned to you'],
      'shift_reminders' => ['type' => 'info', 'title' => 'Shift Reminder', 'message' => 'Upcoming shift reminder'],
      'training_required' => ['type' => 'info', 'title' => 'Training Required', 'message' => 'Training module needs completion'],
      'schedule_changes' => ['type' => 'info', 'title' => 'Schedule Change', 'message' => 'Your work schedule has been updated'],
      'performance_feedback' => ['type' => 'info', 'title' => 'Performance Feedback', 'message' => 'New performance feedback available']
    ]
  ];
  
  return $notifications[$role] ?? [];
}

// ── Audit Trail Helper ────────────────────────────────────────────────────────
// Call this from any page/action to write a row into audit_logs.
// $pdo must be available in the calling scope (global or passed in).
function write_audit_log($pdo, $action_type, $action_details, $entity_type = null, $entity_id = null, $log_type = 'system', $status = 'Success', $station_id = null) {
    try {
        if (!$pdo) return;
        $user_id = null;
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user']['id'])) {
            $user_id = (int)$_SESSION['user']['id'];
        }
        if ($station_id === null && function_exists('user_station_id')) {
            $station_id = user_station_id();
        }
        $ip = $_SERVER['HTTP_CLIENT_IP']
           ?? $_SERVER['HTTP_X_FORWARDED_FOR']
           ?? $_SERVER['REMOTE_ADDR']
           ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        $has_al_st = false;
        try {
            $c_stmt = $pdo->query("SHOW COLUMNS FROM audit_logs LIKE 'station_id'");
            $has_al_st = (bool)($c_stmt ? $c_stmt->fetch() : false);
        } catch (Exception $e) {}

        if ($has_al_st) {
            $pdo->prepare("INSERT INTO audit_logs
                (user_id, station_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$user_id, $station_id ?: null, $log_type, $action_type, $action_details, $entity_type, $entity_id, $status, $ip, $ua]);
        } else {
            $pdo->prepare("INSERT INTO audit_logs
                (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$user_id, $log_type, $action_type, $action_details, $entity_type, $entity_id, $status, $ip, $ua]);
        }
    } catch (Exception $e) { /* silent — never block the main action */ }
}

/**
 * FIFO-based stock level deduction from merchandise batches and inventory
 */
function fifo_deduct_stock(PDO $pdo, int $station_id, $product_id_or_name, float $qty): void {
    if ($qty <= 0) return;
    // NOTE: station_inventory and inventory_products stock levels are managed
    // exclusively by record_inventory_movement() using absolute SET values.
    // fifo_deduct_stock() only drains the merchandise_batches FIFO queue.

    $product_id = 0;
    if (is_numeric($product_id_or_name) && (int)$product_id_or_name > 0) {
        $product_id = (int)$product_id_or_name;
    } else {
        // Look up by product_name
        try {
            $stmt = $pdo->prepare("SELECT id FROM inventory_products WHERE product_name = ? LIMIT 1");
            $stmt->execute([$product_id_or_name]);
            $product_id = (int)$stmt->fetchColumn();
        } catch (Exception $e) {}
    }

    if ($product_id <= 0) {
        return;
    }


    // Deduct from merchandise_batches using FIFO/LIFO based on dynamic config
    try {
        $fifo_enabled = get_module_setting('inventory', 'fifo_enabled', true);
        $order = $fifo_enabled ? "date_received ASC, id ASC" : "date_received DESC, id DESC";
        
        $qty_needed = $qty;
        $batchesStmt = $pdo->prepare("
            SELECT id, remaining_qty 
            FROM merchandise_batches 
            WHERE product_id = ? AND station_id = ? AND status = 'Active' AND remaining_qty > 0 
            ORDER BY {$order}
        ");
        $batchesStmt->execute([$product_id, $station_id]);
        $batches = $batchesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($batches as $batch) {
            if ($qty_needed <= 0) break;
            $batch_id = $batch['id'];
            $remaining = (float)$batch['remaining_qty'];

            if ($remaining > $qty_needed) {
                $new_remaining = $remaining - $qty_needed;
                $pdo->prepare("UPDATE merchandise_batches SET remaining_qty = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$new_remaining, $batch_id]);
                $qty_needed = 0;
            } else {
                $qty_needed -= $remaining;
                $pdo->prepare("UPDATE merchandise_batches SET remaining_qty = 0, status = 'depleted', updated_at = NOW() WHERE id = ?")
                    ->execute([$batch_id]);
            }
        }
    } catch (Exception $e) {
        error_log("FIFO batch deduction failed for product $product_id: " . $e->getMessage());
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * GLOBAL UNIFIED INVENTORY MOVEMENT ENGINE — record_inventory_movement()
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Universal inventory execution pipeline:
 * TRANSACTION -> Determine IN / OUT -> Validate / Check duplicate -> Update stock -> Record Inventory Movement Log -> Audit Trail -> Notification
 *
 * Rules:
 * - Customer buys merchandise           => OUT | Merchandise Sale
 * - Job Order uses merchandise/parts    => OUT | Job Order Usage
 * - Job Order + Merchandise             => OUT | Job Order + Merchandise Sale
 * - Approved Stock-In                   => IN  | Stock-In (Approved)
 * - Approved Void                       => IN  | Void / Sale Reversal (Duplicate checked!)
 * - Approved Adjustment +               => IN  | Stock Adjustment (+)
 * - Approved Adjustment -               => OUT | Stock Adjustment (-)
 * - Return / Approved Return            => IN  | Customer Return / Restock
 * - Pending/Rejected                    => No movement
 */
if (!function_exists('record_inventory_movement')) {
function record_inventory_movement(
    PDO $pdo,
    int $station_id,
    $product_id_or_name,
    string $movement_type, // 'IN' or 'OUT'
    float $quantity,
    string $reason,
    string $reference_no = '',
    string $reference_type = 'general',
    int $user_id = 0,
    string $notes = '',
    bool $check_duplicate_void = false
): array {
    $quantity = abs($quantity);
    if ($quantity <= 0) {
        return ['success' => false, 'error' => 'Quantity must be greater than zero.'];
    }

    $movement_type = strtoupper(trim($movement_type));
    if (!in_array($movement_type, ['IN', 'OUT'])) {
        return ['success' => false, 'error' => 'Invalid movement type. Must be IN or OUT.'];
    }

    // Resolve product ID & Name
    $product_id = 0;
    $product_name = '';
    if (is_numeric($product_id_or_name) && (int)$product_id_or_name > 0) {
        $product_id = (int)$product_id_or_name;
        $st = $pdo->prepare("SELECT product_name FROM inventory_products WHERE id = ? LIMIT 1");
        $st->execute([$product_id]);
        $product_name = $st->fetchColumn() ?: ('Product #' . $product_id);
    } else {
        $product_name = (string)$product_id_or_name;
        $st = $pdo->prepare("SELECT id FROM inventory_products WHERE product_name = ? LIMIT 1");
        $st->execute([$product_name]);
        $product_id = (int)$st->fetchColumn();
    }

    if ($product_id <= 0) {
        return ['success' => false, 'error' => "Product not found: {$product_name}"];
    }

    // ── Check duplicate void/reversal protection ──────────────────────────────
    if ($check_duplicate_void || stripos($reason, 'void') !== false || stripos($reason, 'reversal') !== false) {
        if ($reference_no !== '') {
            $chk = $pdo->prepare("
                SELECT id FROM inventory_logs 
                WHERE station_id = ? AND product_id = ? AND reference_no = ? 
                  AND (movement_type = 'IN' OR action LIKE '%void%' OR action LIKE '%reversal%' OR reason LIKE '%void%' OR reason LIKE '%reversal%')
                LIMIT 1
            ");
            $chk->execute([$station_id, $product_id, $reference_no]);
            if ($chk->fetchColumn()) {
                return [
                    'success'          => false,
                    'error'            => "Transaction {$reference_no} has already been reversed in inventory.",
                    'already_reversed' => true
                ];
            }
        }
    }

    // ── Fetch & lock station inventory row ────────────────────────────────────
    $stInv = $pdo->prepare("SELECT id, stock_level, critical_level, reorder_level FROM station_inventory WHERE station_id = ? AND product_id = ? FOR UPDATE");
    $stInv->execute([$station_id, $product_id]);
    $invRow = $stInv->fetch(PDO::FETCH_ASSOC);

    if (!$invRow) {
        // Initialize row if not existing
        $pdo->prepare("
            INSERT INTO station_inventory (station_id, product_id, stock_level, critical_level, reorder_level, last_updated)
            VALUES (?, ?, 0, 10, 5, NOW())
        ")->execute([$station_id, $product_id]);
        $previous_stock = 0.0;
        $critical_level = 10;
    } else {
        $previous_stock = (float)$invRow['stock_level'];
        $critical_level = (int)($invRow['critical_level'] ?? 10);
    }

    // Calculate new stock level
    if ($movement_type === 'IN') {
        $new_stock = $previous_stock + $quantity;
    } else {
        $new_stock = max(0, $previous_stock - $quantity);
    }

    // ── Update station_inventory ──────────────────────────────────────────────
    $pdo->prepare("
        UPDATE station_inventory 
        SET stock_level = ?, last_updated = NOW() 
        WHERE station_id = ? AND product_id = ?
    ")->execute([$new_stock, $station_id, $product_id]);

    // ── Sync inventory_products (fallback / catalog table) ─────────────────────
    try {
        $pdo->prepare("
            UPDATE inventory_products 
            SET stock = ?, stock_quantity = ?, updated_at = NOW() 
            WHERE id = ?
        ")->execute([$new_stock, $new_stock, $product_id]);
    } catch (Exception $e) {}

    // ── Sync FIFO batches if OUT movement ─────────────────────────────────────
    if ($movement_type === 'OUT') {
        try {
            fifo_deduct_stock($pdo, $station_id, $product_id, $quantity);
        } catch (Exception $e) {}
    }

    // ── Insert formatted Inventory Movement Log ───────────────────────────────
    $logStmt = $pdo->prepare("
        INSERT INTO inventory_logs
            (station_id, product_id, user_id, action, movement_type, reason,
             quantity_before, quantity_after, quantity_change, reference_type, reference_no, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $logStmt->execute([
        $station_id,
        $product_id,
        $user_id > 0 ? $user_id : null,
        strtolower(str_replace([' ', '/', '+', '-'], '_', $reason)),
        $movement_type,
        $reason,
        $previous_stock,
        $new_stock,
        $quantity,
        $reference_type,
        $reference_no ?: null,
        $notes ?: null
    ]);
    $movement_id = (int)$pdo->lastInsertId();

    // ── Low / Critical Stock Notification ─────────────────────────────────────
    if ($new_stock <= $critical_level && $movement_type === 'OUT') {
        try {
            notify_manager(
                $pdo, $station_id,
                'warning', 'inventory', 'high',
                "Low Stock Alert: {$product_name}",
                "Product {$product_name} is down to {$new_stock} units (Critical Level: {$critical_level}).",
                "crit_stock_{$product_id}_{$new_stock}",
                "manager_inventory_merchandise.php?id={$product_id}",
                'inventory_product', $product_id
            );
        } catch (Throwable $e) {}
    }

    return [
        'success'        => true,
        'movement_id'    => $movement_id,
        'movement_type'  => $movement_type,
        'previous_stock' => $previous_stock,
        'new_stock'      => $new_stock,
        'quantity'       => $quantity,
        'reason'         => $reason,
        'reference_no'   => $reference_no
    ];
}
}

// ── Specific Workflow Wrappers ───────────────────────────────────────────
if (!function_exists('record_merchandise_sale_movement')) {
function record_merchandise_sale_movement(PDO $pdo, int $station_id, $product_id_or_name, float $quantity, string $txn_no, int $user_id = 0): array {
    return record_inventory_movement($pdo, $station_id, $product_id_or_name, 'OUT', $quantity, 'Merchandise Sale', $txn_no, 'merchandise_transactions', $user_id);
}
}

if (!function_exists('record_job_order_parts_movement')) {
function record_job_order_parts_movement(PDO $pdo, int $station_id, $product_id_or_name, float $quantity, string $jo_no, int $user_id = 0): array {
    return record_inventory_movement($pdo, $station_id, $product_id_or_name, 'OUT', $quantity, 'Job Order Usage', $jo_no, 'job_orders', $user_id);
}
}

if (!function_exists('record_stock_in_movement')) {
function record_stock_in_movement(PDO $pdo, int $station_id, $product_id_or_name, float $quantity, string $ref_no, int $user_id = 0, string $notes = ''): array {
    return record_inventory_movement($pdo, $station_id, $product_id_or_name, 'IN', $quantity, 'Stock-In', $ref_no, 'stock_in', $user_id, $notes);
}
}

if (!function_exists('record_void_reversal_movement')) {
function record_void_reversal_movement(PDO $pdo, int $station_id, $product_id_or_name, float $quantity, string $orig_txn_no, int $user_id = 0, string $notes = ''): array {
    return record_inventory_movement($pdo, $station_id, $product_id_or_name, 'IN', $quantity, 'Void/Reversal', $orig_txn_no, 'void', $user_id, $notes, true);
}
}

if (!function_exists('record_adjustment_movement')) {
function record_adjustment_movement(PDO $pdo, int $station_id, $product_id_or_name, float $delta_qty, string $ref_no, int $user_id = 0, string $notes = ''): array {
    $mtype = $delta_qty >= 0 ? 'IN' : 'OUT';
    $reason = $delta_qty >= 0 ? 'Stock Adjustment (+)' : 'Stock Adjustment (-)';
    return record_inventory_movement($pdo, $station_id, $product_id_or_name, $mtype, abs($delta_qty), $reason, $ref_no, 'adjustment', $user_id, $notes);
}
}

if (!function_exists('record_return_movement')) {
function record_return_movement(PDO $pdo, int $station_id, $product_id_or_name, float $quantity, string $return_ref_no, int $user_id = 0, string $notes = ''): array {
    return record_inventory_movement($pdo, $station_id, $product_id_or_name, 'IN', $quantity, 'Customer Return', $return_ref_no, 'returns', $user_id, $notes);
}
}

if (!function_exists('clean_fuel_display_name')) {
function clean_fuel_display_name($fuel_type) {
    $name = trim((string)$fuel_type);
    $name = preg_replace('/\s+\d+\s*-\s*\d+$/', '', $name); // Remove " 1 - 1"
    $name = preg_replace('/\s*-\s*\d+$/', '', $name);       // Remove " - 1"
    $name = trim($name);
    $normalized = strtoupper(preg_replace('/\s+/', ' ', $name));
    if (strpos($normalized, 'TURBO') !== false && strpos($normalized, 'DIESEL') !== false) return 'Turbo Diesel';
    if (strpos($normalized, 'KEROSENE') !== false) return 'Kerosene';
    if (strpos($normalized, 'XCS') !== false) return 'XCS Plus';
    if (strpos($normalized, 'XTRA') !== false && strpos($normalized, 'UNL') !== false) return 'Xtra UNL';
    if (strpos($normalized, 'DIESEL 2') !== false || strpos($normalized, 'DIESEL - 2') !== false) return 'Diesel 2';
    if (strpos($normalized, 'DIESEL 1') !== false || strpos($normalized, 'DIESEL - 1') !== false) return 'Diesel 1';
    if (strpos($normalized, 'DIESEL') !== false) return 'Diesel';
    return $name !== '' ? $name : 'Fuel';
}
}

if (!function_exists('resolve_fuel_inventory_tank_id')) {
function resolve_fuel_inventory_tank_id(PDO $pdo, int $station_id, $label, $fuel_type = ''): ?int {
    $raw = strtoupper(trim((string)$label));
    if (empty($raw) && !empty($fuel_type)) {
        $raw = strtoupper(trim((string)$fuel_type));
    }
    
    $target_tank_num = 0;
    if (strpos($raw, 'TURBO') !== false) {
        $target_tank_num = 3; // UGT-03 / Turbo Diesel
    } elseif (strpos($raw, 'DIESEL 2') !== false || strpos($raw, 'DIESEL - 2') !== false || strpos($raw, 'DSL 2') !== false || strpos($raw, 'DSL - 2') !== false) {
        $target_tank_num = 2; // UGT-02 / Diesel 2
    } elseif (strpos($raw, 'DIESEL 1') !== false || strpos($raw, 'DIESEL - 1') !== false || strpos($raw, 'DSL 1') !== false || strpos($raw, 'DSL - 1') !== false || strpos($raw, 'DIESEL') !== false || strpos($raw, 'DSL') !== false) {
        $target_tank_num = 1; // UGT-01 / Diesel 1
    } elseif (strpos($raw, 'XCS') !== false) {
        $target_tank_num = 4; // UGT-04 / XCS Plus
    } elseif (strpos($raw, 'XTRA UNL 1') !== false || strpos($raw, 'ADVANCE 1') !== false || strpos($raw, 'UNL 1') !== false) {
        $target_tank_num = 5; // UGT-05 / Xtra UNL 1
    } elseif (strpos($raw, 'XTRA UNL 2') !== false || strpos($raw, 'ADVANCE 2') !== false || strpos($raw, 'UNL 2') !== false) {
        $target_tank_num = 6; // UGT-06 / Xtra UNL 2
    } elseif (strpos($raw, 'XTRA') !== false || strpos($raw, 'UNL') !== false || strpos($raw, 'ADVANCE') !== false) {
        $target_tank_num = 5; // Default to UGT-05 if unspecified
    } elseif (strpos($raw, 'KERO') !== false) {
        $target_tank_num = 7; // UGT-07 / Kerosene
    }

    if ($target_tank_num > 0) {
        $ugt_tag1 = 'UGT #' . $target_tank_num;
        $ugt_tag2 = 'UGT-' . str_pad($target_tank_num, 2, '0', STR_PAD_LEFT);
        $ugt_tag3 = '#' . $target_tank_num;

        $stmt = $pdo->prepare("
            SELECT id FROM fuel_inventory
            WHERE station_id = ?
              AND (
                  UPPER(TRIM(ugt_no)) = ?
                  OR UPPER(TRIM(ugt_no)) = ?
                  OR fuel_type LIKE ?
                  OR fuel_type LIKE ?
              )
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute([$station_id, strtoupper($ugt_tag1), strtoupper($ugt_tag2), "%{$ugt_tag3}%", "%UGT-0{$target_tank_num}%"]);
        $tid = $stmt->fetchColumn();
        if ($tid) return (int)$tid;
    }

    $clean = trim((string)($fuel_type ?: $label));
    $clean_stripped = trim(preg_replace('/\s*-\s*\d+$/', '', preg_replace('/\s+\d+\s*-\s*\d+$/', '', $clean)));

    $stmt2 = $pdo->prepare("
        SELECT id FROM fuel_inventory 
        WHERE station_id = ? 
          AND (
              LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
              OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
              OR LOWER(TRIM(fuel_type)) LIKE ?
              OR ? LIKE CONCAT('%', LOWER(TRIM(fuel_type)), '%')
          )
        ORDER BY id ASC LIMIT 1
    ");
    $stmt2->execute([
        $station_id, 
        $clean, 
        $clean_stripped, 
        '%' . strtolower(trim($clean_stripped)) . '%',
        strtolower(trim($clean_stripped))
    ]);
    $tid2 = $stmt2->fetchColumn();
    return $tid2 ? (int)$tid2 : null;
}
}

if (!function_exists('deduct_fuel_inventory_stock')) {
function deduct_fuel_inventory_stock(PDO $pdo, int $station_id, $label, $fuel_type, float $liters, int $user_id = 0): bool {
    if (abs($liters) < 0.0001) return true;
    $tank_id = resolve_fuel_inventory_tank_id($pdo, $station_id, $label, $fuel_type);
    if (!$tank_id) {
        error_log("deduct_fuel_inventory_stock: Could not resolve tank for station_id=$station_id, label=$label, fuel_type=$fuel_type");
        return false;
    }
    $stmt = $pdo->prepare("
        UPDATE fuel_inventory
        SET current_level = GREATEST(0, LEAST(capacity, COALESCE(current_level, 0) - ?)),
            current_stock  = GREATEST(0, LEAST(capacity, COALESCE(current_stock, 0) - ?)),
            last_updated   = NOW(),
            updated_by     = ?
        WHERE id = ? AND station_id = ?
    ");
    return $stmt->execute([$liters, $liters, $user_id, $tank_id, $station_id]);
}
}

if (!function_exists('refund_fuel_inventory_stock')) {
function refund_fuel_inventory_stock(PDO $pdo, int $station_id, $label, $fuel_type, float $liters, int $user_id = 0): bool {
    if (abs($liters) < 0.0001) return true;
    return deduct_fuel_inventory_stock($pdo, $station_id, $label, $fuel_type, -$liters, $user_id);
}
}

// ── Ensure all merchandise products from Pricing/Catalog are in station_inventory ──
if (!function_exists('ensure_station_inventory_synced')) {
function ensure_station_inventory_synced(PDO $pdo, int $station_id): void {
    if ($station_id <= 0) return;
    // ── NATIONWIDE RULE: ONLY station 1253 (Vamenta Carmen) has master demo data ──
    // Other 1,411 stations must start completely empty and configure their own products.
    // Never auto-copy products from the global template to new stations.
    if ($station_id !== 1253) {
        return;
    }
    try {
        // 1. Sync any inventory_products not yet in station_inventory for this station
        $pdo->prepare("
            INSERT INTO station_inventory (station_id, product_id, stock_level, unit, cost, price, reorder_level, critical_level, status, expiration_date, last_updated)
            SELECT 
                ?, 
                ip.id, 
                COALESCE(ip.stock_quantity, ip.stock, 0), 
                COALESCE(NULLIF(TRIM(ip.size),''), 'pcs'), 
                COALESCE(ip.unit_cost, 0), 
                COALESCE(ip.unit_price, 0), 
                COALESCE(ip.reorder_level, 24), 
                COALESCE(ip.critical_level, 10), 
                COALESCE(ip.status, 'active'), 
                ip.expiration_date, 
                NOW()
            FROM inventory_products ip
            WHERE (ip.station_id = ? OR ip.station_id IS NULL)
              AND LOWER(COALESCE(ip.category, '')) NOT IN ('fuel', 'fuel products', 'services', 'service')
              AND LOWER(COALESCE(ip.status, 'active')) NOT IN ('deleted', 'archived')
              AND ip.id NOT IN (SELECT product_id FROM station_inventory WHERE station_id = ?)
        ")->execute([$station_id, $station_id, $station_id]);

        // 2. Sync any products not yet in station_inventory
        $pdo->prepare("
            INSERT INTO station_inventory (station_id, product_id, stock_level, unit, cost, price, reorder_level, critical_level, status, last_updated)
            SELECT 
                ?, 
                p.id, 
                COALESCE(p.current_stock, 0), 
                COALESCE(NULLIF(TRIM(p.unit),''), 'pcs'), 
                COALESCE(p.cost, 0), 
                COALESCE(p.price, 0), 
                COALESCE(p.min_stock_level, 24), 
                COALESCE(p.max_stock_level, 10), 
                COALESCE(p.status, 'active'), 
                NOW()
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE (p.station_id = ? OR p.station_id IS NULL)
              AND LOWER(COALESCE(pc.name, '')) NOT IN ('fuel', 'fuel products', 'services', 'service')
              AND LOWER(COALESCE(p.status, 'active')) NOT IN ('deleted', 'archived')
              AND p.id NOT IN (SELECT product_id FROM station_inventory WHERE station_id = ?)
              AND p.id NOT IN (SELECT id FROM inventory_products)
        ")->execute([$station_id, $station_id, $station_id]);

        // 3. Backfill/update empty or zero prices and costs in station_inventory from inventory_products
        $pdo->prepare("
            UPDATE station_inventory si
            JOIN inventory_products ip ON ip.id = si.product_id
            SET si.price = ip.unit_price,
                si.cost  = COALESCE(NULLIF(NULLIF(TRIM(si.cost), ''), '0'), ip.unit_cost, 0)
            WHERE si.station_id = ?
              AND (TRIM(COALESCE(si.price, '')) = '' OR si.price = '0' OR si.price = 0 OR si.price IS NULL)
              AND ip.unit_price > 0
        ")->execute([$station_id]);

        // 4. Backfill/update empty or zero prices and costs from products table
        $pdo->prepare("
            UPDATE station_inventory si
            JOIN products p ON p.id = si.product_id
            SET si.price = p.price,
                si.cost  = COALESCE(NULLIF(NULLIF(TRIM(si.cost), ''), '0'), p.cost, 0)
            WHERE si.station_id = ?
              AND (TRIM(COALESCE(si.price, '')) = '' OR si.price = '0' OR si.price = 0 OR si.price IS NULL)
              AND p.price > 0
        ")->execute([$station_id]);
    } catch (Exception $e) {
        error_log('ensure_station_inventory_synced error: ' . $e->getMessage());
    }
}
}

// ── Ensure fuel products from Product & Pricing/Inventory/Fuel Types are synced ──
// ── Ensure fuel products from Product & Pricing/Inventory/Fuel Types are synced ──
if (!function_exists('ensure_fuel_inventory_synced')) {
function ensure_fuel_inventory_synced(PDO $pdo, int $station_id): void {
    if ($station_id <= 0) return;
    try {
        // If this station has NO fuel_inventory rows with a real price set,
        // it is an unconfigured station — do NOT auto-create records.
        $has_setup_stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM fuel_inventory WHERE station_id = ? AND price_per_liter > 0"
        );
        $has_setup_stmt->execute([$station_id]);
        if ((int)$has_setup_stmt->fetchColumn() === 0) {
            return;
        }

        // A. If fuel exists in fuel_inventory, ensure corresponding entries in fuel_types and inventory_products
        $fi_types = $pdo->prepare("SELECT DISTINCT fuel_type, price_per_liter FROM fuel_inventory WHERE station_id = ? AND fuel_type IS NOT NULL AND fuel_type != ''");
        $fi_types->execute([$station_id]);
        foreach ($fi_types->fetchAll(PDO::FETCH_ASSOC) as $fi) {
            $fname = trim($fi['fuel_type']);
            if ($fname === '') continue;
            $fprice = (float)($fi['price_per_liter'] ?? 0);

            // Ensure in fuel_types
            $chk_ft = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
            $chk_ft->execute([$fname]);
            if (!$chk_ft->fetchColumn()) {
                $pdo->prepare("INSERT INTO fuel_types (name, price_per_liter) VALUES (?, ?)")->execute([$fname, $fprice]);
            }

            // Ensure in inventory_products using exact product name (never clone or auto-create duplicate tanks)
            $chk_ip = $pdo->prepare("SELECT id, unit_price FROM inventory_products WHERE station_id = ? AND LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND LOWER(COALESCE(category,'')) IN ('fuel', 'fuel products') LIMIT 1");
            $chk_ip->execute([$station_id, $fname]);
            $existing_ip = $chk_ip->fetch(PDO::FETCH_ASSOC);
            if (!$existing_ip) {
                $pdo->prepare("INSERT INTO inventory_products (station_id, product_name, category, unit_cost, unit_price, stock, status, created_at) VALUES (?, ?, 'Fuel', ?, ?, 0, 'active', NOW())")
                    ->execute([$station_id, $fname, $fprice, $fprice]);
            } elseif ((float)$existing_ip['unit_price'] <= 0 && $fprice > 0) {
                $pdo->prepare("UPDATE inventory_products SET unit_cost = ?, unit_price = ? WHERE id = ?")
                    ->execute([$fprice, $fprice, $existing_ip['id']]);
            }
        }
        // Part B deliberately removed: NEVER auto-insert extra tanks/products into fuel_inventory.
        // Exactly ONE fuel product is created per user action.
    } catch (Exception $e) {
        error_log('ensure_fuel_inventory_synced error: ' . $e->getMessage());
    }
}
}

function get_system_logo_url($station_id = null) {
    global $pdo;
    if ($station_id === null) {
        $station_id = function_exists('user_station_id') ? (user_station_id() ?: 0) : 0;
    }
    
    $default_logo = 'assets/img/petron_logo.png';
    try {
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key IN ('company_logo','logo','system_logo') AND station_id = ? AND setting_value IS NOT NULL AND setting_value != '' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$station_id]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null && $val !== '' && $val !== 'none' && $val !== 'removed') {
                return ltrim(preg_replace('/^(\.\.\/)+/', '', trim($val)), '/');
            }
            if ($station_id > 0) {
                $stmt->execute([0]);
                $val = $stmt->fetchColumn();
                if ($val !== false && $val !== null && $val !== '' && $val !== 'none' && $val !== 'removed') {
                    return ltrim(preg_replace('/^(\.\.\/)+/', '', trim($val)), '/');
                }
            }
        }
    } catch (Exception $e) {}
    
    return $default_logo;
}

define('PETRON_7_UGT_CONFIG', [
    ['fuel_type'=>'Diesel 1',     'label'=>'DIESEL 1 - 1',     'tank'=>'UGT #1',  'tanker_num'=>1,  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
    ['fuel_type'=>'Diesel 2',     'label'=>'DIESEL 2 - 2',     'tank'=>'UGT #2',  'tanker_num'=>2,  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
    ['fuel_type'=>'Turbo Diesel', 'label'=>'TURBO DIESEL - 1', 'tank'=>'UGT #3',  'tanker_num'=>3,  'capacity'=>7000,  'reorder_level'=>2000, 'critical_level'=>1000],
    ['fuel_type'=>'XCS Plus',     'label'=>'XCS PLUS - 1',     'tank'=>'UGT #4',  'tanker_num'=>4,  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
    ['fuel_type'=>'Xtra UNL',     'label'=>'XTR ADVANCE - 1',  'tank'=>'UGT #5',  'tanker_num'=>5,  'capacity'=>7000,  'reorder_level'=>2000, 'critical_level'=>1000],
    ['fuel_type'=>'Xtra UNL',     'label'=>'XTR ADVANCE - 2',  'tank'=>'UGT #6',  'tanker_num'=>6,  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
    ['fuel_type'=>'Kerosene',     'label'=>'KEROSENE - 1',     'tank'=>'UGT #7',  'tanker_num'=>7,  'capacity'=>14000, 'reorder_level'=>5000, 'critical_level'=>2500],
]);

function get_tank_config(int $station_id = null): array {
    global $pdo;

    if ($station_id === null) {
        $station_id = (int)user_station_id();
    }

    if ($pdo && $station_id > 0) {
        // 1. Dynamic DB Fetch: Query fuel_inventory for this station's active tanks
        try {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    fuel_type_id,
                    COALESCE(NULLIF(CAST(REGEXP_REPLACE(ugt_no, '[^0-9]', '') AS UNSIGNED), 0), id) AS tanker_num,
                    COALESCE(NULLIF(TRIM(fuel_type), ''), 'Unknown') AS fuel_type,
                    COALESCE(NULLIF(TRIM(ugt_no), ''), CONCAT('UGT #', id)) AS label,
                    COALESCE(NULLIF(TRIM(ugt_no), ''), CONCAT('UGT #', id)) AS tank,
                    COALESCE(NULLIF(capacity, 0), 14000) AS capacity,
                    COALESCE(reorder_level, 0) AS reorder_level,
                    COALESCE(critical_level, 0) AS critical_level
                FROM fuel_inventory
                WHERE station_id = ?
                  AND LOWER(COALESCE(status, 'active')) NOT IN ('archived', 'deleted')
                ORDER BY CAST(REGEXP_REPLACE(ugt_no, '[^0-9]', '') AS UNSIGNED) ASC, id ASC
            ");
            $stmt->execute([$station_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                return array_map('_normalize_tank_row', $rows);
            }
        } catch (Throwable $e) {
            error_log("get_tank_config fuel_inventory query error: " . $e->getMessage());
        }

        // 2. Fallback: check fuel_tanks table if present
        try {
            $tables = $pdo->query("SHOW TABLES LIKE 'fuel_tanks'")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($tables)) {
                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        id AS tanker_num,
                        COALESCE(NULLIF(TRIM(fuel_type), ''), 'Unknown') AS fuel_type,
                        COALESCE(NULLIF(TRIM(label), ''), CONCAT('Tank #', id)) AS label,
                        COALESCE(NULLIF(TRIM(tank_name), ''), CONCAT('UGT #', id)) AS tank,
                        COALESCE(NULLIF(capacity, 0), 14000) AS capacity,
                        COALESCE(reorder_level, 0) AS reorder_level,
                        COALESCE(critical_level, 0) AS critical_level
                    FROM fuel_tanks
                    WHERE station_id = ?
                      AND LOWER(COALESCE(status,'active')) NOT IN ('inactive','disabled','deleted')
                    ORDER BY id ASC
                ");
                $stmt->execute([$station_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    return array_map('_normalize_tank_row', $rows);
                }
            }
        } catch (Throwable $e) {}

        return [];
    }

    return [];
}

/** Internal helper: ensure expected keys exist and are properly typed. */
function _normalize_tank_row(array $row): array {
    $cap = max(0.0, (float)($row['capacity'] ?? 0));
    if ($cap <= 0) $cap = 14000.0;
    $reorder   = (float)($row['reorder_level'] ?? 0);
    $critical  = (float)($row['critical_level'] ?? 0);
    if ($reorder <= 0)  $reorder  = ($cap == 7000) ? 2000.0 : 5000.0;
    if ($critical <= 0) $critical = ($cap == 7000) ? 1000.0 : 2500.0;
    return [
        'id'             => (int)($row['id'] ?? 0),
        'fuel_type_id'   => (int)($row['fuel_type_id'] ?? 0),
        'fuel_type'      => trim((string)($row['fuel_type']  ?? 'Unknown')),
        'label'          => trim((string)($row['label']      ?? 'Tank')),
        'tank'           => trim((string)($row['tank']       ?? 'UGT')),
        'tanker_num'     => (int)($row['tanker_num']         ?? 0),
        'capacity'       => $cap,
        'reorder_level'  => $reorder,
        'critical_level' => $critical,
    ];
}

function get_tanks_by_fuel_type(string $fuel_type, int $station_id = null): array {
    $tanks = [];
    foreach (get_tank_config($station_id) as $tank) {
        if (strcasecmp($tank['fuel_type'], $fuel_type) === 0) {
            $tanks[] = $tank;
        }
    }
    return $tanks;
}

function get_tank_by_ugt(int $ugt_no, int $station_id = null): ?array {
    foreach (get_tank_config($station_id) as $tank) {
        if ((int)$tank['tanker_num'] === $ugt_no) {
            return $tank;
        }
    }
    return null;
}

/**
 * Helper to log inventory movements to inventory_logs
 * Actions: 'Stock In', 'Merchandise Sale', 'Job Order Usage', 'Stock Adjustment'
 */
function log_inventory_movement(PDO $pdo, int $station_id, int $product_id, string $product_name, string $action, int $qty_before, int $qty_after, int $qty_change, ?string $ref_type = null, ?string $ref_id = null, ?string $performed_by = null, ?string $notes = null): bool {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `inventory_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `station_id` INT NOT NULL,
            `product_id` INT NOT NULL,
            `product_name` VARCHAR(255) NULL,
            `user_id` INT NULL,
            `performed_by` VARCHAR(255) NULL,
            `action` VARCHAR(100) NOT NULL,
            `quantity_before` INT NOT NULL DEFAULT 0,
            `quantity_after` INT NOT NULL DEFAULT 0,
            `quantity_change` INT NOT NULL DEFAULT 0,
            `reference_type` VARCHAR(100) NULL,
            `reference_id` VARCHAR(100) NULL,
            `notes` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_station (station_id),
            INDEX idx_product (product_id),
            INDEX idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $me = function_exists('current_user') ? current_user() : null;
        $user_id = $me ? (int)($me['id'] ?? 0) : null;
        if (empty($performed_by)) {
            $performed_by = $me ? ($me['name'] ?? $me['username'] ?? 'System') : 'System';
        }

        $stmt = $pdo->prepare("
            INSERT INTO inventory_logs (
                station_id, product_id, product_name, user_id, performed_by,
                action, quantity_before, quantity_after, quantity_change,
                reference_type, reference_id, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([
            $station_id,
            $product_id,
            $product_name,
            $user_id,
            $performed_by,
            $action,
            $qty_before,
            $qty_after,
            $qty_change,
            $ref_type,
            $ref_id,
            $notes
        ]);
    } catch (Exception $e) {
        error_log("log_inventory_movement error: " . $e->getMessage());
        return false;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// LEVEL 1: MASTER UNIT TESTING REGISTRY HELPERS (UT-101 to UT-107)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * UT-101: validateLoginCredentials
 * Validates user credentials against database and returns authentication and role state
 */
if (!function_exists('validateLoginCredentials')) {
function validateLoginCredentials($account, $password, $pdo = null) {
    if ($pdo === null) {
        global $pdo;
    }
    $account = trim((string)$account);
    $password = (string)$password;
    
    if (empty($account) || empty($password)) {
        return [
            'valid' => false,
            'user' => null,
            'role' => null,
            'error' => 'Account identifier and password are required'
        ];
    }
    
    if (!$pdo) {
        return ['valid' => false, 'user' => null, 'role' => null, 'error' => 'Database connection unavailable'];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM users 
            WHERE (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?) OR phone = ?)
              AND LOWER(COALESCE(status, 'active')) = 'active'
            LIMIT 1
        ");
        $stmt->execute([$account, $account, $account]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['valid' => false, 'user' => null, 'role' => null, 'error' => 'User not found or inactive'];
        }
        
        $hash = $user['password_hash'] ?? $user['password'] ?? '';
        if (password_verify($password, $hash) || $hash === $password || (md5($password) === $hash)) {
            $role = role_key($user['role'] ?? 'staff');
            return [
                'valid' => true,
                'user' => $user,
                'role' => $role,
                'error' => null
            ];
        }
        
        return ['valid' => false, 'user' => null, 'role' => null, 'error' => 'Invalid password credentials'];
    } catch (Exception $e) {
        return ['valid' => false, 'user' => null, 'role' => null, 'error' => $e->getMessage()];
    }
}
}

/**
 * UT-102: validatePasswordStrength
 * Validates password meets security requirements (min 8 chars, mixed case, number, special char)
 */
if (!function_exists('validatePasswordStrength')) {
function validatePasswordStrength($password) {
    $password = (string)$password;
    if (strlen($password) < 8) {
        return false;
    }
    // Must contain uppercase, lowercase, and at least one number or symbol
    $has_upper = preg_match('/[A-Z]/', $password);
    $has_lower = preg_match('/[a-z]/', $password);
    $has_num   = preg_match('/[0-9]/', $password);
    $has_sym   = preg_match('/[^a-zA-Z0-9]/', $password);
    
    return ($has_upper && $has_lower && ($has_num || $has_sym));
}
}

/**
 * UT-103: generateAndSendPasswordResetOTP / sendPasswordResetOTP
 * Generates secure OTP, stores hash in database, and sends to registered email
 */
if (!function_exists('generateAndSendPasswordResetOTP')) {
function generateAndSendPasswordResetOTP($email, $pdo = null) {
    if ($pdo === null) {
        global $pdo;
    }
    $email = trim((string)$email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address format'];
    }
    
    if (!$pdo) {
        return ['success' => false, 'error' => 'Database connection unavailable'];
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND LOWER(COALESCE(status,'active')) = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['success' => false, 'error' => 'No active user found with that email address'];
        }
        
        $userId = $user['id'] ?? $user['user_id'] ?? 0;
        $otp_code = sprintf('%06d', random_int(100000, 999999));
        $otp_hash = hash('sha256', $otp_code);
        
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            token_type VARCHAR(50) NOT NULL DEFAULT 'reset',
            expires_at DATETIME NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            is_used TINYINT(1) NOT NULL DEFAULT 0,
            used_at DATETIME NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Invalidate old tokens
        $pdo->prepare("UPDATE password_reset_tokens SET is_used = 1 WHERE user_id = ? AND token_type = 'reset'")->execute([$userId]);
        
        // Insert new token
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, token_type, expires_at, attempts, ip_address) VALUES (?, ?, 'reset', DATE_ADD(NOW(), INTERVAL 5 MINUTE), 0, ?)")
            ->execute([$userId, $otp_hash, $ip]);
            
        // Attempt email dispatch
        $sent = false;
        if (function_exists('sendOtpEmail')) {
            $sent = @sendOtpEmail($email, $otp_code);
        } elseif (function_exists('sendPasswordResetOTPEmail')) {
            $sent = @sendPasswordResetOTPEmail($email, $otp_code);
        }
        
        return [
            'success' => true,
            'email_dispatched' => $sent,
            'user_id' => $userId,
            'expires_in_minutes' => 5,
            'otp_hash' => $otp_hash,
            'error' => null
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
}

if (!function_exists('sendPasswordResetOTP')) {
function sendPasswordResetOTP($to_email, $otp_or_pdo = null, $pdo = null) {
    // If called with (email, '123456') -> legacy email dispatch
    if (is_string($otp_or_pdo) && !empty($otp_or_pdo) && !($otp_or_pdo instanceof PDO) && preg_match('/^\d{4,8}$/', trim($otp_or_pdo))) {
        if (function_exists('sendPasswordResetOTPEmail')) {
            return sendPasswordResetOTPEmail($to_email, $otp_or_pdo);
        }
        if (function_exists('sendOtpEmail')) {
            return sendOtpEmail($to_email, $otp_or_pdo);
        }
        return false;
    }
    
    // Otherwise -> UT-103 OTP generation & DB storage
    $target_pdo = ($otp_or_pdo instanceof PDO) ? $otp_or_pdo : ($pdo instanceof PDO ? $pdo : null);
    return generateAndSendPasswordResetOTP($to_email, $target_pdo);
}
}

/**
 * UT-104: verifyPasswordResetOTP
 * Verifies OTP validity, expiration, and attempt limits
 */
if (!function_exists('verifyPasswordResetOTP')) {
function verifyPasswordResetOTP($otp, $email, $pdo = null) {
    if ($pdo === null) {
        global $pdo;
    }
    $otp = trim((string)$otp);
    $email = trim((string)$email);
    
    if (strlen($otp) !== 6 || !is_numeric($otp)) {
        return ['valid' => false, 'error' => 'Please enter a valid 6-digit numeric OTP'];
    }
    
    if (!$pdo) {
        return ['valid' => false, 'error' => 'Database connection unavailable'];
    }
    
    try {
        $submitted_hash = hash('sha256', $otp);
        $stmt = $pdo->prepare("
            SELECT prt.*, u.id as u_id
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.id
            WHERE LOWER(u.email) = LOWER(?)
              AND prt.token_type = 'reset'
              AND LOWER(COALESCE(u.status,'active')) = 'active'
            ORDER BY prt.id DESC
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$token) {
            return ['valid' => false, 'error' => 'Invalid OTP or no reset request found'];
        }
        
        if ((int)$token['is_used'] === 1) {
            return ['valid' => false, 'error' => 'This OTP has already been used'];
        }
        
        if ((int)$token['attempts'] >= 5) {
            $pdo->prepare("UPDATE password_reset_tokens SET is_used = 1 WHERE id = ?")->execute([$token['id']]);
            return ['valid' => false, 'error' => 'Too many failed verification attempts. OTP locked.'];
        }
        
        if (strtotime($token['expires_at']) < time()) {
            return ['valid' => false, 'error' => 'OTP has expired'];
        }
        
        if ($token['token'] !== $submitted_hash) {
            $pdo->prepare("UPDATE password_reset_tokens SET attempts = attempts + 1 WHERE id = ?")->execute([$token['id']]);
            $remaining = max(0, 5 - ((int)$token['attempts'] + 1));
            return ['valid' => false, 'error' => "Invalid OTP. ({$remaining} attempts remaining)"];
        }
        
        // Successful verification
        $pdo->prepare("UPDATE password_reset_tokens SET is_used = 1, used_at = NOW() WHERE id = ?")->execute([$token['id']]);
        return ['valid' => true, 'user_id' => (int)$token['user_id'], 'error' => null];
    } catch (Exception $e) {
        return ['valid' => false, 'error' => $e->getMessage()];
    }
}
}

/**
 * UT-105: checkRolePermission
 * Validates role-based permission for a specific module and action
 */
if (!function_exists('checkRolePermission')) {
function checkRolePermission($role, $module, $action) {
    $role = strtolower(trim((string)$role));
    $module = strtolower(trim((string)$module));
    $action = strtolower(trim((string)$action));
    
    // Superadmin has unrestricted root access
    if (in_array($role, ['superadmin', 'developer'])) {
        return true;
    }
    
    // Admin access
    if ($role === 'admin') {
        // Admin has oversight across all station operational modules
        $restricted_actions = ['superadmin_config', 'database_drop'];
        return !in_array($action, $restricted_actions);
    }
    
    // Manager access
    if ($role === 'manager') {
        $manager_modules = [
            'transactions', 'job_orders', 'fuel', 'fuel_management',
            'inventory', 'product_management', 'purchase_orders',
            'calendar', 'reports', 'customers', 'deliveries', 'dashboard'
        ];
        $manager_actions = [
            'view', 'review', 'approve', 'reject', 'validate',
            'create_po', 'adjust', 'export', 'print', 'record'
        ];
        return in_array($module, $manager_modules) && in_array($action, $manager_actions);
    }
    
    // Staff access (operational)
    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        $staff_allowed = [
            'transactions' => ['view', 'create', 'encode'],
            'job_orders'   => ['view', 'create'],
            'fuel'         => ['view', 'encode_readings', 'encode'],
            'inventory'    => ['view', 'request_stock', 'record_delivery'],
            'calendar'     => ['view', 'create_event'],
            'reports'      => ['view_personal', 'view_shift'],
            'customers'    => ['view', 'search'],
            'dashboard'    => ['view']
        ];
        return isset($staff_allowed[$module]) && in_array($action, $staff_allowed[$module]);
    }
    
    return false;
}
}

/**
 * UT-106: validateFuelReading
 * Validates meter reading inputs: non-blank, numeric, non-negative, and logical progression
 */
if (!function_exists('validateFuelReading')) {
function validateFuelReading($beginning, $ending, $calibration = 0.0) {
    if (!is_numeric($beginning) || !is_numeric($ending)) {
        return ['valid' => false, 'volume' => 0.0, 'error' => 'Meter readings must be valid numeric values'];
    }
    
    $beg = (float)$beginning;
    $end = (float)$ending;
    $cal = is_numeric($calibration) ? (float)$calibration : 0.0;
    
    if ($beg < 0 || $end < 0 || $cal < 0) {
        return ['valid' => false, 'volume' => 0.0, 'error' => 'Readings and calibration cannot be negative'];
    }
    
    if ($end < $beg) {
        return ['valid' => false, 'volume' => 0.0, 'error' => 'Ending reading cannot be less than beginning reading without mechanical rollover'];
    }
    
    $volume = round($end - $beg - $cal, 2);
    if ($volume < 0) {
        return ['valid' => false, 'volume' => 0.0, 'error' => 'Calibration exceeds gross volume dispensed'];
    }
    
    return [
        'valid' => true,
        'volume' => $volume,
        'gross_volume' => round($end - $beg, 2),
        'calibration' => $cal,
        'error' => null
    ];
}
}

/**
 * UT-107: formatCurrencyInput
 * Formats manual monetary inputs into standard 2-decimal localized format
 */
if (!function_exists('formatCurrencyInput')) {
function formatCurrencyInput($value) {
    if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
        return '0.00';
    }
    
    // Strip commas or currency symbols
    $cleaned = str_replace([',', '₱', 'PHP', ' '], '', (string)$value);
    if (!is_numeric($cleaned)) {
        return '0.00';
    }
    
    return number_format((float)$cleaned, 2, '.', ',');
}
}

/**
 * Category breakdown helper for sidebar drawer badges and header bell sync
 */
if (!function_exists('get_category_unread_counts')) {
function get_category_unread_counts(PDO $pdo, int $user_id, string $role = '', int $station_id = 0): array {
    $counts = [
        'transactions'  => 0,
        'fuel'          => 0,
        'inventory'     => 0,
        'customers'     => 0,
        'prod_pricing'  => 0,
        'reports'       => 0,
        'notifications' => 0
    ];

    $safe_count = function(string $sql, array $params = []) use ($pdo) {
        try {
            $s = $pdo->prepare($sql);
            $s->execute($params);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) { return 0; }
    };

    $counts['notifications'] = $safe_count("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'", [$user_id]);

    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        // SERVICE STAFF
        $counts['transactions'] = $safe_count(
            "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND (created_by = ? OR user_id = ? OR assigned_mechanic_id = ?) AND LOWER(COALESCE(status,'')) IN ('pending','reviewed','in progress','awaiting parts')",
            [$station_id, $user_id, $user_id, $user_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND staff_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation','pending_validation')",
            [$station_id, $user_id]
        );

        $counts['fuel'] = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND staff_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','pending_validation')",
            [$station_id, $user_id]
        );

        $counts['inventory'] = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 24)",
            [$station_id]
        );

    } elseif (in_array($role, ['manager', 'supervisor'])) {
        // MANAGER
        $counts['transactions'] = $safe_count(
            "SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation','reviewed')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND LOWER(COALESCE(validation_status,'')) IN ('pending','pending validation')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
            [$station_id]
        );

        $counts['fuel'] = $safe_count(
            "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM fuel_adjustments WHERE station_id = ? AND LOWER(COALESCE(adjustment_type,'')) LIKE '%calibration%' AND LOWER(COALESCE(status,'')) IN ('pending','pending review')",
            [$station_id]
        );

        $counts['inventory'] = $safe_count(
            "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(si.reorder_level, ip.min_stock, 24)",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status IN ('Pending','Pending Manager Review')",
            [$station_id]
        ) + $safe_count(
            "SELECT COUNT(*) FROM purchase_orders WHERE station_id = ? AND status IN ('Approved','Pending Stock-In')",
            [$station_id]
        );

        $counts['customers']     = $safe_count(
            "SELECT COUNT(*) FROM customers WHERE station_id = ? AND LOWER(COALESCE(NULLIF(verification_status,''), NULLIF(mgr_status,''), 'verified')) IN ('pending','pending verification','for review')",
            [$station_id]
        );
        $counts['mgr_customers'] = $counts['customers'];

    } elseif (in_array($role, ['admin', 'superadmin', 'developer'])) {
        // ADMIN
        if ($station_id > 0) {
            $admin_crit_stock = $safe_count(
                "SELECT COUNT(*) FROM station_inventory si LEFT JOIN inventory_products ip ON ip.id = si.product_id WHERE si.station_id = ? AND (LOWER(COALESCE(ip.category,'')) NOT IN ('fuel','fuels') OR ip.category IS NULL) AND si.stock_level <= COALESCE(si.critical_level, ip.critical_level, 10)",
                [$station_id]
            );
            $admin_pos = $safe_count(
                "SELECT COUNT(*) FROM purchase_orders WHERE station_id = ? AND status IN ('Pending Admin Review', 'Submitted', 'Pending Approval')",
                [$station_id]
            );
            $admin_inv_total = $admin_crit_stock + $admin_pos;
            $counts['inventory']       = $admin_inv_total;
            $counts['admin_inventory'] = $admin_inv_total;

            $admin_price_change = $safe_count(
                "SELECT COUNT(*) FROM pending_price_approvals WHERE station_id = ? AND status = 'pending'",
                [$station_id]
            );
            $counts['prod_pricing']          = $admin_price_change;
            $counts['mgr_product_pricing']   = $admin_price_change;
            $counts['admin_product_pricing'] = $admin_price_change;

            $admin_system_alerts = $safe_count(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND severity IN ('critical','error') AND status = 'unread'",
                [$user_id]
            );
            $counts['reports']       = $admin_system_alerts;
            $counts['admin_reports'] = $admin_system_alerts;

            $admin_fuel_pending = $safe_count(
                "SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(COALESCE(status,'')) IN ('pending','pending validation')",
                [$station_id]
            );
            $counts['fuel']                  = $admin_fuel_pending;
            $counts['admin_fuel']            = $admin_fuel_pending;
            $counts['admin_fuel_management'] = $admin_fuel_pending;
        } else {
            $counts['inventory']             = 0;
            $counts['admin_inventory']       = 0;
            $counts['prod_pricing']          = 0;
            $counts['mgr_product_pricing']   = 0;
            $counts['admin_product_pricing'] = 0;
            $counts['reports']               = 0;
            $counts['admin_reports']         = 0;
            $counts['fuel']                  = 0;
            $counts['admin_fuel']            = 0;
            $counts['admin_fuel_management'] = 0;
        }
    }
    return $counts;
}
}

// ═══════════════════════════════════════════════════════════════════════════
// CENTRAL NOTIFICATION HELPER — notify()
// ═══════════════════════════════════════════════════════════════════════════
if (!function_exists('notify')) {
function notify(
    PDO    $pdo,
    int    $user_id,
    string $role,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = '',
    string $ref_type = '',
    int    $ref_id = 0,
    string $shift_period = ''
): void {
    if ($user_id <= 0) return;
    $title   = function_exists('clean_mojibake') ? clean_mojibake($title) : $title;
    $message = function_exists('clean_mojibake') ? clean_mojibake($message) : $message;
    try {
        static $migrated = false;
        if (!$migrated) {
            foreach ([
                "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS recipient_role VARCHAR(30) NULL AFTER user_id",
                "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS reference_type VARCHAR(80) NULL AFTER redirect_url",
                "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS reference_id INT NULL AFTER reference_type",
                "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS shift_period VARCHAR(20) NULL AFTER reference_id",
            ] as $ddl) {
                try { $pdo->exec($ddl); } catch (Throwable $e) {}
            }
            $migrated = true;
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications
                (user_id, recipient_role, type, event_type, severity, title, message,
                 source_key, redirect_url, reference_type, reference_id, shift_period, status)
            SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unread'
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM notifications WHERE user_id = ? AND source_key = ?
            )
        ");
        $stmt->execute([
            $user_id, $role, $type, $event_type, $severity, $title, $message,
            $source_key, $redirect_url,
            $ref_type ?: null, $ref_id > 0 ? $ref_id : null, $shift_period ?: null,
            $user_id, $source_key
        ]);
    } catch (Throwable $e) {
        error_log('notify() error: ' . $e->getMessage());
    }
}
}

// ─────────────────────────────────────────────────────────────────────────
// notify_manager() — find station's manager user(s) and notify them all
// ─────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────
// notify_transaction_submission() — single notification for any transaction type
// (Merchandise only, Job Order only, or Combined Job Order + Merchandise)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('notify_transaction_submission')) {
function notify_transaction_submission(
    PDO $pdo,
    int $station_id,
    array $data
): void {
    try {
        $txn_id = trim((string)($data['transaction_id'] ?? ''));
        $db_id = (int)($data['transaction_db_id'] ?? $data['id'] ?? 0);
        $raw_type = strtolower(trim((string)($data['transaction_type'] ?? 'merchandise')));
        
        if ($raw_type === 'combined' || (strpos($raw_type, 'job') !== false && strpos($raw_type, 'merch') !== false)) {
            $type_label = 'Job Order + Merchandise';
        } elseif ($raw_type === 'job_order' || strpos($raw_type, 'job') !== false) {
            $type_label = 'Job Order';
        } else {
            $type_label = 'Merchandise';
        }

        $total_amount = (float)($data['total_amount'] ?? $data['amount'] ?? 0);
        $customer = trim((string)($data['customer_name'] ?? $data['customer'] ?? 'Walk-in Customer'));
        if ($customer === '' || $customer === '—') $customer = 'Walk-in Customer';
        $staff = trim((string)($data['staff_name'] ?? $data['staff'] ?? 'Staff'));
        $shift = trim((string)($data['shift_period'] ?? $data['shift_name'] ?? ''));

        $or_no = 'OR-' . date('Y') . '-' . str_pad($db_id > 0 ? $db_id : mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $title = clean_mojibake("New {$type_label} Transaction — {$or_no}");
        $message = clean_mojibake("Staff {$staff} submitted a {$type_label} transaction ({$txn_id}) for {$customer} totaling ₱" . number_format($total_amount, 2) . ".");

        $source_key = "txn_sub_{$station_id}_" . ($db_id > 0 ? $db_id : preg_replace('/[^a-zA-Z0-9_]/', '_', $txn_id));

        notify_manager(
            $pdo,
            $station_id,
            'info',
            'transaction',
            'medium',
            $title,
            $message,
            $source_key,
            'manager_validated_transactions.php',
            'transaction',
            $db_id,
            $shift
        );
    } catch (Throwable $e) {
        error_log("notify_transaction_submission error: " . $e->getMessage());
    }
}
}

if (!function_exists('notify_manager')) {
function notify_manager(
    PDO    $pdo,
    int    $station_id,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = '',
    string $ref_type = '',
    int    $ref_id = 0,
    string $shift_period = ''
): void {
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE (station_id = ? OR station_id IS NULL OR station_id = 0) AND LOWER(role) IN ('manager','supervisor','admin') AND status = 'Active'");
        $stmt->execute([$station_id]);
        $managers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($managers as $mgr_id) {
            notify($pdo, (int)$mgr_id, 'manager', $type, $event_type, $severity,
                   $title, $message, $source_key . '_m' . $mgr_id,
                   $redirect_url, $ref_type, $ref_id, $shift_period);
        }
    } catch (Throwable $e) { error_log('notify_manager error: ' . $e->getMessage()); }
}
}

// ─────────────────────────────────────────────────────────────────────────
// notify_admin() — notify all admin users (system-wide)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('notify_admin')) {
function notify_admin(
    PDO    $pdo,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = '',
    string $ref_type = '',
    int    $ref_id = 0,
    int    $station_id = 0
): void {
    try {
        $sql = $station_id
            ? "SELECT id FROM users WHERE station_id = ? AND LOWER(role) IN ('admin') AND status = 'Active'"
            : "SELECT id FROM users WHERE LOWER(role) IN ('admin') AND status = 'Active'";
        $params = $station_id ? [$station_id] : [];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adm_id) {
            notify($pdo, (int)$adm_id, 'admin', $type, $event_type, $severity,
                   $title, $message, $source_key . '_a' . $adm_id,
                   $redirect_url, $ref_type, $ref_id);
        }
    } catch (Throwable $e) { error_log('notify_admin error: ' . $e->getMessage()); }
}
}

// ─────────────────────────────────────────────────────────────────────────
// notify_superadmin() — system-level only
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('notify_superadmin')) {
function notify_superadmin(
    PDO    $pdo,
    string $type,
    string $event_type,
    string $severity,
    string $title,
    string $message,
    string $source_key,
    string $redirect_url = '',
    string $ref_type = '',
    int    $ref_id = 0
): void {
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE LOWER(role) IN ('superadmin','developer') AND status = 'Active'");
        $sa_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($sa_ids as $sa_id) {
            notify($pdo, (int)$sa_id, 'superadmin', $type, $event_type, $severity,
                   $title, $message, $source_key . '_sa' . $sa_id,
                   $redirect_url, $ref_type, $ref_id);
        }
    } catch (Throwable $e) { error_log('notify_superadmin error: ' . $e->getMessage()); }
}
}

// ─────────────────────────────────────────────────────────────────────────
// notification_redirect_url() — build clickable URL from reference_type + role
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('notification_redirect_url')) {
function notification_redirect_url(string $ref_type, int $ref_id, string $role): string {
    $id = $ref_id > 0 ? "?id={$ref_id}" : '';
    $map = [
        // Staff
        'stock_request' => [
            'staff'    => "staff_inventory_merchandise.php{$id}",
            'manager'  => "manager_inventory_stock_requests.php{$id}",
            'admin'    => "admin_approve_stock_requests.php{$id}",
        ],
        'master_data_request' => [
            'staff'    => "staff_transactions_hub.php?section=merchandise",
            'manager'  => "manager_review_stock_requests.php{$id}",
            'admin'    => "admin_stock_requests_monitor.php{$id}",
        ],
                'transaction' => [
            'staff'    => "staff_transactions_hub.php",
            'manager'  => "manager_validated_transactions.php",
            'admin'    => "admin_all_transactions.php",
        ],
        'merchandise_transaction' => [
            'staff'    => "staff_transactions_hub.php",
            'manager'  => "manager_validated_transactions.php",
            'admin'    => "admin_all_transactions.php",
        ],
        'void_request' => [
            'staff'    => "voided_transactions.php{$id}",
            'manager'  => "manager_validated_transactions.php{$id}",
            'admin'    => "admin_voided_transactions.php{$id}",
        ],
        'transaction_adjustment' => [
            'staff'    => "staff_fuel_sales_report.php{$id}",
            'manager'  => "manager_validated_transactions.php{$id}",
            'admin'    => "admin_voided_transactions.php{$id}",
        ],
        'fuel_transaction' => [
            'staff'    => "staff_fuel_sales_closing.php",
            'manager'  => "manager_fuel_transaction_validation.php",
            'admin'    => "manager_fuel_transaction_validation.php",
        ],
        'fuel_reading' => [
            'staff'    => "staff_transactions_hub.php?section=fuel&fuel_tab=readings",
            'manager'  => "manager_fuel_transaction_validation.php",
            'admin'    => "manager_fuel_transaction_validation.php",
        ],
        'fuel_sales_closing' => [
            'staff'    => "staff_fuel_sales_closing.php",
            'manager'  => "manager_fuel_transaction_validation.php",
            'admin'    => "manager_fuel_transaction_validation.php",
        ],
        'job_order' => [
            'staff'    => "job_order_detail.php{$id}",
            'manager'  => "manager_validated_transactions.php{$id}",
            'admin'    => "manager_validated_transactions.php{$id}",
        ],
        'purchase_order' => [
            'staff'    => "admin_stock_confirmation.php{$id}",
            'manager'  => "admin_stock_confirmation.php{$id}",
            'admin'    => "admin_stock_confirmation.php{$id}",
        ],
        'user_account' => [
            'superadmin' => "superadmin_admin_management.php{$id}",
            'admin'      => "superadmin_admin_management.php{$id}",
        ],
        'customer_request' => [
            'staff'      => "staff_transactions_hub.php?section=merchandise",
            'manager'    => "manager_customers.php?tab=pending" . ($ref_id > 0 ? "&id={$ref_id}" : ""),
            'admin'      => "manager_customers.php?tab=pending" . ($ref_id > 0 ? "&id={$ref_id}" : ""),
            'superadmin' => "manager_customers.php?tab=pending" . ($ref_id > 0 ? "&id={$ref_id}" : ""),
        ],
    ];
    return $map[$ref_type][$role] ?? $map[$ref_type]['staff'] ?? 'notifications.php';
}
}

// ─────────────────────────────────────────────────────────────────────────
// Global Draft & Autosave Engine Functions (backend/lib.php)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('ensure_drafts_table')) {
function ensure_drafts_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_form_drafts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            station_id INT NULL,
            module_key VARCHAR(100) NOT NULL,
            draft_key VARCHAR(150) NOT NULL,
            form_data LONGTEXT NOT NULL,
            status ENUM('draft', 'submitted', 'discarded') NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_module (user_id, module_key),
            INDEX idx_user_status (user_id, status),
            INDEX idx_station_module (station_id, module_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (Exception $e) {}
}
}

if (!function_exists('save_user_draft')) {
function save_user_draft(PDO $pdo, int $userId, int $stationId, string $moduleKey, array $formData): bool {
    if ($userId <= 0 || empty($moduleKey) || empty($formData)) return false;
    ensure_drafts_table($pdo);
    try {
        $json = json_encode($formData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $draftKey = "draft_{$userId}_{$moduleKey}";
        $stmt = $pdo->prepare("
            INSERT INTO user_form_drafts (user_id, station_id, module_key, draft_key, form_data, status, updated_at)
            VALUES (?, ?, ?, ?, ?, 'draft', NOW())
            ON DUPLICATE KEY UPDATE form_data = VALUES(form_data), status = 'draft', updated_at = NOW()
        ");
        return $stmt->execute([$userId, $stationId ?: null, $moduleKey, $draftKey, $json]);
    } catch (Exception $e) {
        error_log("save_user_draft error: " . $e->getMessage());
        return false;
    }
}
}

if (!function_exists('get_user_draft')) {
function get_user_draft(PDO $pdo, int $userId, string $moduleKey): ?array {
    if ($userId <= 0 || empty($moduleKey)) return null;
    ensure_drafts_table($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT id, user_id, station_id, module_key, form_data, status, created_at, updated_at
            FROM user_form_drafts
            WHERE user_id = ? AND module_key = ? AND status = 'draft'
            LIMIT 1
        ");
        $stmt->execute([$userId, $moduleKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $decoded = json_decode($row['form_data'], true);
        if (!is_array($decoded)) return null;
        $row['data'] = $decoded;
        return $row;
    } catch (Exception $e) {
        error_log("get_user_draft error: " . $e->getMessage());
        return null;
    }
}
}

if (!function_exists('discard_user_draft')) {
function discard_user_draft(PDO $pdo, int $userId, string $moduleKey): bool {
    if ($userId <= 0 || empty($moduleKey)) return false;
    ensure_drafts_table($pdo);
    try {
        $stmt = $pdo->prepare("DELETE FROM user_form_drafts WHERE user_id = ? AND module_key = ?");
        return $stmt->execute([$userId, $moduleKey]);
    } catch (Exception $e) {
        error_log("discard_user_draft error: " . $e->getMessage());
        return false;
    }
}
}

if (!function_exists('clear_user_draft')) {
function clear_user_draft(PDO $pdo, int $userId, string $moduleKey): bool {
    return discard_user_draft($pdo, $userId, $moduleKey);
}
}

if (!function_exists('fetch_pumps_for_fuel_product')) {
    function fetch_pumps_for_fuel_product($pdo, $station_id, $fuel) {
        $pumps = [];
        $station_id = (int)$station_id;
        $ft_id = (int)($fuel['fuel_type_id'] ?? 0);
        $raw_ugt = trim($fuel['ugt_no'] ?? '');
        $ugt_num = (int)preg_replace('/[^0-9]/', '', $raw_ugt);
        $fname = strtolower(trim($fuel['fuel_type'] ?? ''));

        $ugt_list = array_filter(array_unique([
            $raw_ugt,
            $ugt_num ? sprintf('UGT-%02d', $ugt_num) : '',
            $ugt_num ? sprintf('UGT #%d', $ugt_num) : '',
            $ugt_num ? sprintf('UGT-%d', $ugt_num) : '',
            $ugt_num ? sprintf('UGT %d', $ugt_num) : '',
            $ugt_num ? (string)$ugt_num : '',
        ]));

        $prefix = '';
        if (strpos($fname, 'turbo') !== false) {
            $prefix = 'TURBO DIESEL - %';
        } elseif (strpos($fname, 'diesel 1') !== false || $ugt_num === 1) {
            $prefix = 'DIESEL 1 - %';
        } elseif (strpos($fname, 'diesel 2') !== false || $ugt_num === 2) {
            $prefix = 'DIESEL 2 - %';
        } elseif (strpos($fname, 'xcs') !== false || $ugt_num === 4) {
            $prefix = 'XCS PLUS - %';
        } elseif (strpos($fname, 'xtra unl 1') !== false || (strpos($fname, 'xtra') !== false && strpos($fname, '1') !== false) || $ugt_num === 5) {
            $prefix = 'XTRA UNL 1 - %';
        } elseif (strpos($fname, 'xtra unl 2') !== false || (strpos($fname, 'xtra') !== false && strpos($fname, '2') !== false) || $ugt_num === 6) {
            $prefix = 'XTRA UNL 2 - %';
        } elseif (strpos($fname, 'kero') !== false || $ugt_num === 7) {
            $prefix = 'KEROSENE - %';
        }

        try {
            $sql = "SELECT id, pump_number, pump_name, nozzle_number, status 
                    FROM fuel_pumps 
                    WHERE station_id = ? 
                      AND (
                        (? > 0 AND fuel_type_id = ?)
                        " . (!empty($ugt_list) ? " OR ugt_no IN (" . implode(',', array_fill(0, count($ugt_list), '?')) . ")" : "") . "
                        " . ($prefix !== '' ? " OR UPPER(pump_number) LIKE ?" : "") . "
                      )
                    ORDER BY id ASC";
            $params = [$station_id, $ft_id, $ft_id];
            if (!empty($ugt_list)) {
                $params = array_merge($params, array_values($ugt_list));
            }
            if ($prefix !== '') {
                $params[] = $prefix;
            }
            $p_stmt = $pdo->prepare($sql);
            $p_stmt->execute($params);
            $pumps = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $pumps = []; }

        if (empty($pumps)) {
            try {
                $n_sql = "SELECT n.id, n.pump_id, 
                                 COALESCE(fp.pump_number, CONCAT(n.pump_name, ' - ', n.nozzle_number)) AS pump_number,
                                 n.pump_name, n.nozzle_number, n.status
                          FROM nozzles n
                          LEFT JOIN fuel_pumps fp ON fp.id = n.pump_id
                          WHERE n.station_id = ?
                            AND (
                              (? > 0 AND n.fuel_type_id = ?)
                              " . (!empty($ugt_list) ? " OR n.ugt_no IN (" . implode(',', array_fill(0, count($ugt_list), '?')) . ")" : "") . "
                              " . ($prefix !== '' ? " OR UPPER(fp.pump_number) LIKE ?" : "") . "
                            )
                          ORDER BY n.id ASC";
                $n_params = [$station_id, $ft_id, $ft_id];
                if (!empty($ugt_list)) {
                    $n_params = array_merge($n_params, array_values($ugt_list));
                }
                if ($prefix !== '') {
                    $n_params[] = $prefix;
                }
                $n_stmt = $pdo->prepare($n_sql);
                $n_stmt->execute($n_params);
                $pumps = $n_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $pumps = []; }
        }

        foreach ($pumps as &$p) {
            $p['id'] = (int)($p['id'] ?? 0);
            $p['pump_number'] = trim($p['pump_number'] ?? '');
            $p['pump_name'] = trim($p['pump_name'] ?? '');
            $p['nozzle_number'] = trim($p['nozzle_number'] ?? '');
            $p['status'] = ucfirst(strtolower($p['status'] ?? 'Active'));
        }
        unset($p);

        return $pumps;
    }
}

