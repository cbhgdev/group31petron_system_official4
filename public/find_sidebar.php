<?php
$files = [];
foreach (['backend', 'public', 'includes', 'views', 'templates', 'app'] as $dir) {
    $full = __DIR__ . '/../' . $dir;
    if (is_dir($full)) {
        foreach (scandir($full) as $f) {
            if (stripos($f, 'sidebar') !== false || stripos($f, 'nav') !== false) {
                $files[] = "$dir/$f";
            }
        }
    }
}
echo json_encode($files, JSON_PRETTY_PRINT);
