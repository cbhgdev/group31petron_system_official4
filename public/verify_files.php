<?php
header('Content-Type: text/plain');
$pub = __DIR__;
$files = scandir($pub);
echo "=== PUBLIC TEMP/TEST FILES ===\n";
$found = 0;
foreach ($files as $f) {
    if ($f === 'verify_files.php') continue;
    if (strpos($f, 'test_') === 0 || strpos($f, 'fix_') === 0 || strpos($f, 'debug_') === 0 || strpos($f, 'temp_') === 0 || strpos($f, 'scan_') === 0 || strpos($f, 'do_cleanup') === 0) {
        echo "$f\n";
        $found++;
        @unlink($pub . '/' . $f);
    }
}
if ($found === 0) {
    echo "No temp files found in public!\n";
} else {
    echo "Deleted $found temp files from public.\n";
}

@unlink(__FILE__);
echo "Cleaned verify_files.php as well.\n";
