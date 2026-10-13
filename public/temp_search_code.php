<?php
$search_terms = ['Job Order Tracker', 'Complete & Settle', 'complete_and_settle', 'Complete & Process Payment', 'settle', 'tracker', 'process_payment'];
$dir1 = __DIR__;
$dir2 = __DIR__ . '/../backend';

function search_in_files($dir, $terms) {
    $results = [];
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || is_dir($dir . '/' . $file)) continue;
        if (!preg_match('/\.(php|js|html)$/i', $file)) continue;
        $content = file_get_contents($dir . '/' . $file);
        foreach ($terms as $term) {
            if (stripos($content, $term) !== false) {
                $lines = explode("\n", $content);
                foreach ($lines as $num => $line) {
                    if (stripos($line, $term) !== false) {
                        $results[] = basename($dir) . "/$file:" . ($num + 1) . " -> " . trim($line);
                        if (count($results) > 200) break 2;
                    }
                }
            }
        }
    }
    return $results;
}

$res1 = search_in_files($dir1, $search_terms);
$res2 = search_in_files($dir2, $search_terms);

echo "<pre>\n";
foreach (array_merge($res1, $res2) as $r) {
    echo htmlspecialchars($r) . "\n";
}
echo "</pre>";
