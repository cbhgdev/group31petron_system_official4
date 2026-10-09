<?php
$dirs = [__DIR__, __DIR__ . '/../includes', __DIR__ . '/../partials', __DIR__ . '/../backend', __DIR__ . '/../templates'];
$results = [];
foreach ($dirs as $d) {
    if (!is_dir($d)) continue;
    foreach (scandir($d) as $f) {
        if (substr($f, -4) !== '.php') continue;
        $content = file_get_contents($d . '/' . $f);
        if (stripos($content, 'purchase order') !== false || stripos($content, 'create po') !== false) {
            $results[] = basename($d) . '/' . $f;
        }
    }
}
echo json_encode($results, JSON_PRETTY_PRINT);
