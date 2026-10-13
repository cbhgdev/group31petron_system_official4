<?php
$files = scandir(__DIR__);
$matched = [];
foreach ($files as $f) {
    if (strpos($f, 'job_order') !== false || strpos($f, 'transaction') !== false || strpos($f, 'receipt') !== false || strpos($f, 'ar_') !== false || strpos($f, 'credit') !== false) {
        $matched[] = $f;
    }
}
$backend_files = scandir(__DIR__ . '/../backend');
$backend_matched = [];
foreach ($backend_files as $f) {
    if (strpos($f, 'job_order') !== false || strpos($f, 'transaction') !== false || strpos($f, 'receipt') !== false || strpos($f, 'ar_') !== false || strpos($f, 'credit') !== false) {
        $backend_matched[] = $f;
    }
}
header('Content-Type: text/plain');
echo "PUBLIC FILES:\n" . implode("\n", $matched) . "\n\nBACKEND FILES:\n" . implode("\n", $backend_matched);
