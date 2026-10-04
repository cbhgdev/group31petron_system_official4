<?php
$files = ['verify_regional_settings.php', 'do_cleanup.php'];
foreach ($files as $f) {
    $p = __DIR__ . '/' . $f;
    if (file_exists($p)) @unlink($p);
}
echo "DONE";
