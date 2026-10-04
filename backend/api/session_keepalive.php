<?php
/**
 * Session Keepalive & Activity Refresh Endpoint
 * backend/api/session_keepalive.php
 *
 * Called when the user interacts or clicks "Stay Logged In" on the timeout warning modal.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Session expired',
        'timeout' => true
    ]);
    exit;
}

// Reset the last activity timestamp
$_SESSION['last_activity'] = time();

$policy = function_exists('petron_get_security_policy') ? petron_get_security_policy() : ['session_timeout' => 30];
$timeoutMinutes = max(1, (int)($policy['session_timeout'] ?? 30));
$timeoutSeconds = $timeoutMinutes * 60;

echo json_encode([
    'ok' => true,
    'message' => 'Session refreshed successfully',
    'timestamp' => time(),
    'timeout_seconds' => $timeoutSeconds,
    'user' => [
        'id' => $_SESSION['user']['id'] ?? $_SESSION['user']['user_id'] ?? 0,
        'role' => $_SESSION['user']['role'] ?? ''
    ]
]);
