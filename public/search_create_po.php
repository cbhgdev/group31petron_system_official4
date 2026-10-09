<?php
$dir = __DIR__;
$files = scandir($dir);
$results = [];
foreach ($files as $f) {
    if (pathinfo($f, PATHINFO_EXTENSION) === 'php') {
        $lines = file($dir . '/' . $f);
        foreach ($lines as $n => $l) {
            if (stripos($l, 'create po') !== false || stripos($l, 'create_po') !== false || stripos($l, 'createpo') !== false || stripos($l, 'create-po') !== false) {
                $results[] = [
                    'file' => $f,
                    'line' => $n + 1,
                    'text' => trim($l)
                ];
            }
        }
    }
}
file_put_contents(__DIR__ . '/test_search_create_po.json', json_encode($results, JSON_PRETTY_PRINT));
echo "Found " . count($results) . " matches";
