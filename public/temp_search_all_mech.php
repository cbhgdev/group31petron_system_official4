<?php
header('Content-Type: text/plain; charset=utf-8');

$all_files = array_merge(
    glob(__DIR__ . '/*.php'),
    glob(__DIR__ . '/../backend/*.php'),
    glob(__DIR__ . '/../backend/*/*.php')
);

foreach ($all_files as $f) {
    $content = file_get_contents($f);
    if (strpos($content, 'job_order_mechanic_id =') !== false ||
        strpos($content, 'job_order_mechanic_id') !== false ||
        strpos($content, 'resolved_mechanic_name') !== false) {
        
        $lines = explode("\n", $content);
        foreach ($lines as $i => $l) {
            if (stripos($l, 'job_order_mechanic_id') !== false || stripos($l, 'resolved_mechanic_name') !== false) {
                echo basename($f) . ":" . ($i + 1) . ": " . trim($l) . "\n";
            }
        }
    }
}
