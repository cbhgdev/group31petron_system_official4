<?php
// ================================================================
// db_download.php  –  Secure Backup File Download Handler
// Streams backup files through PHP so the /backups/ directory
// can remain HTTP-blocked (protected by .htaccess).
// ================================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';

require_login();
$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer', 'admin'])) {
    http_response_code(403);
    exit('Unauthorized');
}

$backup_dir = __DIR__ . '/../backups/';
$bid        = (int)($_GET['id'] ?? 0);

if ($bid <= 0) {
    http_response_code(400);
    exit('Missing backup ID.');
}

// Fetch backup record
try {
    $row = $pdo->prepare("SELECT * FROM database_backups WHERE id = ?");
    $row->execute([$bid]);
    $bk = $row->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    exit('Database error.');
}

if (!$bk) {
    http_response_code(404);
    exit('Backup record not found.');
}

// Always serve as u261539219_petrondbs.sql regardless of stored name
$stored_file = $backup_dir . basename($bk['backup_name'] ?? '');
$download_name = 'u261539219_petrondbs.sql';

if (!file_exists($stored_file)) {
    if (file_exists($backup_dir . 'u261539219_petrondbs.sql')) {
        $stored_file = $backup_dir . 'u261539219_petrondbs.sql';
    } else {
        http_response_code(404);
        exit('Backup file not found on server.');
    }
}

// Log the download
log_activity($pdo, $me['id'], 'Database Management',
    "Downloaded backup: {$bk['backup_name']} (as {$download_name})");

// Stream file to browser
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . filesize($stored_file));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($stored_file);
exit;
